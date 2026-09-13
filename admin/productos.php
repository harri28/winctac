<?php
$adminPage  = 'productos';
$adminTitle = 'Productos';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();

// ── Filtros (server-side: con miles de productos no se puede cargar todo y filtrar en JS) ──
$fBuscar = trim($_GET['buscar'] ?? '');
$fCat    = trim($_GET['cat'] ?? '');
$fEstado = trim($_GET['estado'] ?? '');
$porPagina = 20;
$pagina    = max(1, intval($_GET['pagina'] ?? 1));

$where  = ['p.tienda_id = ?'];
$params = [TIENDA_ID];
if ($fBuscar !== '') {
    $where[]  = '(p.nombre ILIKE ? OR p.codigo ILIKE ?)';
    $params[] = "%$fBuscar%";
    $params[] = "%$fBuscar%";
}
if ($fCat !== '') {
    $where[]  = 'c.nombre = ?';
    $params[] = $fCat;
}
if ($fEstado === 'activo')   $where[] = 'p.activo = TRUE';
elseif ($fEstado === 'inactivo') $where[] = 'p.activo = FALSE';
$whereSql = implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE $whereSql");
$totalStmt->execute($params);
$totalFiltrados = (int) $totalStmt->fetchColumn();
$totalPaginas   = max(1, (int) ceil($totalFiltrados / $porPagina));
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset = ($pagina - 1) * $porPagina;

