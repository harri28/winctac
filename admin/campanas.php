<?php
$adminPage  = 'campanas';
$adminTitle = 'Campañas';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Crear / editar campaña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_campana'])) {
    $id           = intval($_POST['campana_id'] ?? 0);
    $nombre       = trim($_POST['nombre'] ?? '');
    $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
    $tipo         = ($_POST['tipo'] ?? '') === 'monto' ? 'monto' : 'porcentaje';
    $valor        = floatval($_POST['valor'] ?? 0);
    $compraMinima = floatval($_POST['compra_minima'] ?? 0);
    $limiteUsos   = trim($_POST['limite_usos'] ?? '');
    $limiteUsos   = $limiteUsos === '' ? null : max(0, intval($limiteUsos));
    $fechaInicio  = trim($_POST['fecha_inicio'] ?? '');
    $fechaFin     = trim($_POST['fecha_fin'] ?? '');
    $activo       = isset($_POST['activo']) ? 't' : 'f';

    if (!$nombre || !$codigo || !$fechaInicio || !$fechaFin) {
        $error = 'Nombre, código y fechas son requeridos.';
    } elseif ($valor <= 0) {
        $error = 'El valor del descuento debe ser mayor a 0.';
    } elseif ($fechaFin < $fechaInicio) {
        $error = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
    } else {
        $dup = $pdo->prepare('SELECT id FROM campanas WHERE tienda_id = ? AND UPPER(codigo) = UPPER(?) AND id != ?');
        $dup->execute([TIENDA_ID, $codigo, $id]);
        if ($dup->fetch()) {
            $error = 'Ya existe una campaña con ese código.';
        } elseif ($id) {
            $pdo->prepare('
                UPDATE campanas SET nombre=?, codigo=?, tipo=?, valor=?, compra_minima=?, limite_usos=?, fecha_inicio=?, fecha_fin=?, activo=?
                WHERE id=? AND tienda_id=?
            ')->execute([$nombre, $codigo, $tipo, $valor, $compraMinima, $limiteUsos, $fechaInicio, $fechaFin, $activo, $id, TIENDA_ID]);
            $success = 'Campaña actualizada.';
        } else {
            $pdo->prepare('
                INSERT INTO campanas (tienda_id, nombre, codigo, tipo, valor, compra_minima, limite_usos, fecha_inicio, fecha_fin, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([TIENDA_ID, $nombre, $codigo, $tipo, $valor, $compraMinima, $limiteUsos, $fechaInicio, $fechaFin, $activo]);
            $success = 'Campaña creada.';
        }
    }
}

// Eliminar campaña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['del']) && is_numeric($_POST['del'])) {
    $pdo->prepare('DELETE FROM campanas WHERE id = ? AND tienda_id = ?')->execute([intval($_POST['del']), TIENDA_ID]);
    $success = 'Campaña eliminada.';
}

$campanasStmt = $pdo->prepare('SELECT * FROM campanas WHERE tienda_id = ? ORDER BY created_at DESC');
$campanasStmt->execute([TIENDA_ID]);
$campanas = $campanasStmt->fetchAll();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-flag"></i> Campañas</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
        Códigos de descuento públicos con fecha — a diferencia de los cupones de puntos, cualquier cliente puede usarlos, sin necesidad de iniciar sesión.
    </p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-list"></i> Campañas creadas</div>
    <div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr><th>Nombre</th><th>Código</th><th>Descuento</th><th>Vigencia</th><th>Usos</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($campanas as $c):
            $ahora = date('Y-m-d H:i:s');
            if (!$c['activo'])            { $estLabel = 'Inactiva';   $estBg = '#f1f5f9'; $estFg = '#94a3b8'; }
            elseif ($ahora < $c['fecha_inicio']) { $estLabel = 'Programada'; $estBg = '#dbeafe'; $estFg = '#1e40af'; }
            elseif ($ahora > $c['fecha_fin'])    { $estLabel = 'Vencida';    $estBg = '#fee2e2'; $estFg = '#991b1b'; }
            else                          { $estLabel = 'Activa';     $estBg = '#dcfce7'; $estFg = '#166534'; }
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($c['nombre']) ?></td>
            <td style="font-family:monospace;font-weight:600"><?= htmlspecialchars($c['codigo']) ?></td>
            <td><?= $c['tipo'] === 'monto' ? 'S/ ' . number_format($c['valor'], 2) : number_format($c['valor'], 2) . '%' ?></td>
            <td style="font-size:.78rem;color:var(--text-muted)">
                <?= date('d/m/Y', strtotime($c['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($c['fecha_fin'])) ?>
            </td>
            <td><?= (int)$c['usos_actuales'] ?><?= $c['limite_usos'] !== null ? ' / ' . (int)$c['limite_usos'] : '' ?></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;background:<?= $estBg ?>;color:<?= $estFg ?>">
                    <?= $estLabel ?>
                </span>
            </td>
            <td>
                <button onclick='editarCampana(<?= json_encode($c) ?>)' class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="?del=<?= $c['id'] ?>" onclick="return confirm('¿Eliminar esta campaña?')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$campanas): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">Sin campañas todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="max-width:620px">
    <div class="card-title" id="campana-form-titulo"><i class="fas fa-plus"></i> Nueva campaña</div>
    <form method="POST" id="form-campana">
        <input type="hidden" name="campana_id" id="campana-id" value="0">

        <div class="form-group">
            <label class="form-label">Nombre de la campaña <span style="color:var(--danger)">*</span></label>
            <input type="text" name="nombre" id="campana-nombre" class="form-control" placeholder="Ej: Black Friday 2026" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Código <span style="color:var(--danger)">*</span></label>
                <input type="text" name="codigo" id="campana-codigo" class="form-control" placeholder="Ej: BLACKFRIDAY20" style="text-transform:uppercase" required>
            </div>
            <div class="form-group">
                <label class="form-label">Tipo de descuento</label>
                <select name="tipo" id="campana-tipo" class="form-control">
                    <option value="porcentaje">Porcentaje (%)</option>
                    <option value="monto">Monto fijo (S/)</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Valor <span style="color:var(--danger)">*</span></label>
                <input type="number" name="valor" id="campana-valor" class="form-control" step="0.01" min="0" placeholder="20" required>
            </div>
            <div class="form-group">
                <label class="form-label">Compra mínima (S/)</label>
                <input type="number" name="compra_minima" id="campana-compra-minima" class="form-control" step="0.01" min="0" placeholder="0">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Fecha de inicio <span style="color:var(--danger)">*</span></label>
                <input type="date" name="fecha_inicio" id="campana-fecha-inicio" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Fecha de fin <span style="color:var(--danger)">*</span></label>
                <input type="date" name="fecha_fin" id="campana-fecha-fin" class="form-control" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Límite de usos <span style="color:var(--text-muted);font-size:.75rem;font-weight:400">(opcional)</span></label>
            <input type="number" name="limite_usos" id="campana-limite-usos" class="form-control" step="1" min="0" placeholder="Sin límite" style="max-width:200px">
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="activo" id="campana-activo" checked>
                Activa
            </label>
        </div>

        <div style="display:flex;gap:10px">
            <button type="submit" name="save_campana" class="btn btn-primary" id="campana-btn">
                <i class="fas fa-save"></i> Guardar campaña
            </button>
            <button type="button" class="btn btn-secondary" onclick="cancelarEdicionCampana()" id="campana-cancel-btn" style="display:none">
                Cancelar
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function editarCampana(c) {
    document.getElementById('campana-form-titulo').innerHTML = '<i class="fas fa-edit"></i> Editar campaña';
    document.getElementById('campana-id').value = c.id;
    document.getElementById('campana-nombre').value = c.nombre;
    document.getElementById('campana-codigo').value = c.codigo;
    document.getElementById('campana-tipo').value = c.tipo;
    document.getElementById('campana-valor').value = c.valor;
    document.getElementById('campana-compra-minima').value = c.compra_minima;
    document.getElementById('campana-limite-usos').value = c.limite_usos ?? '';
    document.getElementById('campana-fecha-inicio').value = (c.fecha_inicio || '').substring(0, 10);
    document.getElementById('campana-fecha-fin').value = (c.fecha_fin || '').substring(0, 10);
    document.getElementById('campana-activo').checked = c.activo === true;
    document.getElementById('campana-btn').innerHTML = '<i class="fas fa-save"></i> Actualizar campaña';
    document.getElementById('campana-cancel-btn').style.display = '';
    window.scrollTo({top: document.getElementById('form-campana').offsetTop - 20, behavior: 'smooth'});
}

function cancelarEdicionCampana() {
    document.getElementById('campana-form-titulo').innerHTML = '<i class="fas fa-plus"></i> Nueva campaña';
    document.getElementById('form-campana').reset();
    document.getElementById('campana-id').value = 0;
    document.getElementById('campana-activo').checked = true;
    document.getElementById('campana-btn').innerHTML = '<i class="fas fa-save"></i> Guardar campaña';
    document.getElementById('campana-cancel-btn').style.display = 'none';
}
</script>
