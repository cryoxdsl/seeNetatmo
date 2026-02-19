<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';

const MF_VIGILANCE_ACCESSIBLE_URL = 'https://vigilance.meteofrance.fr/fr/vigilance-accessible';
const VIGICRUES_URL = 'https://www.vigicrues.gouv.fr';

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
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
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
        'avalanche' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18h16L13 6z" fill="currentColor"/><circle cx="10" cy="17" r="2" fill="#fff"/></svg>',
        'wave' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 14c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M2 18c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'flood' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h18v6H3z" fill="currentColor"/><path d="M4 19c1.3 0 1.3-1 2.6-1s1.3 1 2.6 1 1.3-1 2.6-1 1.3 1 2.6 1 1.3-1 2.6-1 1.3 1 2.6 1" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 7v6m0 4h.01" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
    };
}

function vigilance_normalize_text(string $value): string
{
    $map = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y',
        'œ' => 'oe',
    ];
    $value = strtr(strtolower($value), $map);
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function vigilance_types_from_label(string $label): array
{
    $v = vigilance_normalize_text($label);
    $types = [];

    if (str_contains($v, 'orages') || str_contains($v, 'orage')) $types[] = 'storm';
    if (str_contains($v, 'pluie')) $types[] = 'rain';
    if (str_contains($v, 'inondation') || str_contains($v, 'crues') || str_contains($v, 'crue')) $types[] = 'flood';
    if (str_contains($v, 'vagues-submersion') || str_contains($v, 'vague-submersion') || str_contains($v, 'submersion')) $types[] = 'wave';
    if (str_contains($v, 'vent')) $types[] = 'wind';
    if (str_contains($v, 'neige') || str_contains($v, 'verglas')) $types[] = 'snow';
    if (str_contains($v, 'canicule')) $types[] = 'heat';
    if (str_contains($v, 'grand froid') || str_contains($v, 'froid')) $types[] = 'cold';
    if (str_contains($v, 'avalanche')) $types[] = 'avalanche';

    $types = array_values(array_unique($types));
    return $types !== [] ? $types : ['generic'];
}

function vigilance_type_from_label(string $label): string
{
    $types = vigilance_types_from_label($label);
    return $types[0] ?? 'generic';
}

function vigilance_badge_label_for_type(string $type, string $sourceLabel): string
{
    $src = vigilance_normalize_text($sourceLabel);
    return match ($type) {
        'flood' => (str_contains($src, 'submersion') ? 'Submersion' : (str_contains($src, 'inondation') ? 'Inondation' : 'Crues')),
        'rain' => 'Pluie',
        'wind' => 'Vent violent',
        'storm' => 'Orages',
        'snow' => 'Neige-verglas',
        'heat' => 'Canicule',
        'cold' => 'Grand froid',
        'avalanche' => 'Avalanches',
        'wave' => 'Vagues-submersion',
        default => trim($sourceLabel) !== '' ? trim($sourceLabel) : 'Vigilance',
    };
}

function vigilance_extract_entries(string $text, string $level, string $dept): array
{
    $out = [];
    $start = 'Nom des départements en vigilance ' . $level . ' :';
    $p = stripos($text, $start);
    if ($p === false) {
        return [];
    }
    $slice = substr($text, $p + strlen($start), 4000);
    if (preg_match('/Nom des départements en vigilance (?:orange|jaune|rouge)\s*:/iu', $slice, $m, PREG_OFFSET_CAPTURE) === 1) {
        $slice = substr($slice, 0, (int) $m[0][1]);
    }

    if (preg_match_all('/([A-Za-zÀ-ÿ\'\-\s]+)\((\d{2,3}|2A|2B)\)\s*([^()]+?)(?=(?:[A-Za-zÀ-ÿ\'\-\s]+\((?:\d{2,3}|2A|2B)\))|$)/u', $slice, $all, PREG_SET_ORDER)) {
        foreach ($all as $entry) {
            $code = strtoupper(trim((string) $entry[2]));
            if ($code !== strtoupper($dept)) {
                continue;
            }
            $name = trim((string) $entry[1]);
            $phen = trim((string) $entry[3]);
            $phen = trim($phen, " \t\n\r\0\x0B,;.");
            $out[] = [
                'level' => vigilance_level_code($level),
                'dept' => $code,
                'dept_name' => $name,
                'phenomenon' => $phen,
            ];
        }
    }
    return $out;
}

