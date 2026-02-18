<?php
declare(strict_types=1);

/*
 * Emergency recovery script for meteo13-netatmo.
 * 1) Use once, 2) remove file immediately after success.
 */

const RECOVERY_TOKEN = '9de111d5d08e04f7cb0d202f2095fb0c';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rand_hex(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function rand_base32(int $len = 32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
    return $out;
}
function totp_uri(string $secret, string $username, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
function encrypt_with_key(string $plain, string $masterHex): string {
    $key = hex2bin($masterHex);
    if ($key === false) throw new RuntimeException('Invalid key');

    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, '', $nonce, $key);
        return base64_encode(json_encode([
            'v' => 1,
            'm' => 'sodium',
            'n' => base64_encode($nonce),
            'c' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES));
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    if ($cipher === false) throw new RuntimeException('OpenSSL encryption failed');

    return base64_encode(json_encode([
        'v' => 1,
        'm' => 'openssl-gcm',
        'n' => base64_encode($iv),
        't' => base64_encode($tag),
        'c' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals(RECOVERY_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden. Provide valid ?token=...');
}

$configFile = __DIR__ . '/config/config.php';
$secretsFile = __DIR__ . '/config/secrets.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config/config.php');
}

$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    exit('Invalid config/config.php');
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
if (!empty($config['db_port'])) {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)$config['db_port'], $config['db_name']);
}
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim((string)($_POST['admin_username'] ?? 'admin'));
        $password = (string)($_POST['admin_password'] ?? '');
        $clientId = trim((string)($_POST['netatmo_client_id'] ?? ''));
        $clientSecret = trim((string)($_POST['netatmo_client_secret'] ?? ''));

        if ($username === '') throw new RuntimeException('Admin username required');
        if (strlen($password) < 12) throw new RuntimeException('Admin password must be at least 12 chars');

        $uStmt = $pdo->prepare('SELECT id FROM users WHERE username=:u LIMIT 1');
        $uStmt->execute([':u' => $username]);
        $uid = (int)$uStmt->fetchColumn();
        if ($uid <= 0) throw new RuntimeException('Admin user not found: ' . $username);

        $newMaster = rand_hex(32);
        $newTotp = rand_base32(32);
        $newCronFetch = rand_hex(24);
        $newCronDaily = rand_hex(24);

        $backupCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $backupCodes[] = strtoupper(substr(rand_hex(4), 0, 8));
        }

        $totpEnc = encrypt_with_key($newTotp, $newMaster);

        $pdo->beginTransaction();

        $pdo->prepare('UPDATE users SET password_hash=:p, totp_secret_enc=:t, is_active=1 WHERE id=:id')
            ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':t' => $totpEnc, ':id' => $uid]);

        $pdo->prepare('DELETE FROM backup_codes WHERE user_id=:u')->execute([':u' => $uid]);
        $bcIns = $pdo->prepare('INSERT INTO backup_codes(user_id,code_hash,created_at) VALUES(:u,:h,NOW())');
        foreach ($backupCodes as $c) {
            $bcIns->execute([':u' => $uid, ':h' => password_hash($c, PASSWORD_DEFAULT)]);
        }

        $secUp = $pdo->prepare('INSERT INTO secrets(name,secret_enc,updated_at) VALUES(:n,:s,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc), updated_at=NOW()');
        $secDel = $pdo->prepare('DELETE FROM secrets WHERE name=:n');

        $secretValues = [
            'cron_key_fetch' => $newCronFetch,
            'cron_key_daily' => $newCronDaily,
            'netatmo_oauth_state' => rand_hex(16),
            'netatmo_oauth_state_ts' => '0',
        ];

        if ($clientId !== '') $secretValues['netatmo_client_id'] = $clientId;
        if ($clientSecret !== '') $secretValues['netatmo_client_secret'] = $clientSecret;

        foreach ($secretValues as $name => $plain) {
            $secUp->execute([':n' => $name, ':s' => encrypt_with_key($plain, $newMaster)]);
        }

        foreach (['netatmo_access_token','netatmo_refresh_token','netatmo_access_expires_at'] as $n) {
            $secDel->execute([':n' => $n]);
        }

        $pdo->prepare('INSERT INTO app_logs(level,channel,message,context_json,created_at) VALUES("warning","recovery","Emergency recovery executed",:ctx,NOW())')
            ->execute([':ctx' => json_encode(['admin' => $username], JSON_UNESCAPED_SLASHES)]);

        $pdo->commit();

        if (is_file($secretsFile)) {
            @copy($secretsFile, $secretsFile . '.bak-' . date('Ymd-His'));
        }
        file_put_contents($secretsFile, "<?php\nreturn " . var_export(['master_key' => $newMaster], true) . ";\n");

        $result = [
            'username' => $username,
            'master_key' => $newMaster,
            'cron_fetch' => $newCronFetch,
            'cron_daily' => $newCronDaily,
            'totp_secret' => $newTotp,
            'totp_uri' => totp_uri($newTotp, $username, 'meteo13-netatmo'),
            'backup_codes' => $backupCodes,
            'client_saved' => ($clientId !== '' && $clientSecret !== ''),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery</title><style>body{font-family:Arial,sans-serif;margin:20px;max-width:900px}input{width:100%;padding:8px;margin:4px 0 12px}button{padding:10px 14px}.ok{background:#e6f6e6;padding:10px}.err{background:#fbe4e4;padding:10px}.code{font-family:Consolas,monospace;background:#f3f5f7;padding:8px;display:block;word-break:break-all}</style></head><body>
<h1>meteo13 Recovery</h1>
<p><strong>Use once then delete <code>recovery.php</code>.</strong></p>
<?php if($error): ?><div class="err">Error: <?= h($error) ?></div><?php endif; ?>
<?php if($result): ?><div class="ok">
<p>Recovery done.</p>
<p>Admin: <span class="code"><?= h($result['username']) ?></span></p>
<p>New master_key: <span class="code"><?= h($result['master_key']) ?></span></p>
<p>Cron fetch URL: <span class="code">https://meteo13.fr/cron/fetch.php?key=<?= h($result['cron_fetch']) ?></span></p>
<p>Cron daily URL: <span class="code">https://meteo13.fr/cron/daily.php?key=<?= h($result['cron_daily']) ?></span></p>
<p>New TOTP secret: <span class="code"><?= h($result['totp_secret']) ?></span></p>
<p>TOTP URI: <span class="code"><?= h($result['totp_uri']) ?></span></p>
<p>Backup codes: <span class="code"><?= h(implode(' ', $result['backup_codes'])) ?></span></p>
<p>Netatmo client credentials updated now: <?= $result['client_saved'] ? 'yes' : 'no (set later in admin)' ?></p>
<p><strong>Next:</strong> login admin, reconnect Netatmo OAuth, update cron-job.org keys, delete this file.</p>
</div><?php endif; ?>
<form method="post">
<input type="hidden" name="token" value="<?= h($token) ?>">
<label>Admin username</label>
<input name="admin_username" value="admin" required>
<label>New admin password (min 12)</label>
<input type="password" name="admin_password" minlength="12" required>
<label>Netatmo client_id (optional now)</label>
<input name="netatmo_client_id" value="">
<label>Netatmo client_secret (optional now)</label>
<input type="password" name="netatmo_client_secret" value="">
<button type="submit">Run recovery</button>
</form>
</body></html>
