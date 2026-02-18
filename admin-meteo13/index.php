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

admin_header('Dashboard');
?>
<h2>Dashboard</h2>
<div class="panel">
  <p>Last DateTime: <strong><?= h($state['last'] ?? 'N/A') ?></strong></p>
  <p class="pill <?= $state['disconnected'] ? 'pill-bad' : 'pill-ok' ?>"><?= $state['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
  <p>Temperature: <?= h($row['T'] ?? 'N/A') ?> °C | Pressure: <?= h($row['P'] ?? 'N/A') ?> hPa</p>
</div>
<?php admin_footer();
