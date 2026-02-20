<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';

function settings_cache_all(?array $newValue = null): ?array
{
    static $cache = null;
    if ($newValue !== null) {
        $cache = $newValue;
    }
    return $cache;
}

function settings_cache_load(): array
{
    $cache = settings_cache_all();
    if (is_array($cache)) {
        return $cache;
    }
    $out = [];
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            $k = (string) ($row['setting_key'] ?? '');
            if ($k === '') {
                continue;
            }
            $out[$k] = (string) ($row['setting_value'] ?? '');
        }
    } catch (Throwable) {
        $out = [];
    }
    settings_cache_all($out);
    return $out;
}

function setting_get(string $key, ?string $default = null): ?string
{
    $cache = settings_cache_load();
    if (array_key_exists($key, $cache)) {
        return (string) $cache[$key];
    }
    return $default;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(:k,:v,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
    $stmt->execute([':k' => $key, ':v' => $value]);
    $cache = settings_cache_load();
    $cache[$key] = $value;
    settings_cache_all($cache);
}

function app_name(): string
{
    return setting_get('site_name', APP_NAME_DEFAULT) ?? APP_NAME_DEFAULT;
}

function data_table(): string
{
    return setting_get('data_table', 'alldata') ?? 'alldata';
}

function contact_email(): string
{
    return setting_get('contact_email', 'contact@meteo13.fr') ?? 'contact@meteo13.fr';
}

function browser_title_base(): string
{
    return setting_get('browser_title', app_name()) ?? app_name();
}

function favicon_url(): string
{
    return setting_get('favicon_url', '/favicon.ico') ?? '/favicon.ico';
}

function station_zipcode(): string
{
    return setting_get('station_zipcode', '') ?? '';
}

function station_latitude_setting(): string
{
    return setting_get('station_lat', '') ?? '';
}

function station_longitude_setting(): string
{
    return setting_get('station_lon', '') ?? '';
}

function station_altitude_setting(): string
{
    return setting_get('station_altitude', '') ?? '';
}

function station_position_locked(): bool
{
    return (setting_get('station_lock_position', '0') ?? '0') === '1';
}

function weather_icon_style_setting(): string
{
    $style = setting_get('weather_icon_style', 'realistic') ?? 'realistic';
    return in_array($style, ['realistic', 'minimal', 'outline', 'glyph'], true) ? $style : 'realistic';
}

function terms_of_use_content(): string
{
    return setting_get('terms_of_use_content', '') ?? '';
}
