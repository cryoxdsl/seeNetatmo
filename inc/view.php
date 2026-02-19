<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';

function front_header(string $title): void
{
    $baseTitle = browser_title_base();
    $fullTitle = $title !== '' ? ($title . ' - ' . $baseTitle) : $baseTitle;
    $units = units_current();
    $currentUrl = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
    if ($currentUrl === '') {
        $currentUrl = '/index.php';
    }

    echo '<!doctype html><html lang="' . h(locale_current()) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="icon" href="' . h(favicon_url()) . '" type="image/x-icon">';
    $cssPath = __DIR__ . '/../assets/css/style.css';
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : APP_VERSION;
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . h($cssVersion) . '">';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap head-row"><a class="brand" href="' . h($currentUrl) . '">' . h(app_name()) . '</a><nav>';
    echo '<a href="/index.php">' . h(t('nav.live')) . '</a><a href="/charts.php">' . h(t('nav.charts')) . '</a><a href="/climat.php">' . h(t('nav.climate')) . '</a><a href="/history.php">' . h(t('nav.history')) . '</a>';
    echo '<span class="nav-switch"><a href="' . h(locale_switch_url('fr_FR')) . '"' . (locale_current() === 'fr_FR' ? ' class="active"' : '') . ' title="' . h(t('lang.fr_full')) . '">' . h(t('lang.fr')) . '</a><span class="sep">|</span><a href="' . h(locale_switch_url('en_EN')) . '"' . (locale_current() === 'en_EN' ? ' class="active"' : '') . ' title="' . h(t('lang.en_full')) . '">' . h(t('lang.en')) . '</a></span>';
    echo '<span class="nav-switch"><a href="' . h(units_switch_url('si')) . '"' . ($units === 'si' ? ' class="active"' : '') . ' title="' . h(t('units.si_full')) . '">' . h(t('units.si')) . '</a><span class="sep">|</span><a href="' . h(units_switch_url('imperial')) . '"' . ($units === 'imperial' ? ' class="active"' : '') . ' title="' . h(t('units.imperial_full')) . '">' . h(t('units.imperial')) . '</a></span>';
    echo '</nav></div></header><main class="wrap">';
}

function front_footer(): void
{
    echo '</main><footer class="foot"><div class="wrap">' . h(t('footer.license')) . ' | ' . h(t('footer.contact')) . ': <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a> | <a href="/terms.php">' . h(t('footer.terms')) . '</a> | <a class="git-badge" href="https://github.com/cryoxdsl/seeNetatmo" target="_blank" rel="noopener noreferrer" aria-label="GitHub repository"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 .5a12 12 0 0 0-3.79 23.39c.6.11.82-.26.82-.58v-2.05c-3.34.73-4.04-1.42-4.04-1.42a3.17 3.17 0 0 0-1.33-1.74c-1.09-.75.08-.73.08-.73a2.5 2.5 0 0 1 1.82 1.23a2.56 2.56 0 0 0 3.49 1a2.57 2.57 0 0 1 .77-1.61c-2.66-.31-5.46-1.33-5.46-5.93a4.64 4.64 0 0 1 1.24-3.22a4.3 4.3 0 0 1 .12-3.18s1.01-.32 3.3 1.23a11.39 11.39 0 0 1 6 0c2.29-1.55 3.29-1.23 3.29-1.23a4.3 4.3 0 0 1 .12 3.18a4.63 4.63 0 0 1 1.24 3.22c0 4.61-2.81 5.61-5.49 5.91a2.89 2.89 0 0 1 .82 2.24v3.31c0 .32.22.7.83.58A12 12 0 0 0 12 .5z"/></svg><span>GitHub</span></a></div></footer></body></html>';
}
