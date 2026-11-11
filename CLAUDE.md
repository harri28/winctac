# CLAUDE.md

Este archivo guía a Claude Code (claude.ai/code) cuando trabaja con el código de este repositorio.

## Resumen del proyecto

Selvadigital Ecommerce es una tienda online PHP/PostgreSQL que corre sobre XAMPP (Apache + PostgreSQL). Es totalmente autocontenida — ningún sistema externo depende de ella para su catálogo. Un mismo código base atiende varias tiendas (multi-tenant: una instalación, varias marcas/dominios). Ofrece:
- Catálogo público (productos/categorías propios) con filtro por categoría y búsqueda
- Registro, login y gestión de cuenta de cliente
- Carrito (basado en localStorage) con flujo de checkout
- Métodos de pago: Yape, Plin, transferencia bancaria (confirmación manual, sin pasarela)
- Empresas de envío con costo fijo por empresa
- Panel admin para pedidos, productos, categorías, clientes, config, programa de puntos, marketing (campañas/promociones), postulantes y más
- Programa de fidelización: puntos ganados por pedido confirmado, canjeables por cupones personales
- Códigos de descuento públicos (campañas) separados de los cupones personales de puntos

Nota histórica: antes el proyecto sincronizaba su catálogo con una API externa de farmacia (`config.farmacia_api_url`/`farmacia_schema`, `modo_productos`). Esa integración se eliminó — la tienda ahora dueña directa de sus datos de productos/categorías. `productos_override` es una tabla heredada de esa época, sin uso por ningún código actual.

**Stack:** PHP 7+, PostgreSQL, JavaScript vanilla, HTML/CSS, Font Awesome 6.5

## Correr localmente

Requiere XAMPP con Apache y PostgreSQL. Se sirve desde `http://localhost/ecomerce/`.

**Instalación inicial:**
1. Crear la base PostgreSQL `selvadigital` con usuario `postgres` / password `1234` (configurables vía `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS`)
2. Abrir `http://localhost/ecomerce/database/setup.php` para crear el esquema y datos de ejemplo
3. Abrir `http://localhost/ecomerce/database/migrar.php` para aplicar migraciones

**Credenciales por defecto tras el setup:**
- Admin: email=`admin`, password=`admin123`
- Cliente de prueba: email=`usuario1`, password=`usuario123`

## Arquitectura multi-tenant

Una misma instalación puede servir varias tiendas desde el mismo código y base de datos. Una tienda = una fila en `tiendas` llaveada por `hostname` (dominio completo, sin puerto).

**Constante `TIENDA_ID`** — se resuelve en `config/app.php` en cada request buscando `tiendas.hostname = HTTP_HOST`. Si el hostname no está registrado, el request responde 404 (falla cerrado — nunca cae a la tienda 1 por accidente), salvo cuando la tabla `tiendas` aún no existe (bootstrap previo a migrar), donde cae a 1 para que las pantallas de setup carguen.

**Reglas al escribir queries/código:**
- Toda tabla con alcance por tienda tiene columna `tienda_id`: `productos`, `categorias`, `admin_usuarios`, `promociones`, `banners`, `campanas`, `postulaciones` y `config` (cuyo `id` ES el `tienda_id`). Todo SELECT/INSERT/UPDATE sobre ellas DEBE filtrar/poblar por `TIENDA_ID` — el admin lo revalida (`requireAdmin()` en `admin/includes/header.php` reconfirma que el admin logueado pertenece a `TIENDA_ID`).
- `clientes` NO tiene alcance por tienda (los clientes se comparten entre tiendas, identificados por email único global). Saldos/movimientos/cupones/recompensas de fidelidad cuelgan del cliente y también son globales.
- `pedidos` no tiene `tienda_id` directo — se implica a través de sus líneas/productos (y en la UI de admin se filtra por la tienda actual).
- `BASE_URL` se deriva del host del request: en `localhost` apunta a `/ecomerce`; en cualquier dominio real apunta a la raíz. `ECOMMERCE_BASE_URL` sobrescribe.

## Base de datos

PostgreSQL `selvadigital` en `localhost:5432` (credenciales por defecto: `postgres` / `1234`).

