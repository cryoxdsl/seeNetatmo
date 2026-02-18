<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/constants.php';

function latest_row(): ?array
{
    $t = data_table();
    $row = db()->query("SELECT * FROM `{$t}` ORDER BY `DateTime` DESC LIMIT 1")->fetch();
    return $row ?: null;
}

function latest_rows(int $limit = 2): array
{
    $limit = max(1, min(50, $limit));
    $t = data_table();
    $stmt = db()->prepare("SELECT * FROM `{$t}` ORDER BY `DateTime` DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function last_update_state(): array
{
    $row = latest_row();
    if (!$row) {
        return ['last' => null, 'age' => null, 'disconnected' => true];
    }
    $last = new DateTimeImmutable($row['DateTime'], new DateTimeZone(APP_TIMEZONE));
    $age = (int) floor((time() - $last->getTimestamp()) / 60);
    return ['last' => $row['DateTime'], 'age' => $age, 'disconnected' => $age > DISCONNECT_THRESHOLD_MINUTES];
}

function period_bounds(string $period): array
{
    $now = now_paris();
    return match ($period) {
        '24h' => [$now->modify('-24 hours'), $now],
        '7d' => [$now->modify('-7 days'), $now],
        '30d' => [$now->modify('-30 days'), $now],
        'month' => [$now->modify('first day of this month')->setTime(0,0,0), $now],
        'year' => [$now->setDate((int)$now->format('Y'),1,1)->setTime(0,0,0), $now],
        '365d' => [$now->modify('-365 days'), $now],
        default => [$now->modify('-24 hours'), $now],
    };
}

function period_rows(string $period): array
{
    [$from, $to] = period_bounds($period);
    $t = data_table();
    $stmt = db()->prepare("SELECT * FROM `{$t}` WHERE `DateTime` BETWEEN :f AND :to ORDER BY `DateTime` ASC");
    $stmt->execute([':f' => $from->format('Y-m-d H:i:s'), ':to' => $to->format('Y-m-d H:i:s')]);
    return $stmt->fetchAll();
}

function rain_totals(): array
{
    $t = data_table();
    $now = now_paris();
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $nowStr = $now->format('Y-m-d H:i:s');

    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN `DateTime` BETWEEN :day_start AND :now THEN COALESCE(`RR`,0) ELSE 0 END),0) AS day_total,
            COALESCE(SUM(CASE WHEN `DateTime` BETWEEN :month_start AND :now THEN COALESCE(`RR`,0) ELSE 0 END),0) AS month_total,
            COALESCE(SUM(CASE WHEN `DateTime` BETWEEN :year_start AND :now THEN COALESCE(`RR`,0) ELSE 0 END),0) AS year_total
         FROM `{$t}`"
    );
    $stmt->execute([
        ':day_start' => $dayStart,
        ':month_start' => $monthStart,
        ':year_start' => $yearStart,
        ':now' => $nowStr,
    ]);
    $row = $stmt->fetch() ?: [];

    return [
        'day' => round((float) ($row['day_total'] ?? 0), 3),
        'month' => round((float) ($row['month_total'] ?? 0), 3),
        'year' => round((float) ($row['year_total'] ?? 0), 3),
    ];
}
