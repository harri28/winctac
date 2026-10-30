<?php
// ============================================================
// INICIO — Portada corporativa: presentación institucional y
// accesos a catálogo, fidelización, WhatsApp y redes sociales
// ============================================================
$pageTitle   = 'Inicio';
$currentPage = 'home';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

$heroImg = (!empty($cfg['portal_hero_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['portal_hero_path']))
    ? UPLOADS_URL . '/' . htmlspecialchars($cfg['portal_hero_path'])
    : null;
$lema = trim($cfg['descripcion'] ?? '') ?: 'Calidad, confianza y cercanía en cada compra';

// Extracto de "¿Quiénes somos?" (Portal Web) para la portada
$historia = preg_replace('#<(br|/?div|/?p)[^>]*>#i', ' ', renderRichText($cfg['portal_historia'] ?? ''));
$historia = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($historia), ENT_QUOTES, 'UTF-8')));
if (mb_strlen($historia) > 320) {
    $historia = rtrim(mb_substr($historia, 0, 320), " ,.;") . '…';
}

$puntosActivo = !empty($cfg['puntos_activo']);
$puntosPorSol = (float) ($cfg['puntos_por_sol'] ?? 1);
$waUrl  = whatsappUrl($cfg, 'Hola ' . ($cfg['nombre_tienda'] ?? '') . ', quisiera hacer una consulta.');
$redes  = redesSociales($cfg);
?>

<!-- PRESENTACIÓN INSTITUCIONAL -->
<section class="corp-hero corp-hero--home" <?= $heroImg ? 'style="background-image:url(\'' . $heroImg . '\')"' : '' ?>>
    <div class="corp-hero-overlay">
        <h1><?= $shopName ?></h1>
        <p><?= htmlspecialchars($lema) ?></p>
        <div class="corp-hero-actions">
            <a href="<?= BASE_URL ?>/catalogo" class="btn btn-primary btn-lg"><i class="fas fa-th-large"></i> Ver catálogo</a>
            <a href="<?= BASE_URL ?>/nosotros" class="btn btn-lg corp-hero-btn-light"><i class="fas fa-building"></i> Conócenos</a>
        </div>
    </div>
</section>

<div class="container">
    <!-- ACCESOS PRINCIPALES -->
    <div class="home-quick-links">
        <a href="<?= BASE_URL ?>/catalogo" class="home-quick-link">
            <span class="home-quick-icon"><i class="fas fa-th-large"></i></span>
            <span><strong>Catálogo virtual</strong><small>Productos por categoría, con precio y fotos</small></span>
        </a>
        <?php if ($puntosActivo): ?>
        <a href="<?= BASE_URL ?>/cuenta/puntos" class="home-quick-link">
            <span class="home-quick-icon"><i class="fas fa-star"></i></span>
            <span><strong>Programa de puntos</strong><small>Acumula puntos y canjéalos por beneficios</small></span>
        </a>
        <?php endif; ?>
        <?php if ($waUrl): ?>
        <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener" class="home-quick-link home-quick-link--wa">
            <span class="home-quick-icon"><i class="fab fa-whatsapp"></i></span>
            <span><strong>WhatsApp</strong><small>Atención directa con un asesor</small></span>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/contacto" class="home-quick-link">
            <span class="home-quick-icon"><i class="fas fa-headset"></i></span>
            <span><strong>Atención al cliente</strong><small>Formulario de contacto, teléfono y correo</small></span>
        </a>
    </div>

    <!-- QUIÉNES SOMOS -->
    <section class="home-about">
        <div>
            <h2 class="section-title"><i class="fas fa-building"></i> ¿Quiénes somos?</h2>
            <?php if ($historia !== ''): ?>
            <p><?= htmlspecialchars($historia) ?></p>
            <?php endif; ?>
            <div class="home-about-links">
                <a href="<?= BASE_URL ?>/nosotros" class="btn btn-outline"><i class="fas fa-arrow-right"></i> Misión, visión e historia</a>
                <a href="<?= BASE_URL ?>/trabaja-con-nosotros" class="btn btn-secondary"><i class="fas fa-briefcase"></i> Trabaja con nosotros</a>
            </div>
        </div>
        <?php if ($redes): ?>
        <div class="home-about-social">
            <div class="footer-col-title" style="color:var(--text)">Síguenos en redes</div>
            <div class="social-links social-links--dark">
                <?php foreach ($redes as $red): ?>
                <a href="<?= htmlspecialchars($red['url']) ?>" target="_blank" rel="noopener" aria-label="<?= $red['label'] ?>" title="<?= $red['label'] ?>"><i class="fab <?= $red['icon'] ?>"></i></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<div class="page-wrapper">
    <div class="container">

        <!-- PROMOCIONES -->
        <div class="home-section" id="promo-section" style="display:none">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-percentage"></i> <span id="promo-titulo">Promociones</span></h2>
            </div>
            <div id="promo-container" class="products-grid"></div>
        </div>

        <!-- NUEVOS PRODUCTOS -->
        <div class="home-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-box-open"></i> Productos destacados</h2>
                <a href="<?= BASE_URL ?>/catalogo" class="ver-todos-link">Ver catálogo completo <i class="fas fa-arrow-right"></i></a>
            </div>
            <div id="nuevos-container" class="products-grid">
                <div class="loading-overlay">
                    <div class="spinner"></div> Cargando productos...
                </div>
            </div>
        </div>

        <?php if ($puntosActivo): ?>
        <!-- PROGRAMA DE FIDELIZACIÓN -->
        <section class="home-section home-points">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-star"></i> Programa de puntos</h2>
            </div>
            <div class="home-points-steps">
                <div class="home-points-step">
                    <span class="home-points-num">1</span>
                    <strong>Regístrate</strong>
                    <small>Crea tu cuenta gratis con tus datos y teléfono.</small>
                </div>
                <div class="home-points-step">
                    <span class="home-points-num">2</span>
                    <strong>Compra y acumula</strong>
                    <small>Ganas <?= rtrim(rtrim(number_format($puntosPorSol, 2), '0'), '.') ?> punto(s) por cada S/ 1 de tus compras confirmadas.</small>
                </div>
                <div class="home-points-step">
                    <span class="home-points-num">3</span>
                    <strong>Canjea beneficios</strong>
                    <small>Consulta tu saldo en línea y canjéalo por cupones de descuento.</small>
                </div>
            </div>
            <div class="home-about-links" style="justify-content:center">
                <?php if (clienteLogueado()): ?>
                <a href="<?= BASE_URL ?>/cuenta/puntos" class="btn btn-primary"><i class="fas fa-star"></i> Ver mi saldo de puntos</a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>/cuenta/registro" class="btn btn-primary"><i class="fas fa-user-plus"></i> Afíliate gratis</a>
                <a href="<?= BASE_URL ?>/cuenta/login?redirect=<?= urlencode(BASE_URL . '/cuenta/puntos') ?>" class="btn btn-secondary"><i class="fas fa-right-to-bracket"></i> Ya tengo cuenta</a>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ATENCIÓN AL CLIENTE -->
        <section class="corp-cta" style="margin-top:40px">
            <h2>¿Tienes alguna consulta?</h2>
            <p>Nuestro equipo te atiende por WhatsApp, teléfono, correo o mediante nuestro formulario de contacto.</p>
            <div class="home-about-links" style="justify-content:center">
                <?php if ($waUrl): ?>
                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener" class="btn btn-lg corp-cta-btn"><i class="fab fa-whatsapp"></i> Escríbenos por WhatsApp</a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/contacto" class="btn btn-lg corp-cta-btn"><i class="fas fa-paper-plane"></i> Formulario de contacto</a>
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
const NUEVOS_LIMITE = 4;

