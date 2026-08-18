# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Selvadigital Ecommerce is a PHP/PostgreSQL online store running on XAMPP (Apache + PostgreSQL). It is fully self-contained — no external system dependency for its catalog. It provides:
- Public-facing product catalog (own local products/categories) with category filtering and search
- Customer registration, login, and account management
- Shopping cart (localStorage-based) with checkout workflow
- Payment methods: Yape, Plin, Bank Transfer (manual confirmation, no payment gateway)
- Shipping company integration with per-company cost calculation
- Admin dashboard for orders, products, categories, customers, and store config

Historical note: this project previously synced its catalog from an external Pharmacy API (`config.farmacia_api_url`/`farmacia_schema`, `modo_productos`). That integration was removed — the store now owns its product/category data directly. `productos_override` is a leftover table from that era, unused by any current code.

**Tech Stack:** PHP 7+, PostgreSQL, vanilla JavaScript, HTML/CSS, Font Awesome 6.5

## Running Locally

Requires XAMPP with Apache and PostgreSQL. Serve from `http://localhost/ecomerce/`.

**First-time setup:**
1. Create PostgreSQL database `selvadigital` with user `postgres` / password `1234`
2. Visit `http://localhost/ecomerce/database/setup.php` to create schema and seed data
3. Visit `http://localhost/ecomerce/database/migrar.php` to apply any migrations

**Default credentials after setup:**
- Admin: email=`admin`, password=`admin123`
- Test customer: email=`usuario1`, password=`usuario123`

## Database

PostgreSQL database `selvadigital` on `localhost:5432` (credentials: `postgres` / `1234`).

**Tables:**
- `admin_usuarios` — Admin accounts
- `clientes` — Customer accounts
- `categorias` — Product categories (`id`, `nombre`, `activo`)
- `productos` — Product catalog (`nombre`, `codigo`, `descripcion`, `precio`, `stock`, `categoria_id`, `imagen_path`, `activo`). Source of truth for the whole store — `stock` is decremented on checkout inside the same transaction as the order insert.
- `pedidos` — Orders with status tracking (`pendiente` → `confirmado` → `enviado` → `entregado` / `cancelado`)
- `pedido_detalles` — Order line items (snapshot of product data at purchase time, immutable)
- `productos_override` — Unused leftover from the old external-catalog integration; no current code touches it
- `empresas_envio` — Shipping companies with fixed delivery costs
- `login_intentos` — Brute-force lockout tracking for both login forms (`clave` = IP + account identifier)
- `config` — Single-row store settings (branding, payment methods, WhatsApp, invoicing provider connection)

## Architecture

**Entry points:**
- `config/app.php` — Starts session, defines `BASE_URL`/`BASE_PATH`/`UPLOADS_PATH`, provides `formatMoney()`, `generarCodigoPedidoUnico()`, and `getShopConfig()` (statically cached)
- `config/database.php` — `getDB()` returns a statically cached PDO instance; `jsonResponse()` sends JSON and exits

**Public pages:** `index.php`, `carrito.php`, `checkout.php`, `confirmacion.php`

**Customer account:** `cuenta/login.php`, `cuenta/registro.php`, `cuenta/logout.php`, `cuenta/mis-pedidos.php`
- Auth helpers in `includes/auth_cliente.php`: `clienteLogueado()`, `clienteId()`, `clienteNombre()`, `loginCliente()`, `logoutCliente()`, `requireClienteAuth()`

**Admin:** `admin/` — `login.php`, `logout.php`, `index.php` (dashboard), `config.php`, `facturacion.php`, `productos.php`, `categorias.php`, `pedidos.php`, `clientes.php`, `banners.php`, `api.php`
- Admin sessions stored in `$_SESSION['admin_id']` and `$_SESSION['admin_nombre']`
- Login brute-force protection via `includes/rate_limit.php` (`loginBloqueado()`, `registrarIntentoFallido()`, `limpiarIntentos()`) — 5 failed attempts locks that IP+account for 15 minutes. Lock comparisons run in SQL (`NOW()`) rather than PHP `time()`, since PHP's timezone and PostgreSQL's server timezone are not guaranteed to match.

**Internal API:** `api/index.php` dispatches on `?action=`:
- `categorias` — Reads local `categorias` table (no auth required)
- `productos` — Reads local `productos` + `categorias` via `obtenerCatalogo()` (no auth required); this same function is reused by `crear_pedido` so pricing/stock validation always checks the identical source a customer sees
- `crear_pedido` — POST JSON; works for both logged-in customers and guests (guest name/celular/dni stored directly on `pedidos.cliente_*`); re-validates every cart item's price/stock against `obtenerCatalogo()` server-side (never trusts the browser), then decrements `productos.stock` inside the same transaction as the order insert (rolls back if another order already consumed the stock)
- `login_cliente` — POST JSON email/password
- `mis_pedidos` — GET customer's order history (requires auth)

**Client-side cart:** `assets/js/carrito.js` — `Carrito` object persists to `localStorage` under key `sd_carrito`. Per-item schema: `{id, nombre, precio, imagen, stock, cantidad}`. Dispatches `carritoUpdated` custom event on changes. `showToast()` is a global toast helper defined in the same file.

**Product management:** Fully local, managed from **Productos** and **Categorías** in the admin menu. `admin/api.php` actions `guardar_producto` (create/update, multipart for image upload), `eliminar_producto`, `toggle_producto`/`toggle_todos` (visibility). Deleting a category is blocked while any product still references it.

**Uploads:** Files (logos, QR codes, product images) stored in `uploads/` (product images specifically under `uploads/productos/`) and served at `UPLOADS_URL`.

**Invoicing (facturación electrónica):** `includes/facturacion.php` — `emitirComprobante(PDO $pdo, int $pedidoId)` posts the order (customer + line items + totals) as generic JSON to `config.facturacion_api_url` (Bearer token from `config.facturacion_api_token`) and expects `{success, comprobante_tipo, comprobante_numero, comprobante_url}` back. Any provider can be plugged in by pointing the URL/token at its API, as long as it speaks this contract (adjust the payload/response parsing in `_llamarApiFacturacion()` otherwise). Triggered automatically from `admin/api.php` (`cambiar_estado`) when an order moves to `confirmado` and hasn't already been invoiced (`pedidos.comprobante_estado != 'emitido'`). Result is stored on the order (`comprobante_tipo/numero/url/estado/error`) — never blocks the status change if invoicing fails. Configured from the admin menu under **Sistema → Facturación** (`admin/facturacion.php`).

## Key Patterns

- All DB queries use PDO prepared statements via `getDB()`
- `pedido_detalles` stores a snapshot at purchase time — never join back to live product data for order display
- `config` table has exactly one row (`id=1`); `getShopConfig()` caches it statically per request
- Admin and customer auth are entirely separate sessions; no shared session keys
- WhatsApp link uses template variables `{codigo}` and `{total}` from `config.whatsapp_mensaje`
