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

admin_header('Health');
?>
<h2>Health</h2>
<div class="panel">
  <p>DB status: <?= $dbOk ? 'OK' : 'ERROR ' . h($dbErr) ?></p>
  <p>Last DateTime: <?= h($last['DateTime'] ?? 'N/A') ?></p>
  <p>Last cron fetch: <?= h($lastFetch['created_at'] ?? 'N/A') ?> (<?= h($lastFetch['message'] ?? '') ?>)</p>
  <p>Last cron daily: <?= h($lastDaily['created_at'] ?? 'N/A') ?> (<?= h($lastDaily['message'] ?? '') ?>)</p>
  <p>Token status: <?= $token['configured'] ? 'Configured' : 'Missing' ?><?= $token['expired'] ? ' (expired)' : '' ?></p>
  <p>Error counter 24h: <?= $err24h ?></p>
</div>
<?php admin_footer();
