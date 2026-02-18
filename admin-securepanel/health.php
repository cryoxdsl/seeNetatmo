<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/data.php';
require_once __DIR__ . '/../inc/netatmo.php';

enforce_admin_suffix_url();
auth_require_login();

$dbOk = true;
$dbErr = '';
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $dbOk = false;
    $dbErr = $e->getMessage();
}

$latest = fetch_latest_row();
$fetchErr = (int) db()->query("SELECT COUNT(*) FROM app_logs WHERE channel='cron.fetch' AND level='error' AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$dailyErr = (int) db()->query("SELECT COUNT(*) FROM app_logs WHERE channel='cron.daily' AND level='error' AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$token = netatmo_token_status();

admin_header('Health');
?>
<h2>Health</h2>
<div class="panel">
    <p>DB status: <?= $dbOk ? 'OK' : 'ERROR: ' . h($dbErr) ?></p>
    <p>Last DateTime: <?= h((string) ($latest['DateTime'] ?? 'N/A')) ?></p>
    <p>Cron fetch errors (24h): <?= $fetchErr ?></p>
    <p>Cron daily errors (24h): <?= $dailyErr ?></p>
    <p>Token status: <?= $token['has_access'] ? 'Available' : 'Missing' ?><?= $token['expired'] ? ' (expired)' : '' ?></p>
</div>
<?php
admin_footer();
