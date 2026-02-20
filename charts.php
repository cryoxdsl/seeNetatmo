<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$allowed = ['24h','7d','30d','month','year','365d','custom'];
$period = (string) ($_GET['period'] ?? '24h');
if (!in_array($period, $allowed, true)) {
    $period = '24h';
}

$tz = new DateTimeZone(APP_TIMEZONE);
$now = now_paris();
$defaultFrom = $now->modify('-24 hours');
$customFromInput = trim((string) ($_GET['from'] ?? ''));
$customToInput = trim((string) ($_GET['to'] ?? ''));
$hasCustomInput = ($customFromInput !== '' || $customToInput !== '');
if ($period !== 'custom' && $hasCustomInput) {
    $period = 'custom';
}

$parseCustomDate = static function (string $raw, DateTimeZone $tz): ?DateTimeImmutable {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    return null;
};

if ($period === 'custom') {
    if ($customFromInput === '') {
        $customFromInput = $defaultFrom->format('Y-m-d\TH:i');
    }
    if ($customToInput === '') {
        $customToInput = $now->format('Y-m-d\TH:i');
    }
    $fromDt = $parseCustomDate($customFromInput, $tz) ?? $defaultFrom;
    $toDt = $parseCustomDate($customToInput, $tz) ?? $now;
    if ($fromDt > $toDt) {
        [$fromDt, $toDt] = [$toDt, $fromDt];
    }
    $customFromInput = $fromDt->format('Y-m-d\TH:i');
    $customToInput = $toDt->format('Y-m-d\TH:i');
    $rows = period_rows_between($fromDt, $toDt);
} else {
    $customFromInput = '';
    $customToInput = '';
    $rows = period_rows($period);
}

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
    'B' => $metricSeries('B'),
    'chart_labels' => [
        'T' => units_metric_label('T'),
        'H' => units_metric_label('H'),
        'P' => units_metric_label('P'),
        'R' => units_metric_name('R') . ' (' . units_symbol('R') . ')',
        'W' => units_metric_name('W') . ' (' . units_symbol('W') . ')',
        'B' => units_metric_label('B'),
    ],
    'chart_ui' => [
        'density_label' => t('charts.density'),
        'density_auto' => t('charts.density.auto'),
        'density_compact' => t('charts.density.compact'),
        'density_dense' => t('charts.density.dense'),
        'time_axis' => t('table.datetime'),
    ],
];

$periodLabels = [
    '24h' => t('period.24h'),
    '7d' => t('period.7d'),
    '30d' => t('period.30d'),
    'month' => t('period.month'),
    'year' => t('period.year'),
    '365d' => t('period.365d'),
    'custom' => t('period.custom'),
];

front_header(t('charts.title'));
?>
<section class="panel">
  <h2><?= h(t('charts.title')) ?></h2>
  <p><?= h(t('dashboard.last_update')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
  <form class="row" method="get">
    <select name="period" id="periodSelect">
      <?php foreach ($allowed as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($periodLabels[$p]) ?></option>
      <?php endforeach; ?>
    </select>
    <span id="customRangeFields"<?= $period === 'custom' ? '' : ' style="display:none"' ?>>
      <label for="customFrom"><?= h(t('charts.from')) ?></label>
      <input id="customFrom" type="datetime-local" name="from" value="<?= h($customFromInput) ?>">
      <label for="customTo"><?= h(t('charts.to')) ?></label>
      <input id="customTo" type="datetime-local" name="to" value="<?= h($customToInput) ?>">
    </span>
    <button type="submit"><?= h(t('btn.apply')) ?></button>
    <button type="button" class="btn-lite" id="chartDensityToggle"><?= h(t('charts.density')) ?>: <?= h(t('charts.density.auto')) ?></button>
  </form>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['T']) ?></h3>
  <canvas class="weather-chart" id="chartT"></canvas>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['H']) ?></h3>
  <canvas class="weather-chart" id="chartH"></canvas>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['P']) ?></h3>
  <canvas class="weather-chart" id="chartP"></canvas>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['R']) ?></h3>
  <canvas class="weather-chart" id="chartR"></canvas>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['W']) ?></h3>
  <canvas class="weather-chart" id="chartW"></canvas>
</section>
<section class="panel chart-panel">
  <h3 class="chart-title"><?= h($payload['chart_labels']['B']) ?></h3>
  <canvas class="weather-chart" id="chartB"></canvas>
</section>
<script src="/assets/js/chart.min.js?v=<?= @filemtime(__DIR__ . '/assets/js/chart.min.js') ?: time() ?>"></script>
<script>window.METEO_DATA = <?= json_encode($payload, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/js/charts.js?v=<?= @filemtime(__DIR__ . '/assets/js/charts.js') ?: time() ?>"></script>
<script>
  (function () {
    var period = document.getElementById('periodSelect');
    var custom = document.getElementById('customRangeFields');
    if (!period || !custom) return;
    function syncCustomVisibility() {
      custom.style.display = period.value === 'custom' ? '' : 'none';
    }
    period.addEventListener('change', syncCustomVisibility);
    syncCustomVisibility();
  })();
</script>
<?php front_footer();
