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
$state = last_update_state();

$payload = [
    'labels' => array_map(fn($r) => $r['DateTime'], $rows),
    'T' => array_map(fn($r) => $r['T'] !== null ? (float)$r['T'] : null, $rows),
    'H' => array_map(fn($r) => $r['H'] !== null ? (float)$r['H'] : null, $rows),
    'P' => array_map(fn($r) => $r['P'] !== null ? (float)$r['P'] : null, $rows),
    'RR' => array_map(fn($r) => $r['RR'] !== null ? (float)$r['RR'] : null, $rows),
    'R' => array_map(fn($r) => $r['R'] !== null ? (float)$r['R'] : null, $rows),
    'W' => array_map(fn($r) => $r['W'] !== null ? (float)$r['W'] : null, $rows),
    'G' => array_map(fn($r) => $r['G'] !== null ? (float)$r['G'] : null, $rows),
];

front_header('Charts');
?>
<section class="panel">
  <h2>Charts</h2>
  <p>Last update: <strong><?= h($state['last'] ?? 'N/A') ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
  <form class="row" method="get">
    <select name="period">
      <?php foreach ($allowed as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Apply</button>
  </form>
</section>
<section class="panel"><canvas id="chartT"></canvas></section>
<section class="panel"><canvas id="chartH"></canvas></section>
<section class="panel"><canvas id="chartP"></canvas></section>
<section class="panel"><canvas id="chartR"></canvas></section>
<section class="panel"><canvas id="chartW"></canvas></section>
<script src="/assets/js/chart.min.js"></script>
<script>window.METEO_DATA = <?= json_encode($payload, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/js/charts.js"></script>
<?php front_footer();
