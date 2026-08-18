<?php
// ============================================================
// PÁGINA DE PRODUCTO INDIVIDUAL
// ============================================================
require_once __DIR__ . '/config/app.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/'); exit; }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_cliente.php';

$cfg = getShopConfig();
$pdo = getDB();

$imgBase = BASE_URL . '/uploads/productos/';
$stmt = $pdo->prepare("
    SELECT p.*, c.nombre AS categoria,
           CASE WHEN p.imagen_path <> '' THEN '$imgBase' || p.imagen_path ELSE NULL END AS imagen
    FROM productos p
    LEFT JOIN categorias c ON c.id = p.categoria_id
    WHERE p.id = ? AND p.activo = TRUE
");
$stmt->execute([$id]);
$producto = $stmt->fetch();

if (!$producto) { header('Location: ' . BASE_URL . '/'); exit; }

$nombre    = $producto['nombre'];
$precio    = floatval($producto['precio']);
$stock     = intval($producto['stock'] ?? 0);
$imagen    = $producto['imagen'] ?? '';
$categoria = $producto['categoria'] ?? '';
$catId     = $producto['categoria_id'] ?? '';
$desc      = $producto['descripcion'] ?? $producto['detalle'] ?? '';
$codigo    = $producto['codigo'] ?? '';
$shopNom   = $cfg['nombre_tienda'] ?? 'Mi Tienda Online';

$productUrl = BASE_URL . '/producto.php?id=' . $id;
$waText     = urlencode("🛍️ *" . $nombre . "*\nS/ " . number_format($precio, 2) . "\n👉 " . $productUrl);

$ogData = [
    'title'       => $nombre . ' — ' . $shopNom,
    'description' => $desc ?: 'Precio: S/ ' . number_format($precio, 2) . ($categoria ? ' · ' . $categoria : ''),
    'image'       => $imagen,
    'url'         => $productUrl,
    'type'        => 'product',
];

$pageTitle   = htmlspecialchars($nombre) . ' — ' . htmlspecialchars($shopNom);
$currentPage = 'catalogo';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb">
    <div class="breadcrumb-inner">
        <a href="<?= BASE_URL ?>/">Inicio</a>
        <i class="fas fa-chevron-right bc-sep"></i>
        <a href="<?= BASE_URL ?>/?categoria=all">Catálogo</a>
        <?php if ($categoria): ?>
        <i class="fas fa-chevron-right bc-sep"></i>
        <a href="<?= BASE_URL ?>/?categoria=<?= urlencode($catId ?: $categoria) ?>"><?= htmlspecialchars($categoria) ?></a>
        <?php endif; ?>
        <i class="fas fa-chevron-right bc-sep"></i>
        <span><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<style>
@media(max-width:600px){
    .prod-card-wrap{flex-direction:column!important}
    .prod-img-box{width:100%!important;min-width:unset!important;height:200px!important}
}
</style>

<div class="page-wrapper" style="padding-top:20px">
<div class="container">

    <!-- ── PRODUCTO ── -->
    <div class="prod-card-wrap" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;display:flex;gap:20px;align-items:flex-start;max-width:860px">

        <!-- Imagen fija 160px -->
        <div class="prod-img-box" style="width:160px;min-width:160px;height:160px;background:var(--surface-3);border-radius:var(--radius);overflow:hidden;display:flex;align-items:center;justify-content:center;border:1px solid var(--border)">
            <?php if ($imagen): ?>
            <img src="<?= htmlspecialchars($imagen) ?>"
                 alt="<?= htmlspecialchars($nombre) ?>"
                 style="width:100%;height:100%;object-fit:contain;padding:10px"
                 onerror="this.outerHTML='<i class=\'fas fa-box\' style=\'font-size:2.5rem;color:var(--text-light)\'></i>'">
            <?php else: ?>
            <i class="fas fa-box" style="font-size:2.5rem;color:var(--text-light)"></i>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:10px">

            <?php if ($categoria): ?>
            <div class="product-category"><?= htmlspecialchars($categoria) ?></div>
            <?php endif; ?>

            <h1 style="font-size:1.1rem;font-weight:700;line-height:1.35;color:var(--text);margin:0"><?= htmlspecialchars($nombre) ?></h1>

            <div style="font-size:1.6rem;font-weight:700;color:var(--primary);line-height:1">S/ <?= number_format($precio, 2) ?></div>

            <?php if ($stock <= 0): ?>
            <div class="product-stock out"><i class="fas fa-times-circle"></i> Sin stock</div>
            <?php elseif ($stock <= 5): ?>
            <div class="product-stock low"><i class="fas fa-exclamation-triangle"></i> Últimas <?= $stock ?> unidades</div>
            <?php else: ?>
            <div class="product-stock"><i class="fas fa-check-circle" style="color:var(--success)"></i> <?= $stock ?> disponibles</div>
            <?php endif; ?>

            <?php if ($desc): ?>
            <p style="font-size:.85rem;color:var(--text-muted);line-height:1.6;margin:0;border-top:1px solid var(--border);padding-top:8px"><?= nl2br(htmlspecialchars($desc)) ?></p>
            <?php endif; ?>

            <div style="border-top:1px solid var(--border);padding-top:10px;display:flex;flex-direction:column;gap:8px">

                <?php if ($stock > 0): ?>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:.82rem;color:var(--text-muted);font-weight:600">Cantidad:</span>
                    <div class="qty-control">
                        <button class="qty-btn" id="qty-minus">−</button>
                        <span class="qty-num" id="qty-val">1</span>
                        <button class="qty-btn" id="qty-plus">+</button>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn btn-primary" id="btn-agregar" style="flex:1;min-width:160px" <?= $stock <= 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-shopping-cart"></i>
                        <?= $stock <= 0 ? 'Sin stock' : 'Agregar al carrito' ?>
                    </button>
                    <a href="https://wa.me/?text=<?= $waText ?>" target="_blank" rel="noopener" class="btn-wa-share" style="flex:1;min-width:160px">
                        <i class="fab fa-whatsapp"></i> Compartir
                    </a>
                </div>

                <?php if ($codigo): ?>
                <div style="font-size:.72rem;color:var(--text-light)">SKU: <?= htmlspecialchars($codigo) ?></div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ── PRODUCTOS RELACIONADOS ── -->
    <div id="relacionados-section" style="margin-top:40px">
        <div class="section-header">
            <h2 class="section-title" id="relacionados-titulo">
                <?= $categoria ? 'Más de ' . htmlspecialchars($categoria) : 'Productos relacionados' ?>
            </h2>
            <?php if ($categoria): ?>
            <a href="<?= BASE_URL ?>/?categoria=<?= urlencode($catId ?: $categoria) ?>" class="btn btn-secondary" style="font-size:.82rem">
                Ver todos <i class="fas fa-arrow-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <div id="relacionados-grid" class="products-grid">
            <div class="loading-overlay" style="grid-column:1/-1">
                <div class="spinner"></div> Cargando...
            </div>
        </div>
    </div>

</div>
</div>

<!-- Schema.org -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'Product',
    'name'     => $nombre,
    'image'    => $imagen ?: null,
    'sku'      => $codigo ?: (string)$id,
    'offers'   => [
        '@type'         => 'Offer',
        'price'         => $precio,
        'priceCurrency' => 'PEN',
        'availability'  => $stock > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
        'url'           => $productUrl,
        'seller'        => ['@type' => 'Organization', 'name' => $shopNom],
    ],
], JSON_UNESCAPED_UNICODE) ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
const _producto = <?= json_encode([
    'id'     => $producto['id'],
    'nombre' => $nombre,
    'precio' => $precio,
    'imagen' => $imagen,
    'stock'  => $stock,
]) ?>;
const _catActual = <?= json_encode($categoria) ?>;
const _idActual  = <?= $id ?>;
let qty = 1;
const maxQty = <?= $stock ?>;

