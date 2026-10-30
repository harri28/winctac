<?php
// ============================================================
// API DE INTEGRACIÓN ERP — para el ERP de la tienda o su conector
//
// Autenticación: cabecera "Authorization: Bearer <config.erp_api_token>"
// (se genera en Admin → Productos → Integración ERP; vacío = API apagada).
//
//   GET  ?action=ping       → verifica token y conexión
//   POST ?action=productos  → {"productos":[{codigo, nombre?, marca?, um?,
//                              costo?, stock?, minimo_porcentaje?,
//                              lista_porcentaje?, precio?}], "crear_nuevos": false}
//   POST ?action=compras    → {"compras":[{dni, comprobante, fecha, monto}]}
//                              compras de la tienda física → puntos
//
// Documentación completa: docs/integracion-erp.md
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/erp_sync.php';

header('Content-Type: application/json; charset=utf-8');

function erpApiRespuesta(int $codigo, array $data): void {
    http_response_code($codigo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();

// ── Autenticación por token de la tienda ──
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('apache_request_headers')) {
    $h = array_change_key_case(apache_request_headers(), CASE_LOWER);
    $auth = $h['authorization'] ?? '';
}
$tokenRecibido = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : '';

$tokenStmt = $pdo->prepare('SELECT erp_api_token FROM config WHERE id = ?');
$tokenStmt->execute([TIENDA_ID]);
$tokenTienda = (string) $tokenStmt->fetchColumn();

if ($tokenTienda === '') {
    erpApiRespuesta(503, ['success' => false, 'error' => 'La API de integración está desactivada para esta tienda.']);
}
if ($tokenRecibido === '' || !hash_equals($tokenTienda, $tokenRecibido)) {
    erpApiRespuesta(401, ['success' => false, 'error' => 'Token inválido o ausente.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'ping') {
    erpApiRespuesta(200, ['success' => true, 'tienda_id' => TIENDA_ID, 'hora_servidor' => date('c')]);
}

if ($action === 'productos') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erpApiRespuesta(405, ['success' => false, 'error' => 'Usa POST.']);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['productos']) || !is_array($body['productos'])) {
        erpApiRespuesta(400, ['success' => false, 'error' => 'El cuerpo debe ser JSON con un arreglo "productos".']);
    }
    if (count($body['productos']) > 20000) {
        erpApiRespuesta(413, ['success' => false, 'error' => 'Máximo 20 000 productos por petición.']);
    }

    // Solo se aceptan los campos conocidos de cada producto
    $filas = [];
    foreach ($body['productos'] as $p) {
        if (!is_array($p)) { $filas[] = []; continue; }
        $filas[] = array_intersect_key($p, array_flip(ERP_CAMPOS));
    }

    try {
        $simular = !empty($body['simular']);
        $res = erpSincronizarProductos($pdo, $filas, 'api', 'API ' . ($_SERVER['REMOTE_ADDR'] ?? ''), !empty($body['crear_nuevos']), $simular);
    } catch (Throwable $e) {
        erpApiRespuesta(500, ['success' => false, 'error' => 'Error al sincronizar: ' . $e->getMessage()]);
    }

    erpApiRespuesta(200, [
        'success'        => true,
        'simulado'       => $simular,
        'total'          => $res['total'],
        'actualizados'   => $res['actualizados'],
        'creados'        => $res['creados'],
        'sin_cambios'    => $res['sin_cambios'],
        'no_encontrados' => $res['no_encontrados'],
        'errores'        => $res['errores'],
    ]);
}

// Compras de la tienda física (comprobantes del ERP) que acumulan puntos.
// Cada compra es independiente: un comprobante repetido o con datos
// inválidos se reporta en "resultados" sin afectar a los demás.
if ($action === 'compras') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erpApiRespuesta(405, ['success' => false, 'error' => 'Usa POST.']);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['compras']) || !is_array($body['compras'])) {
        erpApiRespuesta(400, ['success' => false, 'error' => 'El cuerpo debe ser JSON con un arreglo "compras".']);
    }
    if (count($body['compras']) > 5000) {
        erpApiRespuesta(413, ['success' => false, 'error' => 'Máximo 5 000 compras por petición.']);
    }

    require_once __DIR__ . '/../includes/puntos.php';
    $resultados = [];
    $resumen = ['otorgados' => 0, 'pendientes' => 0, 'errores' => 0, 'puntos' => 0];
    foreach ($body['compras'] as $c) {
        $c = is_array($c) ? $c : [];
        $r = registrarCompraTienda($pdo, $c, 'api');
        $item = ['comprobante' => (string) ($c['comprobante'] ?? '')];
        if (!$r['ok']) {
            $resumen['errores']++;
            $item += ['estado' => 'error', 'error' => $r['error']];
        } else {
            $resumen[$r['estado'] === 'otorgado' ? 'otorgados' : 'pendientes']++;
            $resumen['puntos'] += $r['puntos'];
            $item += ['estado' => $r['estado'], 'puntos' => $r['puntos']];
        }
        $resultados[] = $item;
    }
    erpApiRespuesta(200, ['success' => true, 'total' => count($resultados)] + $resumen + ['resultados' => $resultados]);
}

erpApiRespuesta(400, ['success' => false, 'error' => 'Acción no válida. Usa ?action=ping, ?action=productos o ?action=compras.']);
