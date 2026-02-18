<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/weather_math.php';
require_once __DIR__ . '/../inc/logger.php';
require_once __DIR__ . '/../inc/lock.php';

header('Content-Type: text/plain; charset=utf-8');

if (!app_is_installed()) {
    http_response_code(403);
    exit("Not installed\n");
}

$key = (string) ($_GET['key'] ?? '');
$expected = (string) app_setting('cron_key_daily', '');
if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lock = acquire_lock('cron_daily');
if ($lock === null) {
    http_response_code(429);
    app_log('warning', 'cron.daily', 'Daily skipped because lock is already held');
    exit("Already running\n");
}

$start = microtime(true);

try {
    $table = alldata_table();
    $today = now_paris()->format('Y-m-d');

    $stmt = db()->prepare("SELECT MAX(T) AS tmax, MIN(T) AS tmin FROM `{$table}` WHERE DATE(`DateTime`) = :d AND T IS NOT NULL");
    $stmt->execute([':d' => $today]);
    $agg = $stmt->fetch() ?: ['tmax' => null, 'tmin' => null];
    $tmax = $agg['tmax'] !== null ? (float) $agg['tmax'] : null;
    $tmin = $agg['tmin'] !== null ? (float) $agg['tmin'] : null;

    $rowsStmt = db()->prepare("SELECT `DateTime`, `T`, `H`, `W` FROM `{$table}` WHERE DATE(`DateTime`) = :d");
    $rowsStmt->execute([':d' => $today]);
    $rows = $rowsStmt->fetchAll();

    $upd = db()->prepare("UPDATE `{$table}` SET `Tmax`=:tmax, `Tmin`=:tmin, `D`=:d, `A`=:a WHERE `DateTime`=:dt");
    foreach ($rows as $row) {
        $t = $row['T'] !== null ? (float) $row['T'] : null;
        $h = $row['H'] !== null ? (float) $row['H'] : null;
        $w = $row['W'] !== null ? (float) $row['W'] : null;
        $upd->execute([
            ':tmax' => $tmax,
            ':tmin' => $tmin,
            ':d' => dew_point($t, $h),
            ':a' => apparent_temperature($t, $h, $w),
            ':dt' => $row['DateTime'],
        ]);
    }

    $durationMs = (int) ((microtime(true) - $start) * 1000);
    app_log('info', 'cron.daily', 'Daily recompute done', ['duration_ms' => $durationMs, 'date' => $today, 'rows' => count($rows)]);
    echo "OK {$today}\n";
} catch (Throwable $e) {
    app_log('error', 'cron.daily', $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
} finally {
    release_lock($lock);
}
