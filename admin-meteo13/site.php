<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();
$msg='';$err='';
$termsContentForm = terms_of_use_content();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    $site = trim((string)($_POST['site_name'] ?? ''));
    $mail = trim((string)($_POST['contact_email'] ?? ''));
    $table = trim((string)($_POST['data_table'] ?? 'alldata'));
    $browserTitle = trim((string)($_POST['browser_title'] ?? ''));
    $favicon = trim((string)($_POST['favicon_url'] ?? ''));
    $weatherIconStyle = trim((string) ($_POST['weather_icon_style'] ?? weather_icon_style_setting()));
    $defaultLocale = normalize_locale((string) ($_POST['default_locale'] ?? 'fr_FR'));
    $stationDepartment = strtoupper(trim((string) ($_POST['station_department'] ?? '')));
    $stationZip = trim((string) ($_POST['station_zipcode'] ?? ''));
    $stationLat = trim((string) ($_POST['station_lat'] ?? ''));
    $stationLon = trim((string) ($_POST['station_lon'] ?? ''));
    $stationAlt = trim((string) ($_POST['station_altitude'] ?? ''));
    $stationLock = isset($_POST['station_lock_position']) ? '1' : '0';
    $termsContent = (string) ($_POST['terms_of_use_content'] ?? terms_of_use_content());
    $termsContent = str_replace(["\r\n", "\r"], "\n", $termsContent);
    $termsContentForm = $termsContent;
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
    } elseif (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
        $err=t('site.invalid');
    } elseif (!in_array($weatherIconStyle, ['realistic', 'minimal', 'outline', 'glyph'], true)) {
        $err=t('site.invalid');
    } elseif ($stationDepartment !== '' && preg_match('/^(?:\d{2,3}|2A|2B)$/', $stationDepartment) !== 1) {
        $err=t('site.invalid');
    } elseif ($stationLat !== '' && (!is_numeric($stationLat) || (float) $stationLat < -90 || (float) $stationLat > 90)) {
        $err=t('site.invalid');
    } elseif ($stationLon !== '' && (!is_numeric($stationLon) || (float) $stationLon < -180 || (float) $stationLon > 180)) {
        $err=t('site.invalid');
    } elseif ($stationAlt !== '' && (!is_numeric($stationAlt) || (float) $stationAlt < -500 || (float) $stationAlt > 10000)) {
        $err=t('site.invalid');
    } elseif (($stationLat === '') xor ($stationLon === '')) {
        $err=t('site.invalid');
    } elseif ($err === '') {
        $tbl = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $tbl->execute([':t' => $table]);
        if ((int) $tbl->fetchColumn() === 0) {
            $err = t('site.invalid');
        } else {
            $pk = db()->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
            $pk->execute([':t' => $table]);
            $pkCols = array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], $pk->fetchAll());
            if ($pkCols !== ['DateTime']) {
                $err = t('site.invalid');
            }
        }
    }

    if ($err === '') {
        setting_set('site_name',$site);
        setting_set('contact_email',$mail);
        setting_set('data_table',$table);
        setting_set('browser_title', $browserTitle !== '' ? $browserTitle : $site);
        setting_set('favicon_url', $uploadedFavicon ?? ($favicon !== '' ? $favicon : '/favicon.ico'));
        setting_set('weather_icon_style', $weatherIconStyle);
        setting_set('default_locale', $defaultLocale);
        setting_set('station_department', $stationDepartment);
        setting_set('station_zipcode', $stationZip);
        setting_set('station_lat', $stationLat !== '' ? (string) ((float) $stationLat) : '');
        setting_set('station_lon', $stationLon !== '' ? (string) ((float) $stationLon) : '');
        setting_set('station_altitude', $stationAlt !== '' ? (string) ((float) $stationAlt) : '');
        setting_set('station_lock_position', $stationLock);
        setting_set('terms_of_use_content', $termsContent);
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
  <label><?= h(t('site.weather_icon_style')) ?><br>
    <?php $iconStyle = weather_icon_style_setting(); ?>
    <select name="weather_icon_style">
      <option value="realistic" <?= $iconStyle === 'realistic' ? 'selected' : '' ?>><?= h(t('site.weather_icon_style_realistic')) ?></option>
      <option value="minimal" <?= $iconStyle === 'minimal' ? 'selected' : '' ?>><?= h(t('site.weather_icon_style_minimal')) ?></option>
      <option value="outline" <?= $iconStyle === 'outline' ? 'selected' : '' ?>><?= h(t('site.weather_icon_style_outline')) ?></option>
      <option value="glyph" <?= $iconStyle === 'glyph' ? 'selected' : '' ?>><?= h(t('site.weather_icon_style_glyph')) ?></option>
    </select>
  </label><br><br>
  <label><?= h(t('site.favicon_upload')) ?><br><input type="file" name="favicon_file" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp"></label><br><br>
  <p><?= h(t('site.favicon_current')) ?>: <span class="code"><?=h(favicon_url())?></span></p>
  <label><?= h(t('site.contact')) ?><br><input type="email" name="contact_email" value="<?=h(contact_email())?>" required></label><br><br>
  <label><?= h(t('site.terms_content')) ?><br>
    <textarea name="terms_of_use_content" rows="12" style="width:min(900px,100%);"><?= h($termsContentForm) ?></textarea>
  </label>
  <p class="small-muted"><?= h(t('site.terms_content_help')) ?></p><br>
  <label><?= h(t('site.table')) ?><br><input name="data_table" value="<?=h(data_table())?>" required></label><br><br>
  <label><?= h(t('site.default_locale')) ?><br>
    <select name="default_locale">
      <?php $currentDefault = normalize_locale(setting_get('default_locale', 'fr_FR')); ?>
      <option value="fr_FR" <?= $currentDefault === 'fr_FR' ? 'selected' : '' ?>>fr_FR</option>
      <option value="en_EN" <?= $currentDefault === 'en_EN' ? 'selected' : '' ?>>en_EN</option>
    </select>
  </label><br><br>
  <label><?= h(t('site.station_zipcode')) ?><br><input name="station_zipcode" value="<?=h(station_zipcode())?>" placeholder="13590"></label><br><br>
  <label><?= h(t('site.station_department')) ?><br><input name="station_department" value="<?=h(station_department_setting())?>" placeholder="13"></label><br><br>
  <label><?= h(t('site.station_lat')) ?><br><input name="station_lat" value="<?=h(station_latitude_setting())?>" placeholder="43.53"></label><br><br>
  <label><?= h(t('site.station_lon')) ?><br><input name="station_lon" value="<?=h(station_longitude_setting())?>" placeholder="5.45"></label><br><br>
  <label><?= h(t('site.station_altitude')) ?><br><input name="station_altitude" value="<?=h(station_altitude_setting())?>" placeholder="350"></label><br><br>
  <label><input type="checkbox" name="station_lock_position" value="1" <?= station_position_locked() ? 'checked' : '' ?>> <?= h(t('site.station_lock_position')) ?></label><br><br>
  <button type="submit"><?= h(t('site.save')) ?></button>
</form>
<?php admin_footer();
