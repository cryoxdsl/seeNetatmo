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
    $bodyModules = $res['body']['modules'] ?? [];

    $out = [
        'T' => null, 'H' => null,
        'P' => null,
        'RR' => null, 'R' => null,
        'W' => null, 'G' => null, 'B' => null,
        'mod_outdoor' => false,
        'mod_rain' => false,
        'mod_wind' => false,
        'module_debug' => [],
    ];

    $seenOutdoor = false;
    $reachableOutdoor = false;
    $seenRain = false;
    $reachableRain = false;
    $seenWind = false;
    $reachableWind = false;

    $allModules = [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }

        if ($out['P'] === null) {
            foreach (['Pressure', 'AbsolutePressure', 'pressure'] as $pressureKey) {
                if (isset($device['dashboard_data'][$pressureKey])) {
                    $out['P'] = round((float) $device['dashboard_data'][$pressureKey], 3);
                    break;
                }
            }
        }

        foreach (($device['modules'] ?? []) as $mod) {
            $allModules[] = $mod;
        }
    }

    if (is_array($bodyModules)) {
        foreach ($bodyModules as $mod) {
            $allModules[] = $mod;
        }
    }

    foreach ($allModules as $mod) {
        if (!is_array($mod)) {
            continue;
        }
        $type = (string) ($mod['type'] ?? '');
        $moduleName = (string) ($mod['module_name'] ?? '');
        $place = (string) ($mod['place_in_house'] ?? '');
        $reachable = ($mod['reachable'] ?? true) ? true : false;
        $dash = $mod['dashboard_data'] ?? [];
        $out['module_debug'][] = [
            'type' => $type,
            'name' => $moduleName,
            'place' => $place,
            'reachable' => $reachable ? 1 : 0,
            'has_temp' => isset($dash['Temperature']) ? 1 : 0,
            'has_hum' => isset($dash['Humidity']) ? 1 : 0,
            'has_rain' => (isset($dash['sum_rain_1']) || isset($dash['sum_rain_24']) || isset($dash['Rain'])) ? 1 : 0,
            'has_wind' => (isset($dash['WindStrength']) || isset($dash['GustStrength']) || isset($dash['WindAngle'])) ? 1 : 0,
        ];

        $isRain = in_array($type, ['NAModule3', 'NARainGauge'], true)
            || isset($dash['Rain']) || isset($dash['sum_rain_1']) || isset($dash['sum_rain_24']);
        $isWind = in_array($type, ['NAModule2', 'NAWindGauge'], true)
            || isset($dash['WindStrength']) || isset($dash['GustStrength']) || isset($dash['WindAngle']);
        $nameHint = strtolower($moduleName . ' ' . $place);
        $outdoorHint = str_contains($nameHint, 'outdoor')
            || str_contains($nameHint, 'outside')
            || str_contains($nameHint, 'exterieur')
            || str_contains($nameHint, 'extérieur')
            || str_contains($nameHint, 'dehors')
            || str_contains($nameHint, 'garden')
            || str_contains($nameHint, 'jardin');

        $hasTempHum = isset($dash['Temperature']) || isset($dash['Humidity']);
        $isOutdoor = ($type === 'NAModule1')
            || ($hasTempHum && !$isRain && !$isWind && $outdoorHint);

        if ($isOutdoor) {
            $seenOutdoor = true;
            if ($reachable) {
                $reachableOutdoor = true;
                if (isset($dash['Temperature']) && $dash['Temperature'] !== null) {
                    $out['T'] = round((float) $dash['Temperature'], 1);
                }
                if (isset($dash['Humidity']) && $dash['Humidity'] !== null) {
                    $out['H'] = round((float) $dash['Humidity'], 1);
                }
            }
        }

        if ($isRain) {
            $seenRain = true;
            if ($reachable) {
                $reachableRain = true;
                $rr = $dash['sum_rain_1'] ?? $dash['Rain'] ?? null;
                $r = $dash['sum_rain_24'] ?? null;
                if ($rr !== null) {
                    $out['RR'] = round((float) $rr, 3);
                }
                if ($r !== null) {
                    $out['R'] = round((float) $r, 3);
                }
            }
        }

        if ($isWind) {
            $seenWind = true;
            if ($reachable) {
                $reachableWind = true;
                if (isset($dash['WindStrength']) && $dash['WindStrength'] !== null) {
                    $out['W'] = round((float) $dash['WindStrength'], 1);
                }
                if (isset($dash['GustStrength']) && $dash['GustStrength'] !== null) {
                    $out['G'] = round((float) $dash['GustStrength'], 1);
                }
                if (isset($dash['WindAngle']) && $dash['WindAngle'] !== null) {
                    $out['B'] = round((float) $dash['WindAngle'], 1);
                }
            }
        }
    }

    $out['mod_outdoor'] = $seenOutdoor && $reachableOutdoor;
    $out['mod_rain'] = $seenRain && $reachableRain;
    $out['mod_wind'] = $seenWind && $reachableWind;

    return $out;
}
