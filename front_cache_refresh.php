<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/lock.php';
require_once __DIR__ . '/inc/logger.php';
require_once __DIR__ . '/inc/forecast.php';
require_once __DIR__ . '/inc/sea_temp.php';
require_once __DIR__ . '/inc/metar.php';
require_once __DIR__ . '/inc/weather_alerts.php';
require_once __DIR__ . '/inc/data.php';

http_response_code(204);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!app_is_installed()) {
    exit;
}

$token = trim((string) ($_GET['t'] ?? ''));
$expected = (string) ($_SESSION['csrf_token'] ?? '');
if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
    exit;
}
$force = ((string) ($_GET['force'] ?? '')) === '1';
$scope = (string) ($_GET['scope'] ?? 'partial');

$lock = lock_acquire('front_cache_refresh');
if ($lock === null) {
    exit;
}

try {
    $now = time();
    $minInterval = 300; // 5 minutes
    $lastTry = (int) (setting_get('front_cache_refresh_last_try', '0') ?? 0);
    if (!$force && $lastTry > ($now - $minInterval)) {
        exit;
    }

    setting_set('front_cache_refresh_last_try', (string) $now);

    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(8);
    }

    forecast_summary(true);
    sea_temp_nearest(true);
    metar_nearest(true);
    weather_alerts_summary(true);
    if ($scope === 'full') {
        rain_totals();
        rain_reference_averages();
        current_day_temp_range();
        current_day_temp_extreme_times();
        current_day_wind_avg_range();
        current_day_rain_episode();
        pressure_trend_snapshot();
        if (function_exists('wind_rose_for_period')) {
            wind_rose_for_period('1j');
            wind_rose_for_period('1s');
            wind_rose_for_period('1m');
            wind_rose_for_period('1a');
        }
    }

    setting_set('front_cache_refresh_last_done', (string) time());
} catch (Throwable $e) {
    log_event('warning', 'front.cache_refresh', 'Async refresh failed', ['err' => $e->getMessage()]);
} finally {
    lock_release($lock);
}
