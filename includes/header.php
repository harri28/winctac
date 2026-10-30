<?php
// ============================================================
// HEADER PÚBLICO — Selvadigital Ecommerce
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_cliente.php';

$cfg      = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$currentPage = $currentPage ?? '';
$faviconOk = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path']);
$faviconTypes = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
$faviconType = $faviconOk ? ($faviconTypes[strtolower(pathinfo($cfg['logo_path'], PATHINFO_EXTENSION))] ?? 'image/jpeg') : '';

// ── SEO: título, descripción y URL canónica de cada página ──
// Cada página puede fijar $pageTitle, $metaDescription y $canonicalUrl antes
// de incluir este header; si no, se derivan de la config y de la URL limpia
// (sin ".php", sin "index", sin query string).
$shopNameRaw = $cfg['nombre_tienda'] ?? 'Mi Tienda Online';
$fullTitle = (!empty($pageTitle) && $currentPage !== 'home') ? $pageTitle . ' | ' . $shopNameRaw : $shopNameRaw;
$metaDescription = trim($metaDescription ?? '') ?: trim($cfg['descripcion'] ?? '')
    ?: $shopNameRaw . ': catálogo virtual de productos, programa de puntos y atención al cliente por WhatsApp.';
if (empty($canonicalUrl)) {
    $basePathUrl = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($basePathUrl !== '' && strpos($reqPath, $basePathUrl) === 0) $reqPath = substr($reqPath, strlen($basePathUrl));
    $reqPath = preg_replace('#(/index)?\.php$#', '', $reqPath);
    $canonicalUrl = BASE_URL . ($reqPath === '' ? '/' : $reqPath);
}
$ogData = array_merge([
    'title'       => $fullTitle,
    'description' => $metaDescription,
    'url'         => $canonicalUrl,
    'type'        => 'website',
    'image'       => $faviconOk ? UPLOADS_URL . '/' . $cfg['logo_path'] : '',
], $ogData ?? []);

