<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/constants.php';

function data_cache_read(string $key): ?array
{
    $raw = setting_get($key, '');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : null;
}

function data_cache_write(string $key, array $payload): void
{
    setting_set($key, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

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
    return period_rows_between($from, $to);
}

function period_rows_between(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $t = data_table();
    $stmt = db()->prepare("SELECT * FROM `{$t}` WHERE `DateTime` BETWEEN :f AND :to ORDER BY `DateTime` ASC");
    $stmt->execute([':f' => $from->format('Y-m-d H:i:s'), ':to' => $to->format('Y-m-d H:i:s')]);
    return $stmt->fetchAll();
}

function rain_totals(): array
{
    $table = data_table();
    $now = now_paris();
    $today = $now->format('Y-m-d');
    $cacheKey = 'rain_totals_cache_json';
    $cacheTtl = 120;
    $cache = data_cache_read($cacheKey);
    if (
        is_array($cache)
        && (string) ($cache['table'] ?? '') === $table
        && (string) ($cache['day'] ?? '') === $today
        && (int) ($cache['computed_at'] ?? 0) > (time() - $cacheTtl)
        && isset($cache['totals']) && is_array($cache['totals'])
    ) {
        return $cache['totals'];
    }

    $t = $table;
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

    $totals = [
        'day' => round($dayTotal, 3),
        'month' => round($monthTotal, 3),
        'year' => round($yearTotal, 3),
        'rolling_year' => round($rollingTotal, 3),
    ];
    data_cache_write($cacheKey, [
        'table' => $table,
        'day' => $today,
        'computed_at' => time(),
        'totals' => $totals,
    ]);
    return $totals;
}

function rain_reference_averages(): array
{
    $table = data_table();
    $now = now_paris();
    $today = $now->format('Y-m-d');
    $cacheKey = 'rain_refs_cache_json';
    $cache = data_cache_read($cacheKey);
    if (
        is_array($cache)
        && (string) ($cache['table'] ?? '') === $table
        && (string) ($cache['day'] ?? '') === $today
        && isset($cache['refs']) && is_array($cache['refs'])
    ) {
        return $cache['refs'];
    }

    $t = $table;
    $currentYear = (int) $now->format('Y');
    $currentMonth = (int) $now->format('n');
    $monthDay = $now->format('m-d');

    $dayStmt = db()->prepare(
        "SELECT AVG(day_total) AS avg_total, COUNT(*) AS sample_count
         FROM (
            SELECT YEAR(`DateTime`) AS y,
                   COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
            FROM `{$t}`
            WHERE DATE_FORMAT(`DateTime`, '%m-%d') = :md
              AND YEAR(`DateTime`) <> :y
            GROUP BY YEAR(`DateTime`), DATE(`DateTime`)
         ) x"
    );
    $dayStmt->execute([':md' => $monthDay, ':y' => $currentYear]);
    $dayRow = $dayStmt->fetch() ?: [];

    $monthStmt = db()->prepare(
        "SELECT AVG(month_total) AS avg_total, COUNT(*) AS sample_count
         FROM (
            SELECT y, COALESCE(SUM(day_total),0) AS month_total
            FROM (
                SELECT YEAR(`DateTime`) AS y,
                       DATE(`DateTime`) AS d,
                       COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
                FROM `{$t}`
                WHERE MONTH(`DateTime`) = :m
                  AND YEAR(`DateTime`) <> :y
                GROUP BY YEAR(`DateTime`), DATE(`DateTime`)
            ) z
            GROUP BY y
         ) x"
    );
    $monthStmt->execute([':m' => $currentMonth, ':y' => $currentYear]);
    $monthRow = $monthStmt->fetch() ?: [];

    $yearStmt = db()->prepare(
        "SELECT AVG(year_total) AS avg_total, COUNT(*) AS sample_count
         FROM (
            SELECT y, COALESCE(SUM(day_total),0) AS year_total
            FROM (
                SELECT YEAR(`DateTime`) AS y,
                       DATE(`DateTime`) AS d,
                       COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
                FROM `{$t}`
                WHERE YEAR(`DateTime`) <> :y
                  AND DATE_FORMAT(`DateTime`, '%m-%d') <= :md
                GROUP BY YEAR(`DateTime`), DATE(`DateTime`)
            ) z
            GROUP BY y
         ) x"
    );
    $yearStmt->execute([':y' => $currentYear, ':md' => $monthDay]);
    $yearRow = $yearStmt->fetch() ?: [];

    $dailyStmt = db()->query(
        "SELECT DATE(`DateTime`) AS d,
                COALESCE(MAX(`R`), SUM(COALESCE(`RR`,0)), 0) AS day_total
         FROM `{$t}`
         GROUP BY DATE(`DateTime`)
         ORDER BY d ASC"
    );
    $dailyRows = $dailyStmt ? $dailyStmt->fetchAll() : [];
    $dailyByDate = [];
    foreach ($dailyRows as $r) {
        $d = (string) ($r['d'] ?? '');
        if ($d === '') {
            continue;
        }
        $dailyByDate[$d] = (float) ($r['day_total'] ?? 0.0);
    }
    $rollingTotals = [];
    for ($y = (int) $currentYear - 1; $y >= 1900; $y--) {
        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $y . '-' . $monthDay, new DateTimeZone(APP_TIMEZONE));
        if (!$candidate) {
            if ($monthDay === '02-29') {
                $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $y . '-02-28', new DateTimeZone(APP_TIMEZONE));
            }
            if (!$candidate) {
                continue;
            }
        }
        $end = $candidate;
        $start = $end->modify('-364 days');
        $startKey = $start->format('Y-m-d');
        $endKey = $end->format('Y-m-d');

        $sum = 0.0;
        $daysWithData = 0;
        foreach ($dailyByDate as $d => $dayTotal) {
            if ($d < $startKey || $d > $endKey) {
                continue;
            }
            $sum += (float) $dayTotal;
            $daysWithData++;
        }
        if ($daysWithData < 300) {
            continue;
        }
        $rollingTotals[] = $sum;
    }

    $dayAvg = isset($dayRow['avg_total']) && $dayRow['avg_total'] !== null ? round((float) $dayRow['avg_total'], 3) : null;
    $monthAvg = isset($monthRow['avg_total']) && $monthRow['avg_total'] !== null ? round((float) $monthRow['avg_total'], 3) : null;
    $yearAvg = isset($yearRow['avg_total']) && $yearRow['avg_total'] !== null ? round((float) $yearRow['avg_total'], 3) : null;
    $rollingAvg = null;
    if ($rollingTotals) {
        $rollingAvg = round(array_sum($rollingTotals) / count($rollingTotals), 3);
    }

    $refs = [
        'day_avg' => $dayAvg,
        'month_avg' => $monthAvg,
        'year_to_date_avg' => $yearAvg,
        'rolling_365_avg' => $rollingAvg,
        'day_samples' => (int) ($dayRow['sample_count'] ?? 0),
        'month_samples' => (int) ($monthRow['sample_count'] ?? 0),
        'year_samples' => (int) ($yearRow['sample_count'] ?? 0),
        'rolling_samples' => count($rollingTotals),
    ];
    data_cache_write($cacheKey, [
        'table' => $table,
        'day' => $today,
        'computed_at' => time(),
        'refs' => $refs,
    ]);
    return $refs;
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
    $now = now_paris();
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $dayEnd = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT MIN(`T`) AS t_min, MAX(`T`) AS t_max
         FROM `{$t}`
         WHERE `DateTime` >= :day_start
           AND `DateTime` < :day_end"
    );
    $stmt->execute([':day_start' => $dayStart, ':day_end' => $dayEnd]);
    $row = $stmt->fetch();
    return [
        'min' => isset($row['t_min']) && $row['t_min'] !== null ? (float) $row['t_min'] : null,
        'max' => isset($row['t_max']) && $row['t_max'] !== null ? (float) $row['t_max'] : null,
    ];
}

