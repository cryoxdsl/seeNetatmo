<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/sea_temp.php';
require_once __DIR__ . '/inc/forecast.php';
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
$sea = sea_temp_nearest();
$seaValue = $sea['available'] ? units_format('T', $sea['value_c']) : t('common.na');
$forecast = forecast_summary(true);
$forecastReason = (string) ($forecast['reason'] ?? '');
$forecastCurrentType = (string) ($forecast['current_type'] ?? 'cloudy');
$forecastCurrentLabel = t((string) ($forecast['current_label_key'] ?? 'forecast.condition.unknown'));
$forecastCurrentTemp = units_format('T', $forecast['current_temp_c'] ?? null);
$forecastCurrentTempDisplay = $forecastCurrentTemp;
if ($forecastCurrentTemp !== t('common.na')) {
    $forecastCurrentTempDisplay .= ' ' . units_symbol('T');
}
$forecastTodayMin = units_format('T', $forecast['today_min_c'] ?? null);
$forecastTodayMax = units_format('T', $forecast['today_max_c'] ?? null);
$forecastTomorrowMin = units_format('T', $forecast['tomorrow_min_c'] ?? null);
$forecastTomorrowMax = units_format('T', $forecast['tomorrow_max_c'] ?? null);
$forecastTodayPop = isset($forecast['today_pop']) && $forecast['today_pop'] !== null
    ? ((string) ((int) $forecast['today_pop']) . '%')
    : t('common.na');
$forecastTomorrowPop = isset($forecast['tomorrow_pop']) && $forecast['tomorrow_pop'] !== null
    ? ((string) ((int) $forecast['tomorrow_pop']) . '%')
    : t('common.na');
$forecastNa = t('common.na');
$forecastTodayRange = ($forecastTodayMin === $forecastNa && $forecastTodayMax === $forecastNa)
    ? $forecastNa
    : ($forecastTodayMin . ' / ' . $forecastTodayMax . ' ' . units_symbol('T'));
$forecastTomorrowRange = ($forecastTomorrowMin === $forecastNa && $forecastTomorrowMax === $forecastNa)
    ? $forecastNa
    : ($forecastTomorrowMin . ' / ' . $forecastTomorrowMax . ' ' . units_symbol('T'));
