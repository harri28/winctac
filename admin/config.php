<?php
$adminPage  = 'config';
$adminTitle = 'Configuración';
require_once __DIR__ . '/includes/header.php';


$pdo = getDB();
$cfgStmt = $pdo->prepare('SELECT * FROM config WHERE id = ?');
$cfgStmt->execute([TIENDA_ID]);
$cfg = $cfgStmt->fetch();
$empresas = $pdo->query('SELECT * FROM empresas_envio ORDER BY id')->fetchAll();
$success = '';
$error   = '';

// Guardar configuración general
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $fields = ['nombre_tienda','descripcion','whatsapp_numero','whatsapp_mensaje',
               'banco_nombre','banco_cuenta','banco_cci','banco_titular',
               'contacto_email','contacto_celular','color_primary'];

    $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
    $params = [];
    foreach ($fields as $f) { $params[$f] = trim($_POST[$f] ?? ''); }

    // Subir QR de billetera digital
    if (!empty($_FILES['billetera_qr']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['billetera_qr']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = 'billetera_qr_' . time() . '.' . $ext;
            $dest = UPLOADS_PATH . '/' . $fname;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['billetera_qr']['tmp_name'], $dest)) {
                $sets .= ', billetera_qr_path = :billetera_qr_path';
                $params['billetera_qr_path'] = $fname;
            }
        }
    }

    // Subir logo
    if (!empty($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
            $fname = 'logo_' . time() . '.' . $ext;
            $dest = UPLOADS_PATH . '/' . $fname;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $sets .= ', logo_path = :logo_path';
                $params['logo_path'] = $fname;
            }
        }
    }

    $params['__id'] = TIENDA_ID;
    $pdo->prepare("UPDATE config SET $sets, updated_at = NOW() WHERE id = :__id")->execute($params);
    $cfgStmt->execute([TIENDA_ID]);
    $cfg = $cfgStmt->fetch();
    $success = 'Configuración guardada correctamente.';
}

// Guardar empresa de envío
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_envio'])) {
    $id     = intval($_POST['envio_id'] ?? 0);
    $nombre = trim($_POST['envio_nombre'] ?? '');
    $desc   = trim($_POST['envio_desc'] ?? '');
    $precio = floatval($_POST['envio_precio'] ?? 0);

    if ($id) {
        $pdo->prepare('UPDATE empresas_envio SET nombre=?, descripcion=?, precio=? WHERE id=?')
            ->execute([$nombre, $desc, $precio, $id]);
    } else {
        $pdo->prepare('INSERT INTO empresas_envio (nombre, descripcion, precio) VALUES (?,?,?)')
            ->execute([$nombre, $desc, $precio]);
    }
    header('Location: ' . BASE_URL . '/admin/config.php?ok=envio');
    exit;
}

// Eliminar empresa de envío
if ($_GET['del_envio'] ?? '') {
    $pdo->prepare('DELETE FROM empresas_envio WHERE id = ?')->execute([intval($_GET['del_envio'])]);
    header('Location: ' . BASE_URL . '/admin/config.php');
    exit;
}

if (isset($_GET['ok'])) $success = 'Guardado correctamente.';
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-cog"></i> Configuración</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

<!-- Col 1 -->
<div style="display:flex;flex-direction:column;gap:20px">

    <!-- Tienda -->
    <div class="card">
        <div class="card-title"><i class="fas fa-store"></i> Datos de la tienda</div>
        <div class="form-group">
            <label class="form-label">Nombre de la tienda</label>
            <input type="text" name="nombre_tienda" class="form-control" value="<?= htmlspecialchars($cfg['nombre_tienda'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="2"><?= htmlspecialchars($cfg['descripcion'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Logo</label>
            <?php if (!empty($cfg['logo_path'])): ?>
            <div style="margin-bottom:8px"><img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>" style="height:40px;border-radius:6px"></div>
            <?php endif; ?>
            <input type="file" name="logo" class="form-control" accept="image/*">
            <div class="form-hint">Se usa en el login, la tienda y el panel de administración, y también como ícono de la pestaña del navegador (favicon)</div>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Color de marca</label>
            <input type="color" name="color_primary" value="<?= htmlspecialchars($cfg['color_primary'] ?? '#dc2626') ?>" style="width:70px;height:38px;padding:2px;cursor:pointer">
            <div class="form-hint">Se aplica en toda la tienda, el login y el panel de administración</div>
        </div>
    </div>

    <!-- Contacto (footer: botón "Contáctanos") -->
    <div class="card">
        <div class="card-title"><i class="fas fa-address-card"></i> Contacto</div>
        <div class="form-group">
            <label class="form-label">Correo de contacto</label>
            <input type="email" name="contacto_email" class="form-control" placeholder="contacto@tutienda.com" value="<?= htmlspecialchars($cfg['contacto_email'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Celular de contacto</label>
            <input type="text" name="contacto_celular" class="form-control" placeholder="999999999" value="<?= htmlspecialchars($cfg['contacto_celular'] ?? '') ?>">
        </div>
        <div class="form-hint">Se muestran en el botón "Contáctanos" del pie de página</div>
    </div>

    <!-- WhatsApp -->
    <div class="card">
        <div class="card-title"><i class="fab fa-whatsapp" style="color:#25d366"></i> WhatsApp</div>
        <div class="form-group">
            <label class="form-label">Número de WhatsApp</label>
            <input type="text" name="whatsapp_numero" class="form-control" placeholder="999999999" value="<?= htmlspecialchars($cfg['whatsapp_numero'] ?? '') ?>">
            <div class="form-hint">Sin +51, solo el número (ej: 987654321)</div>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Mensaje automático</label>
            <textarea name="whatsapp_mensaje" class="form-control" rows="3"><?= htmlspecialchars($cfg['whatsapp_mensaje'] ?? '') ?></textarea>
            <div class="form-hint">Usa {codigo} y {total} como variables</div>
        </div>
    </div>