$navItems = [
    ['home',                 BASE_URL . '/',                     'Inicio'],
    ['nosotros',             BASE_URL . '/nosotros',             'Nosotros'],
    ['catalogo',             BASE_URL . '/catalogo',             'Catálogo'],
];
if (!empty($cfg['puntos_activo'])) {
    $navItems[] = ['puntos', BASE_URL . '/cuenta/puntos', 'Programa de puntos'];
}
$navItems[] = ['trabaja-con-nosotros', BASE_URL . '/trabaja-con-nosotros', 'Trabaja con nosotros'];
$navItems[] = ['contacto',             BASE_URL . '/contacto',             'Contacto'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fullTitle) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php if ($faviconOk): ?>
    <link rel="icon" type="<?= $faviconType ?>" href="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=38">
    <?= brandColorStyleTag($cfg) ?>
    <script>
        window.BASE_URL = "<?= BASE_URL ?>";
        window.MOSTRAR_STOCK = <?= !empty($cfg['mostrar_stock']) ? 'true' : 'false' ?>;
    </script>
    <?php if ($ogData['description'] !== ''): ?>
    <meta name="description" content="<?= htmlspecialchars($ogData['description']) ?>">
    <?php endif; ?>
    <meta property="og:site_name"   content="<?= $shopName ?>">
    <meta property="og:locale"      content="es_PE">
    <meta property="og:title"       content="<?= htmlspecialchars($ogData['title']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogData['description']) ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($ogData['url']) ?>">
    <meta property="og:type"        content="<?= htmlspecialchars($ogData['type']) ?>">
    <?php if (!empty($ogData['image'])): ?>
    <meta property="og:image"       content="<?= htmlspecialchars($ogData['image']) ?>">
    <?php endif; ?>
    <?php if ($currentPage === 'home'): ?>
    <script type="application/ld+json"><?= json_encode(array_filter([
        '@context'  => 'https://schema.org',
        '@type'     => 'Organization',
        'name'      => $shopNameRaw,
        'url'       => BASE_URL . '/',
        'logo'      => $ogData['image'] ?: null,
        'email'     => trim($cfg['contacto_email'] ?? '') ?: null,
        'telephone' => trim($cfg['contacto_celular'] ?? '') ?: null,
        'address'   => trim($cfg['portal_direccion'] ?? '') ?: null,
        'sameAs'    => array_column(redesSociales($cfg), 'url') ?: null,
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
</head>
<body>

<?php if (!empty($cfg['anuncio_activo']) && !empty($cfg['anuncio_texto'])): ?>
<div class="top-announcement-bar"><?= htmlspecialchars($cfg['anuncio_texto']) ?></div>
<?php endif; ?>

<header class="site-header">
    <div class="header-inner">
        <!-- Logo -->
        <a href="<?= BASE_URL ?>/" class="header-logo" aria-label="<?= $shopName ?>">
            <?php if (!empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path'])): ?>
                <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>" alt="<?= $shopName ?>">
            <?php else: ?>
                <div class="header-logo-icon"><i class="fas fa-store"></i></div>
            <?php endif; ?>
        </a>

        <!-- Categorías -->
        <div class="cat-menu-wrap" id="cat-menu-wrap">
            <button type="button" class="cat-menu-btn" id="cat-toggle" aria-label="Ver categorías">
                <i class="fas fa-bars"></i>
            </button>
            <div class="cat-menu-dropdown" id="cat-dropdown">
                <div class="cat-menu-item" data-cat="all">
                    <i class="fas fa-th"></i> Todos los productos
                </div>
            </div>
        </div>

        <!-- Buscador -->
        <div class="header-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="header-search-input" placeholder="Buscar producto...">
        </div>

        <!-- Acciones -->
        <div class="header-actions">
            <?php if (clienteLogueado()): ?>
                <a href="<?= BASE_URL ?>/cuenta/mis-pedidos" class="user-btn">
                    <i class="fas fa-user"></i>
                    <span class="user-btn-label"><?= htmlspecialchars(clienteNombre()) ?></span>
                </a>
                <a href="<?= BASE_URL ?>/cuenta/puntos" class="user-btn" title="Mis puntos">
                    <i class="fas fa-star"></i>
                </a>
                <a href="<?= BASE_URL ?>/cuenta/logout" class="user-btn" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/cuenta/login" class="user-btn">
                    <i class="fas fa-user"></i> <span class="user-btn-label">Ingresar</span>
                </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/carrito" class="cart-btn" title="Carrito" aria-label="Carrito">
                <i class="fas fa-shopping-cart"></i>
                <span class="cart-badge" id="cart-badge">0</span>
            </a>
        </div>
    </div>

    <!-- Navegación institucional: en celulares se desplaza horizontalmente -->
    <nav class="site-subnav" aria-label="Secciones">
        <div class="site-subnav-inner">
            <?php foreach ($navItems as [$page, $href, $label]): ?>
            <a href="<?= $href ?>" class="<?= $currentPage === $page ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>

<script>
(function() {
    // ── Menú de categorías (compartido en todas las páginas) ──
    const catWrap     = document.getElementById('cat-menu-wrap');
    const catToggle   = document.getElementById('cat-toggle');
    const catDropdown = document.getElementById('cat-dropdown');

    catToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        catWrap.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
        if (!catWrap.contains(e.target)) catWrap.classList.remove('open');
    });

    function irACategoria(id, nombre, el) {
        // Si estamos en catalogo.php, filtrarCategoriaInicio() ya está definida y filtra sin recargar.
        if (typeof window.filtrarCategoriaInicio === 'function') {
            window.filtrarCategoriaInicio(id, nombre, el);
        } else {
            window.location.href = window.BASE_URL + '/catalogo?categoria=' + encodeURIComponent(id);
        }
        catWrap.classList.remove('open');
    }

    catDropdown.addEventListener('click', (e) => {
        const item = e.target.closest('.cat-menu-item');
        if (!item) return;
        irACategoria(item.dataset.cat, item.textContent.trim(), item);
    });

    fetch(window.BASE_URL + '/api/index.php?action=categorias')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            data.data.forEach(cat => {
                const item = document.createElement('div');
                item.className = 'cat-menu-item';
                item.dataset.cat = cat.id;
                item.innerHTML = `<i class="fas fa-tag"></i> ${cat.nombre}`;
                catDropdown.appendChild(item);
            });
            document.dispatchEvent(new CustomEvent('categoriasListas'));
        })
        .catch(() => {});

    // ── Buscador (compartido en todas las páginas) ──
    const searchInput = document.getElementById('header-search-input');
    let searchTimeout;

    searchInput.addEventListener('input', () => {
        if (typeof window.filtrarBusquedaInicio !== 'function') return;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => window.filtrarBusquedaInicio(searchInput.value), 250);
    });
    searchInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (typeof window.filtrarBusquedaInicio !== 'function') {
            window.location.href = window.BASE_URL + '/catalogo?q=' + encodeURIComponent(searchInput.value);
        }
    });
})();
</script>
<script src="<?= BASE_URL ?>/assets/js/carrito.js"></script>
