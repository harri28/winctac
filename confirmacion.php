<?php
$pageTitle  = 'Pedido Confirmado';
$currentPage = 'confirmacion';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth_cliente.php';
require_once __DIR__ . '/config/database.php';

$codigo = trim($_GET['pedido'] ?? '');
if (!$codigo) { header('Location: ' . BASE_URL . '/'); exit; }

$pdo = getDB();

// Clientes logueados: verificar por código + cliente_id
// Invitados: verificar usando el código guardado en sesión al crear el pedido
if (clienteLogueado()) {
    $stmt = $pdo->prepare('
        SELECT p.*, e.nombre AS empresa_nombre
        FROM pedidos p
        LEFT JOIN empresas_envio e ON e.id = p.empresa_envio_id
        WHERE p.codigo = ? AND p.cliente_id = ?
    ');
    $stmt->execute([$codigo, clienteId()]);
} elseif (($_SESSION['ultimo_pedido'] ?? '') === $codigo) {
    $stmt = $pdo->prepare('
        SELECT p.*, e.nombre AS empresa_nombre
        FROM pedidos p
        LEFT JOIN empresas_envio e ON e.id = p.empresa_envio_id
        WHERE p.codigo = ?
    ');
    $stmt->execute([$codigo]);
} else {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$pedido = $stmt->fetch();
if (!$pedido) { header('Location: ' . BASE_URL . '/'); exit; }

$detalles = $pdo->prepare('SELECT * FROM pedido_detalles WHERE pedido_id = ?');
$detalles->execute([$pedido['id']]);
$detalles = $detalles->fetchAll();

$cfg = getShopConfig();
$waNum = preg_replace('/\D/', '', $cfg['whatsapp_numero'] ?? '');

// Construir mensaje WhatsApp
$waMsg = $cfg['whatsapp_mensaje'] ?? 'Hola! Realicé el pedido {codigo} por S/ {total}. Adjunto mi comprobante.';
$waMsg = str_replace('{codigo}', $pedido['codigo'], $waMsg);
$waMsg = str_replace('{total}', number_format($pedido['total'], 2), $waMsg);
$waLink = $waNum ? 'https://wa.me/51' . $waNum . '?text=' . urlencode($waMsg) : '';

$metodo = $pedido['metodo_pago'];
?>

<div class="page-wrapper">
    <div class="container">
        <div class="confirm-wrapper">

            <div class="confirm-header">
                <div class="confirm-icon"><i class="fas fa-check"></i></div>
                <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:6px">¡Pedido registrado!</h2>
                <p style="color:#166534;font-size:.95rem">Tu pedido ha sido recibido. Completa el pago para confirmarlo.</p>
                <div style="margin-top:14px">
                    <span class="order-code"><?= htmlspecialchars($pedido['codigo']) ?></span>
                </div>
            </div>

            <div class="confirm-body">

                <!-- Detalle del pedido -->
                <div style="margin-bottom:20px">
                    <div class="card-title" style="margin-bottom:12px"><i class="fas fa-box"></i> Detalle del pedido</div>
                    <?php foreach ($detalles as $d): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:.9rem">
                        <span><?= htmlspecialchars($d['producto_nombre']) ?> <span style="color:var(--text-muted)">x<?= $d['cantidad'] ?></span></span>
                        <span style="font-weight:600">S/ <?= number_format($d['subtotal'], 2) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:.9rem">
                        <span style="color:var(--text-muted)">Envío (<?= htmlspecialchars($pedido['empresa_envio_nombre'] ?: 'Sin envío') ?>)</span>
                        <span>S/ <?= number_format($pedido['costo_envio'], 2) ?></span>
                    </div>
                    <?php if (!empty($pedido['cupon_codigo']) && $pedido['cupon_descuento'] > 0): ?>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:.9rem;color:#16a34a">
                        <span>Cupón (<?= htmlspecialchars($pedido['cupon_codigo']) ?>)</span>
                        <span>- S/ <?= number_format($pedido['cupon_descuento'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;padding:10px 0;font-weight:700;font-size:1.05rem;color:var(--primary);border-top:2px solid var(--border);margin-top:4px">
                        <span>Total a pagar</span>
                        <span>S/ <?= number_format($pedido['total'], 2) ?></span>
                    </div>
                </div>

                <!-- Instrucciones de pago -->
                <div class="card-title" style="margin-bottom:12px"><i class="fas fa-credit-card"></i> Instrucciones de pago</div>

                <?php if ($metodo === 'billetera'): ?>
                <div class="payment-detail-box">
                    <div style="font-weight:700;font-size:1rem;margin-bottom:8px;color:#7c3aed">
                        <i class="fas fa-wallet"></i> Paga con tu billetera digital
                    </div>
                    <?php if (!empty($cfg['billetera_qr_path'])): ?>
                    <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['billetera_qr_path']) ?>" class="qr-img" alt="QR billetera digital">
                    <?php else: ?>
                    <div style="font-size:.85rem;color:var(--text-muted)">El administrador todavía no configuró el QR de pago.</div>
                    <?php endif; ?>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-top:10px">Monto: <strong>S/ <?= number_format($pedido['total'], 2) ?></strong></div>
                </div>

                <?php elseif ($metodo === 'pos'): ?>
                <div class="payment-detail-box">
                    <div style="font-weight:700;font-size:1rem;margin-bottom:8px;color:#0e7490">
                        <i class="fas fa-credit-card"></i> Pago con POS
                    </div>
                    <div style="font-size:.9rem;color:var(--text-muted)">
                        <?= $pedido['tipo_entrega'] === 'recojo' ? 'Acerca tu tarjeta al POS al recoger tu pedido en tienda.' : 'Acerca tu tarjeta al POS cuando recibas tu pedido.' ?>
                    </div>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-top:10px">Monto: <strong>S/ <?= number_format($pedido['total'], 2) ?></strong></div>
                </div>

                <?php elseif ($metodo === 'efectivo'): ?>
                <div class="payment-detail-box">
                    <div style="font-weight:700;font-size:1rem;margin-bottom:8px;color:#16a34a">
                        <i class="fas fa-money-bill-wave"></i> Pago en efectivo
                    </div>
                    <div style="font-size:.9rem;color:var(--text-muted)">Paga en efectivo al recoger tu pedido en tienda.</div>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-top:10px">Monto: <strong>S/ <?= number_format($pedido['total'], 2) ?></strong></div>
                </div>
                <?php endif; ?>

                <!-- WhatsApp -->
                <?php if ($waLink): ?>
                <div style="margin-top:20px;padding:16px;background:var(--surface-3);border-radius:var(--radius);text-align:center">
                    <p style="font-size:.9rem;font-weight:600;margin-bottom:12px">
                        <i class="fab fa-whatsapp" style="color:#25d366"></i>
                        Envía tu comprobante de pago por WhatsApp
                    </p>
                    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:14px">
                        Una vez que realices el pago, envíanos la captura del comprobante junto con tu código de pedido: <strong><?= htmlspecialchars($pedido['codigo']) ?></strong>
                    </p>
                    <a href="<?= $waLink ?>" target="_blank" class="wa-btn">
                        <i class="fab fa-whatsapp fa-lg"></i>
                        Enviar comprobante por WhatsApp
                    </a>
                    <?php if ($waNum): ?>
                    <p style="font-size:.78rem;color:var(--text-muted);margin-top:8px">
                        Nuestro número: +51 <?= htmlspecialchars($cfg['whatsapp_numero']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="margin-top:20px;display:flex;gap:10px">
                    <a href="<?= BASE_URL ?>/" class="btn btn-secondary" style="flex:1;justify-content:center">
                        <i class="fas fa-store"></i> Seguir comprando
                    </a>
                    <?php if (clienteLogueado()): ?>
                    <a href="<?= BASE_URL ?>/cuenta/mis-pedidos.php" class="btn btn-outline" style="flex:1;justify-content:center">
                        <i class="fas fa-list"></i> Mis pedidos
                    </a>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>/cuenta/registro.php" class="btn btn-outline" style="flex:1;justify-content:center">
                        <i class="fas fa-user-plus"></i> Crear cuenta
                    </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
