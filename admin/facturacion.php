<?php
$adminPage  = 'facturacion';
$adminTitle = 'Facturación';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$cfg = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_facturacion'])) {
    $activo    = isset($_POST['facturacion_activo']) ? 't' : 'f';
    $proveedor = trim($_POST['facturacion_proveedor'] ?? '');
    $apiUrl    = trim($_POST['facturacion_api_url'] ?? '');
    $apiToken  = trim($_POST['facturacion_api_token'] ?? '');
    $ruc       = trim($_POST['facturacion_ruc_emisor'] ?? '');

    $pdo->prepare('
        UPDATE config
        SET facturacion_activo = ?, facturacion_proveedor = ?, facturacion_api_url = ?,
            facturacion_api_token = ?, facturacion_ruc_emisor = ?, updated_at = NOW()
        WHERE id = 1
    ')->execute([$activo, $proveedor, $apiUrl, $apiToken, $ruc]);

    $cfg = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch();
    $success = 'Configuración de facturación guardada correctamente.';
}
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-file-invoice"></i> Facturación</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST">
<div class="card" style="max-width:640px">
    <div class="card-title"><i class="fas fa-plug"></i> Conexión con proveedor de facturación electrónica</div>
    <div class="form-hint" style="margin-bottom:16px">
        Conecta cualquier sistema de facturación electrónica que exponga una API. Al confirmar un pedido,
        el sistema enviará los datos del pedido a la URL configurada y guardará el comprobante devuelto.
    </div>

    <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="facturacion_activo" value="1" <?= !empty($cfg['facturacion_activo']) ? 'checked' : '' ?>>
            Emitir comprobante automáticamente al confirmar un pedido
        </label>
    </div>

    <div class="form-group">
        <label class="form-label">Proveedor</label>
        <input type="text" name="facturacion_proveedor" class="form-control" placeholder="Nubefact, Facturador SUNAT, Bsale..." value="<?= htmlspecialchars($cfg['facturacion_proveedor'] ?? '') ?>">
        <div class="form-hint">Solo referencial, para identificar qué proveedor está conectado</div>
    </div>

    <div class="form-group">
        <label class="form-label">URL del endpoint de emisión</label>
        <input type="text" name="facturacion_api_url" class="form-control" placeholder="https://mi-proveedor.com/api/emitir" value="<?= htmlspecialchars($cfg['facturacion_api_url'] ?? '') ?>">
    </div>

    <div class="form-group">
        <label class="form-label">Token / API Key</label>
        <input type="text" name="facturacion_api_token" class="form-control" placeholder="Token de autenticación (Bearer)" value="<?= htmlspecialchars($cfg['facturacion_api_token'] ?? '') ?>">
    </div>

    <div class="form-group" style="margin-bottom:0">
        <label class="form-label">RUC del emisor</label>
        <input type="text" name="facturacion_ruc_emisor" class="form-control" placeholder="20123456789" value="<?= htmlspecialchars($cfg['facturacion_ruc_emisor'] ?? '') ?>">
    </div>
</div>

<div style="margin-top:20px;max-width:640px">
    <button type="submit" name="save_facturacion" class="btn btn-primary btn-lg">
        <i class="fas fa-save"></i> Guardar configuración
    </button>
</div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
