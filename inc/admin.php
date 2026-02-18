<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/logger.php';

function admin_suffix(): string
{
    if (!app_is_installed()) {
        return 'securepanel';
    }
    return (string) app_setting('admin_suffix', setting_get('admin_suffix', 'admin-secure'));
}

function enforce_admin_suffix_url(): void
{
    $suffix = admin_suffix();
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (!str_contains($script, '/admin-' . $suffix . '/')) {
        http_response_code(404);
        exit('Not found');
    }
}

function admin_header(string $title): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title . ' - Admin') . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css">';
    echo '</head><body><div class="admin-shell">';
    echo '<aside class="admin-nav"><strong>Admin</strong>';
    if (auth_is_logged_in()) {
        echo '<a href="dashboard.php">Dashboard</a><a href="site.php">Site</a><a href="netatmo.php">Netatmo</a><a href="health.php">Health</a><a href="logs.php">Logs</a><a href="/upgrade.php">Upgrade</a><a href="logout.php">Logout</a>';
    }
    echo '</aside><main class="admin-main">';
}

function admin_footer(): void
{
    echo '</main></div></body></html>';
}
