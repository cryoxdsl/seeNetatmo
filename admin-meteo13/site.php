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
    $browserTitle = trim((string)($_POST['browser_title'] ?? ''));
    $favicon = trim((string)($_POST['favicon_url'] ?? ''));
    $defaultLocale = normalize_locale((string) ($_POST['default_locale'] ?? 'fr_FR'));
    $stationDepartment = strtoupper(trim((string) ($_POST['station_department'] ?? '')));
    $uploadedFavicon = null;

    if (isset($_FILES['favicon_file']) && is_array($_FILES['favicon_file']) && ((int)($_FILES['favicon_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = (int) ($_FILES['favicon_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $err = 'Favicon upload failed (error code ' . $uploadError . ')';
        } else {
            $tmp = (string) ($_FILES['favicon_file']['tmp_name'] ?? '');
            $size = (int) ($_FILES['favicon_file']['size'] ?? 0);
            if (!is_uploaded_file($tmp)) {
                $err = 'Invalid uploaded file';
            } elseif ($size <= 0 || $size > 1024 * 1024) {
                $err = 'Favicon max size is 1 MB';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmp);
                $allowed = [
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    'image/x-icon' => 'ico',
                    'image/vnd.microsoft.icon' => 'ico',
                ];
                if (!isset($allowed[$mime])) {
                    $err = 'Unsupported favicon format. Use ICO, PNG, JPG or WEBP';
                } else {
                    $uploadDir = __DIR__ . '/../assets/uploads';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $err = 'Cannot create uploads directory';
                    } else {
                        $htPath = $uploadDir . '/.htaccess';
                        if (!is_file($htPath)) {
                            @file_put_contents($htPath, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
                        }

                        $filename = 'favicon_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                        $target = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $target)) {
                            $err = 'Cannot move uploaded favicon';
                        } else {
                            $uploadedFavicon = '/assets/uploads/' . $filename;
                        }
                    }
                }
            }
        }
    }

    if ($site==='' || !filter_var($mail, FILTER_VALIDATE_EMAIL) || $table==='') {
        $err=t('site.invalid');
    } elseif ($stationDepartment !== '' && preg_match('/^(?:\d{2,3}|2A|2B)$/', $stationDepartment) !== 1) {
        $err=t('site.invalid');
    } elseif ($err === '') {
        setting_set('site_name',$site);
        setting_set('contact_email',$mail);
        setting_set('data_table',$table);
        setting_set('browser_title', $browserTitle !== '' ? $browserTitle : $site);
        setting_set('favicon_url', $uploadedFavicon ?? ($favicon !== '' ? $favicon : '/favicon.ico'));
        setting_set('default_locale', $defaultLocale);
        setting_set('station_department', $stationDepartment);
        $msg=t('site.saved');
    }
}
admin_header(t('admin.site'));
?>
<h2><?= h(t('site.title')) ?></h2>
<?php if($msg):?><div class="alert alert-ok"><?=h($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-bad"><?=h($err)?></div><?php endif;?>
<form method="post" class="panel" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <label><?= h(t('site.name')) ?><br><input name="site_name" value="<?=h(app_name())?>" required></label><br><br>
  <label><?= h(t('site.browser_title')) ?><br><input name="browser_title" value="<?=h(browser_title_base())?>" placeholder="meteo13.fr" required></label><br><br>
  <label><?= h(t('site.favicon_url')) ?><br><input name="favicon_url" value="<?=h(favicon_url())?>" placeholder="/favicon.ico"></label><br><br>
  <label><?= h(t('site.favicon_upload')) ?><br><input type="file" name="favicon_file" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp"></label><br><br>
  <p><?= h(t('site.favicon_current')) ?>: <span class="code"><?=h(favicon_url())?></span></p>
  <label><?= h(t('site.contact')) ?><br><input type="email" name="contact_email" value="<?=h(contact_email())?>" required></label><br><br>
  <label><?= h(t('site.table')) ?><br><input name="data_table" value="<?=h(data_table())?>" required></label><br><br>
  <label><?= h(t('site.default_locale')) ?><br>
    <select name="default_locale">
      <?php $currentDefault = normalize_locale(setting_get('default_locale', 'fr_FR')); ?>
      <option value="fr_FR" <?= $currentDefault === 'fr_FR' ? 'selected' : '' ?>>fr_FR</option>
      <option value="en_EN" <?= $currentDefault === 'en_EN' ? 'selected' : '' ?>>en_EN</option>
    </select>
  </label><br><br>
  <label><?= h(t('site.station_department')) ?><br><input name="station_department" value="<?=h(station_department_setting())?>" placeholder="13"></label><br><br>
  <button type="submit"><?= h(t('site.save')) ?></button>
</form>
<?php admin_footer();
