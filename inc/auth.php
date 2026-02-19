<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';

function auth_msg(string $key, string $fallback): string
{
    if (function_exists('t')) {
        $v = t($key);
        if ($v !== $key) {
            return $v;
        }
    }
    return $fallback;
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_ok']) && !empty($_SESSION['admin_uid']);
}

function admin_require_login(): void
{
    if (!admin_logged_in()) {
        redirect(APP_ADMIN_PATH . '/login.php');
    }
    $now = time();
    $timeout = (int) cfg('admin_session_timeout_seconds', ADMIN_SESSION_TIMEOUT_SECONDS);
    if ($timeout < 300) {
        $timeout = ADMIN_SESSION_TIMEOUT_SECONDS;
    }
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && ($now - $last) > $timeout) {
        admin_logout();
    }
    $_SESSION['admin_last_seen'] = $now;
}

function auth_lockout_active(string $username, string $ip): bool
{
    $since = now_paris()->modify('-' . LOCKOUT_WINDOW_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE username=:u AND ip_address=:ip AND success=0 AND created_at>=:s');
    $stmt->execute([':u' => $username, ':ip' => $ip, ':s' => $since]);
    return ((int) $stmt->fetchColumn()) >= LOCKOUT_ATTEMPTS;
}

function auth_log_attempt(string $username, string $ip, bool $success): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts(username,ip_address,success,created_at) VALUES(:u,:ip,:s,NOW())');
    $stmt->execute([':u' => $username, ':ip' => $ip, ':s' => $success ? 1 : 0]);
}

function auth_login_password(string $username, string $password): array
{
    $ip = client_ip();
    if (auth_lockout_active($username, $ip)) {
        return ['ok' => false, 'error' => auth_msg('auth.too_many_attempts', 'Too many failed attempts. Retry in 10 minutes.')];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username=:u AND is_active=1 LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        auth_log_attempt($username, $ip, false);
        return ['ok' => false, 'error' => auth_msg('auth.invalid_credentials', 'Invalid credentials.')];
    }

    auth_log_attempt($username, $ip, true);
    unset($_SESSION['pending_2fa_failures']);
    $totpSecret = decrypt_string((string) ($user['totp_secret_enc'] ?? ''));
    if ($totpSecret === null || $totpSecret === '') {
        $_SESSION['admin_uid'] = (int) $user['id'];
        $_SESSION['admin_username'] = (string) $user['username'];
        $_SESSION['admin_ok'] = 1;
        $_SESSION['admin_last_seen'] = time();
        unset($_SESSION['pending_uid'], $_SESSION['pending_user']);
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute([':id' => $user['id']]);
        session_regenerate_id(true);
        return ['ok' => true, 'need_2fa' => false];
    }

    $_SESSION['pending_uid'] = (int) $user['id'];
    $_SESSION['pending_user'] = (string) $user['username'];
    $_SESSION['pending_created_at'] = time();
    return ['ok' => true, 'need_2fa' => true];
}

function auth_pending_user(): ?array
{
    $uid = (int) ($_SESSION['pending_uid'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $created = (int) ($_SESSION['pending_created_at'] ?? 0);
    if ($created <= 0 || (time() - $created) > PENDING_2FA_TTL_SECONDS) {
        unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_created_at']);
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function auth_verify_2fa(string $codeOrBackup): array
{
    $u = auth_pending_user();
    if (!$u) {
        return ['ok' => false, 'error' => auth_msg('auth.no_pending_login', 'No pending login')];
    }
    $username = '2fa:' . (string) ($u['username'] ?? '');
    $ip = client_ip();
    if (auth_lockout_active($username, $ip)) {
        return ['ok' => false, 'error' => auth_msg('auth.too_many_attempts', 'Too many failed attempts. Retry in 10 minutes.')];
    }

    $input = trim($codeOrBackup);
    $totpSecret = decrypt_string((string) $u['totp_secret_enc']);
    if ($totpSecret && totp_verify($totpSecret, $input)) {
        auth_log_attempt($username, $ip, true);
        $_SESSION['admin_uid'] = (int) $u['id'];
        $_SESSION['admin_username'] = (string) $u['username'];
        $_SESSION['admin_ok'] = 1;
        $_SESSION['admin_last_seen'] = time();
        unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_created_at']);
        unset($_SESSION['pending_2fa_failures']);
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute([':id' => $u['id']]);
        session_regenerate_id(true);
        return ['ok' => true, 'backup_used' => false];
    }

    $codes = db()->prepare('SELECT id, code_hash FROM backup_codes WHERE user_id=:u AND used_at IS NULL');
    $codes->execute([':u' => $u['id']]);
    $backupCandidate = strtoupper(str_replace([' ', '-'], '', $input));
    foreach ($codes->fetchAll() as $row) {
        if (password_verify($backupCandidate, (string) $row['code_hash'])) {
            db()->prepare('UPDATE backup_codes SET used_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
            auth_log_attempt($username, $ip, true);
            $_SESSION['admin_uid'] = (int) $u['id'];
            $_SESSION['admin_username'] = (string) $u['username'];
            $_SESSION['admin_ok'] = 1;
            $_SESSION['admin_last_seen'] = time();
            unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_created_at']);
            unset($_SESSION['pending_2fa_failures']);
            db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute([':id' => $u['id']]);
            session_regenerate_id(true);
            return ['ok' => true, 'backup_used' => true];
        }
    }
    auth_log_attempt($username, $ip, false);
    $fails = (int) ($_SESSION['pending_2fa_failures'] ?? 0) + 1;
    $_SESSION['pending_2fa_failures'] = $fails;
    $sleepMicros = min(3000000, $fails * 250000);
    usleep($sleepMicros);

    return ['ok' => false, 'error' => auth_msg('auth.invalid_2fa', 'Invalid TOTP/backup code')];
}

function admin_logout(): void
{
    app_session_destroy();
    redirect(APP_ADMIN_PATH . '/login.php');
}

function admin_current_user(): ?array
{
    $uid = (int) ($_SESSION['admin_uid'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function user_has_2fa(array $user): bool
{
    $secret = decrypt_string((string) ($user['totp_secret_enc'] ?? ''));
    return $secret !== null && $secret !== '';
}
