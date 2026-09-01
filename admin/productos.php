<?php
$adminPage  = 'productos';
$adminTitle = 'Productos';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();

$productos = $pdo->query("
    SELECT p.*, c.nombre AS categoria
    FROM productos p
    LEFT JOIN categorias c ON c.id = p.categoria_id
    ORDER BY p.nombre ASC
")->fetchAll();

$categorias = $pdo->query('SELECT id, nombre FROM categorias ORDER BY nombre ASC')->fetchAll();

$totalActivos  = count(array_filter($productos, fn($p) => $p['activo']));
$totalInactivos = count($productos) - $totalActivos;
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-box"></i> Productos</h1>
        <p style="color:var(--text-muted);font-size:.83rem;margin-top:2px">
            <?= count($productos) ?> productos · <span style="color:var(--success)"><?= $totalActivos ?> activos</span> · <span style="color:var(--text-muted)"><?= $totalInactivos ?> inactivos</span>
        </p>
    </div>
    <div style="display:flex;gap:10px">
        <button onclick="toggleTodos(true)" class="btn btn-secondary" style="font-size:.83rem"><i class="fas fa-eye"></i> Activar todos</button>
        <button onclick="toggleTodos(false)" class="btn btn-secondary" style="font-size:.83rem"><i class="fas fa-eye-slash"></i> Desactivar todos</button>
        <button onclick="abrirNuevo()" class="btn btn-primary" style="font-size:.83rem"><i class="fas fa-plus"></i> Nuevo producto</button>
    </div>
</div>

<?php if (!$categorias): ?>
<div class="alert alert-warning">
    Todavía no tienes categorías. <a href="<?= BASE_URL ?>/admin/categorias.php">Crea una primero</a> para poder clasificar tus productos.
</div>
<?php endif; ?>

<!-- Filtros -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
    <div style="position:relative;flex:1;min-width:200px">
        <input type="text" id="f-buscar" class="form-control" placeholder="Buscar producto..." oninput="filtrar()" style="padding-left:36px">
        <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.85rem"></i>
    </div>
    <select id="f-cat" class="form-control" style="width:180px" onchange="filtrar()">
        <option value="">Todas las categorías</option>
        <?php foreach ($categorias as $cat): ?>
        <option value="<?= htmlspecialchars($cat['nombre']) ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="f-estado" class="form-control" style="width:150px" onchange="filtrar()">
        <option value="">Todos</option>
        <option value="activo">Activos</option>
        <option value="inactivo">Inactivos</option>
    </select>
</div>

<!-- Tabla -->
<div class="table-wrapper">
    <table class="data-table" id="tabla-productos">
        <thead>
            <tr>
                <th style="width:60px">Imagen</th>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Precio</th>
                <th>Stock</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($productos as $p): ?>
        <tr id="prod-row-<?= $p['id'] ?>"
            data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>"
            data-cat="<?= htmlspecialchars($p['categoria'] ?? '') ?>"
            data-estado="<?= $p['activo'] ? 'activo' : 'inactivo' ?>">

            <td>
                <div style="width:48px;height:48px;border-radius:8px;overflow:hidden;background:var(--surface-3);cursor:pointer" onclick='abrirEditar(<?= json_encode($p) ?>)' title="Editar producto">
                    <?php if (!empty($p['imagen_path'])): ?>
                        <img src="<?= UPLOADS_URL ?>/productos/<?= htmlspecialchars($p['imagen_path']) ?>" style="width:100%;height:100%;object-fit:contain;padding:4px" onerror="this.style.display='none'">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-light)"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                </div>
            </td>

            <td>
                <div style="font-weight:600;font-size:.9rem"><?= htmlspecialchars($p['nombre']) ?></div>
                <div style="font-size:.75rem;color:var(--text-muted);font-family:monospace"><?= htmlspecialchars($p['codigo'] ?? '') ?></div>
            </td>

            <td>
                <span style="background:var(--primary-bg);color:var(--primary);padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:600">
                    <?= htmlspecialchars($p['categoria'] ?? '—') ?>
                </span>
            </td>

            <td style="font-weight:600">S/ <?= number_format(floatval($p['precio']), 2) ?></td>

            <td>
                <?php $stock = intval($p['stock']); ?>
                <span style="font-weight:600;color:<?= $stock > 5 ? 'var(--success)' : ($stock > 0 ? 'var(--warning)' : 'var(--danger)') ?>">
                    <?= $stock ?> uds
                </span>
            </td>

            <td>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <div class="toggle-switch" onclick="toggleActivo(<?= $p['id'] ?>, this)" data-on="<?= $p['activo'] ? '1' : '0' ?>">
                        <div class="toggle-track <?= $p['activo'] ? 'on' : '' ?>">
                            <div class="toggle-thumb"></div>
                        </div>
                    </div>
                    <span id="label-<?= $p['id'] ?>" style="font-size:.8rem;color:var(--text-muted)"><?= $p['activo'] ? 'Activo' : 'Inactivo' ?></span>
                </label>
            </td>

            <td>
                <button onclick='abrirEditar(<?= json_encode($p) ?>)' class="btn btn-secondary" style="padding:5px 10px;font-size:.75rem" title="Editar producto">
                    <i class="fas fa-edit"></i>
                </button>
                <button onclick="eliminarProducto(<?= $p['id'] ?>)" class="btn btn-danger" style="padding:5px 10px;font-size:.75rem" title="Eliminar producto">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$productos): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:40px">Sin productos todavía. Crea el primero con "Nuevo producto".</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal crear/editar -->
