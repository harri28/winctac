<?php
// ============================================================
// FACTURACIÓN ELECTRÓNICA — Conector genérico vía API
// ============================================================
// Contrato asumido con el proveedor configurado en config.facturacion_*:
//
//   POST {facturacion_api_url}
//   Headers: Content-Type: application/json
//            Authorization: Bearer {facturacion_api_token}
//   Body:    { "ruc_emisor": "...", "pedido": {
//                 "codigo", "cliente": {nombre, dni, email},
//                 "items": [{nombre, cantidad, precio_unitario, subtotal}],
//                 "subtotal", "envio", "total"
//             } }
//   Respuesta esperada: { "success": true,
//                          "comprobante_tipo": "boleta|factura",
//                          "comprobante_numero": "...",
//                          "comprobante_url": "https://..." }
//         o { "success": false, "error": "..." }
//
// Si el proveedor real usa un contrato distinto, ajustar el armado
// del payload y el parseo de la respuesta en emitirComprobante().

function emitirComprobante(PDO $pdo, int $pedidoId): array {
    $cfg = getShopConfig();

    if (empty($cfg['facturacion_activo']) || empty($cfg['facturacion_api_url'])) {
        return ['success' => false, 'skipped' => true, 'error' => 'Facturación electrónica no está activada'];
    }

    $stmt = $pdo->prepare('
        SELECT p.*,
               COALESCE(c.nombre, p.cliente_nombre) AS cliente_nombre,
               COALESCE(c.dni, p.cliente_dni) AS cliente_dni,
               c.email AS cliente_email
        FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?
    ');
    $stmt->execute([$pedidoId]);
    $pedido = $stmt->fetch();
    if (!$pedido) {
        return ['success' => false, 'error' => 'Pedido no encontrado'];
    }

    $det = $pdo->prepare('SELECT producto_nombre, cantidad, precio_unitario, subtotal FROM pedido_detalles WHERE pedido_id = ?');
    $det->execute([$pedidoId]);

    $payload = [
        'ruc_emisor' => $cfg['facturacion_ruc_emisor'] ?? '',
        'pedido' => [
            'codigo' => $pedido['codigo'],
            'cliente' => [
                'nombre' => $pedido['cliente_nombre'] ?? '',
                'dni'    => $pedido['cliente_dni'] ?? '',
                'email'  => $pedido['cliente_email'] ?? '',
            ],
            'items' => array_map(fn($d) => [
                'nombre'          => $d['producto_nombre'],
                'cantidad'        => (int)$d['cantidad'],
                'precio_unitario' => (float)$d['precio_unitario'],
                'subtotal'        => (float)$d['subtotal'],
            ], $det->fetchAll()),
            'subtotal' => (float)$pedido['subtotal'],
            'envio'    => (float)$pedido['costo_envio'],
            'total'    => (float)$pedido['total'],
        ],
    ];

    $resultado = _llamarApiFacturacion($cfg['facturacion_api_url'], $cfg['facturacion_api_token'] ?? '', $payload);

    if ($resultado['success']) {
        $pdo->prepare('
            UPDATE pedidos
            SET comprobante_tipo = ?, comprobante_numero = ?, comprobante_url = ?,
                comprobante_estado = ?, comprobante_error = ?
            WHERE id = ?
        ')->execute([
            $resultado['comprobante_tipo'] ?? '',
            $resultado['comprobante_numero'] ?? '',
            $resultado['comprobante_url'] ?? '',
            'emitido',
            '',
            $pedidoId,
        ]);
    } else {
        $pdo->prepare('
            UPDATE pedidos SET comprobante_estado = ?, comprobante_error = ? WHERE id = ?
        ')->execute(['error', $resultado['error'] ?? 'Error desconocido', $pedidoId]);
    }

    return $resultado;
}

function _llamarApiFacturacion(string $url, string $token, array $payload): array {
    $headers = "Content-Type: application/json\r\n";
    if ($token !== '') {
        $headers .= "Authorization: Bearer $token\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => $headers,
            'content'       => json_encode($payload),
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return ['success' => false, 'error' => 'No se pudo conectar con el proveedor de facturación'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Respuesta inválida del proveedor de facturación'];
    }

    if (empty($data['success'])) {
        return ['success' => false, 'error' => $data['error'] ?? 'El proveedor rechazó la emisión del comprobante'];
    }

    return [
        'success'            => true,
        'comprobante_tipo'   => $data['comprobante_tipo'] ?? '',
        'comprobante_numero' => $data['comprobante_numero'] ?? '',
        'comprobante_url'    => $data['comprobante_url'] ?? '',
    ];
}
