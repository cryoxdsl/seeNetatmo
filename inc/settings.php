<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function setting_get(string $name, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute([':k' => $name]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
        return $default;
    }
    return (string) $value;
}

function setting_set(string $name, string $value): void
{
    $sql = 'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
    $stmt = db()->prepare($sql);
    $stmt->execute([':k' => $name, ':v' => $value]);
}

function app_name(): string
{
    return setting_get('site_name', (string) app_setting('site_name', 'seeNetatmo')) ?? 'seeNetatmo';
}

function contact_email(): string
{
    return setting_get('contact_email', (string) app_setting('contact_email', 'contact@example.com')) ?? 'contact@example.com';
}

function alldata_table(): string
{
    return setting_get('table_name', (string) app_setting('table_name', 'alldata')) ?? 'alldata';
}
