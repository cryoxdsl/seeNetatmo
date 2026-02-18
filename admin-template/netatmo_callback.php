<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/netatmo.php';

enforce_admin_suffix_url();
auth_require_login();

$state = (string) ($_GET['state'] ?? '');
if ($state === '' || !hash_equals(csrf_token(), $state)) {
    http_response_code(400);
    exit('Invalid state');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    http_response_code(400);
    exit('Missing code');
}

try {
    netatmo_exchange_code($code, base_url() . '/netatmo_callback.php');
    app_log('info', 'admin.netatmo', 'Netatmo connected');
    header('Location: netatmo.php?ok=1');
    exit;
} catch (Throwable $e) {
    app_log('error', 'admin.netatmo', $e->getMessage());
    header('Location: netatmo.php?error=' . urlencode($e->getMessage()));
    exit;
}
