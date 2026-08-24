<?php
// ============================================================
// MIGRACIÓN — Ejecutar en: http://localhost/ecomerce/database/migrar.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Migración</title>
<style>
body{font-family:system-ui,sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f8fafc}
h1{color:#dc2626}
.ok{background:#dcfce7;border-left:4px solid #16a34a;padding:10px 14px;margin:8px 0;border-radius:4px}
.err{background:#fee2e2;border-left:4px solid #dc2626;padding:10px 14px;margin:8px 0;border-radius:4px}
.skip{background:#f1f5f9;border-left:4px solid #94a3b8;padding:10px 14px;margin:8px 0;border-radius:4px}
a{color:#dc2626}
</style></head><body>
<h1>Migraciones — Selvadigital</h1>';

$pdo = getDB();
$migraciones = [
    'banners_table' => "CREATE TABLE IF NOT EXISTS banners (
        id SERIAL PRIMARY KEY,
        imagen_path TEXT NOT NULL,
        titulo VARCHAR(200) DEFAULT '',
        subtitulo VARCHAR(300) DEFAULT '',
        enlace VARCHAR(500) DEFAULT '',
        orden INTEGER DEFAULT 0,
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    'pedidos_cliente_nombre' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS cliente_nombre VARCHAR(150) DEFAULT ''",
    'pedidos_cliente_celular' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS cliente_celular VARCHAR(20) DEFAULT ''",
    'pedidos_tipo_entrega' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS tipo_entrega VARCHAR(20) DEFAULT 'delivery'",
    'banners_imagen_url'   => "ALTER TABLE banners ADD COLUMN IF NOT EXISTS imagen_url TEXT DEFAULT ''",
    'banners_producto_id'  => "ALTER TABLE banners ADD COLUMN IF NOT EXISTS producto_id INTEGER DEFAULT NULL",
    'banners_imagen_path_nullable' => "ALTER TABLE banners ALTER COLUMN imagen_path DROP NOT NULL",
    'config_facturacion_activo'      => "ALTER TABLE config ADD COLUMN IF NOT EXISTS facturacion_activo BOOLEAN DEFAULT FALSE",
    'config_facturacion_proveedor'   => "ALTER TABLE config ADD COLUMN IF NOT EXISTS facturacion_proveedor VARCHAR(100) DEFAULT ''",
    'config_facturacion_api_url'     => "ALTER TABLE config ADD COLUMN IF NOT EXISTS facturacion_api_url TEXT DEFAULT ''",
    'config_facturacion_api_token'   => "ALTER TABLE config ADD COLUMN IF NOT EXISTS facturacion_api_token TEXT DEFAULT ''",
    'config_facturacion_ruc_emisor'  => "ALTER TABLE config ADD COLUMN IF NOT EXISTS facturacion_ruc_emisor VARCHAR(20) DEFAULT ''",
    'pedidos_comprobante_tipo'       => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comprobante_tipo VARCHAR(20) DEFAULT ''",
    'pedidos_comprobante_numero'     => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comprobante_numero VARCHAR(50) DEFAULT ''",
    'pedidos_comprobante_url'        => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comprobante_url TEXT DEFAULT ''",
    'pedidos_comprobante_estado'     => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comprobante_estado VARCHAR(20) DEFAULT ''",
    'pedidos_comprobante_error'      => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comprobante_error TEXT DEFAULT ''",
    'pedidos_cliente_dni' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS cliente_dni VARCHAR(15) DEFAULT ''",
    'config_billetera_qr_path' => "ALTER TABLE config ADD COLUMN IF NOT EXISTS billetera_qr_path TEXT DEFAULT ''",
    'config_anuncio_texto'  => "ALTER TABLE config ADD COLUMN IF NOT EXISTS anuncio_texto TEXT DEFAULT ''",
    'config_anuncio_activo' => "ALTER TABLE config ADD COLUMN IF NOT EXISTS anuncio_activo BOOLEAN DEFAULT FALSE",
    'categorias_table' => "CREATE TABLE IF NOT EXISTS categorias (
        id SERIAL PRIMARY KEY,
        nombre VARCHAR(150) UNIQUE NOT NULL,
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    'productos_table' => "CREATE TABLE IF NOT EXISTS productos (
        id SERIAL PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        codigo VARCHAR(50) DEFAULT '',
        descripcion TEXT DEFAULT '',
        precio DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock INTEGER NOT NULL DEFAULT 0,
        categoria_id INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
        imagen_path TEXT DEFAULT '',
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW()
    )",
    'login_intentos_table' => "CREATE TABLE IF NOT EXISTS login_intentos (
        id SERIAL PRIMARY KEY,
        clave VARCHAR(255) UNIQUE NOT NULL,
        intentos INTEGER NOT NULL DEFAULT 0,
        bloqueado_hasta TIMESTAMP DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT NOW()
    )",

    // ── Programa de puntos ──
    'config_puntos_activo'   => "ALTER TABLE config ADD COLUMN IF NOT EXISTS puntos_activo BOOLEAN DEFAULT FALSE",
    'config_puntos_por_sol'  => "ALTER TABLE config ADD COLUMN IF NOT EXISTS puntos_por_sol DECIMAL(10,2) DEFAULT 1.00",
    'clientes_puntos_saldo'  => "ALTER TABLE clientes ADD COLUMN IF NOT EXISTS puntos_saldo INTEGER NOT NULL DEFAULT 0",
    'pedidos_puntos_ganados' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS puntos_ganados INTEGER DEFAULT 0",
    'pedidos_puntos_estado'  => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS puntos_estado VARCHAR(20) DEFAULT ''",
    'pedidos_cupon_codigo'   => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS cupon_codigo VARCHAR(30) DEFAULT ''",
    'pedidos_cupon_descuento' => "ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS cupon_descuento DECIMAL(10,2) DEFAULT 0",
    'puntos_movimientos_table' => "CREATE TABLE IF NOT EXISTS puntos_movimientos (
        id SERIAL PRIMARY KEY,
        cliente_id INTEGER NOT NULL REFERENCES clientes(id),
        tipo VARCHAR(20) NOT NULL,
        puntos INTEGER NOT NULL,
        pedido_id INTEGER REFERENCES pedidos(id),
        recompensa_id INTEGER,
        nota TEXT DEFAULT '',
        admin_id INTEGER REFERENCES admin_usuarios(id),
        created_at TIMESTAMP DEFAULT NOW()
    )",
    'puntos_recompensas_table' => "CREATE TABLE IF NOT EXISTS puntos_recompensas (
        id SERIAL PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        valor DECIMAL(10,2) NOT NULL,
        costo_puntos INTEGER NOT NULL,
        compra_minima DECIMAL(10,2) DEFAULT 0,
        vigencia_dias INTEGER DEFAULT 0,
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    'puntos_cupones_table' => "CREATE TABLE IF NOT EXISTS puntos_cupones (
        id SERIAL PRIMARY KEY,
        codigo VARCHAR(30) UNIQUE NOT NULL,
        cliente_id INTEGER NOT NULL REFERENCES clientes(id),
        recompensa_id INTEGER,
        recompensa_nombre VARCHAR(150) NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        valor DECIMAL(10,2) NOT NULL,
        compra_minima DECIMAL(10,2) DEFAULT 0,
        puntos_gastados INTEGER NOT NULL,
        estado VARCHAR(20) DEFAULT 'activo',
        pedido_id INTEGER REFERENCES pedidos(id),
        expira_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW(),
        used_at TIMESTAMP
    )",

    // ── Etiquetas de búsqueda en productos ──
    'productos_etiquetas' => "ALTER TABLE productos ADD COLUMN IF NOT EXISTS etiquetas TEXT DEFAULT '[]'",

    // ── Datos de contacto (footer: botón "Contáctanos") ──
    'config_contacto_email'   => "ALTER TABLE config ADD COLUMN IF NOT EXISTS contacto_email VARCHAR(150) DEFAULT ''",
    'config_contacto_celular' => "ALTER TABLE config ADD COLUMN IF NOT EXISTS contacto_celular VARCHAR(20) DEFAULT ''",
];

foreach ($migraciones as $nombre => $sql) {
    try {
        $pdo->exec($sql);
        echo "<div class=\"ok\">✓ <strong>$nombre</strong> aplicada correctamente</div>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            echo "<div class=\"skip\">— <strong>$nombre</strong> ya existe, omitida</div>";
        } else {
            echo "<div class=\"err\">✗ <strong>$nombre</strong>: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

echo '<p><a href="' . BASE_URL . '/">→ Ir a la tienda</a> &nbsp;|&nbsp; <a href="' . BASE_URL . '/admin/">→ Panel admin</a></p>';
echo '</body></html>';
