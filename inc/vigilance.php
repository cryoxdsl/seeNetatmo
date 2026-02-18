<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const MF_VIGILANCE_ACCESSIBLE_URL = 'https://vigilance.meteofrance.fr/fr/vigilance-accessible';

function station_department_from_zip(?string $zip): ?string
{
    $zip = preg_replace('/\D+/', '', (string) $zip);
    if ($zip === null || strlen($zip) < 2) {
        return null;
    }
    if (str_starts_with($zip, '97') || str_starts_with($zip, '98')) {
        return strlen($zip) >= 3 ? substr($zip, 0, 3) : null;
    }
    return substr($zip, 0, 2);
}

function station_department(): string
{
    $manual = strtoupper(trim((string) setting_get('station_department', '')));
    if ($manual !== '' && preg_match('/^(?:\d{2,3}|2A|2B)$/', $manual) === 1) {
        return $manual;
    }
    $fromZip = station_department_from_zip(setting_get('station_zipcode', ''));
    return $fromZip ?? '13';
}

function vigilance_http_get(string $url): string
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
        throw new RuntimeException('Vigilance fetch failed: ' . $err);
    }
    if ($http >= 400) {
        throw new RuntimeException('Vigilance HTTP ' . $http);
    }
    return $raw;
}

function vigilance_icon(string $type): string
{
    return match ($type) {
        'rain' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18a4 4 0 1 1 .8-7.9A5.5 5.5 0 0 1 18.5 12 3.5 3.5 0 0 1 18 19H7z" fill="currentColor"/><path d="M8 21l1.2-2M12 21l1.2-2M16 21l1.2-2" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/></svg>',
        'wind' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h11a2.5 2.5 0 1 0-2.3-3.5M3 14h15a2 2 0 1 1-1.8 3M3 19h9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'snow' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 16a4 4 0 1 1 .8-7.9A5.5 5.5 0 0 1 18.5 10 3.5 3.5 0 0 1 18 17H7z" fill="currentColor"/><path d="M9 20h0M13 20h0M17 20h0" stroke="#fff" stroke-width="2.3" stroke-linecap="round"/></svg>',
        'storm' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 15a4 4 0 1 1 .8-7.9A5.5 5.5 0 0 1 18.5 9 3.5 3.5 0 0 1 18 16H7z" fill="currentColor"/><path d="M12 13l-2 4h2l-1 4 4-6h-2l1-2z" fill="#fff"/></svg>',
        'heat' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M17 7l2.1-2.1M4.9 19.1L7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'cold' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M6 6l12 12M18 6L6 18M4 12h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'flood' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h18v6H3z" fill="currentColor"/><path d="M4 19c1.3 0 1.3-1 2.6-1s1.3 1 2.6 1 1.3-1 2.6-1 1.3 1 2.6 1 1.3-1 2.6-1 1.3 1 2.6 1" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 7v6m0 4h.01" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
    };
}

function vigilance_type_from_label(string $label): string
{
    $v = strtolower($label);
    if (str_contains($v, 'pluie')) return 'rain';
    if (str_contains($v, 'vent')) return 'wind';
    if (str_contains($v, 'neige') || str_contains($v, 'verglas')) return 'snow';
    if (str_contains($v, 'orage')) return 'storm';
    if (str_contains($v, 'canicule')) return 'heat';
    if (str_contains($v, 'froid')) return 'cold';
    if (str_contains($v, 'crues') || str_contains($v, 'submersion')) return 'flood';
    return 'generic';
}