$productosStmt = $pdo->prepare("
    SELECT p.*, c.nombre AS categoria
    FROM productos p
    LEFT JOIN categorias c ON c.id = p.categoria_id
    WHERE $whereSql
    ORDER BY p.nombre ASC
    LIMIT $porPagina OFFSET $offset
");
$productosStmt->execute($params);
$productos = $productosStmt->fetchAll();

$categoriasStmt = $pdo->prepare('SELECT id, nombre FROM categorias WHERE tienda_id = ? ORDER BY nombre ASC');
$categoriasStmt->execute([TIENDA_ID]);
$categorias = $categoriasStmt->fetchAll();

$stmtTotales = $pdo->prepare('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE activo) AS activos FROM productos WHERE tienda_id = ?');
$stmtTotales->execute([TIENDA_ID]);
$totales = $stmtTotales->fetch();
$totalGeneral   = (int) $totales['total'];
$totalActivos   = (int) $totales['activos'];
$totalInactivos = $totalGeneral - $totalActivos;

// Construye la query string de paginación preservando los filtros activos
function paginaUrl(int $n, string $buscar, string $cat, string $estado): string {
    $q = ['pagina' => $n];
    if ($buscar !== '') $q['buscar'] = $buscar;
    if ($cat !== '')    $q['cat']    = $cat;
    if ($estado !== '') $q['estado'] = $estado;
    return '?' . http_build_query($q);
}
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-box"></i> Productos</h1>
        <p style="color:var(--text-muted);font-size:.83rem;margin-top:2px">
            <?= $totalGeneral ?> productos · <span style="color:var(--success)"><?= $totalActivos ?> activos</span> · <span style="color:var(--text-muted)"><?= $totalInactivos ?> inactivos</span>
            <?php if ($fBuscar !== '' || $fCat !== '' || $fEstado !== ''): ?>
            · <?= $totalFiltrados ?> coinciden con el filtro
            <?php endif; ?>
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

<!-- Filtros (server-side, vía GET) -->
<form method="GET" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
    <div style="position:relative;flex:1;min-width:200px">
        <input type="text" name="buscar" id="f-buscar" class="form-control" placeholder="Buscar producto o código..." value="<?= htmlspecialchars($fBuscar) ?>" style="padding-left:36px">
        <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.85rem"></i>
    </div>
    <select name="cat" id="f-cat" class="form-control" style="width:180px" onchange="this.form.submit()">
        <option value="">Todas las categorías</option>
        <?php foreach ($categorias as $cat): ?>
        <option value="<?= htmlspecialchars($cat['nombre']) ?>" <?= $fCat === $cat['nombre'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="estado" id="f-estado" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="">Todos</option>
        <option value="activo" <?= $fEstado === 'activo' ? 'selected' : '' ?>>Activos</option>
        <option value="inactivo" <?= $fEstado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
    </select>
    <button type="submit" class="btn btn-secondary" style="font-size:.83rem"><i class="fas fa-filter"></i> Buscar</button>
</form>

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

<?php if ($totalPaginas > 1): ?>
<div class="pagination">
    <?php $hrefAnterior = $pagina > 1 ? paginaUrl($pagina - 1, $fBuscar, $fCat, $fEstado) : '#'; ?>
    <a href="<?= $hrefAnterior ?>" class="page-btn page-nav" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none' : '' ?>">
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php
    $rango = 1;
    $paginasAMostrar = [];
    for ($i = 1; $i <= $totalPaginas; $i++) {
        if ($i === 1 || $i === $totalPaginas || ($i >= $pagina - $rango && $i <= $pagina + $rango)) {
            $paginasAMostrar[] = $i;
        } elseif (end($paginasAMostrar) !== '...') {
            $paginasAMostrar[] = '...';
        }
    }
    foreach ($paginasAMostrar as $p):
        if ($p === '...'): ?>
        <span class="page-ellipsis">…</span>
        <?php else: ?>
        <a href="<?= paginaUrl($p, $fBuscar, $fCat, $fEstado) ?>" class="page-btn <?= $p === $pagina ? 'active' : '' ?>" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none"><?= $p ?></a>
        <?php endif;
    endforeach; ?>
    <?php $hrefSiguiente = $pagina < $totalPaginas ? paginaUrl($pagina + 1, $fBuscar, $fCat, $fEstado) : '#'; ?>
    <a href="<?= $hrefSiguiente ?>" class="page-btn page-nav" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;width:auto;padding:0 14px;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none' : '' ?>">
        Siguiente <i class="fas fa-chevron-right"></i>
    </a>
</div>
<?php endif; ?>

<!-- Modal crear/editar -->
<div id="modal-producto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;align-items:safe center;justify-content:center;overflow-y:auto;padding:20px">
    <div style="background:#fff;border-radius:var(--radius-lg);padding:28px;max-width:1280px;width:calc(100vw - 40px);box-shadow:var(--shadow-lg);margin:auto 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
            <h3 id="modal-titulo" style="font-weight:700;font-size:.95rem"><i class="fas fa-box"></i> Nuevo producto</h3>
            <button onclick="cerrarModal()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--text-muted)">×</button>
        </div>

        <div id="img-gallery" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:6px">
            <div id="modal-img-preview" style="display:none;width:clamp(64px,18vw,100px);height:clamp(64px,18vw,100px);border-radius:10px;background:var(--surface-3);overflow:hidden;align-items:center;justify-content:center;color:var(--text-light)"></div>
        </div>
        <div class="form-hint" style="text-align:center;margin-bottom:18px">Hasta 5 imágenes en total (la principal + 4 adicionales)</div>

        <div class="form-group">
            <label class="form-label">Datos del producto <span style="color:var(--text-muted);font-size:.75rem;font-weight:400">(orden tipo hoja de costeo)</span></label>
            <div style="overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius-sm)">
                <table class="planilla-table">
                    <thead>
                        <tr>
                            <th rowspan="2">ITEM</th>
                            <th rowspan="2">CÓDIGO</th>
                            <th rowspan="2">DESCRIPCIÓN</th>
                            <th rowspan="2">MARCA</th>
                            <th rowspan="2">UM</th>
                            <th colspan="3">COSTO</th>
                            <th colspan="3">MÍNIMO</th>
                            <th colspan="3">LISTA</th>
                            <th rowspan="2">STOCK</th>
                        </tr>
                        <tr>
                            <th>Anterior S/</th><th>Actual S/</th><th>Resultado</th>
                            <th>%</th><th>Precio S/</th><th>Utilidad S/</th>
                            <th>%</th><th>Precio S/</th><th>Utilidad S/</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td id="planilla-item" class="planilla-readonly" style="width:40px">—</td>
                            <td><input type="text" id="f-codigo" class="planilla-input" style="width:80px" placeholder="Opcional"></td>
                            <td><input type="text" id="f-nombre" class="planilla-input" style="width:200px;text-align:left" placeholder="Ej: Jabón Antibacterial"></td>
                            <td><input type="text" id="f-marca" class="planilla-input" style="width:90px"></td>
                            <td><input type="text" id="f-um" class="planilla-input" style="width:55px" placeholder="UND"></td>
                            <td><input type="number" id="f-costo-anterior" class="planilla-input" style="width:75px" step="0.0001" min="0" placeholder="0.0000" oninput="recalcularPrecio()"></td>
                            <td><input type="number" id="f-costo-actual" class="planilla-input" style="width:75px" step="0.0001" min="0" placeholder="0.0000" oninput="recalcularPrecio()"></td>
                            <td id="r-resultado" class="planilla-readonly" style="width:65px">0.00</td>
                            <td><input type="number" id="f-minimo-pct" class="planilla-input" style="width:55px" step="0.01" placeholder="0.00" oninput="recalcularPrecio()"></td>
                            <td id="r-precio-min" class="planilla-readonly" style="width:70px">0.00</td>
                            <td id="r-utilidad-min" class="planilla-readonly" style="width:70px">0.00</td>
                            <td><input type="number" id="f-lista-pct" class="planilla-input" style="width:55px" step="0.01" placeholder="0.00" oninput="recalcularPrecio()"></td>
                            <td id="r-precio-lista" class="planilla-readonly" style="width:75px;color:var(--primary);font-weight:700">0.00</td>
                            <td id="r-utilidad-lista" class="planilla-readonly" style="width:70px">0.00</td>
                            <td><input type="number" id="f-stock" class="planilla-input" style="width:60px" step="1" min="0" placeholder="0"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-hint">
                El precio de venta (columna LISTA → Precio S/) se calcula solo, con Costo actual × (1 + % Lista). Si dejas "Costo actual" en 0, se conserva el precio que ya tenía el producto. El nombre del producto va en la columna DESCRIPCIÓN.
            </div>
        </div>

        <div class="form-group" style="max-width:280px">
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

        <div class="form-group">
            <label class="form-label">Descripción técnica <span style="color:var(--text-muted);font-size:.75rem;font-weight:400">(se muestra al cliente en la página del producto)</span></label>
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
.planilla-table { border-collapse: collapse; white-space: nowrap; }
.planilla-table th, .planilla-table td { border: 1px solid var(--border); padding: 4px; text-align: center; }
.planilla-table thead th {
    background: var(--surface-3);
    font-weight: 700;
    font-size: .64rem;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: .02em;
}
.planilla-input {
    width: 100%;
    border: 1px solid #93c5fd;
    border-radius: 4px;
    padding: 5px 4px;
    font-size: .78rem;
    font-family: inherit;
    text-align: center;
    background: #eff6ff;
    color: #1e3a8a;
}
.planilla-input:focus { outline: none; border-color: #2563eb; background: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.15); }
.planilla-input::placeholder { color: #93b8ec; }
/* Sin flechas de subir/bajar en los campos numéricos de la fila */
.planilla-input[type="number"] { -moz-appearance: textfield; }
.planilla-input::-webkit-outer-spin-button,
.planilla-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.planilla-readonly {
    font-size: .78rem;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--surface-3);
    cursor: not-allowed;
}

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
let precioActualProducto = 0; // precio ya guardado; se conserva si "costo actual" queda en 0

// ── COSTEO: recalcula Resultado/Precio mínimo/Precio de venta en vivo ──
function recalcularPrecio() {
    const costoAnt = parseFloat(document.getElementById('f-costo-anterior').value) || 0;
    const costoAct = parseFloat(document.getElementById('f-costo-actual').value) || 0;
    const minPct   = parseFloat(document.getElementById('f-minimo-pct').value) || 0;
    const listaPct = parseFloat(document.getElementById('f-lista-pct').value) || 0;

    const resultado     = costoAnt || costoAct ? costoAct - costoAnt : 0;
    const precioMin      = costoAct > 0 ? costoAct * (1 + minPct / 100) : 0;
    const utilidadMin    = costoAct > 0 ? precioMin - costoAct : 0;
    const precioLista    = costoAct > 0 ? costoAct * (1 + listaPct / 100) : precioActualProducto;
    const utilidadLista  = costoAct > 0 ? precioLista - costoAct : 0;

    document.getElementById('r-resultado').textContent    = resultado.toFixed(2);
    document.getElementById('r-precio-min').textContent   = precioMin.toFixed(2);
    document.getElementById('r-utilidad-min').textContent = utilidadMin.toFixed(2);
    document.getElementById('r-precio-lista').textContent = precioLista.toFixed(2);
    document.getElementById('r-utilidad-lista').textContent = utilidadLista.toFixed(2);
}

// ── IMÁGENES ADICIONALES (hasta 4, + la principal = 5 en total) ──
// Array compacto (sin huecos): cada elemento es {existingId, path} (ya guardada) | {file} (recién elegida, sin subir).
// Se muestran solo los slots ocupados más UN cuadro para agregar la siguiente, hasta llegar a 4.
const MAX_EXTRA = 4;
let extraSlots = [];
let eliminarImagenesIds = [];

function resetExtraSlots() {
    extraSlots = [];
    eliminarImagenesIds = [];
    renderExtraGallery();
}

function extraSlotHTML(n) {
    return `<div class="img-extra-slot" data-slot="${n}" onclick="clickExtraSlot(${n})"
         style="width:clamp(64px,18vw,100px);height:clamp(64px,18vw,100px);border-radius:10px;background:var(--surface-3);border:2px dashed var(--border);position:relative;overflow:hidden;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-light)">
        <i class="fas fa-plus"></i>
        <input type="file" class="img-extra-input" data-slot="${n}" accept="image/*" style="display:none" onchange="onExtraFileChange(${n}, this)">
    </div>`;
}

function renderExtraGallery() {
    const gallery = document.getElementById('img-gallery');
    gallery.querySelectorAll('.img-extra-slot').forEach(el => el.remove());

    const totalSlots = extraSlots.length + (extraSlots.length < MAX_EXTRA ? 1 : 0);
    for (let n = 1; n <= totalSlots; n++) {
        gallery.insertAdjacentHTML('beforeend', extraSlotHTML(n));
        renderExtraSlotContent(n);
    }
}

function renderExtraSlotContent(n) {
    const slot  = gallerySlotEl(n);
    const input = slot.querySelector('.img-extra-input');
    const data  = extraSlots[n - 1];

    let src = '';
    if (data && data.file) src = URL.createObjectURL(data.file);
    else if (data && data.path) src = window.BASE_URL + '/uploads/productos/' + data.path;

    if (src) {
        slot.style.border = '1.5px solid var(--border)';
        const img = document.createElement('img');
        img.className = 'img-extra-thumb';
        img.src = src;
        img.style.cssText = 'width:100%;height:100%;object-fit:cover';
        slot.insertBefore(img, input);
        const btnX = document.createElement('span');
        btnX.className = 'img-extra-remove';
        btnX.innerHTML = '&times;';
        btnX.style.cssText = 'position:absolute;top:2px;right:2px;width:18px;height:18px;background:rgba(0,0,0,.6);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;cursor:pointer;z-index:2';
        btnX.onclick = (e) => removeExtraSlot(n, e);
        slot.appendChild(btnX);
        slot.querySelector('.fa-plus').style.display = 'none';
    }
}

function gallerySlotEl(n) {
    return document.querySelector(`.img-extra-slot[data-slot="${n}"]`);
}

function clickExtraSlot(n) {
    document.querySelector(`.img-extra-input[data-slot="${n}"]`).click();
}

function onExtraFileChange(n, input) {
    const file = input.files[0];
    if (!file) return;
    const idx = n - 1;
    // Si ya había una imagen guardada en este slot, se reemplaza: se marca para borrar.
    if (extraSlots[idx] && extraSlots[idx].existingId) {
        eliminarImagenesIds.push(extraSlots[idx].existingId);
    }
    extraSlots[idx] = {file};
    renderExtraGallery();
}

function removeExtraSlot(n, event) {
    event.stopPropagation();
    const idx = n - 1;
    if (extraSlots[idx] && extraSlots[idx].existingId) {
        eliminarImagenesIds.push(extraSlots[idx].existingId);
    }
    extraSlots.splice(idx, 1);
    renderExtraGallery();
}

async function cargarImagenesExtra(productoId) {
    try {
        const res  = await fetch(window.BASE_URL + '/admin/api.php?action=imagenes_producto&producto_id=' + productoId);
        const data = await res.json();
        if (!data.success) return;
        extraSlots = data.data.slice(0, MAX_EXTRA).map(img => ({existingId: img.id, path: img.imagen_path}));
        renderExtraGallery();
    } catch (e) {}
}

fetch(window.BASE_URL + '/admin/api.php?action=etiquetas_sugeridas')
    .then(r => r.json())
    .then(d => { if (d.success) todasLasEtiquetas = d.data; })
    .catch(() => {});

// ── FILTROS ──

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
    document.getElementById('planilla-item').textContent = 'Nuevo';
    document.getElementById('f-nombre').value = '';
    document.getElementById('f-codigo').value = '';
    document.getElementById('f-marca').value = '';
    document.getElementById('f-um').value = '';
    document.getElementById('f-categoria').value = '';
    document.getElementById('f-costo-anterior').value = '';
    document.getElementById('f-costo-actual').value = '';
    document.getElementById('f-minimo-pct').value = '';
    document.getElementById('f-lista-pct').value = '';
    precioActualProducto = 0;
    recalcularPrecio();
    document.getElementById('f-stock').value = '';
    document.getElementById('f-descripcion').value = '';
    document.getElementById('f-imagen').value = '';
    document.getElementById('f-activo').checked = true;
    const previewNuevo = document.getElementById('modal-img-preview');
    previewNuevo.innerHTML = '';
    previewNuevo.style.display = 'none';
    document.getElementById('modal-msg').style.display = 'none';
    document.getElementById('qr-section').style.display = 'none';
    tagsActuales = [];
    renderTags();
    resetExtraSlots();
    cerrarNuevaCategoria();
    document.getElementById('modal-producto').style.display = 'flex';
}

function abrirEditar(p) {
    modalProductoId = p.id;
    modalImagenActual = p.imagen_path || '';
    document.getElementById('modal-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar producto';
    document.getElementById('planilla-item').textContent = '#' + p.id;
    document.getElementById('f-nombre').value = p.nombre || '';
    document.getElementById('f-codigo').value = p.codigo || '';
    document.getElementById('f-marca').value = p.marca || '';
    document.getElementById('f-um').value = p.um || '';
    document.getElementById('f-categoria').value = p.categoria_id || '';
    document.getElementById('f-costo-anterior').value = parseFloat(p.costo_anterior || 0) || '';
    document.getElementById('f-costo-actual').value = parseFloat(p.costo_actual || 0) || '';
    document.getElementById('f-minimo-pct').value = parseFloat(p.minimo_porcentaje || 0) || '';
    document.getElementById('f-lista-pct').value = parseFloat(p.lista_porcentaje || 0) || '';
    precioActualProducto = parseFloat(p.precio || 0);
    recalcularPrecio();
    document.getElementById('f-stock').value = p.stock || '';
    document.getElementById('f-descripcion').value = p.descripcion || '';
    document.getElementById('f-imagen').value = '';
    document.getElementById('f-activo').checked = p.activo === true;
    document.getElementById('modal-msg').style.display = 'none';

    const preview = document.getElementById('modal-img-preview');
    if (modalImagenActual) {
        preview.style.display = 'flex';
        preview.innerHTML = `<img src="${window.BASE_URL}/uploads/productos/${modalImagenActual}" style="width:100%;height:100%;object-fit:contain;padding:6px">`;
    } else {
        preview.style.display = 'none';
        preview.innerHTML = '';
    }

    try { tagsActuales = JSON.parse(p.etiquetas || '[]'); } catch (e) { tagsActuales = []; }
    if (!Array.isArray(tagsActuales)) tagsActuales = [];
    renderTags();

    const productoUrl = window.BASE_URL + '/producto.php?id=' + p.id;
    document.getElementById('qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(productoUrl);
    document.getElementById('qr-section').style.display = 'block';

    resetExtraSlots();
    cargarImagenesExtra(p.id);

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
        const preview = document.getElementById('modal-img-preview');
        preview.style.display = 'flex';
        preview.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:contain;padding:6px">`;
    };
    reader.readAsDataURL(input.files[0]);
}

async function guardarProducto() {
    const nombre = document.getElementById('f-nombre').value.trim();
    const stock  = document.getElementById('f-stock').value;

    if (!nombre) { mostrarMsg('El nombre es requerido.', false); return; }
    if (stock === '' || parseInt(stock) < 0) { mostrarMsg('Ingresa un stock válido.', false); return; }

    const btn = document.getElementById('btn-guardar');
    btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Guardando...';

    const form = new FormData();
    form.append('producto_id', modalProductoId);
    form.append('nombre', nombre);
    form.append('codigo', document.getElementById('f-codigo').value.trim());
    form.append('marca', document.getElementById('f-marca').value.trim());
    form.append('um', document.getElementById('f-um').value.trim());
    form.append('categoria_id', document.getElementById('f-categoria').value);
    form.append('costo_anterior', document.getElementById('f-costo-anterior').value || 0);
    form.append('costo_actual', document.getElementById('f-costo-actual').value || 0);
    form.append('minimo_porcentaje', document.getElementById('f-minimo-pct').value || 0);
    form.append('lista_porcentaje', document.getElementById('f-lista-pct').value || 0);
    form.append('stock', stock);
    form.append('descripcion', document.getElementById('f-descripcion').value.trim());
    form.append('etiquetas', JSON.stringify(tagsActuales));

    form.append('activo', document.getElementById('f-activo').checked ? '1' : '0');
    const imgFile = document.getElementById('f-imagen').files[0];
    if (imgFile) form.append('imagen', imgFile);

    extraSlots.forEach((data, idx) => {
        if (data && data.file) form.append('imagen_extra_' + (idx + 1), data.file);
    });
    form.append('eliminar_imagenes', JSON.stringify(eliminarImagenesIds));

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
