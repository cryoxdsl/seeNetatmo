<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/logger.php';

if (!app_is_installed()) {
    http_response_code(403);
    exit(t('upgrade.not_installed'));
}
admin_require_login();

$migrations = [
    2 => [
        'description' => 'Ensure admin_path setting exists',
        'sql' => [
            "INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('admin_path','/admin-meteo13',NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()",
        ],
    ],
    3 => [
        'description' => 'Ensure login_attempts username+ip index',
        'sql' => [
            "ALTER TABLE login_attempts ADD KEY idx_login_attempts_uip (username, ip_address, created_at)",
        ],
    ],
];

$applied = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    foreach ($migrations as $version => $migration) {
        $exists = db()->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=:v');
        $exists->execute([':v' => $version]);
        if ((int)$exists->fetchColumn() > 0) {
            continue;
        }

        db()->beginTransaction();
        try {
            foreach ($migration['sql'] as $sql) {
                try {
                    db()->exec($sql);
                } catch (Throwable $e) {
                    $msg = strtolower($e->getMessage());
                    if (!str_contains($msg, 'duplicate key name') && !str_contains($msg, 'duplicate column name')) {
                        throw $e;
                    }
                }
            }
            db()->prepare('INSERT INTO schema_migrations(version,description,applied_at) VALUES(:v,:d,NOW())')->execute([':v'=>$version,':d'=>$migration['description']]);
            db()->commit();
            $applied[] = $version;
        } catch (Throwable $e) {
            db()->rollBack();
            $errors[] = 'Migration ' . $version . ': ' . $e->getMessage();
            break;
        }
    }

    if (!$errors) {
        setting_set('app_version', APP_VERSION);
        log_event('info', 'upgrade', 'Upgrade executed', ['applied' => $applied]);
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(t('upgrade.title')) ?></title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="wrap"><section class="panel"><h1><?= h(t('upgrade.title')) ?></h1>
<p><?= h(t('upgrade.target_version')) ?>: <?= h(APP_VERSION) ?></p>
<?php foreach($applied as $v):?><div class="alert alert-ok"><?= h(t('upgrade.applied')) ?>: <?= (int)$v ?></div><?php endforeach; ?>
<?php foreach($errors as $e):?><div class="alert alert-bad"><?= h($e) ?></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit"><?= h(t('upgrade.run')) ?></button></form>
<p><a class="btn" href="<?= h(APP_ADMIN_PATH) ?>/index.php"><?= h(t('upgrade.back_admin')) ?></a></p>
</section></main></body></html>
