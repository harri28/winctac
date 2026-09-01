<?php
$pageTitle   = 'Tienda Online';
$currentPage = 'home';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

// Cargar banners activos para el slider
$banners = [];
try {
    $bannersStmt = getDB()->prepare("SELECT * FROM banners WHERE activo = TRUE AND tienda_id = ? AND (imagen_path IS NOT NULL OR imagen_url IS NOT NULL) ORDER BY orden ASC, id ASC");
    $bannersStmt->execute([TIENDA_ID]);
    $banners = $bannersStmt->fetchAll();
} catch (Exception $e) {}
?>

<!-- HERO / SLIDER DINÁMICO -->
<div class="hero-slider" id="hero-slider">

    <?php if ($banners): ?>
    <div class="slider-track" id="slider-track">
        <?php foreach ($banners as $b): ?>
        <div class="slider-slide">
            <?php
            $imgSrc = '';
            if (!empty($b['imagen_path'])) {
                $imgSrc = UPLOADS_URL . '/' . htmlspecialchars($b['imagen_path']);
            } elseif (!empty($b['imagen_url'])) {
                $imgSrc = htmlspecialchars($b['imagen_url']);
            }
            ?>
            <?php if ($b['enlace']): ?><a href="<?= htmlspecialchars($b['enlace']) ?>" style="display:block;height:100%"><?php endif; ?>
            <?php if ($imgSrc): ?>
            <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($b['titulo']) ?>">
            <?php endif; ?>
            <?php if ($b['titulo'] || $b['subtitulo']): ?>
            <div class="slider-slide-overlay">
                <?php if ($b['titulo']): ?><div class="slider-slide-title"><?= htmlspecialchars($b['titulo']) ?></div><?php endif; ?>
                <?php if ($b['subtitulo']): ?><div class="slider-slide-sub"><?= htmlspecialchars($b['subtitulo']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($b['enlace']): ?></a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="slider-arrows">
        <button class="slider-arrow" id="slider-prev"><i class="fas fa-chevron-left"></i></button>
        <button class="slider-arrow" id="slider-next"><i class="fas fa-chevron-right"></i></button>
    </div>

    <?php else: ?>
    <!-- 4 slots vacíos — agregar banners desde el panel admin -->
    <div style="position:absolute;inset:0;display:flex;gap:3px;padding:3px;background:#1e293b">
        <?php for ($i = 0; $i < 4; $i++): ?>
        <div style="flex:1;border:2px dashed rgba(255,255,255,.25);border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(255,255,255,.45);gap:6px">
            <i class="fas fa-image" style="font-size:1.6rem"></i>
            <span style="font-size:.72rem;font-weight:500">Banner <?= $i + 1 ?></span>
        </div>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

</div>

<?php if ($banners): ?>
<script>
(function() {
    const slider  = document.getElementById('hero-slider');
    const track   = document.getElementById('slider-track');
    const VISIBLE = 4;
    const N       = <?= count($banners) ?>;

    // Necesitamos al menos N slides originales + VISIBLE clones al final
    // para que al llegar al último el primero ya esté listo a la derecha
    const orig = [...track.children];
    while (track.children.length < N + VISIBLE) {
        orig.forEach(s => track.appendChild(s.cloneNode(true)));
    }

    let cur = 0, timer;

    function W() { return slider.offsetWidth / VISIBLE; }

    function setSizes() {
        const w = W() + 'px';
        [...track.children].forEach(s => { s.style.minWidth = w; s.style.width = w; });
    }

    // Salto sin animación — se usa después de que la transición llega a los clones
    function jump(n) {
        cur = n;
        track.style.transition = 'none';
        track.style.transform  = `translateX(-${cur * W()}px)`;
        track.offsetHeight; // forzar reflow
        requestAnimationFrame(() => requestAnimationFrame(() => {
            track.style.transition = '';
        }));
    }

    // Deslizar con animación; si llegamos al territorio de clones, resetear silenciosamente
    function slide(n) {
        cur = n;
        track.style.transform = `translateX(-${cur * W()}px)`;
        // Cuando cur >= N estamos viendo clones del inicio → jump de vuelta
        if (cur >= N) setTimeout(() => jump(cur - N), 580);
    }

    function start() {
        clearInterval(timer);
        timer = setInterval(() => slide(cur + 1), 1500);
    }

    setSizes();

    document.getElementById('slider-next')?.addEventListener('click', () => { slide(cur + 1); start(); });
    document.getElementById('slider-prev')?.addEventListener('click', () => {
        if (cur <= 0) { jump(N); setTimeout(() => slide(N - 1), 20); }
        else slide(cur - 1);
        start();
    });
    slider.addEventListener('mouseenter', () => clearInterval(timer));
    slider.addEventListener('mouseleave', start);
    window.addEventListener('resize', () => { setSizes(); jump(cur); });

    start();
})();
</script>
<?php endif; ?>

<!-- PRODUCTOS -->
<div class="page-wrapper">

    <div class="container">
        <div class="section-header">
            <h2 class="section-title" id="section-title">Todos los productos</h2>
        </div>

        <div id="products-container">
            <div class="loading-overlay">
                <div class="spinner"></div> Cargando productos...
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
let allProductos = [];
let categoriaActiva = 'all';

// Si llegamos desde otra página con ?categoria= o ?q=, aplicarlo de entrada
const _params = new URLSearchParams(window.location.search);
if (_params.get('categoria')) categoriaActiva = _params.get('categoria');
if (_params.get('q')) document.getElementById('header-search-input').value = _params.get('q');

// Resalta el ítem correcto en el dropdown de categorías (del header compartido)
function marcarCategoriaActiva() {
    document.querySelectorAll('.cat-menu-item').forEach(item => {
        const activo = item.dataset.cat === categoriaActiva;
        item.classList.toggle('active', activo);
        if (activo) {
            document.getElementById('section-title').textContent =
                categoriaActiva === 'all' ? 'Todos los productos' : item.textContent.trim();
        }
    });
}
marcarCategoriaActiva();
document.addEventListener('categoriasListas', marcarCategoriaActiva);

// Hooks que el header (compartido en todas las páginas) llama cuando estamos aquí,
// para filtrar sin recargar en vez de navegar a index.php?categoria=/?q=
window.filtrarCategoriaInicio = function(id, nombre, el) {
    categoriaActiva = id;
    document.querySelectorAll('.cat-menu-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    document.getElementById('section-title').textContent = id === 'all' ? 'Todos los productos' : nombre;
    renderProductos();
};

window.filtrarBusquedaInicio = function() {
    renderProductos();
};

async function cargarProductos() {
    document.getElementById('products-container').innerHTML =
        '<div class="loading-overlay"><div class="spinner"></div> Cargando productos...</div>';
    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=productos');
        const data = await res.json();
        if (!data.success) throw new Error();
        allProductos = data.data;
        renderProductos();
    } catch(e) {
        document.getElementById('products-container').innerHTML =
            '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><h3>Error al cargar productos</h3><p>Intenta de nuevo más tarde.</p></div>';
    }
}

function renderProductos() {
    const q = (document.getElementById('header-search-input').value || '').toLowerCase();
    let lista = allProductos.filter(p => {
        const catOk = categoriaActiva === 'all' || p.categoria_id == categoriaActiva;
        const qOk = !q
            || p.nombre.toLowerCase().includes(q)
            || (p.codigo||'').toLowerCase().includes(q)
            || (p.etiquetas||[]).some(t => t.toLowerCase().includes(q));
        return catOk && qOk;
    });

    const container = document.getElementById('products-container');
    if (!lista.length) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><h3>Sin productos</h3><p>No se encontraron productos con los filtros seleccionados.</p></div>';
        return;
    }

    container.innerHTML = `<div class="products-grid">${lista.map(p => cardHTML(p)).join('')}</div>`;
}

