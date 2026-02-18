<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}
if (admin_logged_in()) {
    redirect(APP_ADMIN_PATH . '/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    $res = auth_login_password($u, $p);
    if ($res['ok']) {
        redirect(APP_ADMIN_PATH . '/2fa.php');
    }
    $error = (string) ($res['error'] ?? 'Login failed');
}

admin_header('Login');
?>
<h2>Admin login</h2>
<?php if ($error): ?><div class="alert alert-bad"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="panel">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <label>Username<br><input name="username" required></label><br><br>
  <label>Password<br><input type="password" name="password" required minlength="12"></label><br><br>
  <button type="submit">Continue</button>
</form>
<?php admin_footer();
