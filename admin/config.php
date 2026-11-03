<?php
$adminPage  = 'config';
$adminTitle = 'Configuración';
require_once __DIR__ . '/includes/header.php';


$pdo = getDB();
$cfgStmt = $pdo->prepare('SELECT * FROM config WHERE id = ?');
$cfgStmt->execute([TIENDA_ID]);
$cfg = $cfgStmt->fetch();
$success = '';
$error   = '';

// Guardar configuración general
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $fields = ['nombre_tienda','descripcion','whatsapp_numero','whatsapp_mensaje',
               'banco_nombre','banco_cuenta','banco_cci','banco_titular',
               'contacto_email','contacto_celular','color_primary',
               'red_facebook','red_instagram','red_tiktok','red_youtube','red_linkedin'];

    $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
    $params = [];
    foreach ($fields as $f) { $params[$f] = trim($_POST[$f] ?? ''); }

    $sets .= ', mostrar_stock = :mostrar_stock';
    $params['mostrar_stock'] = isset($_POST['mostrar_stock']) ? 't' : 'f';

    $sets .= ', carrito_activo = :carrito_activo';
    $params['carrito_activo'] = isset($_POST['carrito_activo']) ? 't' : 'f';

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
    $success = 'Empresa de envío guardada.';
}

// Eliminar empresa de envío
// Eliminar empresa de envío.
// Si hay pedidos que la usaron, `pedidos.empresa_envio_id` tiene FK que
// bloquea el DELETE (foreign_key_violation). En ese caso se hace soft-delete
// (activo = FALSE): deja de aparecer en el checkout público pero conserva
// el historial de pedidos intacto.
if ($_GET['del_envio'] ?? '') {
    $envioId = intval($_GET['del_envio']);
    try {
        $usada = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE empresa_envio_id = ?');
        $usada->execute([$envioId]);
        $nUsos = (int) $usada->fetchColumn();

        if ($nUsos === 0) {
            $pdo->prepare('DELETE FROM empresas_envio WHERE id = ?')->execute([$envioId]);
            $success = 'Empresa de envío eliminada.';
        } else {
            $pdo->prepare('UPDATE empresas_envio SET activo = FALSE WHERE id = ?')->execute([$envioId]);
            $success = 'La empresa tenía ' . $nUsos . ' pedido(s) asociado(s), así que se desactivó en lugar de eliminarse. '
                     . 'Ya no aparecerá a los clientes en el checkout, pero el historial de pedidos se conserva.';
        }
    } catch (PDOException $e) {
        $error = 'No se pudo eliminar la empresa de envío: ' . $e->getMessage();
    }
}

// Reactivar empresa de envío (solo si se cambia de opinión tras un soft-delete)
if ($_GET['act_envio'] ?? '') {
    try {
        $pdo->prepare('UPDATE empresas_envio SET activo = TRUE WHERE id = ?')->execute([intval($_GET['act_envio'])]);
        $success = 'Empresa de envío reactivada.';
    } catch (PDOException $e) {
        $error = 'No se pudo reactivar la empresa de envío: ' . $e->getMessage();
    }
}

// admin/includes/header.php ya imprimió el layout de la página antes de este
// punto, así que ningún handler de arriba puede usar header('Location: ...')
// (fallaría en silencio) — en vez de redirigir, se recarga aquí y se
// re-renderiza la misma página con los datos frescos.
$empresas = $pdo->query('SELECT * FROM empresas_envio ORDER BY id')->fetchAll();

