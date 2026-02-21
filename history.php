<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$allowed = ['24h','7d','30d','month','year','365d'];
$period = (string) ($_GET['period'] ?? '24h');
if (!in_array($period, $allowed, true)) {
    $period = '24h';
}
$rows = period_rows($period);

if ((string)($_GET['export'] ?? '') === 'csv') {
    $rl = rate_limit_allow('history_export_csv', 10, 3600);
    if (empty($rl['ok'])) {
        http_response_code(429);
        $retryAfter = (int) ($rl['retry_after'] ?? 3600);
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Export quota reached. Please try again later.';
        exit;
    }

    $maxRows = 25000;
    if (count($rows) > $maxRows) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Export too large for this period. Please choose a shorter period.';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meteo13_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    $generatedAt = now_paris()->format('Y-m-d H:i:s');
    fputcsv($out, ['# Source', app_name() . ' - ' . base_url_root()]);
    fputcsv($out, ['# Generated at', $generatedAt . ' (' . APP_TIMEZONE . ')']);
    fputcsv($out, ['# Domain', base_url_root()]);
    fputcsv($out, ['DateTime','T','Tmax','Tmin','H','D','W','G','B','RR','R','P','S','A']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['DateTime'],$r['T'],$r['Tmax'],$r['Tmin'],$r['H'],$r['D'],$r['W'],$r['G'],$r['B'],$r['RR'],$r['R'],$r['P'],$r['S'],$r['A']]);
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25, 50, 100, 200], true)) {
    $perPage = 25;
}
$total = count($rows);
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$chunk = array_slice($rows, ($page - 1) * $perPage, $perPage);

$baseParams = [
    'period' => $period,
    'per_page' => $perPage,
];
function history_query(array $params): string
{
    return http_build_query(array_filter($params, static fn($v) => $v !== '' && $v !== null));
}

$periodLabels = [
    '24h' => t('period.24h'),
    '7d' => t('period.7d'),
    '30d' => t('period.30d'),
    'month' => t('period.month'),
    'year' => t('period.year'),
    '365d' => t('period.365d'),
];

front_header(t('history.title'));
?>
<section class="panel">
  <h2><?= h(t('history.title')) ?></h2>
  <form class="row" method="get">
    <select name="period">
      <?php foreach ($allowed as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($periodLabels[$p]) ?></option>
      <?php endforeach; ?>
    </select>
    <label><?= h(t('logs.rows')) ?>
      <select name="per_page">
        <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
      </select>
    </label>
    <button type="submit"><?= h(t('btn.apply')) ?></button>
    <a class="btn" href="/history.php?<?= h(history_query($baseParams + ['export' => 'csv'])) ?>"><?= h(t('btn.export_csv')) ?></a>
  </form>
</section>
<section class="panel table-wrap">
<table>
<thead><tr><th><?= h(t('table.datetime')) ?></th><th><?= h(units_metric_label('T')) ?></th><th><?= h(units_metric_label('Tmax')) ?></th><th><?= h(units_metric_label('Tmin')) ?></th><th><?= h(units_metric_label('H')) ?></th><th><?= h(units_metric_label('D')) ?></th><th><?= h(units_metric_label('W')) ?></th><th><?= h(units_metric_label('G')) ?></th><th><?= h(units_metric_label('B')) ?></th><th><?= h(units_metric_label('RR')) ?></th><th><?= h(units_metric_label('R')) ?></th><th><?= h(units_metric_label('P')) ?></th><th><?= h(t('table.s')) ?></th><th><?= h(units_metric_label('A')) ?></th></tr></thead>
<tbody>
<?php foreach ($chunk as $r): ?><tr>
<td><?= h($r['DateTime']) ?></td><td><?= h(units_format('T', $r['T'])) ?></td><td><?= h(units_format('Tmax', $r['Tmax'])) ?></td><td><?= h(units_format('Tmin', $r['Tmin'])) ?></td><td><?= h(units_format('H', $r['H'])) ?></td><td><?= h(units_format('D', $r['D'])) ?></td><td><?= h(units_format('W', $r['W'])) ?></td><td><?= h(units_format('G', $r['G'])) ?></td><td><?= h(units_format('B', $r['B'])) ?></td><td><?= h(units_format('RR', $r['RR'])) ?></td><td><?= h(units_format('R', $r['R'])) ?></td><td><?= h(units_format('P', $r['P'])) ?></td><td><?= h($r['S'] ?? t('common.na')) ?></td><td><?= h(units_format('A', $r['A'])) ?></td>
</tr><?php endforeach; ?>
<?php if (!$chunk): ?><tr><td colspan="14"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
</tbody>
</table>
</section>
<section class="panel row">
  <a class="btn" href="/history.php?<?= h(history_query($baseParams + ['page' => max(1, $page - 1)])) ?>"><?= h(t('pagination.prev')) ?></a>
  <span><?= h(t('pagination.page')) ?> <?= $page ?>/<?= $pages ?></span>
  <a class="btn" href="/history.php?<?= h(history_query($baseParams + ['page' => min($pages, $page + 1)])) ?>"><?= h(t('pagination.next')) ?></a>
</section>
<?php front_footer();