function vigilance_extract_entry(string $text, string $level, string $dept): ?array
{
    $entries = vigilance_extract_entries($text, $level, $dept);
    return $entries[0] ?? null;
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
    $fallbackByDept = [
        '01' => 'ain',
        '02' => 'aisne',
        '03' => 'allier',
        '04' => 'alpes-de-haute-provence',
        '05' => 'hautes-alpes',
        '06' => 'alpes-maritimes',
        '07' => 'ardeche',
        '08' => 'ardennes',
        '09' => 'ariege',
        '10' => 'aube',
        '11' => 'aude',
        '12' => 'aveyron',
        '13' => 'bouches-du-rhone',
        '14' => 'calvados',
        '15' => 'cantal',
        '16' => 'charente',
        '17' => 'charente-maritime',
        '18' => 'cher',
        '19' => 'correze',
        '2A' => 'corse-du-sud',
        '2B' => 'haute-corse',
        '21' => 'cote-d-or',
        '22' => 'cotes-d-armor',
        '23' => 'creuse',
        '24' => 'dordogne',
        '25' => 'doubs',
        '26' => 'drome',
        '27' => 'eure',
        '28' => 'eure-et-loir',
        '29' => 'finistere',
        '30' => 'gard',
        '31' => 'haute-garonne',
        '32' => 'gers',
        '33' => 'gironde',
        '34' => 'herault',
        '35' => 'ille-et-vilaine',
        '36' => 'indre',
        '37' => 'indre-et-loire',
        '38' => 'isere',
        '39' => 'jura',
        '40' => 'landes',
        '41' => 'loir-et-cher',
        '42' => 'loire',
        '43' => 'haute-loire',
        '44' => 'loire-atlantique',
        '45' => 'loiret',
        '46' => 'lot',
        '47' => 'lot-et-garonne',
        '48' => 'lozere',
        '49' => 'maine-et-loire',
        '50' => 'manche',
        '51' => 'marne',
        '52' => 'haute-marne',
        '53' => 'mayenne',
        '54' => 'meurthe-et-moselle',
        '55' => 'meuse',
        '56' => 'morbihan',
        '57' => 'moselle',
        '58' => 'nievre',
        '59' => 'nord',
        '60' => 'oise',
        '61' => 'orne',
        '62' => 'pas-de-calais',
        '63' => 'puy-de-dome',
        '64' => 'pyrenees-atlantiques',
        '65' => 'hautes-pyrenees',
        '66' => 'pyrenees-orientales',
        '67' => 'bas-rhin',
        '68' => 'haut-rhin',
        '69' => 'rhone',
        '70' => 'haute-saone',
        '71' => 'saone-et-loire',
        '72' => 'sarthe',
        '73' => 'savoie',
        '74' => 'haute-savoie',
        '75' => 'paris',
        '76' => 'seine-maritime',
        '77' => 'seine-et-marne',
        '78' => 'yvelines',
        '79' => 'deux-sevres',
        '80' => 'somme',
        '81' => 'tarn',
        '82' => 'tarn-et-garonne',
        '83' => 'var',
        '84' => 'vaucluse',
        '85' => 'vendee',
        '86' => 'vienne',
        '87' => 'haute-vienne',
        '88' => 'vosges',
        '89' => 'yonne',
        '90' => 'territoire-de-belfort',
        '91' => 'essonne',
        '92' => 'hauts-de-seine',
        '93' => 'seine-saint-denis',
        '94' => 'val-de-marne',
        '95' => 'val-d-oise',
        '971' => 'guadeloupe',
        '972' => 'martinique',
        '973' => 'guyane',
        '974' => 'la-reunion',
        '976' => 'mayotte',
    ];
    $k = strtoupper($dept);
    if (isset($fallbackByDept[$k])) {
        return 'https://vigilance.meteofrance.fr/fr/' . $fallbackByDept[$k];
    }

    $deptName = trim($deptName);
    if ($deptName !== '') {
        $slug = vigilance_slug($deptName);
        if ($slug !== '') {
            return 'https://vigilance.meteofrance.fr/fr/' . rawurlencode($slug);
        }
    }

    return 'https://vigilance.meteofrance.fr/fr/widget-vigilance/vigilance-departement/' . rawurlencode($dept);
}