// Precalcula cuántos pedidos usan cada empresa; se pasa al modal de eliminar
// para avisar al admin si va a borrar o solo desactivar (soft-delete).
$usosRaw = $pdo->query('SELECT empresa_envio_id, COUNT(*) AS n FROM pedidos WHERE empresa_envio_id IS NOT NULL GROUP BY empresa_envio_id')->fetchAll();
$usosPorEmpresa = [];
foreach ($usosRaw as $r) { $usosPorEmpresa[(int) $r['empresa_envio_id']] = (int) $r['n']; }
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
        <div class="form-group">
            <label class="form-label">Color de marca</label>
            <input type="color" name="color_primary" value="<?= htmlspecialchars($cfg['color_primary'] ?? '#dc2626') ?>" style="width:70px;height:38px;padding:2px;cursor:pointer">
            <div class="form-hint">Se aplica en toda la tienda, el login y el panel de administración</div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0">
            <input type="checkbox" name="mostrar_stock" value="1" <?= !empty($cfg['mostrar_stock']) ? 'checked' : '' ?>>
            Mostrar cantidad exacta de stock al público
        </label>
        <div class="form-hint">Si lo apagas, la tienda solo indica "Sin stock" o "Disponible", sin mostrar el número exacto de unidades — el producto se sigue bloqueando para compra si el stock es 0.</div>

        <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0">
            <input type="checkbox" name="carrito_activo" value="1" <?= (!array_key_exists('carrito_activo', $cfg) || !empty($cfg['carrito_activo'])) ? 'checked' : '' ?>>
            <strong>Modo e-commerce (carrito de compras activo)</strong>
        </label>
        <div class="form-hint">
            <strong>Activado:</strong> funciona como tienda online normal — el cliente agrega productos al carrito, hace checkout y paga.<br>
            <strong>Desactivado (modo catálogo electrónico):</strong> el sitio muestra los productos con precio y stock, pero no se puede comprar. En cada producto se reemplaza el botón "Agregar al carrito" por "Consultar por WhatsApp" (usa el número de WhatsApp configurado arriba). Las páginas de carrito y checkout quedan deshabilitadas.
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
        <div class="form-hint">El número también activa el botón flotante de WhatsApp y los accesos de Inicio y Contacto.</div>
    </div>

    <!-- Redes sociales -->
    <div class="card">
        <div class="card-title"><i class="fas fa-share-nodes"></i> Redes sociales</div>
        <?php foreach ([
            'red_facebook'  => ['Facebook',  'fab fa-facebook-f', 'https://facebook.com/tuempresa'],
            'red_instagram' => ['Instagram', 'fab fa-instagram',  'https://instagram.com/tuempresa'],
            'red_tiktok'    => ['TikTok',    'fab fa-tiktok',     'https://tiktok.com/@tuempresa'],
            'red_youtube'   => ['YouTube',   'fab fa-youtube',    'https://youtube.com/@tuempresa'],
            'red_linkedin'  => ['LinkedIn',  'fab fa-linkedin-in','https://linkedin.com/company/tuempresa'],
        ] as $campo => [$label, $icon, $ph]): ?>
        <div class="form-group">
            <label class="form-label"><i class="<?= $icon ?>"></i> <?= $label ?></label>
            <input type="url" name="<?= $campo ?>" class="form-control" placeholder="<?= $ph ?>" value="<?= htmlspecialchars($cfg[$campo] ?? '') ?>">
        </div>
        <?php endforeach; ?>
        <div class="form-hint">Deja vacía la red que no uses. Se muestran en Inicio, Contacto y el pie de página.</div>
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

    <?php
    $empresasActivas   = array_filter($empresas, fn($e) => !empty($e['activo']));
    $empresasInactivas = array_filter($empresas, fn($e) => empty($e['activo']));
    ?>
    <table class="data-table" style="margin-bottom:20px">
        <thead>
            <tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($empresasActivas as $e): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($e['nombre']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($e['descripcion']) ?></td>
            <td style="font-weight:700">S/ <?= number_format($e['precio'], 2) ?></td>
            <td>
                <button onclick="editarEnvio(<?= htmlspecialchars(json_encode($e)) ?>)" class="btn btn-secondary" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-edit"></i>
                </button>
                <button type="button" onclick="pedirEliminarEnvio(<?= (int) $e['id'] ?>, <?= htmlspecialchars(json_encode($e['nombre']), ENT_QUOTES) ?>, <?= (int) ($usosPorEmpresa[(int) $e['id']] ?? 0) ?>)" class="btn btn-danger" style="padding:4px 10px;font-size:.78rem">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$empresasActivas): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No hay empresas de envío activas</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($empresasInactivas): ?>
    <details style="margin-bottom:20px">
        <summary style="cursor:pointer;color:var(--text-muted);font-size:.85rem;padding:6px 0">
            <i class="fas fa-eye-slash"></i> Empresas desactivadas (<?= count($empresasInactivas) ?>)
            — ocultas al cliente pero conservadas por tener pedidos históricos
        </summary>
        <table class="data-table" style="margin-top:10px;opacity:.7">
            <thead>
                <tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($empresasInactivas as $e): ?>
            <tr>
                <td style="font-weight:600"><?= htmlspecialchars($e['nombre']) ?></td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($e['descripcion']) ?></td>
                <td style="font-weight:700">S/ <?= number_format($e['precio'], 2) ?></td>
                <td>
                    <a href="?act_envio=<?= $e['id'] ?>" class="btn btn-primary" style="padding:4px 10px;font-size:.78rem" title="Reactivar">
                        <i class="fas fa-undo"></i> Reactivar
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

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

