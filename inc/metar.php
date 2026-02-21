<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const METAR_API_XML_URL = 'https://aviationweather.gov/adds/dataserver_current/httpparam';
const METAR_API_JSON_URL = 'https://aviationweather.gov/api/data/metar';
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
        CURLOPT_TIMEOUT => 7,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'meteo13-netatmo/1.0 contact@meteo13.fr',
        CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml;q=0.9,*/*;q=0.8'],
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

function metar_http_get_json(string $url): array
{
    $transientErrnos = [6, 7, 28, 35, 52, 56];
    $maxAttempts = 3;
    $lastErr = '';
    $lastHttp = 0;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $attempt === 1 ? 5 : 8,
            CURLOPT_CONNECTTIMEOUT => $attempt === 1 ? 2 : 4,
            CURLOPT_USERAGENT => 'meteo13-netatmo/1.0 contact@meteo13.fr',
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain;q=0.9,*/*;q=0.8'],
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            $lastErr = $err;
            $lastHttp = $http;
            if (in_array($errno, $transientErrnos, true) && $attempt < $maxAttempts) {
                usleep(250000 * $attempt);
                continue;
            }
            throw new RuntimeException('METAR JSON fetch failed: ' . $err);
        }
        if ($http >= 400) {
            $lastHttp = $http;
            if ($http >= 500 && $attempt < $maxAttempts) {
                usleep(250000 * $attempt);
                continue;
            }
            throw new RuntimeException('METAR JSON HTTP ' . $http);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('METAR JSON invalid');
        }
        return $json;
    }
    throw new RuntimeException('METAR JSON fetch failed: ' . ($lastErr !== '' ? $lastErr : ('HTTP ' . $lastHttp)));
}

function metar_fetch_nearby_json(array $coords, int $radiusKm = 120, int $hours = 3): array
{
    $lat = (float) $coords['lat'];
    $lon = (float) $coords['lon'];
    $deltaLat = max(0.1, $radiusKm / 111.0);
    $cosLat = max(0.15, abs(cos(deg2rad($lat))));
    $deltaLon = max(0.1, $radiusKm / (111.0 * $cosLat));
    $latMin = max(-90.0, $lat - $deltaLat);
    $latMax = min(90.0, $lat + $deltaLat);
    $lonMin = max(-180.0, $lon - $deltaLon);
    $lonMax = min(180.0, $lon + $deltaLon);

    $query = http_build_query([
        'format' => 'json',
        'hours' => $hours,
        // AviationWeather JSON API expects bbox=minLon,minLat,maxLon,maxLat
        'bbox' => number_format($lonMin, 4, '.', '') . ',' . number_format($latMin, 4, '.', '')
            . ',' . number_format($lonMax, 4, '.', '') . ',' . number_format($latMax, 4, '.', ''),
    ]);
    $url = METAR_API_JSON_URL . '?' . $query;
    return ['items' => metar_http_get_json($url), 'url' => $url];
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

function metar_locale_code(): string
{
    if (function_exists('locale_current') && locale_current() === 'en_EN') {
        return 'en';
    }
    return 'fr';
}

function metar_fraction_to_float(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $m) === 1) {
        $den = (float) $m[3];
        if ($den <= 0.0) {
            return null;
        }
        return (float) $m[1] + ((float) $m[2] / $den);
    }
    if (preg_match('/^(\d+)\/(\d+)$/', $value, $m) === 1) {
        $den = (float) $m[2];
        if ($den <= 0.0) {
            return null;
        }
        return (float) $m[1] / $den;
    }
    return null;
}

function metar_visibility_mi_to_float(string $raw): ?float
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return null;
    }
    $raw = str_replace(['SM', 'MI'], '', $raw);
    $raw = trim(str_replace('+', '', $raw));
    return metar_fraction_to_float($raw);
}

function metar_cardinal_from_degrees(?float $degrees, string $lang): string
{
    if ($degrees === null) {
        return $lang === 'en' ? 'variable' : 'variable';
    }
    $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    $idx = (int) round((fmod($degrees, 360.0) / 22.5));
    if ($idx < 0) {
        $idx += 16;
    }
    return $dirs[$idx % 16];
}

