<?php
declare(strict_types=1);

$start = microtime(true);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/weather_math.php';
require_once __DIR__ . '/../inc/lock.php';
require_once __DIR__ . '/../inc/logger.php';

header('Content-Type: text/plain; charset=utf-8');
if (!app_is_installed()) {
    http_response_code(403);
    exit("Not installed\n");
}

$provided = (string) ($_GET['key'] ?? '');
$expected = secret_get('cron_key_daily') ?? '';
if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lock = lock_acquire('cron_daily');
if ($lock === null) {
    http_response_code(429);
    exit("Busy\n");
}

try {
    $table = data_table();
    $dates = [now_paris()->format('Y-m-d')];
    if ((string) ($_GET['recalc_yesterday'] ?? '1') === '1') {
        $dates[] = now_paris()->modify('-1 day')->format('Y-m-d');
    }

    $update = db()->prepare("UPDATE `{$table}` SET `Tmax`=:tmax, `Tmin`=:tmin, `D`=:d, `A`=:a WHERE `DateTime`=:dt");

    foreach ($dates as $date) {
        $agg = db()->prepare("SELECT MAX(T) tmax, MIN(T) tmin FROM `{$table}` WHERE DATE(`DateTime`)=:d AND T IS NOT NULL");
        $agg->execute([':d' => $date]);
        $mm = $agg->fetch() ?: ['tmax' => null, 'tmin' => null];

        $rows = db()->prepare("SELECT `DateTime`,`T`,`H`,`W` FROM `{$table}` WHERE DATE(`DateTime`)=:d");
        $rows->execute([':d' => $date]);

        foreach ($rows->fetchAll() as $r) {
            $t = $r['T'] !== null ? (float) $r['T'] : null;
            $h = $r['H'] !== null ? (float) $r['H'] : null;
            $w = $r['W'] !== null ? (float) $r['W'] : null;
            $update->execute([
                ':tmax' => $mm['tmax'] !== null ? (float) $mm['tmax'] : null,
                ':tmin' => $mm['tmin'] !== null ? (float) $mm['tmin'] : null,
                ':d' => dew_point_magnus($t, $h),
                ':a' => apparent_temp($t, $h, $w),
                ':dt' => $r['DateTime'],
            ]);
        }
    }

    $dur = round(microtime(true) - $start, 3);
    log_event('info', 'cron.daily', 'Daily recompute success', ['dates' => $dates, 'duration_sec' => $dur]);
    if ($dur > CRON_MAX_SECONDS) {
        log_event('warning', 'cron.daily', 'Execution exceeded target', ['duration_sec' => $dur]);
    }
    echo "OK\n";
} catch (Throwable $e) {
    log_event('error', 'cron.daily', $e->getMessage());
    http_response_code(500);
    echo 'ERR ' . $e->getMessage() . "\n";
} finally {
    lock_release($lock);
}