<div id="modal-producto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;overflow-y:auto;padding:20px">
    <div style="background:#fff;border-radius:var(--radius-lg);padding:28px;max-width:460px;width:92%;box-shadow:var(--shadow-lg)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <h3 id="modal-titulo" style="font-weight:700;font-size:.95rem"><i class="fas fa-box"></i> Nuevo producto</h3>
            <button onclick="cerrarModal()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--text-muted)">×</button>
        </div>

        <div id="modal-img-preview" style="width:100px;height:100px;border-radius:10px;background:var(--surface-3);margin:0 auto 18px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--text-light)">
            <i class="fas fa-image fa-2x"></i>
        </div>

        <div class="form-group">
            <label class="form-label">Nombre <span style="color:var(--danger)">*</span></label>
            <input type="text" id="f-nombre" class="form-control" placeholder="Ej: Jabón Antibacterial">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Código / SKU</label>
                <input type="text" id="f-codigo" class="form-control" placeholder="Opcional">
            </div>
            <div class="form-group">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center">
                    Categoría
                    <span onclick="toggleNuevaCategoria()" style="cursor:pointer;color:var(--primary);font-weight:600;font-size:.78rem">
                        <i class="fas fa-plus"></i> Nueva
                    </span>
                </label>
                <select id="f-categoria" class="form-control">
                    <option value="">— Sin categoría —</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="nueva-categoria-row" style="display:none;margin-top:6px;gap:6px">
                    <input type="text" id="nueva-categoria-nombre" class="form-control" placeholder="Nombre de la categoría" style="font-size:.85rem"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();guardarNuevaCategoria();} if(event.key==='Escape'){toggleNuevaCategoria();}">
                    <button type="button" class="btn btn-primary" onclick="guardarNuevaCategoria()" title="Guardar" style="padding:6px 10px">
                        <i class="fas fa-check"></i>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="toggleNuevaCategoria()" title="Cancelar" style="padding:6px 10px">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Precio (S/) <span style="color:var(--danger)">*</span></label>
                <input type="number" id="f-precio" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label">Stock <span style="color:var(--danger)">*</span></label>
                <input type="number" id="f-stock" class="form-control" step="1" min="0" placeholder="0">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Descripción</label>
            <textarea id="f-descripcion" class="form-control" rows="2" placeholder="Opcional"></textarea>
        </div>

        <div class="form-group" style="position:relative">
            <label class="form-label">
                Etiquetas <span style="color:var(--text-muted);font-size:.75rem;font-weight:400">(uso interno, ayudan a que el buscador lo encuentre)</span>
            </label>
            <div id="tags-box" class="form-control" style="height:auto;min-height:38px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:6px 8px;cursor:text" onclick="document.getElementById('f-tag-input').focus()">
                <input type="text" id="f-tag-input" placeholder="Escribe y presiona Enter..." style="border:none;outline:none;flex:1;min-width:120px;font-size:.85rem;padding:4px 2px;background:transparent">
            </div>
            <div id="tags-suggestions" style="display:none;position:absolute;z-index:10;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);margin-top:4px;max-height:160px;overflow-y:auto;width:100%"></div>
            <div class="form-hint">Ej: limpieza, cuidado del hogar. Enter o coma para agregar.</div>
        </div>

        <div class="form-group">
            <label class="form-label">Imagen</label>
            <input type="file" id="f-imagen" class="form-control" accept="image/*" onchange="previewImagen(this)">
            <div class="form-hint">JPG, PNG, WEBP — Recomendado: 400×400px</div>
        </div>

        <div id="qr-section" class="form-group" style="display:none;text-align:center;padding:14px;background:var(--surface-3);border-radius:var(--radius)">
            <label class="form-label">Código QR del producto</label>
            <img id="qr-img" src="" alt="QR del producto" style="width:120px;height:120px;background:#fff;border-radius:8px;padding:6px">
            <div class="form-hint">Enlaza directo a la página pública del producto — útil para imprimir en etiquetas/empaques</div>
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:16px">
            <input type="checkbox" id="f-activo" checked>
            Mostrar en la tienda
        </label>

        <button onclick="guardarProducto()" class="btn btn-primary btn-full" id="btn-guardar">
            <i class="fas fa-save"></i> Guardar producto
        </button>
        <div id="modal-msg" style="margin-top:10px;display:none"></div>
    </div>
