<?php
declare(strict_types=1);

function weather_condition_from_row(?array $row, array $state, ?array $prev = null): array
{
    if (!$row || $state['disconnected']) {
        return [
            'type' => 'offline',
            'label' => 'Station deconnectee',
            'detail' => 'Aucune mesure recente du module exterieur',
            'trend' => 'unknown',
        ];
    }

    $t = $row['T'] !== null ? (float) $row['T'] : null;
    $h = $row['H'] !== null ? (float) $row['H'] : null;
    $rr = $row['RR'] !== null ? (float) $row['RR'] : 0.0;
    $w = $row['W'] !== null ? (float) $row['W'] : 0.0;

    $type = 'voile';
    $label = 'Voile';
    $detail = 'Ciel partiellement couvert';

    if ($rr > 0.05 && $t !== null && $t <= 1.5) {
        $type = 'snow';
        $label = 'Neige';
        $detail = 'Precipitations froides detectees';
    } elseif ($rr > 0.1) {
        $type = 'rain';
        $label = 'Pluie';
        $detail = 'Precipitations en cours';
    } elseif ($w >= 40) {
        $type = 'wind';
        $label = 'Vent fort';
        $detail = 'Rafales soutenues';
    } elseif ($h !== null && $h >= 90) {
        $type = 'very_cloudy';
        $label = 'Tres nuageux';
        $detail = 'Humidite elevee';
    } elseif ($h !== null && $h >= 75) {
        $type = 'cloudy';
        $label = 'Nuageux';
        $detail = 'Ciel charge';
    } elseif ($h !== null && $h < 55) {
        $type = 'sunny';
        $label = 'Grand soleil';
        $detail = 'Air plutot sec';
    }

    $trend = 'stable';
    if ($prev && $prev['T'] !== null && $row['T'] !== null) {
        $delta = (float) $row['T'] - (float) $prev['T'];
        if ($delta >= 0.3) {
            $trend = 'up';
        } elseif ($delta <= -0.3) {
            $trend = 'down';
        }
    }

    return ['type' => $type, 'label' => $label, 'detail' => $detail, 'trend' => $trend];
}

function weather_trend_label(string $trend): string
{
    return match ($trend) {
        'up' => 'Tendance temperature: hausse',
        'down' => 'Tendance temperature: baisse',
        'stable' => 'Tendance temperature: stable',
        default => 'Tendance temperature: inconnue',
    };
}

function weather_icon_svg(string $type): string
{
    return match ($type) {
        'sunny' => '<svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="22" fill="#f8bf24"/><g stroke="#f8bf24" stroke-width="6" stroke-linecap="round"><line x1="60" y1="10" x2="60" y2="28"/><line x1="60" y1="92" x2="60" y2="110"/><line x1="10" y1="60" x2="28" y2="60"/><line x1="92" y1="60" x2="110" y2="60"/><line x1="24" y1="24" x2="36" y2="36"/><line x1="84" y1="84" x2="96" y2="96"/><line x1="24" y1="96" x2="36" y2="84"/><line x1="84" y1="36" x2="96" y2="24"/></g></svg>',
        'cloudy' => '<svg viewBox="0 0 140 120" aria-hidden="true"><ellipse cx="62" cy="72" rx="30" ry="22" fill="#9ab3c9"/><ellipse cx="85" cy="72" rx="34" ry="24" fill="#8fa8bf"/><ellipse cx="46" cy="78" rx="24" ry="18" fill="#a5bdd2"/></svg>',
        'very_cloudy' => '<svg viewBox="0 0 140 120" aria-hidden="true"><ellipse cx="60" cy="66" rx="34" ry="24" fill="#7e98b2"/><ellipse cx="88" cy="70" rx="36" ry="26" fill="#6f8aa4"/><ellipse cx="44" cy="78" rx="28" ry="20" fill="#8ca5bc"/></svg>',
        'rain' => '<svg viewBox="0 0 140 140" aria-hidden="true"><ellipse cx="60" cy="58" rx="34" ry="24" fill="#7f99b2"/><ellipse cx="90" cy="62" rx="36" ry="26" fill="#708aa4"/><g stroke="#2f78c5" stroke-width="6" stroke-linecap="round"><line x1="44" y1="92" x2="38" y2="112"/><line x1="70" y1="92" x2="64" y2="112"/><line x1="96" y1="92" x2="90" y2="112"/></g></svg>',
        'snow' => '<svg viewBox="0 0 140 140" aria-hidden="true"><ellipse cx="60" cy="58" rx="34" ry="24" fill="#90a9bf"/><ellipse cx="90" cy="62" rx="36" ry="26" fill="#819bb3"/><g stroke="#d7e8f7" stroke-width="4" stroke-linecap="round"><line x1="46" y1="102" x2="58" y2="114"/><line x1="58" y1="102" x2="46" y2="114"/><line x1="82" y1="102" x2="94" y2="114"/><line x1="94" y1="102" x2="82" y2="114"/></g></svg>',
        'wind' => '<svg viewBox="0 0 160 120" aria-hidden="true"><g fill="none" stroke="#5e86aa" stroke-width="8" stroke-linecap="round"><path d="M16 44h90c16 0 24-22 8-30"/><path d="M16 68h118c18 0 28 18 14 30"/><path d="M16 92h78c14 0 20-12 12-20"/></g></svg>',
        'offline' => '<svg viewBox="0 0 140 120" aria-hidden="true"><circle cx="70" cy="56" r="30" fill="#cad6e2"/><line x1="44" y1="30" x2="96" y2="82" stroke="#9bafc2" stroke-width="8"/></svg>',
        default => '<svg viewBox="0 0 140 120" aria-hidden="true"><ellipse cx="70" cy="60" rx="34" ry="24" fill="#9ab3c9"/></svg>',
    };
}
