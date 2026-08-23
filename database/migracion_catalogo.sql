-- Migración de catálogo (categorías, productos, envíos, config, banners)
-- Generado desde la base de datos local. No incluye admin_usuarios/clientes/pedidos
-- (esos se migran aparte, fuera del repo, por contener hashes de contraseña / datos de clientes).
BEGIN;

DELETE FROM banners;
DELETE FROM productos;
DELETE FROM categorias;
DELETE FROM empresas_envio;
DELETE FROM config;

-- === categorias ===
INSERT INTO public.categorias (id, nombre, activo, created_at) VALUES (3, 'Categoría Prueba', true, '2026-08-11 19:24:29.829179');
INSERT INTO public.categorias (id, nombre, activo, created_at) VALUES (4, 'Medicamentos', true, '2026-08-11 19:27:31.033735');
INSERT INTO public.categorias (id, nombre, activo, created_at) VALUES (5, 'Cuidado Personal', true, '2026-08-11 19:27:31.041227');
INSERT INTO public.categorias (id, nombre, activo, created_at) VALUES (7, 'Abarrotes', true, '2026-08-18 18:49:37.328683');
INSERT INTO public.categorias (id, nombre, activo, created_at) VALUES (8, 'Hogar', true, '2026-08-18 18:49:37.339567');

-- === empresas_envio ===
INSERT INTO public.empresas_envio (id, nombre, descripcion, precio, activo, created_at) VALUES (4, 'Media Empresa 2', 'Envío express', 25.00, true, '2026-05-26 09:55:11.877012');
INSERT INTO public.empresas_envio (id, nombre, descripcion, precio, activo, created_at) VALUES (1, 'Turismo Selva', '', 15.00, true, '2026-05-26 09:44:20.845903');
INSERT INTO public.empresas_envio (id, nombre, descripcion, precio, activo, created_at) VALUES (3, 'Turismo Cajarma', 'Envío estándar', 15.00, true, '2026-05-26 09:55:11.877012');
INSERT INTO public.empresas_envio (id, nombre, descripcion, precio, activo, created_at) VALUES (2, 'Turismo San Martín', 'Envío express', 12.00, true, '2026-05-26 09:44:20.845903');

-- === config ===
INSERT INTO public.config (id, nombre_tienda, descripcion, logo_path, whatsapp_numero, whatsapp_mensaje, yape_numero, yape_titular, yape_qr_path, plin_numero, plin_titular, plin_qr_path, banco_nombre, banco_cuenta, banco_cci, banco_titular, farmacia_api_url, farmacia_schema, modo_productos, updated_at, facturacion_activo, facturacion_proveedor, facturacion_api_url, facturacion_api_token, facturacion_ruc_emisor, anuncio_texto, anuncio_activo, billetera_qr_path, puntos_activo, puntos_por_sol) VALUES (1, 'WIN TAC', '', 'logo_1786814415.jpeg', '', '', '', '', '', '', '', '', '', '', '', '', 'http://localhost/farmacia/modules/ecommerce/api.php', 'generic_pharma_jr_alonso_de_alvarado', 'api', '2026-08-18 18:45:15.876788', false, '', '', '', '', 'Envio gratis en compras mayores a S/50', true, '', true, 1.00);

