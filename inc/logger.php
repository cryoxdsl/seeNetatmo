<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function log_event(string $level, string $channel, string $message, array $context = []): void
{
    try {
        $stmt = db()->prepare('INSERT INTO app_logs(level,channel,message,context_json,created_at) VALUES(:l,:c,:m,:j,NOW())');
        $stmt->execute([
            ':l' => substr($level, 0, 20),
            ':c' => substr($channel, 0, 60),
            ':m' => $message,
            ':j' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[meteo13] logger failed: ' . $e->getMessage());
    }
}
