<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';

function app_is_installed(): bool
{
    return is_file(__DIR__ . '/../config/installed.lock');
}

function app_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/../config/config.php';
    if (!is_file($file)) {
        return $config = [];
    }

    $loaded = require $file;
    if (!is_array($loaded)) {
        throw new RuntimeException('Invalid config/config.php file.');
    }

    return $config = $loaded;
}

function app_secret_config(): array
{
    static $secret;
    if ($secret !== null) {
        return $secret;
    }

    $file = __DIR__ . '/../config/secrets.php';
    if (!is_file($file)) {
        return $secret = [];
    }

    $loaded = require $file;
    if (!is_array($loaded)) {
        throw new RuntimeException('Invalid config/secrets.php file.');
    }

    return $secret = $loaded;
}

function app_setting(string $key, mixed $default = null): mixed
{
    $config = app_config();
    return $config[$key] ?? $default;
}
