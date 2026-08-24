<?php
$pageTitle   = 'Nosotros';
$currentPage = 'nosotros';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

$cfg      = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$pdo      = getDB();

$numProductos  = (int) $pdo->query("SELECT COUNT(*) FROM productos WHERE activo = TRUE")->fetchColumn();
$numCategorias = (int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE activo = TRUE")->fetchColumn();
$numEnvios     = (int) $pdo->query("SELECT COUNT(*) FROM empresas_envio WHERE activo = TRUE")->fetchColumn();

$heroImg = (!empty($cfg['portal_hero_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['portal_hero_path']))
    ? UPLOADS_URL . '/' . htmlspecialchars($cfg['portal_hero_path'])
    : null;
?>

<section class="corp-hero" <?= $heroImg ? 'style="background-image:url(\'' . $heroImg . '\')"' : '' ?>>
    <div class="corp-hero-overlay">
        <h1><?= $shopName ?></h1>
        <p>Calidad, confianza y cercanía en cada compra</p>
        <a href="<?= BASE_URL ?>/" class="btn btn-primary btn-lg">
            <i class="fas fa-store"></i> Ver catálogo
        </a>
    </div>
</section>

<div class="page-wrapper">
<div class="container" style="max-width:1000px">

    <div class="corp-values">
        <div class="corp-value-card">
            <i class="fas fa-medal"></i>
            <h3>Calidad garantizada</h3>
            <p>Seleccionamos cuidadosamente cada producto de nuestro catálogo para ofrecerte siempre lo mejor.</p>
        </div>
        <div class="corp-value-card">
            <i class="fas fa-truck-fast"></i>
            <h3>Entrega confiable</h3>
            <p>Coordinamos el envío con empresas de confianza para que tu pedido llegue seguro y a tiempo.</p>
        </div>
        <div class="corp-value-card">
            <i class="fas fa-shield-halved"></i>
            <h3>Compra segura</h3>
            <p>Tu información y tus pagos están protegidos en cada paso del proceso de compra.</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:32px">
        <div class="card">
            <div class="card-title"><i class="fas fa-eye"></i> Visión</div>
            <p style="font-size:.9rem;color:var(--text-muted);line-height:1.7">
                Ser la tienda online de referencia para nuestros clientes, reconocida por su confiabilidad,
                variedad y la calidad de su servicio.
            </p>
        </div>
        <div class="card">
            <div class="card-title"><i class="fas fa-bullseye"></i> Misión</div>
            <p style="font-size:.9rem;color:var(--text-muted);line-height:1.7">
                Brindar a cada cliente una experiencia de compra simple, segura y satisfactoria, todos los días.
            </p>
        </div>
    </div>

    <div class="corp-stats">
        <div class="corp-stat">
            <div class="corp-stat-num"><?= $numProductos ?>+</div>
            <div class="corp-stat-label">Productos</div>
        </div>
        <div class="corp-stat">
            <div class="corp-stat-num"><?= $numCategorias ?></div>
            <div class="corp-stat-label">Categorías</div>
        </div>
        <div class="corp-stat">
            <div class="corp-stat-num"><?= $numEnvios ?></div>
            <div class="corp-stat-label">Empresas de envío</div>
        </div>
    </div>

    <div class="corp-cta">
        <h2>¿Listo para comprar?</h2>
        <p>Explora nuestro catálogo y encuentra lo que necesitas.</p>
        <a href="<?= BASE_URL ?>/" class="btn btn-primary btn-lg">
            <i class="fas fa-arrow-right"></i> Ir al catálogo
        </a>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
