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

function weather_alerts_normalize_text(string $text): string
{
    $v = trim($text);
    if ($v === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if (is_string($tmp) && $tmp !== '') {
            $v = $tmp;
        }
    }
    $v = strtolower($v);
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? '';
    $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
    return $v;
}

function weather_alerts_location_needles(string $zoneLabel): array
{
    $needles = [];
    $parts = array_values(array_filter(array_map('trim', explode(',', $zoneLabel)), static fn(string $p): bool => $p !== ''));
    foreach ($parts as $part) {
        $norm = weather_alerts_normalize_text($part);
        if ($norm !== '' && strlen($norm) >= 3) {
            $needles[] = $norm;
        }
    }
    $needles = array_values(array_unique(array_filter($needles, static fn(string $n): bool => $n !== '')));
    return $needles;
}

function weather_alerts_simplify_plural(string $v): string
{
    $parts = explode(' ', $v);
    foreach ($parts as &$p) {
        if (strlen($p) > 4 && str_ends_with($p, 's')) {
            $p = substr($p, 0, -1);
        }
    }
    unset($p);
    return trim(implode(' ', $parts));
}

function weather_alerts_extract_area_from_title(string $title): string
{
    $raw = trim($title);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/\bFrance\s*-\s*(.+)$/i', $raw, $m) === 1) {
        return weather_alerts_normalize_text((string) $m[1]);
    }
    if (preg_match('/\bfor\s+(.+)$/i', $raw, $m) === 1) {
        return weather_alerts_normalize_text((string) $m[1]);
    }
    return '';
}

