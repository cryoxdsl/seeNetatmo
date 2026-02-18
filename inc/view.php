<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';

function front_header(string $title): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title . ' - ' . app_name()) . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css">';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap head-row"><div class="brand">' . h(app_name()) . '</div><nav>';
    echo '<a href="/index.php">Live</a><a href="/charts.php">Charts</a><a href="/history.php">History</a>';
    echo '</nav></div></header><main class="wrap">';
}

function front_footer(): void
{
    echo '</main><footer class="foot"><div class="wrap">License: CC BY 4.0 | Contact: <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a></div></footer></body></html>';
}
