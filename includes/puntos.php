<?php
// ============================================================
// PROGRAMA DE PUNTOS — Otorgar, revertir y generar cupones
// ============================================================

// Otorga puntos por un pedido que pasó a 'confirmado'. Idempotente:
// no vuelve a otorgar si el pedido ya tiene puntos_estado = 'otorgado'.
function otorgarPuntosPedido(PDO $pdo, int $pedidoId): void {
    $p = $pdo->prepare('SELECT cliente_id, total, puntos_estado FROM pedidos WHERE id = ?');
    $p->execute([$pedidoId]);
    $pedido = $p->fetch();
    if (!$pedido || !$pedido['cliente_id'] || $pedido['puntos_estado'] === 'otorgado') return;

    $cfgStmt = $pdo->prepare('SELECT puntos_activo, puntos_por_sol FROM config WHERE id = ?');
    $cfgStmt->execute([TIENDA_ID]);
    $cfg = $cfgStmt->fetch();
    if (!$cfg || !$cfg['puntos_activo']) return;

    $puntos = (int) floor(floatval($pedido['total']) * floatval($cfg['puntos_por_sol']));
    if ($puntos <= 0) return;

    $pdo->prepare('
        INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, pedido_id, nota)
        VALUES (?, \'ganado\', ?, ?, \'Compra confirmada\')
    ')->execute([$pedido['cliente_id'], $puntos, $pedidoId]);

    $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo + ? WHERE id = ?')
        ->execute([$puntos, $pedido['cliente_id']]);

    $pdo->prepare('UPDATE pedidos SET puntos_ganados = ?, puntos_estado = \'otorgado\' WHERE id = ?')
        ->execute([$puntos, $pedidoId]);
}

// Revierte los puntos de un pedido que ya los había otorgado y pasó a 'cancelado'.
function revertirPuntosPedido(PDO $pdo, int $pedidoId): void {
    $p = $pdo->prepare('SELECT cliente_id, puntos_ganados, puntos_estado FROM pedidos WHERE id = ?');
    $p->execute([$pedidoId]);
    $pedido = $p->fetch();
    if (!$pedido || $pedido['puntos_estado'] !== 'otorgado') return;

    $puntos = (int) $pedido['puntos_ganados'];

    $pdo->prepare('
        INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, pedido_id, nota)
        VALUES (?, \'revertido\', ?, ?, \'Pedido cancelado\')
    ')->execute([$pedido['cliente_id'], -$puntos, $pedidoId]);

    $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo - ? WHERE id = ?')
        ->execute([$puntos, $pedido['cliente_id']]);

    $pdo->prepare('UPDATE pedidos SET puntos_estado = \'revertido\' WHERE id = ?')
        ->execute([$pedidoId]);
}

// Genera un código de cupón único, mismo patrón que generarCodigoPedidoUnico().
function generarCodigoCuponUnico(PDO $pdo): string {
    do {
        $codigo = 'PTS-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $exists = $pdo->prepare('SELECT 1 FROM puntos_cupones WHERE codigo = ?');
        $exists->execute([$codigo]);
    } while ($exists->fetch());
    return $codigo;
}