function metar_decode_wx_token(string $token, string $lang): string
{
    $token = strtoupper(trim($token));
    if ($token === '') {
        return '';
    }

    $intensity = '';
    if (str_starts_with($token, '+') || str_starts_with($token, '-')) {
        $intensity = $token[0];
        $token = substr($token, 1);
    }

    $vicinity = false;
    if (str_starts_with($token, 'VC')) {
        $vicinity = true;
        $token = substr($token, 2);
    }

    $descriptors = [
        'MI' => ['fr' => 'mince', 'en' => 'shallow'],
        'PR' => ['fr' => 'partielle', 'en' => 'partial'],
        'BC' => ['fr' => 'bancs', 'en' => 'patches'],
        'DR' => ['fr' => 'chasse-neige', 'en' => 'drifting'],
        'BL' => ['fr' => 'soulevée', 'en' => 'blowing'],
        'SH' => ['fr' => 'averses', 'en' => 'showers'],
        'TS' => ['fr' => 'orage', 'en' => 'thunderstorm'],
        'FZ' => ['fr' => 'verglaçante', 'en' => 'freezing'],
    ];
    $phenomena = [
        'DZ' => ['fr' => 'bruine', 'en' => 'drizzle'],
        'RA' => ['fr' => 'pluie', 'en' => 'rain'],
        'SN' => ['fr' => 'neige', 'en' => 'snow'],
        'SG' => ['fr' => 'neige en grains', 'en' => 'snow grains'],
        'PL' => ['fr' => 'granules de glace', 'en' => 'ice pellets'],
        'GR' => ['fr' => 'grêle', 'en' => 'hail'],
        'GS' => ['fr' => 'grésil', 'en' => 'small hail'],
        'BR' => ['fr' => 'brume', 'en' => 'mist'],
        'FG' => ['fr' => 'brouillard', 'en' => 'fog'],
        'HZ' => ['fr' => 'brume sèche', 'en' => 'haze'],
        'DU' => ['fr' => 'poussière', 'en' => 'dust'],
        'SA' => ['fr' => 'sable', 'en' => 'sand'],
        'SQ' => ['fr' => 'grain', 'en' => 'squall'],
        'FC' => ['fr' => 'trombe', 'en' => 'funnel cloud'],
        'SS' => ['fr' => 'tempête de sable', 'en' => 'sandstorm'],
        'DS' => ['fr' => 'tempête de poussière', 'en' => 'duststorm'],
    ];

    $descWords = [];
    while (strlen($token) >= 2) {
        $code = substr($token, 0, 2);
        if (!isset($descriptors[$code])) {
            break;
        }
        $descWords[] = $descriptors[$code][$lang];
        $token = substr($token, 2);
    }

    $phenWords = [];
    while (strlen($token) >= 2) {
        $code = substr($token, 0, 2);
        if (!isset($phenomena[$code])) {
            break;
        }
        $phenWords[] = $phenomena[$code][$lang];
        $token = substr($token, 2);
    }

    if ($descWords === [] && $phenWords === []) {
        return strtoupper(trim($token));
    }

    $parts = [];
    if ($intensity === '+') {
        $parts[] = $lang === 'en' ? 'heavy' : 'forte';
    } elseif ($intensity === '-') {
        $parts[] = $lang === 'en' ? 'light' : 'faible';
    }
    if ($vicinity) {
        $parts[] = $lang === 'en' ? 'in the vicinity' : 'à proximité';
    }
    if ($descWords !== []) {
        $parts[] = implode(' ', $descWords);
    }
    if ($phenWords !== []) {
        $parts[] = implode(' ', $phenWords);
    }
    return trim(implode(' ', $parts));
}

function metar_parse_signed_temp_token(string $token): ?float
{
    $token = strtoupper(trim($token));
    if ($token === '') {
        return null;
    }
    if (preg_match('/^M?(\d{2})$/', $token) !== 1) {
        return null;
    }
    $value = (float) substr($token, -2);
    return str_starts_with($token, 'M') ? -$value : $value;
}

