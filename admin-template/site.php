<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';

enforce_admin_suffix_url();
auth_require_login();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    setting_set('site_name', trim((string) ($_POST['site_name'] ?? 'seeNetatmo')));
    setting_set('contact_email', trim((string) ($_POST['contact_email'] ?? 'contact@example.com')));
    setting_set('table_name', trim((string) ($_POST['table_name'] ?? 'alldata')));
    $msg = 'Settings saved.';
}

admin_header('Site settings');
?>
<h2>Site settings</h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<form method="post" class="panel">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label>Site name<br><input name="site_name" value="<?= h(app_name()) ?>"></label><br><br>
    <label>Contact email<br><input type="email" name="contact_email" value="<?= h(contact_email()) ?>"></label><br><br>
    <label>Data table name<br><input name="table_name" value="<?= h(alldata_table()) ?>"></label><br><br>
    <button type="submit">Save</button>
</form>
<?php
admin_footer();
