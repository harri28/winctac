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
    $stmt = $pdo->prepare("
        SELECT p.id, p.nombre, p.codigo, p.descripcion, p.precio, p.stock,
               p.categoria_id, c.nombre AS categoria, p.etiquetas, p.created_at,
               CASE WHEN p.imagen_path <> '' THEN '$imgBase' || p.imagen_path ELSE NULL END AS imagen
        FROM productos p
        LEFT JOIN categorias c ON c.id = p.categoria_id
        WHERE p.activo = TRUE AND p.tienda_id = ?
        ORDER BY p.nombre ASC
    ");
    $stmt->execute([TIENDA_ID]);
    $productos = $stmt->fetchAll();
    // Las etiquetas son uso interno del buscador: nunca se muestran en la UI,
    // pero viajan en el catálogo para que el filtro de búsqueda las revise.
    foreach ($productos as &$p) {
        $tags = json_decode($p['etiquetas'] ?? '[]', true);
        $p['etiquetas'] = is_array($tags) ? $tags : [];
    }
    return $productos;
}

// Catálogo paginado para el frontend público — filtra, busca y ordena en
// Postgres y devuelve solo la página pedida + metadata. Reemplaza al patrón
// anterior de "cargar todo y filtrar en JS", que no escala pasando los pocos
// miles de productos. `crear_pedido` sigue usando obtenerCatalogo() porque
// necesita el map completo id → precio/stock para revalidar el carrito.
function obtenerCatalogoPaginado(PDO $pdo, array $filtros): array {
    $imgBase = BASE_URL . '/uploads/productos/';
    $page    = max(1, (int) ($filtros['page']     ?? 1));
    $perPage = min(200, max(1, (int) ($filtros['per_page'] ?? 48)));
    $catId   = (string) ($filtros['categoria_id'] ?? '');
    $q       = trim((string) ($filtros['q'] ?? ''));
    $sort    = (string) ($filtros['sort'] ?? 'nombre'); // nombre | nuevos | precio_asc | precio_desc

    $where  = ['p.activo = TRUE', 'p.tienda_id = ?'];
    $params = [TIENDA_ID];

    if ($catId !== '' && $catId !== 'all') {
        $where[] = 'p.categoria_id = ?';
        $params[] = (int) $catId;
    }

    if ($q !== '') {
        // Búsqueda insensible a mayúsculas sobre nombre, código y etiquetas (array JSON).
        // Las etiquetas se castean a texto para que ILIKE las encuentre sin desempacar el JSON.
        $where[] = '(p.nombre ILIKE ? OR p.codigo ILIKE ? OR p.etiquetas::text ILIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $orderBy = match ($sort) {
        'nuevos'      => 'p.created_at DESC, p.id DESC',
        'precio_asc'  => 'p.precio ASC,  p.nombre ASC',
        'precio_desc' => 'p.precio DESC, p.nombre ASC',
        default       => 'p.nombre ASC',
    };

    $whereSql = implode(' AND ', $where);

    // Total: una query COUNT corta.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM productos p WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    // LIMIT y OFFSET se interpolan como enteros (ya saneados arriba), no como
    // parámetros, porque algunos drivers PDO rechazan placeholders ahí.
    $sql = "
        SELECT p.id, p.nombre, p.codigo, p.descripcion, p.precio, p.stock,
               p.categoria_id, c.nombre AS categoria, p.etiquetas, p.created_at,
               CASE WHEN p.imagen_path <> '' THEN '$imgBase' || p.imagen_path ELSE NULL END AS imagen
        FROM productos p
        LEFT JOIN categorias c ON c.id = p.categoria_id
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
    foreach ($productos as &$p) {
        $tags = json_decode($p['etiquetas'] ?? '[]', true);
        $p['etiquetas'] = is_array($tags) ? $tags : [];
    }

    return [
        'data'        => $productos,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ];
}

