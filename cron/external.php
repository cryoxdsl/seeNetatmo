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

$provided = trim((string) ($_GET['key'] ?? $_GET['k'] ?? $_GET['cron_key'] ?? $_GET['token'] ?? ''));
$cfgSecrets = app_secrets_config();
$candidates = array_values(array_unique(array_filter([
    secret_get('cron_key_external') ?? '',
    secret_get('cron_key_daily') ?? '',
    secret_get('cron_key_fetch') ?? '',
    setting_get('cron_key_external', '') ?? '',
    setting_get('cron_key_daily', '') ?? '',
    setting_get('cron_key_fetch', '') ?? '',
    (string) ($cfgSecrets['cron_key_external'] ?? ''),
    (string) ($cfgSecrets['cron_key_daily'] ?? ''),
    (string) ($cfgSecrets['cron_key_fetch'] ?? ''),
], static fn(string $v): bool => trim($v) !== '')));
$ok = false;
foreach ($candidates as $expected) {
    if (hash_equals(trim($expected), $provided)) {
        $ok = true;
        break;
    }
}
if ($provided === '' || !$ok) {
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