</div>

<style>
.toggle-track {
    width: 40px; height: 22px;
    background: var(--border);
    border-radius: 20px;
    position: relative;
    transition: background .2s;
    cursor: pointer;
}
.toggle-track.on { background: var(--success); }
.toggle-thumb {
    width: 16px; height: 16px;
    background: #fff;
    border-radius: 50%;
    position: absolute;
    top: 3px; left: 3px;
    transition: left .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.toggle-track.on .toggle-thumb { left: 21px; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
let modalProductoId = 0;
let modalImagenActual = '';
let tagsActuales = [];
let todasLasEtiquetas = [];

fetch(window.BASE_URL + '/admin/api.php?action=etiquetas_sugeridas')
    .then(r => r.json())
    .then(d => { if (d.success) todasLasEtiquetas = d.data; })
    .catch(() => {});

// ── FILTROS ──
function filtrar() {
    const q     = document.getElementById('f-buscar').value.toLowerCase();
    const cat   = document.getElementById('f-cat').value;
    const est   = document.getElementById('f-estado').value;
    document.querySelectorAll('#tabla-productos tbody tr').forEach(tr => {
        if (!tr.dataset.nombre) return;
        const nombre = tr.dataset.nombre || '';
        const trCat  = tr.dataset.cat    || '';
        const trEst  = tr.dataset.estado || '';
        const ok = (!q || nombre.includes(q))
                && (!cat || trCat === cat)
                && (!est || trEst === est);
        tr.style.display = ok ? '' : 'none';
    });
}

// ── TOGGLE ACTIVO ──
async function toggleActivo(id, el) {
    const track = el.querySelector('.toggle-track');
    const on    = !track.classList.contains('on');
    track.classList.toggle('on', on);
    document.getElementById('label-' + id).textContent = on ? 'Activo' : 'Inactivo';
    const row = document.getElementById('prod-row-' + id);
    if (row) row.dataset.estado = on ? 'activo' : 'inactivo';

    const res  = await fetch(window.BASE_URL + '/admin/api.php?action=toggle_producto', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({producto_id: id, activo: on})
    });
    const data = await res.json();
    if (!data.success) showToast('Error al guardar', 'error');
}

async function toggleTodos(activo) {
    if (!confirm(`¿${activo ? 'Activar' : 'Desactivar'} todos los productos?`)) return;
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=toggle_todos', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({activo})
    });
    const data = await res.json();
    if (data.success) { showToast('Actualizado', 'success'); setTimeout(() => location.reload(), 800); }
}

// ── ELIMINAR ──
async function eliminarProducto(id) {
    if (!confirm('¿Eliminar este producto? Esta acción no se puede deshacer.')) return;
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=eliminar_producto', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id})
    });
    const data = await res.json();
    if (data.success) {
        showToast('Producto eliminado', 'success');
        document.getElementById('prod-row-' + id)?.remove();
    } else {
        showToast('Error al eliminar', 'error');
    }
}

