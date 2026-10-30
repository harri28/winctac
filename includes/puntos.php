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

// ── Compras en tienda física / ERP ───────────────────────────
// Las compras registradas en el ERP (Fox Smart Business) también acumulan
// puntos: se identifican por el DNI/documento del cliente afiliado y por el
// número de comprobante (único por tienda, para no acreditar dos veces).

// DNI (8 dígitos) o carné de extranjería / pasaporte (9 a 12 alfanuméricos).
function normalizarDocumento(string $doc): string {
    return strtoupper(preg_replace('/[\s.\-]/', '', trim($doc)));
}
function documentoValido(string $doc): bool {
    return (bool) preg_match('/^(\d{8}|[A-Z0-9]{9,12})$/', $doc);
}

// "2026-10-30", "30/10/2026" o "30-10-2026" → "2026-10-30" (null si no es fecha).
function normalizarFechaCompra(string $f): ?string {
    $f = trim($f);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $f, $m))      [$y, $mo, $d] = [$m[1], $m[2], $m[3]];
    elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})#', $f, $m)) [$d, $mo, $y] = [$m[1], $m[2], $m[3]];
    elseif (is_numeric($f) && $f > 20000 && $f < 80000)                 return date('Y-m-d', ((int) $f - 25569) * 86400); // fecha serial de Excel
    else return null;
    return checkdate((int) $mo, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

// "S/ 1,234.50" / "1.234,50" / "85.9" → float redondeado (0 si no es número).
function montoCompra($v): float {
    $v = str_replace(['S/', ' '], '', trim((string) $v));
    if (preg_match('/^-?[\d.]*,\d{1,2}$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v);
    else $v = str_replace(',', '', $v);
    return is_numeric($v) ? round((float) $v, 2) : 0;
}

// Acredita en una transacción los puntos de una compra ya insertada.
function _acreditarCompraTienda(PDO $pdo, int $compraId, int $clienteId, float $monto, string $comprobante, float $puntosPorSol): int {
    $puntos = (int) floor($monto * $puntosPorSol);
    $pdo->prepare('INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, compra_tienda_id, nota) VALUES (?, \'ganado\', ?, ?, ?)')
        ->execute([$clienteId, $puntos, $compraId, 'Compra en tienda ' . $comprobante]);
    $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo + ? WHERE id = ?')->execute([$puntos, $clienteId]);
    $pdo->prepare("UPDATE compras_tienda SET cliente_id = ?, puntos = ?, estado = 'otorgado' WHERE id = ?")
        ->execute([$clienteId, $puntos, $compraId]);
    return $puntos;
}

// Registra una compra del ERP/tienda física y, si el documento pertenece a un
// cliente afiliado, le acredita floor(monto × puntos_por_sol) puntos. Si el
// documento aún no está afiliado, la compra queda 'pendiente' y se acredita
// cuando el cliente se registre (acreditarComprasPendientes).
// Devuelve ['ok' => bool, 'estado' => ..., 'puntos' => int, 'cliente' => nombre|null, 'error' => ...].
function registrarCompraTienda(PDO $pdo, array $c, string $origen, ?int $adminId = null): array {
    $dni         = normalizarDocumento((string) ($c['dni'] ?? ''));
    $comprobante = strtoupper(trim((string) ($c['comprobante'] ?? '')));
    $fecha       = normalizarFechaCompra((string) ($c['fecha'] ?? ''));
    $monto       = montoCompra($c['monto'] ?? '');

    if (!documentoValido($dni))                          return ['ok' => false, 'error' => 'Documento inválido (DNI de 8 dígitos o CE de 9 a 12 caracteres)'];
    if ($comprobante === '' || mb_strlen($comprobante) > 40) return ['ok' => false, 'error' => 'Número de comprobante vacío o demasiado largo'];
    if (!$fecha)                                         return ['ok' => false, 'error' => 'Fecha inválida'];
    if ($fecha > date('Y-m-d'))                          return ['ok' => false, 'error' => 'La fecha no puede ser futura'];
    if ($monto <= 0 || $monto > 1000000)                 return ['ok' => false, 'error' => 'Monto inválido'];

    $cfgStmt = $pdo->prepare('SELECT puntos_activo, puntos_por_sol FROM config WHERE id = ?');
    $cfgStmt->execute([TIENDA_ID]);
    $cfg = $cfgStmt->fetch();
    if (!$cfg || !$cfg['puntos_activo']) return ['ok' => false, 'error' => 'El programa de puntos está desactivado'];

    $dup = $pdo->prepare('SELECT 1 FROM compras_tienda WHERE tienda_id = ? AND comprobante = ?');
    $dup->execute([TIENDA_ID, $comprobante]);
    if ($dup->fetchColumn()) return ['ok' => false, 'error' => "El comprobante $comprobante ya fue registrado"];

    $cli = $pdo->prepare("SELECT id, nombre, apellidos FROM clientes WHERE UPPER(REPLACE(dni, ' ', '')) = ? AND activo = TRUE ORDER BY id LIMIT 1");
    $cli->execute([$dni]);
    $cliente = $cli->fetch();

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO compras_tienda (tienda_id, dni, comprobante, fecha, monto, origen, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $ins->execute([TIENDA_ID, $dni, $comprobante, $fecha, $monto, $origen, $adminId]);
        $compraId = (int) $ins->fetchColumn();
        $puntos = $cliente ? _acreditarCompraTienda($pdo, $compraId, (int) $cliente['id'], $monto, $comprobante, (float) $cfg['puntos_por_sol']) : 0;
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Carrera con otro registro del mismo comprobante (UNIQUE)
        return ['ok' => false, 'error' => "No se pudo registrar el comprobante $comprobante"];
    }

    return [
        'ok' => true, 'id' => $compraId, 'estado' => $cliente ? 'otorgado' : 'pendiente', 'puntos' => $puntos,
        'cliente' => $cliente ? trim($cliente['nombre'] . ' ' . $cliente['apellidos']) : null,
    ];
}

// Acredita las compras 'pendiente' de un documento recién afiliado (registro
// del cliente o cuando completa su DNI). Devuelve el total de puntos acreditados.
function acreditarComprasPendientes(PDO $pdo, int $clienteId, string $dni): int {
    $dni = normalizarDocumento($dni);
    if (!documentoValido($dni)) return 0;

    $cfgStmt = $pdo->prepare('SELECT puntos_activo, puntos_por_sol FROM config WHERE id = ?');
    $cfgStmt->execute([TIENDA_ID]);
    $cfg = $cfgStmt->fetch();
    if (!$cfg || !$cfg['puntos_activo']) return 0;

    $pend = $pdo->prepare("SELECT id, monto, comprobante FROM compras_tienda WHERE tienda_id = ? AND dni = ? AND estado = 'pendiente' FOR UPDATE");
    $total = 0;
    $pdo->beginTransaction();
    try {
        $pend->execute([TIENDA_ID, $dni]);
        foreach ($pend->fetchAll() as $c) {
            $total += _acreditarCompraTienda($pdo, (int) $c['id'], $clienteId, (float) $c['monto'], $c['comprobante'], (float) $cfg['puntos_por_sol']);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return 0;
    }
    return $total;
}

// Anula una compra de tienda (comprobante anulado en el ERP) y revierte sus
// puntos si ya se habían acreditado — mismo criterio que revertirPuntosPedido().
function anularCompraTienda(PDO $pdo, int $compraId, ?int $adminId = null): bool {
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT * FROM compras_tienda WHERE id = ? AND tienda_id = ? AND estado <> 'anulado' FOR UPDATE");
        $s->execute([$compraId, TIENDA_ID]);
        $c = $s->fetch();
        if (!$c) { $pdo->rollBack(); return false; }

        if ($c['estado'] === 'otorgado' && $c['cliente_id'] && (int) $c['puntos'] > 0) {
            $pdo->prepare('INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, compra_tienda_id, nota, admin_id) VALUES (?, \'revertido\', ?, ?, ?, ?)')
                ->execute([$c['cliente_id'], -(int) $c['puntos'], $compraId, 'Compra en tienda anulada ' . $c['comprobante'], $adminId]);
            $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo - ? WHERE id = ?')->execute([(int) $c['puntos'], $c['cliente_id']]);
        }
        $pdo->prepare("UPDATE compras_tienda SET estado = 'anulado' WHERE id = ?")->execute([$compraId]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        return false;
    }
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
