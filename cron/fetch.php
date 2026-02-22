<?php
declare(strict_types=1);

$start = microtime(true);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/weather_math.php';
require_once __DIR__ . '/../inc/lock.php';
require_once __DIR__ . '/../inc/logger.php';

header('Content-Type: text/plain; charset=utf-8');
if (!app_is_installed()) {
    http_response_code(403);
    exit("Not installed\n");
}

$provided = request_bearer_token();
if ($provided === '') {
    $provided = (string) ($_GET['key'] ?? '');
}
$expected = secret_get('cron_key_fetch') ?? '';
if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lock = null;
try {
    $lock = lock_acquire('cron_fetch');
} catch (Throwable $e) {
    log_event('error', 'cron.fetch', 'Lock acquire failed', ['err' => $e->getMessage()]);
    http_response_code(500);
    exit("Lock error\n");
}
if ($lock === null) {
    http_response_code(429);
    exit("Busy\n");
}

try {
    $w = netatmo_fetch_weather();
    $dt = floor_5min(now_paris())->format('Y-m-d H:i:s');

    $t = $w['T'] !== null ? (float) $w['T'] : null;
    $h = $w['H'] !== null ? (float) $w['H'] : null;
    $wind = $w['W'] !== null ? (float) $w['W'] : null;

    $row = [
        'DateTime' => $dt,
        'T' => $t,
        'Tmax' => $t,
        'Tmin' => $t,
        'H' => $h,
        'D' => dew_point_magnus($t, $h),
        'W' => $wind,
        'G' => $w['G'] !== null ? (float) $w['G'] : null,
        'B' => $w['B'] !== null ? (float) $w['B'] : null,
        'RR' => $w['RR'] !== null ? (float) $w['RR'] : null,
        'R' => $w['R'] !== null ? (float) $w['R'] : null,
        'P' => $w['P'] !== null ? (float) $w['P'] : null,
        'A' => apparent_temp($t, $h, $wind),
    ];

    $table = data_table();
    $sql = "INSERT INTO `{$table}` (`DateTime`,`T`,`Tmax`,`Tmin`,`H`,`D`,`W`,`G`,`B`,`RR`,`R`,`P`,`A`) VALUES
      (:DateTime,:T,:Tmax,:Tmin,:H,:D,:W,:G,:B,:RR,:R,:P,:A)
      ON DUPLICATE KEY UPDATE
      `T`=COALESCE(VALUES(`T`),`T`),
      `Tmax`=COALESCE(VALUES(`Tmax`),`Tmax`),
      `Tmin`=COALESCE(VALUES(`Tmin`),`Tmin`),
      `H`=COALESCE(VALUES(`H`),`H`),
      `D`=COALESCE(VALUES(`D`),`D`),
      `W`=COALESCE(VALUES(`W`),`W`),
      `G`=COALESCE(VALUES(`G`),`G`),
      `B`=COALESCE(VALUES(`B`),`B`),
      `RR`=COALESCE(VALUES(`RR`),`RR`),
      `R`=COALESCE(VALUES(`R`),`R`),
      `P`=COALESCE(VALUES(`P`),`P`),
      `A`=COALESCE(VALUES(`A`),`A`)";

    db()->prepare($sql)->execute($row);

    $dur = round(microtime(true) - $start, 3);
    if (!station_position_locked()) {
        $zip = trim((string) ($w['station_zipcode'] ?? ''));
        if ($zip !== '') {
            setting_set('station_zipcode', $zip);
        }
        if (isset($w['station_lat']) && $w['station_lat'] !== null && is_numeric((string) $w['station_lat'])) {
            setting_set('station_lat', (string) ((float) $w['station_lat']));
        }
        if (isset($w['station_lon']) && $w['station_lon'] !== null && is_numeric((string) $w['station_lon'])) {
            setting_set('station_lon', (string) ((float) $w['station_lon']));
        }
        if (isset($w['station_altitude']) && $w['station_altitude'] !== null && is_numeric((string) $w['station_altitude'])) {
            setting_set('station_altitude', (string) ((float) $w['station_altitude']));
        }
    }

    log_event('info', 'cron.fetch', 'Fetch success', ['dt' => $dt, 'duration_sec' => $dur, 'mods' => [
        'outdoor' => $w['mod_outdoor'],
        'rain' => $w['mod_rain'],
        'wind' => $w['mod_wind'],
    ], 'modules' => $w['module_debug'] ?? []]);

    if ($dur > CRON_MAX_SECONDS) {
        log_event('warning', 'cron.fetch', 'Execution exceeded target', ['duration_sec' => $dur]);
    }
    if ($row['T'] === null || $row['H'] === null) {
        log_event('warning', 'cron.fetch', 'Outdoor data missing (T/H is null)', ['mods' => [
            'outdoor' => $w['mod_outdoor'],
            'rain' => $w['mod_rain'],
            'wind' => $w['mod_wind'],
        ], 'modules' => $w['module_debug'] ?? []]);
    }

    echo "OK {$dt}\n";
} catch (Throwable $e) {
    log_event('error', 'cron.fetch', $e->getMessage());
    http_response_code(500);
    echo 'ERR ' . $e->getMessage() . "\n";
} finally {
    lock_release($lock);
}
