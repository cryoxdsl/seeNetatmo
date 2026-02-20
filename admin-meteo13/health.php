<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/data.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();

$dbOk=true;$dbErr='';
try{db()->query('SELECT 1');}catch(Throwable $e){$dbOk=false;$dbErr=$e->getMessage();}
$last = latest_row();
$token = netatmo_token_status();
$lastFetch = db()->query("SELECT created_at,message FROM app_logs WHERE channel='cron.fetch' ORDER BY id DESC LIMIT 1")->fetch();
$lastDaily = db()->query("SELECT created_at,message FROM app_logs WHERE channel='cron.daily' ORDER BY id DESC LIMIT 1")->fetch();
$lastExternal = db()->query("SELECT created_at,message FROM app_logs WHERE channel='cron.external' ORDER BY id DESC LIMIT 1")->fetch();
$err24h = (int)db()->query("SELECT COUNT(*) FROM app_logs WHERE level='error' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$source = station_position_locked() ? t('health.position_manual') : t('health.position_auto');
$lockStatus = station_position_locked() ? t('health.position_on') : t('health.position_off');
$zip = station_zipcode();
$lat = station_latitude_setting();
$lon = station_longitude_setting();
$release = app_release_info();
$releaseSourceMap = [
    'file' => 'release_tag.txt',
    'env' => 'APP_RELEASE_TAG',
    'git' => '.git tag',
    'fallback' => 'APP_VERSION',
];
$releaseSource = $releaseSourceMap[(string) ($release['source'] ?? '')] ?? (string) ($release['source'] ?? 'unknown');

admin_header(t('admin.health'));
?>
<h2><?= h(t('health.title')) ?></h2>
<div class="panel">
  <p><?= h(t('health.db_status')) ?>: <?= $dbOk ? h(t('status.ok')) : h(t('status.error')) . ' ' . h($dbErr) ?></p>
  <p><?= h(t('admin.last_datetime')) ?>: <?= h($last['DateTime'] ?? t('common.na')) ?></p>
  <p><?= h(t('health.last_fetch')) ?>: <?= h($lastFetch['created_at'] ?? t('common.na')) ?> (<?= h($lastFetch['message'] ?? '') ?>)</p>
  <p><?= h(t('health.last_daily')) ?>: <?= h($lastDaily['created_at'] ?? t('common.na')) ?> (<?= h($lastDaily['message'] ?? '') ?>)</p>
  <p><?= h(t('health.last_external')) ?>: <?= h($lastExternal['created_at'] ?? t('common.na')) ?> (<?= h($lastExternal['message'] ?? '') ?>)</p>
  <p><?= h(t('health.token_status')) ?>: <?= $token['configured'] ? h(t('netatmo.configured')) : h(t('netatmo.missing')) ?><?= $token['expired'] ? ' (' . h(t('netatmo.expired')) . ')' : '' ?></p>
  <p><?= h(t('health.error_24h')) ?>: <?= $err24h ?></p>
  <p><?= h(t('health.release_tag')) ?>: <?= h((string) ($release['tag'] ?? t('common.na'))) ?></p>
  <p><?= h(t('health.release_source')) ?>: <?= h($releaseSource) ?></p>
  <hr>
  <p><strong><?= h(t('health.position')) ?></strong></p>
  <p><?= h(t('health.position_source')) ?>: <?= h($source) ?></p>
  <p><?= h(t('health.position_lock')) ?>: <?= h($lockStatus) ?></p>
  <p>ZIP: <?= h($zip !== '' ? $zip : t('common.na')) ?></p>
  <p>Lat: <?= h($lat !== '' ? $lat : t('common.na')) ?> | Lon: <?= h($lon !== '' ? $lon : t('common.na')) ?></p>
</div>
<?php admin_footer();
