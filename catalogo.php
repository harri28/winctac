<?php
$pageTitle   = 'Catálogo';
$metaDescription = 'Catálogo virtual: productos organizados por categoría, con precios y fotografías.';
$currentPage = 'catalogo';
require_once __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/banner_slider.php'; ?>

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
// Paginación server-side: solo guardamos la página actual, nunca el catálogo
// completo. Cada cambio de filtro/página hace un nuevo fetch con los params
// correspondientes. productosPagina queda a mano para que agregarAlCarrito()
// pueda tomar los datos del producto sin re-fetchearlos.
let productosPagina = [];
let categoriaActiva = 'all';
let paginaActual    = 1;
let totalPaginas    = 1;
let busquedaActual  = '';
const PRODUCTOS_POR_PAGINA = 48;

// Si llegamos desde otra página con ?categoria= o ?q=, aplicarlo de entrada
const _params = new URLSearchParams(window.location.search);
if (_params.get('categoria')) categoriaActiva = _params.get('categoria');
if (_params.get('q')) {
    document.getElementById('header-search-input').value = _params.get('q');
    busquedaActual = _params.get('q');
}

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

// Hooks que el header (compartido en todas las páginas) llama cuando estamos
// aquí, para filtrar sin recargar la página — ahora refetchea al server.
window.filtrarCategoriaInicio = function(id, nombre, el) {
    categoriaActiva = id;
    document.querySelectorAll('.cat-menu-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    document.getElementById('section-title').textContent = id === 'all' ? 'Todos los productos' : nombre;
    paginaActual = 1;
    cargarProductos();
};

window.filtrarBusquedaInicio = function(q) {
    busquedaActual = (q || '').trim();
    paginaActual = 1;
    cargarProductos();
};

// Fetch de una sola página. Con retry (3 intentos, backoff 0/1.5/4s) y timeout
// de 60s. En vez de descargar los 8000+ productos de golpe, pide solo los que
// se van a mostrar.
let fetchActivo = null;
async function cargarProductos(intento = 1) {
    const container = document.getElementById('products-container');
    container.innerHTML = '<div class="loading-overlay"><div class="spinner"></div> Cargando productos'
        + (intento > 1 ? ` (reintento ${intento} de 3)` : '') + '...</div>';

    // Si ya hay otro fetch en curso (cambiar de categoría rápido mientras llega
    // la respuesta anterior), lo abortamos para que no sobreescriba al nuevo.
    if (fetchActivo) fetchActivo.abort();
    const ctrl = new AbortController();
    fetchActivo = ctrl;
    const timeoutId = setTimeout(() => ctrl.abort(), 60000);

    const params = new URLSearchParams({
        action:   'productos',
        page:     paginaActual,
        per_page: PRODUCTOS_POR_PAGINA,
    });
    if (categoriaActiva && categoriaActiva !== 'all') params.set('categoria', categoriaActiva);
    if (busquedaActual) params.set('q', busquedaActual);

    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?' + params.toString(), { signal: ctrl.signal });
        clearTimeout(timeoutId);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'respuesta sin success');
        productosPagina = data.data;
        totalPaginas    = Math.max(1, data.total_pages || 1);
        if (paginaActual > totalPaginas) { paginaActual = totalPaginas; return cargarProductos(); }
        renderProductos(data.total);
    } catch (e) {
        clearTimeout(timeoutId);
        if (e.name === 'AbortError' && fetchActivo !== ctrl) return; // abort normal (nuevo fetch disparado)
        console.error('[catalogo] intento', intento, '-', e);
        if (intento < 3) {
            const espera = intento === 1 ? 1500 : 4000;
            setTimeout(() => cargarProductos(intento + 1), espera);
            return;
        }
        const motivo = e.name === 'AbortError'  ? 'La solicitud tardó demasiado (timeout de 60s).'
                     : e.message.startsWith('HTTP') ? 'El servidor respondió con error (' + e.message + ').'
                     : 'No se pudo procesar la respuesta del servidor: ' + (e.message || e.name || 'error desconocido');
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <h3>No pudimos cargar los productos</h3>
                <p>${motivo}</p>
                <button class="btn btn-primary" onclick="cargarProductos(1)" style="margin-top:12px">
                    <i class="fas fa-redo"></i> Reintentar
                </button>
            </div>`;
    }
}

function renderProductos(total) {
    const container = document.getElementById('products-container');
    if (!productosPagina.length) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><h3>Sin productos</h3><p>No se encontraron productos con los filtros seleccionados.</p></div>';
        return;
    }

    const resumen = total != null
        ? `<div class="catalogo-total" style="color:var(--text-muted);font-size:.82rem;margin-bottom:10px">
             Mostrando ${productosPagina.length} de ${total} producto${total !== 1 ? 's' : ''}
           </div>`
        : '';

    container.innerHTML = resumen
        + `<div class="products-grid">${productosPagina.map(p => cardHTML(p)).join('')}</div>`
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
    cargarProductos();
    document.getElementById('section-title').scrollIntoView({ behavior: 'smooth', block: 'start' });
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

    const nombreEsc = p.nombre.replace(/'/g,"\\'");
    // Si carrito.js llegó desactualizado (sin waLinkProducto), armamos el link
    // aquí para que no se caiga todo el catálogo por una tarjeta.
    const waHref = typeof waLinkProducto === 'function'
        ? waLinkProducto(p.nombre, p.id)
        : 'https://wa.me/' + (window.WA_NUMERO || '') + '?text='
            + encodeURIComponent('Hola, me interesa consultar por el producto: ' + p.nombre);
    const footerHTML = window.CARRITO_ACTIVO === false
        ? `<a class="btn-add-wa" href="${waHref}" target="_blank" rel="noopener" onclick="event.stopPropagation()">
               <i class="fab fa-whatsapp"></i> Consultar
           </a>`
        : `<button class="btn-add-cart" onclick="agregarAlCarrito(${p.id})"
                   ${stock <= 0 ? 'disabled' : ''}>
               <i class="fas fa-plus"></i> Agregar
           </button>`;

    return `
    <div class="product-card" style="cursor:pointer" onclick="irAProducto(event,${p.id},'${nombreEsc}')">
        ${imgHTML}
        <div class="product-body">
            <div class="product-category">${p.categoria || ''}</div>
            <div class="product-name">${p.nombre}</div>
            <div class="product-price">S/ ${parseFloat(p.precio).toFixed(2)}</div>
            ${stockLabel}
        </div>
        <div class="product-footer">
            ${footerHTML}
        </div>
    </div>`;
}

function irAProducto(e, id, nombre) {
    if (e.target.closest('.btn-add-cart') || e.target.closest('.btn-add-wa')) return;
    window.location.href = urlProducto(id, nombre);
}

function agregarAlCarrito(id) {
    const p = productosPagina.find(x => x.id === id);
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
