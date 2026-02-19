<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';

function front_header(string $title): void
{
    $baseTitle = browser_title_base();
    $fullTitle = $title !== '' ? ($title . ' - ' . $baseTitle) : $baseTitle;
    $units = units_current();

    echo '<!doctype html><html lang="' . h(locale_current()) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="icon" href="' . h(favicon_url()) . '" type="image/x-icon">';
    $cssPath = __DIR__ . '/../assets/css/style.css';
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : APP_VERSION;
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . h($cssVersion) . '">';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap head-row"><div class="brand">' . h(app_name()) . '</div><nav>';
    echo '<a href="/index.php">' . h(t('nav.live')) . '</a><a href="/charts.php">' . h(t('nav.charts')) . '</a><a href="/climat.php">' . h(t('nav.climate')) . '</a><a href="/history.php">' . h(t('nav.history')) . '</a>';
    echo '<span class="nav-switch"><a href="' . h(locale_switch_url('fr_FR')) . '"' . (locale_current() === 'fr_FR' ? ' class="active"' : '') . ' title="' . h(t('lang.fr_full')) . '">' . h(t('lang.fr')) . '</a><span class="sep">|</span><a href="' . h(locale_switch_url('en_EN')) . '"' . (locale_current() === 'en_EN' ? ' class="active"' : '') . ' title="' . h(t('lang.en_full')) . '">' . h(t('lang.en')) . '</a></span>';
    echo '<span class="nav-switch"><a href="' . h(units_switch_url('si')) . '"' . ($units === 'si' ? ' class="active"' : '') . ' title="' . h(t('units.si_full')) . '">' . h(t('units.si')) . '</a><span class="sep">|</span><a href="' . h(units_switch_url('imperial')) . '"' . ($units === 'imperial' ? ' class="active"' : '') . ' title="' . h(t('units.imperial_full')) . '">' . h(t('units.imperial')) . '</a></span>';
    echo '</nav></div></header><main class="wrap">';
}

function front_footer(): void
{
    echo '</main><footer class="foot"><div class="wrap">' . h(t('footer.license')) . ' | ' . h(t('footer.contact')) . ': <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a> | <a href="/terms.php">' . h(t('footer.terms')) . '</a></div></footer></body></html>';
}
