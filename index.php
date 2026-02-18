<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/vigilance.php';
require_once __DIR__ . '/inc/sea_temp.php';
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

$month = (int) now_paris()->format('n');
$season = match (true) {
    in_array($month, [3, 4, 5], true) => 'spring',
    in_array($month, [6, 7, 8], true) => 'summer',
    in_array($month, [9, 10, 11], true) => 'autumn',
    default => 'winter',
};
$seasonFile = __DIR__ . '/assets/img/seasons/' . $season . '.svg';
$seasonUrl = '/assets/img/seasons/' . $season . '.svg';
if (is_file($seasonFile)) {
    $seasonUrl .= '?v=' . filemtime($seasonFile);
}
$alert = vigilance_current();
$alertLevel = (string) ($alert['level'] ?? 'green');
$alertLabel = match ($alertLevel) {
    'yellow' => t('dashboard.alert_level_yellow'),
    'orange' => t('dashboard.alert_level_orange'),
    'red' => t('dashboard.alert_level_red'),
    default => t('dashboard.alert_level_green'),
};
$alertType = trim((string) ($alert['phenomenon'] ?? ''));
$tooltip = t('dashboard.alert_label') . ': ' . $alertLabel;
if ($alertType !== '') {
    $tooltip .= "\n" . t('dashboard.alert_type') . ': ' . $alertType;
}
if (($alert['period_text'] ?? '') !== '') {
    $tooltip .= "\n" . t('dashboard.alert_period') . ': ' . (string) $alert['period_text'];
}
if (($alert['updated_text'] ?? '') !== '') {
    $tooltip .= "\n" . t('dashboard.alert_updated') . ': ' . (string) $alert['updated_text'];
}
$tooltip .= "\n" . t('dashboard.alert_source');
$alertIcon = vigilance_icon((string) ($alert['type'] ?? 'generic'));
$alertHref = (string) ($alert['url'] ?? 'https://vigilance.meteofrance.fr');
$sea = sea_temp_nearest();
$seaValue = $sea['available'] ? units_format('T', $sea['value_c']) : t('common.na');

front_header(t('dashboard.title'));
?>
<section class="panel panel-dashboard season-<?= h($season) ?>" style="background-image:url('<?= h($seasonUrl) ?>')">
  <a class="vigi-badge vigi-<?= h($alertLevel) ?>" href="<?= h($alertHref) ?>" target="_blank" rel="noopener noreferrer" data-tooltip="<?= h($tooltip) ?>">
    <span class="vigi-icon"><?= $alertIcon ?></span>
  </a>
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
  <article class="card">
    <h3><?= h(t('sea.title') . ' (' . units_symbol('T') . ')') ?></h3>
    <div><?= h($seaValue) ?></div>
    <p class="small-muted"><?= h(t('sea.subtitle')) ?></p>
    <?php if (!empty($sea['distance_km'])): ?>
      <p class="small-muted"><?= h(t('sea.distance')) ?>: <?= h(number_format((float) $sea['distance_km'], 1, '.', '')) ?> km</p>
    <?php endif; ?>
    <?php if (!empty($sea['time'])): ?>
      <p class="small-muted"><?= h(t('sea.updated')) ?>: <?= h((string) $sea['time']) ?></p>
    <?php endif; ?>
  </article>
</section>
<section class="cards">
<?php
$metrics = [
  'T' => $row['T'] ?? null,
  'H' => $row['H'] ?? null,
  'P' => $row['P'] ?? null,
  'RR' => $row['RR'] ?? null,
  'R' => $row['R'] ?? null,
  'W' => $row['W'] ?? null,
  'G' => $row['G'] ?? null,
  'B' => $row['B'] ?? null,
  'D' => $row['D'] ?? null,
  'A' => $row['A'] ?? null,
];
foreach ($metrics as $metric => $value):
    $display = units_format($metric, $value);
?>
  <article class="card"><h3><?= h(units_metric_label($metric)) ?></h3><div><?= h($display) ?></div></article>
<?php endforeach; ?>
</section>
<section class="panel">
  <h3><?= h(t('rain.total')) ?></h3>
  <div class="cards">
    <article class="card"><h3><?= h(t('rain.day_base') . ' (' . units_symbol('R') . ')') ?></h3><div><?= h(units_format('R', $rain['day'])) ?></div></article>
    <article class="card"><h3><?= h(t('rain.month_base') . ' (' . units_symbol('R') . ')') ?></h3><div><?= h(units_format('R', $rain['month'])) ?></div></article>
    <article class="card"><h3><?= h(t('rain.year_base') . ' (' . units_symbol('R') . ')') ?></h3><div><?= h(units_format('R', $rain['year'])) ?></div></article>
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