function vigilance_extract_entry(string $text, string $level, string $dept): ?array
{
    $start = 'Nom des départements en vigilance ' . $level . ' :';
    $p = stripos($text, $start);
    if ($p === false) {
        return null;
    }
    $slice = substr($text, $p + strlen($start), 4000);
    if (preg_match('/Nom des départements en vigilance (?:orange|jaune|rouge)\s*:/iu', $slice, $m, PREG_OFFSET_CAPTURE) === 1) {
        $slice = substr($slice, 0, (int) $m[0][1]);
    }

    if (preg_match_all('/([A-Za-zÀ-ÿ\'\-\s]+)\((\d{2,3}|2A|2B)\)\s*([A-Za-zÀ-ÿ\-\s]+)/u', $slice, $all, PREG_SET_ORDER)) {
        foreach ($all as $entry) {
            $code = strtoupper(trim((string) $entry[2]));
            if ($code !== strtoupper($dept)) {
                continue;
            }
            $name = trim((string) $entry[1]);
            $phen = trim((string) $entry[3]);
            return ['level' => $level, 'dept' => $code, 'dept_name' => $name, 'phenomenon' => $phen];
        }
    }
    return null;
}

function vigilance_slug(string $value): string
{
    $value = trim(strtolower($value));
    $map = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y',
        '\'' => '-', '’' => '-', ' ' => '-',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?? $value;
    $value = preg_replace('/-+/', '-', $value) ?? $value;
    return trim($value, '-');
}

function vigilance_department_url(string $dept, string $deptName = ''): string
{
    $deptName = trim($deptName);
    if ($deptName !== '') {
        $slug = vigilance_slug($deptName);
        if ($slug !== '') {
            return 'https://vigilance.meteofrance.fr/fr/' . rawurlencode($slug);
        }
    }

    $fallbackByDept = [
        '13' => 'bouches-du-rhone',
    ];
    $k = strtoupper($dept);
    if (isset($fallbackByDept[$k])) {
        return 'https://vigilance.meteofrance.fr/fr/' . $fallbackByDept[$k];
    }

    return 'https://vigilance.meteofrance.fr/fr/widget-vigilance/vigilance-departement/' . rawurlencode($dept);
}

function vigilance_current(): array
{
    $dept = station_department();
    $rawCache = setting_get('mf_vigilance_cache_json', '');
    if ($rawCache !== '') {
        $cache = json_decode($rawCache, true);
        if (is_array($cache) && ($cache['dept'] ?? '') === $dept && ((int) ($cache['fetched_at'] ?? 0)) > (time() - 300)) {
            return $cache;
        }
    }

    $result = [
        'dept' => $dept,
        'fetched_at' => time(),
        'active' => false,
        'level' => 'green',
        'level_label' => 'green',
        'phenomenon' => '',
        'type' => 'generic',
        'period_text' => '',
        'updated_text' => '',
        'url' => vigilance_department_url($dept),
    ];

    try {
        $html = vigilance_http_get(MF_VIGILANCE_ACCESSIBLE_URL);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (preg_match('/Diffusion\s*:\s*le\s*([^\.]+)\./iu', $text, $m) === 1) {
            $result['updated_text'] = trim((string) $m[1]);
        }
        if (preg_match('/Vigilance météo et crues pour\s*([^\.]+)\./iu', $text, $m) === 1) {
            $result['period_text'] = trim((string) $m[1]);
        }

        $entry = vigilance_extract_entry($text, 'rouge', $dept)
            ?? vigilance_extract_entry($text, 'orange', $dept)
            ?? vigilance_extract_entry($text, 'jaune', $dept);

        if ($entry !== null) {
            $result['active'] = true;
            $result['level'] = $entry['level'];
            $result['level_label'] = $entry['level'];
            $result['phenomenon'] = $entry['phenomenon'];
            $result['type'] = vigilance_type_from_label($entry['phenomenon']);
            $result['url'] = vigilance_department_url($dept, (string) ($entry['dept_name'] ?? ''));
        }
    } catch (Throwable $e) {
        log_event('warning', 'front.vigilance', 'Vigilance fetch failed', ['err' => $e->getMessage(), 'dept' => $dept]);
    }

    setting_set('mf_vigilance_cache_json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $result;
}