function metar_beaufort_from_kt(float $kt): int
{
    if ($kt < 1.0) {
        return 0;
    }
    if ($kt <= 3.0) {
        return 1;
    }
    if ($kt <= 6.0) {
        return 2;
    }
    if ($kt <= 10.0) {
        return 3;
    }
    if ($kt <= 16.0) {
        return 4;
    }
    if ($kt <= 21.0) {
        return 5;
    }
    if ($kt <= 27.0) {
        return 6;
    }
    if ($kt <= 33.0) {
        return 7;
    }
    if ($kt <= 40.0) {
        return 8;
    }
    if ($kt <= 47.0) {
        return 9;
    }
    if ($kt <= 55.0) {
        return 10;
    }
    if ($kt <= 63.0) {
        return 11;
    }
    return 12;
}

function metar_relative_humidity(?float $tempC, ?float $dewC): ?float
{
    if ($tempC === null || $dewC === null) {
        return null;
    }
    $a = 17.625;
    $b = 243.04;
    $gammaT = ($a * $tempC) / ($b + $tempC);
    $gammaD = ($a * $dewC) / ($b + $dewC);
    $rh = 100.0 * exp($gammaD - $gammaT);
    $rh = max(0.0, min(100.0, $rh));
    return round($rh, 0);
}

function metar_cardinal_label_long_fr(?float $degrees): string
{
    if ($degrees === null) {
        return 'variable';
    }
    $short = metar_cardinal_from_degrees($degrees, 'fr');
    return match ($short) {
        'N' => 'nord',
        'NNE' => 'nord-nord-est',
        'NE' => 'nord-est',
        'ENE' => 'est-nord-est',
        'E' => 'est',
        'ESE' => 'est-sud-est',
        'SE' => 'sud-est',
        'SSE' => 'sud-sud-est',
        'S' => 'sud',
        'SSW' => 'sud-sud-ouest',
        'SW' => 'sud-ouest',
        'WSW' => 'ouest-sud-ouest',
        'W' => 'ouest',
        'WNW' => 'ouest-nord-ouest',
        'NW' => 'nord-ouest',
        'NNW' => 'nord-nord-ouest',
        default => 'variable',
    };
}

