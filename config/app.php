<?php
// ============================================================
// CONFIGURACIÓN GLOBAL DE LA APLICACIÓN
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

$scheme = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
} elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443') {
    $scheme = 'https';
}
$host = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$host = preg_replace('/:\d+$/', '', $host); // quita el puerto, si viene (ej. localhost:8080)
if (in_array($host, ['127.0.0.1', '::1'], true)) {
    $host = 'localhost';
}
$basePath = '/ecomerce';
$baseUrl = getenv('ECOMMERCE_BASE_URL');
if ($baseUrl === false) {
    $baseUrl = $scheme . '://' . $host . $basePath;
}

define('BASE_URL', $baseUrl);
define('BASE_PATH', realpath(__DIR__ . '/..'));
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('UPLOADS_URL', BASE_URL . '/uploads');

// ── Multi-tenant: resuelve a qué tienda pertenece este hostname ──
// Si la tabla `tiendas` todavía no existe (antes de correr database/migrar.php
// en esta instalación), cae a la tienda 1 sin romper el sitio; una vez migrado,
// un hostname no registrado en `tiendas` falla cerrado (404) en vez de asumir
// la tienda 1, para no filtrar el storefront de un tenant real por error de DNS.
try {
    $tiendaStmt = getDB()->prepare('SELECT id FROM tiendas WHERE hostname = ? AND activo = TRUE');
    $tiendaStmt->execute([$host]);
    $tiendaId = $tiendaStmt->fetchColumn();
    if ($tiendaId === false) {
        http_response_code(404);
        exit('Tienda no encontrada.');
    }
    define('TIENDA_ID', (int) $tiendaId);
} catch (PDOException $e) {
    define('TIENDA_ID', 1);
}

// Helpers de formato
function formatMoney(float $amount): string {
    return 'S/ ' . number_format($amount, 2);
}

function generarCodigoPedido(): string {
    return 'SD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generarCodigoPedidoUnico(PDO $pdo): string {
    do {
        $codigo = 'SD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $exists = $pdo->prepare('SELECT 1 FROM pedidos WHERE codigo = ?');
        $exists->execute([$codigo]);
    } while ($exists->fetch());
    return $codigo;
}

// Config de la tienda cacheada
function getShopConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        try {
            $stmt = getDB()->prepare('SELECT * FROM config WHERE id = ?');
            $stmt->execute([TIENDA_ID]);
            $cfg = $stmt->fetch() ?: [];
        } catch (Exception $e) {
            $cfg = [];
        }
    }
    return $cfg;
}
