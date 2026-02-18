<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/netatmo.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();
$msg='';$err='';
$existingClientId = secret_get('netatmo_client_id') ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    $id = trim((string)($_POST['client_id'] ?? ''));
    $sec = trim((string)($_POST['client_secret'] ?? ''));
    if ($id==='' || $sec==='') {
        $err='client_id and client_secret required';
    } else {
        netatmo_store_client($id,$sec);
        $msg='Credentials saved';
    }
}
if (isset($_GET['ok'])) $msg='OAuth connection successful';
if (isset($_GET['error'])) $err=(string)$_GET['error'];

$stateToken = random_hex(24);
secret_set('netatmo_oauth_state', $stateToken);
secret_set('netatmo_oauth_state_ts', (string) time());
$authUrl = netatmo_authorize_url($stateToken);
$status = netatmo_token_status();

admin_header('Netatmo');
?>
<h2>Netatmo OAuth</h2>
<?php if($msg):?><div class="alert alert-ok"><?=h($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-bad"><?=h($err)?></div><?php endif;?>
<form method="post" class="panel">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <label>Client ID<br><input name="client_id" value="<?= h($existingClientId) ?>" required></label><br><br>
  <label>Client Secret<br><input name="client_secret" required></label><br><br>
  <button type="submit">Save credentials</button>
</form>
<div class="panel">
  <p>Redirect URI: <span class="code">https://meteo13.fr/admin-meteo13/netatmo_callback.php</span></p>
  <p>Token: <?= $status['configured'] ? 'Configured' : 'Missing' ?><?= $status['expired'] ? ' (expired)' : '' ?></p>
  <a class="btn" href="<?=h($authUrl)?>">Connect / reconnect Netatmo</a>
</div>
<?php admin_footer();
