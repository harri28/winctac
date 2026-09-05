<?php
$adminPage  = 'promociones';
$adminTitle = 'Promociones';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Crear / editar promoción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_promocion'])) {
    $id     = intval($_POST['promocion_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $activo = isset($_POST['activo']) ? 't' : 'f';
    $productoIds = json_decode($_POST['producto_ids'] ?? '[]', true);
    $productoIds = is_array($productoIds) ? array_values(array_unique(array_map('intval', $productoIds))) : [];

    if (!$nombre) {
        $error = 'El nombre es requerido.';
    } else {
        if ($id) {
            $pdo->prepare('UPDATE promociones SET nombre = ?, activo = ? WHERE id = ? AND tienda_id = ?')
                ->execute([$nombre, $activo, $id, TIENDA_ID]);
        } else {
            $ins = $pdo->prepare('INSERT INTO promociones (tienda_id, nombre, activo) VALUES (?, ?, ?) RETURNING id');
            $ins->execute([TIENDA_ID, $nombre, $activo]);
            $id = $ins->fetchColumn();
        }
        // Reemplazar la lista de productos de la promoción por la enviada
        $pdo->prepare('DELETE FROM promocion_productos WHERE promocion_id = ?')->execute([$id]);
        if ($productoIds) {
            $insProd = $pdo->prepare('INSERT INTO promocion_productos (promocion_id, producto_id, orden) VALUES (?, ?, ?)');
            foreach ($productoIds as $i => $pid) {
                $insProd->execute([$id, $pid, $i]);
            }
        }
        $success = 'Promoción guardada.';
    }
}

// Eliminar promoción
if (!empty($_GET['del']) && is_numeric($_GET['del'])) {
    $pdo->prepare('DELETE FROM promociones WHERE id = ? AND tienda_id = ?')->execute([intval($_GET['del']), TIENDA_ID]);
    $success = 'Promoción eliminada.';
}

$promosStmt = $pdo->prepare('
    SELECT p.*, COUNT(pp.id) AS total_productos
    FROM promociones p
    LEFT JOIN promocion_productos pp ON pp.promocion_id = p.id
    WHERE p.tienda_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
');
$promosStmt->execute([TIENDA_ID]);
$promociones = $promosStmt->fetchAll();

// Productos de cada promoción, para precargar el formulario al editar
$prodsPorPromo = [];
if ($promociones) {
    $ids = array_column($promociones, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT pp.promocion_id, pr.id, pr.nombre
        FROM promocion_productos pp JOIN productos pr ON pr.id = pp.producto_id
        WHERE pp.promocion_id IN ($ph) ORDER BY pp.orden ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $prodsPorPromo[$row['promocion_id']][] = ['id' => $row['id'], 'nombre' => $row['nombre']];
    }
}
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-percentage"></i> Promociones</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
        Selecciona productos existentes para destacarlos en la fila de Promociones del Inicio (no cambia su precio).
    </p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-list"></i> Promociones creadas</div>
    <table class="data-table">
        <thead>
            <tr><th>Nombre</th><th>Productos</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($promociones as $p): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?></td>
            <td><?= (int)$p['total_productos'] ?></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;<?= $p['activo'] ? 'background:#dcfce7;color:#166534' : 'background:#f1f5f9;color:#94a3b8' ?>">
                    <?= $p['activo'] ? 'Activa' : 'Inactiva' ?>
                </span>
            </td>
            <td>
                <button onclick='editarPromocion(<?= json_encode($p) ?>, <?= json_encode($prodsPorPromo[$p['id']] ?? []) ?>)' class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="?del=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar esta promoción?')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$promociones): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">Sin promociones todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card" style="max-width:560px">
    <div class="card-title" id="promo-form-titulo"><i class="fas fa-plus"></i> Nueva promoción</div>
    <form method="POST" id="form-promocion">
        <input type="hidden" name="promocion_id" id="promo-id" value="0">
        <input type="hidden" name="producto_ids" id="promo-producto-ids" value="[]">

        <div class="form-group">
            <label class="form-label">Nombre de la promoción <span style="color:var(--danger)">*</span></label>
            <input type="text" name="nombre" id="promo-nombre" class="form-control" placeholder="Ej: Ofertas de la semana" required>
        </div>

        <div class="form-group" style="position:relative">
            <label class="form-label">Productos en esta promoción</label>
            <div id="promo-prod-box" class="form-control" style="height:auto;min-height:38px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:6px 8px;cursor:text" onclick="document.getElementById('promo-prod-input').focus()">
                <input type="text" id="promo-prod-input" placeholder="Escribe para buscar un producto..." style="border:none;outline:none;flex:1;min-width:160px;font-size:.85rem;padding:4px 2px;background:transparent">
            </div>
            <div id="promo-prod-suggestions" style="display:none;position:absolute;z-index:10;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);margin-top:4px;max-height:220px;overflow-y:auto;width:100%"></div>
            <div class="form-hint">Escribe el nombre y haz clic en una sugerencia para agregarlo</div>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="activo" id="promo-activo" checked>
                Activa (se muestra en Inicio)
            </label>
        </div>

        <div style="display:flex;gap:10px">
            <button type="submit" name="save_promocion" class="btn btn-primary" id="promo-btn">
                <i class="fas fa-save"></i> Guardar promoción
            </button>
            <button type="button" class="btn btn-secondary" onclick="cancelarEdicionPromo()" id="promo-cancel-btn" style="display:none">
                Cancelar
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
let promoProductosSeleccionados = []; // [{id, nombre}]
let todosLosProductos = [];

fetch(window.BASE_URL + '/api/index.php?action=productos')
    .then(r => r.json())
    .then(d => { if (d.success) todosLosProductos = d.data; })
    .catch(() => {});

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function renderPromoProductos() {
    const box = document.getElementById('promo-prod-box');
    const input = document.getElementById('promo-prod-input');
    box.querySelectorAll('.promo-prod-pill').forEach(el => el.remove());
    promoProductosSeleccionados.forEach((p, i) => {
        const pill = document.createElement('span');
        pill.className = 'promo-prod-pill';
        pill.style.cssText = 'background:var(--primary-bg);color:var(--primary);padding:3px 8px;border-radius:14px;font-size:.78rem;display:inline-flex;align-items:center;gap:5px;white-space:nowrap';
        pill.innerHTML = `${escapeHtml(p.nombre)} <span style="cursor:pointer;font-weight:700" onclick="quitarPromoProducto(${i})">&times;</span>`;
        box.insertBefore(pill, input);
    });
    document.getElementById('promo-producto-ids').value = JSON.stringify(promoProductosSeleccionados.map(p => p.id));
}

function agregarPromoProducto(p) {
    if (promoProductosSeleccionados.some(x => x.id === p.id)) return;
    promoProductosSeleccionados.push(p);
    renderPromoProductos();
    document.getElementById('promo-prod-input').value = '';
    ocultarPromoSugerencias();
}

function quitarPromoProducto(i) {
    promoProductosSeleccionados.splice(i, 1);
    renderPromoProductos();
}

function mostrarPromoSugerencias(q) {
    const box = document.getElementById('promo-prod-suggestions');
    q = q.trim().toLowerCase();
    if (!q) { box.style.display = 'none'; return; }
    const matches = todosLosProductos
        .filter(p => p.nombre.toLowerCase().includes(q) && !promoProductosSeleccionados.some(s => s.id === p.id))
        .slice(0, 8);
    if (!matches.length) { box.style.display = 'none'; return; }
    box.innerHTML = matches.map(p =>
        `<div style="padding:8px 12px;cursor:pointer;font-size:.85rem" onmousedown='agregarPromoProducto(${JSON.stringify({id:p.id,nombre:p.nombre})})' onmouseover="this.style.background='var(--surface-3)'" onmouseout="this.style.background=''">${escapeHtml(p.nombre)}</div>`
    ).join('');
    box.style.display = 'block';
}

function ocultarPromoSugerencias() {
    document.getElementById('promo-prod-suggestions').style.display = 'none';
}

const promoProdInput = document.getElementById('promo-prod-input');
promoProdInput.addEventListener('input', (e) => mostrarPromoSugerencias(e.target.value));
promoProdInput.addEventListener('blur', () => setTimeout(ocultarPromoSugerencias, 150));

function editarPromocion(promo, productos) {
    document.getElementById('promo-form-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar promoción';
    document.getElementById('promo-id').value = promo.id;
    document.getElementById('promo-nombre').value = promo.nombre;
    document.getElementById('promo-activo').checked = promo.activo === true;
    promoProductosSeleccionados = productos || [];
    renderPromoProductos();
    document.getElementById('promo-btn').innerHTML = '<i class="fas fa-save"></i> Actualizar promoción';
    document.getElementById('promo-cancel-btn').style.display = '';
    window.scrollTo({top: document.getElementById('form-promocion').offsetTop - 20, behavior: 'smooth'});
}

function cancelarEdicionPromo() {
    document.getElementById('promo-form-titulo').innerHTML = '<i class="fas fa-plus"></i> Nueva promoción';
    document.getElementById('promo-id').value = 0;
    document.getElementById('promo-nombre').value = '';
    document.getElementById('promo-activo').checked = true;
    promoProductosSeleccionados = [];
    renderPromoProductos();
    document.getElementById('promo-btn').innerHTML = '<i class="fas fa-save"></i> Guardar promoción';
    document.getElementById('promo-cancel-btn').style.display = 'none';
}
</script>
