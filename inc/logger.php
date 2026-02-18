<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_log(string $level, string $channel, string $message, array $context = []): void
{
    try {
        $sql = 'INSERT INTO app_logs (level, channel, message, context_json, created_at) VALUES (:level, :channel, :message, :ctx, NOW())';
        $stmt = db()->prepare($sql);
        $stmt->execute([
            ':level' => substr($level, 0, 20),
            ':channel' => substr($channel, 0, 50),
            ':message' => $message,
            ':ctx' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[seeNetatmo] log failure: ' . $e->getMessage());
    }
}
