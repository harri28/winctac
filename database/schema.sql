-- ============================================================
-- SELVADIGITAL ECOMMERCE — Schema PostgreSQL
-- Base de datos: selvadigital
-- ============================================================

-- Tiendas (multi-tenant): cada negocio alojado en esta instalación,
-- identificado por el hostname con el que llega la petición (dominio
-- propio o subdominio, ambos caben en la misma columna).
CREATE TABLE IF NOT EXISTS tiendas (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    hostname VARCHAR(255) UNIQUE NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Admin del sistema
CREATE TABLE IF NOT EXISTS admin_usuarios (
    id SERIAL PRIMARY KEY,
    tienda_id INTEGER NOT NULL REFERENCES tiendas(id),
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(tienda_id, email)
);

-- Clientes registrados
CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) DEFAULT '',
    dni VARCHAR(15) DEFAULT '',
    email VARCHAR(150) UNIQUE NOT NULL,
    celular VARCHAR(20) DEFAULT '',
    direccion TEXT DEFAULT '',
    distrito VARCHAR(100) DEFAULT '',
    ciudad VARCHAR(100) DEFAULT 'Lima',
    password_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    puntos_saldo INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Empresas de envío
CREATE TABLE IF NOT EXISTS empresas_envio (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT DEFAULT '',
    precio DECIMAL(10,2) NOT NULL DEFAULT 0,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Pedidos
CREATE TABLE IF NOT EXISTS pedidos (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE NOT NULL,
    cliente_id INTEGER REFERENCES clientes(id),
    empresa_envio_id INTEGER REFERENCES empresas_envio(id),
    empresa_envio_nombre VARCHAR(100) DEFAULT '',
    costo_envio DECIMAL(10,2) DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    metodo_pago VARCHAR(50) DEFAULT '',
    cliente_dni VARCHAR(15) DEFAULT '',
    estado VARCHAR(30) DEFAULT 'pendiente',
    direccion_entrega TEXT DEFAULT '',
    notas TEXT DEFAULT '',
    whatsapp_notificado BOOLEAN DEFAULT FALSE,
    -- Comprobante emitido por el proveedor de facturación
    comprobante_tipo VARCHAR(20) DEFAULT '',
    comprobante_numero VARCHAR(50) DEFAULT '',
    comprobante_url TEXT DEFAULT '',
    comprobante_estado VARCHAR(20) DEFAULT '',
    comprobante_error TEXT DEFAULT '',
    -- Programa de puntos
    puntos_ganados INTEGER DEFAULT 0,
    puntos_estado VARCHAR(20) DEFAULT '',
    cupon_codigo VARCHAR(30) DEFAULT '',
    cupon_descuento DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Detalle de pedidos (snapshot al momento de la compra)
CREATE TABLE IF NOT EXISTS pedido_detalles (
    id SERIAL PRIMARY KEY,
    pedido_id INTEGER REFERENCES pedidos(id) ON DELETE CASCADE,
    producto_id INTEGER NOT NULL,
    producto_nombre VARCHAR(200) NOT NULL,
    producto_codigo VARCHAR(50) DEFAULT '',
    precio_unitario DECIMAL(10,2) NOT NULL,
    cantidad INTEGER NOT NULL DEFAULT 1,
    subtotal DECIMAL(10,2) NOT NULL,
    imagen_url TEXT DEFAULT ''
);

-- Overrides de productos (imagen, visibilidad, precio personalizado, nombre)
-- Obsoleta desde que el catálogo es local (ver `productos` más abajo); se
-- deja para no perder datos históricos pero ya no la usa ningún código.
CREATE TABLE IF NOT EXISTS productos_override (
    producto_id INTEGER PRIMARY KEY,
    publicado BOOLEAN DEFAULT TRUE,
    imagen_path TEXT DEFAULT '',
    precio_override DECIMAL(10,2),
    nombre_override VARCHAR(200) DEFAULT '',
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Categorías locales del catálogo propio
CREATE TABLE IF NOT EXISTS categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) UNIQUE NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Catálogo propio de productos (independiente de sistemas externos)
CREATE TABLE IF NOT EXISTS productos (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    codigo VARCHAR(50) DEFAULT '',
    descripcion TEXT DEFAULT '',
    precio DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock INTEGER NOT NULL DEFAULT 0,
    categoria_id INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
    imagen_path TEXT DEFAULT '',
    activo BOOLEAN DEFAULT TRUE,
    -- Etiquetas de búsqueda (uso interno, no se muestran al cliente): array JSON de strings, ej. ["limpieza","cuidado del hogar"]
    etiquetas TEXT DEFAULT '[]',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Configuración general (una fila por tienda; el id ES el id de la tienda)
CREATE TABLE IF NOT EXISTS config (
    id INTEGER PRIMARY KEY REFERENCES tiendas(id),
    -- Tienda
    nombre_tienda VARCHAR(200) DEFAULT 'Mi Tienda Online',
    descripcion TEXT DEFAULT '',
    logo_path TEXT DEFAULT '',
    color_primary VARCHAR(7) DEFAULT '#dc2626',
    -- Franja de anuncio sobre el header
    anuncio_texto TEXT DEFAULT '',
    anuncio_activo BOOLEAN DEFAULT FALSE,
    -- WhatsApp
    whatsapp_numero VARCHAR(20) DEFAULT '',
    whatsapp_mensaje TEXT DEFAULT 'Hola! Realizé el pedido {codigo} por S/ {total}. Adjunto mi comprobante de pago.',
    -- Yape
    yape_numero VARCHAR(20) DEFAULT '',
    yape_titular VARCHAR(100) DEFAULT '',
    yape_qr_path TEXT DEFAULT '',
    -- Plin
    plin_numero VARCHAR(20) DEFAULT '',
    plin_titular VARCHAR(100) DEFAULT '',
    plin_qr_path TEXT DEFAULT '',
    -- Banco / Billetera digital
    banco_nombre VARCHAR(100) DEFAULT '',
    banco_cuenta VARCHAR(50) DEFAULT '',
    banco_cci VARCHAR(50) DEFAULT '',
    banco_titular VARCHAR(100) DEFAULT '',
    billetera_qr_path TEXT DEFAULT '',
    -- Conexión Farmacia API
    farmacia_api_url TEXT DEFAULT 'http://localhost/farmacia/modules/ecommerce/api.php',
    farmacia_schema VARCHAR(100) DEFAULT '',
    modo_productos VARCHAR(20) DEFAULT 'api',
    -- Facturación electrónica (proveedor conectable vía API)
    facturacion_activo BOOLEAN DEFAULT FALSE,
    facturacion_proveedor VARCHAR(100) DEFAULT '',
    facturacion_api_url TEXT DEFAULT '',
    facturacion_api_token TEXT DEFAULT '',
    facturacion_ruc_emisor VARCHAR(20) DEFAULT '',
    -- Programa de puntos
    puntos_activo BOOLEAN DEFAULT FALSE,
    puntos_por_sol DECIMAL(10,2) DEFAULT 1.00,
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Bloqueo temporal de login tras intentos fallidos (fuerza bruta)
CREATE TABLE IF NOT EXISTS login_intentos (
    id SERIAL PRIMARY KEY,
    clave VARCHAR(255) UNIQUE NOT NULL,
    intentos INTEGER NOT NULL DEFAULT 0,
    bloqueado_hasta TIMESTAMP DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Banners del slider de inicio
CREATE TABLE IF NOT EXISTS banners (
    id SERIAL PRIMARY KEY,
    imagen_path TEXT NOT NULL,
    titulo VARCHAR(200) DEFAULT '',
    subtitulo VARCHAR(300) DEFAULT '',
    enlace VARCHAR(500) DEFAULT '',
    orden INTEGER DEFAULT 0,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Programa de puntos: movimientos (ledger/auditoría de saldo por cliente)
CREATE TABLE IF NOT EXISTS puntos_movimientos (
    id SERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL REFERENCES clientes(id),
    tipo VARCHAR(20) NOT NULL, -- 'ganado' | 'canjeado' | 'revertido' | 'ajuste_admin'
    puntos INTEGER NOT NULL,   -- con signo
    pedido_id INTEGER REFERENCES pedidos(id),
    recompensa_id INTEGER,
    nota TEXT DEFAULT '',
    admin_id INTEGER REFERENCES admin_usuarios(id),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Programa de puntos: catálogo de recompensas canjeables (admin)
CREATE TABLE IF NOT EXISTS puntos_recompensas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    tipo VARCHAR(20) NOT NULL, -- 'monto' | 'porcentaje'
    valor DECIMAL(10,2) NOT NULL,
    costo_puntos INTEGER NOT NULL,
    compra_minima DECIMAL(10,2) DEFAULT 0,
    vigencia_dias INTEGER DEFAULT 0,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Programa de puntos: cupones generados al canjear una recompensa
-- (snapshot de la recompensa al momento del canje, igual que pedido_detalles)
CREATE TABLE IF NOT EXISTS puntos_cupones (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE NOT NULL,
    cliente_id INTEGER NOT NULL REFERENCES clientes(id),
    recompensa_id INTEGER,
    recompensa_nombre VARCHAR(150) NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    compra_minima DECIMAL(10,2) DEFAULT 0,
    puntos_gastados INTEGER NOT NULL,
    estado VARCHAR(20) DEFAULT 'activo', -- 'activo' | 'usado' | 'vencido'
    pedido_id INTEGER REFERENCES pedidos(id),
    expira_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    used_at TIMESTAMP
);

-- Datos por defecto

-- Tienda por defecto (instalación local / primer negocio)
INSERT INTO tiendas (slug, hostname) VALUES ('default', 'localhost') ON CONFLICT (hostname) DO NOTHING;

INSERT INTO config (id) VALUES ((SELECT id FROM tiendas WHERE hostname = 'localhost')) ON CONFLICT (id) DO NOTHING;

-- Admin por defecto: admin / admin123
INSERT INTO admin_usuarios (tienda_id, nombre, email, password_hash)
VALUES ((SELECT id FROM tiendas WHERE hostname = 'localhost'), 'Administrador', 'admin', '$2y$10$tCq8.q6YA.MXRTqCMmXsy.nxbAK7dGpLSKvRBWmn.LXgzd6rgY0iy')
ON CONFLICT (tienda_id, email) DO NOTHING;

-- Empresas de envío de ejemplo
INSERT INTO empresas_envio (nombre, descripcion, precio) VALUES
    ('Media Empresa 1', 'Envío estándar', 15.00),
    ('Media Empresa 2', 'Envío express', 25.00)
ON CONFLICT DO NOTHING;
