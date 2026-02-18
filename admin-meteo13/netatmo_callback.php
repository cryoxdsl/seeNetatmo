<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/logger.php';

admin_require_login();

$state = (string)($_GET['state'] ?? '');
if ($state==='' || !hash_equals(csrf_token(), $state)) {
    http_response_code(400);
    exit('Invalid state');
}
$code = (string)($_GET['code'] ?? '');
if ($code==='') {
    http_response_code(400);
    exit('Missing code');
}

try {
    netatmo_exchange_code($code);
    log_event('info','admin.netatmo','OAuth connected');
    redirect(APP_ADMIN_PATH . '/netatmo.php?ok=1');
} catch (Throwable $e) {
    log_event('error','admin.netatmo',$e->getMessage());
    redirect(APP_ADMIN_PATH . '/netatmo.php?error=' . urlencode($e->getMessage()));
}