-- === productos ===
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (4, 'Ibuprofeno 400mg', 'MED-003', 'Ibuprofeno 400mg, caja x 20 tabletas.', 8.90, 50, 4, 'prod_seed_3_1786494451.png', true, '2026-08-11 19:27:31.078072', '2026-08-11 19:50:17.274336', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (5, 'Jabón Antibacterial', 'CP-001', 'Jabón líquido antibacterial, botella 250ml.', 6.50, 35, 5, 'prod_seed_4_1786494451.png', true, '2026-08-11 19:27:31.085228', '2026-08-11 19:50:17.274336', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (2, 'Metformina 850mg', 'MED-001', 'Metformina 850mg, caja x 30 tabletas.', 12.50, 40, 4, 'prod_seed_1_1786494451.png', true, '2026-08-11 19:27:31.048122', '2026-08-12 00:21:34.478693', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (3, 'Agua Oxigenada 10vol', 'MED-002', 'Agua oxigenada 10 volúmenes, frasco 120ml.', 3.20, 60, 4, 'prod_seed_2_1786494451.png', true, '2026-08-11 19:27:31.07199', '2026-08-12 12:41:36.93172', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (7, 'Arroz Extra 5kg', 'ABA-001', 'Arroz extra grano largo, bolsa de 5kg.', 22.90, 50, 7, '', true, '2026-08-18 18:49:37.344467', '2026-08-18 18:49:37.344467', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (8, 'Azúcar Rubia 1kg', 'ABA-002', 'Azúcar rubia doméstica, bolsa de 1kg.', 4.50, 80, 7, '', true, '2026-08-18 18:49:37.354556', '2026-08-18 18:49:37.354556', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (10, 'Fideos Spaghetti 500g', 'ABA-004', 'Fideos spaghetti de sémola, paquete de 500g.', 3.20, 100, 7, '', true, '2026-08-18 18:49:37.357554', '2026-08-18 18:49:37.357554', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (11, 'Atún en Aceite 170g', 'ABA-005', 'Conserva de atún en aceite vegetal, lata de 170g.', 5.50, 90, 7, '', true, '2026-08-18 18:49:37.359225', '2026-08-18 18:49:37.359225', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (12, 'Leche Evaporada 400g', 'ABA-006', 'Leche evaporada entera, tarro de 400g.', 4.20, 100, 7, '', true, '2026-08-18 18:49:37.360621', '2026-08-18 18:49:37.360621', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (13, 'Sal de Mesa 1kg', 'ABA-007', 'Sal de mesa yodada, bolsa de 1kg.', 2.10, 70, 7, '', true, '2026-08-18 18:49:37.362748', '2026-08-18 18:49:37.362748', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (14, 'Café Instantáneo 100g', 'ABA-008', 'Café soluble instantáneo, frasco de 100g.', 12.90, 40, 7, '', true, '2026-08-18 18:49:37.363817', '2026-08-18 18:49:37.363817', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (15, 'Harina Sin Preparar 1kg', 'ABA-009', 'Harina de trigo sin preparar, bolsa de 1kg.', 4.80, 60, 7, '', true, '2026-08-18 18:49:37.364856', '2026-08-18 18:49:37.364856', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (16, 'Menestra Frejol Canario 500g', 'ABA-010', 'Frejol canario seleccionado, bolsa de 500g.', 6.50, 45, 7, '', true, '2026-08-18 18:49:37.365993', '2026-08-18 18:49:37.365993', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (17, 'Detergente en Polvo 1kg', 'HOG-001', 'Detergente en polvo multiuso, bolsa de 1kg.', 15.90, 40, 8, '', true, '2026-08-18 18:49:37.367099', '2026-08-18 18:49:37.367099', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (18, 'Lejía 1L', 'HOG-002', 'Lejía desinfectante, botella de 1 litro.', 4.50, 60, 8, '', true, '2026-08-18 18:49:37.368451', '2026-08-18 18:49:37.368451', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (19, 'Papel Higiénico x4', 'HOG-003', 'Papel higiénico doble hoja, paquete de 4 rollos.', 9.90, 70, 8, '', true, '2026-08-18 18:49:37.369446', '2026-08-18 18:49:37.369446', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (20, 'Esponja de Cocina x3', 'HOG-004', 'Esponjas de cocina multiuso, paquete de 3 unidades.', 5.50, 90, 8, '', true, '2026-08-18 18:49:37.370183', '2026-08-18 18:49:37.370183', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (21, 'Bolsas de Basura x20', 'HOG-005', 'Bolsas de basura resistentes, paquete de 20 unidades.', 6.90, 55, 8, '', true, '2026-08-18 18:49:37.371033', '2026-08-18 18:49:37.371033', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (22, 'Jabón para Ropa en Barra', 'HOG-006', 'Jabón en barra para lavado de ropa a mano.', 3.20, 80, 8, '', true, '2026-08-18 18:49:37.37187', '2026-08-18 18:49:37.37187', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (23, 'Ambientador en Spray', 'HOG-007', 'Ambientador en spray para el hogar, aroma floral.', 11.90, 35, 8, '', true, '2026-08-18 18:49:37.373502', '2026-08-18 18:49:37.373502', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (24, 'Limpiavidrios 500ml', 'HOG-008', 'Limpiavidrios en gatillo, botella de 500ml.', 7.50, 45, 8, '', true, '2026-08-18 18:49:37.374299', '2026-08-18 18:49:37.374299', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (25, 'Foco LED 9W', 'HOG-009', 'Foco LED ahorrador de 9W, luz blanca.', 8.90, 50, 8, '', true, '2026-08-18 18:49:37.375664', '2026-08-18 18:49:37.375664', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (26, 'Pilas AA x4', 'HOG-010', 'Pilas alcalinas AA, paquete de 4 unidades.', 10.50, 60, 8, '', true, '2026-08-18 18:49:37.376473', '2026-08-18 18:49:37.376473', '[]');
INSERT INTO public.productos (id, nombre, codigo, descripcion, precio, stock, categoria_id, imagen_path, activo, created_at, updated_at, etiquetas) VALUES (9, 'Aceite Vegetal 1L', 'ABA-003', 'Aceite vegetal comestible, botella de 1 litro.', 9.90, 60, 7, '', true, '2026-08-18 18:49:37.356127', '2026-08-18 19:06:35.325937', '["Limpieza","Belleza","Salud"]');

-- === banners ===
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (6, NULL, '', '', '', 6, true, '2026-06-09 00:54:50.378074', NULL, NULL);
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (1, 'banner_1780985196_406.png', 'Metformina 850mg', '', '/ecomerce/producto.php?id=2', 1, true, '2026-06-09 00:48:14.698709', NULL, 2);
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (2, 'banner_1780984514_640.png', 'Agua Oxigenada 10vol', '', '/ecomerce/producto.php?id=3', 2, true, '2026-06-09 00:52:10.085892', NULL, 3);
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (3, 'banner_1780985211_166.png', 'Ibuprofeno 400mg', '', '/ecomerce/producto.php?id=4', 3, true, '2026-06-09 00:54:50.375459', NULL, 4);
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (4, 'banner_1780985228_811.png', 'Jabón Antibacterial', '', '/ecomerce/producto.php?id=5', 4, true, '2026-06-09 00:54:50.37628', NULL, 5);
INSERT INTO public.banners (id, imagen_path, titulo, subtitulo, enlace, orden, activo, created_at, imagen_url, producto_id) VALUES (5, NULL, '', '', '', 5, false, '2026-06-09 00:54:50.376815', NULL, NULL);

-- Excluir imágenes precargadas por producto (se subirán fotos reales luego)
UPDATE public.productos SET imagen_path = '';

-- Reiniciar secuencias
SELECT setval(pg_get_serial_sequence('categorias','id'), COALESCE((SELECT MAX(id) FROM categorias), 1));
SELECT setval(pg_get_serial_sequence('empresas_envio','id'), COALESCE((SELECT MAX(id) FROM empresas_envio), 1));
SELECT setval(pg_get_serial_sequence('productos','id'), COALESCE((SELECT MAX(id) FROM productos), 1));
SELECT setval(pg_get_serial_sequence('banners','id'), COALESCE((SELECT MAX(id) FROM banners), 1));

COMMIT;
