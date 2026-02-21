<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/settings.php';

function admin_header(string $title): void
{
    $baseTitle = browser_title_base();
    $fullTitle = $title !== '' ? ($title . ' - ' . $baseTitle . ' (Admin)') : ($baseTitle . ' (Admin)');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow,noarchive">';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="icon" href="' . h(favicon_url()) . '" type="image/x-icon">';
    echo '<link rel="stylesheet" href="/assets/css/style.css"></head><body>';
    echo '<div class="admin-layout"><aside class="admin-nav"><h1>' . h(t('admin.title')) . '</h1>';
    echo '<a href="' . APP_ADMIN_PATH . '/index.php">' . h(t('admin.dashboard')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/site.php">' . h(t('admin.site')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/security.php">' . h(t('admin.twofa')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/netatmo.php">' . h(t('admin.netatmo')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/health.php">' . h(t('admin.health')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/stats.php">' . h(t('admin.stats')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/logs.php">' . h(t('admin.logs')) . '</a>';
    echo '<a href="' . APP_ADMIN_PATH . '/backups.php">' . h(t('admin.backups')) . '</a>';
    echo '<a href="/upgrade.php">' . h(t('admin.upgrade')) . '</a>';
    echo '<form method="post" action="' . APP_ADMIN_PATH . '/logout.php" style="margin:0;" onsubmit="return confirm(' . json_encode((string) t('admin.logout_confirm')) . ');">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    echo '<button type="submit" style="all:unset;display:block;cursor:pointer;color:#fff;padding:.4rem .6rem;">' . h(t('admin.logout')) . '</button>';
    echo '</form>';
    echo '<a href="' . h(locale_switch_url('fr_FR')) . '">' . h(t('lang.fr')) . '</a><a href="' . h(locale_switch_url('en_EN')) . '">' . h(t('lang.en')) . '</a>';
    echo '</aside><main class="admin-main">';
}

function admin_footer(): void
{
    if (function_exists('admin_logged_in') && admin_logged_in()) {
        $pingUrl = APP_ADMIN_PATH . '/ping.php';
        echo '<script>(function(){var d=240000;function p(){if(document.hidden){return;}fetch(' . json_encode($pingUrl) . ',{method:"GET",credentials:"same-origin",cache:"no-store"}).catch(function(){});}window.setInterval(p,d);})();</script>';
    }
    echo '</main></div></body></html>';
}
