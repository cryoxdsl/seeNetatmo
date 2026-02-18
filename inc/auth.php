<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/constants.php';

function auth_is_logged_in(): bool
{
    return !empty($_SESSION['auth_ok']) && !empty($_SESSION['user_id']);
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function auth_get_user(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function auth_record_attempt(string $username, bool $success): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (username, ip_address, success, created_at) VALUES (:u, :ip, :s, NOW())');
    $stmt->execute([
        ':u' => $username,
        ':ip' => client_ip(),
        ':s' => $success ? 1 : 0,
    ]);
}

function auth_is_locked(string $username): bool
{
    $since = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))
        ->modify('-' . LOCKOUT_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s');
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE username = :u AND success = 0 AND created_at >= :since');
    $stmt->execute([':u' => $username, ':since' => $since]);
    $count = (int) $stmt->fetchColumn();
    return $count >= LOCKOUT_ATTEMPTS;
}

function auth_login_password(string $username, string $password): bool
{
    if (auth_is_locked($username)) {
        return false;
    }

    $user = auth_get_user($username);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        auth_record_attempt($username, false);
        return false;
    }

    $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
    $_SESSION['pending_2fa_username'] = $user['username'];
    auth_record_attempt($username, true);
    return true;
}

function auth_complete_2fa(): void
{
    $userId = (int) ($_SESSION['pending_2fa_user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = (string) $_SESSION['pending_2fa_username'];
    $_SESSION['auth_ok'] = 1;
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);

    $stmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function auth_pending_2fa_user(): ?array
{
    $id = (int) ($_SESSION['pending_2fa_user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
