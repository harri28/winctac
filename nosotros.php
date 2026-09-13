<?php
$pageTitle   = 'Nosotros';
$currentPage = 'nosotros';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

$cfg      = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$pdo      = getDB();

$numProdStmt = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE activo = TRUE AND tienda_id = ?");
$numProdStmt->execute([TIENDA_ID]);
$numProductos = (int) $numProdStmt->fetchColumn();

$numCatStmt = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE activo = TRUE AND tienda_id = ?");
$numCatStmt->execute([TIENDA_ID]);
$numCategorias = (int) $numCatStmt->fetchColumn();

// Si el admin no fijó un valor propio en Portal Web, se usa el conteo real.
$statProductos = ($cfg['portal_stat_productos'] ?? '') !== '' ? $cfg['portal_stat_productos'] : ($numProductos . '+');
$statCategorias = ($cfg['portal_stat_categorias'] ?? '') !== '' ? $cfg['portal_stat_categorias'] : (string) $numCategorias;

$heroImg = (!empty($cfg['portal_hero_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['portal_hero_path']))
    ? UPLOADS_URL . '/' . htmlspecialchars($cfg['portal_hero_path'])
    : null;

$direccion = trim($cfg['portal_direccion'] ?? '');
$mapEmail  = trim($cfg['contacto_email'] ?? '');
$mapCel    = trim($cfg['contacto_celular'] ?? '');

$puntosSaldo    = 0;
$cuponesActivos = 0;
if (!empty($cfg['puntos_activo']) && clienteLogueado()) {
    $saldoStmt = $pdo->prepare('SELECT puntos_saldo FROM clientes WHERE id = ?');
    $saldoStmt->execute([clienteId()]);
    $puntosSaldo = (int) $saldoStmt->fetchColumn();

    $cuponesStmt = $pdo->prepare("
        SELECT COUNT(*) FROM puntos_cupones
        WHERE cliente_id = ? AND estado = 'activo' AND (expira_at IS NULL OR expira_at >= NOW())
    ");
    $cuponesStmt->execute([clienteId()]);
    $cuponesActivos = (int) $cuponesStmt->fetchColumn();
}
?>

<section class="corp-hero" <?= $heroImg ? 'style="background-image:url(\'' . $heroImg . '\')"' : '' ?>>
    <div class="corp-hero-overlay">
        <h1><?= $shopName ?></h1>
        <p>Calidad, confianza y cercanía en cada compra</p>
    </div>
    <a href="<?= BASE_URL ?>/" class="corp-hero-cta">
        <i class="fas fa-store"></i> Ver catálogo
    </a>
</section>

<div class="page-wrapper">
<div class="container" style="max-width:1000px">

    <div class="corp-info-grid">
        <div class="card corp-info-card">
            <div class="card-title"><i class="fas fa-bullseye"></i> Misión</div>
            <div style="font-size:.9rem;color:var(--text-muted);line-height:1.7"><?= renderRichText($cfg['portal_mision'] ?? '') ?></div>
        </div>
        <div class="card corp-info-card">
            <div class="card-title"><i class="fas fa-eye"></i> Visión</div>
            <div style="font-size:.9rem;color:var(--text-muted);line-height:1.7"><?= renderRichText($cfg['portal_vision'] ?? '') ?></div>
        </div>
    </div>

    <div class="card corp-info-card" style="margin-top:20px">
        <div class="card-title"><i class="fas fa-book-open"></i> ¿Quiénes somos?</div>
        <div style="font-size:.9rem;color:var(--text-muted);line-height:1.7"><?= renderRichText($cfg['portal_historia'] ?? '') ?></div>
    </div>

    <div class="corp-action-cards">
        <div class="corp-action-card">
            <div class="corp-action-icon"><i class="fas fa-briefcase"></i></div>
            <h3>Trabaja con nosotros</h3>
            <p>Estamos creciendo y buscamos gente comprometida. Postula dejando tus datos y tu CV.</p>
            <a href="<?= BASE_URL ?>/trabaja-con-nosotros.php" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right"></i> Postula aquí
            </a>
        </div>
        <?php if (!empty($cfg['puntos_activo'])): ?>
        <div class="corp-action-card">
            <div class="corp-action-icon"><i class="fas fa-ticket"></i></div>
            <h3>Mis cupones y puntos</h3>
            <?php if (clienteLogueado()): ?>
            <p>Tienes <strong><?= $puntosSaldo ?> puntos</strong><?= $cuponesActivos ? ' y ' . $cuponesActivos . ' cupón(es) activo(s)' : '' ?> acumulados.</p>
            <?php else: ?>
            <p>Inicia sesión para ver tu saldo de puntos y tus cupones disponibles.</p>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/cuenta/puntos.php" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right"></i> Ver mis puntos
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="corp-stats">
        <div class="corp-stat">
            <div class="corp-stat-num"><?= htmlspecialchars($statProductos) ?></div>
            <div class="corp-stat-label">Productos</div>
        </div>
        <div class="corp-stat">
            <div class="corp-stat-num"><?= htmlspecialchars($statCategorias) ?></div>
            <div class="corp-stat-label">Categorías</div>
        </div>
    </div>

    <?php if ($direccion): ?>
    <div class="corp-map-section">
        <div class="corp-map-frame">
            <iframe
                src="https://www.google.com/maps?q=<?= urlencode($direccion) ?>&output=embed"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                allowfullscreen></iframe>
        </div>
        <div class="corp-map-info">
            <h2><i class="fas fa-location-dot"></i> Encuéntranos</h2>
            <p class="corp-map-address"><?= htmlspecialchars($direccion) ?></p>
            <?php if ($mapCel): ?>
            <p><i class="fas fa-phone"></i> <?= htmlspecialchars($mapCel) ?></p>
            <?php endif; ?>
            <?php if ($mapEmail): ?>
            <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($mapEmail) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="corp-cta">
        <h2>¿Listo para comprar?</h2>
        <p>Explora nuestro catálogo y encuentra lo que necesitas.</p>
        <a href="<?= BASE_URL ?>/" class="btn btn-lg corp-cta-btn">
            <i class="fas fa-arrow-right"></i> Ir al catálogo
        </a>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
