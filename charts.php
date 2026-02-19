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

$metricSeries = static function (string $metric) use ($rows): array {
    $decimals = units_decimals($metric);
    return array_map(static function (array $r) use ($metric, $decimals): ?float {
        $raw = $r[$metric] ?? null;
        if ($raw === null) {
            return null;
        }
        $converted = units_convert($metric, (float) $raw);
        return $converted === null ? null : round($converted, $decimals);
    }, $rows);
};

$payload = [
    'labels' => array_map(fn($r) => $r['DateTime'], $rows),
    'T' => $metricSeries('T'),
    'H' => $metricSeries('H'),
    'P' => $metricSeries('P'),
    'RR' => $metricSeries('RR'),
    'R' => $metricSeries('R'),
    'W' => $metricSeries('W'),
    'G' => $metricSeries('G'),
    'chart_labels' => [
        'T' => units_metric_label('T'),
        'H' => units_metric_label('H'),
        'P' => units_metric_label('P'),
        'R' => units_metric_name('R') . ' (' . units_symbol('R') . ')',
        'W' => units_metric_name('W') . ' (' . units_symbol('W') . ')',
    ],
];

$periodLabels = [
    '24h' => t('period.24h'),
    '7d' => t('period.7d'),
    '30d' => t('period.30d'),
    'month' => t('period.month'),
    'year' => t('period.year'),
    '365d' => t('period.365d'),
];

front_header(t('charts.title'));
?>
<section class="panel">
  <h2><?= h(t('charts.title')) ?></h2>
  <p><?= h(t('dashboard.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
  <form class="row" method="get">
    <select name="period">
      <?php foreach ($allowed as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($periodLabels[$p]) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit"><?= h(t('btn.apply')) ?></button>
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
