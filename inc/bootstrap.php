<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';

date_default_timezone_set(APP_TIMEZONE);

if (app_is_installed()) {
    start_secure_session();
}