function current_day_temp_extreme_times(): array
{
    $t = data_table();
    $now = now_paris();
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $dayEnd = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT
            (SELECT `DateTime`
             FROM `{$t}`
             WHERE `DateTime` >= :day_start_min
               AND `DateTime` < :day_end_min
               AND `T` IS NOT NULL
             ORDER BY `T` ASC, `DateTime` ASC
             LIMIT 1) AS t_min_time,
            (SELECT `DateTime`
             FROM `{$t}`
             WHERE `DateTime` >= :day_start_max
               AND `DateTime` < :day_end_max
               AND `T` IS NOT NULL
             ORDER BY `T` DESC, `DateTime` ASC
             LIMIT 1) AS t_max_time"
    );
    $stmt->execute([
        ':day_start_min' => $dayStart,
        ':day_end_min' => $dayEnd,
        ':day_start_max' => $dayStart,
        ':day_end_max' => $dayEnd,
    ]);
    $row = $stmt->fetch() ?: [];
    return [
        'min_time' => isset($row['t_min_time']) && $row['t_min_time'] !== null ? (string) $row['t_min_time'] : null,
        'max_time' => isset($row['t_max_time']) && $row['t_max_time'] !== null ? (string) $row['t_max_time'] : null,
    ];
}

function current_day_wind_avg_range(): array
{
    $t = data_table();
    $now = now_paris();
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $dayEnd = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT MIN(`W`) AS w_min, MAX(`W`) AS w_max
         FROM `{$t}`
         WHERE `DateTime` >= :day_start
           AND `DateTime` < :day_end"
    );
    $stmt->execute([':day_start' => $dayStart, ':day_end' => $dayEnd]);
    $row = $stmt->fetch();
    return [
        'min' => isset($row['w_min']) && $row['w_min'] !== null ? (float) $row['w_min'] : null,
        'max' => isset($row['w_max']) && $row['w_max'] !== null ? (float) $row['w_max'] : null,
    ];
}

