<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/netatmo.php';

enforce_admin_suffix_url();
auth_require_login();

$msg = '';
$error = '';
$redirectUri = base_url() . '/netatmo_callback.php';

if (isset($_GET['ok'])) {
    $msg = 'Netatmo connection updated.';
}
if (isset($_GET['error'])) {
    $error = (string) $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    $clientSecret = trim((string) ($_POST['client_secret'] ?? ''));
    if ($clientId !== '' && $clientSecret !== '') {
        netatmo_save_client($clientId, $clientSecret);
        $msg = 'Client credentials saved.';
    } else {
        $error = 'Client ID and Client Secret are required.';
    }
}

$clientIdCurrent = netatmo_client_id() ?? '';
$status = netatmo_token_status();
$authorizeUrl = NETATMO_AUTH_URL . '?' . http_build_query([
    'client_id' => $clientIdCurrent,
    'redirect_uri' => $redirectUri,
    'scope' => 'read_station',
    'response_type' => 'code',
    'state' => csrf_token(),
]);

admin_header('Netatmo');
?>
<h2>Netatmo</h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<div class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <label>Client ID<br><input name="client_id" value="<?= h($clientIdCurrent) ?>" required></label><br><br>
        <label>Client Secret<br><input type="password" name="client_secret" value="" required></label><br><br>
        <button type="submit">Save credentials</button>
    </form>
</div>
<div class="panel">
    <p>Token status: <?= $status['has_access'] ? 'Configured' : 'Missing' ?><?= $status['expired'] ? ' (expired)' : '' ?></p>
    <?php if ($clientIdCurrent !== ''): ?>
        <a class="button" href="<?= h($authorizeUrl) ?>">Connect / Reconnect Netatmo</a>
    <?php else: ?>
        <p>Save client credentials first.</p>
    <?php endif; ?>
    <p>Redirect URI: <code><?= h($redirectUri) ?></code></p>
</div>
<?php
admin_footer();
