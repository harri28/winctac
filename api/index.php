<?php
// ============================================================
// API INTERNA — Selvadigital Ecommerce
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/puntos.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$pdo = getDB();

// Catálogo local propio. Centralizado acá para que `productos` y
// `crear_pedido` usen exactamente la misma fuente — el pedido nunca confía
// en precio/stock enviados por el navegador.
function obtenerCatalogo(PDO $pdo): array {
    $imgBase = BASE_URL . '/uploads/productos/';
    $productos = $pdo->query("
        SELECT p.id, p.nombre, p.codigo, p.descripcion, p.precio, p.stock,
               p.categoria_id, c.nombre AS categoria, p.etiquetas,
               CASE WHEN p.imagen_path <> '' THEN '$imgBase' || p.imagen_path ELSE NULL END AS imagen
        FROM productos p
        LEFT JOIN categorias c ON c.id = p.categoria_id
        WHERE p.activo = TRUE
        ORDER BY p.nombre ASC
    ")->fetchAll();
    // Las etiquetas son uso interno del buscador: nunca se muestran en la UI,
    // pero viajan en el catálogo para que el filtro de búsqueda las revise.
    foreach ($productos as &$p) {
        $tags = json_decode($p['etiquetas'] ?? '[]', true);
        $p['etiquetas'] = is_array($tags) ? $tags : [];
    }
    return $productos;
}

try {
    switch ($action) {

        // ── CATEGORÍAS ──────────────────────────────────────
        case 'categorias':
            $cats = $pdo->query('SELECT id, nombre FROM categorias WHERE activo = TRUE ORDER BY nombre ASC')->fetchAll();
            echo json_encode(['success' => true, 'data' => $cats]);
            break;

        // ── PRODUCTOS ───────────────────────────────────────
        case 'productos':
            $productos = obtenerCatalogo($pdo);
            echo json_encode(['success' => true, 'data' => $productos]);
            break;

        // ── CREAR PEDIDO ─────────────────────────────────────
        case 'crear_pedido':
            $body = json_decode(file_get_contents('php://input'), true);
            $items           = $body['items'] ?? [];
            $metodoPago      = $body['metodo_pago'] ?? '';
            $empresaEnvioId  = $body['empresa_envio_id'] ?: null;
            $empresaEnvioNom = $body['empresa_envio_nombre'] ?? '';
            $costoEnvio      = floatval($body['costo_envio'] ?? 0);
            $direccion       = $body['direccion_entrega'] ?? '';
            $notas           = $body['notas'] ?? '';
            $tipoEntrega     = $body['tipo_entrega'] ?? 'delivery';
            $clienteNombre   = trim($body['cliente_nombre'] ?? '');
            $clienteCelular  = trim($body['cliente_celular'] ?? '');
            $clienteDni      = trim($body['cliente_dni'] ?? '');
            $crearCuenta     = $body['crear_cuenta'] ?? null;

            if (!$items) {
                echo json_encode(['success' => false, 'error' => 'Carrito vacío']);
                break;
            }

            // Validar cada ítem contra el catálogo real: nunca confiar en el
            // precio/stock que manda el navegador.
            $catalogoMap = array_column(obtenerCatalogo($pdo), null, 'id');

            $itemsValidados = [];
            foreach ($items as $i) {
                $cantidad = intval($i['cantidad'] ?? 0);
                if ($cantidad < 1) {
                    echo json_encode(['success' => false, 'error' => 'Cantidad inválida en el carrito.']);
                    break 2;
                }

                $real = $catalogoMap[$i['id']] ?? null;
                if (!$real) {
                    echo json_encode(['success' => false, 'error' => 'Uno de los productos ya no está disponible. Actualiza tu carrito.']);
                    break 2;
                }
                if ($cantidad > intval($real['stock'] ?? 0)) {
                    echo json_encode(['success' => false, 'error' => 'No hay stock suficiente de "' . $real['nombre'] . '".']);
                    break 2;
                }
                $itemsValidados[] = [
                    'id' => $real['id'], 'nombre' => $real['nombre'], 'codigo' => $real['codigo'] ?? '',
                    'precio' => floatval($real['precio']), 'cantidad' => $cantidad, 'imagen' => $real['imagen'] ?? '',
                ];
            }
            $items = $itemsValidados;

            // Determinar cliente_id
            $cliId = clienteLogueado() ? clienteId() : null;

            // Crear cuenta si se solicitó
            if (!$cliId && $crearCuenta) {
                $email    = trim($crearCuenta['email'] ?? '');
                $password = $crearCuenta['password'] ?? '';
                $cNombre  = trim($crearCuenta['nombre'] ?? '');
                $cApells  = trim($crearCuenta['apellidos'] ?? '');
                $cCelular = trim($crearCuenta['celular'] ?? '');
                $cDni     = trim($crearCuenta['dni'] ?? '');

                if (!$email || strlen($password) < 6) {
                    echo json_encode(['success' => false, 'error' => 'Datos de cuenta inválidos.']);
                    break;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'error' => 'El correo electrónico no es válido.']);
                    break;
                }
                $ex = $pdo->prepare('SELECT 1 FROM clientes WHERE email = ?');
                $ex->execute([$email]);
                if ($ex->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Ya existe una cuenta con ese correo electrónico.']);
                    break;
                }
                $pdo->prepare('
                    INSERT INTO clientes (nombre, apellidos, dni, email, celular, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?)
                ')->execute([$cNombre, $cApells, $cDni, $email, $cCelular, password_hash($password)]);
                $c = $pdo->prepare('SELECT * FROM clientes WHERE email = ?');
                $c->execute([$email]);
                $cData = $c->fetch();
                loginCliente($cData);
                $cliId = $cData['id'];
            }

            $subtotal = 0;
            foreach ($items as $i) {
                $subtotal += floatval($i['precio']) * intval($i['cantidad']);
            }

            // Cupón de puntos (opcional): revalidado siempre server-side, nunca se
            // confía en el descuento que calcule el navegador.
            $cuponCodigo = trim($body['cupon_codigo'] ?? '');
            $baseTotal   = $subtotal + $costoEnvio;
            $descuento   = 0;
            $cuponRow    = null;
            if ($cuponCodigo) {
                if (!$cliId) {
                    echo json_encode(['success' => false, 'error' => 'Debes iniciar sesión para usar un cupón.']);
                    break;
                }
                $cs = $pdo->prepare("
                    SELECT * FROM puntos_cupones
                    WHERE codigo = ? AND cliente_id = ? AND estado = 'activo'
                      AND (expira_at IS NULL OR expira_at > NOW())
                ");
                $cs->execute([$cuponCodigo, $cliId]);
                $cuponRow = $cs->fetch();
                if (!$cuponRow) {
                    echo json_encode(['success' => false, 'error' => 'Cupón no válido, vencido o ya usado.']);
                    break;
                }
                if ($baseTotal < floatval($cuponRow['compra_minima'])) {
                    echo json_encode(['success' => false, 'error' => 'El pedido no alcanza la compra mínima de este cupón (' . formatMoney($cuponRow['compra_minima']) . ').']);
                    break;
                }
                $descuento = $cuponRow['tipo'] === 'monto'
                    ? floatval($cuponRow['valor'])
                    : $baseTotal * floatval($cuponRow['valor']) / 100;
                $descuento = min($descuento, $baseTotal);
            }

            $total  = $baseTotal - $descuento;
            $codigo = generarCodigoPedidoUnico($pdo);

            $pdo->beginTransaction();
            $ins = $pdo->prepare('
                INSERT INTO pedidos
                    (codigo, cliente_id, cliente_nombre, cliente_celular, cliente_dni, tipo_entrega,
                     empresa_envio_id, empresa_envio_nombre, costo_envio,
                     subtotal, total, metodo_pago, estado, direccion_entrega, notas,
                     cupon_codigo, cupon_descuento)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\', ?, ?, ?, ?)
            ');
            $ins->execute([
                $codigo, $cliId, $clienteNombre, $clienteCelular, $clienteDni, $tipoEntrega,
                $empresaEnvioId, $empresaEnvioNom, $costoEnvio,
                $subtotal, $total, $metodoPago, $direccion, $notas,
                $cuponRow ? $cuponRow['codigo'] : '', $descuento
            ]);
            $pedidoId = $pdo->lastInsertId();

            if ($cuponRow) {
                $useCupon = $pdo->prepare("UPDATE puntos_cupones SET estado = 'usado', pedido_id = ?, used_at = NOW() WHERE id = ? AND estado = 'activo'");
                $useCupon->execute([$pedidoId, $cuponRow['id']]);
                if ($useCupon->rowCount() === 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'El cupón ya no está disponible.']);
                    break;
                }
            }

            $insDet = $pdo->prepare('
                INSERT INTO pedido_detalles (pedido_id, producto_id, producto_nombre, producto_codigo, precio_unitario, cantidad, subtotal, imagen_url)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            // Descuenta stock solo si sigue alcanzando (protege contra dos compras simultáneas del último stock)
            $descStock = $pdo->prepare('UPDATE productos SET stock = stock - ?, updated_at = NOW() WHERE id = ? AND stock >= ?');
            foreach ($items as $i) {
                $sub = floatval($i['precio']) * intval($i['cantidad']);
                $insDet->execute([
                    $pedidoId, $i['id'], $i['nombre'], $i['codigo'] ?? '',
                    floatval($i['precio']), intval($i['cantidad']), $sub, $i['imagen'] ?? ''
                ]);

                $descStock->execute([intval($i['cantidad']), $i['id'], intval($i['cantidad'])]);
                if ($descStock->rowCount() === 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'No hay stock suficiente de "' . $i['nombre'] . '". Otro cliente lo compró recién.']);
                    break 2;
                }
            }

            $pdo->commit();
            $_SESSION['ultimo_pedido'] = $codigo;
            echo json_encode(['success' => true, 'codigo' => $codigo, 'pedido_id' => $pedidoId]);
            break;

        // ── LOGIN CLIENTE ────────────────────────────────────
        case 'login_cliente':
            $body  = json_decode(file_get_contents('php://input'), true);
            $email = trim($body['email'] ?? '');
            $pass  = $body['password'] ?? '';

            $stmt = $pdo->prepare('SELECT * FROM clientes WHERE email = ? AND activo = TRUE');
            $stmt->execute([$email]);
            $c = $stmt->fetch();

            if ($c && password_verify($pass, $c['password_hash'])) {
                loginCliente($c);
                echo json_encode(['success' => true, 'nombre' => $c['nombre']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas']);
            }
            break;

        // ── MIS PEDIDOS ──────────────────────────────────────
        case 'mis_pedidos':
            if (!clienteLogueado()) {
                echo json_encode(['success' => false, 'error' => 'No autenticado']);
                break;
            }
            $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE cliente_id = ? ORDER BY created_at DESC LIMIT 20');
            $stmt->execute([clienteId()]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── VALIDAR CUPÓN (previsualización en checkout) ─────
        case 'validar_cupon':
            if (!clienteLogueado()) {
                echo json_encode(['success' => false, 'error' => 'Debes iniciar sesión para usar un cupón.']);
                break;
            }
            $body      = json_decode(file_get_contents('php://input'), true);
            $codigo    = trim($body['codigo'] ?? '');
            $baseTotal = floatval($body['subtotal'] ?? 0) + floatval($body['costo_envio'] ?? 0);

            $cs = $pdo->prepare("
                SELECT * FROM puntos_cupones
                WHERE codigo = ? AND cliente_id = ? AND estado = 'activo'
                  AND (expira_at IS NULL OR expira_at > NOW())
            ");
            $cs->execute([$codigo, clienteId()]);
            $cupon = $cs->fetch();
            if (!$cupon) {
                echo json_encode(['success' => false, 'error' => 'Cupón no válido, vencido o ya usado.']);
                break;
            }
            if ($baseTotal < floatval($cupon['compra_minima'])) {
                echo json_encode(['success' => false, 'error' => 'El pedido no alcanza la compra mínima de este cupón (' . formatMoney($cupon['compra_minima']) . ').']);
                break;
            }
            $descuento = $cupon['tipo'] === 'monto' ? floatval($cupon['valor']) : $baseTotal * floatval($cupon['valor']) / 100;
            $descuento = min($descuento, $baseTotal);
            echo json_encode(['success' => true, 'descuento' => $descuento, 'nombre' => $cupon['recompensa_nombre']]);
            break;

        // ── CANJEAR RECOMPENSA ────────────────────────────────
        case 'canjear_recompensa':
            if (!clienteLogueado()) {
                echo json_encode(['success' => false, 'error' => 'Debes iniciar sesión.']);
                break;
            }
            $body         = json_decode(file_get_contents('php://input'), true);
            $recompensaId = intval($body['recompensa_id'] ?? 0);

            $rs = $pdo->prepare('SELECT * FROM puntos_recompensas WHERE id = ? AND activo = TRUE');
            $rs->execute([$recompensaId]);
            $recompensa = $rs->fetch();
            if (!$recompensa) {
                echo json_encode(['success' => false, 'error' => 'Recompensa no disponible.']);
                break;
            }

            $cs = $pdo->prepare('SELECT puntos_saldo FROM clientes WHERE id = ?');
            $cs->execute([clienteId()]);
            $saldo = (int) $cs->fetchColumn();
            if ($saldo < intval($recompensa['costo_puntos'])) {
                echo json_encode(['success' => false, 'error' => 'No tienes suficientes puntos para esta recompensa.']);
                break;
            }

            $codigoCupon = generarCodigoCuponUnico($pdo);
            $expiraAt    = intval($recompensa['vigencia_dias']) > 0
                ? date('Y-m-d H:i:s', strtotime('+' . intval($recompensa['vigencia_dias']) . ' days'))
                : null;

            $pdo->beginTransaction();
            $pdo->prepare('
                INSERT INTO puntos_cupones
                    (codigo, cliente_id, recompensa_id, recompensa_nombre, tipo, valor, compra_minima, puntos_gastados, expira_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $codigoCupon, clienteId(), $recompensa['id'], $recompensa['nombre'],
                $recompensa['tipo'], $recompensa['valor'], $recompensa['compra_minima'],
                $recompensa['costo_puntos'], $expiraAt
            ]);
            $pdo->prepare('
                INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, recompensa_id, nota)
                VALUES (?, \'canjeado\', ?, ?, ?)
            ')->execute([clienteId(), -intval($recompensa['costo_puntos']), $recompensa['id'], 'Canje: ' . $recompensa['nombre']]);
            $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo - ? WHERE id = ?')
                ->execute([$recompensa['costo_puntos'], clienteId()]);
            $pdo->commit();

            echo json_encode(['success' => true, 'codigo' => $codigoCupon]);
            break;

        // ── MIS PUNTOS ────────────────────────────────────────
        case 'mis_puntos':
            if (!clienteLogueado()) {
                echo json_encode(['success' => false, 'error' => 'No autenticado']);
                break;
            }
            $saldo = $pdo->prepare('SELECT puntos_saldo FROM clientes WHERE id = ?');
            $saldo->execute([clienteId()]);

            $recompensas = $pdo->query('SELECT * FROM puntos_recompensas WHERE activo = TRUE ORDER BY costo_puntos ASC')->fetchAll();

            $cupones = $pdo->prepare('SELECT * FROM puntos_cupones WHERE cliente_id = ? ORDER BY created_at DESC');
            $cupones->execute([clienteId()]);

            $mov = $pdo->prepare('SELECT * FROM puntos_movimientos WHERE cliente_id = ? ORDER BY created_at DESC LIMIT 50');
            $mov->execute([clienteId()]);

            echo json_encode([
                'success'      => true,
                'saldo'        => (int) $saldo->fetchColumn(),
                'recompensas'  => $recompensas,
                'cupones'      => $cupones->fetchAll(),
                'movimientos'  => $mov->fetchAll(),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
