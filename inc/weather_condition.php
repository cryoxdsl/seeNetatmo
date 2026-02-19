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

function weather_icon_svg(string $type, string $style = 'realistic'): string
{
    if ($style === 'outline') {
        return match ($type) {
            'sunny' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="11" fill="none" stroke="#e2a21c" stroke-width="3"/><g stroke="#e2a21c" stroke-width="3" stroke-linecap="round"><line x1="32" y1="6" x2="32" y2="14"/><line x1="32" y1="50" x2="32" y2="58"/><line x1="6" y1="32" x2="14" y2="32"/><line x1="50" y1="32" x2="58" y2="32"/><line x1="13" y1="13" x2="18" y2="18"/><line x1="46" y1="46" x2="51" y2="51"/><line x1="13" y1="51" x2="18" y2="46"/><line x1="46" y1="18" x2="51" y2="13"/></g></svg>',
            'cloudy', 'very_cloudy', 'voile' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M20 42h25a8 8 0 0 0 0-16 11 11 0 0 0-21-2 8 8 0 0 0-4 18z" fill="none" stroke="#6f859c" stroke-width="3" stroke-linejoin="round"/></svg>',
            'rain' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M20 36h25a8 8 0 0 0 0-16 11 11 0 0 0-21-2 8 8 0 0 0-4 18z" fill="none" stroke="#6f859c" stroke-width="3" stroke-linejoin="round"/><g stroke="#377fc2" stroke-width="3" stroke-linecap="round"><line x1="24" y1="44" x2="22" y2="54"/><line x1="32" y1="44" x2="30" y2="54"/><line x1="40" y1="44" x2="38" y2="54"/></g></svg>',
            'snow' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M20 36h25a8 8 0 0 0 0-16 11 11 0 0 0-21-2 8 8 0 0 0-4 18z" fill="none" stroke="#7f93a8" stroke-width="3" stroke-linejoin="round"/><g stroke="#cfe4f8" stroke-width="2.5" stroke-linecap="round"><line x1="24" y1="46" x2="30" y2="52"/><line x1="30" y1="46" x2="24" y2="52"/><line x1="36" y1="46" x2="42" y2="52"/><line x1="42" y1="46" x2="36" y2="52"/></g></svg>',
            'wind' => '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="none" stroke="#5f7f9f" stroke-width="3.5" stroke-linecap="round"><path d="M8 24h30c6 0 9-7 4-10"/><path d="M8 34h42c7 0 10 7 4 10"/><path d="M8 44h24c5 0 7-4 4-7"/></g></svg>',
            'offline' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="32" rx="16" ry="11" fill="none" stroke="#889aac" stroke-width="3"/><line x1="20" y1="20" x2="44" y2="44" stroke="#889aac" stroke-width="4" stroke-linecap="round"/></svg>',
            default => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="34" rx="14" ry="10" fill="none" stroke="#8095aa" stroke-width="3"/></svg>',
        };
    }

    if ($style === 'glyph') {
        return match ($type) {
            'sunny' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="17" fill="#f6b733"/></svg>',
            'cloudy', 'very_cloudy', 'voile' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 43h30a9 9 0 0 0 0-18 12 12 0 0 0-23-2 9 9 0 0 0-7 20z" fill="#8ca2b8"/></svg>',
            'rain' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 36h30a9 9 0 0 0 0-18 12 12 0 0 0-23-2 9 9 0 0 0-7 20z" fill="#8ca2b8"/><path d="M24 42l-3 11h4l3-11zm10 0l-3 11h4l3-11zm10 0l-3 11h4l3-11z" fill="#3f8dd3"/></svg>',
            'snow' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 36h30a9 9 0 0 0 0-18 12 12 0 0 0-23-2 9 9 0 0 0-7 20z" fill="#93a8bb"/><circle cx="25" cy="49" r="3" fill="#eaf5ff"/><circle cx="33" cy="49" r="3" fill="#eaf5ff"/><circle cx="41" cy="49" r="3" fill="#eaf5ff"/></svg>',
            'wind' => '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="#6f8eae"><rect x="8" y="21" width="38" height="5" rx="2.5"/><rect x="8" y="31" width="48" height="5" rx="2.5"/><rect x="8" y="41" width="30" height="5" rx="2.5"/></g></svg>',
            'offline' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="32" rx="16" ry="11" fill="#aab8c6"/><rect x="30.5" y="16" width="3" height="32" fill="#7f90a2" transform="rotate(-45 32 32)"/></svg>',
            default => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="34" rx="14" ry="10" fill="#9db0c3"/></svg>',
        };
    }

    if ($style === 'minimal') {
        return match ($type) {
            'sunny' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="12" fill="#f4b400"/><g stroke="#f4b400" stroke-width="3" stroke-linecap="round"><line x1="32" y1="6" x2="32" y2="16"/><line x1="32" y1="48" x2="32" y2="58"/><line x1="6" y1="32" x2="16" y2="32"/><line x1="48" y1="32" x2="58" y2="32"/><line x1="13" y1="13" x2="20" y2="20"/><line x1="44" y1="44" x2="51" y2="51"/><line x1="13" y1="51" x2="20" y2="44"/><line x1="44" y1="20" x2="51" y2="13"/></g></svg>',
            'cloudy', 'very_cloudy', 'voile' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="28" cy="36" rx="12" ry="9" fill="#b9c7d6"/><ellipse cx="40" cy="36" rx="11" ry="8" fill="#9fb2c4"/><rect x="18" y="36" width="32" height="10" rx="5" fill="#9fb2c4"/></svg>',
            'rain' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="28" cy="30" rx="12" ry="9" fill="#b9c7d6"/><ellipse cx="40" cy="30" rx="11" ry="8" fill="#9fb2c4"/><rect x="18" y="30" width="32" height="10" rx="5" fill="#9fb2c4"/><g stroke="#3b82c4" stroke-width="3" stroke-linecap="round"><line x1="24" y1="46" x2="22" y2="54"/><line x1="32" y1="46" x2="30" y2="54"/><line x1="40" y1="46" x2="38" y2="54"/></g></svg>',
            'snow' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="28" cy="30" rx="12" ry="9" fill="#c7d3de"/><ellipse cx="40" cy="30" rx="11" ry="8" fill="#afbfcd"/><rect x="18" y="30" width="32" height="10" rx="5" fill="#afbfcd"/><g stroke="#f3f8ff" stroke-width="2" stroke-linecap="round"><line x1="24" y1="48" x2="30" y2="54"/><line x1="30" y1="48" x2="24" y2="54"/><line x1="36" y1="48" x2="42" y2="54"/><line x1="42" y1="48" x2="36" y2="54"/></g></svg>',
            'wind' => '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="none" stroke="#6b89a8" stroke-width="4" stroke-linecap="round"><path d="M8 24h34c7 0 10-8 4-12"/><path d="M8 34h44c8 0 12 8 5 12"/><path d="M8 44h28c6 0 9-5 5-9"/></g></svg>',
            'offline' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="32" rx="16" ry="12" fill="#c6d0db"/><line x1="20" y1="20" x2="44" y2="44" stroke="#7f90a2" stroke-width="5" stroke-linecap="round"/></svg>',
            default => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="34" rx="14" ry="10" fill="#b7c7d6"/></svg>',
        };
    }

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
