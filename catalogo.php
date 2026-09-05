<?php
$pageTitle   = 'Catálogo';
$currentPage = 'catalogo';
require_once __DIR__ . '/includes/header.php';
?>

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
let paginaActual = 1;
const PRODUCTOS_POR_PAGINA = 20;

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
// para filtrar sin recargar en vez de navegar a catalogo.php?categoria=/?q=
window.filtrarCategoriaInicio = function(id, nombre, el) {
    categoriaActiva = id;
    document.querySelectorAll('.cat-menu-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    document.getElementById('section-title').textContent = id === 'all' ? 'Todos los productos' : nombre;
    paginaActual = 1;
    renderProductos();
};

window.filtrarBusquedaInicio = function() {
    paginaActual = 1;
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

    const totalPaginas = Math.ceil(lista.length / PRODUCTOS_POR_PAGINA);
    if (paginaActual > totalPaginas) paginaActual = totalPaginas;
    if (paginaActual < 1) paginaActual = 1;

    const inicio = (paginaActual - 1) * PRODUCTOS_POR_PAGINA;
    const pagina = lista.slice(inicio, inicio + PRODUCTOS_POR_PAGINA);

    container.innerHTML = `<div class="products-grid">${pagina.map(p => cardHTML(p)).join('')}</div>`
        + paginacionHTML(totalPaginas);
}

// Genera "‹ 1 2 3 ... 10 ›" con el rango alrededor de la página actual,
// para no listar cientos de botones cuando hay catálogos grandes.
function paginacionHTML(totalPaginas) {
    if (totalPaginas <= 1) return '';

    const paginas = [];
    const rango = 1;
    for (let i = 1; i <= totalPaginas; i++) {
        if (i === 1 || i === totalPaginas || (i >= paginaActual - rango && i <= paginaActual + rango)) {
            paginas.push(i);
        } else if (paginas[paginas.length - 1] !== '...') {
            paginas.push('...');
        }
    }

    const botones = paginas.map(p => p === '...'
        ? `<span class="page-ellipsis">…</span>`
        : `<button class="page-btn ${p === paginaActual ? 'active' : ''}" onclick="irAPagina(${p})">${p}</button>`
    ).join('');

    return `
    <div class="pagination">
        <button class="page-btn page-nav" onclick="irAPagina(${paginaActual - 1})" ${paginaActual === 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
        ${botones}
        <button class="page-btn page-nav" onclick="irAPagina(${paginaActual + 1})" ${paginaActual === totalPaginas ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>`;
}

function irAPagina(n) {
    paginaActual = n;
    renderProductos();
    document.getElementById('section-title').scrollIntoView({ behavior: 'smooth', block: 'start' });
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
