<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/weather_math.php';
require_once __DIR__ . '/../inc/logger.php';
require_once __DIR__ . '/../inc/lock.php';

header('Content-Type: text/plain; charset=utf-8');

if (!app_is_installed()) {
    http_response_code(403);
    exit("Not installed\n");
}

$key = (string) ($_GET['key'] ?? '');
$expected = (string) app_setting('cron_key_fetch', '');
if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lock = acquire_lock('cron_fetch');
if ($lock === null) {
    http_response_code(429);
    app_log('warning', 'cron.fetch', 'Fetch skipped because lock is already held');
    exit("Already running\n");
}

$start = microtime(true);

try {
    $table = alldata_table();
    $data = netatmo_fetch_station_data();
    $dt = floor_to_5_minutes(now_paris())->format('Y-m-d H:i:s');

    $t = isset($data['T']) ? (float) $data['T'] : null;
    $h = isset($data['H']) ? (float) $data['H'] : null;
    $w = isset($data['W']) ? (float) $data['W'] : null;

    $dew = dew_point($t, $h);
    $app = apparent_temperature($t, $h, $w);

    $sql = "INSERT INTO `{$table}`
        (`DateTime`,`T`,`Tmax`,`Tmin`,`H`,`D`,`W`,`G`,`B`,`RR`,`R`,`P`,`A`)
        VALUES
        (:DateTime,:T,:Tmax,:Tmin,:H,:D,:W,:G,:B,:RR,:R,:P,:A)
        ON DUPLICATE KEY UPDATE
        `T` = COALESCE(VALUES(`T`), `T`),
        `Tmax` = COALESCE(VALUES(`Tmax`), `Tmax`),
        `Tmin` = COALESCE(VALUES(`Tmin`), `Tmin`),
        `H` = COALESCE(VALUES(`H`), `H`),
        `D` = COALESCE(VALUES(`D`), `D`),
        `W` = COALESCE(VALUES(`W`), `W`),
        `G` = COALESCE(VALUES(`G`), `G`),
        `B` = COALESCE(VALUES(`B`), `B`),
        `RR` = COALESCE(VALUES(`RR`), `RR`),
        `R` = COALESCE(VALUES(`R`), `R`),
        `P` = COALESCE(VALUES(`P`), `P`),
        `A` = COALESCE(VALUES(`A`), `A`)";

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':DateTime' => $dt,
        ':T' => $t !== null ? round($t, 1) : null,
        ':Tmax' => $t !== null ? round($t, 1) : null,
        ':Tmin' => $t !== null ? round($t, 1) : null,
        ':H' => $h !== null ? round($h, 1) : null,
        ':D' => $dew,
        ':W' => $w !== null ? round($w, 1) : null,
        ':G' => isset($data['G']) && $data['G'] !== null ? round((float) $data['G'], 1) : null,
        ':B' => isset($data['B']) && $data['B'] !== null ? round((float) $data['B'], 1) : null,
        ':RR' => isset($data['RR']) && $data['RR'] !== null ? round((float) $data['RR'], 3) : null,
        ':R' => isset($data['R']) && $data['R'] !== null ? round((float) $data['R'], 3) : null,
        ':P' => isset($data['P']) && $data['P'] !== null ? round((float) $data['P'], 3) : null,
        ':A' => $app,
    ]);

    $durationMs = (int) ((microtime(true) - $start) * 1000);
    app_log('info', 'cron.fetch', 'Netatmo data stored', ['duration_ms' => $durationMs, 'datetime' => $dt, 'connectivity' => [
        'outdoor' => $data['outdoor_connected'],
        'rain' => $data['rain_connected'],
        'wind' => $data['wind_connected'],
    ]]);

    echo "OK {$dt}\n";
} catch (Throwable $e) {
    app_log('error', 'cron.fetch', $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
} finally {
    release_lock($lock);
}
