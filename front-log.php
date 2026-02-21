<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
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

$rl = rate_limit_allow('front_log', 120, 60);
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

$sessionRate = $_SESSION['front_log_rate'] ?? ['window_start' => time(), 'count' => 0];
if (!is_array($sessionRate)) {
    $sessionRate = ['window_start' => time(), 'count' => 0];
}
$now = time();
$windowStart = (int) ($sessionRate['window_start'] ?? $now);
$count = (int) ($sessionRate['count'] ?? 0);
if (($now - $windowStart) >= 60) {
    $windowStart = $now;
    $count = 0;
}
if ($count >= 40) {
    $_SESSION['front_log_rate'] = ['window_start' => $windowStart, 'count' => $count];
    echo json_encode(['ok' => true, 'throttled' => true]);
    exit;
}
$_SESSION['front_log_rate'] = ['window_start' => $windowStart, 'count' => $count + 1];

$level = strtolower(trim((string) ($data['level'] ?? 'error')));
if (!in_array($level, ['warning', 'error'], true)) {
    $level = 'warning';
}
$message = trim((string) ($data['message'] ?? 'Client error'));
if ($message === '') {
    $message = 'Client error';
}
$message = substr($message, 0, 300);

$context = [
    'kind' => substr(trim((string) ($data['kind'] ?? 'js')), 0, 40),
    'path' => substr((string) ($data['path'] ?? ''), 0, 255),
    'url' => substr((string) ($data['url'] ?? ''), 0, 255),
    'line' => (int) ($data['line'] ?? 0),
    'col' => (int) ($data['col'] ?? 0),
    'stack' => substr((string) ($data['stack'] ?? ''), 0, 1200),
    'referrer' => substr((string) ($data['referrer'] ?? ''), 0, 255),
    'visitor_id' => substr((string) ($data['visitor_id'] ?? ''), 0, 64),
    'session_token' => substr((string) ($data['session_token'] ?? ''), 0, 32),
    'lang' => substr((string) ($data['lang'] ?? ''), 0, 16),
    'ip' => client_ip(),
    'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
];

try {
    log_event($level, 'front.client', $message, $context);
    echo json_encode(['ok' => true]);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'server_error']);
}
