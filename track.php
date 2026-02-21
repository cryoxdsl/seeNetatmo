<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/analytics.php';
require_once __DIR__ . '/inc/logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!app_is_installed()) {
    echo json_encode(['ok' => false, 'reason' => 'not_installed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);
    exit;
}

$rl = rate_limit_allow('track', 300, 60);
if (empty($rl['ok'])) {
    http_response_code(429);
    $retryAfter = (int) ($rl['retry_after'] ?? 60);
    header('Retry-After: ' . $retryAfter);
    echo json_encode(['ok' => false, 'reason' => 'rate_limited']);
    exit;
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $data = $json;
    }
}
if ($data === [] && !empty($_POST)) {
    $data = $_POST;
}

$action = strtolower(trim((string) ($data['action'] ?? 'start')));
$visitorId = (string) ($data['visitor_id'] ?? '');
$sessionToken = (string) ($data['session_token'] ?? '');
$path = (string) ($data['path'] ?? '/');
$title = (string) ($data['title'] ?? '');
$referrer = (string) ($data['referrer'] ?? '');
$lang = (string) ($data['lang'] ?? locale_current());
$delta = (int) ($data['delta_seconds'] ?? 0);
$ip = client_ip();
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

try {
    if ($action === 'start') {
        $res = analytics_start_or_resume_session($visitorId, $sessionToken, $path, $title, $referrer, $lang, $ua, $ip);
        echo json_encode([
            'ok' => true,
            'session_token' => (string) ($res['session_token'] ?? ''),
            'new_session' => !empty($res['new_session']),
        ]);
        exit;
    }

    if (in_array($action, ['ping', 'page_end'], true)) {
        analytics_record_activity($sessionToken, $path, $title, $delta);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'reason' => 'unknown_action']);
} catch (Throwable $e) {
    log_event('warning', 'front.analytics', 'Tracking endpoint failed', ['err' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'server_error']);
}
