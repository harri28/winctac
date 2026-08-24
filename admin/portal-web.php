<?php
$adminPage  = 'portal_web';
$adminTitle = 'Portal Web';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$cfg = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_portal'])) {
    if (!empty($_FILES['hero']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['hero']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $fname = 'portal_hero_' . time() . '.' . $ext;
            $dest  = UPLOADS_PATH . '/' . $fname;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['hero']['tmp_name'], $dest)) {
                $pdo->prepare('UPDATE config SET portal_hero_path = ?, updated_at = NOW() WHERE id = 1')
                    ->execute([$fname]);
                $cfg = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch();
                $success = 'Imagen de portada actualizada correctamente.';
            }
        }
    }
}
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-globe"></i> Portal Web</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="card" style="max-width:640px">
    <div class="card-title"><i class="fas fa-image"></i> Imagen de portada — página "Nosotros"</div>
    <div class="form-hint" style="margin-bottom:16px">
        Foto grande que se muestra en el encabezado de la página pública "Nosotros"
        (tienda, local, equipo, etc.)
    </div>

    <?php if (!empty($cfg['portal_hero_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['portal_hero_path'])): ?>
    <div class="form-group">
        <label class="form-label">Imagen actual</label>
        <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['portal_hero_path']) ?>" style="max-width:100%;max-height:220px;border-radius:var(--radius);display:block">
    </div>
    <?php endif; ?>

    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Reemplazar imagen</label>
        <input type="file" name="hero" class="form-control" accept="image/jpeg,image/png,image/webp">
    </div>
</div>

<div style="margin-top:20px;max-width:640px">
    <button type="submit" name="save_portal" class="btn btn-primary btn-lg">
        <i class="fas fa-save"></i> Guardar
    </button>
    <a href="<?= BASE_URL ?>/nosotros.php" target="_blank" class="btn btn-secondary btn-lg">
        <i class="fas fa-eye"></i> Ver página
    </a>
</div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
