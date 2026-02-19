<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const FORECAST_API_URL = 'https://api.open-meteo.com/v1/forecast';
const FORECAST_CACHE_TTL_SECONDS = 1800;

function forecast_station_coordinates(): ?array
{
    $lat = setting_get('station_lat', '');
    $lon = setting_get('station_lon', '');
    if ($lat === null || $lon === null || $lat === '' || $lon === '') {
        return null;
    }
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }
    return ['lat' => (float) $lat, 'lon' => (float) $lon];
}

function forecast_http_get_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_USERAGENT => 'meteo13-netatmo/1.0',
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Forecast fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('Forecast HTTP ' . $http);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Forecast invalid JSON');
    }
    return $json;
}

function forecast_type_from_wmo(int $code): string
{
    if ($code === 0) {
        return 'sunny';
    }
    if ($code === 1 || $code === 2) {
        return 'voile';
    }
    if ($code === 3) {
        return 'cloudy';
    }
    if ($code === 45 || $code === 48) {
        return 'very_cloudy';
    }
    if (
        in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true)
    ) {
        return 'rain';
    }
    if (in_array($code, [71, 73, 75, 77, 85, 86], true)) {
        return 'snow';
    }
    return 'cloudy';
}

function forecast_label_key_from_wmo(int $code): string
{
    if ($code === 0) {
        return 'forecast.condition.clear';
    }
    if ($code === 1 || $code === 2) {
        return 'forecast.condition.partly_cloudy';
    }
    if ($code === 3) {
        return 'forecast.condition.overcast';
    }
    if ($code === 45 || $code === 48) {
        return 'forecast.condition.fog';
    }
    if (in_array($code, [51, 53, 55, 56, 57], true)) {
        return 'forecast.condition.drizzle';
    }
    if (in_array($code, [61, 63, 65, 66, 67], true)) {
        return 'forecast.condition.rain';
    }
    if (in_array($code, [71, 73, 75, 77], true)) {
        return 'forecast.condition.snow';
    }
    if (in_array($code, [80, 81, 82], true)) {
        return 'forecast.condition.showers';
    }
    if (in_array($code, [85, 86], true)) {
        return 'forecast.condition.snow_showers';
    }
    if (in_array($code, [95, 96, 99], true)) {
        return 'forecast.condition.thunderstorm';
    }
    return 'forecast.condition.unknown';
}

