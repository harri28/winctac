<?php
$adminPage  = 'puntos';
$adminTitle = 'Programa de puntos';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_puntos'])) {
    $activo  = isset($_POST['puntos_activo']) ? 't' : 'f';
    $porSol  = floatval($_POST['puntos_por_sol'] ?? 1);
    if ($porSol < 0) {
        $error = 'El ratio de puntos no puede ser negativo.';
    } else {
        $pdo->prepare('UPDATE config SET puntos_activo = ?, puntos_por_sol = ?, updated_at = NOW() WHERE id = 1')
            ->execute([$activo, $porSol]);
        $success = 'Configuración de puntos guardada.';
    }
}

$cfg = $pdo->query('SELECT puntos_activo, puntos_por_sol FROM config WHERE id = 1')->fetch();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-star"></i> Programa de puntos</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:520px">
    <div class="card-title"><i class="fas fa-sliders-h"></i> Configuración</div>
    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:18px">
        Los puntos se acreditan cuando un pedido pasa a estado <strong>Confirmado</strong> y se calculan
        sobre el total del pedido (incluye envío), redondeando hacia abajo. Si el pedido se cancela después,
        los puntos otorgados se revierten automáticamente.
    </p>

    <form method="POST">
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="puntos_activo" value="1" <?= !empty($cfg['puntos_activo']) ? 'checked' : '' ?>>
                Programa de puntos activo
            </label>
        </div>
        <div class="form-group">
            <label class="form-label">Puntos por cada S/ 1 gastado</label>
            <input type="number" step="0.01" min="0" name="puntos_por_sol" class="form-control"
                   value="<?= htmlspecialchars($cfg['puntos_por_sol'] ?? '1.00') ?>" style="max-width:180px">
            <p style="font-size:.78rem;color:var(--text-muted);margin-top:6px">
                Ej: 1.00 = un cliente que paga S/ 50.00 gana 50 puntos.
            </p>
        </div>
        <button type="submit" name="save_puntos" class="btn btn-primary">
            <i class="fas fa-save"></i> Guardar
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
