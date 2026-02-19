<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_paris(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function floor_5min(DateTimeImmutable $dt): DateTimeImmutable
{
    $minute = (int) $dt->format('i');
    $floored = $minute - ($minute % 5);
    return $dt->setTime((int) $dt->format('H'), $floored, 0);
}

function random_hex(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function random_base32(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
}

function base_url_root(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = cfg('trusted_proxy_ips', []);
    if (!is_array($trusted)) {
        $trusted = [];
    }
    $trusted = array_map(static fn($v): string => (string) $v, $trusted);
    $isTrustedProxy = in_array($remote, $trusted, true);

    if ($isTrustedProxy && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if ($isTrustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $remote;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
