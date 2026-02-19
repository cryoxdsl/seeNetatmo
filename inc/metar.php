<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const METAR_API_URL = 'https://aviationweather.gov/adds/dataserver_current/httpparam';
const METAR_CACHE_TTL_SECONDS = 900;

function metar_station_coordinates(): ?array
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

function metar_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return $r * $c;
}

function metar_http_get_xml(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_USERAGENT => 'meteo13-netatmo/1.0',
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('METAR fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('METAR HTTP ' . $http);
    }
    return $raw;
}

function metar_decode_summary(array $metar): array
{
    $parts = [];
    if (!empty($metar['flight_category'])) {
        $parts[] = 'Cat ' . (string) $metar['flight_category'];
    }
    if (isset($metar['wind_dir_degrees']) && $metar['wind_dir_degrees'] !== '') {
        $wind = (string) $metar['wind_dir_degrees'] . '°';
        if (isset($metar['wind_speed_kt']) && $metar['wind_speed_kt'] !== '') {
            $wind .= ' ' . (string) $metar['wind_speed_kt'] . ' kt';
        }
        if (isset($metar['wind_gust_kt']) && $metar['wind_gust_kt'] !== '') {
            $wind .= ' G' . (string) $metar['wind_gust_kt'];
        }
        $parts[] = $wind;
    }
    if (isset($metar['visibility_statute_mi']) && $metar['visibility_statute_mi'] !== '') {
        $parts[] = 'Vis ' . (string) $metar['visibility_statute_mi'] . ' mi';
    }
    if (isset($metar['temp_c']) && $metar['temp_c'] !== '') {
        $tmp = (string) $metar['temp_c'] . 'C';
        if (isset($metar['dewpoint_c']) && $metar['dewpoint_c'] !== '') {
            $tmp .= ' / ' . (string) $metar['dewpoint_c'] . 'C';
        }
        $parts[] = $tmp;
    }
    if (isset($metar['altim_in_hg']) && $metar['altim_in_hg'] !== '') {
        $parts[] = 'QNH ' . (string) $metar['altim_in_hg'] . ' inHg';
    }

    $sky = [];
    if (isset($metar['sky']) && is_array($metar['sky'])) {
        foreach ($metar['sky'] as $layer) {
            $cover = (string) ($layer['cover'] ?? '');
            $base = (string) ($layer['base'] ?? '');
            if ($cover === '') {
                continue;
            }
            $sky[] = $base !== '' ? ($cover . ' ' . $base . ' ft') : $cover;
        }
    }

    return [
        'headline' => implode(' | ', $parts),
        'sky' => implode(', ', $sky),
        'weather' => (string) ($metar['wx_string'] ?? ''),
    ];
}

function metar_parse_items(string $xml): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('SimpleXML not available');
    }
    $sx = @simplexml_load_string($xml);
    if (!$sx) {
        throw new RuntimeException('METAR XML invalid');
    }
    $items = [];
    if (!isset($sx->data->METAR)) {
        return $items;
    }
    foreach ($sx->data->METAR as $node) {
        $row = [
            'station_id' => (string) ($node->station_id ?? ''),
            'raw_text' => (string) ($node->raw_text ?? ''),
            'observation_time' => (string) ($node->observation_time ?? ''),
            'latitude' => (string) ($node->latitude ?? ''),
            'longitude' => (string) ($node->longitude ?? ''),
            'temp_c' => (string) ($node->temp_c ?? ''),
            'dewpoint_c' => (string) ($node->dewpoint_c ?? ''),
            'wind_dir_degrees' => (string) ($node->wind_dir_degrees ?? ''),
            'wind_speed_kt' => (string) ($node->wind_speed_kt ?? ''),
            'wind_gust_kt' => (string) ($node->wind_gust_kt ?? ''),
            'visibility_statute_mi' => (string) ($node->visibility_statute_mi ?? ''),
            'altim_in_hg' => (string) ($node->altim_in_hg ?? ''),
            'flight_category' => (string) ($node->flight_category ?? ''),
            'wx_string' => (string) ($node->wx_string ?? ''),
            'sky' => [],
        ];
        if (isset($node->sky_condition)) {
            foreach ($node->sky_condition as $sky) {
                $attrs = $sky->attributes();
                $row['sky'][] = [
                    'cover' => isset($attrs['sky_cover']) ? (string) $attrs['sky_cover'] : '',
                    'base' => isset($attrs['cloud_base_ft_agl']) ? (string) $attrs['cloud_base_ft_agl'] : '',
                ];
            }
        }
        $items[] = $row;
    }
    return $items;
}

function metar_nearest(bool $allowRemote = false): array
{
    $coords = metar_station_coordinates();
    if ($coords === null) {
        return ['available' => false, 'reason' => 'no_station_coords'];
    }

    $cacheRaw = setting_get('metar_cache_json', '');
    $lastTry = (int) (setting_get('metar_last_try', '0') ?? 0);
    $retryAfter = 300;
    if ($cacheRaw !== '') {
        $cache = json_decode($cacheRaw, true);
        if (is_array($cache)) {
            $fresh = ((int) ($cache['fetched_at'] ?? 0)) > (time() - METAR_CACHE_TTL_SECONDS);
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

    $query = http_build_query([
        'dataSource' => 'metars',
        'requestType' => 'retrieve',
        'format' => 'xml',
        'hoursBeforeNow' => 3,
        'radialDistance' => '120;' . number_format($coords['lat'], 4, '.', '') . ',' . number_format($coords['lon'], 4, '.', ''),
    ]);
    $url = METAR_API_URL . '?' . $query;

    $out = [
        'available' => false,
        'reason' => 'fetch_failed',
        'fetched_at' => time(),
        'station_lat' => $coords['lat'],
        'station_lon' => $coords['lon'],
        'airport_icao' => '',
        'distance_km' => null,
        'observed_at' => '',
        'raw_text' => '',
        'headline' => '',
        'weather' => '',
        'sky' => '',
        'source_url' => $url,
    ];

    try {
        setting_set('metar_last_try', (string) time());
        $xml = metar_http_get_xml($url);
        $items = metar_parse_items($xml);
        if (!$items) {
            $out['reason'] = 'no_data';
        } else {
            $best = null;
            $bestDist = null;
            foreach ($items as $item) {
                if (!isset($item['latitude'], $item['longitude']) || !is_numeric($item['latitude']) || !is_numeric($item['longitude'])) {
                    continue;
                }
                $dist = metar_haversine_km($coords['lat'], $coords['lon'], (float) $item['latitude'], (float) $item['longitude']);
                if ($bestDist === null || $dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $item;
                }
            }
            if ($best !== null) {
                $decoded = metar_decode_summary($best);
                $out['available'] = true;
                $out['reason'] = '';
                $out['airport_icao'] = (string) ($best['station_id'] ?? '');
                $out['distance_km'] = $bestDist !== null ? round($bestDist, 1) : null;
                $out['observed_at'] = (string) ($best['observation_time'] ?? '');
                $out['raw_text'] = (string) ($best['raw_text'] ?? '');
                $out['headline'] = (string) ($decoded['headline'] ?? '');
                $out['weather'] = (string) ($decoded['weather'] ?? '');
                $out['sky'] = (string) ($decoded['sky'] ?? '');
            } else {
                $out['reason'] = 'no_georef_data';
            }
        }
    } catch (Throwable $e) {
        log_event('warning', 'front.metar', 'METAR fetch failed', ['err' => $e->getMessage()]);
    }

    setting_set('metar_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}
