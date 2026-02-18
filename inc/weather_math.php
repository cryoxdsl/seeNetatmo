<?php
declare(strict_types=1);

function dew_point(?float $temperatureC, ?float $humidity): ?float
{
    if ($temperatureC === null || $humidity === null || $humidity <= 0.0) {
        return null;
    }

    $a = 17.62;
    $b = 243.12;
    $gamma = (($a * $temperatureC) / ($b + $temperatureC)) + log($humidity / 100.0);
    $dp = ($b * $gamma) / ($a - $gamma);
    return round($dp, 1);
}

function apparent_temperature(?float $temperatureC, ?float $humidity, ?float $windKmh): ?float
{
    if ($temperatureC === null) {
        return null;
    }

    $e = null;
    if ($humidity !== null) {
        $e = ($humidity / 100.0) * 6.105 * exp((17.27 * $temperatureC) / (237.7 + $temperatureC));
    }

    $windMs = $windKmh !== null ? ($windKmh / 3.6) : 0.0;

    if ($e !== null) {
        $at = $temperatureC + (0.33 * $e) - (0.70 * $windMs) - 4.00;
        return round($at, 1);
    }

    return round($temperatureC, 1);
}
