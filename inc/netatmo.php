<?php
declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/logger.php';

function curl_json(string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL failed: ' . $err);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON response');
    }
    if ($http >= 400 || isset($json['error'])) {
        $msg = is_array($json['error'] ?? null) ? json_encode($json['error']) : (string) ($json['error_description'] ?? ($json['error'] ?? ('HTTP ' . $http)));
        throw new RuntimeException('Netatmo error: ' . $msg);
    }
    return $json;
}

function netatmo_redirect_uri(): string
{
    return 'https://meteo13.fr/admin-meteo13/netatmo_callback.php';
}

function netatmo_authorize_url(string $state): string
{
    $clientId = secret_get('netatmo_client_id') ?? '';
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => netatmo_redirect_uri(),
        'scope' => 'read_station',
        'state' => $state,
        'response_type' => 'code',
    ];
    return NETATMO_OAUTH_AUTHORIZE . '?' . http_build_query($params);
}

function netatmo_store_client(string $clientId, string $clientSecret): void
{
    secret_set('netatmo_client_id', trim($clientId));
    secret_set('netatmo_client_secret', trim($clientSecret));
}

function netatmo_exchange_code(string $code): void
{
    $clientId = secret_get('netatmo_client_id') ?? '';
    $clientSecret = secret_get('netatmo_client_secret') ?? '';
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Netatmo client_id/client_secret not configured');
    }

    $payload = curl_json(NETATMO_OAUTH_TOKEN, [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => netatmo_redirect_uri(),
        'scope' => 'read_station',
    ]);

    netatmo_store_tokens($payload);
}

function netatmo_store_tokens(array $payload): void
{
    if (!isset($payload['access_token'], $payload['refresh_token'], $payload['expires_in'])) {
        throw new RuntimeException('Token payload incomplete');
    }
    secret_set('netatmo_access_token', (string) $payload['access_token']);
    secret_set('netatmo_refresh_token', (string) $payload['refresh_token']);
    secret_set('netatmo_access_expires_at', (string) (time() + (int) $payload['expires_in'] - 60));
}

function netatmo_refresh_token(): void
{
    $clientId = secret_get('netatmo_client_id') ?? '';
    $clientSecret = secret_get('netatmo_client_secret') ?? '';
    $refresh = secret_get('netatmo_refresh_token') ?? '';
    if ($clientId === '' || $clientSecret === '' || $refresh === '') {
        throw new RuntimeException('Cannot refresh token (missing credentials/token)');
    }

    $payload = curl_json(NETATMO_OAUTH_TOKEN, [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);

    netatmo_store_tokens($payload);
}

function netatmo_access_token(): string
{
    $token = secret_get('netatmo_access_token') ?? '';
    $exp = (int) (secret_get('netatmo_access_expires_at') ?? 0);
    if ($token === '' || time() >= $exp) {
        netatmo_refresh_token();
        $token = secret_get('netatmo_access_token') ?? '';
    }
    if ($token === '') {
        throw new RuntimeException('No Netatmo access token');
    }
    return $token;
}

function netatmo_token_status(): array
{
    $exp = (int) (secret_get('netatmo_access_expires_at') ?? 0);
    return [
        'configured' => (secret_get('netatmo_refresh_token') ?? '') !== '',
        'expires_at' => $exp,
        'expired' => $exp > 0 && time() >= $exp,
    ];
}

function netatmo_fetch_weather(): array
{
    $res = curl_json(NETATMO_API_STATIONS, null, ['Authorization: Bearer ' . netatmo_access_token()]);
    $devices = $res['body']['devices'] ?? [];
    if (!is_array($devices) || !$devices) {
        throw new RuntimeException('No device in Netatmo response');
    }

    $out = [
        'T' => null, 'H' => null,
        'P' => null,
        'RR' => null, 'R' => null,
        'W' => null, 'G' => null, 'B' => null,
        'mod_outdoor' => false,
        'mod_rain' => false,
        'mod_wind' => false,
    ];

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }

        if ($out['P'] === null && isset($device['dashboard_data']['Pressure'])) {
            $out['P'] = round((float) $device['dashboard_data']['Pressure'], 3);
        }

        foreach (($device['modules'] ?? []) as $mod) {
            if (!is_array($mod)) {
                continue;
            }
            $type = (string) ($mod['type'] ?? '');
            $reachable = ($mod['reachable'] ?? true) ? true : false;
            $dash = $mod['dashboard_data'] ?? [];

            if ($type === 'NAModule1') {
                $out['mod_outdoor'] = $reachable;
                if ($reachable) {
                    $out['T'] = isset($dash['Temperature']) ? round((float) $dash['Temperature'], 1) : null;
                    $out['H'] = isset($dash['Humidity']) ? round((float) $dash['Humidity'], 1) : null;
                }
            }

            if ($type === 'NAModule3' || $type === 'NARainGauge') {
                $out['mod_rain'] = $reachable;
                if ($reachable) {
                    $rr = $dash['sum_rain_1'] ?? $dash['Rain'] ?? null;
                    $r = $dash['sum_rain_24'] ?? null;
                    $out['RR'] = $rr !== null ? round((float) $rr, 3) : null;
                    $out['R'] = $r !== null ? round((float) $r, 3) : null;
                }
            }

            if ($type === 'NAModule2' || $type === 'NAWindGauge') {
                $out['mod_wind'] = $reachable;
                if ($reachable) {
                    $out['W'] = isset($dash['WindStrength']) ? round((float) $dash['WindStrength'], 1) : null;
                    $out['G'] = isset($dash['GustStrength']) ? round((float) $dash['GustStrength'], 1) : null;
                    $out['B'] = isset($dash['WindAngle']) ? round((float) $dash['WindAngle'], 1) : null;
                }
            }
        }
    }

    return $out;
}