</div>

<!-- Col 2 -->
<div style="display:flex;flex-direction:column;gap:20px">

    <!-- Banco / Billetera digital -->
    <div class="card">
        <div class="card-title"><i class="fas fa-university"></i> Transferencia bancaria</div>
        <div class="form-group">
            <label class="form-label">Banco</label>
            <input type="text" name="banco_nombre" class="form-control" placeholder="BCP, Interbank..." value="<?= htmlspecialchars($cfg['banco_nombre'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">N° de cuenta</label>
            <input type="text" name="banco_cuenta" class="form-control" value="<?= htmlspecialchars($cfg['banco_cuenta'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">CCI</label>
            <input type="text" name="banco_cci" class="form-control" value="<?= htmlspecialchars($cfg['banco_cci'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Titular</label>
            <input type="text" name="banco_titular" class="form-control" value="<?= htmlspecialchars($cfg['banco_titular'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">QR de billetera digital</label>
            <?php if (!empty($cfg['billetera_qr_path'])): ?>
            <div style="margin-bottom:6px"><img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['billetera_qr_path']) ?>" style="height:120px;border-radius:6px"></div>
            <?php endif; ?>
            <input type="file" name="billetera_qr" class="form-control" accept="image/*">
            <div class="form-hint">Este QR es el que ve el cliente en el checkout al elegir "Billetera digital" (Yape, Plin o cualquier billetera que lea QR)</div>
        </div>
    </div>

    <div style="display:flex;gap:10px">
        <button type="submit" name="save_config" class="btn btn-primary btn-lg" style="flex:1">
            <i class="fas fa-save"></i> Guardar configuración
        </button>
    </div>
</div>
</div>
</form>

<!-- Empresas de envío -->
<div class="card" style="margin-top:28px">
    <div class="card-title"><i class="fas fa-truck"></i> Empresas de envío</div>

    <table class="data-table" style="margin-bottom:20px">
        <thead>
            <tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($empresas as $e): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($e['nombre']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($e['descripcion']) ?></td>
            <td style="font-weight:700">S/ <?= number_format($e['precio'], 2) ?></td>
            <td>
                <button onclick="editarEnvio(<?= htmlspecialchars(json_encode($e)) ?>)" class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="?del_envio=<?= $e['id'] ?>" onclick="return confirm('¿Eliminar?')" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$empresas): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No hay empresas de envío</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="POST" style="display:grid;grid-template-columns:2fr 2fr 1fr auto;gap:12px;align-items:end">
        <div>
            <label class="form-label">Nombre empresa</label>
            <input type="text" name="envio_nombre" id="envio_nombre" class="form-control" placeholder="Media Empresa 1" required>
        </div>
        <div>
            <label class="form-label">Descripción</label>
            <input type="text" name="envio_desc" id="envio_desc" class="form-control" placeholder="Envío estándar">
        </div>
        <div>
            <label class="form-label">Precio (S/)</label>
            <input type="number" name="envio_precio" id="envio_precio" class="form-control" step="0.01" min="0" placeholder="0.00" required>
        </div>
        <div>
            <input type="hidden" name="envio_id" id="envio_id" value="0">
            <button type="submit" name="save_envio" class="btn btn-primary" id="envio_btn">
                <i class="fas fa-plus"></i> Agregar
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function editarEnvio(e) {
    document.getElementById('envio_id').value = e.id;
    document.getElementById('envio_nombre').value = e.nombre;
    document.getElementById('envio_desc').value = e.descripcion;
    document.getElementById('envio_precio').value = e.precio;
    document.getElementById('envio_btn').innerHTML = '<i class="fas fa-save"></i> Actualizar';
    document.getElementById('envio_nombre').focus();
}
</script>
