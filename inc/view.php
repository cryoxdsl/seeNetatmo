<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';

function front_header(string $title): void
{
    $baseTitle = browser_title_base();
    $fullTitle = $title !== '' ? ($title . ' - ' . $baseTitle) : $baseTitle;
    $units = units_current();

    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="icon" href="' . h(favicon_url()) . '" type="image/x-icon">';
    echo '<link rel="stylesheet" href="/assets/css/style.css">';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap head-row"><div class="brand">' . h(app_name()) . '</div><nav>';
    echo '<a href="/index.php">' . h(t('nav.live')) . '</a><a href="/charts.php">' . h(t('nav.charts')) . '</a><a href="/history.php">' . h(t('nav.history')) . '</a>';
    echo '<a href="' . h(locale_switch_url('fr_FR')) . '">' . h(t('lang.fr')) . '</a><a href="' . h(locale_switch_url('en_EN')) . '">' . h(t('lang.en')) . '</a>';
    echo '<a href="' . h(units_switch_url('si')) . '"' . ($units === 'si' ? ' style="text-decoration:underline"' : '') . '>' . h(t('units.si')) . '</a>';
    echo '<a href="' . h(units_switch_url('imperial')) . '"' . ($units === 'imperial' ? ' style="text-decoration:underline"' : '') . '>' . h(t('units.imperial')) . '</a>';
    echo '</nav></div></header><main class="wrap">';
}

function front_footer(): void
{
    echo '</main><footer class="foot"><div class="wrap">' . h(t('footer.license')) . ' | ' . h(t('footer.contact')) . ': <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a></div></footer></body></html>';
}
