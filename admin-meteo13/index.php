<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/data.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();
$state = last_update_state();
$row = latest_row();

admin_header(t('admin.dashboard'));
?>
<h2><?= h(t('admin.dashboard')) ?></h2>
<div class="panel">
  <p><?= h(t('admin.last_datetime')) ?>: <strong><?= h($state['last'] ?? t('common.na')) ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? h(t('status.disconnected')) : h(t('status.connected')) ?></p>
  <p><?= h(t('admin.temperature')) ?>: <?= h($row['T'] ?? t('common.na')) ?> °C | <?= h(t('admin.pressure')) ?>: <?= h($row['P'] ?? t('common.na')) ?> hPa</p>
</div>
<?php admin_footer();
