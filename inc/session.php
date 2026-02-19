<?php
declare(strict_types=1);

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('meteo13_sid');
    $timeout = (int) cfg('session_timeout_seconds', SESSION_TIMEOUT_SECONDS);
    if ($timeout < 300) {
        $timeout = SESSION_TIMEOUT_SECONDS;
    }
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

    if (function_exists('ini_set')) {
        @ini_set('session.gc_maxlifetime', (string) $timeout);
        @ini_set('session.use_strict_mode', '1');
    }

    session_set_cookie_params([
        'lifetime' => $timeout,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
    $now = time();

    if (!isset($_SESSION['last_seen'])) {
        $_SESSION['last_seen'] = $now;
        session_regenerate_id(true);
    }

    if (($now - (int) $_SESSION['last_seen']) > $timeout) {
        app_session_destroy();
        session_start();
    }

    $_SESSION['last_seen'] = $now;
}

function app_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_hex(24);
    }
    return (string) $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    $expect = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $expect === '' || !hash_equals($expect, $sent)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}