function current_day_rain_episode(): array
{
    $t = data_table();
    $now = now_paris();
    $today = $now->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $from = $yesterday . ' 00:00:00';
    $to = $today . ' 23:59:59';
    $stmt = db()->prepare(
        "SELECT `DateTime`, `RR`, `R`
         FROM `{$t}`
         WHERE `DateTime` BETWEEN :from AND :to
         ORDER BY `DateTime` ASC"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return ['start' => null, 'end' => null, 'ongoing' => false, 'start_is_yesterday' => false];
    }

    $episodes = [];
    $activeStart = null;
    $activeEnd = null;
    $previousR = null;
    $lastRaining = false;

    foreach ($rows as $row) {
        $dtRaw = (string) ($row['DateTime'] ?? '');
        if ($dtRaw === '') {
            continue;
        }
        $rr = isset($row['RR']) && $row['RR'] !== null ? (float) $row['RR'] : 0.0;
        $r = isset($row['R']) && $row['R'] !== null ? (float) $row['R'] : null;
        $rainByCumulative = $r !== null && $previousR !== null && ($r - $previousR) > 0.001;
        $isRaining = $rr > 0.0 || $rainByCumulative;

        if ($isRaining) {
            if ($activeStart === null) {
                $activeStart = $dtRaw;
            }
            $activeEnd = $dtRaw;
        } elseif ($activeStart !== null && $activeEnd !== null) {
            $episodes[] = ['start' => $activeStart, 'end' => $activeEnd, 'ongoing' => false];
            $activeStart = null;
            $activeEnd = null;
        }

        $lastRaining = $isRaining;
        if ($r !== null) {
            $previousR = $r;
        }
    }

    if ($activeStart !== null && $activeEnd !== null) {
        $episodes[] = ['start' => $activeStart, 'end' => $activeEnd, 'ongoing' => $lastRaining];
    }

    if (!$episodes) {
        return ['start' => null, 'end' => null, 'ongoing' => false, 'start_is_yesterday' => false];
    }

    $relevant = null;
    foreach ($episodes as $ep) {
        $startDate = substr((string) ($ep['start'] ?? ''), 0, 10);
        $endDate = substr((string) ($ep['end'] ?? ''), 0, 10);
        if ($startDate === $today || $endDate === $today) {
            $relevant = $ep;
        }
    }
    if ($relevant === null) {
        return ['start' => null, 'end' => null, 'ongoing' => false, 'start_is_yesterday' => false];
    }

    $startDate = substr((string) ($relevant['start'] ?? ''), 0, 10);
    $relevant['start_is_yesterday'] = ($startDate === $yesterday);
    return $relevant;
}

function pressure_trend_snapshot(int $windowMinutes = 90, float $threshold = 0.5): array
{
    $windowMinutes = max(30, min(360, $windowMinutes));
    $threshold = max(0.1, $threshold);
    $t = data_table();
    $now = now_paris();
    $from = $now->modify('-' . $windowMinutes . ' minutes')->format('Y-m-d H:i:s');
    $to = $now->format('Y-m-d H:i:s');

    $latestStmt = db()->prepare(
        "SELECT `P` AS p_val, `DateTime` AS dt
         FROM `{$t}`
         WHERE `DateTime` BETWEEN :f AND :to AND `P` IS NOT NULL
         ORDER BY `DateTime` DESC
         LIMIT 1"
    );
    $latestStmt->execute([':f' => $from, ':to' => $to]);
    $latest = $latestStmt->fetch();

    $oldestStmt = db()->prepare(
        "SELECT `P` AS p_val, `DateTime` AS dt
         FROM `{$t}`
         WHERE `DateTime` BETWEEN :f AND :to AND `P` IS NOT NULL
         ORDER BY `DateTime` ASC
         LIMIT 1"
    );
    $oldestStmt->execute([':f' => $from, ':to' => $to]);
    $oldest = $oldestStmt->fetch();

    if (!$latest || !$oldest || !isset($latest['p_val'], $oldest['p_val'])) {
        return ['trend' => 'unknown', 'delta' => null];
    }

    $delta = (float) $latest['p_val'] - (float) $oldest['p_val'];
    $trend = 'stable';
    if ($delta >= $threshold) {
        $trend = 'up';
    } elseif ($delta <= -$threshold) {
        $trend = 'down';
    }
    return ['trend' => $trend, 'delta' => round($delta, 2)];
}
