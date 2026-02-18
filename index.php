<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';
if (is_file(__DIR__ . '/inc/weather_condition.php')) {
    require_once __DIR__ . '/inc/weather_condition.php';
}

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$state = ['last' => null, 'age' => null, 'disconnected' => true];
$row = null;
$prev = null;
try {
    if (function_exists('last_update_state')) {
        $state = last_update_state();
    }
    if (function_exists('latest_rows')) {
        $rows = latest_rows(2);
        $row = $rows[0] ?? null;
        $prev = $rows[1] ?? null;
    } elseif (function_exists('latest_row')) {
        $row = latest_row();
    }
} catch (Throwable $e) {
    $state = ['last' => null, 'age' => null, 'disconnected' => true];
    $row = null;
    $prev = null;
}
$weather = [
  'type' => 'offline',
  'label' => 'Meteo indisponible',
  'detail' => 'Module visuel non charge',
  'trend' => 'unknown',
];
if (function_exists('weather_condition_from_row')) {
    try {
        $weather = weather_condition_from_row($row, $state, $prev);
    } catch (Throwable $e) {
        $weather = [
          'type' => 'offline',
          'label' => 'Meteo indisponible',
          'detail' => 'Erreur de chargement de la tendance',
          'trend' => 'unknown',
        ];
    }
}

$rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0];
if (function_exists('rain_totals')) {
    try {
        $rain = rain_totals();
    } catch (Throwable $e) {
        $rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0];
    }
}

front_header('Dashboard');
?>
<section class="panel">
  <h2>Live dashboard</h2>
  <p>Last update: <strong><?= h($state['last'] ?? 'N/A') ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
</section>
<section class="panel weather-hero weather-<?= h($weather['type']) ?>">
  <div class="weather-icon"><?= function_exists('weather_icon_svg') ? weather_icon_svg($weather['type']) : '' ?></div>
  <div class="weather-copy">
    <h3><?= h($weather['label']) ?></h3>
    <p><?= h($weather['detail']) ?></p>
    <p class="weather-trend"><?= h(function_exists('weather_trend_label') ? weather_trend_label($weather['trend']) : 'Tendance indisponible') ?></p>
  </div>
</section>
<section class="panel">
  <h3>Pluviometrie cumulee</h3>
  <div class="cards">
    <article class="card"><h3>Jour (mm)</h3><div><?= h(number_format((float) $rain['day'], 3, '.', '')) ?></div></article>
    <article class="card"><h3>Mois (mm)</h3><div><?= h(number_format((float) $rain['month'], 3, '.', '')) ?></div></article>
    <article class="card"><h3>Annee (mm)</h3><div><?= h(number_format((float) $rain['year'], 3, '.', '')) ?></div></article>
  </div>
</section>
<section class="cards">
<?php
$metrics = [
  'Temperature (°C)' => $row['T'] ?? null,
  'Humidity (%)' => $row['H'] ?? null,
  'Pressure (hPa)' => $row['P'] ?? null,
  'Rain 1h (mm)' => $row['RR'] ?? null,
  'Rain day (mm)' => $row['R'] ?? null,
  'Wind avg (km/h)' => $row['W'] ?? null,
  'Wind gust (km/h)' => $row['G'] ?? null,
  'Wind dir (°)' => $row['B'] ?? null,
  'Dew point (°C)' => $row['D'] ?? null,
  'Apparent (°C)' => $row['A'] ?? null,
];
foreach ($metrics as $label => $value): ?>
  <article class="card"><h3><?= h($label) ?></h3><div><?= $value === null ? 'N/A' : h((string) $value) ?></div></article>
<?php endforeach; ?>
</section>
<?php front_footer();
