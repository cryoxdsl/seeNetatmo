<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/view.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}

$mode = (string) ($_GET['mode'] ?? 'monthly');
if (!in_array($mode, ['monthly', 'yearly'], true)) {
    $mode = 'monthly';
}

$years = climat_available_years();
$defaultYear = $years[0] ?? (int) now_paris()->format('Y');
$year = (int) ($_GET['year'] ?? $defaultYear);
if (!in_array($year, $years, true)) {
    $year = $defaultYear;
}

$rows = $mode === 'yearly' ? climat_yearly_stats() : climat_monthly_stats($year);

$range = static function (string $metric, mixed $min, mixed $max): string {
    $left = units_format($metric, $min);
    $right = units_format($metric, $max);
    if ($left === t('common.na') && $right === t('common.na')) {
        return t('common.na');
    }
    return $left . ' / ' . $right;
};

front_header(t('climate.title'));
?>
<section class="panel">
  <h2><?= h(t('climate.title')) ?></h2>
  <form class="row" method="get">
    <label><?= h(t('climate.mode')) ?>
      <select name="mode">
        <option value="monthly" <?= $mode === 'monthly' ? 'selected' : '' ?>><?= h(t('climate.monthly')) ?></option>
        <option value="yearly" <?= $mode === 'yearly' ? 'selected' : '' ?>><?= h(t('climate.yearly')) ?></option>
      </select>
    </label>
    <?php if ($mode === 'monthly'): ?>
    <label><?= h(t('climate.year')) ?>
      <select name="year">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button type="submit"><?= h(t('btn.apply')) ?></button>
  </form>
</section>

<section class="panel table-wrap">
<table>
<thead>
<tr>
  <th><?= h(t('climate.period')) ?></th>
  <th><?= h(t('climate.temp_range') . ' (' . units_symbol('T') . ')') ?></th>
  <th><?= h(t('climate.pressure_range') . ' (' . units_symbol('P') . ')') ?></th>
  <th><?= h(t('climate.rain_total') . ' (' . units_symbol('R') . ')') ?></th>
  <th><?= h(t('climate.rain_1h_max') . ' (' . units_symbol('RR') . ')') ?></th>
  <th><?= h(t('climate.wind_max') . ' (' . units_symbol('W') . ')') ?></th>
  <th><?= h(t('climate.gust_max') . ' (' . units_symbol('G') . ')') ?></th>
  <th><?= h(t('climate.dew_range') . ' (' . units_symbol('D') . ')') ?></th>
  <th><?= h(t('climate.apparent_range') . ' (' . units_symbol('A') . ')') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= h((string) $r['p']) ?></td>
  <td><?= h($range('T', $r['t_min'] ?? null, $r['t_max'] ?? null)) ?></td>
  <td><?= h($range('P', $r['p_min'] ?? null, $r['p_max'] ?? null)) ?></td>
  <td><?= h(units_format('R', $r['rain_total'] ?? null)) ?></td>
  <td><?= h(units_format('RR', $r['rr_max'] ?? null)) ?></td>
  <td><?= h(units_format('W', $r['w_max'] ?? null)) ?></td>
  <td><?= h(units_format('G', $r['g_max'] ?? null)) ?></td>
  <td><?= h($range('D', $r['d_min'] ?? null, $r['d_max'] ?? null)) ?></td>
  <td><?= h($range('A', $r['a_min'] ?? null, $r['a_max'] ?? null)) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
</tbody>
</table>
</section>
<?php front_footer();

