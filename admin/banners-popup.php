<?php
// Los handlers de ?toggle y ?del hacen header('Location: ...'), así que tienen
// que correr ANTES de que includes/header.php emita HTML — si no, PHP tira
// "Cannot modify header information - headers already sent". Por eso aquí
// cargamos app.php + database.php y hacemos la auth mínima a mano, procesamos
// los GET redirects, y recién después incluimos el header admin.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}

$pdo = getDB();

// ── Alternar activo/inactivo rápido ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfTokenValido($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['toggle'] ?? '')) {
    $pdo->prepare('UPDATE banners_popup SET activo = NOT activo WHERE id = ? AND tienda_id = ?')
        ->execute([intval($_POST['toggle']), TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners-popup.php?ok=1'); exit;
}

// ── Eliminar ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['del'] ?? '')) {
    $delId = intval($_POST['del']);
    $img = $pdo->prepare('SELECT imagen_path FROM banners_popup WHERE id = ? AND tienda_id = ?');
    $img->execute([$delId, TIENDA_ID]);
    $fname = $img->fetchColumn();
    if ($fname && file_exists(UPLOADS_PATH . '/' . $fname)) @unlink(UPLOADS_PATH . '/' . $fname);
    $pdo->prepare('DELETE FROM banners_popup WHERE id = ? AND tienda_id = ?')->execute([$delId, TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners-popup.php?ok=1'); exit;
}

// A partir de aquí ya podemos emitir HTML.
$adminPage  = 'banners_popup';
$adminTitle = 'Banners pop-up';
require_once __DIR__ . '/includes/header.php';

$success = '';
$error   = '';

// ── Crear/actualizar banner (POST, no redirige) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_popup'])) {
    $id          = intval($_POST['popup_id'] ?? 0);
    $enlaceUrl   = trim($_POST['enlace_url'] ?? '');
    $enlaceLabel = trim($_POST['enlace_label'] ?? '');
    $activo      = isset($_POST['activo']) ? 't' : 'f';

    // Validar enlace_label si hay URL (y viceversa): o ambos vacíos, o ambos llenos.
    if (($enlaceUrl !== '' && $enlaceLabel === '') || ($enlaceUrl === '' && $enlaceLabel !== '')) {
        $error = 'Si configuras un botón, llena tanto la URL como el texto del botón (o deja ambos vacíos).';
    } else {
        // Imagen: obligatoria al crear, opcional al editar (mantiene la anterior).
        $imagenPath = null;
        if ($id > 0) {
            $cur = $pdo->prepare('SELECT imagen_path FROM banners_popup WHERE id = ? AND tienda_id = ?');
            $cur->execute([$id, TIENDA_ID]);
            $imagenPath = $cur->fetchColumn() ?: null;
        }

        if (!empty($_FILES['imagen']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
                $fname = 'popup_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], UPLOADS_PATH . '/' . $fname)) {
                    // Si estamos editando y había imagen vieja, la borramos del disco.
                    if ($imagenPath && file_exists(UPLOADS_PATH . '/' . $imagenPath)) {
                        @unlink(UPLOADS_PATH . '/' . $imagenPath);
                    }
                    $imagenPath = $fname;
                } else {
                    $error = 'No se pudo guardar la imagen.';
                }
            } else {
                $error = 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.';
            }
        }

        if (!$error && $id === 0 && !$imagenPath) {
            $error = 'La imagen del banner es obligatoria al crearlo.';
        }

        if (!$error) {
            if ($id > 0) {
                $pdo->prepare('UPDATE banners_popup SET imagen_path = ?, enlace_url = ?, enlace_label = ?, activo = ? WHERE id = ? AND tienda_id = ?')
                    ->execute([$imagenPath, $enlaceUrl, $enlaceLabel, $activo, $id, TIENDA_ID]);
                $success = 'Banner pop-up actualizado.';
            } else {
                $pdo->prepare('INSERT INTO banners_popup (tienda_id, imagen_path, enlace_url, enlace_label, activo) VALUES (?, ?, ?, ?, ?)')
                    ->execute([TIENDA_ID, $imagenPath, $enlaceUrl, $enlaceLabel, $activo]);
                $success = 'Banner pop-up creado. Si ya tenías uno activo, apágalo para que no se vean los dos a la vez.';
            }
        }
    }
}

$banners = $pdo->prepare('SELECT * FROM banners_popup WHERE tienda_id = ? ORDER BY activo DESC, created_at DESC');
$banners->execute([TIENDA_ID]);
$banners = $banners->fetchAll();

// Modo edición: si viene ?edit=<id>, carga el banner y precarga el form.
// Si no, el form queda en modo "Crear nuevo".
$editBanner = null;
if ($_GET['edit'] ?? '') {
    $e = $pdo->prepare('SELECT * FROM banners_popup WHERE id = ? AND tienda_id = ?');
    $e->execute([intval($_GET['edit']), TIENDA_ID]);
    $editBanner = $e->fetch() ?: null;
}
?>

<?php adminSeccionTabs('configuracion'); ?>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (isset($_GET['ok'])): ?><div class="alert alert-success">Cambios guardados.</div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-info-circle"></i> ¿Qué son los banners pop-up?</div>
    <p style="color:var(--text-muted);font-size:.9rem;line-height:1.55;margin:0">
        Una ventana centrada que aparece cuando una persona entra a tu web. Útil para destacar una promoción, campaña
        o aviso importante. Se cierra con la X y el visitante no vuelve a verlo (lo recuerda el navegador por su id).
        Si publicas un banner nuevo, vuelve a mostrarse. <strong>Solo el banner activo más reciente se muestra</strong> —
        si tienes varios activos, activa solo uno a la vez.
    </p>
