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
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meteo13_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['DateTime','T','Tmax','Tmin','H','D','W','G','B','RR','R','P','S','A']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['DateTime'],$r['T'],$r['Tmax'],$r['Tmin'],$r['H'],$r['D'],$r['W'],$r['G'],$r['B'],$r['RR'],$r['R'],$r['P'],$r['S'],$r['A']]);
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;
$total = count($rows);
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$chunk = array_slice($rows, ($page - 1) * $perPage, $perPage);

front_header('History');
?>
<section class="panel">
  <h2>History</h2>
  <form class="row" method="get">
    <select name="period">
      <?php foreach ($allowed as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Apply</button>
    <a class="btn" href="/history.php?period=<?= h($period) ?>&export=csv">Export CSV</a>
  </form>
</section>
<section class="panel table-wrap">
<table>
<thead><tr><th>DateTime</th><th>T</th><th>Tmax</th><th>Tmin</th><th>H</th><th>D</th><th>W</th><th>G</th><th>B</th><th>RR</th><th>R</th><th>P</th><th>S</th><th>A</th></tr></thead>
<tbody>
<?php foreach ($chunk as $r): ?><tr>
<td><?= h($r['DateTime']) ?></td><td><?= h($r['T']) ?></td><td><?= h($r['Tmax']) ?></td><td><?= h($r['Tmin']) ?></td><td><?= $r['H'] === null ? 'N/A' : h(number_format((float) $r['H'], 0, '.', '')) ?></td><td><?= h($r['D']) ?></td><td><?= $r['W'] === null ? 'N/A' : h(number_format((float) $r['W'], 0, '.', '')) ?></td><td><?= $r['G'] === null ? 'N/A' : h(number_format((float) $r['G'], 0, '.', '')) ?></td><td><?= $r['B'] === null ? 'N/A' : h(number_format((float) $r['B'], 0, '.', '')) ?></td><td><?= $r['RR'] === null ? 'N/A' : h(number_format((float) $r['RR'], 1, '.', '')) ?></td><td><?= $r['R'] === null ? 'N/A' : h(number_format((float) $r['R'], 1, '.', '')) ?></td><td><?= $r['P'] === null ? 'N/A' : h(number_format((float) $r['P'], 0, '.', '')) ?></td><td><?= h($r['S']) ?></td><td><?= h($r['A']) ?></td>
</tr><?php endforeach; ?>
</tbody>
</table>
</section>
<section class="panel row">
  <a class="btn" href="/history.php?period=<?= h($period) ?>&page=<?= max(1, $page-1) ?>">Prev</a>
  <span>Page <?= $page ?>/<?= $pages ?></span>
  <a class="btn" href="/history.php?period=<?= h($period) ?>&page=<?= min($pages, $page+1) ?>">Next</a>
</section>
<?php front_footer();
