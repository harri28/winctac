<?php
$pageTitle  = 'Mis Pedidos';
$currentPage = 'pedidos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../config/database.php';

requireClienteAuth();

$pdo = getDB();
$pedidos = $pdo->prepare('
    SELECT p.*, e.nombre AS empresa_nombre
    FROM pedidos p
    LEFT JOIN empresas_envio e ON e.id = p.empresa_envio_id
    WHERE p.cliente_id = ?
    ORDER BY p.created_at DESC
');
$pedidos->execute([clienteId()]);
$pedidos = $pedidos->fetchAll();

$estadoLabel = ['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
?>

<div class="page-wrapper">
    <div class="container">
        <div class="section-header" style="margin-bottom:24px">
            <h2 class="section-title"><i class="fas fa-list"></i> Mis Pedidos</h2>
            <a href="<?= BASE_URL ?>/" class="btn btn-secondary"><i class="fas fa-store"></i> Seguir comprando</a>
        </div>

        <?php if (!$pedidos): ?>
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No tienes pedidos aún</h3>
            <p>¡Haz tu primer pedido y aparecerá aquí!</p>
            <a href="<?= BASE_URL ?>/" class="btn btn-primary" style="margin-top:16px">Ver productos</a>
        </div>
        <?php else: ?>
        <?php foreach ($pedidos as $p):
            $estado = $p['estado'];
            $badgeClass = 'badge-' . ($estado ?: 'pendiente');
        ?>
        <details class="pedido-row" style="flex-direction:column;align-items:stretch;cursor:pointer">
            <summary style="display:flex;align-items:center;gap:16px;list-style:none">
                <div class="pedido-codigo"><?= htmlspecialchars($p['codigo']) ?></div>
                <div>
                    <div style="font-weight:600;font-size:.9rem"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div>
                    <div class="pedido-fecha"><?= htmlspecialchars($p['empresa_nombre'] ?? 'Sin envío') ?></div>
                </div>
                <span class="badge <?= $badgeClass ?>"><?= $estadoLabel[$estado] ?? $estado ?></span>
                <div class="pedido-total">S/ <?= number_format($p['total'], 2) ?></div>
                <i class="fas fa-chevron-down" style="color:var(--text-muted);margin-left:auto;font-size:.8rem"></i>
            </summary>

            <?php
            $det = $pdo->prepare('SELECT * FROM pedido_detalles WHERE pedido_id = ?');
            $det->execute([$p['id']]);
            $det = $det->fetchAll();
            ?>
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                <?php foreach ($det as $d): ?>
                <div style="display:flex;justify-content:space-between;font-size:.88rem;padding:4px 0">
                    <span><?= htmlspecialchars($d['producto_nombre']) ?> <span style="color:var(--text-muted)">x<?= $d['cantidad'] ?></span></span>
                    <span style="font-weight:600">S/ <?= number_format($d['subtotal'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:space-between;font-size:.85rem;color:var(--text-muted);margin-top:6px">
                    <span>Envío</span><span>S/ <?= number_format($p['costo_envio'], 2) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-weight:700;color:var(--primary);margin-top:6px;padding-top:6px;border-top:1px solid var(--border)">
                    <span>Total</span><span>S/ <?= number_format($p['total'], 2) ?></span>
                </div>
                <div style="margin-top:10px;font-size:.82rem;color:var(--text-muted)">
                    Pago: <?= htmlspecialchars(strtoupper($p['metodo_pago'])) ?>
                    <?php if ($p['direccion_entrega']): ?>
                    &nbsp;|&nbsp; Dirección: <?= htmlspecialchars($p['direccion_entrega']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
