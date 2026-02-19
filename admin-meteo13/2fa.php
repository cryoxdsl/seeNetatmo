<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';
require_once __DIR__ . '/../inc/logger.php';

if (!app_is_installed()) {
    redirect('/install/index.php');
}
if (admin_logged_in()) {
    redirect(APP_ADMIN_PATH . '/index.php');
}
if (!auth_pending_user()) {
    redirect(APP_ADMIN_PATH . '/login.php');
}

$error = '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $rememberDevice = !empty($_POST['remember_device']);
    $res = auth_verify_2fa((string) ($_POST['code'] ?? ''), $rememberDevice);
    if ($res['ok']) {
        if (!empty($res['backup_used'])) {
            $msg = t('twofa.backup_used');
        }
        redirect(APP_ADMIN_PATH . '/index.php');
    }
    $error = (string) ($res['error'] ?? t('twofa.invalid'));
}

admin_header('2FA');
?>
<h2><?= h(t('twofa.title')) ?></h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-bad"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="panel">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <label><?= h(t('twofa.code')) ?><br><input name="code" required></label><br><br>
  <label><input type="checkbox" name="remember_device" value="1"> <?= h(t('twofa.remember_device')) ?></label>
  <p class="small-muted"><?= h(t('twofa.remember_device_help')) ?></p><br>
  <button type="submit"><?= h(t('twofa.validate')) ?></button>
</form>
<?php admin_footer();