// ── MODAL CREAR/EDITAR ──
function abrirNuevo() {
    modalProductoId = 0;
    modalImagenActual = '';
    document.getElementById('modal-titulo').innerHTML = '<i class="fas fa-box"></i> Nuevo producto';
    document.getElementById('f-nombre').value = '';
    document.getElementById('f-codigo').value = '';
    document.getElementById('f-categoria').value = '';
    document.getElementById('f-precio').value = '';
    document.getElementById('f-stock').value = '';
    document.getElementById('f-descripcion').value = '';
    document.getElementById('f-imagen').value = '';
    document.getElementById('f-activo').checked = true;
    document.getElementById('modal-img-preview').innerHTML = '<i class="fas fa-image fa-2x"></i>';
    document.getElementById('modal-msg').style.display = 'none';
    document.getElementById('qr-section').style.display = 'none';
    tagsActuales = [];
    renderTags();
    cerrarNuevaCategoria();
    document.getElementById('modal-producto').style.display = 'flex';
}

function abrirEditar(p) {
    modalProductoId = p.id;
    modalImagenActual = p.imagen_path || '';
    document.getElementById('modal-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar producto';
    document.getElementById('f-nombre').value = p.nombre || '';
    document.getElementById('f-codigo').value = p.codigo || '';
    document.getElementById('f-categoria').value = p.categoria_id || '';
    document.getElementById('f-precio').value = p.precio || '';
    document.getElementById('f-stock').value = p.stock || '';
    document.getElementById('f-descripcion').value = p.descripcion || '';
    document.getElementById('f-imagen').value = '';
    document.getElementById('f-activo').checked = p.activo === true;
    document.getElementById('modal-msg').style.display = 'none';

    const preview = document.getElementById('modal-img-preview');
    preview.innerHTML = modalImagenActual
        ? `<img src="${window.BASE_URL}/uploads/productos/${modalImagenActual}" style="width:100%;height:100%;object-fit:contain;padding:6px">`
        : '<i class="fas fa-image fa-2x"></i>';

    try { tagsActuales = JSON.parse(p.etiquetas || '[]'); } catch (e) { tagsActuales = []; }
    if (!Array.isArray(tagsActuales)) tagsActuales = [];
    renderTags();

    const productoUrl = window.BASE_URL + '/producto.php?id=' + p.id;
    document.getElementById('qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(productoUrl);
    document.getElementById('qr-section').style.display = 'block';

    cerrarNuevaCategoria();
    document.getElementById('modal-producto').style.display = 'flex';
}

// ── ETIQUETAS (chips estilo hashtag, con autocompletado) ──
function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function renderTags() {
    const box = document.getElementById('tags-box');
    const input = document.getElementById('f-tag-input');
    box.querySelectorAll('.tag-pill').forEach(el => el.remove());
    tagsActuales.forEach((t, i) => {
        const pill = document.createElement('span');
        pill.className = 'tag-pill';
        pill.style.cssText = 'background:var(--primary-bg);color:var(--primary);padding:3px 8px;border-radius:14px;font-size:.78rem;display:inline-flex;align-items:center;gap:5px;white-space:nowrap';
        pill.innerHTML = `${escapeHtml(t)} <span style="cursor:pointer;font-weight:700" onclick="quitarTag(${i})">&times;</span>`;
        box.insertBefore(pill, input);
    });
}

function agregarTag(valor) {
    valor = valor.trim().replace(/,$/, '').trim();
    if (!valor) return;
    if (tagsActuales.some(t => t.toLowerCase() === valor.toLowerCase())) {
        document.getElementById('f-tag-input').value = '';
        ocultarSugerencias();
        return;
    }
    tagsActuales.push(valor);
    renderTags();
    document.getElementById('f-tag-input').value = '';
    ocultarSugerencias();
}

function quitarTag(i) {
    tagsActuales.splice(i, 1);
    renderTags();
}

function mostrarSugerencias(q) {
    const box = document.getElementById('tags-suggestions');
    q = q.trim().toLowerCase();
    if (!q) { box.style.display = 'none'; return; }
    const matches = todasLasEtiquetas
        .filter(t => t.toLowerCase().includes(q) && !tagsActuales.some(a => a.toLowerCase() === t.toLowerCase()))
        .slice(0, 8);
    if (!matches.length) { box.style.display = 'none'; return; }
    box.innerHTML = matches.map(t =>
        `<div style="padding:8px 12px;cursor:pointer;font-size:.85rem" onmousedown="agregarTag('${t.replace(/'/g, "\\'")}')" onmouseover="this.style.background='var(--surface-3)'" onmouseout="this.style.background=''">${escapeHtml(t)}</div>`
    ).join('');
    box.style.display = 'block';
}

function ocultarSugerencias() {
    document.getElementById('tags-suggestions').style.display = 'none';
}

const tagInput = document.getElementById('f-tag-input');
tagInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        agregarTag(e.target.value);
    } else if (e.key === 'Backspace' && !e.target.value && tagsActuales.length) {
        quitarTag(tagsActuales.length - 1);
    }
});
tagInput.addEventListener('input', (e) => mostrarSugerencias(e.target.value));
tagInput.addEventListener('blur', () => setTimeout(ocultarSugerencias, 150));

