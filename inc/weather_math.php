<?php
declare(strict_types=1);

function dew_point_magnus(?float $t, ?float $h): ?float
{
    if ($t === null || $h === null || $h <= 0 || $h > 100) {
        return null;
    }
    $a = 17.62;
    $b = 243.12;
    $gamma = ($a * $t / ($b + $t)) + log($h / 100.0);
    return round(($b * $gamma) / ($a - $gamma), 1);
}

function apparent_temp(?float $t, ?float $h, ?float $wKmh): ?float
{
    if ($t === null) {
        return null;
    }
    $e = null;
    if ($h !== null) {
        $e = ($h / 100.0) * 6.105 * exp(17.27 * $t / (237.7 + $t));
    }
    $wMs = ($wKmh ?? 0.0) / 3.6;
    if ($e === null) {
        return round($t, 1);
    }
    return round($t + (0.33 * $e) - (0.70 * $wMs) - 4.0, 1);
}
