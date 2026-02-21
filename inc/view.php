<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';

function seo_canonical_url(string $path): string
{
    $lang = locale_current();
    $query = [];
    if ($lang === 'en_EN') {
        $query['lang'] = 'en_EN';
    } elseif (isset($_GET['lang']) && (string) $_GET['lang'] === 'fr_FR') {
        // Keep explicit FR when requested.
        $query['lang'] = 'fr_FR';
    }
    $qs = $query ? ('?' . http_build_query($query)) : '';
    return base_url_root() . $path . $qs;
}

function seo_alternate_locale_url(string $path, string $locale): string
{
    $query = [];
    if ($locale === 'en_EN') {
        $query['lang'] = 'en_EN';
    } elseif (isset($_GET['lang']) && (string) $_GET['lang'] === 'fr_FR') {
        $query['lang'] = 'fr_FR';
    }
    $qs = $query ? ('?' . http_build_query($query)) : '';
    return base_url_root() . $path . $qs;
}

function seo_meta_description_for_path(string $path): string
{
    return match ($path) {
        '/index.php', '/' => t('seo.dashboard_description'),
        '/charts.php' => t('seo.charts_description'),
        '/history.php' => t('seo.history_description'),
        '/climat.php' => t('seo.climate_description'),
        '/terms.php' => t('seo.terms_description'),
        default => t('seo.default_description'),
    };
}

function front_header(string $title): void
{
    $baseTitle = browser_title_base();
    $fullTitle = $title !== '' ? ($title . ' - ' . $baseTitle) : $baseTitle;
    $units = units_current();
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH);
    if ($path === '') {
        $path = '/index.php';
    }
    $canonical = seo_canonical_url($path);
    $altFr = seo_alternate_locale_url($path, 'fr_FR');
    $altEn = seo_alternate_locale_url($path, 'en_EN');
    $description = seo_meta_description_for_path($path);
    $ogLocale = locale_current() === 'fr_FR' ? 'fr_FR' : 'en_US';
    $siteName = app_name();
    echo '<!doctype html><html lang="' . h(locale_current()) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="description" content="' . h($description) . '">';
    echo '<meta name="robots" content="index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1">';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="canonical" href="' . h($canonical) . '">';
    echo '<link rel="alternate" hreflang="fr-FR" href="' . h($altFr) . '">';
    echo '<link rel="alternate" hreflang="en" href="' . h($altEn) . '">';
    echo '<link rel="alternate" hreflang="x-default" href="' . h($altFr) . '">';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:site_name" content="' . h($siteName) . '">';
    echo '<meta property="og:title" content="' . h($fullTitle) . '">';
    echo '<meta property="og:description" content="' . h($description) . '">';
    echo '<meta property="og:url" content="' . h($canonical) . '">';
    echo '<meta property="og:locale" content="' . h($ogLocale) . '">';
    echo '<meta name="twitter:card" content="summary">';
    echo '<meta name="twitter:title" content="' . h($fullTitle) . '">';
    echo '<meta name="twitter:description" content="' . h($description) . '">';
    echo '<link rel="icon" href="' . h(favicon_url()) . '" type="image/x-icon">';
    $cssPath = __DIR__ . '/../assets/css/style.css';
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : APP_VERSION;
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . h($cssVersion) . '">';
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'url' => base_url_root() . '/',
        'inLanguage' => locale_current() === 'fr_FR' ? 'fr-FR' : 'en',
    ];
    echo '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap head-row"><div class="brand-wrap"><a class="brand" href="/index.php">' . h(app_name()) . '</a><span class="brand-version">' . h(app_release_tag()) . '</span></div><nav>';
    echo '<a href="/index.php">' . h(t('nav.live')) . '</a><a href="/charts.php">' . h(t('nav.charts')) . '</a><a href="/climat.php">' . h(t('nav.climate')) . '</a><a href="/history.php">' . h(t('nav.history')) . '</a>';
    echo '<span class="nav-switch"><a href="' . h(locale_switch_url('fr_FR')) . '"' . (locale_current() === 'fr_FR' ? ' class="active"' : '') . ' title="' . h(t('lang.fr_full')) . '">' . h(t('lang.fr')) . '</a><span class="sep">|</span><a href="' . h(locale_switch_url('en_EN')) . '"' . (locale_current() === 'en_EN' ? ' class="active"' : '') . ' title="' . h(t('lang.en_full')) . '">' . h(t('lang.en')) . '</a></span>';
    echo '<span class="nav-switch"><a href="' . h(units_switch_url('si')) . '"' . ($units === 'si' ? ' class="active"' : '') . ' title="' . h(t('units.si_full')) . '">' . h(t('units.si')) . '</a><span class="sep">|</span><a href="' . h(units_switch_url('imperial')) . '"' . ($units === 'imperial' ? ' class="active"' : '') . ' title="' . h(t('units.imperial_full')) . '">' . h(t('units.imperial')) . '</a></span>';
    echo '</nav></div></header><main class="wrap">';
}

