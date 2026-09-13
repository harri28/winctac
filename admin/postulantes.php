<?php
$adminPage  = 'postulantes';
$adminTitle = 'Postulantes';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';

// Cambiar estado de una postulación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    $id     = intval($_POST['postulante_id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if (in_array($estado, ['nueva', 'revisada', 'contactada', 'descartada'], true)) {
        $pdo->prepare('UPDATE postulaciones SET estado = ? WHERE id = ? AND tienda_id = ?')
            ->execute([$estado, $id, TIENDA_ID]);
        $success = 'Estado actualizado.';
    }
}

// Eliminar una postulación (y su CV en disco)
if (!empty($_GET['del']) && is_numeric($_GET['del'])) {
    $id  = intval($_GET['del']);
    $row = $pdo->prepare('SELECT cv_path FROM postulaciones WHERE id = ? AND tienda_id = ?');
    $row->execute([$id, TIENDA_ID]);
    $cvPath = $row->fetchColumn();

    $pdo->prepare('DELETE FROM postulaciones WHERE id = ? AND tienda_id = ?')->execute([$id, TIENDA_ID]);
    if ($cvPath && file_exists(UPLOADS_PATH . '/postulaciones/' . $cvPath)) {
        @unlink(UPLOADS_PATH . '/postulaciones/' . $cvPath);
    }
    $success = 'Postulación eliminada.';
}

$stmt = $pdo->prepare('SELECT * FROM postulaciones WHERE tienda_id = ? ORDER BY created_at DESC');
$stmt->execute([TIENDA_ID]);
$postulantes = $stmt->fetchAll();

$estadosLabel = [
    'nueva'      => ['Nueva', '#dbeafe', '#1e40af'],
    'revisada'   => ['Revisada', '#fef9c3', '#854d0e'],
    'contactada' => ['Contactada', '#dcfce7', '#166534'],
    'descartada' => ['Descartada', '#f1f5f9', '#94a3b8'],
];
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-briefcase"></i> Postulantes</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
        Postulaciones recibidas desde "Trabaja con nosotros" en la página pública.
    </p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>DNI</th>
                <th>Teléfono</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($postulantes as $p): ?>
        <?php [$label, $bg, $fg] = $estadosLabel[$p['estado']] ?? $estadosLabel['nueva']; ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?></td>
            <td style="font-family:monospace"><?= htmlspecialchars($p['dni']) ?></td>
            <td><?= htmlspecialchars($p['telefono']) ?></td>
            <td style="font-size:.82rem;color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="postulante_id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="cambiar_estado" value="1">
                    <select name="estado" class="form-control" style="font-size:.78rem;padding:4px 8px;width:auto;background:<?= $bg ?>;color:<?= $fg ?>;font-weight:600;border:none" onchange="this.form.submit()">
                        <?php foreach ($estadosLabel as $key => [$lbl,,]): ?>
                        <option value="<?= $key ?>" <?= $p['estado'] === $key ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td>
                <a href="<?= BASE_URL ?>/admin/descargar-cv.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-secondary" style="padding:5px 10px;font-size:.75rem" title="Descargar CV">
                    <i class="fas fa-file-pdf"></i>
                </a>
                <a href="?del=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar esta postulación? Esta acción no se puede deshacer.')" class="btn btn-danger" style="padding:5px 10px;font-size:.75rem" title="Eliminar">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$postulantes): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px">Sin postulaciones todavía.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
