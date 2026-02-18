<?php
declare(strict_types=1);

function units_supported(): array
{
    return ['si', 'imperial'];
}

function units_normalize(?string $units): string
{
    $units = strtolower((string) $units);
    return in_array($units, units_supported(), true) ? $units : 'si';
}

function units_bootstrap(): void
{
    if (isset($_GET['units'])) {
        $_SESSION['units'] = units_normalize((string) $_GET['units']);
    }
    if (empty($_SESSION['units'])) {
        $_SESSION['units'] = units_normalize((string) cfg('default_units', 'si'));
    }
}

function units_current(): string
{
    return units_normalize((string) ($_SESSION['units'] ?? cfg('default_units', 'si')));
}

function units_switch_url(string $target): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = parse_url($uri);
    $path = (string) ($parts['path'] ?? '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['units'] = units_normalize($target);
    return $path . '?' . http_build_query($query);
}

function units_symbol(string $metric): string
{
    $u = units_current();
    return match ($metric) {
        'T', 'Tmax', 'Tmin', 'D', 'A' => $u === 'imperial' ? '°F' : '°C',
        'P' => $u === 'imperial' ? 'inHg' : 'hPa',
        'W', 'G' => $u === 'imperial' ? 'mph' : 'km/h',
        'RR', 'R' => $u === 'imperial' ? 'in' : 'mm',
        'H' => '%',
        'B' => '°',
        default => '',
    };
}

function units_convert(string $metric, ?float $value): ?float
{
    if ($value === null) {
        return null;
    }
    if (units_current() !== 'imperial') {
        return $value;
    }

    return match ($metric) {
        'T', 'Tmax', 'Tmin', 'D', 'A' => ($value * 9 / 5) + 32,
        'P' => $value * 0.0295299831,
        'W', 'G' => $value * 0.621371192,
        'RR', 'R' => $value * 0.0393700787,
        default => $value,
    };
}

function units_decimals(string $metric): int
{
    return match ($metric) {
        'T', 'Tmax', 'Tmin', 'D', 'A', 'RR', 'R' => 1,
        'P', 'W', 'G', 'B', 'H' => 0,
        default => 1,
    };
}

function units_format(string $metric, mixed $value, bool $naAllowed = true): string
{
    if ($value === null || $value === '') {
        return $naAllowed ? t('common.na') : '';
    }
    $converted = units_convert($metric, (float) $value);
    return number_format((float) $converted, units_decimals($metric), '.', '');
}
