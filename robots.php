<?php
// ============================================================
// ROBOTS.TXT — servido en /robots.txt (reescritura en .htaccess / nginx)
// ============================================================
require_once __DIR__ . '/config/app.php';

header('Content-Type: text/plain; charset=UTF-8');
echo "User-agent: *\n";
echo "Disallow: /admin/\n";
echo "Disallow: /api/\n";
echo "Disallow: /database/\n";
echo "Disallow: /carrito\n";
echo "Disallow: /checkout\n";
echo "Disallow: /confirmacion\n";
echo "Disallow: /cuenta/\n";
echo "Allow: /cuenta/registro\n";
echo "\nSitemap: " . BASE_URL . "/sitemap.xml\n";