**Tablas principales:**
- `tiendas` — Registro multi-tenant (`slug`, `hostname`, `activo`). El id de la fila determina `TIENDA_ID`.
- `admin_usuarios` — Cuentas admin (único por `(tienda_id, email)`)
- `clientes` — Cuentas de cliente (globales; `email` es único global). Guarda `puntos_saldo` para fidelización.
- `categorias` — Categorías por tienda (`UNIQUE(tienda_id, nombre)`)
- `productos` — Catálogo por tienda. Fuente de verdad de toda la tienda; `stock` se descuenta al checkout dentro de la misma transacción que inserta el pedido. Campos de costeo interno (uso admin, nunca visibles al público): `costo_actual`, `costo_anterior`, `lista_porcentaje`, `minimo_porcentaje` — el `precio` público se recalcula desde `costo_actual × lista_porcentaje` en cada guardado. `etiquetas` es un array JSON de etiquetas internas de búsqueda (nunca se muestran al cliente, pero viajan en el catálogo para que el buscador público las revise). También carga `marca`, `um`, `codigo_barras` (EAN, columna "Catálogo" del listado) y `precio_minimo` (`costo_actual × (1 + minimo_porcentaje)`). La carga masiva desde el Excel "Listado de precios" está en `admin/importar-productos.php` (mismo motor que `includes/erp_sync.php`; crea los códigos nuevos visibles).
- `producto_imagenes` — Hasta 4 imágenes extras por producto (5 totales con `productos.imagen_path`); el admin valida ese tope.
- `producto_ficha_tecnica` — Filas libres nombre/valor para la ficha técnica (sin plantilla fija).
- `pedidos` — Pedidos con estado (`pendiente` → `confirmado` → `enviado` → `entregado` / `cancelado`). También guarda los campos de facturación (`comprobante_*`), descuento aplicado (`cupon_codigo`, `cupon_descuento`) y puntos (`puntos_ganados`, `puntos_estado`).
- `pedido_detalles` — Líneas del pedido (snapshot del producto al momento de la compra, inmutable)
- `productos_override` — Herencia inútil de la integración externa antigua; ningún código actual la toca
- `empresas_envio` — Empresas de envío con costo fijo
- `login_intentos` — Bloqueo por fuerza bruta para ambos logins (`clave` = IP + identificador de cuenta)
- `config` — Configuración por tienda (`id = tienda_id`): branding, métodos de pago, WhatsApp, facturación, toggles de puntos (`puntos_activo`, `puntos_por_sol`), visibilidad de stock (`mostrar_stock`)
- `postulaciones` — Postulaciones de "Trabaja con nosotros" (CV subidos) por tienda

**Tablas de marketing / fidelización:**
- `campanas` — Códigos de descuento **públicos** con ventana de fechas y tope de usos opcional. **No requieren login** ni cliente — cualquiera con el código lo usa en checkout. `UNIQUE(tienda_id, codigo)`.
- `promociones` + `promocion_productos` — Agrupan productos existentes para destacarlos en Inicio. No cambian el precio, solo visibilidad/orden.
- `puntos_recompensas` — Catálogo de recompensas definido por admin (`costo_puntos`, `tipo` monto|porcentaje, `valor`, `compra_minima`, `vigencia_dias`)
- `puntos_cupones` — Cupones personales generados cuando el cliente canjea puntos por una recompensa. **Requieren login** para usarse. Snapshot de la recompensa al momento del canje, mismo patrón que `pedido_detalles` snapshotea el producto.
- `puntos_movimientos` — Ledger append-only de todo cambio de saldo (`ganado` | `canjeado` | `revertido` | `ajuste_admin`)
- `banners` — Slider del Inicio por tienda (imagen subida o referencia a un producto)

## Arquitectura

**Puntos de entrada:**
- `config/app.php` — Arranca sesión, resuelve hostname → `TIENDA_ID` (404 si no coincide), define `BASE_URL`/`BASE_PATH`/`UPLOADS_PATH`/`UPLOADS_URL`, expone `formatMoney()`, `generarCodigoPedidoUnico()`, `getShopConfig()` (cacheada estáticamente por request), `sanitizarRichText()`/`renderRichText()` (saneo whitelist de HTML para Portal Web), `shadeColor()`/`brandColorStyleTag()` (deriva variantes claro/oscuro/bg del color de marca)
- `config/database.php` — `getDB()` devuelve una instancia PDO cacheada; `jsonResponse()` emite JSON y hace exit

**Páginas públicas:** `index.php` (portada corporativa), `catalogo.php`, `producto.php`, `carrito.php`, `checkout.php`, `confirmacion.php`, `nosotros.php`, `trabaja-con-nosotros.php`, `contacto.php` (guarda en `mensajes_contacto`, se gestiona en `admin/mensajes.php`), `sitemap.php`/`robots.php` (servidos como `/sitemap.xml`/`/robots.txt`)

