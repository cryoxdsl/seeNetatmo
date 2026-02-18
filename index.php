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
  'label' => t('weather.unavailable'),
  'detail' => t('weather.unavailable'),
  'trend' => 'unknown',
];
if (function_exists('weather_condition_from_row')) {
    try {
        $weather = weather_condition_from_row($row, $state, $prev);
    } catch (Throwable $e) {
        $weather = [
          'type' => 'offline',
          'label' => t('weather.unavailable'),
          'detail' => t('weather.unavailable'),
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

front_header(t('dashboard.title'));
?>
<section class="panel">
  <h2><?= h(t('dashboard.title')) ?></h2>
  <p><?= h(t('dashboard.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <div class="row status-row">
    <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
    <div class="auto-refresh" id="autoRefreshBox">
      <span id="autoRefreshLabel"><?= h(t('dashboard.auto_refresh')) ?>: ON</span>
      <span class="code" id="autoRefreshCountdown">05:00</span>
      <button type="button" class="btn-lite" id="autoRefreshToggle"><?= h(t('dashboard.disable')) ?></button>
    </div>
  </div>
</section>
<section class="panel weather-hero weather-<?= h($weather['type']) ?>">
  <div class="weather-icon"><?= function_exists('weather_icon_svg') ? weather_icon_svg($weather['type']) : '' ?></div>
  <div class="weather-copy">
    <h3><?= h($weather['label']) ?></h3>
    <p><?= h($weather['detail']) ?></p>
    <p class="weather-trend"><?= h(function_exists('weather_trend_label') ? weather_trend_label($weather['trend']) : t('weather.trend.unavailable')) ?></p>
  </div>
</section>
<section class="cards">
<?php
$metrics = [
  t('metric.temperature') => $row['T'] ?? null,
  t('metric.humidity') => $row['H'] ?? null,
  t('metric.pressure') => $row['P'] ?? null,
  t('metric.rain_1h') => $row['RR'] ?? null,
  t('metric.rain_day') => $row['R'] ?? null,
  t('metric.wind_avg') => $row['W'] ?? null,
  t('metric.wind_gust') => $row['G'] ?? null,
  t('metric.wind_dir') => $row['B'] ?? null,
  t('metric.dew_point') => $row['D'] ?? null,
  t('metric.apparent') => $row['A'] ?? null,
];
foreach ($metrics as $label => $value):
    $display = t('common.na');
    if ($value !== null) {
        if ($label === t('metric.rain_1h') || $label === t('metric.rain_day')) {
            $display = number_format((float) $value, 1, '.', '');
        } elseif (in_array($label, [t('metric.humidity'), t('metric.pressure'), t('metric.wind_avg'), t('metric.wind_gust'), t('metric.wind_dir')], true)) {
            $display = number_format((float) $value, 0, '.', '');
        } else {
            $display = (string) $value;
        }
    }
?>
  <article class="card"><h3><?= h($label) ?></h3><div><?= h($display) ?></div></article>
<?php endforeach; ?>
</section>
<section class="panel">
  <h3><?= h(t('rain.total')) ?></h3>
  <div class="cards">
    <article class="card"><h3><?= h(t('rain.day')) ?></h3><div><?= h(number_format((float) $rain['day'], 1, '.', '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.month')) ?></h3><div><?= h(number_format((float) $rain['month'], 1, '.', '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.year')) ?></h3><div><?= h(number_format((float) $rain['year'], 1, '.', '')) ?></div></article>
  </div>
</section>
<script>
(function () {
  var PERIOD = 300;
  var storageKey = 'meteo13_auto_refresh_enabled';
  var enabled = localStorage.getItem(storageKey);
  enabled = enabled === null ? true : enabled === '1';
  var remaining = PERIOD;

  var label = document.getElementById('autoRefreshLabel');
  var countdown = document.getElementById('autoRefreshCountdown');
  var toggle = document.getElementById('autoRefreshToggle');
  if (!label || !countdown || !toggle) return;

  function fmt(sec) {
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function render() {
    label.textContent = '<?= h(t('dashboard.auto_refresh')) ?>: ' + (enabled ? 'ON' : 'OFF');
    countdown.textContent = fmt(remaining);
    toggle.textContent = enabled ? '<?= h(t('dashboard.disable')) ?>' : '<?= h(t('dashboard.enable')) ?>';
  }

  toggle.addEventListener('click', function () {
    enabled = !enabled;
    localStorage.setItem(storageKey, enabled ? '1' : '0');
    render();
  });

  setInterval(function () {
    if (remaining <= 0) {
      if (enabled) {
        window.location.reload();
        return;
      }
      remaining = PERIOD;
    }
    remaining--;
    render();
  }, 1000);

  render();
})();
</script>
<?php front_footer();
