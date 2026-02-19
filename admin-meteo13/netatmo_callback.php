<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/logger.php';

$state = (string)($_GET['state'] ?? '');
$expectedState = secret_get('netatmo_oauth_state') ?? '';
$stateTs = (int) (secret_get('netatmo_oauth_state_ts') ?? 0);
if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    http_response_code(400);
    exit('Invalid state');
}
if ($stateTs <= 0 || (time() - $stateTs) > 900) {
    http_response_code(400);
    exit('Expired state');
}
$code = (string)($_GET['code'] ?? '');
if ($code==='') {
    http_response_code(400);
    exit('Missing code');
}

try {
    netatmo_exchange_code($code);
    secret_set('netatmo_oauth_state', random_hex(16));
    secret_set('netatmo_oauth_state_ts', '0');
    log_event('info','admin.netatmo','OAuth connected');
    redirect(APP_ADMIN_PATH . '/netatmo.php?ok=1');
} catch (Throwable $e) {
    log_event('error','admin.netatmo',$e->getMessage());
    redirect(APP_ADMIN_PATH . '/netatmo.php?error=' . urlencode($e->getMessage()));
}
