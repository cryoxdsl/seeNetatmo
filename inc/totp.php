<?php
declare(strict_types=1);

function base32_decode_custom(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
    $bits = '';
    $output = '';

    foreach (str_split($clean) as $char) {
        $val = strpos($alphabet, $char);
        if ($val === false) {
            continue;
        }
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }

    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $output .= chr((int) bindec($byte));
        }
    }

    return $output;
}

function generate_totp_secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $secret;
}

function totp_code(string $secret, ?int $time = null, int $period = 30, int $digits = 6): string
{
    $counter = (int) floor(($time ?? time()) / $period);
    $key = base32_decode_custom($secret);
    $binaryCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $slice = substr($hash, $offset, 4);
    $value = unpack('N', $slice)[1] & 0x7FFFFFFF;
    $mod = 10 ** $digits;
    return str_pad((string) ($value % $mod), $digits, '0', STR_PAD_LEFT);
}

function verify_totp(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + ($i * 30)), $code)) {
            return true;
        }
    }

    return false;
}

function totp_uri(string $account, string $issuer, string $secret): string
{
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($account),
        rawurlencode($secret),
        rawurlencode($issuer)
    );
}