function forecast_summary(bool $allowRemote = false): array
{
    $coords = forecast_station_coordinates();
    if ($coords === null) {
        return ['available' => false, 'reason' => 'no_station_coords'];
    }

    $cacheRaw = setting_get('forecast_cache_json', '');
    $lastTry = (int) (setting_get('forecast_last_try', '0') ?? 0);
    $retryAfter = 300;
    if ($cacheRaw !== '') {
        $cache = json_decode($cacheRaw, true);
        if (is_array($cache)) {
            $fresh = ((int) ($cache['fetched_at'] ?? 0)) > (time() - FORECAST_CACHE_TTL_SECONDS);
            $same = abs(((float) ($cache['station_lat'] ?? 0)) - $coords['lat']) < 0.0001
                && abs(((float) ($cache['station_lon'] ?? 0)) - $coords['lon']) < 0.0001;
            if ($fresh && $same) {
                return $cache;
            }
            if ($same && $lastTry > (time() - $retryAfter)) {
                return $cache;
            }
            if (!$allowRemote && $same) {
                return $cache;
            }
        }
    } elseif ($lastTry > (time() - $retryAfter)) {
        return ['available' => false, 'reason' => 'retry_later'];
    }
    if (!$allowRemote) {
        return ['available' => false, 'reason' => 'cache_only'];
    }

    $tz = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC';
    $query = http_build_query([
        'latitude' => $coords['lat'],
        'longitude' => $coords['lon'],
        'current' => 'temperature_2m,weather_code',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
        'forecast_days' => 2,
        'timezone' => $tz,
    ]);
    $url = FORECAST_API_URL . '?' . $query;

    $out = [
        'available' => false,
        'reason' => 'fetch_failed',
        'fetched_at' => time(),
        'station_lat' => $coords['lat'],
        'station_lon' => $coords['lon'],
        'updated_at' => '',
        'current_temp_c' => null,
        'current_code' => null,
        'current_type' => 'cloudy',
        'current_label_key' => 'forecast.condition.unknown',
        'today_min_c' => null,
        'today_max_c' => null,
        'today_pop' => null,
        'today_code' => null,
        'today_type' => 'cloudy',
        'today_label_key' => 'forecast.condition.unknown',
        'tomorrow_min_c' => null,
        'tomorrow_max_c' => null,
        'tomorrow_pop' => null,
        'tomorrow_code' => null,
        'tomorrow_type' => 'cloudy',
        'tomorrow_label_key' => 'forecast.condition.unknown',
        'source_url' => $url,
    ];

    try {
        setting_set('forecast_last_try', (string) time());
        $json = forecast_http_get_json($url);

        $currentCode = isset($json['current']['weather_code']) && is_numeric($json['current']['weather_code'])
            ? (int) $json['current']['weather_code']
            : null;
        if ($currentCode !== null) {
            $out['current_code'] = $currentCode;
            $out['current_type'] = forecast_type_from_wmo($currentCode);
            $out['current_label_key'] = forecast_label_key_from_wmo($currentCode);
        }
        if (isset($json['current']['temperature_2m']) && is_numeric($json['current']['temperature_2m'])) {
            $out['current_temp_c'] = round((float) $json['current']['temperature_2m'], 1);
        }
        $out['updated_at'] = (string) ($json['current']['time'] ?? '');

        $daily = $json['daily'] ?? null;
        if (is_array($daily)) {
            $codes = is_array($daily['weather_code'] ?? null) ? $daily['weather_code'] : [];
            $mins = is_array($daily['temperature_2m_min'] ?? null) ? $daily['temperature_2m_min'] : [];
            $maxs = is_array($daily['temperature_2m_max'] ?? null) ? $daily['temperature_2m_max'] : [];
            $pops = is_array($daily['precipitation_probability_max'] ?? null) ? $daily['precipitation_probability_max'] : [];

            if (isset($mins[0]) && is_numeric($mins[0])) {
                $out['today_min_c'] = round((float) $mins[0], 1);
            }
            if (isset($maxs[0]) && is_numeric($maxs[0])) {
                $out['today_max_c'] = round((float) $maxs[0], 1);
            }
            if (isset($pops[0]) && is_numeric($pops[0])) {
                $out['today_pop'] = (int) round((float) $pops[0]);
            }
            if (isset($codes[0]) && is_numeric($codes[0])) {
                $todayCode = (int) $codes[0];
                $out['today_code'] = $todayCode;
                $out['today_type'] = forecast_type_from_wmo($todayCode);
                $out['today_label_key'] = forecast_label_key_from_wmo($todayCode);
            }

            if (isset($mins[1]) && is_numeric($mins[1])) {
                $out['tomorrow_min_c'] = round((float) $mins[1], 1);
            }
            if (isset($maxs[1]) && is_numeric($maxs[1])) {
                $out['tomorrow_max_c'] = round((float) $maxs[1], 1);
            }
            if (isset($pops[1]) && is_numeric($pops[1])) {
                $out['tomorrow_pop'] = (int) round((float) $pops[1]);
            }
            if (isset($codes[1]) && is_numeric($codes[1])) {
                $tomorrowCode = (int) $codes[1];
                $out['tomorrow_code'] = $tomorrowCode;
                $out['tomorrow_type'] = forecast_type_from_wmo($tomorrowCode);
                $out['tomorrow_label_key'] = forecast_label_key_from_wmo($tomorrowCode);
            }
        }

        $out['available'] = $out['current_temp_c'] !== null
            || $out['today_min_c'] !== null
            || $out['today_max_c'] !== null
            || $out['tomorrow_min_c'] !== null
            || $out['tomorrow_max_c'] !== null;
        $out['reason'] = $out['available'] ? '' : 'no_data';
    } catch (Throwable $e) {
        $out['reason'] = 'fetch_failed';
        log_event('warning', 'front.forecast', 'Forecast fetch failed', ['err' => $e->getMessage()]);
    }

    setting_set('forecast_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}
