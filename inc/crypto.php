<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function master_key_binary(): string
{
    $secret = app_secret_config();
    $hex = (string) ($secret['master_key'] ?? '');
    if ($hex === '' || strlen($hex) !== 64) {
        throw new RuntimeException('Invalid master key.');
    }
    return hex2bin($hex);
}

function encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', master_key_binary(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }

    $mac = hash_hmac('sha256', $iv . $cipher, master_key_binary(), true);
    return base64_encode($iv . $mac . $cipher);
}

function decrypt_secret(?string $payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false || strlen($decoded) < 48) {
        return null;
    }

    $iv = substr($decoded, 0, 16);
    $mac = substr($decoded, 16, 32);
    $cipher = substr($decoded, 48);

    $expected = hash_hmac('sha256', $iv . $cipher, master_key_binary(), true);
    if (!hash_equals($expected, $mac)) {
        return null;
    }

    $plain = openssl_decrypt($cipher, 'AES-256-CBC', master_key_binary(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}

function secret_get(string $name): ?string
{
    $stmt = db()->prepare('SELECT secret_enc FROM secrets WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return decrypt_secret($row['secret_enc']);
}

function secret_set(string $name, ?string $value): void
{
    if ($value === null) {
        $stmt = db()->prepare('DELETE FROM secrets WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return;
    }

    $enc = encrypt_secret($value);
    $sql = 'INSERT INTO secrets (name, secret_enc, updated_at) VALUES (:name, :enc, NOW()) ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), updated_at = NOW()';
    $stmt = db()->prepare($sql);
    $stmt->execute([':name' => $name, ':enc' => $enc]);
}