**Integración ERP:** `includes/erp_sync.php` sincroniza productos (código → costo/stock/precio) desde el ERP (Fox Smart Business). La usan `admin/erp.php` (importar el Listado de precios .xlsx/.csv con vista previa) y `api/erp.php` (API REST con token `config.erp_api_token`). Las compras de la tienda física acumulan puntos vía `registrarCompraTienda()` (`includes/puntos.php`, tabla `compras_tienda`, identifica al cliente por DNI y no repite comprobantes), desde `admin/compras-tienda.php` o `api/erp.php?action=compras`. Detalle y reglas en `docs/integracion-erp.md`.

**URLs amigables:** los enlaces públicos van sin `.php` (`BASE_URL . '/nosotros'`) y los productos con `urlProducto($id, $nombre)` (PHP, `config/app.php`) o `urlProducto(id, nombre)` (JS, `assets/js/carrito.js`) → `/producto/{id}/{slug}`. Las reescrituras y los 301 desde las URLs antiguas `.php` viven en `.htaccess` (Apache local) y `deploy/nginx-wintac.conf` (producción) — mantener ambos sincronizados. Admin y `api/` siguen usando `.php`.

**Cuenta del cliente:** `cuenta/login.php`, `cuenta/registro.php`, `cuenta/logout.php`, `cuenta/mis-pedidos.php`, `cuenta/puntos.php`
- Helpers de auth en `includes/auth_cliente.php`: `clienteLogueado()`, `clienteId()`, `clienteNombre()`, `loginCliente()`, `logoutCliente()`, `requireClienteAuth()`

**Admin:** `admin/` — agrupado en el sidebar como Ventas (pedidos), Productos (productos/categorías), Clientes, Fidelización (puntos, recompensas, cupones, niveles), Marketing (campanas, promociones), Postulantes, Reportes, Configuración (config, facturacion, banners, portal-web). Archivos de entrada: `login.php`, `logout.php`, `index.php` (dashboard), `api.php` (todas las acciones JSON del admin), más un PHP por cada entrada del sidebar.
- La sesión admin vive en `$_SESSION['admin_id']` y `$_SESSION['admin_nombre']`
- `requireAdmin()` en `admin/includes/header.php` no solo verifica que haya sesión: también vuelve a consultar `admin_usuarios` para confirmar que el admin pertenece a `TIENDA_ID` actual — una cookie residual desde el dominio de otra tienda se cierra sesión.
- Bloqueo por fuerza bruta vía `includes/rate_limit.php` (`loginBloqueado()`, `registrarIntentoFallido()`, `limpiarIntentos()`) — 5 intentos fallidos bloquean esa IP+cuenta por 15 minutos. Las comparaciones corren en SQL (`NOW()`), no en PHP `time()`, porque la zona horaria de PHP y la de PostgreSQL no se garantizan iguales.

**API interna:** `api/index.php` despacha según `?action=`:
- `categorias` — Lee `categorias` de la tienda (sin auth)
- `productos` — Paginado y filtrado **server-side** vía `obtenerCatalogoPaginado()`: acepta `?page`, `?per_page` (máx 200, default 48), `?categoria` (id), `?q` (busca en `nombre` + `codigo` + `etiquetas` con `ILIKE`), `?sort` (`nombre` | `nuevos` | `precio_asc` | `precio_desc`). Devuelve `{data, total, page, per_page, total_pages}`. Índices que lo soportan: `productos_tienda_activo_nombre_idx`, `productos_tienda_categoria_idx`, `productos_tienda_created_at_idx`. `crear_pedido` sigue usando la función vieja `obtenerCatalogo()` (sin paginar) porque necesita el map id→precio/stock completo para revalidar el carrito.
- `promociones` — Productos destacados en `promociones` activas para la fila de Inicio
- `crear_pedido` — POST JSON; funciona tanto para clientes logueados como invitados (nombre/celular/dni del invitado se guardan directo en `pedidos.cliente_*`); revalida precio/stock de cada ítem contra `obtenerCatalogo()` en el servidor (nunca confía en el navegador), descuenta `productos.stock` dentro de la misma transacción que inserta el pedido (rollback si otro pedido ya consumió el stock). Aplica un `cupon_codigo` opcional que se resuelve **primero como campaña pública** y, si no, como **cupón personal de puntos** (requiere login). Ambos caminos revalidan el descuento en servidor, suben contadores de uso dentro de la transacción y solo persisten el descuento si todo commitea.
- `validar_cupon` — Endpoint de previsualización en checkout: mismo orden de resolución (campaña primero, luego cupón personal). Refleja la aritmética de descuento de `crear_pedido`.
- `canjear_recompensa` — POST; solo cliente logueado. Gasta puntos, escribe un `puntos_movimientos` y genera un `puntos_cupones` personal con código `PTS-XXXXXX`.
- `mis_puntos` — Payload del dashboard del cliente (saldo + catálogo de recompensas + sus cupones + ledger).
- `login_cliente` — POST JSON email/password
- `mis_pedidos` — GET historial de pedidos del cliente (requiere auth)

