<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/data.php';

enforce_admin_suffix_url();
auth_require_login();

$status = last_update_status();
$latest = fetch_latest_row();

admin_header('Dashboard');
?>
<h2>Admin dashboard</h2>
<div class="panel">
    <p>Last update: <strong><?= h((string) ($status['last_update'] ?? 'N/A')) ?></strong></p>
    <p class="badge <?= $status['disconnected'] ? 'badge-danger' : 'badge-ok' ?>"><?= $status['disconnected'] ? 'Disconnected' : 'Connected' ?></p>
    <p>Latest temperature: <?= h((string) ($latest['T'] ?? 'N/A')) ?> C</p>
</div>
<?php
admin_footer();
