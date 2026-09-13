<?php
$adminPage  = 'portal_web';
$adminTitle = 'Portal Web';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$cfgStmt = $pdo->prepare('SELECT * FROM config WHERE id = ?');
$cfgStmt->execute([TIENDA_ID]);
$cfg = $cfgStmt->fetch();
$success = '';

$numProdStmt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE activo = TRUE AND tienda_id = ?');
$numProdStmt->execute([TIENDA_ID]);
$numProductosReal = (int) $numProdStmt->fetchColumn();

$numCatStmt = $pdo->prepare('SELECT COUNT(*) FROM categorias WHERE activo = TRUE AND tienda_id = ?');
$numCatStmt->execute([TIENDA_ID]);
$numCategoriasReal = (int) $numCatStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_portal'])) {
    $vision        = sanitizarRichText(trim($_POST['portal_vision'] ?? ''));
    $mision        = sanitizarRichText(trim($_POST['portal_mision'] ?? ''));
    $historia      = sanitizarRichText(trim($_POST['portal_historia'] ?? ''));
    $statProductos = trim(mb_substr($_POST['portal_stat_productos'] ?? '', 0, 20));
    $statCategorias = trim(mb_substr($_POST['portal_stat_categorias'] ?? '', 0, 20));
    $direccion     = trim(mb_substr($_POST['portal_direccion'] ?? '', 0, 300));

    $pdo->prepare('UPDATE config SET portal_vision = ?, portal_mision = ?, portal_historia = ?, portal_stat_productos = ?, portal_stat_categorias = ?, portal_direccion = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$vision, $mision, $historia, $statProductos, $statCategorias, $direccion, TIENDA_ID]);
    $success = 'Contenido actualizado correctamente.';

    if (!empty($_FILES['hero']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['hero']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $fname = 'portal_hero_' . time() . '.' . $ext;
            $dest  = UPLOADS_PATH . '/' . $fname;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['hero']['tmp_name'], $dest)) {
                $pdo->prepare('UPDATE config SET portal_hero_path = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$fname, TIENDA_ID]);
                $success = 'Imagen de portada y contenido actualizados correctamente.';
            }
        }
    }

    $cfgStmt->execute([TIENDA_ID]);
    $cfg = $cfgStmt->fetch();
}
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-globe"></i> Portal Web</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php
// Imprime la barra de herramientas + el editor + el input oculto que se
// sincroniza justo antes de enviar el formulario, para un campo dado.
$__rte = function (string $field, string $valor, int $minRows) {
    $lineHeight = 20;
    $minHeight  = $minRows * $lineHeight + 16;
    ?>
    <div class="rte-toolbar">
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="bold" title="Negrita"><i class="fas fa-bold"></i></button>
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="italic" title="Cursiva"><i class="fas fa-italic"></i></button>
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="justifyLeft" title="Alinear a la izquierda"><i class="fas fa-align-left"></i></button>
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="justifyCenter" title="Centrar"><i class="fas fa-align-center"></i></button>
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="justifyRight" title="Alinear a la derecha"><i class="fas fa-align-right"></i></button>
        <button type="button" class="rte-btn" data-target="rte-<?= $field ?>" data-cmd="justifyFull" title="Justificar texto"><i class="fas fa-align-justify"></i></button>
    </div>
    <div id="rte-<?= $field ?>" class="form-control rte-editable" style="min-height:<?= $minHeight ?>px" contenteditable="true"><?= renderRichText($valor) ?></div>
    <input type="hidden" name="portal_<?= $field ?>" id="rte-<?= $field ?>-input">
    <?php
};
?>

<form method="POST" enctype="multipart/form-data">
<div class="portal-web-grid">
<div class="portal-web-col-left">
<div class="card">
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

<div class="card">
    <div class="card-title"><i class="fas fa-chart-simple"></i> Estadísticas</div>
    <div class="form-hint" style="margin-bottom:16px">
        Cifras que se muestran en "Nosotros". Déjalas vacías para usar el conteo real de tu catálogo.
    </div>

    <div class="form-group">
        <label class="form-label">Productos</label>
        <input type="text" name="portal_stat_productos" class="form-control" maxlength="20"
               placeholder="<?= $numProductosReal ?>+" value="<?= htmlspecialchars($cfg['portal_stat_productos'] ?? '') ?>">
        <div class="form-hint">Automático ahora mismo: <?= $numProductosReal ?>+ productos activos</div>
    </div>

    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Categorías</label>
        <input type="text" name="portal_stat_categorias" class="form-control" maxlength="20"
               placeholder="<?= $numCategoriasReal ?>" value="<?= htmlspecialchars($cfg['portal_stat_categorias'] ?? '') ?>">
        <div class="form-hint">Automático ahora mismo: <?= $numCategoriasReal ?> categorías activas</div>
    </div>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-map-location-dot"></i> Encuéntranos</div>
    <div class="form-hint" style="margin-bottom:16px">
        Muestra un mapa y tu correo/celular de contacto en "Nosotros". Déjalo vacío para ocultar esta sección.
    </div>

    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Dirección</label>
        <input type="text" name="portal_direccion" class="form-control" maxlength="300"
               placeholder="Jr. Ejemplo 123, Moyobamba, San Martín" value="<?= htmlspecialchars($cfg['portal_direccion'] ?? '') ?>">
        <div class="form-hint">
            El correo y celular que se muestran junto al mapa son los de
            <a href="<?= BASE_URL ?>/admin/config.php">Configuración → Contacto</a>.
        </div>
    </div>
</div>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-file-lines"></i> Visión, Misión y ¿Quiénes somos?</div>
    <div class="form-hint" style="margin-bottom:16px">
        Contenido que se muestra en la página pública "Nosotros"
    </div>

    <div class="form-group">
        <label class="form-label">Visión</label>
        <?php $__rte('vision', $cfg['portal_vision'] ?? '', 3) ?>
    </div>

    <div class="form-group">
        <label class="form-label">Misión</label>
        <?php $__rte('mision', $cfg['portal_mision'] ?? '', 3) ?>
    </div>

    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">¿Quiénes somos?</label>
        <?php $__rte('historia', $cfg['portal_historia'] ?? '', 4) ?>
    </div>
</div>
</div>

<div style="margin-top:20px;display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px">
    <button type="submit" name="save_portal" class="btn btn-primary btn-lg">
        <i class="fas fa-save"></i> Guardar
    </button>
    <a href="<?= BASE_URL ?>/nosotros.php" target="_blank" class="btn btn-secondary btn-lg">
        <i class="fas fa-eye"></i> Ver página
    </a>
</div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
document.querySelectorAll('.rte-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target);
        target.focus();
        document.execCommand(btn.dataset.cmd, false, null);
    });
});

document.querySelector('form').addEventListener('submit', () => {
    ['vision', 'mision', 'historia'].forEach(f => {
        document.getElementById('rte-' + f + '-input').value = document.getElementById('rte-' + f).innerHTML;
    });
});
</script>