function front_footer(): void
{
    echo '</main><footer class="foot"><div class="wrap">' . h(t('footer.license')) . ' | ' . h(t('footer.contact')) . ': <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a> | <a href="/terms.php">' . h(t('footer.terms')) . '</a> | <a href="https://github.com/cryoxdsl/seeNetatmo" target="_blank" rel="noopener noreferrer">Git</a></div></footer>';
    echo '<script>(function(){if(window.__m13TrackInit){return;}window.__m13TrackInit=true;var EP="/track.php";var VID_KEY="m13_vid";var SID_KEY="m13_sid";var toHex=function(n){return ("0"+n.toString(16)).slice(-2);};var rand=function(len){if(window.crypto&&crypto.getRandomValues){var a=new Uint8Array(len);crypto.getRandomValues(a);var s="";for(var i=0;i<a.length;i++){s+=toHex(a[i]);}return s;}var out="";for(var j=0;j<len*2;j++){out+=(Math.random()*16|0).toString(16);}return out;};var vid=localStorage.getItem(VID_KEY)||rand(12);localStorage.setItem(VID_KEY,vid);var sid=sessionStorage.getItem(SID_KEY)||"";var path=location.pathname||"/";var title=document.title||"";var ref=document.referrer||"";var lang=document.documentElement.lang||"";var lastTs=Date.now();var hiddenSentAt=0;var send=function(action,delta,keepalive){var payload={action:action,visitor_id:vid,session_token:sid,path:path,title:title.slice(0,255),referrer:ref.slice(0,255),lang:lang.slice(0,16),delta_seconds:Math.max(0,Math.min(600,delta|0))};if(keepalive&&navigator.sendBeacon){try{var blob=new Blob([JSON.stringify(payload)],{type:"application/json"});navigator.sendBeacon(EP,blob);return Promise.resolve();}catch(_e){}}return fetch(EP,{method:"POST",credentials:"same-origin",cache:"no-store",keepalive:!!keepalive,headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)}).then(function(r){if(action==="start"){return r.json();}}).then(function(j){if(j&&j.session_token){sid=j.session_token;sessionStorage.setItem(SID_KEY,sid);}}).catch(function(){});};send("start",0,false).finally(function(){lastTs=Date.now();});var beat=function(forceEnd){var now=Date.now();var d=Math.floor((now-lastTs)/1000);if(d<=0&&!forceEnd){return;}lastTs=now;send(forceEnd?"page_end":"ping",d,!!forceEnd);};var timer=window.setInterval(function(){if(document.hidden){return;}beat(false);},15000);document.addEventListener("visibilitychange",function(){if(document.hidden){var now=Date.now();if(now-hiddenSentAt>1200){hiddenSentAt=now;beat(true);}}else{lastTs=Date.now();}});window.addEventListener("pagehide",function(){beat(true);});window.addEventListener("beforeunload",function(){beat(true);});})();</script>';
    echo '</body></html>';
}
