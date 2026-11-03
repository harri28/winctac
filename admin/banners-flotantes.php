<?php
// Igual que banners-popup.php: los handlers de ?toggle, ?del y ?export_csv
// hacen header()/output antes de HTML, así que corren ANTES de incluir el
// header admin.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}

$pdo = getDB();

// ── Alternar activo ────────────────────────────────────────────────────────
if ($_GET['toggle'] ?? '') {
    $pdo->prepare('UPDATE banners_flotantes SET activo = NOT activo WHERE id = ? AND tienda_id = ?')
        ->execute([intval($_GET['toggle']), TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners-flotantes.php?ok=1'); exit;
}

// ── Eliminar banner ────────────────────────────────────────────────────────
if ($_GET['del'] ?? '') {
    $delId = intval($_GET['del']);
    $img = $pdo->prepare('SELECT imagen_path FROM banners_flotantes WHERE id = ? AND tienda_id = ?');
    $img->execute([$delId, TIENDA_ID]);
    $fname = $img->fetchColumn();
    if ($fname && file_exists(UPLOADS_PATH . '/' . $fname)) @unlink(UPLOADS_PATH . '/' . $fname);
    $pdo->prepare('DELETE FROM banners_flotantes WHERE id = ? AND tienda_id = ?')->execute([$delId, TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners-flotantes.php?ok=1'); exit;
}

// ── Exportar leads a CSV ───────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_banners_flotantes_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha', 'Nombre', 'Apellido', 'Email', 'Celular', 'Género', 'Cumpleaños', 'Código entregado']);
    $leads = $pdo->prepare('SELECT * FROM banners_flotantes_leads WHERE tienda_id = ? ORDER BY created_at DESC');
    $leads->execute([TIENDA_ID]);
    foreach ($leads as $l) {
        fputcsv($out, [
            date('Y-m-d H:i', strtotime($l['created_at'])),
            $l['nombre'], $l['apellido'], $l['email'], $l['celular'],
            $l['genero'], $l['cumpleanos'], $l['codigo_entregado'],
        ]);
    }
    fclose($out);
    exit;
}

$adminPage  = 'banners_flotantes';
$adminTitle = 'Banners flotantes';
require_once __DIR__ . '/includes/header.php';

$success = '';
$error   = '';

// ── Guardar banner (crear o actualizar) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banner'])) {
    $id            = intval($_POST['banner_id'] ?? 0);
    $titulo        = trim($_POST['titulo'] ?? '');
    $subtitulo     = trim($_POST['subtitulo'] ?? '');
    $colorFondo    = trim($_POST['color_fondo'] ?? '#fcd34d');
    $colorTexto    = trim($_POST['color_texto'] ?? '#1e293b');
    $campanaId     = !empty($_POST['campana_id']) ? intval($_POST['campana_id']) : null;
    $botonTexto    = trim($_POST['boton_texto'] ?? 'Enviar');
    $mensajeExito  = trim($_POST['mensaje_exito'] ?? '¡Gracias por suscribirte!');
    $activo        = isset($_POST['activo']) ? 't' : 'f';
    $flags = [
        'mostrar_nombre'     => isset($_POST['mostrar_nombre'])     ? 't' : 'f',
        'mostrar_apellido'   => isset($_POST['mostrar_apellido'])   ? 't' : 'f',
        'mostrar_celular'    => isset($_POST['mostrar_celular'])    ? 't' : 'f',
        'mostrar_genero'     => isset($_POST['mostrar_genero'])     ? 't' : 'f',
        'mostrar_cumpleanos' => isset($_POST['mostrar_cumpleanos']) ? 't' : 'f',
    ];

    if (!$titulo) {
        $error = 'El título es obligatorio.';
    } else {
        // Mantener imagen actual si no se sube una nueva
        $imagenPath = '';
        if ($id > 0) {
            $cur = $pdo->prepare('SELECT imagen_path FROM banners_flotantes WHERE id = ? AND tienda_id = ?');
            $cur->execute([$id, TIENDA_ID]);
            $imagenPath = $cur->fetchColumn() ?: '';
        }

        if (!empty($_FILES['imagen']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
                $fname = 'bflotante_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], UPLOADS_PATH . '/' . $fname)) {
                    if ($imagenPath && file_exists(UPLOADS_PATH . '/' . $imagenPath)) @unlink(UPLOADS_PATH . '/' . $imagenPath);
                    $imagenPath = $fname;
                } else {
                    $error = 'No se pudo guardar la imagen.';
                }
            } else {
                $error = 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.';
            }
        }

        if (!$error) {
            $params = [
                $titulo, $subtitulo, $imagenPath, $colorFondo, $colorTexto, $campanaId,
                $botonTexto, $mensajeExito,
                $flags['mostrar_nombre'], $flags['mostrar_apellido'], $flags['mostrar_celular'],
                $flags['mostrar_genero'], $flags['mostrar_cumpleanos'], $activo,
            ];
            if ($id > 0) {
                $params[] = $id;
                $params[] = TIENDA_ID;
                $pdo->prepare('UPDATE banners_flotantes SET
                    titulo = ?, subtitulo = ?, imagen_path = ?, color_fondo = ?, color_texto = ?, campana_id = ?,
                    boton_texto = ?, mensaje_exito = ?,
                    mostrar_nombre = ?, mostrar_apellido = ?, mostrar_celular = ?, mostrar_genero = ?, mostrar_cumpleanos = ?,
                    activo = ?
                    WHERE id = ? AND tienda_id = ?')
                    ->execute($params);
                $success = 'Banner flotante actualizado.';
            } else {
                array_unshift($params, TIENDA_ID);
                $pdo->prepare('INSERT INTO banners_flotantes
                    (tienda_id, titulo, subtitulo, imagen_path, color_fondo, color_texto, campana_id, boton_texto, mensaje_exito,
                     mostrar_nombre, mostrar_apellido, mostrar_celular, mostrar_genero, mostrar_cumpleanos, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute($params);
                $success = 'Banner flotante creado.';
            }
        }
    }
}

// Datos para render
$banners = $pdo->prepare('SELECT bf.*, c.codigo AS cup_codigo FROM banners_flotantes bf
                          LEFT JOIN campanas c ON c.id = bf.campana_id
                          WHERE bf.tienda_id = ? ORDER BY bf.activo DESC, bf.created_at DESC');
$banners->execute([TIENDA_ID]);
$banners = $banners->fetchAll();

$campanas = $pdo->prepare('SELECT id, codigo, nombre FROM campanas WHERE tienda_id = ? AND activo = TRUE ORDER BY created_at DESC');
$campanas->execute([TIENDA_ID]);
$campanas = $campanas->fetchAll();

$leads = $pdo->prepare('SELECT * FROM banners_flotantes_leads WHERE tienda_id = ? ORDER BY created_at DESC LIMIT 50');
$leads->execute([TIENDA_ID]);
$leads = $leads->fetchAll();

$totalLeads = (int) $pdo->query('SELECT COUNT(*) FROM banners_flotantes_leads WHERE tienda_id = ' . TIENDA_ID)->fetchColumn();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-envelope-open-text"></i> Banners flotantes</h1>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (isset($_GET['ok'])): ?><div class="alert alert-success">Cambios guardados.</div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-info-circle"></i> ¿Qué son los banners flotantes?</div>
    <p style="color:var(--text-muted);font-size:.9rem;line-height:1.55;margin:0">
        Un modal con imagen a la izquierda y formulario de captura de leads a la derecha. El visitante llena sus datos
        (nombre, correo, etc.) y a cambio recibe el código de un cupón para usar en checkout. Útil para listas de suscriptores
        y campañas tipo "10% off en tu primera compra". Si hay un banner flotante activo <strong>y</strong> un banner pop-up simple activo
        al mismo tiempo, el flotante gana prioridad.
    </p>
    <?php if (!$campanas): ?>
    <div class="alert alert-warning" style="margin-top:12px">
        <strong>Primero crea un cupón.</strong> Entra a <a href="<?= BASE_URL ?>/admin/campanas.php">Marketing → Campañas</a> y crea un código
        (ej. <code>BIENVENIDA10</code>) con el descuento que quieras entregar. Luego vuelve aquí y lo asocias al banner.
    </div>
    <?php endif; ?>
</div>

<!-- Banners existentes -->
<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-list"></i> Banners existentes</div>

    <?php if (!$banners): ?>
        <div style="text-align:center;color:var(--text-muted);padding:20px 0">
            Aún no tienes banners flotantes. Crea el primero más abajo.
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Imagen</th>
                    <th>Título</th>
                    <th>Cupón asociado</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($banners as $b): ?>
            <tr>
                <td>
                    <?php if ($b['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $b['imagen_path'])): ?>
                    <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($b['imagen_path']) ?>" style="height:50px;border-radius:6px;background:#f1f5f9">
                    <?php else: ?>
                    <span style="color:var(--text-light);font-size:.8rem">— sin imagen —</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($b['titulo']) ?></div>
                    <?php if ($b['subtitulo']): ?>
                    <div style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($b['subtitulo']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['cup_codigo']): ?>
                    <code style="background:var(--surface-3);padding:2px 8px;border-radius:4px"><?= htmlspecialchars($b['cup_codigo']) ?></code>
                    <?php else: ?>
                    <span style="color:var(--text-light);font-size:.82rem">(sin cupón)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['activo']): ?>
                    <span style="background:var(--success-bg);color:var(--success);padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600">Activo</span>
                    <?php else: ?>
                    <span style="background:var(--surface-3);color:var(--text-muted);padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600">Inactivo</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="?toggle=<?= $b['id'] ?>" class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem" title="<?= $b['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fas fa-<?= $b['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                    </a>
                    <a href="?del=<?= $b['id'] ?>" onclick="return confirm('¿Eliminar este banner flotante? Los leads capturados no se borran.')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem" title="Eliminar">
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
<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-plus-circle"></i> Crear nuevo banner flotante</div>

    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px">
        <input type="hidden" name="banner_id" value="0">

        <div class="form-group">
            <label class="form-label">Título <span style="color:var(--danger)">*</span></label>
            <input type="text" name="titulo" class="form-control" placeholder="Suscríbete y obtén 10% de descuento" required maxlength="200">
        </div>

        <div class="form-group">
            <label class="form-label">Subtítulo</label>
            <input type="text" name="subtitulo" class="form-control" placeholder="En tu primera compra" maxlength="300">
        </div>

        <div class="form-group">
            <label class="form-label">Imagen del banner (lado izquierdo)</label>
            <input type="file" name="imagen" class="form-control" accept="image/*">
            <div class="form-hint">Recomendado: imagen vertical ~400x600 px. En móvil se oculta.</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Color de fondo</label>
                <input type="color" name="color_fondo" value="#fcd34d" style="width:70px;height:38px;padding:2px;cursor:pointer">
            </div>
            <div class="form-group">
                <label class="form-label">Color del texto</label>
                <input type="color" name="color_texto" value="#1e293b" style="width:70px;height:38px;padding:2px;cursor:pointer">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Cupón que se entrega al suscribirse</label>
            <select name="campana_id" class="form-control">
                <option value="">— Ninguno (solo guardar el lead) —</option>
                <?php foreach ($campanas as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['codigo']) ?> — <?= htmlspecialchars($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-hint">Si eliges un cupón, al suscribirse el visitante lo verá en pantalla para usar en el checkout.</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px">
            <div class="form-group">
                <label class="form-label">Texto del botón</label>
                <input type="text" name="boton_texto" class="form-control" value="Enviar" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label">Mensaje de éxito</label>
                <input type="text" name="mensaje_exito" class="form-control" value="¡Gracias por suscribirte! Usa este código en tu primera compra:" maxlength="300">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" style="margin-bottom:8px"><strong>Campos del formulario</strong></label>
            <div style="font-size:.85rem;color:var(--text-muted);line-height:1.6;background:var(--surface-3);padding:12px 14px;border-radius:6px">
                El formulario siempre pide: <strong>Nombre, Apellido, Correo, Número de celular, Fecha de cumpleaños</strong>.
                Debajo del botón se muestran los enlaces legales a los <strong>Términos y condiciones</strong>
                y a la <strong>Política de privacidad</strong>.
            </div>
            <!-- Mantenemos los flags en TRUE siempre; el frontend ignora estas columnas y usa los 5 campos fijos. -->
            <input type="hidden" name="mostrar_nombre" value="1">
            <input type="hidden" name="mostrar_apellido" value="1">
            <input type="hidden" name="mostrar_celular" value="1">
            <input type="hidden" name="mostrar_cumpleanos" value="1">
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="activo" value="1" checked>
            <strong>Mostrar activo</strong> &nbsp;<span style="color:var(--text-muted);font-size:.85rem">— si tienes otro flotante activo, apágalo antes</span>
        </label>

        <div>
            <button type="submit" name="save_banner" class="btn btn-primary">
                <i class="fas fa-save"></i> Crear banner flotante
            </button>
        </div>
    </form>
</div>

<!-- Leads capturados -->
<div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="fas fa-users"></i> Leads capturados <span style="color:var(--text-muted);font-size:.85rem">(<?= $totalLeads ?> total)</span></span>
        <?php if ($totalLeads > 0): ?>
        <a href="?export_csv=1" class="btn btn-secondary" style="font-size:.8rem">
            <i class="fas fa-file-csv"></i> Exportar CSV
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$leads): ?>
        <div style="text-align:center;color:var(--text-muted);padding:20px 0">Aún nadie se ha suscrito.</div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Celular</th>
                    <th>Código entregado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l): ?>
            <tr>
                <td style="font-size:.82rem;color:var(--text-muted);white-space:nowrap"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
                <td><?= htmlspecialchars(trim($l['nombre'] . ' ' . $l['apellido'])) ?: '—' ?></td>
                <td><?= htmlspecialchars($l['email']) ?></td>
                <td><?= htmlspecialchars($l['celular']) ?: '—' ?></td>
                <td>
                    <?php if ($l['codigo_entregado']): ?>
                    <code style="background:var(--surface-3);padding:2px 8px;border-radius:4px"><?= htmlspecialchars($l['codigo_entregado']) ?></code>
                    <?php else: ?>
                    <span style="color:var(--text-light)">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($totalLeads > count($leads)): ?>
        <div style="color:var(--text-muted);font-size:.82rem;margin-top:10px">
            Mostrando los <?= count($leads) ?> más recientes de <?= $totalLeads ?>. Exporta el CSV para ver todos.
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
