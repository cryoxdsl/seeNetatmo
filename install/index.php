<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/constants.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../inc/i18n.php';

if (is_file(__DIR__ . '/../config/installed.lock')) {
    http_response_code(403);
    exit(t('install.disabled'));
}

session_name('meteo13_install');
session_start();
i18n_bootstrap();

$step = max(1, min(8, (int)($_GET['step'] ?? 1)));
$error = '';
$ok = '';
$st = $_SESSION['install'] ?? [];

function installer_encrypt(string $plain, string $masterHex): string
{
    $key = hex2bin($masterHex);
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, '', $nonce, $key);
        return base64_encode(json_encode(['v'=>1,'m'=>'sodium','n'=>base64_encode($nonce),'c'=>base64_encode($cipher)], JSON_UNESCAPED_SLASHES));
    }
    $iv = random_bytes(12); $tag='';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    if ($cipher === false) throw new RuntimeException('OpenSSL encryption failure');
    return base64_encode(json_encode(['v'=>1,'m'=>'openssl-gcm','n'=>base64_encode($iv),'t'=>base64_encode($tag),'c'=>base64_encode($cipher)], JSON_UNESCAPED_SLASHES));
}

function installer_db(array $s): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $s['db_host'], $s['db_name']);
    if (!empty($s['db_port'])) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s['db_host'], (int)$s['db_port'], $s['db_name']);
    }
    return new PDO($dsn, $s['db_user'], $s['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function installer_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();

        if ($step === 1) {
            $errs = [];
            if (PHP_VERSION_ID < 80000) $errs[] = 'PHP >= 8.0 required';
            foreach (['curl','pdo_mysql','openssl','json'] as $ext) {
                if (!extension_loaded($ext)) $errs[] = 'Missing extension: ' . $ext;
            }
            if (!is_writable(__DIR__ . '/../config')) $errs[] = '/config must be writable';
            if ($errs) throw new RuntimeException(implode(' | ', $errs));
            $ok = 'Environment checks passed';
            header('Location: ?step=2'); exit;
        }

        if ($step === 2) {
            $st['db_host'] = trim((string)$_POST['db_host']);
            $st['db_port'] = (int)($_POST['db_port'] ?? 3306);
            $st['db_name'] = trim((string)$_POST['db_name']);
            $st['db_user'] = trim((string)$_POST['db_user']);
            $st['db_pass'] = (string)($_POST['db_pass'] ?? '');
            installer_db($st)->query('SELECT 1');
            $_SESSION['install'] = $st;
            header('Location: ?step=3'); exit;
        }

        if ($step === 3) {
            $st['data_table'] = trim((string)($_POST['data_table'] ?? 'alldata'));
            $pdo = installer_db($st);

            $tbl = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t');
            $tbl->execute([':db' => $st['db_name'], ':t' => $st['data_table']]);
            if ((int)$tbl->fetchColumn() === 0) throw new RuntimeException('Table ' . $st['data_table'] . ' does not exist');

            $pk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
            $pk->execute([':db' => $st['db_name'], ':t' => $st['data_table']]);
            $pkCols = array_map(fn($r)=>$r['COLUMN_NAME'], $pk->fetchAll());
            if ($pkCols !== ['DateTime']) throw new RuntimeException('Primary key must be DateTime only');

            $_SESSION['install'] = $st;
            header('Location: ?step=4'); exit;
        }

        if ($step === 4) {
            $pdo = installer_db($st);
            $pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            $ok = 'Tables created/verified';
            header('Location: ?step=5'); exit;
        }

        if ($step === 5) {
            $user = trim((string)$_POST['admin_username']);
            $pass = (string)($_POST['admin_password'] ?? '');
            if ($user === '') throw new RuntimeException('Username required');
            if (strlen($pass) < 12) throw new RuntimeException('Password must be at least 12 chars');

            $st['admin_username'] = $user;
            $st['admin_password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            $st['totp_secret'] = totp_secret_generate();
            $st['backup_plain'] = [];
            for ($i=0; $i<8; $i++) {
                $st['backup_plain'][] = strtoupper(substr(random_hex(4),0,8));
            }

            $_SESSION['install'] = $st;
            header('Location: ?step=6'); exit;
        }

        if ($step === 6) {
            $st['netatmo_client_id'] = trim((string)($_POST['client_id'] ?? ''));
            $st['netatmo_client_secret'] = trim((string)($_POST['client_secret'] ?? ''));
            $_SESSION['install'] = $st;
            header('Location: ?step=7'); exit;
        }

        if ($step === 7) {
            $st['master_key'] = random_hex(32);
            $st['cron_key_fetch'] = random_hex(24);
            $st['cron_key_daily'] = random_hex(24);
            $st['cron_key_external'] = random_hex(24);
            $_SESSION['install'] = $st;
            header('Location: ?step=8'); exit;
        }

        if ($step === 8) {
            foreach (['db_host','db_name','db_user','data_table','admin_username','admin_password_hash','totp_secret','master_key','cron_key_fetch','cron_key_daily','cron_key_external'] as $k) {
                if (empty($st[$k])) throw new RuntimeException('Installer state missing: ' . $k);
            }

            $pdo = installer_db($st);
            $pdo->beginTransaction();

            $totpEnc = installer_encrypt($st['totp_secret'], $st['master_key']);
            $pdo->prepare('INSERT INTO users(username,password_hash,totp_secret_enc,is_active,created_at) VALUES(:u,:p,:t,1,NOW())')
                ->execute([':u'=>$st['admin_username'],':p'=>$st['admin_password_hash'],':t'=>$totpEnc]);
            $uid = (int)$pdo->lastInsertId();

            $insBc = $pdo->prepare('INSERT INTO backup_codes(user_id,code_hash,created_at) VALUES(:u,:h,NOW())');
            foreach (($st['backup_plain'] ?? []) as $c) {
                $insBc->execute([':u'=>$uid, ':h'=>password_hash($c,PASSWORD_DEFAULT)]);
            }

            $settings = [
                'site_name' => 'meteo13-netatmo',
                'contact_email' => 'contact@meteo13.fr',
                'data_table' => $st['data_table'],
                'app_version' => APP_VERSION,
                'admin_path' => APP_ADMIN_PATH,
            ];
            $setStmt = $pdo->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(:k,:v,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
            foreach ($settings as $k=>$v) $setStmt->execute([':k'=>$k,':v'=>$v]);

            $secStmt = $pdo->prepare('INSERT INTO secrets(name,secret_enc,updated_at) VALUES(:n,:s,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc),updated_at=NOW()');
            $secrets = [
                'cron_key_fetch' => $st['cron_key_fetch'],
                'cron_key_daily' => $st['cron_key_daily'],
                'cron_key_external' => $st['cron_key_external'],
                'netatmo_client_id' => (string)($st['netatmo_client_id'] ?? ''),
                'netatmo_client_secret' => (string)($st['netatmo_client_secret'] ?? ''),
            ];
            foreach ($secrets as $k=>$v) {
                if ($v === '') continue;
                $secStmt->execute([':n'=>$k, ':s'=>installer_encrypt($v, $st['master_key'])]);
            }

            $pdo->prepare('INSERT INTO schema_migrations(version,description,applied_at) VALUES(1,:d,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description)')
                ->execute([':d'=>'Initial install']);

            $pdo->commit();

            $config = [
                'db_host' => $st['db_host'],
                'db_port' => (int)$st['db_port'],
                'db_name' => $st['db_name'],
                'db_user' => $st['db_user'],
                'db_pass' => $st['db_pass'],
                'domain' => 'meteo13.fr',
                'admin_path' => APP_ADMIN_PATH,
                'app_version' => APP_VERSION,
            ];
            file_put_contents(__DIR__ . '/../config/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
            file_put_contents(__DIR__ . '/../config/secrets.php', "<?php\nreturn " . var_export(['master_key' => $st['master_key']], true) . ";\n");
            file_put_contents(__DIR__ . '/../config/installed.lock', date('c'));

            $_SESSION['install_done'] = true;
            $_SESSION['install'] = $st;
            $ok = 'Installation complete';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$totpUri = '';
if (!empty($st['totp_secret']) && !empty($st['admin_username'])) {
    $totpUri = totp_uri($st['totp_secret'], $st['admin_username'], 'meteo13-netatmo');
}
?><!doctype html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(t('install.title')) ?></title>
<link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="wrap"><section class="panel">
<h1><?= h(t('install.title')) ?> - meteo13-netatmo</h1>
<p><?= h(t('install.step')) ?> <?= $step ?>/8</p>
<?php if($error):?><div class="alert alert-bad"><?=h($error)?></div><?php endif;?>
<?php if($ok):?><div class="alert alert-ok"><?=h($ok)?></div><?php endif;?>

<?php if($step===1):?><form method="post"><?= installer_csrf_input() ?><p><?= h(t('install.check_desc')) ?></p><button type="submit"><?= h(t('install.run_checks')) ?></button></form><?php endif;?>

<?php if($step===2):?><form method="post"><?= installer_csrf_input() ?><label>DB host<br><input name="db_host" value="<?=h($st['db_host']??'localhost')?>" required></label><br><br><label>DB port<br><input name="db_port" value="<?=h($st['db_port']??'3306')?>" required></label><br><br><label>DB name<br><input name="db_name" value="<?=h($st['db_name']??'')?>" required></label><br><br><label>DB user<br><input name="db_user" value="<?=h($st['db_user']??'')?>" required></label><br><br><label>DB pass<br><input type="password" name="db_pass"></label><br><br><button type="submit"><?= h(t('install.connect')) ?></button></form><?php endif;?>

<?php if($step===3):?><form method="post"><?= installer_csrf_input() ?><label>Data table<br><input name="data_table" value="<?=h($st['data_table']??'alldata')?>" required></label><br><br><button type="submit"><?= h(t('install.verify_table')) ?></button></form><?php endif;?>

<?php if($step===4):?><form method="post"><?= installer_csrf_input() ?><p><?= h(t('install.create_tables_desc')) ?></p><button type="submit"><?= h(t('install.create_tables')) ?></button></form><?php endif;?>

<?php if($step===5):?><form method="post"><?= installer_csrf_input() ?><label>Admin username<br><input name="admin_username" value="<?=h($st['admin_username']??'admin')?>" required></label><br><br><label>Admin password (min 12)<br><input type="password" name="admin_password" minlength="12" required></label><br><br><button type="submit"><?= h(t('install.create_admin')) ?></button></form><?php endif;?>

<?php if($step===6):?><form method="post"><?= installer_csrf_input() ?><p>Netatmo credentials (can be updated later in admin)</p><label>client_id<br><input name="client_id" value="<?=h($st['netatmo_client_id']??'')?>"></label><br><br><label>client_secret<br><input type="password" name="client_secret"></label><br><br><button type="submit"><?= h(t('install.save_netatmo')) ?></button></form><?php endif;?>

<?php if($step===7):?><form method="post"><?= installer_csrf_input() ?><p>Generate master key and cron keys.</p><button type="submit"><?= h(t('install.generate_keys')) ?></button></form><?php endif;?>

<?php if($step===8 && empty($_SESSION['install_done'])):?><form method="post"><?= installer_csrf_input() ?><p><?= h(t('install.finalize_desc')) ?></p><button type="submit"><?= h(t('install.finalize')) ?></button></form><?php endif;?>

<?php if(!empty($_SESSION['install_done'])): ?>
<div class="panel">
  <h2><?= h(t('install.complete')) ?></h2>
  <p>Admin URL: <span class="code">https://meteo13.fr/admin-meteo13/</span></p>
  <p>Netatmo redirect URI: <span class="code">https://meteo13.fr/admin-meteo13/netatmo_callback.php</span></p>
  <p>Cron fetch URL: <span class="code">https://meteo13.fr/cron/fetch.php?key=<?=h($st['cron_key_fetch'])?></span></p>
  <p>Cron daily URL: <span class="code">https://meteo13.fr/cron/daily.php?key=<?=h($st['cron_key_daily'])?></span></p>
  <p>Cron external URL: <span class="code">https://meteo13.fr/cron/external.php?key=<?=h($st['cron_key_external'])?></span></p>
  <p><?= h(t('twofa.scan_qr')) ?></p>
  <p>2FA TOTP URI: <span class="code"><?=h($totpUri)?></span></p>
  <p>Backup codes (store securely): <span class="code"><?=h(implode(' ', $st['backup_plain'] ?? []))?></span></p>
  <p>Delete `/install/` directory if desired. `config/installed.lock` already blocks installer execution.</p>
</div>
<?php endif; ?>
</section></main></body></html>
