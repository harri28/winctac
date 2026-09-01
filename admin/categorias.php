<?php
$adminPage  = 'categorias';
$adminTitle = 'Categorías';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Crear / editar categoría
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_categoria'])) {
    $id     = intval($_POST['categoria_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $activo = isset($_POST['activo']) ? 't' : 'f';

    if (!$nombre) {
        $error = 'El nombre es requerido.';
    } else {
        $dup = $pdo->prepare('SELECT id FROM categorias WHERE LOWER(nombre) = LOWER(?) AND id != ? AND tienda_id = ?');
        $dup->execute([$nombre, $id, TIENDA_ID]);
        if ($dup->fetch()) {
            $error = 'Ya existe una categoría con ese nombre.';
        } elseif ($id) {
            $pdo->prepare('UPDATE categorias SET nombre = ?, activo = ? WHERE id = ? AND tienda_id = ?')->execute([$nombre, $activo, $id, TIENDA_ID]);
            $success = 'Categoría actualizada.';
        } else {
            $pdo->prepare('INSERT INTO categorias (tienda_id, nombre, activo) VALUES (?, ?, ?)')->execute([TIENDA_ID, $nombre, $activo]);
            $success = 'Categoría creada.';
        }
    }
}

// Eliminar categoría (bloqueado si tiene productos)
if (!empty($_GET['del']) && is_numeric($_GET['del'])) {
    $id = intval($_GET['del']);
    $total = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ? AND tienda_id = ?');
    $total->execute([$id, TIENDA_ID]);
    if ($total->fetchColumn() > 0) {
        $error = 'No se puede eliminar: hay productos usando esta categoría.';
    } else {
        $pdo->prepare('DELETE FROM categorias WHERE id = ? AND tienda_id = ?')->execute([$id, TIENDA_ID]);
        header('Location: ' . BASE_URL . '/admin/categorias.php?ok=1');
        exit;
    }
}

if (isset($_GET['ok'])) $success = 'Categoría eliminada.';

$categoriasStmt = $pdo->prepare('
    SELECT c.*, COUNT(p.id) AS total_productos
    FROM categorias c
    LEFT JOIN productos p ON p.categoria_id = c.id AND p.tienda_id = c.tienda_id
    WHERE c.tienda_id = ?
    GROUP BY c.id
    ORDER BY c.nombre ASC
');
$categoriasStmt->execute([TIENDA_ID]);
$categorias = $categoriasStmt->fetchAll();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-tags"></i> Categorías</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:720px">
    <div class="card-title"><i class="fas fa-list"></i> Categorías del catálogo</div>

    <table class="data-table" style="margin-bottom:20px">
        <thead>
            <tr><th>Nombre</th><th>Productos</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($categorias as $c): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($c['nombre']) ?></td>
            <td><?= (int)$c['total_productos'] ?></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;<?= $c['activo'] ? 'background:#dcfce7;color:#166534' : 'background:#f1f5f9;color:#94a3b8' ?>">
                    <?= $c['activo'] ? 'Activa' : 'Inactiva' ?>
                </span>
            </td>
            <td>
                <button onclick='editarCategoria(<?= json_encode($c) ?>)' class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="?del=<?= $c['id'] ?>" onclick="return confirm('¿Eliminar esta categoría?')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$categorias): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">Sin categorías todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="POST" style="display:grid;grid-template-columns:2fr auto auto;gap:12px;align-items:end">
        <div>
            <label class="form-label">Nombre de la categoría</label>
            <input type="text" name="nombre" id="cat-nombre" class="form-control" placeholder="Ej: Bebidas" required>
        </div>
        <div>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem;padding-bottom:9px">
                <input type="checkbox" name="activo" id="cat-activo" value="1" checked>
                Activa
            </label>
        </div>
        <div>
            <input type="hidden" name="categoria_id" id="cat-id" value="0">
            <button type="submit" name="save_categoria" class="btn btn-primary" id="cat-btn">
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function editarCategoria(c) {
    document.getElementById('cat-id').value = c.id;
    document.getElementById('cat-nombre').value = c.nombre;
    document.getElementById('cat-activo').checked = c.activo === true || c.activo === 't';
    document.getElementById('cat-btn').innerHTML = '<i class="fas fa-save"></i> Actualizar';
    document.getElementById('cat-nombre').focus();
}
</script>