function cardHTML(p) {
    const stock = parseInt(p.stock || 0);
    const stockLabel = stock <= 0 ? '<span class="product-stock out">Sin stock</span>'
        : stock <= 5 ? `<span class="product-stock low">Últimas ${stock} unidades</span>`
        : `<span class="product-stock">${stock} disponibles</span>`;

    const imgHTML = p.imagen
        ? `<img class="product-img" src="${p.imagen}" alt="${p.nombre}" onerror="this.parentElement.innerHTML='<div class=\'product-img-placeholder\'><i class=\'fas fa-box\'></i></div>'">`
        : `<div class="product-img-placeholder"><i class="fas fa-box"></i></div>`;

    return `
    <div class="product-card" style="cursor:pointer" onclick="irAProducto(event,${p.id})">
        ${imgHTML}
        <div class="product-body">
            <div class="product-category">${p.categoria || ''}</div>
            <div class="product-name">${p.nombre}</div>
            <div class="product-price">S/ ${parseFloat(p.precio).toFixed(2)}</div>
            ${stockLabel}
        </div>
        <div class="product-footer">
            <button class="btn-add-cart" onclick="agregarAlCarrito(${p.id})"
                ${stock <= 0 ? 'disabled' : ''}>
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </div>`;
}

function irAProducto(e, id) {
    if (e.target.closest('.btn-add-cart')) return;
    window.location.href = window.BASE_URL + '/producto.php?id=' + id;
}

function agregarAlCarrito(id) {
    const p = allProductos.find(x => x.id === id);
    if (!p) return;
    Carrito.agregar({
        id:     p.id,
        nombre: p.nombre,
        precio: parseFloat(p.precio),
        imagen: p.imagen || '',
        stock:  parseInt(p.stock || 0)
    });
}

cargarProductos();
</script>
