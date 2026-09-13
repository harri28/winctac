<?php
// ============================================================
// API ADMIN — Selvadigital Ecommerce
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/facturacion.php';
require_once __DIR__ . '/../includes/puntos.php';

function adminLogueado(): bool { return !empty($_SESSION['admin_id']); }

header('Content-Type: application/json; charset=utf-8');

if (!adminLogueado()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo    = getDB();

try {
    switch ($action) {

        // ── PEDIDOS ──────────────────────────────────────────
        case 'cambiar_estado':
            $body   = json_decode(file_get_contents('php://input'), true);
            $id     = intval($body['id'] ?? 0);
            $estado = $body['estado'] ?? '';
            if (!in_array($estado, ['pendiente','confirmado','enviado','entregado','cancelado'])) {
                echo json_encode(['success' => false, 'error' => 'Estado no válido']); break;
            }
            $ya = $pdo->prepare('SELECT comprobante_estado FROM pedidos WHERE id = ?');
            $ya->execute([$id]);
            $comprobanteEstado = $ya->fetchColumn();

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE pedidos SET estado = ?, updated_at = NOW() WHERE id = ?')->execute([$estado, $id]);

            if ($estado === 'confirmado') {
                otorgarPuntosPedido($pdo, $id);
            } elseif ($estado === 'cancelado') {
                revertirPuntosPedido($pdo, $id);
            }
            $pdo->commit();

            $facturacion = null;
            if ($estado === 'confirmado' && $comprobanteEstado !== 'emitido') {
                $facturacion = emitirComprobante($pdo, $id);
            }
            echo json_encode(['success' => true, 'facturacion' => $facturacion]);
            break;

        case 'detalle_pedido':
            $id = intval($_GET['id'] ?? 0);
            $p  = $pdo->prepare('
                SELECT p.*,
                       COALESCE(c.nombre, p.cliente_nombre) AS cliente_nombre,
                       c.email AS cliente_email,
                       COALESCE(c.celular, p.cliente_celular) AS cliente_celular
                FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?
            ');
            $p->execute([$id]);
            $pedido = $p->fetch();
            $det    = $pdo->prepare('SELECT * FROM pedido_detalles WHERE pedido_id = ?');
            $det->execute([$id]);
            echo json_encode(['success' => true, 'pedido' => $pedido, 'detalles' => $det->fetchAll()]);
            break;

        // ── PRODUCTOS ────────────────────────────────────────
        case 'toggle_producto':
            $body       = json_decode(file_get_contents('php://input'), true);
            $productoId = intval($body['producto_id'] ?? 0);
            $activo     = ($body['activo'] ?? true) ? 't' : 'f';
            $pdo->prepare('UPDATE productos SET activo = ?, updated_at = NOW() WHERE id = ? AND tienda_id = ?')->execute([$activo, $productoId, TIENDA_ID]);
            echo json_encode(['success' => true]);
            break;

        case 'toggle_todos':
            $body   = json_decode(file_get_contents('php://input'), true);
            $activo = ($body['activo'] ?? true) ? 't' : 'f';
            $pdo->prepare('UPDATE productos SET activo = ?, updated_at = NOW() WHERE tienda_id = ?')->execute([$activo, TIENDA_ID]);
            echo json_encode(['success' => true]);
            break;

        case 'guardar_producto':
            $productoId  = intval($_POST['producto_id'] ?? 0);
            $nombre      = trim($_POST['nombre'] ?? '');
            $codigo      = trim($_POST['codigo'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $stock       = intval($_POST['stock'] ?? 0);
            $categoriaId = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
            $activo      = (isset($_POST['activo']) && $_POST['activo'] !== '0') ? 't' : 'f';

            $marca            = trim(mb_substr($_POST['marca'] ?? '', 0, 100));
            $um               = trim(mb_substr($_POST['um'] ?? '', 0, 20));
            $costoAnterior    = max(0, floatval($_POST['costo_anterior'] ?? 0));
            $costoActual      = max(0, floatval($_POST['costo_actual'] ?? 0));
            $minimoPorcentaje = floatval($_POST['minimo_porcentaje'] ?? 0);
            $listaPorcentaje  = floatval($_POST['lista_porcentaje'] ?? 0);

            // El precio de venta se calcula del costeo, nunca se escribe a mano.
            // Si todavía no se cargó un costo actual para este producto (caso de
            // productos ya existentes antes de este costeo), se conserva el
            // precio que ya tenía en vez de pisarlo con 0.
            if ($costoActual > 0) {
                $precio = round($costoActual * (1 + $listaPorcentaje / 100), 2);
            } elseif ($productoId) {
                $prevPrecio = $pdo->prepare('SELECT precio FROM productos WHERE id = ? AND tienda_id = ?');
                $prevPrecio->execute([$productoId, TIENDA_ID]);
                $precio = (float) $prevPrecio->fetchColumn();
            } else {
                $precio = 0;
            }

            // Etiquetas de búsqueda: llegan como JSON (array de strings) desde el input tipo chips.
            // Nunca se confía en lo que mande el navegador: se limpia, se recorta longitud y se
            // deduplica sin distinguir mayúsculas/minúsculas antes de guardar.
            $etiquetasRaw = json_decode($_POST['etiquetas'] ?? '[]', true);
            $etiquetas    = [];
            if (is_array($etiquetasRaw)) {
                $vistas = [];
                foreach ($etiquetasRaw as $t) {
                    $t = trim(mb_substr((string)$t, 0, 40));
                    if ($t === '' || isset($vistas[mb_strtolower($t)])) continue;
                    $vistas[mb_strtolower($t)] = true;
                    $etiquetas[] = $t;
                }
            }
            $etiquetasJson = json_encode($etiquetas, JSON_UNESCAPED_UNICODE);

            if (!$nombre) { echo json_encode(['success' => false, 'error' => 'El nombre es requerido']); break; }
            if ($precio < 0 || $stock < 0) { echo json_encode(['success' => false, 'error' => 'Precio y stock no pueden ser negativos']); break; }

            // Imagen (opcional)
            $imagenPath = null; // null = no tocar la imagen actual
            if (!empty($_FILES['imagen']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    echo json_encode(['success' => false, 'error' => 'Formato de imagen no permitido']); break;
                }
                $dir = UPLOADS_PATH . '/productos';
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $fname = 'prod_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . '/' . $fname)) {
                    echo json_encode(['success' => false, 'error' => 'Error al guardar la imagen']); break;
                }
                $imagenPath = $fname;
            }

            if ($productoId) {
                if ($imagenPath !== null) {
                    $prev = $pdo->prepare('SELECT imagen_path FROM productos WHERE id = ? AND tienda_id = ?');
                    $prev->execute([$productoId, TIENDA_ID]);
                    $prevPath = $prev->fetchColumn();
                    if ($prevPath && file_exists(UPLOADS_PATH . '/productos/' . $prevPath)) {
                        @unlink(UPLOADS_PATH . '/productos/' . $prevPath);
                    }
                    $pdo->prepare('
                        UPDATE productos SET nombre=?, codigo=?, descripcion=?, precio=?, stock=?, categoria_id=?, activo=?, imagen_path=?, etiquetas=?,
                               marca=?, um=?, costo_anterior=?, costo_actual=?, minimo_porcentaje=?, lista_porcentaje=?, updated_at=NOW()
                        WHERE id=? AND tienda_id=?
                    ')->execute([$nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $imagenPath, $etiquetasJson,
                                  $marca, $um, $costoAnterior, $costoActual, $minimoPorcentaje, $listaPorcentaje, $productoId, TIENDA_ID]);
                } else {
                    $pdo->prepare('
                        UPDATE productos SET nombre=?, codigo=?, descripcion=?, precio=?, stock=?, categoria_id=?, activo=?, etiquetas=?,
                               marca=?, um=?, costo_anterior=?, costo_actual=?, minimo_porcentaje=?, lista_porcentaje=?, updated_at=NOW()
                        WHERE id=? AND tienda_id=?
                    ')->execute([$nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $etiquetasJson,
                                  $marca, $um, $costoAnterior, $costoActual, $minimoPorcentaje, $listaPorcentaje, $productoId, TIENDA_ID]);
                }
            } else {
                $ins = $pdo->prepare('
                    INSERT INTO productos (tienda_id, nombre, codigo, descripcion, precio, stock, categoria_id, activo, imagen_path, etiquetas,
                                            marca, um, costo_anterior, costo_actual, minimo_porcentaje, lista_porcentaje)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                ');
                $ins->execute([TIENDA_ID, $nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $imagenPath ?? '', $etiquetasJson,
                               $marca, $um, $costoAnterior, $costoActual, $minimoPorcentaje, $listaPorcentaje]);
                $productoId = $ins->fetchColumn();
            }

            // Imágenes adicionales (hasta 4, más la principal = 5 en total).
            // Eliminar las marcadas por el usuario (llegan como JSON de ids).
            $eliminarImgs = json_decode($_POST['eliminar_imagenes'] ?? '[]', true);
            if (is_array($eliminarImgs) && $eliminarImgs) {
                $ph = implode(',', array_fill(0, count($eliminarImgs), '?'));
                $sel = $pdo->prepare("SELECT id, imagen_path FROM producto_imagenes WHERE producto_id = ? AND id IN ($ph)");
                $sel->execute(array_merge([$productoId], array_map('intval', $eliminarImgs)));
                foreach ($sel->fetchAll() as $img) {
                    if (file_exists(UPLOADS_PATH . '/productos/' . $img['imagen_path'])) {
                        @unlink(UPLOADS_PATH . '/productos/' . $img['imagen_path']);
                    }
                }
                $pdo->prepare("DELETE FROM producto_imagenes WHERE producto_id = ? AND id IN ($ph)")
                    ->execute(array_merge([$productoId], array_map('intval', $eliminarImgs)));
            }

            // Subir las nuevas (slots imagen_extra_1..4)
            $insExtra = $pdo->prepare('INSERT INTO producto_imagenes (producto_id, imagen_path, orden) VALUES (?, ?, ?)');
            $primeraExtraSubida = null;
            for ($i = 1; $i <= 4; $i++) {
                $campo = "imagen_extra_$i";
                if (empty($_FILES[$campo]['tmp_name'])) continue;
                $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) continue;
                $dir = UPLOADS_PATH . '/productos';
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $fname = 'prod_' . time() . '_' . rand(100, 999) . '_' . $i . '.' . $ext;
                if (move_uploaded_file($_FILES[$campo]['tmp_name'], $dir . '/' . $fname)) {
                    $insExtra->execute([$productoId, $fname, $i]);
                    if ($primeraExtraSubida === null) $primeraExtraSubida = $fname;
                }
            }

            // Si el producto todavía no tiene imagen principal, la primera imagen
            // adicional recién subida también se usa como principal, para que
            // aparezca en los listados sin tener que usar el campo "Imagen" aparte.
            if ($primeraExtraSubida !== null) {
                $tieneImagen = $pdo->prepare('SELECT imagen_path FROM productos WHERE id = ? AND tienda_id = ?');
                $tieneImagen->execute([$productoId, TIENDA_ID]);
                if (empty($tieneImagen->fetchColumn())) {
                    $pdo->prepare('UPDATE productos SET imagen_path = ? WHERE id = ? AND tienda_id = ?')
                        ->execute([$primeraExtraSubida, $productoId, TIENDA_ID]);
                }
            }

            echo json_encode(['success' => true, 'id' => $productoId]);
            break;

        // Imágenes adicionales de un producto (para precargar el modal de edición)
        case 'imagenes_producto':
            $productoId = intval($_GET['producto_id'] ?? 0);
            $img = $pdo->prepare('
                SELECT pi.id, pi.imagen_path FROM producto_imagenes pi
                JOIN productos p ON p.id = pi.producto_id
                WHERE pi.producto_id = ? AND p.tienda_id = ?
                ORDER BY pi.orden ASC, pi.id ASC
            ');
            $img->execute([$productoId, TIENDA_ID]);
            echo json_encode(['success' => true, 'data' => $img->fetchAll()]);
            break;

        case 'eliminar_producto':
            $body       = json_decode(file_get_contents('php://input'), true);
            $productoId = intval($body['id'] ?? 0);
            $row = $pdo->prepare('SELECT imagen_path FROM productos WHERE id = ? AND tienda_id = ?');
            $row->execute([$productoId, TIENDA_ID]);
            $imgPath = $row->fetchColumn();
            $extraImgs = $pdo->prepare('SELECT imagen_path FROM producto_imagenes WHERE producto_id = ?');
            $extraImgs->execute([$productoId]);
            $extraPaths = $extraImgs->fetchAll(PDO::FETCH_COLUMN);
            // producto_imagenes se borra sola por ON DELETE CASCADE
            $pdo->prepare('DELETE FROM productos WHERE id = ? AND tienda_id = ?')->execute([$productoId, TIENDA_ID]);
            if ($imgPath && file_exists(UPLOADS_PATH . '/productos/' . $imgPath)) {
                @unlink(UPLOADS_PATH . '/productos/' . $imgPath);
            }
            foreach ($extraPaths as $p) {
                if ($p && file_exists(UPLOADS_PATH . '/productos/' . $p)) @unlink(UPLOADS_PATH . '/productos/' . $p);
            }
            echo json_encode(['success' => true]);
            break;

        // Lista de etiquetas ya usadas en cualquier producto, para autocompletado
        // en el input de etiquetas (estilo sugerencias de hashtags).
        case 'etiquetas_sugeridas':
            $etStmt = $pdo->prepare("SELECT etiquetas FROM productos WHERE etiquetas IS NOT NULL AND etiquetas != '[]' AND tienda_id = ?");
            $etStmt->execute([TIENDA_ID]);
            $rows = $etStmt->fetchAll(PDO::FETCH_COLUMN);
            $vistas = [];
            foreach ($rows as $json) {
                $arr = json_decode($json, true);
                if (!is_array($arr)) continue;
                foreach ($arr as $t) {
                    $t = trim((string)$t);
                    if ($t === '' || isset($vistas[mb_strtolower($t)])) continue;
                    $vistas[mb_strtolower($t)] = $t;
                }
            }
            $lista = array_values($vistas);
            sort($lista, SORT_FLAG_CASE | SORT_STRING);
            echo json_encode(['success' => true, 'data' => $lista]);
            break;

        // ── CATEGORÍAS ───────────────────────────────────────
        case 'crear_categoria':
            $body   = json_decode(file_get_contents('php://input'), true);
            $nombre = trim($body['nombre'] ?? '');
            if (!$nombre) { echo json_encode(['success' => false, 'error' => 'El nombre es requerido']); break; }

            $dup = $pdo->prepare('SELECT id FROM categorias WHERE LOWER(nombre) = LOWER(?) AND tienda_id = ?');
            $dup->execute([$nombre, TIENDA_ID]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ya existe una categoría con ese nombre']); break;
            }

            $ins = $pdo->prepare('INSERT INTO categorias (tienda_id, nombre, activo) VALUES (?, ?, TRUE) RETURNING id');
            $ins->execute([TIENDA_ID, $nombre]);
            echo json_encode(['success' => true, 'id' => $ins->fetchColumn(), 'nombre' => $nombre]);
            break;

        // ── CLIENTES ─────────────────────────────────────────
        case 'toggle_cliente':
            $body   = json_decode(file_get_contents('php://input'), true);
            $id     = intval($body['id'] ?? 0);
            $activo = ($body['activo'] ?? true) ? 't' : 'f';
            $pdo->prepare('UPDATE clientes SET activo = ? WHERE id = ?')->execute([$activo, $id]);
            echo json_encode(['success' => true]);
            break;

        case 'detalle_cliente':
            $id = intval($_GET['id'] ?? 0);
            $c  = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
            $c->execute([$id]);
            $cliente = $c->fetch();
            $p       = $pdo->prepare('SELECT codigo, total, estado, created_at FROM pedidos WHERE cliente_id = ? ORDER BY created_at DESC LIMIT 20');
            $p->execute([$id]);
            echo json_encode(['success' => true, 'cliente' => $cliente, 'pedidos' => $p->fetchAll()]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