try {
    switch ($action) {

        // ── CATEGORÍAS ──────────────────────────────────────
        case 'categorias':
            $catsStmt = $pdo->prepare('SELECT id, nombre FROM categorias WHERE activo = TRUE AND tienda_id = ? ORDER BY nombre ASC');
            $catsStmt->execute([TIENDA_ID]);
            echo json_encode(['success' => true, 'data' => $catsStmt->fetchAll()]);
            break;

        // ── PRODUCTOS ───────────────────────────────────────
        // Paginado y filtrado server-side: ?page, ?per_page, ?categoria, ?q, ?sort.
        // Compatibilidad con clientes viejos: si no se pasa ningún parámetro
        // de filtro/paginación, igual se devuelve una primera página con un
        // tope prudente, no el catálogo completo.
        case 'productos':
            $res = obtenerCatalogoPaginado($pdo, [
                'page'         => $_GET['page']      ?? 1,
                'per_page'     => $_GET['per_page']  ?? 48,
                'categoria_id' => $_GET['categoria'] ?? null,
                'q'            => $_GET['q']         ?? '',
                'sort'         => $_GET['sort']      ?? 'nombre',
            ]);
            echo json_encode([
                'success'     => true,
                'data'        => $res['data'],
                'total'       => $res['total'],
                'page'        => $res['page'],
                'per_page'    => $res['per_page'],
                'total_pages' => $res['total_pages'],
            ]);
            break;

        // ── PRODUCTOS EN PROMOCIÓN (para la fila de Inicio) ──
        case 'promociones':
            $imgBase = BASE_URL . '/uploads/productos/';
            $promoStmt = $pdo->prepare("
                SELECT p.id, p.nombre, p.codigo, p.precio, p.stock,
                       p.categoria_id, c.nombre AS categoria,
                       CASE WHEN p.imagen_path <> '' THEN '$imgBase' || p.imagen_path ELSE NULL END AS imagen,
                       MIN(pp.orden) AS orden
                FROM promocion_productos pp
                JOIN promociones prom ON prom.id = pp.promocion_id AND prom.activo = TRUE AND prom.tienda_id = ?
                JOIN productos p ON p.id = pp.producto_id AND p.activo = TRUE
                LEFT JOIN categorias c ON c.id = p.categoria_id
                GROUP BY p.id, p.nombre, p.codigo, p.precio, p.stock, p.categoria_id, c.nombre, p.imagen_path
                ORDER BY orden ASC, p.nombre ASC
            ");
            $promoStmt->execute([TIENDA_ID]);
            echo json_encode(['success' => true, 'data' => $promoStmt->fetchAll()]);
            break;

        // ── CREAR PEDIDO ─────────────────────────────────────
        case 'crear_pedido':
            // Modo catálogo electrónico: el carrito está desactivado — no se
            // aceptan pedidos aunque alguien llame al endpoint directamente.
            if (!carritoActivo()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Las compras online están desactivadas. Contáctanos por WhatsApp.']);
                break;
            }
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
                ')->execute([$cNombre, $cApells, $cDni, $email, $cCelular, password_hash($password, PASSWORD_DEFAULT)]);
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

            // Código de descuento (opcional): puede ser una campaña pública (sin
            // login) o un cupón de puntos personal (requiere login) — siempre
            // revalidado server-side, nunca se confía en el descuento del navegador.
            $cuponCodigo = trim($body['cupon_codigo'] ?? '');
            $baseTotal   = $subtotal + $costoEnvio;
            $descuento   = 0;
            $cuponRow    = null;
            $campanaRow  = null;
            if ($cuponCodigo) {
                $cam = $pdo->prepare("
                    SELECT * FROM campanas
                    WHERE tienda_id = ? AND UPPER(codigo) = UPPER(?) AND activo = TRUE
                      AND NOW() BETWEEN fecha_inicio AND fecha_fin
                      AND (limite_usos IS NULL OR usos_actuales < limite_usos)
                ");
                $cam->execute([TIENDA_ID, $cuponCodigo]);
                $campanaRow = $cam->fetch();

                if ($campanaRow) {
                    if ($baseTotal < floatval($campanaRow['compra_minima'])) {
                        echo json_encode(['success' => false, 'error' => 'El pedido no alcanza la compra mínima de este código (' . formatMoney($campanaRow['compra_minima']) . ').']);
                        break;
                    }
                    $descuento = $campanaRow['tipo'] === 'monto'
                        ? floatval($campanaRow['valor'])
                        : $baseTotal * floatval($campanaRow['valor']) / 100;
                    $descuento = min($descuento, $baseTotal);
                } else {
                    if (!$cliId) {
                        echo json_encode(['success' => false, 'error' => 'Código no válido.']);
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
            $codigoAplicado = $campanaRow ? $campanaRow['codigo'] : ($cuponRow ? $cuponRow['codigo'] : '');
            $ins->execute([
                $codigo, $cliId, $clienteNombre, $clienteCelular, $clienteDni, $tipoEntrega,
                $empresaEnvioId, $empresaEnvioNom, $costoEnvio,
                $subtotal, $total, $metodoPago, $direccion, $notas,
                $codigoAplicado, $descuento
            ]);
            $pedidoId = $pdo->lastInsertId();

            if ($campanaRow) {
                $useCampana = $pdo->prepare("UPDATE campanas SET usos_actuales = usos_actuales + 1 WHERE id = ? AND (limite_usos IS NULL OR usos_actuales < limite_usos)");
                $useCampana->execute([$campanaRow['id']]);
                if ($useCampana->rowCount() === 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Este código ya alcanzó su límite de usos.']);
                    break;
                }
            } elseif ($cuponRow) {
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
            $descStock = $pdo->prepare('UPDATE productos SET stock = stock - ?, updated_at = NOW() WHERE id = ? AND stock >= ? AND tienda_id = ?');
            foreach ($items as $i) {
                $sub = floatval($i['precio']) * intval($i['cantidad']);
                $insDet->execute([
                    $pedidoId, $i['id'], $i['nombre'], $i['codigo'] ?? '',
                    floatval($i['precio']), intval($i['cantidad']), $sub, $i['imagen'] ?? ''
                ]);

                $descStock->execute([intval($i['cantidad']), $i['id'], intval($i['cantidad']), TIENDA_ID]);
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
            $body      = json_decode(file_get_contents('php://input'), true);
            $codigo    = trim($body['codigo'] ?? '');
            $baseTotal = floatval($body['subtotal'] ?? 0) + floatval($body['costo_envio'] ?? 0);

            // Primero se busca como código de campaña (público, sin login).
            $cam = $pdo->prepare("
                SELECT * FROM campanas
                WHERE tienda_id = ? AND UPPER(codigo) = UPPER(?) AND activo = TRUE
                  AND NOW() BETWEEN fecha_inicio AND fecha_fin
                  AND (limite_usos IS NULL OR usos_actuales < limite_usos)
            ");
            $cam->execute([TIENDA_ID, $codigo]);
            $campana = $cam->fetch();

            if ($campana) {
                if ($baseTotal < floatval($campana['compra_minima'])) {
                    echo json_encode(['success' => false, 'error' => 'El pedido no alcanza la compra mínima de este código (' . formatMoney($campana['compra_minima']) . ').']);
                    break;
                }
                $descuento = $campana['tipo'] === 'monto' ? floatval($campana['valor']) : $baseTotal * floatval($campana['valor']) / 100;
                $descuento = min($descuento, $baseTotal);
                echo json_encode(['success' => true, 'descuento' => $descuento, 'nombre' => $campana['nombre']]);
                break;
            }

            // Si no es un código de campaña, se busca como cupón de puntos (personal, requiere login).
            if (!clienteLogueado()) {
                echo json_encode(['success' => false, 'error' => 'Código no válido.']);
                break;
            }
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

        // ── SUSCRIBIRSE A UN BANNER FLOTANTE (captura de lead) ────────────
        // Guarda al visitante como lead y, si el banner tiene una campaña
        // asociada, le devuelve el código de descuento para usar en checkout.
        // Si el email ya existe para esta tienda, devuelve el código original
        // (no re-crea el lead) — así el visitante no se frustra por error.
        case 'suscribir_banner':
            $body     = json_decode(file_get_contents('php://input'), true) ?: [];
            $bannerId = intval($body['banner_id'] ?? 0);
            $nombre    = trim($body['nombre']     ?? '');
            $apellido  = trim($body['apellido']   ?? '');
            $email     = trim($body['email']      ?? '');
            $celular   = trim($body['celular']    ?? '');
            $genero    = trim($body['genero']     ?? '');
            $cumpleStr = trim($body['cumpleanos'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Correo electrónico inválido.']);
                break;
            }

            // Verifica que el banner exista y esté activo para esta tienda
            $bStmt = $pdo->prepare('SELECT bf.*, c.codigo AS cup_codigo FROM banners_flotantes bf
                                    LEFT JOIN campanas c ON c.id = bf.campana_id
                                    WHERE bf.id = ? AND bf.tienda_id = ? AND bf.activo = TRUE');
            $bStmt->execute([$bannerId, TIENDA_ID]);
            $banner = $bStmt->fetch();
            if (!$banner) {
                echo json_encode(['success' => false, 'error' => 'Banner no disponible.']);
                break;
            }

            $cumpleanos = $cumpleStr && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cumpleStr) ? $cumpleStr : null;
            $codigo     = $banner['cup_codigo'] ?: '';
            $ip         = $_SERVER['REMOTE_ADDR'] ?? '';

            // Si ya existe un lead con ese email + tienda, devolvemos su
            // código original. ON CONFLICT lo haría más limpio, pero así
            // también sabemos si fue reutilización para log.
            $yaStmt = $pdo->prepare('SELECT codigo_entregado FROM banners_flotantes_leads WHERE tienda_id = ? AND email = ?');
            $yaStmt->execute([TIENDA_ID, $email]);
            $ya = $yaStmt->fetch();
            if ($ya) {
                echo json_encode([
                    'success' => true,
                    'ya_suscrito' => true,
                    'codigo'  => $ya['codigo_entregado'] ?: $codigo,
                    'mensaje' => $banner['mensaje_exito'],
                ]);
                break;
            }

            $pdo->prepare('INSERT INTO banners_flotantes_leads
                (tienda_id, banner_id, nombre, apellido, email, celular, genero, cumpleanos, codigo_entregado, ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([TIENDA_ID, $banner['id'], $nombre, $apellido, $email, $celular, $genero, $cumpleanos, $codigo, $ip]);

            echo json_encode([
                'success' => true,
                'codigo'  => $codigo,
                'mensaje' => $banner['mensaje_exito'],
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
