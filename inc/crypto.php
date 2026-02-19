<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function master_key(): string
{
    $cfg = app_secrets_config();
    $key = (string) ($cfg['master_key'] ?? '');
    if ($key === '' || strlen($key) !== 64) {
        throw new RuntimeException('Master key not configured');
    }
    return hex2bin($key);
}

function encrypt_string(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = master_key();

    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, '', $nonce, $key);
        return base64_encode(json_encode([
            'v' => 1,
            'm' => 'sodium',
            'n' => base64_encode($nonce),
            'c' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES));
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    if ($cipher === false) {
        throw new RuntimeException('OpenSSL encrypt failure');
    }

    return base64_encode(json_encode([
        'v' => 1,
        'm' => 'openssl-gcm',
        'n' => base64_encode($iv),
        't' => base64_encode($tag),
        'c' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

function decrypt_string(?string $payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }

    $data = json_decode((string) base64_decode($payload, true), true);
    if (!is_array($data) || empty($data['m']) || empty($data['n']) || empty($data['c'])) {
        return null;
    }

    $key = master_key();
    $nonce = base64_decode((string) $data['n'], true);
    $cipher = base64_decode((string) $data['c'], true);
    if ($nonce === false || $cipher === false) {
        return null;
    }

    if ($data['m'] === 'sodium') {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);
        return $plain === false ? null : $plain;
    }

    if ($data['m'] === 'openssl-gcm') {
        $tag = base64_decode((string) ($data['t'] ?? ''), true);
        if ($tag === false) {
            return null;
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        return $plain === false ? null : $plain;
    }

    return null;
}

function secret_set(string $name, string $value): void
{
    $enc = encrypt_string($value);
    $stmt = db()->prepare('INSERT INTO secrets(name,secret_enc,updated_at) VALUES(:n,:s,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc), updated_at=NOW()');
    $stmt->execute([':n' => $name, ':s' => $enc]);
}

function secret_get(string $name): ?string
{
    $stmt = db()->prepare('SELECT secret_enc FROM secrets WHERE name=:n LIMIT 1');
    $stmt->execute([':n' => $name]);
    $enc = $stmt->fetchColumn();
    if ($enc === false || $enc === null) {
        return null;
    }
    return decrypt_string((string) $enc);
}
