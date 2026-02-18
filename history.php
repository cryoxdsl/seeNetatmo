<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/data.php';

if (!app_is_installed()) {
    header('Location: /install/index.php');
    exit;
}

$period = (string) ($_GET['period'] ?? '24h');
$allowed = ['24h', '7d', '30d', 'month', 'year', '365d'];
if (!in_array($period, $allowed, true)) {
    $period = '24h';
}

$rows = fetch_rows_period($period);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;
$total = count($rows);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($rows, $offset, $perPage);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="history_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['DateTime','T','Tmax','Tmin','H','D','W','G','B','RR','R','P','S','A']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['DateTime'],$r['T'],$r['Tmax'],$r['Tmin'],$r['H'],$r['D'],$r['W'],$r['G'],$r['B'],$r['RR'],$r['R'],$r['P'],$r['S'],$r['A']]);
    }
    fclose($out);
    exit;
}

render_header('History');
?>
<section class="panel">
    <h2>History</h2>
    <form method="get" class="inline-form">
        <select name="period">
            <option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>24h</option>
            <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>7 days</option>
            <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>30 days</option>
            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Current month</option>
            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Current year</option>
            <option value="365d" <?= $period === '365d' ? 'selected' : '' ?>>365 rolling days</option>
        </select>
        <button type="submit">Apply</button>
        <a class="button" href="?period=<?= h($period) ?>&export=csv">Export CSV</a>
    </form>
</section>
<section class="panel table-wrap">
    <table>
        <thead>
            <tr>
                <th>DateTime</th><th>T</th><th>Tmax</th><th>Tmin</th><th>H</th><th>D</th><th>W</th><th>G</th><th>B</th><th>RR</th><th>R</th><th>P</th><th>A</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pageRows as $r): ?>
                <tr>
                    <td><?= h($r['DateTime']) ?></td>
                    <td><?= h((string) $r['T']) ?></td>
                    <td><?= h((string) $r['Tmax']) ?></td>
                    <td><?= h((string) $r['Tmin']) ?></td>
                    <td><?= h((string) $r['H']) ?></td>
                    <td><?= h((string) $r['D']) ?></td>
                    <td><?= h((string) $r['W']) ?></td>
                    <td><?= h((string) $r['G']) ?></td>
                    <td><?= h((string) $r['B']) ?></td>
                    <td><?= h((string) $r['RR']) ?></td>
                    <td><?= h((string) $r['R']) ?></td>
                    <td><?= h((string) $r['P']) ?></td>
                    <td><?= h((string) $r['A']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="panel pagination">
    <a class="button" href="?period=<?= h($period) ?>&page=<?= max(1, $page - 1) ?>">Prev</a>
    <span>Page <?= $page ?> / <?= $totalPages ?></span>
    <a class="button" href="?period=<?= h($period) ?>&page=<?= min($totalPages, $page + 1) ?>">Next</a>
</section>
<?php
render_footer();
