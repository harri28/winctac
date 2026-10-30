<?php
$adminPage  = 'mensajes';
$adminTitle = 'Mensajes de contacto';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';

// Cambiar estado de un mensaje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    $id     = intval($_POST['mensaje_id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if (in_array($estado, ['nuevo', 'leido', 'respondido', 'archivado'], true)) {
        $pdo->prepare('UPDATE mensajes_contacto SET estado = ? WHERE id = ? AND tienda_id = ?')
            ->execute([$estado, $id, TIENDA_ID]);
        $success = 'Estado actualizado.';
    }
}

// Eliminar un mensaje
if (!empty($_GET['del']) && is_numeric($_GET['del'])) {
    $pdo->prepare('DELETE FROM mensajes_contacto WHERE id = ? AND tienda_id = ?')
        ->execute([intval($_GET['del']), TIENDA_ID]);
    $success = 'Mensaje eliminado.';
}

$stmt = $pdo->prepare('SELECT * FROM mensajes_contacto WHERE tienda_id = ? ORDER BY created_at DESC');
$stmt->execute([TIENDA_ID]);
$mensajes = $stmt->fetchAll();

$estadosLabel = [
    'nuevo'      => ['Nuevo', '#dbeafe', '#1e40af'],
    'leido'      => ['Leído', '#fef9c3', '#854d0e'],
    'respondido' => ['Respondido', '#dcfce7', '#166534'],
    'archivado'  => ['Archivado', '#f1f5f9', '#94a3b8'],
];
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-envelope-open-text"></i> Mensajes de contacto</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
        Mensajes recibidos desde el formulario de la página pública "Contacto".
    </p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Remitente</th>
                <th>Asunto / mensaje</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mensajes as $m): ?>
        <?php [$label, $bg, $fg] = $estadosLabel[$m['estado']] ?? $estadosLabel['nuevo']; ?>
        <tr>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($m['nombre']) ?></div>
                <div style="font-size:.8rem"><a href="mailto:<?= htmlspecialchars($m['email']) ?>"><?= htmlspecialchars($m['email']) ?></a></div>
                <?php if ($m['telefono']): ?>
                <div style="font-size:.8rem;color:var(--text-muted)"><?= htmlspecialchars($m['telefono']) ?></div>
                <?php endif; ?>
            </td>
            <td style="max-width:420px">
                <?php if ($m['asunto']): ?>
                <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($m['asunto']) ?></div>
                <?php endif; ?>
                <div style="font-size:.82rem;color:var(--text-muted);white-space:pre-line"><?= htmlspecialchars($m['mensaje']) ?></div>
            </td>
            <td style="font-size:.82rem;color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="mensaje_id" value="<?= $m['id'] ?>">
                    <input type="hidden" name="cambiar_estado" value="1">
                    <select name="estado" class="form-control" style="font-size:.78rem;padding:4px 8px;width:auto;background:<?= $bg ?>;color:<?= $fg ?>;font-weight:600;border:none" onchange="this.form.submit()">
                        <?php foreach ($estadosLabel as $key => [$lbl,,]): ?>
                        <option value="<?= $key ?>" <?= $m['estado'] === $key ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td>
                <a href="mailto:<?= htmlspecialchars($m['email']) ?>?subject=<?= rawurlencode('Re: ' . ($m['asunto'] ?: 'Tu consulta')) ?>" class="btn btn-secondary" style="padding:5px 10px;font-size:.75rem" title="Responder por correo">
                    <i class="fas fa-reply"></i>
                </a>
                <a href="?del=<?= $m['id'] ?>" onclick="return confirm('¿Eliminar este mensaje? Esta acción no se puede deshacer.')" class="btn btn-danger" style="padding:5px 10px;font-size:.75rem" title="Eliminar">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$mensajes): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:40px">Sin mensajes todavía.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
