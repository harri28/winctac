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
            $pdo->prepare('UPDATE productos SET activo = ?, updated_at = NOW() WHERE id = ?')->execute([$activo, $productoId]);
            echo json_encode(['success' => true]);
            break;

        case 'toggle_todos':
            $body   = json_decode(file_get_contents('php://input'), true);
            $activo = ($body['activo'] ?? true) ? 't' : 'f';
            $pdo->prepare('UPDATE productos SET activo = ?, updated_at = NOW()')->execute([$activo]);
            echo json_encode(['success' => true]);
            break;

        case 'guardar_producto':
            $productoId  = intval($_POST['producto_id'] ?? 0);
            $nombre      = trim($_POST['nombre'] ?? '');
            $codigo      = trim($_POST['codigo'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precio      = floatval($_POST['precio'] ?? 0);
            $stock       = intval($_POST['stock'] ?? 0);
            $categoriaId = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
            $activo      = (isset($_POST['activo']) && $_POST['activo'] !== '0') ? 't' : 'f';

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
                    $prev = $pdo->prepare('SELECT imagen_path FROM productos WHERE id = ?');
                    $prev->execute([$productoId]);
                    $prevPath = $prev->fetchColumn();
                    if ($prevPath && file_exists(UPLOADS_PATH . '/productos/' . $prevPath)) {
                        @unlink(UPLOADS_PATH . '/productos/' . $prevPath);
                    }
                    $pdo->prepare('
                        UPDATE productos SET nombre=?, codigo=?, descripcion=?, precio=?, stock=?, categoria_id=?, activo=?, imagen_path=?, etiquetas=?, updated_at=NOW()
                        WHERE id=?
                    ')->execute([$nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $imagenPath, $etiquetasJson, $productoId]);
                } else {
                    $pdo->prepare('
                        UPDATE productos SET nombre=?, codigo=?, descripcion=?, precio=?, stock=?, categoria_id=?, activo=?, etiquetas=?, updated_at=NOW()
                        WHERE id=?
                    ')->execute([$nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $etiquetasJson, $productoId]);
                }
                echo json_encode(['success' => true, 'id' => $productoId]);
            } else {
                $ins = $pdo->prepare('
                    INSERT INTO productos (nombre, codigo, descripcion, precio, stock, categoria_id, activo, imagen_path, etiquetas)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                ');
                $ins->execute([$nombre, $codigo, $descripcion, $precio, $stock, $categoriaId, $activo, $imagenPath ?? '', $etiquetasJson]);
                echo json_encode(['success' => true, 'id' => $ins->fetchColumn()]);
            }
            break;

        case 'eliminar_producto':
            $body       = json_decode(file_get_contents('php://input'), true);
            $productoId = intval($body['id'] ?? 0);
            $row = $pdo->prepare('SELECT imagen_path FROM productos WHERE id = ?');
            $row->execute([$productoId]);
            $imgPath = $row->fetchColumn();
            $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$productoId]);
            if ($imgPath && file_exists(UPLOADS_PATH . '/productos/' . $imgPath)) {
                @unlink(UPLOADS_PATH . '/productos/' . $imgPath);
            }
            echo json_encode(['success' => true]);
            break;

        // Lista de etiquetas ya usadas en cualquier producto, para autocompletado
        // en el input de etiquetas (estilo sugerencias de hashtags).
        case 'etiquetas_sugeridas':
            $rows = $pdo->query("SELECT etiquetas FROM productos WHERE etiquetas IS NOT NULL AND etiquetas != '[]'")->fetchAll(PDO::FETCH_COLUMN);
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

            $dup = $pdo->prepare('SELECT id FROM categorias WHERE LOWER(nombre) = LOWER(?)');
            $dup->execute([$nombre]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ya existe una categoría con ese nombre']); break;
            }

            $ins = $pdo->prepare('INSERT INTO categorias (nombre, activo) VALUES (?, TRUE) RETURNING id');
            $ins->execute([$nombre]);
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
