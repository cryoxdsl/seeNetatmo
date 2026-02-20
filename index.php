<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/sea_temp.php';
require_once __DIR__ . '/inc/forecast.php';
require_once __DIR__ . '/inc/metar.php';
if (is_file(__DIR__ . '/inc/weather_condition.php')) {
    require_once __DIR__ . '/inc/weather_condition.php';
}

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$perfEnabled = ((string) ($_GET['perf'] ?? '')) === '1';
$perfStart = microtime(true);
$perfTimings = [];
$perfMeasure = static function (string $key, callable $fn) use (&$perfTimings) {
    $t0 = microtime(true);
    $result = $fn();
    $perfTimings[$key] = (microtime(true) - $t0) * 1000.0;
    return $result;
};

$state = ['last' => null, 'age' => null, 'disconnected' => true];
$row = null;
$prev = null;
try {
    if (function_exists('last_update_state')) {
        $state = $perfMeasure('last_update_state', static fn() => last_update_state());
    }
    if (function_exists('latest_rows')) {
        $rows = $perfMeasure('latest_rows', static fn() => latest_rows(2));
        $row = $rows[0] ?? null;
        $prev = $rows[1] ?? null;
    } elseif (function_exists('latest_row')) {
        $row = $perfMeasure('latest_row', static fn() => latest_row());
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
        $weather = $perfMeasure('weather_condition', static fn() => weather_condition_from_row($row, $state, $prev));
    } catch (Throwable $e) {
        $weather = [
          'type' => 'offline',
          'label' => t('weather.unavailable'),
          'detail' => t('weather.unavailable'),
          'trend' => 'unknown',
        ];
    }
}
$currentTrend = (string) ($weather['trend'] ?? 'unknown');
$currentTrendArrow = match ($currentTrend) {
    'up' => '↑',
    'down' => '↓',
    'stable' => '→',
    default => '•',
};
$currentTrendLabel = function_exists('weather_trend_label')
    ? weather_trend_label($currentTrend)
    : t('weather.trend.unavailable');

$rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0, 'rolling_year' => 0.0];
if (function_exists('rain_totals')) {
    try {
        $rain = $perfMeasure('rain_totals', static fn() => rain_totals());
    } catch (Throwable $e) {
        $rain = ['day' => 0.0, 'month' => 0.0, 'year' => 0.0, 'rolling_year' => 0.0];
    }
}
$rainRefs = ['day_avg' => null, 'month_avg' => null, 'year_to_date_avg' => null, 'rolling_365_avg' => null];
if (function_exists('rain_reference_averages')) {
    try {
        $rainRefs = $perfMeasure('rain_reference_averages', static fn() => rain_reference_averages());
    } catch (Throwable $e) {
        $rainRefs = ['day_avg' => null, 'month_avg' => null, 'year_to_date_avg' => null, 'rolling_365_avg' => null];
    }
}
$dayTemp = ['min' => null, 'max' => null];
if (function_exists('current_day_temp_range')) {
    try {
        $dayTemp = $perfMeasure('current_day_temp_range', static fn() => current_day_temp_range());
    } catch (Throwable $e) {
        $dayTemp = ['min' => null, 'max' => null];
    }
}
$dayTempTimes = ['min_time' => null, 'max_time' => null];
if (function_exists('current_day_temp_extreme_times')) {
    try {
        $dayTempTimes = $perfMeasure('current_day_temp_extreme_times', static fn() => current_day_temp_extreme_times());
    } catch (Throwable $e) {
        $dayTempTimes = ['min_time' => null, 'max_time' => null];
    }
}
$dayWind = ['min' => null, 'max' => null];
if (function_exists('current_day_wind_avg_range')) {
    try {
        $dayWind = $perfMeasure('current_day_wind_avg_range', static fn() => current_day_wind_avg_range());
    } catch (Throwable $e) {
        $dayWind = ['min' => null, 'max' => null];
    }
}
$windRosePeriod = (string) ($_GET['wrp'] ?? '1j');
if (!in_array($windRosePeriod, ['1j', '1s', '1m', '1a'], true)) {
    $windRosePeriod = '1j';
}
$windRose = ['counts' => array_fill(0, 16, 0), 'max' => 0, 'total' => 0, 'period' => $windRosePeriod, 'from' => null, 'to' => null];
if (function_exists('wind_rose_for_period')) {
    try {
        $windRose = $perfMeasure('wind_rose_for_period', static fn() => wind_rose_for_period($windRosePeriod));
    } catch (Throwable $e) {
        $windRose = ['counts' => array_fill(0, 16, 0), 'max' => 0, 'total' => 0, 'period' => $windRosePeriod, 'from' => null, 'to' => null];
    }
} elseif (function_exists('current_day_wind_rose')) {
    try {
        $windRose = $perfMeasure('current_day_wind_rose', static fn() => current_day_wind_rose());
    } catch (Throwable $e) {
        $windRose = ['counts' => array_fill(0, 16, 0), 'max' => 0, 'total' => 0, 'period' => '1j', 'from' => null, 'to' => null];
    }
}
$rainEpisode = ['start' => null, 'end' => null, 'ongoing' => false];
if (function_exists('current_day_rain_episode')) {
    try {
        $rainEpisode = $perfMeasure('current_day_rain_episode', static fn() => current_day_rain_episode());
    } catch (Throwable $e) {
        $rainEpisode = ['start' => null, 'end' => null, 'ongoing' => false];
    }
}
$pressureTrend = ['trend' => 'unknown', 'delta' => null];
if (function_exists('pressure_trend_snapshot')) {
    try {
        $pressureTrend = $perfMeasure('pressure_trend_snapshot', static fn() => pressure_trend_snapshot());
    } catch (Throwable $e) {
        $pressureTrend = ['trend' => 'unknown', 'delta' => null];
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
$sea = $perfMeasure('sea_temp_cached', static fn() => sea_temp_nearest(false));
if (empty($sea['available']) && (($sea['reason'] ?? '') === 'cache_only')) {
    $sea = $perfMeasure('sea_temp_remote', static fn() => sea_temp_nearest(true));
}
$seaValue = $sea['available'] ? units_format('T', $sea['value_c']) : t('common.na');
$metar = $perfMeasure('metar_cached', static fn() => metar_nearest(false));
if (empty($metar['available']) && (($metar['reason'] ?? '') === 'cache_only')) {
    $metar = $perfMeasure('metar_remote', static fn() => metar_nearest(true));
}
$metarHumanLines = metar_decode_human($metar);
$forecast = $perfMeasure('forecast_cached', static fn() => forecast_summary(false));
if (empty($forecast['available']) && (($forecast['reason'] ?? '') === 'cache_only')) {
    $forecast = $perfMeasure('forecast_remote', static fn() => forecast_summary(true));
}
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
$dayLengthDisplay = t('common.na');
$sunriseTs = null;
$sunsetTs = null;
$nowDayTs = now_paris()->getTimestamp();
$solarVisualAvailable = false;
$sunrisePct = 0.0;
$sunsetPct = 0.0;
$sunNowPct = 0.0;
$sunPhaseLabel = t('common.na');
if ($stationLat !== '' && $stationLon !== '' && is_numeric($stationLat) && is_numeric($stationLon)) {
    $sunInfo = date_sun_info(now_paris()->getTimestamp(), (float) $stationLat, (float) $stationLon);
    if (is_array($sunInfo)) {
        if (isset($sunInfo['sunrise']) && is_numeric($sunInfo['sunrise'])) {
            $sunriseTs = (int) $sunInfo['sunrise'];
            $sunriseDisplay = date('H:i', $sunriseTs);
        }
        if (isset($sunInfo['sunset']) && is_numeric($sunInfo['sunset'])) {
            $sunsetTs = (int) $sunInfo['sunset'];
            $sunsetDisplay = date('H:i', $sunsetTs);
        }
        if ($sunriseTs !== null && $sunsetTs !== null && $sunsetTs > $sunriseTs) {
            $solarVisualAvailable = true;
            $dayLengthSeconds = $sunsetTs - $sunriseTs;
            $dayHours = (int) floor($dayLengthSeconds / 3600);
            $dayMinutes = (int) floor(($dayLengthSeconds % 3600) / 60);
            $dayLengthDisplay = sprintf('%02d:%02d', $dayHours, $dayMinutes);
            $dayStartTs = now_paris()->setTime(0, 0, 0)->getTimestamp();
            $sunriseMinutes = (int) floor(($sunriseTs - $dayStartTs) / 60);
            $sunsetMinutes = (int) floor(($sunsetTs - $dayStartTs) / 60);
            $nowMinutes = (int) floor(($nowDayTs - $dayStartTs) / 60);
            $sunriseMinutes = max(0, min(1440, $sunriseMinutes));
            $sunsetMinutes = max(0, min(1440, $sunsetMinutes));
            $nowMinutes = max(0, min(1440, $nowMinutes));
            $sunrisePct = ($sunriseMinutes / 1440) * 100;
            $sunsetPct = ($sunsetMinutes / 1440) * 100;
            $sunNowPct = ($nowMinutes / 1440) * 100;
            if ($nowDayTs < $sunriseTs) {
                $sunPhaseLabel = t('extremes.phase.before_sunrise');
            } elseif ($nowDayTs > $sunsetTs) {
                $sunPhaseLabel = t('extremes.phase.after_sunset');
            } else {
                $sunPhaseLabel = t('extremes.phase.daylight');
            }
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
$rollingStart = $nowRain->modify('-364 days');
$rainRollingLabel = t('rain.rolling_year_base') . ' (' . $rollingStart->format('d/m/Y') . ' - ' . $nowRain->format('d/m/Y') . ')';
$windRoseDirs = locale_current() === 'en_EN'
    ? ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW']
    : ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO'];
$windRosePeriodLabels = [
    '1j' => t('windrose.period.1j'),
    '1s' => t('windrose.period.1s'),
    '1m' => t('windrose.period.1m'),
    '1a' => t('windrose.period.1a'),
];
$windRoseSamplesLabel = sprintf(t('windrose.samples_for'), $windRosePeriodLabels[$windRosePeriod] ?? $windRosePeriod);
$windRoseBasePath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH);
$windRoseQueryBase = $_GET;
unset($windRoseQueryBase['wrp']);
if (!function_exists('wind_rose_period_url')) {
    function wind_rose_period_url(string $path, array $queryBase, string $period): string
    {
        $query = $queryBase;
        $query['wrp'] = $period;
        $qs = http_build_query($query);
        return $path . ($qs !== '' ? ('?' . $qs) : '') . '#wind-rose-card';
    }
}
if (!function_exists('wind_cardinal_label')) {
    function wind_cardinal_label(?float $deg): string
    {
        if ($deg === null) {
            return t('common.na');
        }
        $normalized = fmod($deg, 360.0);
        if ($normalized < 0) {
            $normalized += 360.0;
        }
        $dirs = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        if (locale_current() === 'en_EN') {
            $dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        }
        $idx = (int) round($normalized / 45.0) % 8;
        return $dirs[$idx];
    }
}
if (!function_exists('to_hhmm_local')) {
    function to_hhmm_local(?string $dt): string
    {
        if ($dt === null || $dt === '') {
            return t('common.na');
        }
        try {
            return (new DateTimeImmutable($dt, new DateTimeZone(APP_TIMEZONE)))->format('H:i T');
        } catch (Throwable $e) {
            return t('common.na');
        }
    }
}
if (!function_exists('to_hhmm_from_db')) {
    function to_hhmm_from_db(?string $dt): string
    {
        if ($dt === null || $dt === '') {
            return t('common.na');
        }
        try {
            return (new DateTimeImmutable($dt, new DateTimeZone(APP_TIMEZONE)))->format('H:i');
        } catch (Throwable $e) {
            return t('common.na');
        }
    }
}
if (!function_exists('rain_delta_display')) {
    function rain_delta_display(?float $current, ?float $avg): string
    {
        if ($current === null || $avg === null) {
            return t('common.na');
        }
        $delta = $current - $avg;
        $formatted = units_format('R', $delta, false);
        if ($formatted === '') {
            return t('common.na');
        }
        $sign = $delta > 0 ? '+' : '';
        return $sign . $formatted . ' ' . units_symbol('R');
    }
}
if (!function_exists('label_with_small_paren')) {
    function label_with_small_paren(string $label): string
    {
        if (preg_match('/^(.*?)(\s*\(.*\))$/u', $label, $m) === 1) {
            return h(trim((string) $m[1])) . ' <small class="label-paren">' . h(trim((string) $m[2])) . '</small>';
        }
        return h($label);
    }
}
if (!function_exists('rain_episode_start_display')) {
    function rain_episode_start_display(array $episode): string
    {
        $start = isset($episode['start']) ? (string) $episode['start'] : '';
        if ($start === '') {
            return t('common.na');
        }
        if (!empty($episode['start_is_yesterday'])) {
            return t('common.yesterday') . ' ' . to_hhmm_local($start);
        }
        return to_hhmm_local($start);
    }
}

$perfTimings['total_prepare'] = (microtime(true) - $perfStart) * 1000.0;
if ($perfEnabled && !headers_sent()) {
    $serverTiming = [];
    foreach ($perfTimings as $name => $ms) {
        $token = preg_replace('/[^a-z0-9_]/i', '_', (string) $name);
        $serverTiming[] = $token . ';dur=' . number_format((float) $ms, 2, '.', '');
    }
    if ($serverTiming !== []) {
        header('Server-Timing: ' . implode(', ', $serverTiming));
    }
}

front_header(t('dashboard.title'));
?>
<section class="panel panel-dashboard season-<?= h($season) ?>" style="background-image:url('<?= h($seasonUrl) ?>')">
  <h2><?= h(t('dashboard.title')) ?></h2>
  <p><?= h(t('dashboard.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <div class="row status-row">
    <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
    <div class="status-controls">
      <button type="button" class="theme-toggle" id="themeToggle" aria-label="<?= h(t('dashboard.theme')) ?>">
        <span class="theme-dot" id="themeToggleDot">◐</span>
        <span class="theme-label"><?= h(t('dashboard.theme')) ?></span>
        <span class="theme-mode" id="themeToggleLabel"><?= h(t('dashboard.theme_auto')) ?></span>
      </button>
      <div class="auto-refresh-mini" id="autoRefreshBox">
        <button type="button" class="auto-chip" id="autoRefreshToggle" aria-label="<?= h(t('dashboard.auto_refresh')) ?>">
          <span class="dot" id="autoRefreshDot"></span>
          <span class="label"><?= h(t('dashboard.auto_refresh')) ?></span>
          <span class="count" id="autoRefreshCountdown">05:00</span>
        </button>
        <span class="auto-progress"><span id="autoRefreshProgress"></span></span>
      </div>
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
    <div class="current-value" data-live-key="current_temp" data-live-value="<?= h(isset($row['T']) && $row['T'] !== null ? (string) $row['T'] : '') ?>"><span class="temp-trend temp-trend-<?= h($currentTrend) ?>" aria-label="<?= h($currentTrendLabel) ?>" title="<?= h($currentTrendLabel) ?>"><?= h($currentTrendArrow) ?></span><?= h(units_format('T', $row['T'] ?? null)) ?><small><?= h(units_symbol('T')) ?></small></div>
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
  <article class="card station-card js-live-card" data-card-ok="<?= $state['disconnected'] ? '0' : '1' ?>">
    <h3><?= h(t('station.card_title')) ?></h3>
    <p class="small-muted"><?= h(t('station.location')) ?>: <span class="code"><?= h($stationPosition) ?></span>
      <?php if ($stationMapUrl !== null): ?>
        <a class="station-map-link" href="<?= h($stationMapUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="OpenStreetMap">
          <svg viewBox="0 0 64 64" aria-hidden="true">
            <path d="M32 6c-9.4 0-17 7.6-17 17 0 12.4 17 35 17 35s17-22.6 17-35c0-9.4-7.6-17-17-17zm0 24a7 7 0 1 1 0-14 7 7 0 0 1 0 14z" fill="currentColor"/>
          </svg>
        </a>
      <?php endif; ?>
    </p>
    <p class="small-muted"><?= h(t('station.altitude')) ?>: <strong><?= h($stationAltDisplay) ?></strong></p>
    <p class="small-muted"><?= h(t('station.status')) ?>:
      <span class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></span>
    </p>
    <p class="small-muted"><?= h(t('station.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  </article>
  <article class="card forecast-card sea-card js-live-card" data-card-ok="<?= !empty($sea['available']) ? '1' : '0' ?>">
    <h3><?= h(t('sea.title')) ?></h3>
    <div class="forecast-head">
      <span class="forecast-icon sea-illustration" aria-hidden="true">
        <svg viewBox="0 0 64 64">
          <path d="M6 42c4-3 8-3 12 0s8 3 12 0s8-3 12 0s8 3 12 0s8-3 12 0" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>
          <path d="M6 50c4-3 8-3 12 0s8 3 12 0s8-3 12 0s8 3 12 0s8-3 12 0" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>
          <path d="M36 12a10 10 0 0 1 10 10H26a10 10 0 0 1 10-10z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>
          <path d="M36 22v14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
        </svg>
      </span>
      <div class="forecast-current">
        <div class="forecast-value" data-live-key="sea_temp" data-live-value="<?= h(!empty($sea['available']) && isset($sea['value_c']) && $sea['value_c'] !== null ? (string) $sea['value_c'] : '') ?>"><?= h($seaValue . ($sea['available'] ? (' ' . units_symbol('T')) : '')) ?></div>
      </div>
    </div>
    <p class="small-muted"><?= h(t('sea.subtitle')) ?></p>
    <?php if (!empty($sea['distance_km'])): ?>
      <p class="small-muted"><?= h(t('sea.distance')) ?>: <?= h(number_format((float) $sea['distance_km'], 1, '.', '')) ?> km</p>
    <?php endif; ?>
    <?php if (!empty($sea['time'])): ?>
      <p class="small-muted"><?= h(t('sea.updated')) ?>: <?= h((string) $sea['time']) ?></p>
    <?php endif; ?>
  </article>
  <article class="card forecast-card js-live-card" data-card-ok="<?= !empty($forecast['available']) ? '1' : '0' ?>">
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
  <article class="card extremes-card js-live-card" data-card-ok="<?= ($dayTemp['min'] !== null || $dayTemp['max'] !== null) ? '1' : '0' ?>">
    <h3><?= h(t('extremes.card_title')) ?></h3>
    <div class="extremes-grid">
      <p class="extremes-line">
        <span class="extremes-label"><span class="extremes-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 4a2 2 0 1 1 4 0v8.6a4.5 4.5 0 1 1-4 0V4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 15.2v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><?= h(t('extremes.day_min')) ?></span>
        <strong data-live-key="extremes_day_min" data-live-value="<?= h(isset($dayTemp['min']) && $dayTemp['min'] !== null ? (string) $dayTemp['min'] : '') ?>"><?= h($dayMinDisplay) ?> <small class="extremes-time">(<?= h(to_hhmm_from_db(isset($dayTempTimes['min_time']) ? (string) $dayTempTimes['min_time'] : null)) ?>)</small></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><span class="extremes-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3c1.5 2.2 3.8 3.7 3.8 6.5 0 2.7-2 4.1-2 5.8 0 1.1.8 2.1 2 2.1 2.2 0 3.4-2 3.4-4.3 0-3.4-2.3-6.1-5.1-8.1M7.2 13.2c-1.3 1.2-2.2 2.8-2.2 4.6C5 20.8 7.4 23 10.7 23c3.5 0 5.8-2.5 5.8-5.7 0-2-1-3.7-2.6-4.8-.3 1.5-1.4 2.6-2.9 2.6-2.2 0-3.7-1.7-3.8-3.9z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span><?= h(t('extremes.day_max')) ?></span>
        <strong data-live-key="extremes_day_max" data-live-value="<?= h(isset($dayTemp['max']) && $dayTemp['max'] !== null ? (string) $dayTemp['max'] : '') ?>"><?= h($dayMaxDisplay) ?> <small class="extremes-time">(<?= h(to_hhmm_from_db(isset($dayTempTimes['max_time']) ? (string) $dayTempTimes['max_time'] : null)) ?>)</small></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><span class="extremes-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 18h18M6 18a6 6 0 0 1 12 0M12 3v3M5.6 6.6l2.1 2.1M18.4 6.6l-2.1 2.1M3 12h3M18 12h3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span><?= h(t('extremes.sunrise')) ?></span>
        <strong><?= h($sunriseDisplay) ?></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><span class="extremes-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 18h18M6 18a6 6 0 0 1 12 0M12 3v3M5.6 6.6l2.1 2.1M18.4 6.6l-2.1 2.1M3 12h3M18 12h3M9 21l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?= h(t('extremes.sunset')) ?></span>
        <strong><?= h($sunsetDisplay) ?></strong>
      </p>
      <p class="extremes-line">
        <span class="extremes-label"><span class="extremes-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="7" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 13V9M12 13l3 2M9 3h6M12 6V4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?= h(t('extremes.day_length')) ?></span>
        <strong><?= h($dayLengthDisplay) ?></strong>
      </p>
      <?php if ($solarVisualAvailable): ?>
        <div class="sun-visual">
          <div class="sun-scale" role="img" aria-label="<?= h(t('extremes.moment')) ?>">
            <span class="sun-daylight" style="left: <?= h(number_format($sunrisePct, 3, '.', '')) ?>%;width: <?= h(number_format(max(0, $sunsetPct - $sunrisePct), 3, '.', '')) ?>%;"></span>
            <span class="sun-tick" style="left: <?= h(number_format($sunrisePct, 3, '.', '')) ?>%;"></span>
            <span class="sun-tick" style="left: <?= h(number_format($sunsetPct, 3, '.', '')) ?>%;"></span>
            <span class="sun-now" style="left: <?= h(number_format($sunNowPct, 3, '.', '')) ?>%;"></span>
          </div>
          <p class="small-muted sun-caption"><span><?= h(t('extremes.moment')) ?>: <strong><?= h($sunPhaseLabel) ?></strong></span><span id="sunMomentClock"><?= h(date('H:i:s', $nowDayTs)) ?></span></p>
        </div>
      <?php endif; ?>
    </div>
  </article>
</section>
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
$metricGroups = [
  'metric.group.thermal' => ['T', 'A', 'D', 'H'],
  'metric.group.wind' => ['W', 'G', 'B'],
  'metric.group.rain' => ['RR', 'R'],
  'metric.group.pressure' => ['P'],
];
$metricGroupIcons = [
  'metric.group.thermal' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4a2 2 0 1 1 4 0v8.6a4.5 4.5 0 1 1-4 0V4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 15.2v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
  'metric.group.wind' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h12c2.7 0 3.8-3 1.4-4.5M3 13h16c3.2 0 4.7 3 1.8 4.8M3 17h10c2.2 0 3.2-1.8 1.9-3.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  'metric.group.rain' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 14h8.5a3 3 0 0 0 0-6 4 4 0 0 0-7.6-.9A3.2 3.2 0 0 0 7.5 14z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9 16.5l-1 3M12 16.5l-1 3M15 16.5l-1 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
  'metric.group.pressure' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 12l4-2.5M12 7.2v1.2M7.8 12H6.6M17.4 12h-1.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
];
?>
<section class="panel metric-groups-panel">
  <h3 class="panel-title-with-icon"><span class="panel-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 18h4v-6H4zM10 18h4V9h-4zM16 18h4V5h-4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span><?= h(t('metrics.by_type')) ?></h3>
  <div class="cards metrics-cards">
    <?php foreach ($metricGroups as $groupLabel => $groupMetrics): ?>
      <article class="card forecast-card metric-group-card js-live-card" data-card-ok="<?= $state['disconnected'] ? '0' : '1' ?>">
        <h3><span class="metric-group-icon" aria-hidden="true"><?= $metricGroupIcons[$groupLabel] ?? '' ?></span><?= h(t($groupLabel)) ?></h3>
        <div class="metric-group-lines">
          <?php foreach ($groupMetrics as $metric):
              $value = $metrics[$metric] ?? null;
              $display = units_format($metric, $value);
              $displayWithUnit = $display;
              if ($display !== t('common.na')) {
                  $symbol = units_symbol($metric);
                  if ($symbol !== '') {
                      $displayWithUnit .= ' ' . $symbol;
                  }
              }
          ?>
            <div class="forecast-day metric-line">
              <strong><?= h(units_metric_name($metric)) ?></strong>
              <p class="forecast-line metric-line-value" data-live-key="metric_<?= h(strtolower((string) $metric)) ?>" data-live-value="<?= h($value === null ? '' : (string) $value) ?>">
                <?php if ($metric === 'P'): ?>
                  <?php
                    $pTrend = (string) ($pressureTrend['trend'] ?? 'unknown');
                    $pArrow = match ($pTrend) {
                        'up' => '↑',
                        'down' => '↓',
                        'stable' => '→',
                        default => '•',
                    };
                  ?>
                  <span class="metric-trend metric-trend-<?= h($pTrend) ?>" title="<?= h(t('metric.pressure_trend')) ?>"><?= h($pArrow) ?></span>
                <?php endif; ?>
                <?php if ($metric === 'B'): ?>
                  <?php
                    $windDeg = $value !== null ? (float) $value : null;
                    // Meteo wind direction is where it comes from; UI arrow shows where it goes.
                    $windToDeg = $windDeg !== null ? fmod(($windDeg + 180.0 + 360.0), 360.0) : 0.0;
                  ?>
                  <span class="wind-compass" aria-label="<?= h(t('metric.wind_dir_compass')) ?>" title="<?= h(t('metric.wind_dir_compass')) ?>">
                    <span class="needle" style="transform:rotate(<?= h(number_format($windToDeg, 1, '.', '')) ?>deg)">↑</span>
                  </span>
                <?php endif; ?>
                <?= h($displayWithUnit) ?>
              </p>
              <?php if ($metric === 'R'): ?>
                <p class="forecast-line"><strong><?= h(t('metric.rain_episode_start')) ?>:</strong> <?= h(rain_episode_start_display($rainEpisode)) ?></p>
                <p class="forecast-line"><strong><?= h(t('metric.rain_episode_end')) ?>:</strong> <?= !empty($rainEpisode['ongoing']) ? h(t('metric.rain_episode_ongoing')) : h(to_hhmm_local(isset($rainEpisode['end']) ? (string) $rainEpisode['end'] : null)) ?></p>
              <?php endif; ?>
              <?php if ($metric === 'W'): ?>
                <?php
                  $wMin = units_format('W', $dayWind['min'] ?? null);
                  $wMax = units_format('W', $dayWind['max'] ?? null);
                  $wMinTxt = $wMin === t('common.na') ? $wMin : ($wMin . ' ' . units_symbol('W'));
                  $wMaxTxt = $wMax === t('common.na') ? $wMax : ($wMax . ' ' . units_symbol('W'));
                ?>
                <p class="forecast-line"><strong><?= h(t('metric.day_min_max')) ?>:</strong> <?= h($wMinTxt) ?> / <?= h($wMaxTxt) ?></p>
              <?php endif; ?>
              <?php if ($metric === 'B'): ?>
                <?php
                  $windDeg = $value !== null ? (float) $value : null;
                  $windFromCardinal = wind_cardinal_label($windDeg);
                ?>
                <p class="forecast-line"><strong><?= h(t('metric.wind_dir_label')) ?>:</strong> <?= h(t('metric.wind_from_sector') . ' ' . $windFromCardinal) ?></p>
              <?php endif; ?>
              <?php if ($metric === 'P'): ?>
                <?php
                  $delta = $pressureTrend['delta'] ?? null;
                  $deltaTxt = $delta === null ? t('common.na') : (($delta > 0 ? '+' : '') . number_format((float) $delta, 2, '.', '') . ' hPa');
                ?>
                <p class="forecast-line"><strong><?= h(t('metric.pressure_trend')) ?>:</strong> <?= h($deltaTxt) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<section class="panel rain-totals-panel">
  <h3 class="panel-title-with-icon"><span class="panel-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7.5 14h8.5a3 3 0 0 0 0-6 4 4 0 0 0-7.6-.9A3.2 3.2 0 0 0 7.5 14z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9 16.5l-1 3M12 16.5l-1 3M15 16.5l-1 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><?= h(t('rain.total')) ?></h3>
  <div class="cards">
    <?php $rainDay = units_format('R', $rain['day']); ?>
    <?php $rainMonth = units_format('R', $rain['month']); ?>
    <?php $rainYear = units_format('R', $rain['year']); ?>
    <?php $rainRolling = units_format('R', $rain['rolling_year'] ?? 0.0); ?>
    <article class="card js-live-card" data-card-ok="<?= isset($rain['day']) ? '1' : '0' ?>">
      <h3><?= label_with_small_paren($rainDayLabel) ?></h3>
      <div data-live-key="rain_day" data-live-value="<?= h(isset($rain['day']) ? (string) $rain['day'] : '') ?>"><?= h($rainDay . ($rainDay !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div>
      <p class="small-muted"><?= h(t('rain.delta_day_vs_avg')) ?>: <strong><?= h(rain_delta_display(isset($rain['day']) ? (float) $rain['day'] : null, isset($rainRefs['day_avg']) ? ($rainRefs['day_avg'] !== null ? (float) $rainRefs['day_avg'] : null) : null)) ?></strong></p>
    </article>
    <article class="card js-live-card" data-card-ok="<?= isset($rain['month']) ? '1' : '0' ?>">
      <h3><?= label_with_small_paren($rainMonthLabel) ?></h3>
      <div data-live-key="rain_month" data-live-value="<?= h(isset($rain['month']) ? (string) $rain['month'] : '') ?>"><?= h($rainMonth . ($rainMonth !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div>
      <p class="small-muted"><?= h(t('rain.delta_month_vs_avg')) ?>: <strong><?= h(rain_delta_display(isset($rain['month']) ? (float) $rain['month'] : null, isset($rainRefs['month_avg']) ? ($rainRefs['month_avg'] !== null ? (float) $rainRefs['month_avg'] : null) : null)) ?></strong></p>
    </article>
    <article class="card js-live-card" data-card-ok="<?= isset($rain['year']) ? '1' : '0' ?>">
      <h3><?= label_with_small_paren($rainYearLabel) ?></h3>
      <div data-live-key="rain_year" data-live-value="<?= h(isset($rain['year']) ? (string) $rain['year'] : '') ?>"><?= h($rainYear . ($rainYear !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div>
      <p class="small-muted"><?= h(t('rain.delta_year_vs_same_date_avg')) ?>: <strong><?= h(rain_delta_display(isset($rain['year']) ? (float) $rain['year'] : null, isset($rainRefs['year_to_date_avg']) ? ($rainRefs['year_to_date_avg'] !== null ? (float) $rainRefs['year_to_date_avg'] : null) : null)) ?></strong></p>
    </article>
    <article class="card js-live-card" data-card-ok="<?= isset($rain['rolling_year']) ? '1' : '0' ?>">
      <h3><?= label_with_small_paren($rainRollingLabel) ?></h3>
      <div data-live-key="rain_rolling_year" data-live-value="<?= h(isset($rain['rolling_year']) ? (string) $rain['rolling_year'] : '') ?>"><?= h($rainRolling . ($rainRolling !== t('common.na') ? (' ' . units_symbol('R')) : '')) ?></div>
      <p class="small-muted"><?= h(t('rain.delta_rolling_vs_avg')) ?>: <strong><?= h(rain_delta_display(isset($rain['rolling_year']) ? (float) $rain['rolling_year'] : null, isset($rainRefs['rolling_365_avg']) ? ($rainRefs['rolling_365_avg'] !== null ? (float) $rainRefs['rolling_365_avg'] : null) : null)) ?></strong></p>
    </article>
  </div>
</section>
<section class="cards">
  <article class="card forecast-card metar-card js-live-card" data-card-ok="<?= !empty($metar['available']) ? '1' : '0' ?>">
    <h3><?= h(t('metar.title')) ?></h3>
    <?php if (!empty($metar['available'])): ?>
      <div class="forecast-head">
        <span class="forecast-icon metar-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64">
            <path d="M8 37h15l9 10h4l-6-10h8l5 7h3l-3-7h10v-4H43l3-7h-3l-5 7h-8l6-10h-4l-9 10H8z" fill="currentColor"/>
          </svg>
        </span>
        <div class="forecast-current">
          <div class="forecast-value"><?= h((string) ($metar['airport_icao'] ?? t('common.na'))) ?></div>
          <?php if (!empty($metar['distance_km'])): ?>
            <p class="small-muted"><?= h(t('metar.nearest')) ?>: <?= h(number_format((float) $metar['distance_km'], 1, '.', '')) ?> km</p>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($metar['weather'])): ?>
        <p class="forecast-line"><strong><?= h(t('metar.weather')) ?>:</strong> <?= h((string) $metar['weather']) ?></p>
      <?php endif; ?>
      <?php if (!empty($metar['sky'])): ?>
        <p class="forecast-line"><strong><?= h(t('metar.clouds')) ?>:</strong> <?= h((string) $metar['sky']) ?></p>
      <?php endif; ?>
      <?php if (!empty($metarHumanLines)): ?>
        <p class="small-muted"><?= h(t('metar.decoded')) ?>:</p>
        <?php foreach ($metarHumanLines as $line): ?>
          <p class="forecast-line"><?= h((string) $line) ?></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($metar['raw_text'])): ?>
        <p class="small-muted"><?= h(t('metar.raw')) ?>:</p>
        <p class="code metar-raw"><?= h((string) $metar['raw_text']) ?></p>
      <?php endif; ?>
      <?php if (!empty($metar['observed_at'])): ?>
        <p class="small-muted"><?= h(t('metar.observed')) ?>: <?= h((string) $metar['observed_at']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="small-muted"><?= h(t('metar.unavailable')) ?></p>
      <?php if (($metar['reason'] ?? '') === 'no_station_coords'): ?>
        <p class="small-muted"><?= h(t('forecast.coords_required')) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </article>
  <article class="card wind-rose-card js-live-card" id="wind-rose-card" data-card-ok="<?= !empty($windRose['total']) ? '1' : '0' ?>">
    <div class="wind-rose-head">
      <h3><?= h(t('windrose.title')) ?></h3>
      <div class="wind-rose-periods" role="tablist" aria-label="<?= h(t('windrose.title')) ?>">
        <?php foreach ($windRosePeriodLabels as $pKey => $pLabel): ?>
          <a href="<?= h(wind_rose_period_url($windRoseBasePath, $windRoseQueryBase, $pKey)) ?>" class="wind-rose-period<?= $windRosePeriod === $pKey ? ' is-active' : '' ?>"><?= h($pLabel) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (!empty($windRose['total']) && !empty($windRose['max']) && is_array($windRose['counts'])): ?>
      <?php
        $roseCounts = array_values(array_map(static fn($v) => (int) $v, $windRose['counts']));
        $roseMax = (int) $windRose['max'];
        $roseTotal = max(1, (int) $windRose['total']);
        $cx = 90.0;
        $cy = 90.0;
        $inner = 14.0;
        $span = 58.0;
        $roseRanked = [];
        foreach ($roseCounts as $i => $count) {
            if ($count <= 0) {
                continue;
            }
            $roseRanked[] = ['idx' => $i, 'count' => $count];
        }
        usort($roseRanked, static fn($a, $b) => $b['count'] <=> $a['count']);
        $roseTop = array_slice($roseRanked, 0, 3);
      ?>
      <svg class="wind-rose-svg" viewBox="0 0 180 180" role="img" aria-label="<?= h(t('windrose.title')) ?>">
        <circle cx="90" cy="90" r="72" class="wind-rose-grid"></circle>
        <circle cx="90" cy="90" r="54" class="wind-rose-grid"></circle>
        <circle cx="90" cy="90" r="36" class="wind-rose-grid"></circle>
        <circle cx="90" cy="90" r="18" class="wind-rose-grid"></circle>
        <line x1="90" y1="10" x2="90" y2="170" class="wind-rose-axis"></line>
        <line x1="10" y1="90" x2="170" y2="90" class="wind-rose-axis"></line>
        <text x="95" y="20" class="wind-rose-scale">100%</text>
        <text x="95" y="38" class="wind-rose-scale">75%</text>
        <text x="95" y="56" class="wind-rose-scale">50%</text>
        <text x="95" y="74" class="wind-rose-scale">25%</text>
        <?php for ($i = 0; $i < 16; $i++): ?>
          <?php
            $a = deg2rad(($i * 22.5) - 90.0);
            $sx = $cx + cos($a) * ($inner - 2.0);
            $sy = $cy + sin($a) * ($inner - 2.0);
            $ex = $cx + cos($a) * 72.0;
            $ey = $cy + sin($a) * 72.0;
          ?>
          <line x1="<?= h(number_format($sx, 2, '.', '')) ?>" y1="<?= h(number_format($sy, 2, '.', '')) ?>" x2="<?= h(number_format($ex, 2, '.', '')) ?>" y2="<?= h(number_format($ey, 2, '.', '')) ?>" class="wind-rose-spoke"></line>
        <?php endfor; ?>
        <?php foreach ($roseCounts as $i => $count): ?>
          <?php
            $a = deg2rad(($i * 22.5) - 90.0);
            $len = $roseMax > 0 ? (($count / $roseMax) * $span) : 0.0;
            $a0 = deg2rad(($i * 22.5) - 101.25);
            $a1 = deg2rad(($i * 22.5) - 78.75);
            $x0i = $cx + cos($a0) * $inner;
            $y0i = $cy + sin($a0) * $inner;
            $x1i = $cx + cos($a1) * $inner;
            $y1i = $cy + sin($a1) * $inner;
            $x0o = $cx + cos($a0) * ($inner + $len);
            $y0o = $cy + sin($a0) * ($inner + $len);
            $x1o = $cx + cos($a1) * ($inner + $len);
            $y1o = $cy + sin($a1) * ($inner + $len);
            $labelX = $cx + cos($a) * 82.0;
            $labelY = $cy + sin($a) * 82.0;
            $tip = ($windRoseDirs[$i] ?? (string) $i) . ': ' . $count;
            $alpha = 0.35 + ($roseMax > 0 ? (($count / $roseMax) * 0.55) : 0.0);
          ?>
          <path d="M <?= h(number_format($x0i, 2, '.', '')) ?> <?= h(number_format($y0i, 2, '.', '')) ?> L <?= h(number_format($x0o, 2, '.', '')) ?> <?= h(number_format($y0o, 2, '.', '')) ?> A <?= h(number_format($inner + $len, 2, '.', '')) ?> <?= h(number_format($inner + $len, 2, '.', '')) ?> 0 0 1 <?= h(number_format($x1o, 2, '.', '')) ?> <?= h(number_format($y1o, 2, '.', '')) ?> L <?= h(number_format($x1i, 2, '.', '')) ?> <?= h(number_format($y1i, 2, '.', '')) ?> A <?= h(number_format($inner, 2, '.', '')) ?> <?= h(number_format($inner, 2, '.', '')) ?> 0 0 0 <?= h(number_format($x0i, 2, '.', '')) ?> <?= h(number_format($y0i, 2, '.', '')) ?> Z" class="wind-rose-sector" style="opacity:<?= h(number_format($alpha, 3, '.', '')) ?>">
            <title><?= h($tip) ?></title>
          </path>
          <?php if (($i % 2) === 0): ?>
            <text x="<?= h(number_format($labelX, 2, '.', '')) ?>" y="<?= h(number_format($labelY, 2, '.', '')) ?>" class="wind-rose-label"><?= h($windRoseDirs[$i] ?? '') ?></text>
          <?php endif; ?>
        <?php endforeach; ?>
        <circle cx="90" cy="90" r="3" class="wind-rose-center"></circle>
      </svg>
      <p class="small-muted"><?= h($windRoseSamplesLabel) ?>: <strong><?= h((string) ((int) $windRose['total'])) ?></strong></p>
      <?php if (!empty($roseTop)): ?>
        <?php
          $main = $roseTop[0];
          $mainDir = $windRoseDirs[$main['idx']] ?? (string) $main['idx'];
          $mainPct = number_format(($main['count'] / $roseTotal) * 100.0, 1, '.', '');
          $topParts = [];
          foreach ($roseTop as $r) {
              $d = $windRoseDirs[$r['idx']] ?? (string) $r['idx'];
              $pct = number_format(($r['count'] / $roseTotal) * 100.0, 1, '.', '');
              $topParts[] = $d . ' ' . $pct . '%';
          }
        ?>
        <p class="small-muted"><strong><?= h(t('windrose.dominant')) ?>:</strong> <?= h($mainDir) ?> (<?= h($mainPct) ?>%)</p>
        <p class="small-muted"><strong><?= h(t('windrose.top')) ?>:</strong> <?= h(implode(' | ', $topParts)) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="small-muted"><?= h(t('windrose.no_data')) ?></p>
    <?php endif; ?>
  </article>
</section>
<script>
(function () {
  var PERIOD = 300;
  var asyncRefreshToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
  var asyncRefreshLocalKey = 'meteo13_front_async_refresh_last_ping';
  var asyncRefreshThrottleMs = 180000;
  var themeModeKey = 'meteo13_theme_mode';
  var storageKey = 'meteo13_auto_refresh_enabled';
  var lastReloadKey = 'meteo13_auto_refresh_last_reload_ts';
  var refreshIntentKey = 'meteo13_auto_refresh_intent';
  var valuesSnapshotKey = 'meteo13_live_values_snapshot_v1';
  var reloadCooldownMs = 15000;
  var sunriseTs = <?= json_encode($sunriseTs !== null ? (int) $sunriseTs : null) ?>;
  var sunsetTs = <?= json_encode($sunsetTs !== null ? (int) $sunsetTs : null) ?>;
  var themeText = {
    auto: <?= json_encode(t('dashboard.theme_auto'), JSON_UNESCAPED_UNICODE) ?>,
    light: <?= json_encode(t('dashboard.theme_light'), JSON_UNESCAPED_UNICODE) ?>,
    dark: <?= json_encode(t('dashboard.theme_dark'), JSON_UNESCAPED_UNICODE) ?>
  };
  var reloadingText = <?= json_encode(t('dashboard.auto_refresh_reloading'), JSON_UNESCAPED_UNICODE) ?>;
  var stationDisconnected = <?= json_encode((bool) ($state['disconnected'] ?? true)) ?>;
  var enabled = localStorage.getItem(storageKey);
  enabled = enabled === null ? true : enabled === '1';
  var themeMode = localStorage.getItem(themeModeKey) || 'auto';
  if (themeMode !== 'auto' && themeMode !== 'light' && themeMode !== 'dark') {
    themeMode = 'auto';
  }
  var remaining = PERIOD;
  var isReloading = false;
  var timerId = null;

  var themeToggle = document.getElementById('themeToggle');
  var themeToggleLabel = document.getElementById('themeToggleLabel');
  var dot = document.getElementById('autoRefreshDot');
  var progress = document.getElementById('autoRefreshProgress');
  var countdown = document.getElementById('autoRefreshCountdown');
  var toggle = document.getElementById('autoRefreshToggle');
  var refreshBox = document.getElementById('autoRefreshBox');
  var sunMomentClock = document.getElementById('sunMomentClock');
  var sunMomentTimezone = <?= json_encode(APP_TIMEZONE, JSON_UNESCAPED_UNICODE) ?>;
  if (!dot || !progress || !countdown || !toggle) return;

  function triggerAsyncCacheRefresh() {
    if (!asyncRefreshToken) return;
    if (typeof navigator !== 'undefined' && 'onLine' in navigator && !navigator.onLine) return;
    try {
      var nowTs = Date.now();
      var last = parseInt(localStorage.getItem(asyncRefreshLocalKey) || '0', 10);
      if (Number.isFinite(last) && last > 0 && (nowTs - last) < asyncRefreshThrottleMs) {
        return;
      }
      localStorage.setItem(asyncRefreshLocalKey, String(nowTs));
    } catch (e) {}

    var url = '/front_cache_refresh.php?t=' + encodeURIComponent(asyncRefreshToken);
    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: true,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(function () {});
  }

  function computeAutoTheme() {
    var nowSec = Math.floor(Date.now() / 1000);
    if (typeof sunriseTs === 'number' && typeof sunsetTs === 'number' && sunsetTs > sunriseTs) {
      return (nowSec >= sunriseTs && nowSec < sunsetTs) ? 'light' : 'dark';
    }
    var h = new Date().getHours();
    return (h >= 7 && h < 20) ? 'light' : 'dark';
  }

  function applyTheme() {
    var active = themeMode === 'auto' ? computeAutoTheme() : themeMode;
    document.body.classList.toggle('theme-dark', active === 'dark');
    document.body.classList.toggle('theme-light', active === 'light');
    if (themeToggle) {
      themeToggle.setAttribute('data-mode', themeMode);
    }
    if (themeToggleLabel) {
      themeToggleLabel.textContent = themeText[themeMode] || themeText.auto;
    }
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      if (themeMode === 'auto') themeMode = 'dark';
      else if (themeMode === 'dark') themeMode = 'light';
      else themeMode = 'auto';
      localStorage.setItem(themeModeKey, themeMode);
      applyTheme();
    });
  }

  function refreshSunMomentClock() {
    if (!sunMomentClock) return;
    try {
      var now = new Date();
      var value = new Intl.DateTimeFormat('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: sunMomentTimezone
      }).format(now);
      sunMomentClock.textContent = value.replace(/\u202f/g, ' ');
    } catch (e) {
      var d = new Date();
      var hh = String(d.getHours()).padStart(2, '0');
      var mm = String(d.getMinutes()).padStart(2, '0');
      var ss = String(d.getSeconds()).padStart(2, '0');
      sunMomentClock.textContent = hh + ':' + mm + ':' + ss;
    }
  }

  function syncProgressWidth() {
    if (!refreshBox) return;
    var w = toggle.getBoundingClientRect().width;
    if (w > 0) {
      refreshBox.style.setProperty('--auto-progress-width', Math.ceil(w) + 'px');
    }
  }

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

  function refreshCardHealth() {
    var cards = document.querySelectorAll('.card');
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var stale = false;

      if (stationDisconnected && card.classList.contains('js-live-card')) {
        stale = true;
      }

      var cardOk = card.getAttribute('data-card-ok');
      if (cardOk === '0') {
        stale = true;
      }

      var liveNodes = card.querySelectorAll('[data-live-key][data-live-value]');
      if (liveNodes.length > 0) {
        var hasValue = false;
        for (var j = 0; j < liveNodes.length; j++) {
          var val = (liveNodes[j].getAttribute('data-live-value') || '').trim();
          if (val !== '') {
            hasValue = true;
            break;
          }
        }
        if (!hasValue) {
          stale = true;
        }
      }

      card.classList.toggle('is-stale-card', stale);
    }
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
      syncProgressWidth();
      progress.style.width = '0%';
      return;
    }
    toggle.classList.remove('is-loading');
    countdown.textContent = fmt(remaining);
    toggle.classList.toggle('is-off', !enabled);
    dot.textContent = enabled ? '' : '||';
    syncProgressWidth();
    var ratio = Math.max(0, Math.min(1, remaining / PERIOD));
    progress.style.width = (ratio * 100) + '%';
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
    refreshSunMomentClock();
    if (themeMode === 'auto') {
      applyTheme();
    }
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
  window.addEventListener('resize', syncProgressWidth);

  initValueUpdateEffects();
  refreshCardHealth();
  applyTheme();
  refreshSunMomentClock();
  setTimeout(triggerAsyncCacheRefresh, 1200);
  render();
})();
</script>
<?php front_footer();