// ── Cantidad ──
document.getElementById('qty-minus')?.addEventListener('click', () => {
    if (qty > 1) { qty--; document.getElementById('qty-val').textContent = qty; }
});
document.getElementById('qty-plus')?.addEventListener('click', () => {
    if (!maxQty || qty < maxQty) { qty++; document.getElementById('qty-val').textContent = qty; }
});

// ── Agregar al carrito ──
document.getElementById('btn-agregar')?.addEventListener('click', () => {
    const items = Carrito.get();
    const idx   = items.findIndex(i => i.id === _producto.id);
    if (idx >= 0) {
        items[idx].cantidad = Math.min(items[idx].cantidad + qty, maxQty || 999);
    } else {
        items.push({ ..._producto, cantidad: qty });
    }
    Carrito.save(items);
    const label = qty > 1 ? `${qty} × ${_producto.nombre}` : _producto.nombre;
    showToast(label + ' agregado al carrito', 'success');
});

// ── Productos relacionados ──
function cardHTML(p) {
    const stock = parseInt(p.stock || 0);
    const stockLabel = stock <= 0
        ? '<span class="product-stock out">Sin stock</span>'
        : stock <= 5
            ? `<span class="product-stock low">Últimas ${stock} uds.</span>`
            : `<span class="product-stock">${stock} disponibles</span>`;
    const imgHTML = p.imagen
        ? `<img class="product-img" src="${p.imagen}" alt="${p.nombre}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=\'product-img-placeholder\'><i class=\'fas fa-box\'></i></div>'">`
        : `<div class="product-img-placeholder"><i class="fas fa-box"></i></div>`;
    return `
    <div class="product-card" style="cursor:pointer" onclick="if(!event.target.closest('.btn-add-cart')) window.location.href=window.BASE_URL + '/producto.php?id=${p.id}'">
        ${imgHTML}
        <div class="product-body">
            <div class="product-category">${p.categoria || ''}</div>
            <div class="product-name">${p.nombre}</div>
            <div class="product-price">S/ ${parseFloat(p.precio).toFixed(2)}</div>
            ${stockLabel}
        </div>
        <div class="product-footer">
            <button class="btn-add-cart" onclick="addRel(${p.id})" ${stock <= 0 ? 'disabled' : ''}>
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </div>`;
}

