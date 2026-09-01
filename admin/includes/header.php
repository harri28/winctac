<?php
// ============================================================
// ADMIN HEADER — Selvadigital
// ============================================================
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

function adminLogueado(): bool { return !empty($_SESSION['admin_id']); }
function requireAdmin(): void {
    if (!adminLogueado()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
    // Multi-tenant: la sesión puede venir de otro hostname/tienda (ej. si el
    // navegador conserva una cookie de otra tienda) — se revalida que el
    // admin logueado en verdad pertenezca a TIENDA_ID, no solo que exista sesión.
    $stmt = getDB()->prepare('SELECT 1 FROM admin_usuarios WHERE id = ? AND tienda_id = ?');
    $stmt->execute([$_SESSION['admin_id'], TIENDA_ID]);
    if (!$stmt->fetchColumn()) {
        session_destroy();
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

// Auth ANTES de emitir cualquier HTML
requireAdmin();

$adminPage = $adminPage ?? '';
$cfg       = getShopConfig();
$shopName  = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda');
$faviconOk = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path']);
$faviconTypes = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
$faviconType = $faviconOk ? ($faviconTypes[strtolower(pathinfo($cfg['logo_path'], PATHINFO_EXTENSION))] ?? 'image/jpeg') : '';

try {
    $pendientesCount = (int) getDB()->query("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn();
} catch (Exception $e) {
    $pendientesCount = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $adminTitle ?? 'Admin' ?> — <?= $shopName ?></title>
    <?php if ($faviconOk): ?>
    <link rel="icon" type="<?= $faviconType ?>" href="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=24">
    <script>window.BASE_URL = "<?= BASE_URL ?>";</script>
    <style>
        .admin-sidebar { display:flex; flex-direction:column; }
        .admin-nav     { flex:1; display:flex; flex-direction:column; }
        .admin-nav a[href*="logout"] { margin-top:auto; }
    </style>
</head>
<body>

<div class="admin-layout">

<!-- Barra superior solo-mobile -->
<div class="admin-mobile-topbar">
    <button type="button" class="admin-hamburger-btn" id="admin-sidebar-toggle" aria-label="Abrir menú">
        <i class="fas fa-bars"></i>
    </button>
    <span><?= $shopName ?></span>
</div>
<div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>

<!-- SIDEBAR -->
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="admin-brand">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            <?php if (!empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path'])): ?>
            <div style="width:34px;height:34px;border-radius:8px;overflow:hidden;flex-shrink:0;background:#fff;display:flex;align-items:center;justify-content:center">
                <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain">
            </div>
            <?php else: ?>
            <div style="width:34px;height:34px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0">
                <i class="fas fa-store"></i>
            </div>
            <?php endif; ?>
            <div>
                <h2 style="font-size:.92rem;line-height:1.2"><?= $shopName ?></h2>
                <p style="font-size:.7rem;color:#64748b;margin:0">Panel de administración</p>
            </div>
        </div>
        <div style="font-size:.72rem;color:#475569;padding:6px 0 0;border-top:1px solid rgba(255,255,255,.08);margin-top:6px">
            <i class="fas fa-user-circle" style="margin-right:4px"></i>
            <?= htmlspecialchars($_SESSION['admin_nombre'] ?? 'Admin') ?>
        </div>
    </div>

    <nav class="admin-nav">
        <a href="<?= BASE_URL ?>/admin/index.php" class="<?= $adminPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>

        <?php
        $adminMenu = [
            ['type' => 'group', 'icon' => 'fa-chart-line', 'label' => 'Ventas', 'children' => [
                ['page' => 'pedidos', 'href' => BASE_URL . '/admin/pedidos.php', 'icon' => 'fa-shopping-bag', 'label' => 'Pedidos', 'badge' => $pendientesCount],
            ]],
            ['type' => 'group', 'icon' => 'fa-box', 'label' => 'Productos', 'children' => [
                ['page' => 'productos', 'href' => BASE_URL . '/admin/productos.php', 'icon' => 'fa-box-open', 'label' => 'Productos'],
                ['page' => 'categorias', 'href' => BASE_URL . '/admin/categorias.php', 'icon' => 'fa-tags', 'label' => 'Categorías'],
                ['page' => 'inventario', 'href' => BASE_URL . '/admin/inventario.php', 'icon' => 'fa-warehouse', 'label' => 'Inventario'],
            ]],
            ['type' => 'group', 'icon' => 'fa-users', 'label' => 'Clientes', 'children' => [
                ['page' => 'clientes', 'href' => BASE_URL . '/admin/clientes.php', 'icon' => 'fa-user', 'label' => 'Clientes'],
            ]],
            ['type' => 'group', 'icon' => 'fa-gift', 'label' => 'Fidelización', 'children' => [
                ['page' => 'puntos',      'href' => BASE_URL . '/admin/puntos.php',      'icon' => 'fa-star',         'label' => 'Programa de puntos'],
                ['page' => 'recompensas', 'href' => BASE_URL . '/admin/recompensas.php', 'icon' => 'fa-award',        'label' => 'Beneficios / Recompensas'],
                ['page' => 'cupones',     'href' => BASE_URL . '/admin/cupones.php',     'icon' => 'fa-ticket',       'label' => 'Cupones'],
                ['page' => 'niveles',     'href' => BASE_URL . '/admin/niveles.php',     'icon' => 'fa-layer-group',  'label' => 'Niveles de cliente'],
            ]],
            ['type' => 'group', 'icon' => 'fa-bullhorn', 'label' => 'Marketing', 'children' => [
                ['page' => 'campanas',    'href' => BASE_URL . '/admin/campanas.php',    'icon' => 'fa-flag',       'label' => 'Campañas'],
                ['page' => 'promociones', 'href' => BASE_URL . '/admin/promociones.php', 'icon' => 'fa-percentage', 'label' => 'Promociones'],
            ]],
            ['type' => 'link', 'page' => 'reportes', 'href' => BASE_URL . '/admin/reportes.php', 'icon' => 'fa-chart-bar', 'label' => 'Reportes'],
            ['type' => 'group', 'icon' => 'fa-cog', 'label' => 'Configuración', 'children' => [
                ['page' => 'config',      'href' => BASE_URL . '/admin/config.php',      'icon' => 'fa-sliders-h',    'label' => 'Configuración'],
                ['page' => 'facturacion', 'href' => BASE_URL . '/admin/facturacion.php', 'icon' => 'fa-file-invoice', 'label' => 'Facturación'],
                ['page' => 'banners',     'href' => BASE_URL . '/admin/banners.php',     'icon' => 'fa-images',       'label' => 'Banners'],
                ['page' => 'portal_web',  'href' => BASE_URL . '/admin/portal-web.php',  'icon' => 'fa-globe',        'label' => 'Portal Web'],
            ]],
        ];

        foreach ($adminMenu as $item):
            if ($item['type'] === 'link'):
                $active = $adminPage === $item['page'];
        ?>
        <a href="<?= $item['href'] ?>" class="<?= $active ? 'active' : '' ?>">
            <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
        </a>
        <?php
            else:
                $hasActive = false;
                foreach ($item['children'] as $child) {
                    if ($adminPage === $child['page']) { $hasActive = true; break; }
                }
        ?>
        <div class="admin-nav-group<?= $hasActive ? ' open has-active' : '' ?>">
            <button type="button" class="admin-nav-parent">
                <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                <i class="fas fa-chevron-right chevron"></i>
            </button>
            <div class="admin-nav-children">
                <?php foreach ($item['children'] as $child):
                    $active = $adminPage === $child['page'];
                ?>
                <a href="<?= $child['href'] ?>" class="<?= $active ? 'active' : '' ?>" style="display:flex;align-items:center">
                    <i class="fas <?= $child['icon'] ?>"></i> <?= $child['label'] ?>
                    <?php if (!empty($child['badge'])): ?>
                    <span style="background:#f59e0b;color:#fff;border-radius:20px;padding:1px 8px;font-size:.68rem;font-weight:700;margin-left:auto">
                        <?= $child['badge'] ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
            endif;
        endforeach;
        ?>

        <a href="<?= BASE_URL ?>/admin/logout.php" style="color:#ef4444 !important">
            <i class="fas fa-sign-out-alt"></i> Cerrar sesión
        </a>
    </nav>
</aside>

<script>
(function() {
    const sidebar  = document.getElementById('admin-sidebar');
    const overlay  = document.getElementById('admin-sidebar-overlay');
    const toggle   = document.getElementById('admin-sidebar-toggle');

    function cerrar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    }

    toggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    });
    overlay.addEventListener('click', cerrar);

    // Menú de 2 niveles: acordeón (un grupo abierto a la vez)
    document.querySelectorAll('.admin-nav-parent').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const grupo = btn.closest('.admin-nav-group');
            const yaAbierto = grupo.classList.contains('open');
            document.querySelectorAll('.admin-nav-group.open').forEach(function(g) { g.classList.remove('open'); });
            if (!yaAbierto) grupo.classList.add('open');
        });
    });
})();
</script>

<!-- MAIN -->
<main class="admin-main">