function vigilance_level_rank(string $level): int
{
    $level = vigilance_level_code($level);
    return match ($level) {
        'red' => 3,
        'orange' => 2,
        'yellow' => 1,
        default => 0,
    };
}

function vigilance_level_code(string $level): string
{
    $v = strtolower(trim($level));
    return match ($v) {
        'rouge', 'red' => 'red',
        'orange' => 'orange',
        'jaune', 'yellow' => 'yellow',
        default => 'green',
    };
}

function vigilance_source_from_label(string $label): string
{
    $v = vigilance_normalize_text($label);
    if (str_contains($v, 'crues') || preg_match('/\bcrue\b/', $v) === 1) {
        return 'vigicrues';
    }
    return 'meteofrance';
}

function vigilance_source_url(string $source, string $dept, string $deptName = ''): string
{
    if ($source === 'vigicrues') {
        return VIGICRUES_URL;
    }
    return vigilance_department_url($dept, $deptName);
}

function vigilance_parse_heading_date(string $heading, DateTimeImmutable $now): ?DateTimeImmutable
{
    $value = trim($heading);
    if ($value === '') {
        return null;
    }

    $norm = vigilance_normalize_text($value);
    $norm = preg_replace('/^(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+/i', '', $norm) ?? $norm;
    if (!preg_match('/(\d{1,2})\s+([a-z\-]+)(?:\s+(\d{4}))?/i', $norm, $m)) {
        return null;
    }

    $day = (int) $m[1];
    $monthToken = strtolower((string) $m[2]);
    $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $now->format('Y');

    $monthMap = [
        'janvier' => 1, 'january' => 1,
        'fevrier' => 2, 'february' => 2,
        'mars' => 3, 'march' => 3,
        'avril' => 4, 'april' => 4,
        'mai' => 5, 'may' => 5,
        'juin' => 6, 'june' => 6,
        'juillet' => 7, 'july' => 7,
        'aout' => 8, 'august' => 8,
        'septembre' => 9, 'september' => 9,
        'octobre' => 10, 'october' => 10,
        'novembre' => 11, 'november' => 11,
        'decembre' => 12, 'december' => 12,
    ];
    $month = $monthMap[$monthToken] ?? 0;
    if ($month <= 0) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone(APP_TIMEZONE));
    } catch (Throwable) {
        return null;
    }

    if (!isset($m[3]) || $m[3] === '') {
        if ($dt < $now->modify('-183 days')) {
            $dt = $dt->modify('+1 year');
        } elseif ($dt > $now->modify('+183 days')) {
            $dt = $dt->modify('-1 year');
        }
    }
    return $dt;
}