function weather_alerts_area_matches(string $areaNorm, array $needles): bool
{
    if ($areaNorm === '' || $needles === []) {
        return false;
    }
    $areaSimple = weather_alerts_simplify_plural($areaNorm);
    foreach ($needles as $needle) {
        $needleNorm = weather_alerts_normalize_text($needle);
        if ($needleNorm === '') {
            continue;
        }
        $needleSimple = weather_alerts_simplify_plural($needleNorm);
        if (
            $areaNorm === $needleNorm
            || $areaSimple === $needleSimple
            || str_contains($areaNorm, $needleNorm)
            || str_contains($needleNorm, $areaNorm)
            || str_contains($areaSimple, $needleSimple)
            || str_contains($needleSimple, $areaSimple)
        ) {
            return true;
        }
    }
    return false;
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

function weather_alerts_localize_official_title(string $title): string
{
    if (locale_current() !== 'fr_FR') {
        return $title;
    }
    $raw = trim($title);
    if ($raw === '') {
        return $raw;
    }
    $pattern = '/^(Yellow|Orange|Red)\s+(.+?)\s+Warning issued for\s+(.+)$/i';
    if (preg_match($pattern, $raw, $m) !== 1) {
        return $raw;
    }
    $colorEn = strtolower((string) $m[1]);
    $hazardEn = strtolower(trim((string) $m[2]));
    $area = trim((string) $m[3]);

    $colorFr = match ($colorEn) {
        'yellow' => 'jaune',
        'orange' => 'orange',
        'red' => 'rouge',
        default => $colorEn,
    };

    $hazardMap = [
        'avalanches' => 'avalanches',
        'snow-ice' => 'neige-verglas',
        'rain-flood' => 'pluie-inondation',
        'flood' => 'crues',
        'wind' => 'vent',
        'thunderstorm' => 'orages',
        'thunderstorms' => 'orages',
        'high-temperature' => 'canicule',
        'low-temperature' => 'grand froid',
        'coastal-event' => 'vagues-submersion',
        'rain' => 'pluie',
        'ice' => 'verglas',
        'snow' => 'neige',
    ];
    $hazardFr = $hazardMap[$hazardEn] ?? $hazardEn;
    $zone = preg_replace('/^\s*France\s*-\s*/i', '', $area) ?? $area;
    $zone = trim($zone);
    if ($zone === '') {
        return 'Vigilance ' . $colorFr . ' ' . $hazardFr;
    }
    return 'Vigilance ' . $colorFr . ' ' . $hazardFr . ' (' . $zone . ')';
}

function weather_alerts_parse_meteoalarm_atom(string $xml, string $zoneLabel, array $locationNeedles = []): array
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

    $needles = $locationNeedles;
    $zoneNeedle = weather_alerts_normalize_text((string) explode(',', $zoneLabel)[0]);
    if ($zoneNeedle !== '' && !in_array($zoneNeedle, $needles, true)) {
        $needles[] = $zoneNeedle;
    }
    $all = [];
    $filtered = [];
    $matchedTitles = [];
    $unmatchedTitles = [];
    foreach ($entries as $entry) {
        $title = trim((string) ($entry->title ?? ''));
        $summaryRaw = (string) ($entry->summary ?? '');
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($summaryRaw)) ?? '');
        $updated = trim((string) ($entry->updated ?? ''));
        if ($title === '' && $summary === '') {
            continue;
        }
        $text = weather_alerts_normalize_text($title . ' ' . $summary);
        $alert = [
            'type' => 'official',
            'title' => $title !== '' ? weather_alerts_localize_official_title($title) : t('alerts.official_warning'),
            'severity' => weather_alerts_severity_from_text($title . ' ' . $summary),
            'detail' => $summary,
            'updated' => $updated,
        ];
        $all[] = $alert;
        if ($needles !== []) {
            $areaNorm = weather_alerts_extract_area_from_title($title);
            if ($areaNorm !== '' && weather_alerts_area_matches($areaNorm, $needles)) {
                $filtered[] = $alert;
                $matchedTitles[] = $alert['title'];
                continue;
            }
            foreach ($needles as $needle) {
                $needleNorm = weather_alerts_normalize_text((string) $needle);
                if ($needleNorm !== '' && str_contains(weather_alerts_normalize_text($title), $needleNorm)) {
                    $filtered[] = $alert;
                    $matchedTitles[] = $alert['title'];
                    break;
                }
            }
            if (!in_array($alert, $filtered, true)) {
                $unmatchedTitles[] = $alert['title'];
            }
        }
    }
    $use = $needles !== [] ? $filtered : $all;
    if ($use !== [] && count($use) > 6) {
        $use = array_slice($use, 0, 6);
    }
    $updatedAt = '';
    if ($use !== []) {
        $updatedAt = trim((string) ($use[0]['updated'] ?? ''));
    }
    return [
        'alerts' => $use,
        'updated_at' => $updatedAt,
        'total_entries' => count($all),
        'matched_entries' => count($filtered),
        'matched_titles' => array_values(array_slice(array_unique($matchedTitles), 0, 5)),
        'unmatched_titles' => array_values(array_slice(array_unique($unmatchedTitles), 0, 5)),
    ];
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
        'debug' => [],
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
            $needles = weather_alerts_location_needles($out['zone_label']);
            $parsed = weather_alerts_parse_meteoalarm_atom($xml, $out['zone_label'], $needles);
            $out['alerts'] = is_array($parsed['alerts'] ?? null) ? $parsed['alerts'] : [];
            $out['updated_at'] = trim((string) ($parsed['updated_at'] ?? ''));
            $out['debug'] = [
                'source' => 'meteoalarm',
                'zone_label' => $out['zone_label'],
                'needles' => $needles,
                'total_entries' => (int) ($parsed['total_entries'] ?? 0),
                'matched_entries' => (int) ($parsed['matched_entries'] ?? 0),
                'matched_titles' => is_array($parsed['matched_titles'] ?? null) ? $parsed['matched_titles'] : [],
                'unmatched_titles' => is_array($parsed['unmatched_titles'] ?? null) ? $parsed['unmatched_titles'] : [],
            ];
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
