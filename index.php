<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/weather_condition.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$state = last_update_state();
$rows = latest_rows(2);
$row = $rows[0] ?? null;
$prev = $rows[1] ?? null;
$weather = weather_condition_from_row($row, $state, $prev);

front_header('Dashboard');
?>
<section class="panel">
  <h2>Live dashboard</h2>
  <p>Last update: <strong><?= h($state['last'] ?? 'N/A') ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
</section>
<section class="panel weather-hero weather-<?= h($weather['type']) ?>">
  <div class="weather-icon"><?= weather_icon_svg($weather['type']) ?></div>
  <div class="weather-copy">
    <h3><?= h($weather['label']) ?></h3>
    <p><?= h($weather['detail']) ?></p>
    <p class="weather-trend"><?= h(weather_trend_label($weather['trend'])) ?></p>
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
