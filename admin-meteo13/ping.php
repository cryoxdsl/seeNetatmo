<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!admin_session_touch_or_invalidate()) {
    http_response_code(401);
    exit;
}

http_response_code(204);
