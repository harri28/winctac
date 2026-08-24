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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? $shopName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=24">
    <script>window.BASE_URL = "<?= BASE_URL ?>";</script>
    <?php if (!empty($ogData)): ?>
    <meta name="description" content="<?= htmlspecialchars($ogData['description'] ?? '') ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($ogData['title'] ?? $shopName) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogData['description'] ?? '') ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($ogData['url'] ?? '') ?>">
    <meta property="og:type"        content="<?= htmlspecialchars($ogData['type'] ?? 'website') ?>">
    <?php if (!empty($ogData['image'])): ?>
    <meta property="og:image"       content="<?= htmlspecialchars($ogData['image']) ?>">
    <?php endif; ?>
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
                    <i class="fas fa-th"></i> Todos
                </div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="header-nav">
            <a href="<?= BASE_URL ?>/" class="<?= $currentPage === 'home' ? 'active' : '' ?>">Inicio</a>
        </nav>

        <!-- Buscador -->
        <div class="header-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="header-search-input" placeholder="Buscar producto...">
        </div>

        <!-- Acciones -->
        <div class="header-actions">
            <?php if (clienteLogueado()): ?>
                <a href="<?= BASE_URL ?>/cuenta/mis-pedidos.php" class="user-btn">
                    <i class="fas fa-user"></i>
                    <?= htmlspecialchars(clienteNombre()) ?>
                </a>
                <a href="<?= BASE_URL ?>/cuenta/puntos.php" class="user-btn" title="Mis puntos">
                    <i class="fas fa-star"></i>
                </a>
                <a href="<?= BASE_URL ?>/cuenta/logout.php" class="user-btn" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/cuenta/login.php" class="user-btn">
                    <i class="fas fa-user"></i> Ingresar
                </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/carrito.php" class="cart-btn" title="Carrito" aria-label="Carrito">
                <i class="fas fa-shopping-cart"></i>
                <span class="cart-badge" id="cart-badge">0</span>
            </a>
        </div>
    </div>
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
        // Si estamos en la home, filtrarCategoriaInicio() ya está definida y filtra sin recargar.
        if (typeof window.filtrarCategoriaInicio === 'function') {
            window.filtrarCategoriaInicio(id, nombre, el);
        } else {
            window.location.href = window.BASE_URL + '/?categoria=' + encodeURIComponent(id);
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
            window.location.href = window.BASE_URL + '/?q=' + encodeURIComponent(searchInput.value);
        }
    });
})();
</script>
<script src="<?= BASE_URL ?>/assets/js/carrito.js"></script>
