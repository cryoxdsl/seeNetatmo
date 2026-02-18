<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/constants.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/install_lib.php';

session_name('seenetatmo_install');
session_start();

$state = $_SESSION['install'] ?? [];
$allowDoneScreen = isset($_GET['done']) && !empty($state['done']);
if (is_file(__DIR__ . '/../config/installed.lock') && !$allowDoneScreen) {
    header('Location: /');
    exit;
}

$step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
$error = '';

function installer_db(array $state): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $state['db_host'], (int) $state['db_port'], $state['db_name']);
    return new PDO($dsn, $state['db_user'], $state['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function install_encrypt_with_key(string $hexKey, string $plaintext): string
{
    $key = hex2bin($hexKey);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
    return base64_encode($iv . $mac . $cipher);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($step === 1) {
            $missing = [];
            if (PHP_VERSION_ID < 80000) {
                $missing[] = 'PHP >= 8.0 required';
            }
            foreach (['curl', 'pdo_mysql', 'openssl'] as $ext) {
                if (!extension_loaded($ext)) {
                    $missing[] = 'Extension missing: ' . $ext;
                }
            }
            if ($missing) {
                throw new RuntimeException(implode(' | ', $missing));
            }
            header('Location: ?step=2');
            exit;
        }

        if ($step === 2) {
            $state['db_host'] = trim((string) $_POST['db_host']);
            $state['db_port'] = (int) ($_POST['db_port'] ?? 3306);
            $state['db_name'] = trim((string) $_POST['db_name']);
            $state['db_user'] = trim((string) $_POST['db_user']);
            $state['db_pass'] = (string) ($_POST['db_pass'] ?? '');
            $state['table_name'] = trim((string) ($_POST['table_name'] ?? 'alldata'));

            $pdo = installer_db($state);
            $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
            $stmt->execute([':t' => $state['table_name']]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Existing table not found: ' . $state['table_name']);
            }

            $sql = file_get_contents(__DIR__ . '/schema.sql');
            $pdo->exec((string) $sql);

            $_SESSION['install'] = $state;
            header('Location: ?step=3');
            exit;
        }

        if ($step === 3) {
            $suffix = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $_POST['admin_suffix'])));
            $username = trim((string) $_POST['admin_username']);
            $password = (string) ($_POST['admin_password'] ?? '');
            $siteName = trim((string) $_POST['site_name']);
            $contactEmail = trim((string) $_POST['contact_email']);
            if ($suffix === '' || strlen($suffix) < 4) {
                throw new RuntimeException('Admin suffix must be at least 4 chars.');
            }
            if (strlen($password) < 12) {
                throw new RuntimeException('Admin password must be at least 12 chars.');
            }
            if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid contact email.');
            }

            $state['admin_suffix'] = $suffix;
            $state['admin_username'] = $username;
            $state['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $state['totp_secret'] = generate_totp_secret();
            $state['site_name'] = $siteName !== '' ? $siteName : 'seeNetatmo';
            $state['contact_email'] = $contactEmail;

            $_SESSION['install'] = $state;
            header('Location: ?step=4');
            exit;
        }

        if ($step === 4) {
            $state['netatmo_client_id'] = trim((string) $_POST['client_id']);
            $state['netatmo_client_secret'] = trim((string) $_POST['client_secret']);
            $_SESSION['install'] = $state;
            header('Location: ?step=5');
            exit;
        }

        if ($step === 5) {
            if (empty($state['db_host']) || empty($state['admin_suffix']) || empty($state['admin_password_hash'])) {
                throw new RuntimeException('Installer session incomplete, restart from step 1.');
            }

            $masterKey = bin2hex(random_bytes(32));
            $cronFetch = random_token(24);
            $cronDaily = random_token(24);
            $appVersion = APP_DEFAULT_VERSION;

            $pdo = installer_db($state);
            $pdo->beginTransaction();

            $totpEnc = install_encrypt_with_key($masterKey, $state['totp_secret']);
            $uStmt = $pdo->prepare('INSERT INTO users (username, password_hash, totp_secret_enc, is_active, created_at) VALUES (:u,:p,:t,1,NOW())');
            $uStmt->execute([':u' => $state['admin_username'], ':p' => $state['admin_password_hash'], ':t' => $totpEnc]);

            $settings = [
                'site_name' => $state['site_name'],
                'contact_email' => $state['contact_email'],
                'table_name' => $state['table_name'],
                'admin_suffix' => $state['admin_suffix'],
                'app_version' => $appVersion,
            ];
            $sStmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k,:v,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
            foreach ($settings as $k => $v) {
                $sStmt->execute([':k' => $k, ':v' => $v]);
            }

            $secStmt = $pdo->prepare('INSERT INTO secrets (name, secret_enc, updated_at) VALUES (:n,:s,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc), updated_at=NOW()');
            foreach ([
                'netatmo_client_id' => $state['netatmo_client_id'] ?? '',
                'netatmo_client_secret' => $state['netatmo_client_secret'] ?? '',
            ] as $name => $plain) {
                if ($plain === '') {
                    continue;
                }
                $secStmt->execute([':n' => $name, ':s' => install_encrypt_with_key($masterKey, $plain)]);
            }

            $migStmt = $pdo->prepare('INSERT INTO schema_migrations (version, description, applied_at) VALUES (1, :d, NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description)');
            $migStmt->execute([':d' => 'Initial installation']);

            $pdo->commit();

            $config = [
                'db_host' => $state['db_host'],
                'db_port' => $state['db_port'],
                'db_name' => $state['db_name'],
                'db_user' => $state['db_user'],
                'db_pass' => $state['db_pass'],
                'site_name' => $state['site_name'],
                'contact_email' => $state['contact_email'],
                'table_name' => $state['table_name'],
                'admin_suffix' => $state['admin_suffix'],
                'cron_key_fetch' => $cronFetch,
                'cron_key_daily' => $cronDaily,
                'app_version' => $appVersion,
            ];

            install_create_admin_folder($state['admin_suffix']);
            install_write_file(__DIR__ . '/../config/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
            install_write_file(__DIR__ . '/../config/secrets.php', "<?php\nreturn " . var_export(['master_key' => $masterKey], true) . ";\n");
            install_write_file(__DIR__ . '/../config/installed.lock', date('c'));

            $state['cron_fetch'] = $cronFetch;
            $state['cron_daily'] = $cronDaily;
            $state['done'] = true;
            $_SESSION['install'] = $state;

            header('Location: ?step=5&done=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$steps = install_steps();
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installer</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<main class="wrap">
    <section class="panel">
        <h1>seeNetatmo installer</h1>
        <p>Step <?= $step ?> / 5 - <?= h($steps[$step]) ?></p>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <?php if ($step === 1): ?>
            <p>Checks PHP 8+, curl, pdo_mysql, openssl.</p>
            <form method="post"><button type="submit">Run checks</button></form>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <form method="post">
                <label>DB host<br><input name="db_host" value="<?= h((string) ($state['db_host'] ?? 'localhost')) ?>" required></label><br><br>
                <label>DB port<br><input name="db_port" value="<?= h((string) ($state['db_port'] ?? '3306')) ?>" required></label><br><br>
                <label>DB name<br><input name="db_name" value="<?= h((string) ($state['db_name'] ?? '')) ?>" required></label><br><br>
                <label>DB user<br><input name="db_user" value="<?= h((string) ($state['db_user'] ?? '')) ?>" required></label><br><br>
                <label>DB password<br><input type="password" name="db_pass" value=""></label><br><br>
                <label>Existing weather table<br><input name="table_name" value="<?= h((string) ($state['table_name'] ?? 'alldata')) ?>" required></label><br><br>
                <button type="submit">Save and continue</button>
            </form>
        <?php endif; ?>

        <?php if ($step === 3): ?>
            <form method="post">
                <label>Admin URL suffix (`/admin-&lt;suffix&gt;/`)<br><input name="admin_suffix" value="<?= h((string) ($state['admin_suffix'] ?? 'securepanel')) ?>" required></label><br><br>
                <label>Admin username<br><input name="admin_username" value="<?= h((string) ($state['admin_username'] ?? 'admin')) ?>" required></label><br><br>
                <label>Admin password (min 12 chars)<br><input type="password" name="admin_password" required></label><br><br>
                <label>Site name<br><input name="site_name" value="<?= h((string) ($state['site_name'] ?? 'seeNetatmo')) ?>" required></label><br><br>
                <label>Contact email<br><input type="email" name="contact_email" value="<?= h((string) ($state['contact_email'] ?? 'contact@example.com')) ?>" required></label><br><br>
                <button type="submit">Save and continue</button>
            </form>
        <?php endif; ?>

        <?php if ($step === 4): ?>
            <form method="post">
                <label>Netatmo client_id<br><input name="client_id" value="<?= h((string) ($state['netatmo_client_id'] ?? '')) ?>"></label><br><br>
                <label>Netatmo client_secret<br><input type="password" name="client_secret" value=""></label><br><br>
                <button type="submit">Save and continue</button>
            </form>
        <?php endif; ?>

        <?php if ($step === 5 && empty($_GET['done'])): ?>
            <p>Click to finalize installation and generate secure keys/files.</p>
            <form method="post"><button type="submit">Finalize installation</button></form>
        <?php endif; ?>

        <?php if ($step === 5 && isset($_GET['done'])): ?>
            <h2>Installation complete</h2>
            <p>Admin URL: <code>/admin-<?= h($state['admin_suffix']) ?>/</code></p>
            <p>Netatmo redirect URI: <code><?= h(((is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin-' . $state['admin_suffix'] . '/netatmo_callback.php') ?></code></p>
            <p>Cron fetch URL: <code>/cron/fetch.php?key=<?= h($state['cron_fetch']) ?></code></p>
            <p>Cron daily URL: <code>/cron/daily.php?key=<?= h($state['cron_daily']) ?></code></p>
            <p>TOTP secret (scan in Authenticator app): <code><?= h($state['totp_secret']) ?></code></p>
            <p>TOTP URI: <code><?= h(totp_uri($state['admin_username'], $state['site_name'], $state['totp_secret'])) ?></code></p>
            <p>Delete or protect the <code>/install</code> directory now.</p>
            <p><a class="button" href="/">Go to site</a></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
