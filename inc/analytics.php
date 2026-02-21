<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const ANALYTICS_SESSION_IDLE_SECONDS = 1800;

function analytics_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS app_visit_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id VARCHAR(64) NOT NULL,
            session_token CHAR(32) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            country VARCHAR(120) NULL,
            region VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            user_agent VARCHAR(255) NULL,
            lang VARCHAR(16) NULL,
            entry_path VARCHAR(255) NOT NULL,
            exit_path VARCHAR(255) NULL,
            referrer VARCHAR(255) NULL,
            page_count INT UNSIGNED NOT NULL DEFAULT 0,
            duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_visit_session_token (session_token),
            KEY idx_visit_sessions_started (started_at),
            KEY idx_visit_sessions_last_seen (last_seen_at),
            KEY idx_visit_sessions_ip (ip),
            KEY idx_visit_sessions_visitor (visitor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS app_visit_pageviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            path VARCHAR(255) NOT NULL,
            title VARCHAR(255) NULL,
            time_spent_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            viewed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pageviews_session (session_id),
            KEY idx_pageviews_viewed (viewed_at),
            KEY idx_pageviews_path (path),
            CONSTRAINT fk_pageviews_session FOREIGN KEY (session_id) REFERENCES app_visit_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS app_ip_geo_cache (
            ip VARCHAR(45) PRIMARY KEY,
            country VARCHAR(120) NULL,
            region VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            looked_up_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function analytics_sanitize_visitor_id(string $visitorId): string
{
    $v = preg_replace('/[^A-Za-z0-9_.-]/', '', $visitorId) ?? '';
    $v = substr($v, 0, 64);
    if ($v === '') {
        $v = bin2hex(random_bytes(12));
    }
    return $v;
}

function analytics_sanitize_session_token(string $token): string
{
    $v = preg_replace('/[^A-Fa-f0-9]/', '', $token) ?? '';
    $v = strtolower(substr($v, 0, 32));
    return $v;
}

function analytics_sanitize_path(string $path): string
{
    $p = trim($path);
    if ($p === '') {
        return '/';
    }
    $parsed = (string) parse_url($p, PHP_URL_PATH);
    if ($parsed === '') {
        $parsed = '/';
    }
    return substr($parsed, 0, 255);
}

function analytics_sanitize_title(string $title): string
{
    return substr(trim($title), 0, 255);
}

function analytics_is_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function analytics_geo_lookup_ip(string $ip): array
{
    if (!analytics_is_public_ip($ip)) {
        return ['country' => null, 'region' => null, 'city' => null];
    }

    $stmt = db()->prepare('SELECT country, region, city, looked_up_at FROM app_ip_geo_cache WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    if (is_array($row) && !empty($row['looked_up_at'])) {
        $ts = strtotime((string) $row['looked_up_at']);
        if ($ts !== false && $ts > (time() - 86400 * 30)) {
            return [
                'country' => (string) ($row['country'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
            ];
        }
    }

    $country = '';
    $region = '';
    $city = '';
    try {
        $url = 'https://ipwho.is/' . rawurlencode($ip);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_USERAGENT => 'meteo13-analytics/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($raw) && $raw !== '' && $http < 400) {
            $json = json_decode($raw, true);
            if (is_array($json) && !empty($json['success'])) {
                $country = substr(trim((string) ($json['country'] ?? '')), 0, 120);
                $region = substr(trim((string) ($json['region'] ?? '')), 0, 120);
                $city = substr(trim((string) ($json['city'] ?? '')), 0, 120);
            }
        }
    } catch (Throwable) {
        // Best effort only.
    }

    $upsert = db()->prepare(
        'INSERT INTO app_ip_geo_cache(ip,country,region,city,looked_up_at) VALUES(:ip,:country,:region,:city,NOW())
         ON DUPLICATE KEY UPDATE country=VALUES(country), region=VALUES(region), city=VALUES(city), looked_up_at=VALUES(looked_up_at)'
    );
    $upsert->execute([
        ':ip' => $ip,
        ':country' => $country,
        ':region' => $region,
        ':city' => $city,
    ]);

    return ['country' => $country, 'region' => $region, 'city' => $city];
}

function analytics_find_session_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM app_visit_sessions WHERE session_token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function analytics_insert_pageview(int $sessionId, string $path, string $title): void
{
    $stmt = db()->prepare(
        'INSERT INTO app_visit_pageviews(session_id,path,title,time_spent_seconds,viewed_at)
         VALUES(:sid,:path,:title,0,NOW())'
    );
    $stmt->execute([
        ':sid' => $sessionId,
        ':path' => $path,
        ':title' => $title !== '' ? $title : null,
    ]);
}

function analytics_start_or_resume_session(
    string $visitorId,
    string $sessionToken,
    string $path,
    string $title,
    string $referrer,
    string $lang,
    string $userAgent,
    string $ip
): array {
    analytics_ensure_tables();

    $visitorId = analytics_sanitize_visitor_id($visitorId);
    $sessionToken = analytics_sanitize_session_token($sessionToken);
    $path = analytics_sanitize_path($path);
    $title = analytics_sanitize_title($title);
    $referrer = substr(trim($referrer), 0, 255);
    $lang = substr(trim($lang), 0, 16);
    $userAgent = substr(trim($userAgent), 0, 255);
    $ip = substr(trim($ip), 0, 45);

    $existing = analytics_find_session_by_token($sessionToken);
    if (is_array($existing)) {
        $lastSeenTs = strtotime((string) ($existing['last_seen_at'] ?? ''));
        $active = $lastSeenTs !== false && $lastSeenTs > (time() - ANALYTICS_SESSION_IDLE_SECONDS);
        if ($active) {
            $sessionId = (int) $existing['id'];
            $upd = db()->prepare(
                'UPDATE app_visit_sessions
                 SET last_seen_at = NOW(),
                     exit_path = :path,
                     lang = :lang,
                     user_agent = :ua,
                     duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                 WHERE id = :id'
            );
            $upd->execute([
                ':id' => $sessionId,
                ':path' => $path,
                ':lang' => $lang !== '' ? $lang : null,
                ':ua' => $userAgent !== '' ? $userAgent : null,
            ]);

            $lastPvStmt = db()->prepare('SELECT id, path FROM app_visit_pageviews WHERE session_id = :sid ORDER BY id DESC LIMIT 1');
            $lastPvStmt->execute([':sid' => $sessionId]);
            $lastPv = $lastPvStmt->fetch();
            if (!is_array($lastPv) || (string) ($lastPv['path'] ?? '') !== $path) {
                analytics_insert_pageview($sessionId, $path, $title);
                db()->prepare('UPDATE app_visit_sessions SET page_count = page_count + 1 WHERE id = :id')->execute([':id' => $sessionId]);
            }
            return ['session_token' => (string) $existing['session_token'], 'session_id' => $sessionId, 'new_session' => false];
        }
    }

    $geo = analytics_geo_lookup_ip($ip);
    $token = bin2hex(random_bytes(16));
    $insert = db()->prepare(
        'INSERT INTO app_visit_sessions(
            visitor_id,session_token,ip,country,region,city,user_agent,lang,entry_path,exit_path,referrer,page_count,duration_seconds,started_at,last_seen_at
         ) VALUES(
            :visitor_id,:session_token,:ip,:country,:region,:city,:ua,:lang,:entry_path,:exit_path,:referrer,1,0,NOW(),NOW()
         )'
    );
    $insert->execute([
        ':visitor_id' => $visitorId,
        ':session_token' => $token,
        ':ip' => $ip,
        ':country' => ($geo['country'] ?? '') !== '' ? $geo['country'] : null,
        ':region' => ($geo['region'] ?? '') !== '' ? $geo['region'] : null,
        ':city' => ($geo['city'] ?? '') !== '' ? $geo['city'] : null,
        ':ua' => $userAgent !== '' ? $userAgent : null,
        ':lang' => $lang !== '' ? $lang : null,
        ':entry_path' => $path,
        ':exit_path' => $path,
        ':referrer' => $referrer !== '' ? $referrer : null,
    ]);
    $sessionId = (int) db()->lastInsertId();
    analytics_insert_pageview($sessionId, $path, $title);
    return ['session_token' => $token, 'session_id' => $sessionId, 'new_session' => true];
}

function analytics_record_activity(string $sessionToken, string $path, string $title, int $deltaSeconds): void
{
    analytics_ensure_tables();
    $token = analytics_sanitize_session_token($sessionToken);
    if ($token === '') {
        return;
    }
    $delta = max(0, min(600, $deltaSeconds));
    $path = analytics_sanitize_path($path);
    $title = analytics_sanitize_title($title);

    $session = analytics_find_session_by_token($token);
    if (!is_array($session)) {
        return;
    }
    $sessionId = (int) $session['id'];
    $lastSeenTs = strtotime((string) ($session['last_seen_at'] ?? ''));
    if ($lastSeenTs === false || $lastSeenTs < (time() - ANALYTICS_SESSION_IDLE_SECONDS * 4)) {
        return;
    }

    $lastPvStmt = db()->prepare('SELECT id, path FROM app_visit_pageviews WHERE session_id = :sid ORDER BY id DESC LIMIT 1');
    $lastPvStmt->execute([':sid' => $sessionId]);
    $lastPv = $lastPvStmt->fetch();
    if (!is_array($lastPv) || (string) ($lastPv['path'] ?? '') !== $path) {
        analytics_insert_pageview($sessionId, $path, $title);
        db()->prepare('UPDATE app_visit_sessions SET page_count = page_count + 1 WHERE id = :id')->execute([':id' => $sessionId]);
        $lastPvStmt->execute([':sid' => $sessionId]);
        $lastPv = $lastPvStmt->fetch();
    } elseif ($title !== '') {
        db()->prepare('UPDATE app_visit_pageviews SET title = :title WHERE id = :id')
            ->execute([':title' => $title, ':id' => (int) $lastPv['id']]);
    }

    if (is_array($lastPv) && $delta > 0) {
        db()->prepare('UPDATE app_visit_pageviews SET time_spent_seconds = time_spent_seconds + :d WHERE id = :id')
            ->execute([':d' => $delta, ':id' => (int) $lastPv['id']]);
    }

    $upd = db()->prepare(
        'UPDATE app_visit_sessions
         SET last_seen_at = NOW(),
             exit_path = :path,
             duration_seconds = GREATEST(duration_seconds + :d, TIMESTAMPDIFF(SECOND, started_at, NOW()))
         WHERE id = :id'
    );
    $upd->execute([
        ':id' => $sessionId,
        ':path' => $path,
        ':d' => $delta,
    ]);
}