</div>

<!-- Lista de banners existentes -->
<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-list"></i> Banners existentes</div>

    <?php if (!$banners): ?>
        <div style="text-align:center;color:var(--text-muted);padding:20px 0">
            Aún no tienes banners pop-up. Crea el primero en el formulario de abajo.
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Imagen</th>
                    <th>Botón</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($banners as $b): ?>
            <tr>
                <td>
                    <?php if ($b['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $b['imagen_path'])): ?>
                    <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($b['imagen_path']) ?>" style="height:60px;border-radius:6px;background:#f1f5f9">
                    <?php else: ?>
                    <span style="color:var(--text-light)">— sin imagen —</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['enlace_url']): ?>
                    <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($b['enlace_label']) ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted);word-break:break-all"><?= htmlspecialchars($b['enlace_url']) ?></div>
                    <?php else: ?>
                    <span style="color:var(--text-light);font-size:.82rem">(sin botón)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['activo']): ?>
                    <span style="background:var(--success-bg);color:var(--success);padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600">
                        <i class="fas fa-circle" style="font-size:.5rem"></i> Activo
                    </span>
                    <?php else: ?>
                    <span style="background:var(--surface-3);color:var(--text-muted);padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600">
                        Inactivo
                    </span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem;color:var(--text-muted)">
                    <?= date('d/m/Y', strtotime($b['created_at'])) ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="?edit=<?= $b['id'] ?>#form-banner" class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem" title="Editar">
                        <i class="fas fa-pencil-alt"></i>
                    </a>
                    <a href="?toggle=<?= $b['id'] ?>" class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem" title="<?= $b['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fas fa-<?= $b['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                    </a>
                    <a href="?del=<?= $b['id'] ?>" onclick="return confirm('¿Eliminar este banner pop-up? La imagen también se borra del disco.')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem" title="Eliminar">
                        <i class="fas fa-trash"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Formulario crear/editar -->
<div class="card" id="form-banner">
    <div class="card-title">
        <?php if ($editBanner): ?>
            <i class="fas fa-pencil-alt"></i> Editando banner #<?= (int) $editBanner['id'] ?>
            &nbsp;<a href="<?= BASE_URL ?>/admin/banners-popup.php" style="font-size:.78rem;color:var(--text-muted);font-weight:400">(cancelar edición)</a>
        <?php else: ?>
            <i class="fas fa-plus-circle"></i> Crear nuevo banner pop-up
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px">
        <input type="hidden" name="popup_id" value="<?= $editBanner ? (int) $editBanner['id'] : 0 ?>">

        <div class="form-group">
            <label class="form-label">
                Imagen del banner
                <?php if (!$editBanner): ?><span style="color:var(--danger)">*</span><?php endif; ?>
            </label>
            <?php if ($editBanner && $editBanner['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $editBanner['imagen_path'])): ?>
            <div style="margin-bottom:8px">
                <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($editBanner['imagen_path']) ?>" style="max-height:120px;border-radius:6px;background:#f1f5f9;border:1px solid var(--border)">
            </div>
            <?php endif; ?>
            <!-- Sin "required" client-side: al editar, la imagen ya está en BD y no hay que re-subir;
                 al crear nuevo, el handler PHP valida la obligatoriedad server-side y devuelve error claro. -->
            <input type="file" name="imagen" class="form-control" accept="image/*">
            <div class="form-hint">
                <?php if ($editBanner): ?>
                    <strong>Deja este campo vacío para conservar la imagen actual.</strong> Sube uno nuevo solo si quieres reemplazarlo.
                <?php else: ?>
                    Recomendado: imagen horizontal rectangular, ~800x600 px, JPG o PNG. Se escala automáticamente al mostrarla.
                    Si incluyes el diseño completo (título y oferta en la propia imagen), no necesitas el botón.
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Texto del botón (opcional)</label>
                <input type="text" name="enlace_label" class="form-control" placeholder="Ver catálogo" maxlength="50" value="<?= htmlspecialchars($editBanner['enlace_label'] ?? '') ?>">
                <div class="form-hint">Si no quieres botón, deja vacíos ambos campos.</div>
            </div>
            <div class="form-group">
                <label class="form-label">URL del botón (opcional)</label>
                <input type="url" name="enlace_url" class="form-control" placeholder="https://wintac.shop/catalogo o /catalogo" value="<?= htmlspecialchars($editBanner['enlace_url'] ?? '') ?>">
                <div class="form-hint">URL absoluta (https://…) o relativa (/catalogo, /producto/123).</div>
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="activo" value="1" <?= (!$editBanner || !empty($editBanner['activo'])) ? 'checked' : '' ?>>
            <strong>Mostrar activo</strong> &nbsp;<span style="color:var(--text-muted);font-size:.85rem">— si ya hay otro activo, apágalo antes para no mostrar los dos</span>
        </label>

        <div>
            <button type="submit" name="save_popup" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editBanner ? 'Guardar cambios' : 'Crear banner pop-up' ?>
            </button>
            <?php if ($editBanner): ?>
            <a href="<?= BASE_URL ?>/admin/banners-popup.php" class="btn btn-secondary" style="margin-left:6px">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
