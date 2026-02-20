<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const WEATHER_ALERTS_API_URL = 'https://api.open-meteo.com/v1/forecast';
const WEATHER_ALERTS_REVERSE_API_URL = 'https://nominatim.openstreetmap.org/reverse';
const WEATHER_ALERTS_METEOALARM_FR_ATOM_URL = 'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-france';
const WEATHER_ALERTS_CACHE_TTL_SECONDS = 900;

function weather_alerts_station_coordinates(): ?array
{
    // Optional override for alerts zone (admin setting), fallback to station coordinates.
    $lat = setting_get('alerts_zone_lat', '');
    $lon = setting_get('alerts_zone_lon', '');
    if (!is_string($lat) || !is_string($lon) || $lat === '' || $lon === '') {
        $lat = setting_get('station_lat', '');
        $lon = setting_get('station_lon', '');
    }
    if (!is_string($lat) || !is_string($lon) || $lat === '' || $lon === '') {
        return null;
    }
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }
    return ['lat' => (float) $lat, 'lon' => (float) $lon];
}

function weather_alerts_http_get_json(string $url): array
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
        throw new RuntimeException('Weather alerts fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('Weather alerts HTTP ' . $http);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Weather alerts invalid JSON');
    }
    return $json;
}

function weather_alerts_http_get_text(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'meteo13-netatmo/1.0',
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Weather alerts fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('Weather alerts HTTP ' . $http);
    }
    return $raw;
}

function weather_alerts_localized_title(string $type): string
{
    return t('alerts.type.' . $type);
}

function weather_alerts_source_setting(): string
{
    $v = strtolower(trim((string) (setting_get('alerts_source', 'openmeteo') ?? 'openmeteo')));
    return in_array($v, ['openmeteo', 'meteoalarm'], true) ? $v : 'openmeteo';
}

function weather_alerts_severity_from_text(string $text): string
{
    $t = strtolower($text);
    if (str_contains($t, 'red') || str_contains($t, 'rouge')) {
        return 'high';
    }
    if (str_contains($t, 'orange') || str_contains($t, 'amber')) {
        return 'moderate';
    }
    if (str_contains($t, 'yellow') || str_contains($t, 'jaune')) {
        return 'low';
    }
    return 'moderate';
}

function weather_alerts_parse_meteoalarm_atom(string $xml, string $zoneLabel): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('SimpleXML not available');
    }
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    if (!$sx) {
        throw new RuntimeException('MeteoAlarm XML invalid');
    }
    $entries = $sx->xpath('//*[local-name()="entry"]');
    if (!is_array($entries)) {
        $entries = [];
    }

    $zoneNeedle = strtolower(trim((string) explode(',', $zoneLabel)[0]));
    $all = [];
    $filtered = [];
    foreach ($entries as $entry) {
        $title = trim((string) ($entry->title ?? ''));
        $summaryRaw = (string) ($entry->summary ?? '');
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($summaryRaw)) ?? '');
        $updated = trim((string) ($entry->updated ?? ''));
        if ($title === '' && $summary === '') {
            continue;
        }
        $text = strtolower($title . ' ' . $summary);
        $alert = [
            'type' => 'official',
            'title' => $title !== '' ? $title : t('alerts.official_warning'),
            'severity' => weather_alerts_severity_from_text($title . ' ' . $summary),
            'detail' => $summary,
            'updated' => $updated,
        ];
        $all[] = $alert;
        if ($zoneNeedle !== '' && str_contains($text, $zoneNeedle)) {
            $filtered[] = $alert;
        }
    }
    $use = $filtered !== [] ? $filtered : $all;
    if ($use !== [] && count($use) > 6) {
        $use = array_slice($use, 0, 6);
    }
    $updatedAt = '';
    if ($use !== []) {
        $updatedAt = trim((string) ($use[0]['updated'] ?? ''));
    }
    return ['alerts' => $use, 'updated_at' => $updatedAt];
}

