<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();

$msg = '';
$err = '';
$setup = null;
$user = admin_current_user();
if (!$user) {
    admin_logout();
}

$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'disable') {
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            if (!password_verify($confirmPassword, (string) ($user['password_hash'] ?? ''))) {
                throw new RuntimeException(t('twofa.disable_bad_password'));
            }
            db()->prepare('UPDATE users SET totp_secret_enc=:s WHERE id=:id')
                ->execute([':s' => '', ':id' => $uid]);
            db()->prepare('DELETE FROM backup_codes WHERE user_id=:u')->execute([':u' => $uid]);
            auth_revoke_all_trusted_devices($uid);
            $msg = t('twofa.disabled');
        } elseif ($action === 'enable' || $action === 'regenerate') {
            if ($action === 'regenerate') {
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                if (!password_verify($confirmPassword, (string) ($user['password_hash'] ?? ''))) {
                    throw new RuntimeException(t('twofa.disable_bad_password'));
                }
            }
            $secret = totp_secret_generate();
            $secretEnc = encrypt_string($secret);

            $codesPlain = [];
            for ($i = 0; $i < 8; $i++) {
                $codesPlain[] = strtoupper(substr(random_hex(4), 0, 8));
            }

            db()->beginTransaction();
            db()->prepare('UPDATE users SET totp_secret_enc=:s WHERE id=:id')
                ->execute([':s' => $secretEnc, ':id' => $uid]);
            db()->prepare('DELETE FROM backup_codes WHERE user_id=:u')->execute([':u' => $uid]);
            auth_revoke_all_trusted_devices($uid);
            $ins = db()->prepare('INSERT INTO backup_codes(user_id,code_hash,created_at) VALUES(:u,:h,NOW())');
            foreach ($codesPlain as $c) {
                $ins->execute([':u' => $uid, ':h' => password_hash($c, PASSWORD_DEFAULT)]);
            }
            db()->commit();

            $issuer = app_name();
            $uri = totp_uri($secret, (string) $user['username'], $issuer);
            $setup = ['secret' => $secret, 'uri' => $uri, 'codes' => $codesPlain];
            $msg = t('twofa.enabled');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $err = $e->getMessage();
    }
    $user = admin_current_user();
}

$enabled = $user ? user_has_2fa($user) : false;

admin_header(t('twofa.manage_title'));
?>
<h2><?= h(t('twofa.manage_title')) ?></h2>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-bad"><?= h($err) ?></div><?php endif; ?>

<div class="panel">
  <p><?= h(t('twofa.status')) ?>:
    <strong><?= $enabled ? h(t('twofa.status_enabled')) : h(t('twofa.status_disabled')) ?></strong>
  </p>

  <?php if ($enabled): ?>
    <form method="post" class="row">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="regenerate">
      <label><?= h(t('twofa.confirm_password')) ?><br><input type="password" name="confirm_password" required></label>
      <button type="submit"><?= h(t('twofa.regenerate')) ?></button>
    </form>
    <form method="post" class="row" onsubmit="return confirm('<?= h(t('twofa.confirm_disable')) ?>');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="disable">
      <label><?= h(t('twofa.confirm_password')) ?><br><input type="password" name="confirm_password" required></label>
      <button type="submit"><?= h(t('twofa.disable')) ?></button>
    </form>
  <?php else: ?>
    <form method="post" class="row">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="enable">
      <button type="submit"><?= h(t('twofa.enable')) ?></button>
    </form>
  <?php endif; ?>
</div>

<?php if ($setup): ?>
<div class="panel">
  <p><?= h(t('twofa.scan_qr')) ?></p>
  <p><?= h(t('twofa.secret')) ?>: <span class="code"><?= h($setup['secret']) ?></span></p>
  <p>TOTP URI: <span class="code"><?= h($setup['uri']) ?></span></p>
  <p><?= h(t('twofa.backup_codes')) ?>: <span class="code"><?= h(implode(' ', $setup['codes'])) ?></span></p>
</div>
<?php endif; ?>

<?php admin_footer();
