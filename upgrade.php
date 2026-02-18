<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/logger.php';

if (!app_is_installed()) {
    http_response_code(403);
    exit('Application is not installed.');
}

if (!auth_is_logged_in()) {
    $suffix = (string) app_setting('admin_suffix', 'securepanel');
    header('Location: /admin-' . $suffix . '/login.php');
    exit;
}

$migrations = [
    2 => [
        'description' => 'Ensure app_logs indexes',
        'sql' => [
            "ALTER TABLE app_logs ADD KEY idx_logs_channel_date (channel, created_at)",
            "ALTER TABLE app_logs ADD KEY idx_logs_level_date (level, created_at)",
        ],
    ],
    3 => [
        'description' => 'Ensure settings metadata',
        'sql' => [
            "ALTER TABLE settings MODIFY setting_value TEXT NOT NULL",
        ],
    ],
];

$applied = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();

    foreach ($migrations as $version => $migration) {
        $existsStmt = db()->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :v');
        $existsStmt->execute([':v' => $version]);
        if ((int) $existsStmt->fetchColumn() > 0) {
            continue;
        }

        db()->beginTransaction();
        try {
            foreach ($migration['sql'] as $sql) {
                try {
                    db()->exec($sql);
                } catch (Throwable $e) {
                    if (!str_contains(strtolower($e->getMessage()), 'duplicate key name')) {
                        throw $e;
                    }
                }
            }
            $ins = db()->prepare('INSERT INTO schema_migrations (version, description, applied_at) VALUES (:v, :d, NOW())');
            $ins->execute([':v' => $version, ':d' => $migration['description']]);
            db()->commit();
            $applied[] = $version;
        } catch (Throwable $e) {
            db()->rollBack();
            $errors[] = 'Migration ' . $version . ': ' . $e->getMessage();
            break;
        }
    }

    if (!$errors) {
        setting_set('app_version', APP_DEFAULT_VERSION);
        app_log('info', 'upgrade', 'Upgrade executed', ['applied' => $applied]);
    }
}

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body><main class="wrap"><section class="panel">
<h1>Upgrade</h1>
<p>Current app version setting: <strong><?= h((string) setting_get('app_version', APP_DEFAULT_VERSION)) ?></strong></p>
<?php foreach ($applied as $v): ?><div class="alert alert-ok">Migration applied: <?= (int) $v ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<button type="submit">Run migrations</button>
</form>
<p><a class="button" href="/admin-<?= h((string) app_setting('admin_suffix', 'securepanel')) ?>/dashboard.php">Back to admin</a></p>
</section></main></body></html>