// ── NUEVA CATEGORÍA (inline, desde el modal de producto) ──
function toggleNuevaCategoria() {
    const row = document.getElementById('nueva-categoria-row');
    const abrir = row.style.display === 'none';
    row.style.display = abrir ? 'flex' : 'none';
    if (abrir) document.getElementById('nueva-categoria-nombre').focus();
}

function cerrarNuevaCategoria() {
    document.getElementById('nueva-categoria-row').style.display = 'none';
    document.getElementById('nueva-categoria-nombre').value = '';
}

async function guardarNuevaCategoria() {
    const nombre = document.getElementById('nueva-categoria-nombre').value.trim();
    if (!nombre) return;

    const res = await fetch(window.BASE_URL + '/admin/api.php?action=crear_categoria', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nombre })
    });
    const data = await res.json();
    if (!data.success) { showToast(data.error || 'Error al crear categoría', 'error'); return; }

    const sel = document.getElementById('f-categoria');
    const opt = document.createElement('option');
    opt.value = data.id;
    opt.textContent = data.nombre;
    sel.appendChild(opt);
    sel.value = data.id;

    cerrarNuevaCategoria();
    showToast('Categoría creada', 'success');
}

function cerrarModal() {
    document.getElementById('modal-producto').style.display = 'none';
}

function previewImagen(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('modal-img-preview').innerHTML =
            `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:contain;padding:6px">`;
    };
    reader.readAsDataURL(input.files[0]);
}

async function guardarProducto() {
    const nombre = document.getElementById('f-nombre').value.trim();
    const precio = document.getElementById('f-precio').value;
    const stock  = document.getElementById('f-stock').value;

    if (!nombre) { mostrarMsg('El nombre es requerido.', false); return; }
    if (precio === '' || parseFloat(precio) < 0) { mostrarMsg('Ingresa un precio válido.', false); return; }
    if (stock === '' || parseInt(stock) < 0) { mostrarMsg('Ingresa un stock válido.', false); return; }

    const btn = document.getElementById('btn-guardar');
    btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Guardando...';

    const form = new FormData();
    form.append('producto_id', modalProductoId);
    form.append('nombre', nombre);
    form.append('codigo', document.getElementById('f-codigo').value.trim());
    form.append('categoria_id', document.getElementById('f-categoria').value);
    form.append('precio', precio);
    form.append('stock', stock);
    form.append('descripcion', document.getElementById('f-descripcion').value.trim());
    form.append('etiquetas', JSON.stringify(tagsActuales));
    form.append('activo', document.getElementById('f-activo').checked ? '1' : '0');
    const imgFile = document.getElementById('f-imagen').files[0];
    if (imgFile) form.append('imagen', imgFile);

    const res  = await fetch(window.BASE_URL + '/admin/api.php?action=guardar_producto', { method: 'POST', body: form });
    const data = await res.json();
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Guardar producto';

    if (data.success) {
        showToast('Producto guardado', 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        mostrarMsg(data.error || 'Error al guardar', false);
    }
}

function mostrarMsg(texto, ok) {
    const msg = document.getElementById('modal-msg');
    msg.style.display = 'block';
    msg.className = ok ? 'alert alert-success' : 'alert alert-error';
    msg.textContent = texto;
}
</script>
