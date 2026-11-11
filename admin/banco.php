<?php
// ============================================================
// Configuración → Banco: datos para pagos por transferencia bancaria y el QR
// de billetera digital que ve el cliente en el checkout. Guarda SOLO estos
// campos (Configuración ya no los toca).
// ============================================================
$adminPage  = 'banco';
$adminTitle = 'Banco';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$cfgStmt = $pdo->prepare('SELECT banco_nombre, banco_cuenta, banco_cci, banco_titular, billetera_qr_path FROM config WHERE id = ?');
$cfgStmt->execute([TIENDA_ID]);
$cfg = $cfgStmt->fetch() ?: [];
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banco'])) {
    $params = [
        'banco_nombre'  => trim(mb_substr($_POST['banco_nombre'] ?? '', 0, 100)),
        'banco_cuenta'  => trim(mb_substr($_POST['banco_cuenta'] ?? '', 0, 50)),
        'banco_cci'     => trim(mb_substr($_POST['banco_cci'] ?? '', 0, 50)),
        'banco_titular' => trim(mb_substr($_POST['banco_titular'] ?? '', 0, 100)),
    ];
    $sets = 'banco_nombre = :banco_nombre, banco_cuenta = :banco_cuenta, banco_cci = :banco_cci, banco_titular = :banco_titular';

    if (!empty($_FILES['billetera_qr']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['billetera_qr']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $error = 'El QR debe ser una imagen JPG, PNG, GIF o WEBP.';
        } else {
            $fname = 'billetera_qr_' . time() . '.' . $ext;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['billetera_qr']['tmp_name'], UPLOADS_PATH . '/' . $fname)) {
                $sets .= ', billetera_qr_path = :billetera_qr_path';
                $params['billetera_qr_path'] = $fname;
            } else {
                $error = 'No se pudo guardar la imagen del QR.';
            }
        }
    }

    if (!$error) {
        $params['__id'] = TIENDA_ID;
        $pdo->prepare("UPDATE config SET $sets, updated_at = NOW() WHERE id = :__id")->execute($params);
        $cfgStmt->execute([TIENDA_ID]);
        $cfg = $cfgStmt->fetch() ?: [];
        $success = 'Datos bancarios guardados correctamente.';
    }
}
?>

<?php adminSeccionTabs('configuracion'); ?>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="card" style="max-width:640px">
    <div class="card-title"><i class="fas fa-university"></i> Transferencia bancaria</div>
    <div class="form-hint" style="margin-bottom:16px">
        Datos que ve el cliente en el checkout y en la confirmación del pedido cuando elige pagar por transferencia o con billetera digital.
    </div>
    <div class="form-group">
        <label class="form-label">Banco</label>
        <input type="text" name="banco_nombre" class="form-control" maxlength="100" placeholder="BCP, Interbank..." value="<?= htmlspecialchars($cfg['banco_nombre'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">N° de cuenta</label>
        <input type="text" name="banco_cuenta" class="form-control" maxlength="50" value="<?= htmlspecialchars($cfg['banco_cuenta'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">CCI</label>
        <input type="text" name="banco_cci" class="form-control" maxlength="50" value="<?= htmlspecialchars($cfg['banco_cci'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Titular</label>
        <input type="text" name="banco_titular" class="form-control" maxlength="100" value="<?= htmlspecialchars($cfg['banco_titular'] ?? '') ?>">
    </div>
    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">QR de billetera digital</label>
        <?php if (!empty($cfg['billetera_qr_path'])): ?>
        <div style="margin-bottom:6px"><img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['billetera_qr_path']) ?>" alt="QR actual" style="height:120px;border-radius:6px"></div>
        <?php endif; ?>
        <input type="file" name="billetera_qr" class="form-control" accept="image/*">
        <div class="form-hint">Este QR es el que ve el cliente en el checkout al elegir "Billetera digital" (Yape, Plin o cualquier billetera que lea QR)</div>
    </div>
</div>

<div style="max-width:640px;margin-top:20px">
    <button type="submit" name="save_banco" class="btn btn-primary btn-lg" style="width:100%">
        <i class="fas fa-save"></i> Guardar datos bancarios
    </button>
</div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