<!-- ── Modal de confirmación: eliminar empresa de envío ── -->
<div class="modal-overlay" id="modal-del-envio" role="dialog" aria-labelledby="modal-del-envio-title" aria-modal="true">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-del-envio-title">
                <i class="fas fa-trash" style="color:var(--danger);margin-right:6px"></i>
                Eliminar empresa de envío
            </h3>
            <button type="button" class="modal-close-btn" onclick="cerrarModalEnvio()" aria-label="Cerrar">×</button>
        </div>

        <div id="modal-del-envio-body" style="font-size:.92rem;color:var(--text);line-height:1.55"></div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px">
            <button type="button" class="btn btn-secondary" onclick="cerrarModalEnvio()">
                Cancelar
            </button>
            <a href="#" id="modal-del-envio-ok" class="btn btn-danger">
                <i class="fas fa-trash"></i> <span id="modal-del-envio-ok-label">Eliminar</span>
            </a>
        </div>
    </div>
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

// ── Modal propio para confirmar eliminación de empresa de envío ──
// Reemplaza el confirm() nativo del navegador. Muestra cuántos pedidos usan
// la empresa y advierte si la acción será un borrado real o solo una
// desactivación (soft-delete), para que el admin sepa qué va a pasar.
function pedirEliminarEnvio(id, nombre, usos) {
    const body    = document.getElementById('modal-del-envio-body');
    const okLink  = document.getElementById('modal-del-envio-ok');
    const okLabel = document.getElementById('modal-del-envio-ok-label');

    const nombreHTML = '<strong>' + escapeHTML(nombre) + '</strong>';

    if (usos > 0) {
        body.innerHTML =
            '<p>La empresa ' + nombreHTML + ' tiene <strong>' + usos
            + ' pedido(s)</strong> asociados en el historial.</p>' +
            '<p style="margin-top:10px;padding:10px 12px;background:var(--warning-bg);border-left:3px solid var(--warning);border-radius:4px">' +
            '<i class="fas fa-info-circle"></i> No se puede eliminar por completo porque romperíamos el historial. ' +
            'Al confirmar, la empresa quedará <strong>desactivada</strong>: dejará de aparecer en el checkout, pero los pedidos pasados la seguirán viendo.' +
            '</p>';
        okLabel.textContent = 'Desactivar';
    } else {
        body.innerHTML =
            '<p>¿Seguro que quieres eliminar la empresa de envío ' + nombreHTML + '?</p>' +
            '<p style="margin-top:8px;font-size:.85rem;color:var(--text-muted)">' +
            'No tiene pedidos asociados, así que se borrará por completo. Esta acción no se puede deshacer.' +
            '</p>';
        okLabel.textContent = 'Eliminar';
    }

    okLink.setAttribute('href', '?del_envio=' + encodeURIComponent(id));
    document.getElementById('modal-del-envio').classList.add('open');
}

function cerrarModalEnvio() {
    document.getElementById('modal-del-envio').classList.remove('open');
}

// Cerrar al clic en el fondo o con Escape
(function() {
    const modal = document.getElementById('modal-del-envio');
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrarModalEnvio(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) cerrarModalEnvio();
    });
})();

function escapeHTML(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>
