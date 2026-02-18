<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();

$level = trim((string) ($_GET['level'] ?? ''));
$channel = trim((string) ($_GET['channel'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$hours = (int) ($_GET['hours'] ?? 24);
if (!in_array($hours, [0, 6, 12, 24, 48, 72, 168, 720], true)) {
    $hours = 24;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25, 50, 100, 200], true)) {
    $perPage = 100;
}

$where = [];
$params = [];

if ($level !== '') {
    $where[] = 'level = :level';
    $params[':level'] = $level;
}
if ($channel !== '') {
    $where[] = 'channel = :channel';
    $params[':channel'] = $channel;
}
if ($q !== '') {
    $where[] = '(message LIKE :q OR context_json LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($hours > 0) {
    $since = now_paris()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
    $where[] = 'created_at >= :since';
    $params[':since'] = $since;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM app_logs {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$sql = "SELECT id,level,channel,message,context_json,created_at
        FROM app_logs
        {$whereSql}
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$levels = db()->query("SELECT DISTINCT level FROM app_logs ORDER BY level ASC")->fetchAll();
$channels = db()->query("SELECT DISTINCT channel FROM app_logs ORDER BY channel ASC")->fetchAll();

$baseParams = [
    'level' => $level,
    'channel' => $channel,
    'q' => $q,
    'hours' => $hours,
    'per_page' => $perPage,
];
function logs_query(array $params): string
{
    return http_build_query(array_filter($params, static fn($v) => $v !== '' && $v !== null));
}

admin_header(t('admin.logs'));
?>
<h2><?= h(t('logs.title')) ?></h2>
<div class="panel">
<form method="get" class="row">
  <label><?= h(t('logs.level')) ?>
    <select name="level">
      <option value=""><?= h(t('logs.all')) ?></option>
      <?php foreach ($levels as $l): $v = (string) $l['level']; ?>
      <option value="<?= h($v) ?>" <?= $level === $v ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= h(t('logs.channel')) ?>
    <select name="channel">
      <option value=""><?= h(t('logs.all')) ?></option>
      <?php foreach ($channels as $c): $v = (string) $c['channel']; ?>
      <option value="<?= h($v) ?>" <?= $channel === $v ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= h(t('logs.search')) ?>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="<?= h(t('logs.search_placeholder')) ?>">
  </label>
  <label><?= h(t('logs.window')) ?>
    <select name="hours">
      <option value="0" <?= $hours === 0 ? 'selected' : '' ?>><?= h(t('logs.all')) ?></option>
      <option value="6" <?= $hours === 6 ? 'selected' : '' ?>>6h</option>
      <option value="12" <?= $hours === 12 ? 'selected' : '' ?>>12h</option>
      <option value="24" <?= $hours === 24 ? 'selected' : '' ?>>24h</option>
      <option value="48" <?= $hours === 48 ? 'selected' : '' ?>>48h</option>
      <option value="72" <?= $hours === 72 ? 'selected' : '' ?>>72h</option>
      <option value="168" <?= $hours === 168 ? 'selected' : '' ?>>7d</option>
      <option value="720" <?= $hours === 720 ? 'selected' : '' ?>>30d</option>
    </select>
  </label>
  <label><?= h(t('logs.rows')) ?>
    <select name="per_page">
      <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
      <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
      <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
      <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
    </select>
  </label>
  <button type="submit"><?= h(t('logs.filter')) ?></button>
  <a class="btn" href="logs.php"><?= h(t('logs.reset')) ?></a>
</form>
<p><?= (int) $total ?> <?= h(t('logs.found')) ?></p>
</div>
<div class="panel table-wrap">
<table>
<thead><tr><th>ID</th><th><?= h(t('logs.level')) ?></th><th><?= h(t('logs.channel')) ?></th><th><?= h(t('logs.message')) ?></th><th><?= h(t('logs.context')) ?></th><th><?= h(t('logs.date')) ?></th></tr></thead>
<tbody><?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= h($r['level']) ?></td><td><?= h($r['channel']) ?></td><td><?= h($r['message']) ?></td><td class="code"><?= h($r['context_json']) ?></td><td><?= h($r['created_at']) ?></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6"><?= h(t('logs.no_results')) ?></td></tr><?php endif; ?>
</tbody>
</table>
</div>
<div class="panel row">
  <a class="btn" href="logs.php?<?= h(logs_query($baseParams + ['page' => max(1, $page - 1)])) ?>"><?= h(t('pagination.prev')) ?></a>
  <span><?= h(t('pagination.page')) ?> <?= (int) $page ?> / <?= (int) $pages ?></span>
  <a class="btn" href="logs.php?<?= h(logs_query($baseParams + ['page' => min($pages, $page + 1)])) ?>"><?= h(t('pagination.next')) ?></a>
</div>
<?php admin_footer();
