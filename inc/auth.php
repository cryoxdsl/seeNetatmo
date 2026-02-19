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

function auth_cookie_secure_flag(): bool
{
    $secure = is_https();
    if (!$secure) {
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '' && str_contains($forwardedProto, 'https')) {
            $secure = true;
        }
    }
    if (!$secure) {
        $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cfVisitor !== '' && stripos($cfVisitor, '"https"') !== false) {
            $secure = true;
        }
    }
    return $secure;
}

function auth_set_trusted_cookie(string $value, int $expiresAt): void
{
    setcookie(TRUSTED_2FA_COOKIE_NAME, $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => auth_cookie_secure_flag(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_clear_trusted_cookie(): void
{
    setcookie(TRUSTED_2FA_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => auth_cookie_secure_flag(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[TRUSTED_2FA_COOKIE_NAME]);
}

function auth_user_agent_hash(): string
{
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    return hash('sha256', $ua);
}

function auth_trusted_devices_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        db()->query('SELECT 1 FROM trusted_devices LIMIT 1');
        $available = true;
    } catch (Throwable) {
        $available = false;
    }
    return $available;
}

function auth_trusted_cookie_parse(?string $raw): ?array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $token] = $parts;
    if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return ['selector' => $selector, 'token' => $token];
}

function auth_finalize_admin_login(array $user): void
{
    $_SESSION['admin_uid'] = (int) $user['id'];
    $_SESSION['admin_username'] = (string) $user['username'];
    $_SESSION['admin_ok'] = 1;
    $_SESSION['admin_last_seen'] = time();
    unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_created_at']);
    unset($_SESSION['pending_2fa_failures']);
    db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute([':id' => $user['id']]);
    session_regenerate_id(true);
}

function auth_issue_trusted_device(int $userId): void
{
    if (!auth_trusted_devices_available() || $userId <= 0) {
        return;
    }
    $selector = random_hex(8);
    $token = random_hex(32);
    $tokenHash = hash('sha256', $token);
    $uaHash = auth_user_agent_hash();
    $expiresAt = time() + (TRUSTED_2FA_DAYS * 86400);
    $expiresSql = date('Y-m-d H:i:s', $expiresAt);

    try {
        db()->prepare('DELETE FROM trusted_devices WHERE user_id=:uid AND (expires_at < NOW() OR revoked_at IS NOT NULL)')
            ->execute([':uid' => $userId]);
        db()->prepare('INSERT INTO trusted_devices(user_id,selector,token_hash,ua_hash,expires_at,last_used_at,created_at,revoked_at) VALUES(:uid,:selector,:token_hash,:ua_hash,:expires_at,NOW(),NOW(),NULL)')
            ->execute([
                ':uid' => $userId,
                ':selector' => $selector,
                ':token_hash' => $tokenHash,
                ':ua_hash' => $uaHash,
                ':expires_at' => $expiresSql,
            ]);
        auth_set_trusted_cookie($selector . '.' . $token, $expiresAt);
    } catch (Throwable) {
        auth_clear_trusted_cookie();
    }
}

function auth_revoke_current_trusted_device(): void
{
    if (!auth_trusted_devices_available()) {
        auth_clear_trusted_cookie();
        return;
    }
    $parsed = auth_trusted_cookie_parse($_COOKIE[TRUSTED_2FA_COOKIE_NAME] ?? '');
    if (!$parsed) {
        auth_clear_trusted_cookie();
        return;
    }
    try {
        db()->prepare('UPDATE trusted_devices SET revoked_at=NOW() WHERE selector=:selector')
            ->execute([':selector' => $parsed['selector']]);
    } catch (Throwable) {
        // Ignore DB errors on logout path.
    }
    auth_clear_trusted_cookie();
}

function auth_revoke_all_trusted_devices(int $userId): void
{
    if (!auth_trusted_devices_available() || $userId <= 0) {
        auth_clear_trusted_cookie();
        return;
    }
    try {
        db()->prepare('UPDATE trusted_devices SET revoked_at=NOW() WHERE user_id=:uid AND revoked_at IS NULL')
            ->execute([':uid' => $userId]);
    } catch (Throwable) {
        // Ignore DB errors on management path.
    }
    auth_clear_trusted_cookie();
}

