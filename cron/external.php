<?php
declare(strict_types=1);

$start = microtime(true);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/lock.php';
require_once __DIR__ . '/../inc/logger.php';
require_once __DIR__ . '/../inc/vigilance.php';
require_once __DIR__ . '/../inc/sea_temp.php';

header('Content-Type: text/plain; charset=utf-8');
if (!app_is_installed()) {
    http_response_code(403);
    exit("Not installed\n");
}

$provided = (string) ($_GET['key'] ?? '');
$expected = secret_get('cron_key_external') ?? '';
if ($expected === '') {
    $expected = secret_get('cron_key_daily') ?? '';
}
if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lock = lock_acquire('cron_external');
if ($lock === null) {
    http_response_code(429);
    exit("Busy\n");
}

try {
    $v = vigilance_current(true);
    $s = sea_temp_nearest(true);
    $dur = round(microtime(true) - $start, 3);

    log_event('info', 'cron.external', 'External refresh success', [
        'duration_sec' => $dur,
        'vigilance' => [
            'dept' => $v['dept'] ?? '',
            'active' => $v['active'] ?? false,
            'level' => $v['level'] ?? 'green',
        ],
        'sea_temp' => [
            'available' => $s['available'] ?? false,
            'distance_km' => $s['distance_km'] ?? null,
            'value_c' => $s['value_c'] ?? null,
        ],
    ]);

    if ($dur > CRON_MAX_SECONDS) {
        log_event('warning', 'cron.external', 'Execution exceeded target', ['duration_sec' => $dur]);
    }

    echo "OK\n";
} catch (Throwable $e) {
    log_event('error', 'cron.external', $e->getMessage());
    http_response_code(500);
    echo 'ERR ' . $e->getMessage() . "\n";
} finally {
    lock_release($lock);
}