$forecastUpdated = trim((string) ($forecast['updated_at'] ?? ''));
$forecastUnavailableMsg = t('forecast.unavailable');
if ($forecastReason === 'no_station_coords') {
    $forecastUnavailableMsg = t('forecast.coords_required');
} elseif ($forecastReason === 'retry_later') {
    $forecastUnavailableMsg = t('forecast.retry_later');
}
$stationLat = station_latitude_setting();
$stationLon = station_longitude_setting();
$stationAlt = station_altitude_setting();
$stationPosition = ($stationLat !== '' && $stationLon !== '') ? ($stationLat . ', ' . $stationLon) : t('common.na');
$stationAltDisplay = $stationAlt !== '' ? (number_format((float) $stationAlt, 0, '.', '') . ' m') : t('common.na');
$stationMapUrl = null;
if ($stationLat !== '' && $stationLon !== '' && is_numeric($stationLat) && is_numeric($stationLon)) {
    $mapLat = number_format((float) $stationLat, 6, '.', '');
    $mapLon = number_format((float) $stationLon, 6, '.', '');
    $stationMapUrl = 'https://www.openstreetmap.org/?mlat=' . $mapLat . '&mlon=' . $mapLon . '#map=15/' . $mapLat . '/' . $mapLon;
}
$dayMinDisplay = units_format('T', $dayTemp['min'] ?? null);
if ($dayMinDisplay !== t('common.na')) {
    $dayMinDisplay .= ' ' . units_symbol('T');
}
$dayMaxDisplay = units_format('T', $dayTemp['max'] ?? null);
if ($dayMaxDisplay !== t('common.na')) {
    $dayMaxDisplay .= ' ' . units_symbol('T');
}
$sunriseDisplay = t('common.na');
$sunsetDisplay = t('common.na');
if ($stationLat !== '' && $stationLon !== '' && is_numeric($stationLat) && is_numeric($stationLon)) {
    $sunInfo = date_sun_info(now_paris()->getTimestamp(), (float) $stationLat, (float) $stationLon);
    if (is_array($sunInfo)) {
        if (isset($sunInfo['sunrise']) && is_numeric($sunInfo['sunrise'])) {
            $sunriseDisplay = date('H:i', (int) $sunInfo['sunrise']);
        }
        if (isset($sunInfo['sunset']) && is_numeric($sunInfo['sunset'])) {
            $sunsetDisplay = date('H:i', (int) $sunInfo['sunset']);
        }
    }
}
$nowRain = now_paris();
$rainDayLabel = t('rain.day_base') . ' (' . $nowRain->format('d/m/Y') . ')';
$monthNames = locale_current() === 'en_EN'
    ? [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December']
    : [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$monthNum = (int) $nowRain->format('n');
$monthName = $monthNames[$monthNum] ?? '';
if ($monthName !== '') {
    $monthName = strtoupper(substr($monthName, 0, 1)) . substr($monthName, 1);
}
$rainMonthLabel = t('rain.month_base') . ' (' . trim($monthName . ' ' . $nowRain->format('Y')) . ')';
$rainYearLabel = t('rain.year_base') . ' (' . $nowRain->format('Y') . ')';

front_header(t('dashboard.title'));
?>
<section class="panel panel-dashboard season-<?= h($season) ?>" style="background-image:url('<?= h($seasonUrl) ?>')">
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
    <div class="current-value" data-live-key="current_temp" data-live-value="<?= h(isset($row['T']) && $row['T'] !== null ? (string) $row['T'] : '') ?>"><?= h(units_format('T', $row['T'] ?? null)) ?><small><?= h(units_symbol('T')) ?></small></div>
    <div class="day-minmax">
      <div class="min">
        <span class="arrow">↓</span>
        <span class="v" data-live-key="day_min" data-live-value="<?= h(isset($dayTemp['min']) && $dayTemp['min'] !== null ? (string) $dayTemp['min'] : '') ?>"><?= h(units_format('T', $dayTemp['min'])) ?></span>
      </div>
      <div class="max">
        <span class="arrow">↑</span>
        <span class="v" data-live-key="day_max" data-live-value="<?= h(isset($dayTemp['max']) && $dayTemp['max'] !== null ? (string) $dayTemp['max'] : '') ?>"><?= h(units_format('T', $dayTemp['max'])) ?></span>
      </div>
    </div>
  </div>
</section>
<section class="cards">
  <article class="card station-card">
    <h3><?= h(t('station.card_title')) ?></h3>
    <p class="small-muted station-line">
      <span class="station-label"><?= h(t('station.location')) ?></span>
      <span class="station-location-value">
        <span class="code"><?= h($stationPosition) ?></span>
        <?php if ($stationMapUrl !== null): ?>
          <a class="station-map-link" href="<?= h($stationMapUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="OpenStreetMap">
            <svg viewBox="0 0 64 64" aria-hidden="true">
              <path d="M32 6c-9.4 0-17 7.6-17 17 0 12.4 17 35 17 35s17-22.6 17-35c0-9.4-7.6-17-17-17zm0 24a7 7 0 1 1 0-14 7 7 0 0 1 0 14z" fill="currentColor"/>
            </svg>
          </a>
        <?php endif; ?>
      </span>
    </p>
    <p class="small-muted station-line"><span class="station-label"><?= h(t('station.altitude')) ?></span><strong><?= h($stationAltDisplay) ?></strong></p>
    <p class="small-muted station-line"><span class="station-label"><?= h(t('station.status')) ?></span>
      <span class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></span>
    </p>
    <p class="small-muted station-line"><span class="station-label"><?= h(t('station.last_update')) ?></span><strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  </article>
  <article class="card">
    <h3><?= h(t('sea.title')) ?></h3>
    <div data-live-key="sea_temp" data-live-value="<?= h(!empty($sea['available']) && isset($sea['value_c']) && $sea['value_c'] !== null ? (string) $sea['value_c'] : '') ?>"><?= h($seaValue . ($sea['available'] ? (' ' . units_symbol('T')) : '')) ?></div>
    <p class="small-muted"><?= h(t('sea.subtitle')) ?></p>
    <?php if (!empty($sea['distance_km'])): ?>
      <p class="small-muted"><?= h(t('sea.distance')) ?>: <?= h(number_format((float) $sea['distance_km'], 1, '.', '')) ?> km</p>
    <?php endif; ?>
    <?php if (!empty($sea['time'])): ?>
      <p class="small-muted"><?= h(t('sea.updated')) ?>: <?= h((string) $sea['time']) ?></p>
    <?php endif; ?>
  </article>
  <article class="card forecast-card">
    <h3><?= h(t('forecast.title')) ?></h3>
    <?php if (!empty($forecast['available'])): ?>
      <div class="forecast-head">
        <span class="forecast-icon"><?= function_exists('weather_icon_svg') ? weather_icon_svg($forecastCurrentType, weather_icon_style_setting()) : '' ?></span>
        <div class="forecast-current">
          <div class="forecast-value" data-live-key="forecast_current_temp" data-live-value="<?= h(isset($forecast['current_temp_c']) && $forecast['current_temp_c'] !== null ? (string) $forecast['current_temp_c'] : '') ?>"><?= h($forecastCurrentTempDisplay) ?></div>
          <p class="small-muted"><?= h($forecastCurrentLabel) ?></p>
        </div>
      </div>
      <div class="forecast-grid">
        <div class="forecast-day">
          <strong><?= h(t('forecast.today')) ?></strong>
          <p class="forecast-line"><?= h($forecastTodayRange) ?></p>
          <p class="forecast-line"><?= h(t('forecast.precip')) ?>: <?= h($forecastTodayPop) ?></p>
        </div>
        <div class="forecast-day">
          <strong><?= h(t('forecast.tomorrow')) ?></strong>
          <p class="forecast-line"><?= h($forecastTomorrowRange) ?></p>
          <p class="forecast-line"><?= h(t('forecast.precip')) ?>: <?= h($forecastTomorrowPop) ?></p>
        </div>
      </div>
      <?php if ($forecastUpdated !== ''): ?>
        <p class="small-muted"><?= h(t('forecast.updated')) ?>: <?= h($forecastUpdated) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <div><?= h(t('forecast.unavailable')) ?></div>
      <p class="small-muted"><?= h($forecastUnavailableMsg) ?></p>
    <?php endif; ?>
  </article>
  <article class="card extremes-card">
    <h3><?= h(t('extremes.card_title')) ?></h3>
    <div class="extremes-grid">
      <p class="extremes-line">
        <span class="extremes-label"><?= h(t('extremes.day_min')) ?></span>
        <strong data-live-key="extremes_day_min" data-live-value="<?= h(isset($dayTemp['min']) && $dayTemp['min'] !== null ? (string) $dayTemp['min'] : '') ?>"><?= h($dayMinDisplay) ?></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><?= h(t('extremes.day_max')) ?></span>
        <strong data-live-key="extremes_day_max" data-live-value="<?= h(isset($dayTemp['max']) && $dayTemp['max'] !== null ? (string) $dayTemp['max'] : '') ?>"><?= h($dayMaxDisplay) ?></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><?= h(t('extremes.sunrise')) ?></span>
        <strong><?= h($sunriseDisplay) ?></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><?= h(t('extremes.sunset')) ?></span>
        <strong><?= h($sunsetDisplay) ?></strong>
      </p>
    </div>
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
  <article class="card"><h3><?= h(units_metric_name($metric)) ?></h3><div data-live-key="metric_<?= h(strtolower((string) $metric)) ?>" data-live-value="<?= h($value === null ? '' : (string) $value) ?>"><?= h($displayWithUnit) ?></div></article>
<?php endforeach; ?>
</section>
<section class="panel">
  <h3><?= h(t('rain.total')) ?></h3>
  <div class="cards">
    <?php $rainDay = units_format('R', $rain['day']); ?>
    <?php $rainMonth = units_format('R', $rain['month']); ?>
    <?php $rainYear = units_format('R', $rain['year']); ?>
    <?php $rainRolling = units_format('R', $rain['rolling_year'] ?? 0.0); ?>
    <article class="card"><h3><?= h($rainDayLabel) ?></h3><div data-live-key="rain_day" data-live-value="<?= h(isset($rain['day']) ? (string) $rain['day'] : '') ?>"><?= h($rainDay . ($rainDay !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h($rainMonthLabel) ?></h3><div data-live-key="rain_month" data-live-value="<?= h(isset($rain['month']) ? (string) $rain['month'] : '') ?>"><?= h($rainMonth . ($rainMonth !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h($rainYearLabel) ?></h3><div data-live-key="rain_year" data-live-value="<?= h(isset($rain['year']) ? (string) $rain['year'] : '') ?>"><?= h($rainYear . ($rainYear !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
    <article class="card"><h3><?= h(t('rain.rolling_year_base')) ?></h3><div data-live-key="rain_rolling_year" data-live-value="<?= h(isset($rain['rolling_year']) ? (string) $rain['rolling_year'] : '') ?>"><?= h($rainRolling . ($rainRolling !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div></article>
  </div>
</section>
<script>
(function () {
  var PERIOD = 300;
  var storageKey = 'meteo13_auto_refresh_enabled';
  var lastReloadKey = 'meteo13_auto_refresh_last_reload_ts';
  var refreshIntentKey = 'meteo13_auto_refresh_intent';
  var valuesSnapshotKey = 'meteo13_live_values_snapshot_v1';
  var reloadCooldownMs = 15000;
  var reloadingText = <?= json_encode(t('dashboard.auto_refresh_reloading'), JSON_UNESCAPED_UNICODE) ?>;
  var enabled = localStorage.getItem(storageKey);
  enabled = enabled === null ? true : enabled === '1';
  var remaining = PERIOD;
  var isReloading = false;
  var timerId = null;

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

  function getLastReloadTs() {
    try {
      var raw = sessionStorage.getItem(lastReloadKey) || '0';
      var n = parseInt(raw, 10);
      return Number.isFinite(n) ? n : 0;
    } catch (e) {
      return 0;
    }
  }

  function setLastReloadTs(ts) {
    try {
      sessionStorage.setItem(lastReloadKey, String(ts));
    } catch (e) {}
  }

  function collectLiveValues() {
    var values = {};
    var nodes = {};
    var all = document.querySelectorAll('[data-live-key][data-live-value]');
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      var key = el.getAttribute('data-live-key') || '';
      if (!key) continue;
      values[key] = el.getAttribute('data-live-value') || '';
      nodes[key] = el;
    }
    return { values: values, nodes: nodes };
  }

  function readValuesSnapshot() {
    try {
      var raw = sessionStorage.getItem(valuesSnapshotKey);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function writeValuesSnapshot(values) {
    try {
      sessionStorage.setItem(valuesSnapshotKey, JSON.stringify(values || {}));
    } catch (e) {}
  }

  function toNumeric(v) {
    if (typeof v !== 'string') return NaN;
    var normalized = v.replace(',', '.').replace(/[^0-9.\-]/g, '');
    if (!normalized || normalized === '-' || normalized === '.' || normalized === '-.') return NaN;
    var out = parseFloat(normalized);
    return Number.isFinite(out) ? out : NaN;
  }

  function markUpdated(node, direction) {
    if (!node) return;
    node.classList.remove('live-updated', 'live-updated-up', 'live-updated-down');
    void node.offsetWidth;
    node.classList.add('live-updated');
    if (direction > 0) {
      node.classList.add('live-updated-up');
    } else if (direction < 0) {
      node.classList.add('live-updated-down');
    }
    setTimeout(function () {
      node.classList.remove('live-updated', 'live-updated-up', 'live-updated-down');
    }, 2200);
  }

  function initValueUpdateEffects() {
    var current = collectLiveValues();
    var currentValues = current.values;
    var currentNodes = current.nodes;
    var isAutoRefreshNavigation = false;
    try {
      isAutoRefreshNavigation = sessionStorage.getItem(refreshIntentKey) === '1';
    } catch (e) {}

    if (isAutoRefreshNavigation) {
      var previousValues = readValuesSnapshot();
      for (var key in currentValues) {
        if (!Object.prototype.hasOwnProperty.call(currentValues, key)) continue;
        var prevVal = Object.prototype.hasOwnProperty.call(previousValues, key) ? String(previousValues[key]) : null;
        var currVal = String(currentValues[key]);
        if (prevVal === null || prevVal === currVal) continue;

        var direction = 0;
        var prevNum = toNumeric(prevVal);
        var currNum = toNumeric(currVal);
        if (!Number.isNaN(prevNum) && !Number.isNaN(currNum)) {
          if (currNum > prevNum) direction = 1;
          else if (currNum < prevNum) direction = -1;
        }
        markUpdated(currentNodes[key], direction);
      }
      try { sessionStorage.removeItem(refreshIntentKey); } catch (e) {}
    }

    writeValuesSnapshot(currentValues);
  }

  function render() {
    if (isReloading) {
      countdown.textContent = reloadingText;
      toggle.classList.add('is-loading');
      dot.textContent = '';
      progress.style.width = '100%';
      return;
    }
    toggle.classList.remove('is-loading');
    countdown.textContent = fmt(remaining);
    toggle.classList.toggle('is-off', !enabled);
    dot.textContent = enabled ? '' : '||';
    progress.style.width = (enabled ? ((PERIOD - remaining) / PERIOD) : 0) * 100 + '%';
  }

  toggle.addEventListener('click', function () {
    if (isReloading) {
      return;
    }
    enabled = !enabled;
    localStorage.setItem(storageKey, enabled ? '1' : '0');
    render();
  });

  timerId = setInterval(function () {
    if (isReloading) {
      return;
    }
    if (!enabled) {
      render();
      return;
    }
    if (remaining <= 0) {
      var nowTs = Date.now();
      var lastTs = getLastReloadTs();
      if (lastTs > 0 && (nowTs - lastTs) < reloadCooldownMs) {
        enabled = false;
        localStorage.setItem(storageKey, '0');
        remaining = PERIOD;
        render();
        return;
      }
      setLastReloadTs(nowTs);
      writeValuesSnapshot(collectLiveValues().values);
      try { sessionStorage.setItem(refreshIntentKey, '1'); } catch (e) {}
      isReloading = true;
      render();
      if (timerId) {
        clearInterval(timerId);
      }
      setTimeout(function () { window.location.reload(); }, 120);
      return;
    }
    remaining--;
    render();
  }, 1000);

  window.addEventListener('beforeunload', function () {
    isReloading = true;
    if (timerId) {
      clearInterval(timerId);
    }
  });

  initValueUpdateEffects();
  render();
})();
</script>
<?php front_footer();
