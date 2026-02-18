<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const SEA_TEMP_API_URL = 'https://marine-api.open-meteo.com/v1/marine';
const SEA_TEMP_CACHE_TTL_SECONDS = 1800;

function station_coordinates(): ?array
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

function sea_temp_http_get_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_USERAGENT => 'meteo13-netatmo/1.0',
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Sea temperature fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('Sea temperature HTTP ' . $http);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Sea temperature invalid JSON');
    }
    return $json;
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return $r * $c;
}

function sea_temp_nearest(): array
{
    $coords = station_coordinates();
    if ($coords === null) {
        return ['available' => false, 'reason' => 'no_station_coords'];
    }

    $cacheRaw = setting_get('sea_temp_cache_json', '');
    $lastTry = (int) (setting_get('sea_temp_last_try', '0') ?? 0);
    $retryAfter = 300;
    if ($cacheRaw !== '') {
        $cache = json_decode($cacheRaw, true);
        if (is_array($cache)) {
            $fresh = ((int) ($cache['fetched_at'] ?? 0)) > (time() - SEA_TEMP_CACHE_TTL_SECONDS);
            $same = abs(((float) ($cache['station_lat'] ?? 0)) - $coords['lat']) < 0.0001
                && abs(((float) ($cache['station_lon'] ?? 0)) - $coords['lon']) < 0.0001;
            if ($fresh && $same) {
                return $cache;
            }
            if ($same && $lastTry > (time() - $retryAfter)) {
                return $cache;
            }
        }
    } elseif ($lastTry > (time() - $retryAfter)) {
        return ['available' => false, 'reason' => 'retry_later'];
    }

    $query = http_build_query([
        'latitude' => $coords['lat'],
        'longitude' => $coords['lon'],
        'current' => 'sea_surface_temperature',
        'hourly' => 'sea_surface_temperature',
        'forecast_hours' => 6,
        'timezone' => APP_TIMEZONE,
        'cell_selection' => 'sea',
    ]);
    $url = SEA_TEMP_API_URL . '?' . $query;

    $out = [
        'available' => false,
        'fetched_at' => time(),
        'station_lat' => $coords['lat'],
        'station_lon' => $coords['lon'],
        'sea_lat' => null,
        'sea_lon' => null,
        'distance_km' => null,
        'value_c' => null,
        'time' => null,
        'source_url' => $url,
    ];

    try {
        setting_set('sea_temp_last_try', (string) time());
        $json = sea_temp_http_get_json($url);
        $value = null;
        $time = null;

        if (isset($json['current']['sea_surface_temperature']) && is_numeric($json['current']['sea_surface_temperature'])) {
            $value = (float) $json['current']['sea_surface_temperature'];
            $time = (string) ($json['current']['time'] ?? '');
        } elseif (isset($json['hourly']['sea_surface_temperature'], $json['hourly']['time']) && is_array($json['hourly']['sea_surface_temperature']) && is_array($json['hourly']['time'])) {
            $temps = $json['hourly']['sea_surface_temperature'];
            $times = $json['hourly']['time'];
            foreach ($temps as $i => $v) {
                if ($v !== null && is_numeric($v)) {
                    $value = (float) $v;
                    $time = (string) ($times[$i] ?? '');
                    break;
                }
            }
        }

        if ($value !== null) {
            $seaLat = isset($json['latitude']) && is_numeric($json['latitude']) ? (float) $json['latitude'] : null;
            $seaLon = isset($json['longitude']) && is_numeric($json['longitude']) ? (float) $json['longitude'] : null;
            $distance = null;
            if ($seaLat !== null && $seaLon !== null) {
                $distance = haversine_km($coords['lat'], $coords['lon'], $seaLat, $seaLon);
            }

            $out['available'] = true;
            $out['value_c'] = round($value, 1);
            $out['time'] = $time;
            $out['sea_lat'] = $seaLat;
            $out['sea_lon'] = $seaLon;
            $out['distance_km'] = $distance !== null ? round($distance, 1) : null;
        }
    } catch (Throwable $e) {
        log_event('warning', 'front.sea_temp', 'Sea temperature fetch failed', ['err' => $e->getMessage()]);
    }

    setting_set('sea_temp_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}
