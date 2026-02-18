<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/constants.php';

function admin_header(string $title): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title . ' - Admin') . '</title><link rel="stylesheet" href="/assets/css/style.css"></head><body>';
    echo '<div class="admin-layout"><aside class="admin-nav"><h1>Admin</h1>';
    echo '<a href="' . APP_ADMIN_PATH . '/index.php">Dashboard</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/site.php">Site</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/netatmo.php">Netatmo</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/health.php">Health</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/logs.php">Logs</a>';
    echo '<a href="/upgrade.php">Upgrade</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/logout.php">Logout</a>';
    echo '</aside><main class="admin-main">';
}

function admin_footer(): void
{
    echo '</main></div></body></html>';
}
