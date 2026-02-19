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
    $today = $now->format('Y-m-d');
    $monthStartDate = $now->modify('first day of this month')->format('Y-m-d');
    $yearStartDate = $now->setDate((int) $now->format('Y'), 1, 1)->format('Y-m-d');
    $rollingStartDate = $now->modify('-365 days')->format('Y-m-d');

    // Day total: prefer Netatmo daily cumulative (R), fallback to sum(RR).
    $dayStmt = db()->prepare(
        "SELECT
            COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
         FROM `{$t}`
         WHERE DATE(`DateTime`) = :d"
    );
    $dayStmt->execute([':d' => $today]);
    $dayTotal = (float) ($dayStmt->fetchColumn() ?: 0);

    // Month/year totals: sum daily totals (max(R) per day, fallback sum(RR) per day).
    $monthStmt = db()->prepare(
        "SELECT COALESCE(SUM(day_total),0) AS total FROM (
            SELECT DATE(`DateTime`) AS d,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            WHERE DATE(`DateTime`) BETWEEN :start AND :end
            GROUP BY DATE(`DateTime`)
        ) x"
    );
    $monthStmt->execute([':start' => $monthStartDate, ':end' => $today]);
    $monthTotal = (float) ($monthStmt->fetchColumn() ?: 0);

    $yearStmt = db()->prepare(
        "SELECT COALESCE(SUM(day_total),0) AS total FROM (
            SELECT DATE(`DateTime`) AS d,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            WHERE DATE(`DateTime`) BETWEEN :start AND :end
            GROUP BY DATE(`DateTime`)
        ) x"
    );
    $yearStmt->execute([':start' => $yearStartDate, ':end' => $today]);
    $yearTotal = (float) ($yearStmt->fetchColumn() ?: 0);

    $rollingStmt = db()->prepare(
        "SELECT COALESCE(SUM(day_total),0) AS total FROM (
            SELECT DATE(`DateTime`) AS d,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            WHERE DATE(`DateTime`) BETWEEN :start AND :end
            GROUP BY DATE(`DateTime`)
        ) x"
    );
    $rollingStmt->execute([':start' => $rollingStartDate, ':end' => $today]);
    $rollingTotal = (float) ($rollingStmt->fetchColumn() ?: 0);

    return [
        'day' => round($dayTotal, 3),
        'month' => round($monthTotal, 3),
        'year' => round($yearTotal, 3),
        'rolling_year' => round($rollingTotal, 3),
    ];
}

function climat_available_years(): array
{
    $t = data_table();
    $rows = db()->query("SELECT DISTINCT YEAR(`DateTime`) AS y FROM `{$t}` ORDER BY y DESC")->fetchAll();
    return array_values(array_map(static fn(array $r): int => (int) $r['y'], $rows));
}

function climat_monthly_stats(int $year): array
{
    $t = data_table();
    $statsStmt = db()->prepare(
        "SELECT DATE_FORMAT(`DateTime`, '%Y-%m') AS p,
                MIN(`T`) AS t_min, MAX(`T`) AS t_max,
                MIN(`P`) AS p_min, MAX(`P`) AS p_max,
                MAX(`RR`) AS rr_max,
                MAX(`W`) AS w_max,
                MAX(`G`) AS g_max,
                MIN(`D`) AS d_min, MAX(`D`) AS d_max,
                MIN(`A`) AS a_min, MAX(`A`) AS a_max
         FROM `{$t}`
         WHERE YEAR(`DateTime`) = :y
         GROUP BY DATE_FORMAT(`DateTime`, '%Y-%m')
         ORDER BY p ASC"
    );
    $statsStmt->execute([':y' => $year]);
    $stats = $statsStmt->fetchAll();

    $rainStmt = db()->prepare(
        "SELECT DATE_FORMAT(d, '%Y-%m') AS p, COALESCE(SUM(day_total),0) AS rain_total
         FROM (
            SELECT DATE(`DateTime`) AS d,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            WHERE YEAR(`DateTime`) = :y
            GROUP BY DATE(`DateTime`)
         ) x
         GROUP BY DATE_FORMAT(d, '%Y-%m')"
    );
    $rainStmt->execute([':y' => $year]);
    $rains = $rainStmt->fetchAll();
    $rainByPeriod = [];
    foreach ($rains as $r) {
        $rainByPeriod[(string) $r['p']] = (float) $r['rain_total'];
    }

    foreach ($stats as &$row) {
        $period = (string) $row['p'];
        $row['rain_total'] = $rainByPeriod[$period] ?? 0.0;
    }
    unset($row);

    return $stats;
}

function climat_yearly_stats(): array
{
    $t = data_table();
    $stats = db()->query(
        "SELECT YEAR(`DateTime`) AS p,
                MIN(`T`) AS t_min, MAX(`T`) AS t_max,
                MIN(`P`) AS p_min, MAX(`P`) AS p_max,
                MAX(`RR`) AS rr_max,
                MAX(`W`) AS w_max,
                MAX(`G`) AS g_max,
                MIN(`D`) AS d_min, MAX(`D`) AS d_max,
                MIN(`A`) AS a_min, MAX(`A`) AS a_max
         FROM `{$t}`
         GROUP BY YEAR(`DateTime`)
         ORDER BY p DESC"
    )->fetchAll();

    $rains = db()->query(
        "SELECT YEAR(d) AS p, COALESCE(SUM(day_total),0) AS rain_total
         FROM (
            SELECT DATE(`DateTime`) AS d,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            GROUP BY DATE(`DateTime`)
         ) x
         GROUP BY YEAR(d)"
    )->fetchAll();
    $rainByPeriod = [];
    foreach ($rains as $r) {
        $rainByPeriod[(string) $r['p']] = (float) $r['rain_total'];
    }

    foreach ($stats as &$row) {
        $period = (string) $row['p'];
        $row['rain_total'] = $rainByPeriod[$period] ?? 0.0;
    }
    unset($row);

    return $stats;
}

function current_day_temp_range(): array
{
    $t = data_table();
    $today = now_paris()->format('Y-m-d');
    $stmt = db()->prepare(
        "SELECT MIN(`T`) AS t_min, MAX(`T`) AS t_max
         FROM `{$t}`
         WHERE DATE(`DateTime`) = :d"
    );
    $stmt->execute([':d' => $today]);
    $row = $stmt->fetch();
    return [
        'min' => isset($row['t_min']) && $row['t_min'] !== null ? (float) $row['t_min'] : null,
        'max' => isset($row['t_max']) && $row['t_max'] !== null ? (float) $row['t_max'] : null,
    ];
}