async function cargarNuevosProductos() {
    const container = document.getElementById('nuevos-container');
    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=productos');
        const data = await res.json();
        if (!data.success) throw new Error();
        const nuevos = [...data.data]
            .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
            .slice(0, NUEVOS_LIMITE);
        if (!nuevos.length) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><h3>Sin productos</h3></div>';
            return;
        }
        container.innerHTML = nuevos.map(p => cardHTML(p)).join('');
    } catch (e) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><h3>Error al cargar productos</h3></div>';
    }
}

async function cargarPromociones() {
    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=promociones');
        const data = await res.json();
        if (!data.success || !data.data.length) return;
        document.getElementById('promo-section').style.display = '';
        document.getElementById('promo-container').innerHTML = data.data.map(p => cardHTML(p)).join('');
    } catch (e) {}
}

function cardHTML(p) {
    const stock = parseInt(p.stock || 0);
    const stockLabel = stock <= 0 ? '<span class="product-stock out">Sin stock</span>'
        : !window.MOSTRAR_STOCK ? '<span class="product-stock">Disponible</span>'
        : stock <= 5 ? `<span class="product-stock low">Últimas ${stock} unidades</span>`
        : `<span class="product-stock">${stock} disponibles</span>`;

    const imgHTML = p.imagen
        ? `<img class="product-img" src="${p.imagen}" alt="${p.nombre}" onerror="this.parentElement.innerHTML='<div class=\'product-img-placeholder\'><i class=\'fas fa-box\'></i></div>'">`
        : `<div class="product-img-placeholder"><i class="fas fa-box"></i></div>`;

    return `
    <div class="product-card" style="cursor:pointer" onclick="irAProducto(event,${p.id},'${p.nombre.replace(/'/g,"\\'")}')">
        ${imgHTML}
        <div class="product-body">
            <div class="product-category">${p.categoria || ''}</div>
            <div class="product-name">${p.nombre}</div>
            <div class="product-price">S/ ${parseFloat(p.precio).toFixed(2)}</div>
            ${stockLabel}
        </div>
        <div class="product-footer">
            <button class="btn-add-cart" onclick="agregarAlCarrito(event,${p.id},'${p.nombre.replace(/'/g,"\\'")}',${p.precio},'${(p.imagen||'').replace(/'/g,"\\'")}',${stock})"
                ${stock <= 0 ? 'disabled' : ''}>
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </div>`;
}

function irAProducto(e, id, nombre) {
    if (e.target.closest('.btn-add-cart')) return;
    window.location.href = urlProducto(id, nombre);
}

function agregarAlCarrito(e, id, nombre, precio, imagen, stock) {
    e.stopPropagation();
    Carrito.agregar({ id, nombre, precio: parseFloat(precio), imagen: imagen || '', stock: parseInt(stock || 0) });
}

cargarNuevosProductos();
cargarPromociones();
</script>
