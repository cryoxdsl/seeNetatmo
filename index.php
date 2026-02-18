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

$rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0, 'rolling_year' => 0.0];
if (function_exists('rain_totals')) {
    try {
        $rain = rain_totals();
    } catch (Throwable $e) {
        $rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0, 'rolling_year' => 0.0];
    }
}
$dayTemp = ['min' => null, 'max' => null];
if (function_exists('current_day_temp_range')) {
    try {
        $dayTemp = current_day_temp_range();
    } catch (Throwable $e) {
        $dayTemp = ['min' => null, 'max' => null];
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
$alertDesc = match ($alertLevel) {
    'yellow' => t('dashboard.alert_desc_yellow'),
    'orange' => t('dashboard.alert_desc_orange'),
    'red' => t('dashboard.alert_desc_red'),
    default => t('dashboard.alert_desc_green'),
};
$alertBadges = [];
if (isset($alert['alerts']) && is_array($alert['alerts'])) {
    foreach ($alert['alerts'] as $a) {
        if (!is_array($a)) {
            continue;
        }
        $type = trim((string) ($a['type'] ?? 'generic'));
        $level = trim((string) ($a['level'] ?? 'green'));
        if ($type === '') {
            $type = 'generic';
        }
        if (!in_array($level, ['yellow', 'orange', 'red'], true)) {
            $level = 'green';
        }
        $alertBadges[] = ['type' => $type, 'level' => $level];
    }
}
if ($alertBadges === []) {
    $fallbackType = trim((string) ($alert['type'] ?? 'generic'));
    $alertBadges[] = [
        'type' => $fallbackType !== '' ? $fallbackType : 'generic',
        'level' => $alertLevel,
    ];
}
$alertHref = (string) ($alert['url'] ?? 'https://vigilance.meteofrance.fr');
$sea = sea_temp_nearest();
$seaValue = $sea['available'] ? units_format('T', $sea['value_c']) : t('common.na');
$stationLat = station_latitude_setting();
$stationLon = station_longitude_setting();
$stationAlt = station_altitude_setting();
$stationPosition = ($stationLat !== '' && $stationLon !== '') ? ($stationLat . ', ' . $stationLon) : t('common.na');
$stationAltDisplay = $stationAlt !== '' ? (number_format((float) $stationAlt, 0, '.', '') . ' m') : t('common.na');

front_header(t('dashboard.title'));
?>
<section class="panel panel-dashboard season-<?= h($season) ?>" style="background-image:url('<?= h($seasonUrl) ?>')">
  <div class="vigi-badges">
    <?php foreach ($alertBadges as $badge):
      $bLevel = (string) ($badge['level'] ?? 'green');
      $bLabel = match ($bLevel) {
        'yellow' => t('dashboard.alert_level_yellow'),
        'orange' => t('dashboard.alert_level_orange'),
        'red' => t('dashboard.alert_level_red'),
        default => t('dashboard.alert_level_green'),
      };
      $bDesc = match ($bLevel) {
        'yellow' => t('dashboard.alert_desc_yellow'),
        'orange' => t('dashboard.alert_desc_orange'),
        'red' => t('dashboard.alert_desc_red'),
        default => t('dashboard.alert_desc_green'),
      };
      $bTooltip = $bLabel . ': ' . $bDesc;
    ?>
      <a class="vigi-badge vigi-<?= h($bLevel) ?>" href="<?= h($alertHref) ?>" target="_blank" rel="noopener noreferrer" data-tooltip="<?= h($bTooltip) ?>">
        <span class="vigi-icon"><?= vigilance_icon((string) ($badge['type'] ?? 'generic')) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <h2><?= h(t('dashboard.title')) ?></h2>
  <p><?= h(t('dashboard.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <div class="row status-row">
    <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
    <div class="auto-refresh-mini" id="autoRefreshBox">
      <button type="button" class="auto-chip" id="autoRefreshToggle" aria-label="<?= h(t('dashboard.auto_refresh')) ?>">
        <span class="dot" id="autoRefreshDot"></span>
        <span class="label"><?= h(t('dashboard.auto_refresh')) ?></span>
        <span class="count" id="autoRefreshCountdown">05:00</span>
      </button>
      <span class="auto-progress"><span id="autoRefreshProgress"></span></span>
    </div>
  </div>
</section>
<section class="panel weather-hero weather-<?= h($weather['type']) ?>">
  <div class="weather-icon"><?= function_exists('weather_icon_svg') ? weather_icon_svg($weather['type'], weather_icon_style_setting()) : '' ?></div>
  <div class="weather-copy">
    <h3><?= h($weather['label']) ?></h3>
    <p><?= h($weather['detail']) ?></p>
    <p class="weather-trend"><?= h(function_exists('weather_trend_label') ? weather_trend_label($weather['trend']) : t('weather.trend.unavailable')) ?></p>
  </div>
  <div class="weather-temp-side">
    <div class="current-label"><?= h(t('dashboard.current_temp')) ?></div>
    <div class="current-value"><?= h(units_format('T', $row['T'] ?? null)) ?><small><?= h(units_symbol('T')) ?></small></div>
    <div class="day-minmax">
      <div class="min">
        <span class="arrow">↓</span>
        <span class="v"><?= h(units_format('T', $dayTemp['min'])) ?></span>
      </div>
      <div class="max">
        <span class="arrow">↑</span>
        <span class="v"><?= h(units_format('T', $dayTemp['max'])) ?></span>
      </div>
    </div>
  </div>
</section>
<section class="cards">
  <article class="card station-card">
    <h3><?= h(t('station.card_title')) ?></h3>
    <div class="station-visual">
      <svg viewBox="0 0 64 64" aria-hidden="true">
        <path d="M32 6c-9.4 0-17 7.6-17 17 0 12.4 17 35 17 35s17-22.6 17-35c0-9.4-7.6-17-17-17zm0 24a7 7 0 1 1 0-14 7 7 0 0 1 0 14z" fill="#2f6ea4"/>
      </svg>
      <div class="alt-meter"><span style="height: <?= h((string) max(10, min(100, (int) round(((float) ($stationAlt !== '' ? $stationAlt : '0')) / 30)))) ?>%"></span></div>
    </div>
    <p class="small-muted"><?= h(t('station.location')) ?>: <span class="code"><?= h($stationPosition) ?></span></p>
    <p class="small-muted"><?= h(t('station.altitude')) ?>: <strong><?= h($stationAltDisplay) ?></strong></p>
    <p class="small-muted"><?= h(t('station.status')) ?>:
      <span class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></span>
    </p>
    <p class="small-muted"><?= h(t('station.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  </article>
  <article class="card">
    <h3><?= h(t('sea.title')) ?></h3>
    <div><?= h($seaValue . ($sea['available'] ? (' ' . units_symbol('T')) : '')) ?></div>
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
    $displayWithUnit = $display;
    if ($display !== t('common.na')) {
        $symbol = units_symbol($metric);
        if ($symbol !== '') {
            $displayWithUnit .= ' ' . $symbol;
        }
    }
?>
  <article class="card"><h3><?= h(units_metric_name($metric)) ?></h3><div><?= h($displayWithUnit) ?></div></article>
<?php endforeach; ?>
</section>
<section class="panel">
  <h3><?= h(t('rain.total')) ?></h3>
  <div class="cards">
    <?php $rainDay = units_format('R', $rain['day']); ?>
    <?php $rainMonth = units_format('R', $rain['month']); ?>
    <?php $rainYear = units_format('R', $rain['year']); ?>
    <?php $rainRolling = units_format('R', $rain['rolling_year'] ?? 0.0); ?>
    <article class="card"><h3><?= h(t('rain.day_base')) ?></h3><div><?= h($rainDay . ($rainDay !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.month_base')) ?></h3><div><?= h($rainMonth . ($rainMonth !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.year_base')) ?></h3><div><?= h($rainYear . ($rainYear !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.rolling_year_base')) ?></h3><div><?= h($rainRolling . ($rainRolling !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
  </div>
</section>
<script>
(function () {
  var PERIOD = 300;
  var storageKey = 'meteo13_auto_refresh_enabled';
  var enabled = localStorage.getItem(storageKey);
  enabled = enabled === null ? true : enabled === '1';
  var remaining = PERIOD;

  var dot = document.getElementById('autoRefreshDot');
  var progress = document.getElementById('autoRefreshProgress');
  var countdown = document.getElementById('autoRefreshCountdown');
  var toggle = document.getElementById('autoRefreshToggle');
  if (!dot || !progress || !countdown || !toggle) return;

  function fmt(sec) {
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function render() {
    countdown.textContent = fmt(remaining);
    toggle.classList.toggle('is-off', !enabled);
    dot.textContent = enabled ? '' : '||';
    progress.style.width = (enabled ? ((PERIOD - remaining) / PERIOD) : 0) * 100 + '%';
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