function vigilance_extract_day_sections(string $text): array
{
    $out = [];
    if (!preg_match_all('/Vigilance\s+m[^\s]*\s+et\s+crues\s+pour\s+(.+?)(?=Vigilance\s+m[^\s]*\s+et\s+crues\s+pour\s+|$)/isu', $text, $all, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($all as $m) {
        $chunk = trim((string) ($m[1] ?? ''));
        if ($chunk === '') {
            continue;
        }
        $parts = preg_split('/Diffusion\s*:/iu', $chunk, 2);
        $heading = trim((string) ($parts[0] ?? ''));
        $out[] = [
            'heading' => $heading,
            'text' => $chunk,
        ];
    }
    return $out;
}

function vigilance_entries_for_dept(string $text, string $dept): array
{
    $entriesRed = vigilance_extract_entries($text, 'rouge', $dept);
    $entriesOrange = vigilance_extract_entries($text, 'orange', $dept);
    $entriesYellow = vigilance_extract_entries($text, 'jaune', $dept);
    return array_values(array_merge($entriesRed, $entriesOrange, $entriesYellow));
}

function vigilance_summary_from_entries(array $entries): array
{
    if ($entries === []) {
        return [
            'active' => false,
            'level' => 'green',
            'labels' => [],
            'types' => [],
            'type' => 'generic',
            'alerts' => [],
            'entry' => null,
        ];
    }

    $level = 'green';
    $labels = [];
    $types = [];
    $alertsByPhenomenon = [];
    foreach ($entries as $e) {
        $lvl = vigilance_level_code((string) ($e['level'] ?? 'green'));
        if (vigilance_level_rank($lvl) > vigilance_level_rank($level)) {
            $level = $lvl;
        }
        $label = trim((string) ($e['phenomenon'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
        $types = array_merge($types, vigilance_types_from_label($label));
        if ($label === '') {
            continue;
        }
        $source = vigilance_source_from_label($label);
        $dept = strtoupper(trim((string) ($e['dept'] ?? '')));
        $deptName = trim((string) ($e['dept_name'] ?? ''));
        foreach (vigilance_types_from_label($label) as $tp) {
            $badgeLabel = vigilance_badge_label_for_type($tp, $label);
            $keyRaw = $source . '|' . $tp . '|' . $badgeLabel;
            $key = function_exists('mb_strtolower') ? mb_strtolower($keyRaw, 'UTF-8') : strtolower($keyRaw);
            if (!isset($alertsByPhenomenon[$key]) || vigilance_level_rank($lvl) > vigilance_level_rank((string) $alertsByPhenomenon[$key]['level'])) {
                $alertsByPhenomenon[$key] = [
                    'level' => $lvl,
                    'type' => $tp,
                    'label' => $badgeLabel,
                    'source' => $source,
                    'url' => vigilance_source_url($source, $dept, $deptName),
                ];
            }
        }
    }

    $labels = array_values(array_unique($labels));
    $types = array_values(array_unique($types));
    if ($types === []) {
        $types = [vigilance_type_from_label((string) ($entries[0]['phenomenon'] ?? ''))];
    }
    $alerts = array_values($alertsByPhenomenon);
    usort($alerts, static function (array $a, array $b): int {
        return vigilance_level_rank((string) ($b['level'] ?? 'green')) <=> vigilance_level_rank((string) ($a['level'] ?? 'green'));
    });

    return [
        'active' => true,
        'level' => $level,
        'labels' => $labels,
        'types' => $types,
        'type' => $types[0] ?? 'generic',
        'alerts' => $alerts,
        'entry' => $entries[0] ?? null,
    ];
}

function vigilance_current(bool $allowRemote = false): array
{
    $dept = station_department();
    $default = [
        'dept' => $dept,
        'fetched_at' => time(),
        'active' => false,
        'level' => 'green',
        'level_label' => 'green',
        'phenomenon' => '',
        'type' => 'generic',
        'types' => [],
        'phenomena' => [],
        'alerts_current' => [],
        'alerts_next_12h' => [],
        'next_12h_active' => false,
        'next_12h_level' => 'green',
        'next_12h_phenomena' => [],
        'next_12h_phenomenon' => '',
        'alerts' => [],
        'period_text' => '',
        'updated_text' => '',
        'url' => vigilance_department_url($dept),
    ];
    $rawCache = setting_get('mf_vigilance_cache_json', '');
    $lastTry = (int) (setting_get('mf_vigilance_last_try', '0') ?? 0);
    $retryAfter = 300;
    if ($rawCache !== '') {
        $cache = json_decode($rawCache, true);
        if (is_array($cache) && ($cache['dept'] ?? '') === $dept && ((int) ($cache['fetched_at'] ?? 0)) > (time() - 300)) {
            return array_replace($default, $cache);
        }
        if (is_array($cache) && ($cache['dept'] ?? '') === $dept && $lastTry > (time() - $retryAfter)) {
            return array_replace($default, $cache);
        }
        if (!$allowRemote && is_array($cache) && ($cache['dept'] ?? '') === $dept) {
            return array_replace($default, $cache);
        }
    } elseif ($lastTry > (time() - $retryAfter)) {
        return $default;
    }
    if (!$allowRemote) {
        return $default;
    }

    $result = $default;

    try {
        setting_set('mf_vigilance_last_try', (string) time());
        $html = vigilance_http_get(MF_VIGILANCE_ACCESSIBLE_URL);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));

        if (preg_match('/Diffusion\s*:\s*le\s*([^\.]+)\./iu', $text, $m) === 1) {
            $result['updated_text'] = trim((string) $m[1]);
        }
        if (preg_match('/Vigilance\s+m[^\s]*\s+et\s+crues\s+pour\s*([^\.]+)\./iu', $text, $m) === 1) {
            $result['period_text'] = trim((string) $m[1]);
        }

        $sections = vigilance_extract_day_sections($text);
        if ($sections === []) {
            $sections = [['heading' => '', 'text' => $text]];
        }

        $resolved = [];
        foreach ($sections as $section) {
            $heading = trim((string) ($section['heading'] ?? ''));
            $sectionText = (string) ($section['text'] ?? '');
            $resolved[] = [
                'heading' => $heading,
                'date' => vigilance_parse_heading_date($heading, $now),
                'summary' => vigilance_summary_from_entries(vigilance_entries_for_dept($sectionText, $dept)),
            ];
        }

        $todayKey = $now->format('Y-m-d');
        $deadlineKey = $now->modify('+12 hours')->format('Y-m-d');
        $currentIndex = 0;
        foreach ($resolved as $i => $item) {
            $dateKey = $item['date'] instanceof DateTimeImmutable ? $item['date']->format('Y-m-d') : '';
            if ($dateKey === $todayKey) {
                $currentIndex = $i;
                break;
            }
        }

        $nextIndex = null;
        foreach ($resolved as $i => $item) {
            if ($i === $currentIndex) {
                continue;
            }
            $dateKey = $item['date'] instanceof DateTimeImmutable ? $item['date']->format('Y-m-d') : '';
            if ($dateKey !== '' && $dateKey > $todayKey && $dateKey <= $deadlineKey) {
                $nextIndex = $i;
                break;
            }
        }

        $current = $resolved[$currentIndex]['summary'] ?? vigilance_summary_from_entries([]);
        if (($current['active'] ?? false) === true) {
            $result['active'] = true;
            $result['level'] = (string) ($current['level'] ?? 'green');
            $result['level_label'] = $result['level'];
            $result['phenomena'] = (array) ($current['labels'] ?? []);
            $result['phenomenon'] = implode(', ', $result['phenomena']);
            $result['types'] = (array) ($current['types'] ?? []);
            $result['type'] = (string) ($current['type'] ?? 'generic');
            $result['alerts_current'] = (array) ($current['alerts'] ?? []);
            $result['alerts'] = $result['alerts_current'];
            $entry = $current['entry'] ?? null;
            if (is_array($entry)) {
                $result['url'] = vigilance_department_url($dept, (string) ($entry['dept_name'] ?? ''));
            }
            $currentHeading = trim((string) ($resolved[$currentIndex]['heading'] ?? ''));
            if ($currentHeading !== '') {
                $result['period_text'] = $currentHeading;
            }
        }

        if ($nextIndex !== null) {
            $next = $resolved[$nextIndex]['summary'] ?? vigilance_summary_from_entries([]);
            if (($next['active'] ?? false) === true) {
                $result['next_12h_active'] = true;
                $result['next_12h_level'] = (string) ($next['level'] ?? 'green');
                $result['next_12h_phenomena'] = (array) ($next['labels'] ?? []);
                $result['next_12h_phenomenon'] = implode(', ', $result['next_12h_phenomena']);
                $result['alerts_next_12h'] = (array) ($next['alerts'] ?? []);
                if ($result['alerts_current'] === []) {
                    $result['alerts'] = $result['alerts_next_12h'];
                }
            }
        }
    } catch (Throwable $e) {
        log_event('warning', 'front.vigilance', 'Vigilance fetch failed', ['err' => $e->getMessage(), 'dept' => $dept]);
    }

    setting_set('mf_vigilance_cache_json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $result;
}