function weather_alerts_reverse_admin_label(float $lat, float $lon): string
{
    $query = http_build_query([
        'format' => 'jsonv2',
        'lat' => number_format($lat, 6, '.', ''),
        'lon' => number_format($lon, 6, '.', ''),
        'zoom' => 10,
        'addressdetails' => 1,
        'accept-language' => locale_current() === 'en_EN' ? 'en' : 'fr',
    ]);
    $url = WEATHER_ALERTS_REVERSE_API_URL . '?' . $query;

    try {
        $json = weather_alerts_http_get_json($url);
    } catch (Throwable) {
        return '';
    }
    $address = is_array($json['address'] ?? null) ? $json['address'] : [];
    if ($address === []) {
        return '';
    }

    $local = '';
    foreach (['city', 'town', 'village', 'municipality', 'hamlet'] as $k) {
        $v = trim((string) ($address[$k] ?? ''));
        if ($v !== '') {
            $local = $v;
            break;
        }
    }
    $county = trim((string) ($address['county'] ?? ($address['state_district'] ?? '')));
    $region = trim((string) ($address['state'] ?? ($address['region'] ?? '')));

    $parts = [];
    foreach ([$local, $county, $region] as $p) {
        if ($p === '' || in_array($p, $parts, true)) {
            continue;
        }
        $parts[] = $p;
    }
    if ($parts !== []) {
        return implode(', ', $parts);
    }

    $display = trim((string) ($json['display_name'] ?? ''));
    if ($display === '') {
        return '';
    }
    $first = trim((string) explode(',', $display)[0]);
    return $first;
}

