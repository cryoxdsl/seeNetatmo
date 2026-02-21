<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo "User-agent: *\n";
echo "Disallow: /admin-meteo13/\n";
echo "Disallow: /install/\n";
echo "Disallow: /config/\n";
echo "Allow: /\n";
echo 'Sitemap: ' . base_url_root() . "/sitemap.xml\n";

