<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/constants.php';

function fetch_latest_row(): ?array
{
    $table = alldata_table();
    $stmt = db()->query("SELECT * FROM `{$table}` ORDER BY `DateTime` DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_last_rows(int $limit = 288): array
{
    $table = alldata_table();
    $stmt = db()->prepare("SELECT * FROM `{$table}` ORDER BY `DateTime` DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return array_reverse($rows);
}

function last_update_status(): array
{
    $row = fetch_latest_row();
    if (!$row) {
        return ['last_update' => null, 'disconnected' => true, 'age_minutes' => null];
    }

    $last = new DateTimeImmutable($row['DateTime'], new DateTimeZone(APP_TIMEZONE));
    $ageMinutes = (int) floor((time() - $last->getTimestamp()) / 60);

    return [
        'last_update' => $row['DateTime'],
        'disconnected' => $ageMinutes > DISCONNECTED_AFTER_MINUTES,
        'age_minutes' => $ageMinutes,
    ];
}

function parse_period_bounds(string $period): array
{
    $now = now_paris();
    return match ($period) {
        '24h' => [$now->modify('-24 hours'), $now],
        '7d' => [$now->modify('-7 days'), $now],
        '30d' => [$now->modify('-30 days'), $now],
        'month' => [$now->modify('first day of this month midnight'), $now],
        'year' => [$now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0), $now],
        '365d' => [$now->modify('-365 days'), $now],
        default => [$now->modify('-24 hours'), $now],
    };
}

function fetch_rows_period(string $period): array
{
    [$from, $to] = parse_period_bounds($period);
    $table = alldata_table();
    $stmt = db()->prepare("SELECT * FROM `{$table}` WHERE `DateTime` BETWEEN :f AND :t ORDER BY `DateTime` ASC");
    $stmt->execute([
        ':f' => $from->format('Y-m-d H:i:s'),
        ':t' => $to->format('Y-m-d H:i:s'),
    ]);
    return $stmt->fetchAll();
}
