<?php
declare(strict_types=1);

function installed_lock_path(): string
{
    return __DIR__ . '/../config/installed.lock';
}

function app_is_installed(): bool
{
    return is_file(installed_lock_path());
}

function app_config(): array
{
    static $cfg;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = __DIR__ . '/../config/config.php';
    if (!is_file($file)) {
        return $cfg = [];
    }
    $data = require $file;
    if (!is_array($data)) {
        throw new RuntimeException('Invalid config/config.php');
    }
    return $cfg = $data;
}

function app_secrets_config(): array
{
    static $cfg;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = __DIR__ . '/../config/secrets.php';
    if (!is_file($file)) {
        return $cfg = [];
    }
    $data = require $file;
    if (!is_array($data)) {
        throw new RuntimeException('Invalid config/secrets.php');
    }
    return $cfg = $data;
}

function cfg(string $key, mixed $default = null): mixed
{
    $c = app_config();
    return $c[$key] ?? $default;
}
