<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();
$msg='';$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    $site = trim((string)($_POST['site_name'] ?? ''));
    $mail = trim((string)($_POST['contact_email'] ?? ''));
    $table = trim((string)($_POST['data_table'] ?? 'alldata'));
    if ($site==='' || !filter_var($mail, FILTER_VALIDATE_EMAIL) || $table==='') {
        $err='Invalid values';
    } else {
        setting_set('site_name',$site);
        setting_set('contact_email',$mail);
        setting_set('data_table',$table);
        $msg='Saved';
    }
}
admin_header('Site');
?>
<h2>Site settings</h2>
<?php if($msg):?><div class="alert alert-ok"><?=h($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-bad"><?=h($err)?></div><?php endif;?>
<form method="post" class="panel">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <label>Site name<br><input name="site_name" value="<?=h(app_name())?>" required></label><br><br>
  <label>Contact email<br><input type="email" name="contact_email" value="<?=h(contact_email())?>" required></label><br><br>
  <label>Data table<br><input name="data_table" value="<?=h(data_table())?>" required></label><br><br>
  <button type="submit">Save</button>
</form>
<?php admin_footer();
