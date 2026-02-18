<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/data.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();

$dbOk=true;$dbErr='';
try{db()->query('SELECT 1');}catch(Throwable $e){$dbOk=false;$dbErr=$e->getMessage();}
$last = latest_row();
$token = netatmo_token_status();
$lastFetch = db()->query("SELECT created_at,message FROM app_logs WHERE channel='cron.fetch' ORDER BY id DESC LIMIT 1")->fetch();
$lastDaily = db()->query("SELECT created_at,message FROM app_logs WHERE channel='cron.daily' ORDER BY id DESC LIMIT 1")->fetch();
$err24h = (int)db()->query("SELECT COUNT(*) FROM app_logs WHERE level='error' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();

admin_header(t('admin.health'));
?>
<h2><?= h(t('health.title')) ?></h2>
<div class="panel">
  <p><?= h(t('health.db_status')) ?>: <?= $dbOk ? h(t('status.ok')) : h(t('status.error')) . ' ' . h($dbErr) ?></p>
  <p><?= h(t('admin.last_datetime')) ?>: <?= h($last['DateTime'] ?? t('common.na')) ?></p>
  <p><?= h(t('health.last_fetch')) ?>: <?= h($lastFetch['created_at'] ?? t('common.na')) ?> (<?= h($lastFetch['message'] ?? '') ?>)</p>
  <p><?= h(t('health.last_daily')) ?>: <?= h($lastDaily['created_at'] ?? t('common.na')) ?> (<?= h($lastDaily['message'] ?? '') ?>)</p>
  <p><?= h(t('health.token_status')) ?>: <?= $token['configured'] ? h(t('netatmo.configured')) : h(t('netatmo.missing')) ?><?= $token['expired'] ? ' (' . h(t('netatmo.expired')) . ')' : '' ?></p>
  <p><?= h(t('health.error_24h')) ?>: <?= $err24h ?></p>
</div>
<?php admin_footer();
