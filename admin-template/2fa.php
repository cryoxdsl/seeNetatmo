<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/crypto.php';

enforce_admin_suffix_url();

if (auth_is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$user = auth_pending_2fa_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $code = (string) ($_POST['code'] ?? '');
    $secret = decrypt_secret($user['totp_secret_enc']);
    if ($secret && verify_totp($secret, $code)) {
        auth_complete_2fa();
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid TOTP code.';
}

admin_header('2FA');
?>
<h2>Two-factor authentication</h2>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="panel">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>TOTP code<br><input required name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"></label><br><br>
    <button type="submit">Validate</button>
</form>
<?php
admin_footer();
