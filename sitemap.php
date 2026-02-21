<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/helpers.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = base_url_root();
$now = date('c');
$pages = [
    '/index.php',
    '/charts.php',
    '/climat.php',
    '/history.php',
    '/terms.php',
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $path) {
    $full = $base . $path;
    echo "  <url>\n";
    echo '    <loc>' . h($full) . "</loc>\n";
    echo '    <lastmod>' . h($now) . "</lastmod>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";