function auth_login_with_trusted_device(array $user): bool
{
    if (!auth_trusted_devices_available()) {
        return false;
    }
    $rawCookie = (string) ($_COOKIE[TRUSTED_2FA_COOKIE_NAME] ?? '');
    $parsed = auth_trusted_cookie_parse($rawCookie);
    if (!$parsed) {
        if ($rawCookie !== '') {
            auth_clear_trusted_cookie();
        }
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT id, user_id, token_hash, ua_hash, expires_at FROM trusted_devices WHERE selector=:selector AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([':selector' => $parsed['selector']]);
        $row = $stmt->fetch();
        if (!$row) {
            auth_clear_trusted_cookie();
            return false;
        }
        if ((int) $row['user_id'] !== (int) $user['id']) {
            auth_clear_trusted_cookie();
            return false;
        }
        if ((string) $row['ua_hash'] !== auth_user_agent_hash()) {
            db()->prepare('UPDATE trusted_devices SET revoked_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
            auth_clear_trusted_cookie();
            return false;
        }
        $tokenHash = hash('sha256', $parsed['token']);
        if (!hash_equals((string) $row['token_hash'], $tokenHash)) {
            db()->prepare('UPDATE trusted_devices SET revoked_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
            auth_clear_trusted_cookie();
            return false;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            db()->prepare('UPDATE trusted_devices SET revoked_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
            auth_clear_trusted_cookie();
            return false;
        }

        $newToken = random_hex(32);
        $newTokenHash = hash('sha256', $newToken);
        $newExpiresAt = time() + (TRUSTED_2FA_DAYS * 86400);
        $newExpiresSql = date('Y-m-d H:i:s', $newExpiresAt);
        db()->prepare('UPDATE trusted_devices SET token_hash=:token_hash, expires_at=:expires_at, last_used_at=NOW() WHERE id=:id')
            ->execute([
                ':token_hash' => $newTokenHash,
                ':expires_at' => $newExpiresSql,
                ':id' => $row['id'],
            ]);
        auth_set_trusted_cookie($parsed['selector'] . '.' . $newToken, $newExpiresAt);
        return true;
    } catch (Throwable) {
        auth_clear_trusted_cookie();
        return false;
    }
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_ok']) && !empty($_SESSION['admin_uid']);
}

function admin_session_timeout_seconds(): int
{
    $timeout = (int) cfg('admin_session_timeout_seconds', ADMIN_SESSION_TIMEOUT_SECONDS);
    if ($timeout < 300) {
        $timeout = ADMIN_SESSION_TIMEOUT_SECONDS;
    }
    return $timeout;
}

function admin_session_touch_or_invalidate(): bool
{
    if (!admin_logged_in()) {
        return false;
    }

    $now = time();
    $timeout = admin_session_timeout_seconds();
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && ($now - $last) > $timeout) {
        app_session_destroy();
        return false;
    }
    $_SESSION['admin_last_seen'] = $now;
    return true;
}

function admin_require_login(): void
{
    if (!admin_session_touch_or_invalidate()) {
        redirect(APP_ADMIN_PATH . '/login.php');
    }
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
        auth_clear_trusted_cookie();
        auth_finalize_admin_login($user);
        return ['ok' => true, 'need_2fa' => false];
    }

    if (auth_login_with_trusted_device($user)) {
        auth_finalize_admin_login($user);
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

function auth_verify_2fa(string $codeOrBackup, bool $rememberDevice = false): array
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
        auth_finalize_admin_login($u);
        if ($rememberDevice) {
            auth_issue_trusted_device((int) $u['id']);
        } else {
            auth_clear_trusted_cookie();
        }
        return ['ok' => true, 'backup_used' => false];
    }

    $codes = db()->prepare('SELECT id, code_hash FROM backup_codes WHERE user_id=:u AND used_at IS NULL');
    $codes->execute([':u' => $u['id']]);
    $backupCandidate = strtoupper(str_replace([' ', '-'], '', $input));
    foreach ($codes->fetchAll() as $row) {
        if (password_verify($backupCandidate, (string) $row['code_hash'])) {
            db()->prepare('UPDATE backup_codes SET used_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
            auth_log_attempt($username, $ip, true);
            auth_finalize_admin_login($u);
            if ($rememberDevice) {
                auth_issue_trusted_device((int) $u['id']);
            } else {
                auth_clear_trusted_cookie();
            }
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
    auth_revoke_current_trusted_device();
    app_session_destroy();
    redirect(APP_ADMIN_PATH . '/login.php?logged_out=1');
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
