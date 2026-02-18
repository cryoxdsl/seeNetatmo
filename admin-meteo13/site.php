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
        $err='Invalid values';
    } elseif ($err === '') {
        setting_set('site_name',$site);
        setting_set('contact_email',$mail);
        setting_set('data_table',$table);
        setting_set('browser_title', $browserTitle !== '' ? $browserTitle : $site);
        setting_set('favicon_url', $uploadedFavicon ?? ($favicon !== '' ? $favicon : '/favicon.ico'));
        $msg='Saved';
    }
}
admin_header('Site');
?>
<h2>Site settings</h2>
<?php if($msg):?><div class="alert alert-ok"><?=h($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-bad"><?=h($err)?></div><?php endif;?>
<form method="post" class="panel" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
  <label>Site name<br><input name="site_name" value="<?=h(app_name())?>" required></label><br><br>
  <label>Browser title<br><input name="browser_title" value="<?=h(browser_title_base())?>" placeholder="meteo13.fr" required></label><br><br>
  <label>Favicon URL/path<br><input name="favicon_url" value="<?=h(favicon_url())?>" placeholder="/favicon.ico"></label><br><br>
  <label>Upload favicon (ICO/PNG/JPG/WEBP, max 1 MB)<br><input type="file" name="favicon_file" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp"></label><br><br>
  <p>Current favicon: <span class="code"><?=h(favicon_url())?></span></p>
  <label>Contact email<br><input type="email" name="contact_email" value="<?=h(contact_email())?>" required></label><br><br>
  <label>Data table<br><input name="data_table" value="<?=h(data_table())?>" required></label><br><br>
  <button type="submit">Save</button>
</form>
<?php admin_footer();
