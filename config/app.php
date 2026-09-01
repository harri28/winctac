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

// Aclara (percent > 0) u oscurece (percent < 0) un color hex, mezclándolo
// hacia blanco/negro — usado para derivar --primary-dark/-light/-bg del
// color de marca que cada tienda elige en Configuración.
function shadeColor(string $hex, float $percent): string {
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return '#dc2626';
    $rgb = [];
    for ($i = 0; $i < 3; $i++) {
        $c = hexdec(substr($hex, $i * 2, 2));
        $c = $percent >= 0 ? $c + (255 - $c) * $percent : $c + $c * $percent;
        $rgb[] = str_pad(dechex((int) round(max(0, min(255, $c)))), 2, '0', STR_PAD_LEFT);
    }
    return '#' . implode('', $rgb);
}

// Bloque <style> que sobreescribe el color de marca de style.css para esta
// tienda — se imprime después del <link> a style.css en cada <head>.
function brandColorStyleTag(array $cfg): string {
    $base = $cfg['color_primary'] ?? '#dc2626';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $base)) $base = '#dc2626';
    $dark  = shadeColor($base, -0.2);
    $light = shadeColor($base, 0.15);
    $bg    = shadeColor($base, 0.94);
    return "<style>:root{--primary:$base;--primary-dark:$dark;--primary-light:$light;--primary-bg:$bg;}</style>";
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