**Carrito en el cliente:** `assets/js/carrito.js` — El objeto `Carrito` persiste en `localStorage` bajo la clave `sd_carrito`. Esquema por ítem: `{id, nombre, precio, imagen, stock, cantidad}`. Dispara el evento `carritoUpdated` en cambios. `showToast()` es un helper global de toast definido en el mismo archivo.

**Gestión de productos:** Totalmente local, desde **Productos** y **Categorías** del menú admin. `admin/api.php` tiene acciones `guardar_producto` (crear/actualizar, multipart para imagen), `eliminar_producto`, `toggle_producto`/`toggle_todos` (visibilidad). Eliminar una categoría se bloquea mientras algún producto la referencie. Los productos cargan galería de imágenes (`producto_imagenes`, hasta 5 totales) y ficha técnica libre (`producto_ficha_tecnica`); los campos de costeo (`costo_actual`, `lista_porcentaje`) recalculan `precio` en cada guardado.

**Uploads:** Archivos (logos, QR, imágenes de productos, CVs) se guardan en `uploads/` (imágenes de productos en `uploads/productos/`, CVs en `uploads/cv/`) y se sirven desde `UPLOADS_URL`.

**Facturación electrónica:** `includes/facturacion.php` — `emitirComprobante(PDO $pdo, int $pedidoId)` postea el pedido (cliente + líneas + totales) como JSON genérico a `config.facturacion_api_url` (token Bearer desde `config.facturacion_api_token`) y espera `{success, comprobante_tipo, comprobante_numero, comprobante_url}` de vuelta. Cualquier proveedor se puede conectar apuntando URL/token a su API, siempre que hable este contrato (ajustar el payload/parseo en `_llamarApiFacturacion()` si no). Se dispara automático desde `admin/api.php` (`cambiar_estado`) cuando un pedido pasa a `confirmado` y aún no está facturado (`pedidos.comprobante_estado != 'emitido'`). El resultado se guarda en el pedido (`comprobante_tipo/numero/url/estado/error`) — nunca bloquea el cambio de estado si la facturación falla. Se configura en el menú admin bajo **Configuración → Facturación** (`admin/facturacion.php`).

**Motor de fidelización:** `includes/puntos.php` — `otorgarPuntosPedido()` y `revertirPuntosPedido()`, invocados desde `admin/api.php` (`cambiar_estado`) cuando un pedido pasa a `confirmado`/`cancelado`. Ambos son idempotentes vía `pedidos.puntos_estado` (`otorgado`|`revertido`). Puntos ganados = `floor(pedidos.total × config.puntos_por_sol)`, solo si `config.puntos_activo = TRUE` y el pedido tiene `cliente_id`. Cada cambio escribe una fila en `puntos_movimientos`; el saldo autoritativo vive en `clientes.puntos_saldo`.

## Patrones clave

- Todas las queries usan prepared statements PDO vía `getDB()`
- Toda query sobre tabla con alcance por tienda DEBE filtrar por `TIENDA_ID`; todo INSERT DEBE poblarlo
- `pedido_detalles` guarda un snapshot al momento de la compra — nunca se hace join hacia la data viva del producto para mostrar el pedido (mismo patrón para `puntos_cupones` snapshoteando la recompensa)
- `config` tiene una fila por tienda (`id = TIENDA_ID`); `getShopConfig()` la cachea estáticamente por request
- La auth de admin y la de cliente son sesiones totalmente separadas; no comparten claves de sesión
- La sesión de admin se revalida contra `TIENDA_ID` en cada carga — nunca confiar en que un `admin_id` existente pertenezca a la tienda actual
- Checkout y aplicación de descuentos siempre se revalidan en servidor contra catálogo + tablas de campañas/cupones; los precios y el stock del navegador son no confiables
- Los puntos de fidelización y los contadores de uso de campañas/cupones se mutan dentro de la misma transacción que el pedido relacionado, para que un pedido en rollback nunca deje contadores inflados ni filas espurias en el ledger
- El enlace de WhatsApp usa las variables `{codigo}` y `{total}` desde `config.whatsapp_mensaje`