function weather_alerts_summary(bool $allowRemote = false): array
{
    $source = weather_alerts_source_setting();
    $coords = weather_alerts_station_coordinates();
    if ($coords === null) {
        return ['available' => false, 'reason' => 'no_station_coords'];
    }

    $cacheRaw = setting_get('weather_alerts_cache_json', '');
    $lastTry = (int) (setting_get('weather_alerts_last_try', '0') ?? 0);
    $retryAfter = 300;

    if ($cacheRaw !== '') {
        $cache = json_decode($cacheRaw, true);
        if (is_array($cache)) {
            if ((string) ($cache['source'] ?? 'openmeteo') !== $source) {
                $cache = [];
            }
        }
        if (is_array($cache) && $cache !== []) {
            if ((string) ($cache['zone_label'] ?? '') === '') {
                $cache['zone_label'] = weather_alerts_reverse_admin_label($coords['lat'], $coords['lon']);
                if ((string) ($cache['zone_label'] ?? '') === '') {
                    $cache['zone_label'] = number_format($coords['lat'], 3, '.', '') . ', ' . number_format($coords['lon'], 3, '.', '');
                }
                $cache['station_lat'] = $coords['lat'];
                $cache['station_lon'] = $coords['lon'];
                setting_set('weather_alerts_cache_json', json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $fresh = ((int) ($cache['fetched_at'] ?? 0)) > (time() - WEATHER_ALERTS_CACHE_TTL_SECONDS);
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
        'latitude' => $coords['lat'],
        'longitude' => $coords['lon'],
        'hourly' => 'weather_code,precipitation,wind_speed_10m,temperature_2m',
        'forecast_days' => 2,
        'timezone' => APP_TIMEZONE,
    ]);
    $url = WEATHER_ALERTS_API_URL . '?' . $query;

    $out = [
        'available' => false,
        'reason' => 'fetch_failed',
        'fetched_at' => time(),
        'station_lat' => $coords['lat'],
        'station_lon' => $coords['lon'],
        'source' => $source,
        'zone_label' => '',
        'updated_at' => '',
        'window' => '48h',
        'alerts' => [],
    ];

    try {
        setting_set('weather_alerts_last_try', (string) time());
        $out['zone_label'] = weather_alerts_reverse_admin_label($coords['lat'], $coords['lon']);
        if ($out['zone_label'] === '') {
            $out['zone_label'] = number_format($coords['lat'], 3, '.', '') . ', ' . number_format($coords['lon'], 3, '.', '');
        }

        if ($source === 'meteoalarm') {
            $out['window'] = t('alerts.window_official');
            $xml = weather_alerts_http_get_text(WEATHER_ALERTS_METEOALARM_FR_ATOM_URL);
            $parsed = weather_alerts_parse_meteoalarm_atom($xml, $out['zone_label']);
            $out['alerts'] = is_array($parsed['alerts'] ?? null) ? $parsed['alerts'] : [];
            $out['updated_at'] = trim((string) ($parsed['updated_at'] ?? ''));
            $out['available'] = true;
            $out['reason'] = '';
            setting_set('weather_alerts_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $out;
        }

        $json = weather_alerts_http_get_json($url);

        $hourly = is_array($json['hourly'] ?? null) ? $json['hourly'] : [];
        $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
        $codes = is_array($hourly['weather_code'] ?? null) ? $hourly['weather_code'] : [];
        $precips = is_array($hourly['precipitation'] ?? null) ? $hourly['precipitation'] : [];
        $winds = is_array($hourly['wind_speed_10m'] ?? null) ? $hourly['wind_speed_10m'] : [];
        $temps = is_array($hourly['temperature_2m'] ?? null) ? $hourly['temperature_2m'] : [];

        $count = max(count($codes), count($precips), count($winds), count($temps));
        $maxPrecip = 0.0;
        $maxWind = 0.0;
        $maxTemp = -999.0;
        $minTemp = 999.0;
        $thunderHours = 0;
        $snowHours = 0;

        for ($i = 0; $i < $count; $i++) {
            $code = isset($codes[$i]) && is_numeric($codes[$i]) ? (int) $codes[$i] : null;
            $p = isset($precips[$i]) && is_numeric($precips[$i]) ? (float) $precips[$i] : 0.0;
            $w = isset($winds[$i]) && is_numeric($winds[$i]) ? (float) $winds[$i] : 0.0;
            $t = isset($temps[$i]) && is_numeric($temps[$i]) ? (float) $temps[$i] : null;

            if ($p > $maxPrecip) {
                $maxPrecip = $p;
            }
            if ($w > $maxWind) {
                $maxWind = $w;
            }
            if ($t !== null) {
                if ($t > $maxTemp) {
                    $maxTemp = $t;
                }
                if ($t < $minTemp) {
                    $minTemp = $t;
                }
            }
            if ($code !== null && in_array($code, [95, 96, 99], true)) {
                $thunderHours++;
            }
            if ($code !== null && in_array($code, [71, 73, 75, 77, 85, 86], true)) {
                $snowHours++;
            }
        }

        $alerts = [];

        if ($thunderHours > 0) {
            $alerts[] = [
                'type' => 'thunderstorm',
                'severity' => 'high',
                'detail' => $thunderHours . 'h ' . t('alerts.hours_affected'),
            ];
        }

        if ($maxPrecip >= 12.0) {
            $alerts[] = [
                'type' => 'heavy_rain',
                'severity' => 'high',
                'detail' => number_format($maxPrecip, 1, '.', '') . ' mm/h',
            ];
        } elseif ($maxPrecip >= 6.0) {
            $alerts[] = [
                'type' => 'heavy_rain',
                'severity' => 'moderate',
                'detail' => number_format($maxPrecip, 1, '.', '') . ' mm/h',
            ];
        }

        if ($maxWind >= 80.0) {
            $alerts[] = [
                'type' => 'strong_wind',
                'severity' => 'high',
                'detail' => number_format($maxWind, 0, '.', '') . ' km/h',
            ];
        } elseif ($maxWind >= 60.0) {
            $alerts[] = [
                'type' => 'strong_wind',
                'severity' => 'moderate',
                'detail' => number_format($maxWind, 0, '.', '') . ' km/h',
            ];
        }

        if ($snowHours > 0) {
            $alerts[] = [
                'type' => 'snow',
                'severity' => 'moderate',
                'detail' => $snowHours . 'h ' . t('alerts.hours_affected'),
            ];
        }

        if ($maxTemp > -900.0 && $maxTemp >= 35.0) {
            $alerts[] = [
                'type' => 'heat',
                'severity' => $maxTemp >= 38.0 ? 'high' : 'moderate',
                'detail' => number_format($maxTemp, 1, '.', '') . ' °C',
            ];
        }

        if ($minTemp < 900.0 && $minTemp <= 0.0) {
            $alerts[] = [
                'type' => 'frost',
                'severity' => $minTemp <= -3.0 ? 'moderate' : 'low',
                'detail' => number_format($minTemp, 1, '.', '') . ' °C',
            ];
        }

        $severityRank = ['low' => 1, 'moderate' => 2, 'high' => 3];
        usort($alerts, static function (array $a, array $b) use ($severityRank): int {
            $ra = $severityRank[(string) ($a['severity'] ?? 'low')] ?? 1;
            $rb = $severityRank[(string) ($b['severity'] ?? 'low')] ?? 1;
            return $rb <=> $ra;
        });

        $out['alerts'] = array_map(static function (array $a): array {
            return [
                'type' => (string) ($a['type'] ?? ''),
                'title' => weather_alerts_localized_title((string) ($a['type'] ?? '')),
                'severity' => (string) ($a['severity'] ?? 'low'),
                'detail' => (string) ($a['detail'] ?? ''),
            ];
        }, $alerts);
        $out['available'] = true;
        $out['reason'] = '';
        $out['updated_at'] = isset($times[0]) ? (string) $times[0] : date('Y-m-d H:i:s');
    } catch (Throwable $e) {
        log_event('warning', 'front.alerts', 'Weather alerts fetch failed', ['err' => $e->getMessage()]);
    }

    setting_set('weather_alerts_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}
