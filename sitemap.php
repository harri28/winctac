<?php
// ============================================================
// SITEMAP.XML — servido en /sitemap.xml (reescritura en .htaccess / nginx)
// Páginas institucionales + cada producto activo de la tienda actual.
// ============================================================
require_once __DIR__ . '/config/app.php';

$cfg = getShopConfig();
$urls = [
    ['loc' => BASE_URL . '/',                     'priority' => '1.0', 'changefreq' => 'weekly'],
    ['loc' => BASE_URL . '/nosotros',             'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => BASE_URL . '/catalogo',             'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => BASE_URL . '/contacto',             'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => BASE_URL . '/trabaja-con-nosotros', 'priority' => '0.5', 'changefreq' => 'monthly'],
];
if (!empty($cfg['puntos_activo'])) {
    $urls[] = ['loc' => BASE_URL . '/cuenta/registro', 'priority' => '0.5', 'changefreq' => 'yearly'];
}

try {
    $stmt = getDB()->prepare('SELECT id, nombre, updated_at, created_at FROM productos WHERE activo = TRUE AND tienda_id = ? ORDER BY id');
    $stmt->execute([TIENDA_ID]);
    foreach ($stmt as $p) {
        $fecha = $p['updated_at'] ?? $p['created_at'] ?? null;
        $urls[] = [
            'loc'        => urlProducto((int) $p['id'], $p['nombre']),
            'lastmod'    => $fecha ? date('Y-m-d', strtotime($fecha)) : null,
            'priority'   => '0.6',
            'changefreq' => 'weekly',
        ];
    }
} catch (PDOException $e) {
    // Sin productos (o sin columna updated_at): el sitemap sigue con las páginas fijas
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    if (!empty($u['lastmod'])) echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n  </url>\n";
}
echo '</urlset>';
