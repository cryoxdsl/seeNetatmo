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
$status = last_update_status();

$series = [
    'labels' => array_map(fn($r) => $r['DateTime'], $rows),
    'T' => array_map(fn($r) => $r['T'] !== null ? (float) $r['T'] : null, $rows),
    'H' => array_map(fn($r) => $r['H'] !== null ? (float) $r['H'] : null, $rows),
    'P' => array_map(fn($r) => $r['P'] !== null ? (float) $r['P'] : null, $rows),
    'R' => array_map(fn($r) => $r['R'] !== null ? (float) $r['R'] : null, $rows),
    'W' => array_map(fn($r) => $r['W'] !== null ? (float) $r['W'] : null, $rows),
];

render_header('Charts');
?>
<section class="panel">
    <h2>Charts</h2>
    <p>Last update: <strong><?= h((string) ($status['last_update'] ?? 'N/A')) ?></strong></p>
    <p class="badge <?= $status['disconnected'] ? 'badge-danger' : 'badge-ok' ?>"><?= $status['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
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
    </form>
</section>
<section class="panel">
    <canvas id="weatherChart" height="120"></canvas>
</section>
<script>
window.chartPayload = <?= json_encode($series, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/charts.js"></script>
<?php
render_footer();
