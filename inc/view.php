<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function render_header(string $title): void
{
    $site = app_name();
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title . ' - ' . $site) . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css">';
    echo '</head><body>';
    echo '<header class="topbar"><div class="wrap"><h1>' . h($site) . '</h1><nav>';
    echo '<a href="/">Live</a><a href="/charts.php">Charts</a><a href="/history.php">History</a>';
    echo '</nav></div></header><main class="wrap">';
}

function render_footer(): void
{
    echo '</main><footer class="footer"><div class="wrap">License: CC BY 4.0 - Contact: <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a></div></footer>';
    echo '</body></html>';
}
