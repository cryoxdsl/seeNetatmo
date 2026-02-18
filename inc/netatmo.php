<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/constants.php';

function netatmo_http_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 8,
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('cURL error: ' . $error);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response from Netatmo.');
    }

    if ($status >= 400 || !empty($decoded['error'])) {
        $err = is_array($decoded['error'] ?? null) ? json_encode($decoded['error']) : (string) ($decoded['error'] ?? ('HTTP ' . $status));
        throw new RuntimeException('Netatmo API error: ' . $err);
    }

    return $decoded;
}

function netatmo_http_get(string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('cURL error: ' . $error);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response from Netatmo.');
    }

    if ($status >= 400 || !empty($decoded['error'])) {
        $err = is_array($decoded['error'] ?? null) ? json_encode($decoded['error']) : (string) ($decoded['error'] ?? ('HTTP ' . $status));
        throw new RuntimeException('Netatmo API error: ' . $err);
    }

    return $decoded;
}

function netatmo_client_id(): ?string
{
    return secret_get('netatmo_client_id');
}

function netatmo_client_secret(): ?string
{
    return secret_get('netatmo_client_secret');
}

function netatmo_save_client(string $clientId, string $clientSecret): void
{
    secret_set('netatmo_client_id', trim($clientId));
    secret_set('netatmo_client_secret', trim($clientSecret));
}

function netatmo_save_token_payload(array $token): void
{
    if (!isset($token['access_token'], $token['refresh_token'], $token['expires_in'])) {
        throw new RuntimeException('Incomplete token payload.');
    }

    secret_set('netatmo_access_token', (string) $token['access_token']);
    secret_set('netatmo_refresh_token', (string) $token['refresh_token']);
    secret_set('netatmo_token_expires_at', (string) (time() + (int) $token['expires_in'] - 60));
}

function netatmo_token_status(): array
{
    $access = secret_get('netatmo_access_token');
    $refresh = secret_get('netatmo_refresh_token');
    $exp = (int) (secret_get('netatmo_token_expires_at') ?? 0);

    return [
        'has_access' => $access !== null && $access !== '',
        'has_refresh' => $refresh !== null && $refresh !== '',
        'expires_at' => $exp,
        'expired' => $exp > 0 && $exp <= time(),
    ];
}

function netatmo_exchange_code(string $code, string $redirectUri): void
{
    $clientId = netatmo_client_id();
    $clientSecret = netatmo_client_secret();
    if (!$clientId || !$clientSecret) {
        throw new RuntimeException('Netatmo client credentials not configured.');
    }

    $data = netatmo_http_post(NETATMO_TOKEN_URL, [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'scope' => 'read_station',
    ]);

    netatmo_save_token_payload($data);
}

function netatmo_refresh_access_token(): void
{
    $clientId = netatmo_client_id();
    $clientSecret = netatmo_client_secret();
    $refresh = secret_get('netatmo_refresh_token');
    if (!$clientId || !$clientSecret || !$refresh) {
        throw new RuntimeException('Missing token refresh prerequisites.');
    }

    $data = netatmo_http_post(NETATMO_TOKEN_URL, [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);

    netatmo_save_token_payload($data);
}

function netatmo_access_token(): string
{
    $access = secret_get('netatmo_access_token');
    $exp = (int) (secret_get('netatmo_token_expires_at') ?? 0);
    if (!$access || $exp <= time()) {
        netatmo_refresh_access_token();
        $access = secret_get('netatmo_access_token');
    }
    if (!$access) {
        throw new RuntimeException('No Netatmo access token available.');
    }

    return $access;
}

function netatmo_fetch_station_data(): array
{
    $payload = netatmo_http_get(NETATMO_STATION_URL, netatmo_access_token());
    $body = $payload['body']['devices'][0] ?? null;
    if (!$body || !is_array($body)) {
        throw new RuntimeException('No station device found in API response.');
    }

    $result = [
        'T' => null,
        'H' => null,
        'RR' => null,
        'R' => null,
        'W' => null,
        'G' => null,
        'B' => null,
        'P' => null,
        'outdoor_connected' => false,
        'rain_connected' => false,
        'wind_connected' => false,
        'base_connected' => true,
    ];

    $dash = $body['dashboard_data'] ?? [];
    if (isset($dash['Pressure'])) {
        $result['P'] = round((float) $dash['Pressure'], 3);
    }

    foreach (($body['modules'] ?? []) as $module) {
        if (!is_array($module)) {
            continue;
        }

        $type = (string) ($module['type'] ?? '');
        $reachable = (bool) (($module['reachable'] ?? true) && (($module['rf_status'] ?? 0) !== 90));
        $m = $module['dashboard_data'] ?? [];

        if ($type === 'NAModule1') {
            $result['outdoor_connected'] = $reachable;
            if ($reachable) {
                $result['T'] = isset($m['Temperature']) ? round((float) $m['Temperature'], 1) : null;
                $result['H'] = isset($m['Humidity']) ? round((float) $m['Humidity'], 1) : null;
            }
        }

        if ($type === 'NAModule3' || $type === 'NARainGauge') {
            $result['rain_connected'] = $reachable;
            if ($reachable) {
                $result['RR'] = isset($m['Rain']) ? round((float) $m['Rain'], 3) : (isset($m['sum_rain_1']) ? round((float) $m['sum_rain_1'], 3) : null);
                $result['R'] = isset($m['sum_rain_24']) ? round((float) $m['sum_rain_24'], 3) : null;
            }
        }

        if ($type === 'NAModule2' || $type === 'NAWindGauge') {
            $result['wind_connected'] = $reachable;
            if ($reachable) {
                $w = isset($m['WindStrength']) ? (float) $m['WindStrength'] : null;
                $g = isset($m['GustStrength']) ? (float) $m['GustStrength'] : null;
                $result['W'] = $w !== null ? round($w, 1) : null;
                $result['G'] = $g !== null ? round($g, 1) : null;
                $result['B'] = isset($m['WindAngle']) ? round((float) $m['WindAngle'], 1) : null;
            }
        }
    }

    return $result;
}
