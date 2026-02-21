<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';
require_once __DIR__ . '/../inc/analytics.php';

admin_require_login();
analytics_ensure_tables();

$hours = (int) ($_GET['hours'] ?? 24);
if (!in_array($hours, [24, 48, 72, 168, 720], true)) {
    $hours = 24;
}
$since = now_paris()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');

$summaryStmt = db()->prepare(
    'SELECT
        COUNT(*) AS sessions_count,
        COUNT(DISTINCT ip) AS unique_ips,
        SUM(page_count) AS total_pages,
        AVG(duration_seconds) AS avg_duration,
        SUM(duration_seconds) AS total_duration
     FROM app_visit_sessions
     WHERE started_at >= :since'
);
$summaryStmt->execute([':since' => $since]);
$summary = $summaryStmt->fetch() ?: [];

$topPagesStmt = db()->prepare(
    'SELECT path, COUNT(*) AS views, SUM(time_spent_seconds) AS spent
     FROM app_visit_pageviews
     WHERE viewed_at >= :since
     GROUP BY path
     ORDER BY views DESC
     LIMIT 20'
);
$topPagesStmt->execute([':since' => $since]);
$topPages = $topPagesStmt->fetchAll();

$topGeoStmt = db()->prepare(
    'SELECT
        COALESCE(NULLIF(country, \'\'), \'N/A\') AS country_label,
        COUNT(*) AS sessions_count,
        COUNT(DISTINCT ip) AS ips_count
     FROM app_visit_sessions
     WHERE started_at >= :since
     GROUP BY country_label
     ORDER BY sessions_count DESC
     LIMIT 15'
);
$topGeoStmt->execute([':since' => $since]);
$topGeo = $topGeoStmt->fetchAll();

$recentStmt = db()->prepare(
    'SELECT started_at,last_seen_at,ip,country,region,city,entry_path,exit_path,page_count,duration_seconds
     FROM app_visit_sessions
     WHERE started_at >= :since
     ORDER BY id DESC
     LIMIT 50'
);
$recentStmt->execute([':since' => $since]);
$recentSessions = $recentStmt->fetchAll();

$sessionsCount = (int) ($summary['sessions_count'] ?? 0);
$uniqueIps = (int) ($summary['unique_ips'] ?? 0);
$totalPages = (int) ($summary['total_pages'] ?? 0);
$avgDuration = isset($summary['avg_duration']) ? (int) round((float) $summary['avg_duration']) : 0;
$totalDuration = isset($summary['total_duration']) ? (int) round((float) $summary['total_duration']) : 0;
$avgPagesPerVisitor = $uniqueIps > 0 ? round($totalPages / $uniqueIps, 2) : 0.0;

admin_header(t('stats.title'));
?>
<h2><?= h(t('stats.title')) ?></h2>
<div class="panel">
  <form method="get" class="row">
    <label><?= h(t('stats.window')) ?>
      <select name="hours">
        <option value="24" <?= $hours === 24 ? 'selected' : '' ?>>24h</option>
        <option value="48" <?= $hours === 48 ? 'selected' : '' ?>>48h</option>
        <option value="72" <?= $hours === 72 ? 'selected' : '' ?>>72h</option>
        <option value="168" <?= $hours === 168 ? 'selected' : '' ?>>7d</option>
        <option value="720" <?= $hours === 720 ? 'selected' : '' ?>>30d</option>
      </select>
    </label>
    <button type="submit"><?= h(t('logs.filter')) ?></button>
  </form>
</div>

<div class="cards">
  <article class="card">
    <h3><?= h(t('stats.sessions')) ?></h3>
    <div><?= (int) $sessionsCount ?></div>
  </article>
  <article class="card">
    <h3><?= h(t('stats.unique_ips')) ?></h3>
    <div><?= (int) $uniqueIps ?></div>
  </article>
  <article class="card">
    <h3><?= h(t('stats.pageviews')) ?></h3>
    <div><?= (int) $totalPages ?></div>
  </article>
  <article class="card">
    <h3><?= h(t('stats.avg_usage_visitor')) ?></h3>
    <div><?= h(number_format($avgPagesPerVisitor, 2, '.', '')) ?> <?= h(t('stats.pages_per_visitor')) ?></div>
  </article>
  <article class="card">
    <h3><?= h(t('stats.avg_duration')) ?></h3>
    <div><?= (int) $avgDuration ?> s</div>
  </article>
  <article class="card">
    <h3><?= h(t('stats.total_duration')) ?></h3>
    <div><?= (int) $totalDuration ?> s</div>
  </article>
</div>

<div class="panel table-wrap">
  <h3><?= h(t('stats.top_pages')) ?></h3>
  <table>
    <thead><tr><th><?= h(t('stats.page')) ?></th><th><?= h(t('stats.views')) ?></th><th><?= h(t('stats.time_spent')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($topPages as $r): ?>
      <tr>
        <td><?= h((string) ($r['path'] ?? '')) ?></td>
        <td><?= (int) ($r['views'] ?? 0) ?></td>
        <td><?= (int) ($r['spent'] ?? 0) ?> s</td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$topPages): ?><tr><td colspan="3"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="panel table-wrap">
  <h3><?= h(t('stats.top_geo')) ?></h3>
  <table>
    <thead><tr><th><?= h(t('stats.country')) ?></th><th><?= h(t('stats.sessions')) ?></th><th><?= h(t('stats.unique_ips')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($topGeo as $r): ?>
      <tr>
        <td><?= h((string) ($r['country_label'] ?? 'N/A')) ?></td>
        <td><?= (int) ($r['sessions_count'] ?? 0) ?></td>
        <td><?= (int) ($r['ips_count'] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$topGeo): ?><tr><td colspan="3"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="panel table-wrap">
  <h3><?= h(t('stats.recent_sessions')) ?></h3>
  <table>
    <thead>
      <tr>
        <th><?= h(t('stats.start')) ?></th>
        <th><?= h(t('stats.last_seen')) ?></th>
        <th>IP</th>
        <th><?= h(t('stats.geo')) ?></th>
        <th><?= h(t('stats.pages')) ?></th>
        <th><?= h(t('stats.time_spent')) ?></th>
        <th><?= h(t('stats.entry')) ?></th>
        <th><?= h(t('stats.exit')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recentSessions as $r): ?>
      <tr>
        <td><?= h((string) ($r['started_at'] ?? '')) ?></td>
        <td><?= h((string) ($r['last_seen_at'] ?? '')) ?></td>
        <td><?= h((string) ($r['ip'] ?? '')) ?></td>
        <td><?= h(trim(implode(', ', array_filter([
            (string) ($r['city'] ?? ''),
            (string) ($r['region'] ?? ''),
            (string) ($r['country'] ?? ''),
        ])))) ?></td>
        <td><?= (int) ($r['page_count'] ?? 0) ?></td>
        <td><?= (int) ($r['duration_seconds'] ?? 0) ?> s</td>
        <td><?= h((string) ($r['entry_path'] ?? '')) ?></td>
        <td><?= h((string) ($r['exit_path'] ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recentSessions): ?><tr><td colspan="8"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_footer();
