<?php
$adminPage  = 'recompensas';
$adminTitle = 'Beneficios / Recompensas';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Crear / editar recompensa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recompensa'])) {
    $id           = intval($_POST['recompensa_id'] ?? 0);
    $nombre       = trim($_POST['nombre'] ?? '');
    $tipo         = ($_POST['tipo'] ?? '') === 'porcentaje' ? 'porcentaje' : 'monto';
    $valor        = floatval($_POST['valor'] ?? 0);
    $costoPuntos  = intval($_POST['costo_puntos'] ?? 0);
    $compraMinima = floatval($_POST['compra_minima'] ?? 0);
    $vigenciaDias = intval($_POST['vigencia_dias'] ?? 0);
    $activo       = isset($_POST['activo']) ? 't' : 'f';

    if (!$nombre) {
        $error = 'El nombre es requerido.';
    } elseif ($valor <= 0) {
        $error = 'El valor del descuento debe ser mayor a 0.';
    } elseif ($tipo === 'porcentaje' && $valor > 100) {
        $error = 'El porcentaje no puede ser mayor a 100.';
    } elseif ($costoPuntos <= 0) {
        $error = 'El costo en puntos debe ser mayor a 0.';
    } elseif ($id) {
        $pdo->prepare('
            UPDATE puntos_recompensas
            SET nombre = ?, tipo = ?, valor = ?, costo_puntos = ?, compra_minima = ?, vigencia_dias = ?, activo = ?
            WHERE id = ?
        ')->execute([$nombre, $tipo, $valor, $costoPuntos, $compraMinima, $vigenciaDias, $activo, $id]);
        $success = 'Recompensa actualizada.';
    } else {
        $pdo->prepare('
            INSERT INTO puntos_recompensas (nombre, tipo, valor, costo_puntos, compra_minima, vigencia_dias, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$nombre, $tipo, $valor, $costoPuntos, $compraMinima, $vigenciaDias, $activo]);
        $success = 'Recompensa creada.';
    }
}

// Eliminar recompensa (no afecta cupones ya emitidos: quedan con su propio snapshot)
if (!empty($_GET['del']) && is_numeric($_GET['del'])) {
    $pdo->prepare('DELETE FROM puntos_recompensas WHERE id = ?')->execute([intval($_GET['del'])]);
    header('Location: ' . BASE_URL . '/admin/recompensas.php?ok=1');
    exit;
}
if (isset($_GET['ok'])) $success = 'Recompensa eliminada.';

$recompensas = $pdo->query('SELECT * FROM puntos_recompensas ORDER BY costo_puntos ASC')->fetchAll();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-award"></i> Beneficios / Recompensas</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:920px">
    <div class="card-title"><i class="fas fa-list"></i> Catálogo de recompensas</div>
    <p style="font-size:.83rem;color:var(--text-muted);margin-bottom:14px">
        Al canjear, el cliente recibe un cupón de un solo uso que puede aplicar en su próximo pedido.
    </p>

    <table class="data-table" style="margin-bottom:20px">
        <thead>
            <tr>
                <th>Nombre</th><th>Descuento</th><th>Costo (pts)</th>
                <th>Compra mínima</th><th>Vigencia</th><th>Estado</th><th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recompensas as $r): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= $r['tipo'] === 'porcentaje' ? number_format($r['valor'], 0) . '%' : 'S/ ' . number_format($r['valor'], 2) ?></td>
            <td><?= (int)$r['costo_puntos'] ?></td>
            <td><?= $r['compra_minima'] > 0 ? 'S/ ' . number_format($r['compra_minima'], 2) : '—' ?></td>
            <td><?= $r['vigencia_dias'] > 0 ? $r['vigencia_dias'] . ' días' : 'No expira' ?></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;<?= $r['activo'] ? 'background:#dcfce7;color:#166534' : 'background:#f1f5f9;color:#94a3b8' ?>">
                    <?= $r['activo'] ? 'Activa' : 'Inactiva' ?>
                </span>
            </td>
            <td>
                <button onclick='editarRecompensa(<?= json_encode($r) ?>)' class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="?del=<?= $r['id'] ?>" onclick="return confirm('¿Eliminar esta recompensa? Los cupones ya emitidos no se ven afectados.')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recompensas): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">Sin recompensas todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="POST" id="form-recompensa">
        <input type="hidden" name="recompensa_id" id="r-id" value="0">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px">
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input type="text" name="nombre" id="r-nombre" class="form-control" placeholder="Ej: S/10 de descuento" required>
            </div>
            <div class="form-group">
                <label class="form-label">Tipo</label>
                <select name="tipo" id="r-tipo" class="form-control">
                    <option value="monto">Monto fijo (S/)</option>
                    <option value="porcentaje">Porcentaje (%)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Valor</label>
                <input type="number" step="0.01" min="0" name="valor" id="r-valor" class="form-control" required>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
            <div class="form-group">
                <label class="form-label">Costo en puntos</label>
                <input type="number" min="1" name="costo_puntos" id="r-costo" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Compra mínima (opcional)</label>
                <input type="number" step="0.01" min="0" name="compra_minima" id="r-minima" class="form-control" placeholder="0 = sin mínimo">
            </div>
            <div class="form-group">
                <label class="form-label">Vigencia del cupón en días (opcional)</label>
                <input type="number" min="0" name="vigencia_dias" id="r-vigencia" class="form-control" placeholder="0 = no expira">
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem">
                <input type="checkbox" name="activo" id="r-activo" value="1" checked>
                Activa
            </label>
            <button type="submit" name="save_recompensa" class="btn btn-primary" id="r-btn">
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function editarRecompensa(r) {
    document.getElementById('r-id').value = r.id;
    document.getElementById('r-nombre').value = r.nombre;
    document.getElementById('r-tipo').value = r.tipo;
    document.getElementById('r-valor').value = r.valor;
    document.getElementById('r-costo').value = r.costo_puntos;
    document.getElementById('r-minima').value = r.compra_minima;
    document.getElementById('r-vigencia').value = r.vigencia_dias;
    document.getElementById('r-activo').checked = r.activo === true || r.activo === 't';
    document.getElementById('r-btn').innerHTML = '<i class="fas fa-save"></i> Actualizar';
    document.getElementById('r-nombre').focus();
}
</script>
