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
// Multi-tenant: cada tienda vive en su propio dominio en la raíz (sin
// subcarpeta), salvo el entorno local XAMPP que sigue sirviéndose bajo
// /ecomerce. No depende de una variable de entorno fija por proceso —
// eso rompería en un pool de PHP-FPM compartido entre varias tiendas
// (cada una necesita su propia URL base, resuelta de su propio Host).
$basePath = ($host === 'localhost') ? '/ecomerce' : '';
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

// ── Texto enriquecido simple (Portal Web: Visión/Misión/¿Quiénes somos?) ──
// El editor solo ofrece negrita/cursiva/alineación, así que el HTML que puede
// llegar está muy acotado — se sanea a una lista blanca de etiquetas y solo
// se conserva un style de alineación de texto; todo lo demás se descarta.
function sanitizarRichText(string $html): string {
    $html = strip_tags($html, '<b><strong><i><em><div><span><br><p>');
    $html = preg_replace_callback('/<(b|strong|i|em|div|span|p)\b([^>]*)>/i', function ($m) {
        $style = '';
        if (preg_match('/style\s*=\s*"([^"]*)"/i', $m[2], $sm)
            && preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $sm[1], $am)) {
            $style = ' style="text-align:' . strtolower($am[1]) . '"';
        }
        return '<' . strtolower($m[1]) . $style . '>';
    }, $html);
    // El HTML que produce el editor nunca trae saltos de línea crudos (usa
    // <div>/<br>) — si aparece alguno (p.ej. al pegar texto), se limpia aquí
    // para que renderRichText() pueda seguir usando "\n" como señal 100%
    // confiable de "esto es texto heredado, no pasó por el editor".
    return str_replace(["\r\n", "\r", "\n"], '', $html);
}

// Contenido guardado antes de este editor es texto plano con saltos de línea
// crudos ("\n") — sanitizarRichText() garantiza que lo nuevo jamás los deja,
// así que ese es el indicador confiable de "texto heredado" y no la sola
// ausencia de etiquetas (un texto nuevo sin saltos de línea tampoco tiene
// ninguna). Lo heredado se escapa y sus saltos de línea se convierten en
// <br> sin dejar el "\n" original (para que, si se vuelve a guardar sin
// cambios, ya se detecte como HTML y no se re-escape); lo nuevo ya viene
// saneado por sanitizarRichText() y se imprime tal cual.
function renderRichText(?string $val): string {
    $val = $val ?? '';
    if (strpos($val, "\n") === false) return $val;
    return str_replace(["\r\n", "\r", "\n"], '<br>', htmlspecialchars($val));
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