let _allProductos = [];

function addRel(id) {
    const p = _allProductos.find(x => x.id === id);
    if (!p) return;
    Carrito.agregar({ id: p.id, nombre: p.nombre, precio: parseFloat(p.precio), imagen: p.imagen || '', stock: parseInt(p.stock || 0) });
}

async function cargarRelacionados() {
    try {
        const res  = await fetch(window.BASE_URL + '/api/index.php?action=productos');
        const data = await res.json();
        if (!data.success) throw new Error();

        _allProductos = data.data;

        // Misma categoría, excluir producto actual
        let rel = _allProductos.filter(p => p.id !== _idActual && (p.categoria || '') === _catActual);

        // Si hay menos de 4, rellenar con otros productos
        if (rel.length < 4) {
            const otros = _allProductos.filter(p => p.id !== _idActual && !rel.find(r => r.id === p.id));
            rel = [...rel, ...otros.slice(0, 10 - rel.length)];
        } else {
            rel = rel.slice(0, 10);
        }

        const grid = document.getElementById('relacionados-grid');
        if (!rel.length) {
            document.getElementById('relacionados-section').style.display = 'none';
            return;
        }
        grid.innerHTML = rel.map(p => cardHTML(p)).join('');
    } catch(e) {
        document.getElementById('relacionados-section').style.display = 'none';
    }
}

cargarRelacionados();
</script>
