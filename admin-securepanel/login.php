<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';

enforce_admin_suffix_url();

if (auth_is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (auth_login_password($username, $password)) {
        header('Location: 2fa.php');
        exit;
    }

    $error = 'Invalid credentials or temporary lockout (10 attempts / 10 minutes).';
}

admin_header('Login');
?>
<h2>Admin Login</h2>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="panel">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>Username<br><input required name="username" autocomplete="username"></label><br><br>
    <label>Password<br><input required type="password" name="password" autocomplete="current-password"></label><br><br>
    <button type="submit">Continue</button>
</form>
<?php
admin_footer();
