<?php
// ============================================================
// CONFIGURACIÓN GLOBAL DE LA APLICACIÓN
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$scheme = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
} elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443') {
    $scheme = 'https';
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
            require_once __DIR__ . '/database.php';
            $cfg = getDB()->query('SELECT * FROM config WHERE id = 1')->fetch() ?: [];
        } catch (Exception $e) {
            $cfg = [];
        }
    }
    return $cfg;
}