function metar_decode_human(array $metar): array
{
    if (empty($metar['available'])) {
        return [];
    }
    $lang = metar_locale_code();
    $lines = [];
    $raw = strtoupper(trim((string) ($metar['raw_text'] ?? '')));
    $icao = strtoupper(trim((string) ($metar['airport_icao'] ?? ($metar['station_id'] ?? ''))));

    $obsTs = null;
    if (!empty($metar['observed_at'])) {
        $tmpTs = strtotime((string) $metar['observed_at']);
        if ($tmpTs !== false) {
            $obsTs = $tmpTs;
        }
    }
    if ($obsTs === null && preg_match('/\b(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})\b/', $raw, $mDate) === 1) {
        $obsTs = strtotime($mDate[1] . '-' . $mDate[2] . '-' . $mDate[3] . ' ' . $mDate[4] . ':' . $mDate[5] . ':00 UTC') ?: null;
    }

    if ($lang === 'fr') {
        if ($obsTs !== null && $icao !== '') {
            $lines[] = 'METAR du ' . gmdate('d', $obsTs) . ' à ' . gmdate('H\hi', $obsTs) . ' UTC pour ' . $icao . ' :';
        } elseif ($icao !== '') {
            $lines[] = 'METAR pour ' . $icao . ' :';
        }
    } else {
        if ($obsTs !== null && $icao !== '') {
            $lines[] = 'METAR for ' . $icao . ' on ' . gmdate('d/m/Y H:i', $obsTs) . ' UTC:';
        } elseif ($icao !== '') {
            $lines[] = 'METAR for ' . $icao . ':';
        }
    }

    if ($raw !== '' && preg_match('/\bAUTO\b/', $raw) === 1) {
        $lines[] = $lang === 'fr'
            ? 'Note : Les relevés ont été effectués par une station météorologique automatique.'
            : 'Note: Observations were made by an automated weather station.';
    }

    $windDir = isset($metar['wind_dir_degrees']) && $metar['wind_dir_degrees'] !== '' ? (float) $metar['wind_dir_degrees'] : null;
    $windSpeedKt = isset($metar['wind_speed_kt']) && $metar['wind_speed_kt'] !== '' ? (float) $metar['wind_speed_kt'] : null;
    if (($windDir === null || $windSpeedKt === null) && $raw !== '' && preg_match('/\b(\d{3}|VRB)(\d{2,3})(G(\d{2,3}))?KT\b/', $raw, $mWind) === 1) {
        if ($windDir === null && $mWind[1] !== 'VRB') {
            $windDir = (float) $mWind[1];
        }
        if ($windSpeedKt === null) {
            $windSpeedKt = (float) $mWind[2];
        }
    }
    if ($windDir !== null || $windSpeedKt !== null) {
        if ($lang === 'fr') {
            $dirLabel = metar_cardinal_label_long_fr($windDir);
            $degTxt = $windDir !== null ? number_format($windDir, 0, '.', '') : 'N/A';
            $ktTxt = $windSpeedKt !== null ? number_format($windSpeedKt, 0, '.', '') : 'N/A';
            $kmhTxt = $windSpeedKt !== null ? number_format($windSpeedKt * 1.852, 0, '.', '') : 'N/A';
            $lines[] = 'Le vent provient d\'' . $dirLabel . ' (' . $degTxt . '°) avec une vitesse de ' . $ktTxt . ' noeuds (' . $kmhTxt . ' km/h).';
        } else {
            $dirLabel = metar_cardinal_from_degrees($windDir, 'en');
            $degTxt = $windDir !== null ? number_format($windDir, 0, '.', '') : 'N/A';
            $ktTxt = $windSpeedKt !== null ? number_format($windSpeedKt, 0, '.', '') : 'N/A';
            $kmhTxt = $windSpeedKt !== null ? number_format($windSpeedKt * 1.852, 0, '.', '') : 'N/A';
            $lines[] = 'Wind is from ' . $dirLabel . ' (' . $degTxt . '°) at ' . $ktTxt . ' kt (' . $kmhTxt . ' km/h).';
        }
        if ($windSpeedKt !== null) {
            $beaufort = metar_beaufort_from_kt($windSpeedKt);
            $lines[] = $lang === 'fr' ? ('Beaufort : ' . $beaufort) : ('Beaufort: ' . $beaufort);
        }
    }

    $visMeters = null;
    if ($raw !== '' && preg_match('/\b(9999|[0-7][0-9]{3})\b/', $raw, $mVis) === 1) {
        $visMeters = (int) $mVis[1];
    }
    if ($visMeters === 9999) {
        $lines[] = $lang === 'fr'
            ? 'La visibilité est supérieure à 10 km'
            : 'Visibility is greater than 10 km';
        $lines[] = $lang === 'fr'
            ? 'Il y a 10000 m de visibilité'
            : 'There is 10000 m visibility';
    } elseif ($visMeters !== null) {
        $km = $visMeters / 1000.0;
        $lines[] = $lang === 'fr'
            ? ('La visibilité est de ' . number_format($km, 1, '.', '') . ' km')
            : ('Visibility is ' . number_format($km, 1, '.', '') . ' km');
        $lines[] = $lang === 'fr'
            ? ('Il y a ' . $visMeters . ' m de visibilité')
            : ('There is ' . $visMeters . ' m visibility');
    } else {
        $visRaw = (string) ($metar['visibility_statute_mi'] ?? '');
        $visMi = metar_visibility_mi_to_float($visRaw);
        if ($visMi !== null) {
            $km = $visMi * 1.60934;
            $lines[] = $lang === 'fr'
                ? ('La visibilité est de ' . number_format($km, 1, '.', '') . ' km')
                : ('Visibility is ' . number_format($km, 1, '.', '') . ' km');
        }
    }

    $temp = isset($metar['temp_c']) && $metar['temp_c'] !== '' ? (float) $metar['temp_c'] : null;
    $dew = isset($metar['dewpoint_c']) && $metar['dewpoint_c'] !== '' ? (float) $metar['dewpoint_c'] : null;
    if (($temp === null || $dew === null) && $raw !== '' && preg_match('/\b(M?\d{2})\/(M?\d{2})\b/', $raw, $mTd) === 1) {
        if ($temp === null) {
            $temp = metar_parse_signed_temp_token((string) $mTd[1]);
        }
        if ($dew === null) {
            $dew = metar_parse_signed_temp_token((string) $mTd[2]);
        }
    }
    if ($temp !== null) {
        $lines[] = $lang === 'fr'
            ? ('La température est de ' . number_format($temp, 0, '.', '') . ' °C')
            : ('Temperature is ' . number_format($temp, 0, '.', '') . ' °C');
    }
    if ($dew !== null) {
        $lines[] = $lang === 'fr'
            ? ('Le point de rosée est de ' . number_format($dew, 0, '.', '') . ' °C')
            : ('Dew point is ' . number_format($dew, 0, '.', '') . ' °C');
    }
    $rh = metar_relative_humidity($temp, $dew);
    if ($rh !== null) {
        $lines[] = $lang === 'fr'
            ? ('L\'humidité relative est de ' . number_format($rh, 0, '.', '') . '%')
            : ('Relative humidity is ' . number_format($rh, 0, '.', '') . '%');
    }

    $qnhHpa = null;
    if ($raw !== '' && preg_match('/\bQ(\d{4})\b/', $raw, $mQnh) === 1) {
        $qnhHpa = (float) $mQnh[1];
    } elseif (!empty($metar['altim_in_hg']) && is_numeric((string) $metar['altim_in_hg'])) {
        $qnhHpa = round(((float) $metar['altim_in_hg']) * 33.8638866667, 0);
    }
    if ($qnhHpa !== null) {
        $lines[] = $lang === 'fr'
            ? ('La pression au niveau de la mer est de ' . number_format($qnhHpa, 0, '.', '') . ' hPa')
            : ('Sea-level pressure is ' . number_format($qnhHpa, 0, '.', '') . ' hPa');
    }

    return array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
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

function metar_default_icao(): string
{
    $v = strtoupper(trim((string) (setting_get('metar_default_icao', 'LFML') ?? 'LFML')));
    if (!preg_match('/^[A-Z]{4}$/', $v)) {
        return 'LFML';
    }
    return $v;
}

function metar_fetch_by_icao(string $icao): ?array
{
    $icao = strtoupper(trim($icao));
    if (!preg_match('/^[A-Z]{4}$/', $icao)) {
        return null;
    }
    $query = http_build_query([
        'ids' => $icao,
        'format' => 'json',
        'hours' => 6,
    ]);
    $url = METAR_API_JSON_URL . '?' . $query;
    $json = metar_http_get_json($url);
    if (!$json || !isset($json[0]) || !is_array($json[0])) {
        return null;
    }
    $m = $json[0];

    $sky = [];
    if (isset($m['clouds']) && is_array($m['clouds'])) {
        foreach ($m['clouds'] as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $cover = (string) ($layer['cover'] ?? '');
            $base = isset($layer['base']) ? (string) $layer['base'] : '';
            if ($cover === '') {
                continue;
            }
            $sky[] = ['cover' => $cover, 'base' => $base];
        }
    }

    return [
        'station_id' => (string) ($m['icaoId'] ?? $icao),
        'raw_text' => (string) ($m['rawOb'] ?? ''),
        'observation_time' => (string) ($m['obsTime'] ?? ''),
        'latitude' => isset($m['lat']) ? (string) $m['lat'] : '',
        'longitude' => isset($m['lon']) ? (string) $m['lon'] : '',
        'temp_c' => isset($m['temp']) ? (string) $m['temp'] : '',
        'dewpoint_c' => isset($m['dewp']) ? (string) $m['dewp'] : '',
        'wind_dir_degrees' => isset($m['wdir']) ? (string) $m['wdir'] : '',
        'wind_speed_kt' => isset($m['wspd']) ? (string) $m['wspd'] : '',
        'wind_gust_kt' => isset($m['wgst']) ? (string) $m['wgst'] : '',
        'visibility_statute_mi' => isset($m['visib']) ? (string) $m['visib'] : '',
        'altim_in_hg' => isset($m['altim']) ? (string) $m['altim'] : '',
        'flight_category' => (string) ($m['flightCat'] ?? ''),
        'wx_string' => (string) ($m['wxString'] ?? ''),
        'sky' => $sky,
    ];
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
        'format' => 'json',
        'hours' => 3,
    ]);
    $url = METAR_API_JSON_URL . '?' . $query;

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

    $primaryErrors = [];
    try {
        setting_set('metar_last_try', (string) time());
        $near = metar_fetch_nearby_json($coords, 120, 3);
        $items = is_array($near['items'] ?? null) ? $near['items'] : [];
        if (!empty($near['url']) && is_string($near['url'])) {
            $out['source_url'] = $near['url'];
        }
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
        $primaryErrors[] = $e->getMessage();
    }

    if (empty($out['available'])) {
        try {
            $queryXml = http_build_query([
                'dataSource' => 'metars',
                'requestType' => 'retrieve',
                'format' => 'xml',
                'hoursBeforeNow' => 3,
                'radialDistance' => '120;' . number_format($coords['lat'], 4, '.', '') . ',' . number_format($coords['lon'], 4, '.', ''),
            ]);
            $urlXml = METAR_API_XML_URL . '?' . $queryXml;
            $xml = metar_http_get_xml($urlXml);
            $items = metar_parse_items($xml);
            if ($items) {
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
                    $out['source_url'] = $urlXml;
                    $out['airport_icao'] = (string) ($best['station_id'] ?? '');
                    $out['distance_km'] = $bestDist !== null ? round($bestDist, 1) : null;
                    $out['observed_at'] = (string) ($best['observation_time'] ?? '');
                    $out['raw_text'] = (string) ($best['raw_text'] ?? '');
                    $out['headline'] = (string) ($decoded['headline'] ?? '');
                    $out['weather'] = (string) ($decoded['weather'] ?? '');
                    $out['sky'] = (string) ($decoded['sky'] ?? '');
                }
            }
        } catch (Throwable $e) {
            $primaryErrors[] = $e->getMessage();
        }
    }

    if (empty($out['available'])) {
        try {
            $fallbackIcao = metar_default_icao();
            $fallback = metar_fetch_by_icao($fallbackIcao);
            if (is_array($fallback)) {
                $decoded = metar_decode_summary($fallback);
                $dist = null;
                if (
                    isset($fallback['latitude'], $fallback['longitude'])
                    && is_numeric((string) $fallback['latitude'])
                    && is_numeric((string) $fallback['longitude'])
                ) {
                    $dist = metar_haversine_km(
                        $coords['lat'],
                        $coords['lon'],
                        (float) $fallback['latitude'],
                        (float) $fallback['longitude']
                    );
                }
                $out['available'] = true;
                $out['reason'] = 'fallback_icao';
                $out['airport_icao'] = (string) ($fallback['station_id'] ?? $fallbackIcao);
                $out['distance_km'] = $dist !== null ? round($dist, 1) : null;
                $out['observed_at'] = (string) ($fallback['observation_time'] ?? '');
                $out['raw_text'] = (string) ($fallback['raw_text'] ?? '');
                $out['headline'] = (string) ($decoded['headline'] ?? '');
                $out['weather'] = (string) ($decoded['weather'] ?? '');
                $out['sky'] = (string) ($decoded['sky'] ?? '');
                // Fallback works: no warning spam for primary provider hiccups.
                $primaryErrors = [];
            }
        } catch (Throwable $e) {
            log_event('warning', 'front.metar', 'METAR fallback fetch failed', ['err' => $e->getMessage()]);
        }
    }

    if (empty($out['available']) && $primaryErrors !== []) {
        log_event('warning', 'front.metar', 'METAR fetch failed', ['err' => implode(' | ', $primaryErrors)]);
    }

    setting_set('metar_cache_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}
