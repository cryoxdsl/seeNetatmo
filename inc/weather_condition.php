<?php
declare(strict_types=1);

function weather_condition_from_row(?array $row, array $state, ?array $prev = null): array
{
    if (!$row || $state['disconnected']) {
        return [
            'type' => 'offline',
            'label' => t('weather.label.offline'),
            'detail' => t('weather.detail.offline'),
            'trend' => 'unknown',
        ];
    }

    $t = $row['T'] !== null ? (float) $row['T'] : null;
    $h = $row['H'] !== null ? (float) $row['H'] : null;
    $rr = $row['RR'] !== null ? (float) $row['RR'] : 0.0;
    $w = $row['W'] !== null ? (float) $row['W'] : 0.0;

    $type = 'voile';
    $label = t('weather.label.voile');
    $detail = t('weather.detail.voile');

    if ($rr > 0.05 && $t !== null && $t <= 1.5) {
        $type = 'snow';
        $label = t('weather.label.snow');
        $detail = t('weather.detail.snow');
    } elseif ($rr > 0.1) {
        $type = 'rain';
        $label = t('weather.label.rain');
        $detail = t('weather.detail.rain');
    } elseif ($w >= 40) {
        $type = 'wind';
        $label = t('weather.label.wind');
        $detail = t('weather.detail.wind');
    } elseif ($h !== null && $h >= 90) {
        $type = 'very_cloudy';
        $label = t('weather.label.very_cloudy');
        $detail = t('weather.detail.very_cloudy');
    } elseif ($h !== null && $h >= 75) {
        $type = 'cloudy';
        $label = t('weather.label.cloudy');
        $detail = t('weather.detail.cloudy');
    } elseif ($h !== null && $h < 55) {
        $type = 'sunny';
        $label = t('weather.label.sunny');
        $detail = t('weather.detail.sunny');
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
        'up' => t('weather.trend.up'),
        'down' => t('weather.trend.down'),
        'stable' => t('weather.trend.stable'),
        default => t('weather.trend.unknown'),
    };
}

function weather_icon_svg(string $type): string
{
    return match ($type) {
        'sunny' => '<svg viewBox="0 0 160 130" aria-hidden="true"><defs><radialGradient id="sunCore" cx="50%" cy="50%"><stop offset="0%" stop-color="#fff4b2"/><stop offset="60%" stop-color="#ffd355"/><stop offset="100%" stop-color="#f6a500"/></radialGradient><filter id="glow"><feGaussianBlur stdDeviation="3"/></filter></defs><circle cx="80" cy="62" r="44" fill="#ffd66a" opacity=".25" filter="url(#glow)"/><circle cx="80" cy="62" r="30" fill="url(#sunCore)"/><g stroke="#f8b400" stroke-width="6" stroke-linecap="round" opacity=".9"><line x1="80" y1="8" x2="80" y2="24"/><line x1="80" y1="100" x2="80" y2="116"/><line x1="24" y1="62" x2="40" y2="62"/><line x1="120" y1="62" x2="136" y2="62"/><line x1="41" y1="23" x2="52" y2="34"/><line x1="108" y1="90" x2="119" y2="101"/><line x1="41" y1="101" x2="52" y2="90"/><line x1="108" y1="34" x2="119" y2="23"/></g></svg>',
        'cloudy' => '<svg viewBox="0 0 170 130" aria-hidden="true"><defs><linearGradient id="cloudA" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#e8eff6"/><stop offset="100%" stop-color="#b8c8d8"/></linearGradient></defs><ellipse cx="74" cy="72" rx="34" ry="23" fill="url(#cloudA)"/><ellipse cx="106" cy="74" rx="38" ry="26" fill="#c5d3e0"/><ellipse cx="50" cy="82" rx="28" ry="19" fill="#d3dde8"/><ellipse cx="88" cy="88" rx="55" ry="18" fill="#aebfd0" opacity=".55"/></svg>',
        'very_cloudy' => '<svg viewBox="0 0 170 130" aria-hidden="true"><defs><linearGradient id="cloudB" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#c7d3df"/><stop offset="100%" stop-color="#7e93a8"/></linearGradient></defs><ellipse cx="70" cy="68" rx="38" ry="26" fill="url(#cloudB)"/><ellipse cx="109" cy="74" rx="42" ry="29" fill="#8ea4ba"/><ellipse cx="46" cy="81" rx="29" ry="20" fill="#9fb3c6"/><ellipse cx="88" cy="91" rx="60" ry="19" fill="#738ba1" opacity=".6"/></svg>',
        'rain' => '<svg viewBox="0 0 170 150" aria-hidden="true"><defs><linearGradient id="cloudR" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#c9d6e3"/><stop offset="100%" stop-color="#8197ac"/></linearGradient><linearGradient id="drop" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#8fd0ff"/><stop offset="100%" stop-color="#2f79c8"/></linearGradient></defs><ellipse cx="70" cy="62" rx="38" ry="26" fill="url(#cloudR)"/><ellipse cx="110" cy="68" rx="42" ry="28" fill="#8ca2b8"/><ellipse cx="46" cy="76" rx="29" ry="20" fill="#a6b9ca"/><path d="M48 103c5 9 5 9 0 18c-5-9-5-9 0-18z" fill="url(#drop)"/><path d="M82 106c5 10 5 10 0 20c-5-10-5-10 0-20z" fill="url(#drop)"/><path d="M116 103c5 9 5 9 0 18c-5-9-5-9 0-18z" fill="url(#drop)"/></svg>',
        'snow' => '<svg viewBox="0 0 170 150" aria-hidden="true"><defs><linearGradient id="cloudS" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#dbe7f2"/><stop offset="100%" stop-color="#95a9bd"/></linearGradient></defs><ellipse cx="70" cy="62" rx="38" ry="26" fill="url(#cloudS)"/><ellipse cx="110" cy="68" rx="42" ry="28" fill="#a3b5c7"/><ellipse cx="46" cy="76" rx="29" ry="20" fill="#b9c8d7"/><g stroke="#ecf5ff" stroke-width="3" stroke-linecap="round"><line x1="50" y1="108" x2="62" y2="120"/><line x1="62" y1="108" x2="50" y2="120"/><line x1="84" y1="110" x2="96" y2="122"/><line x1="96" y1="110" x2="84" y2="122"/><line x1="118" y1="108" x2="130" y2="120"/><line x1="130" y1="108" x2="118" y2="120"/></g></svg>',
        'wind' => '<svg viewBox="0 0 180 130" aria-hidden="true"><defs><linearGradient id="windLine" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#9eb8d0"/><stop offset="100%" stop-color="#5a82a8"/></linearGradient></defs><g fill="none" stroke="url(#windLine)" stroke-width="8" stroke-linecap="round"><path d="M16 42h104c18 0 26-24 9-34"/><path d="M16 68h134c21 0 33 20 16 34"/><path d="M16 95h92c15 0 22-13 12-22"/></g></svg>',
        'offline' => '<svg viewBox="0 0 170 130" aria-hidden="true"><defs><linearGradient id="offBg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#dfe6ee"/><stop offset="100%" stop-color="#b9c5d1"/></linearGradient></defs><ellipse cx="85" cy="66" rx="42" ry="30" fill="url(#offBg)"/><line x1="52" y1="33" x2="118" y2="99" stroke="#8ea0b2" stroke-width="9" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 170 130" aria-hidden="true"><defs><linearGradient id="defCloud" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#e5edf5"/><stop offset="100%" stop-color="#b9c9d9"/></linearGradient></defs><ellipse cx="85" cy="70" rx="44" ry="28" fill="url(#defCloud)"/></svg>',
    };
}
