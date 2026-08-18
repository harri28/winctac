<?php
$pageTitle  = 'Mi Carrito';
$currentPage = 'carrito';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

// Cargar empresas de envío
$envios = getDB()->query("SELECT * FROM empresas_envio WHERE activo = TRUE ORDER BY precio ASC")->fetchAll();
?>

<div class="page-wrapper">
    <div class="container">
        <h2 class="section-title" style="margin-bottom:24px">
            <i class="fas fa-shopping-cart"></i> Mi Carrito
            <span style="font-size:.85rem;font-weight:400;color:var(--text-muted)" id="cart-count-text"></span>
        </h2>

        <div id="empty-cart" class="empty-state" style="display:none">
            <i class="fas fa-shopping-cart"></i>
            <h3>Tu carrito está vacío</h3>
            <p>Agrega productos para continuar</p>
            <a href="<?= BASE_URL ?>/" class="btn btn-primary" style="margin-top:16px">
                <i class="fas fa-arrow-left"></i> Ver productos
            </a>
        </div>

        <div id="cart-content" class="cart-layout" style="display:none">
            <!-- Items -->
            <div>
                <div class="cart-items" id="cart-items"></div>
                <div style="margin-top:12px">
                    <button onclick="Carrito.limpiar();renderCart()" class="btn btn-secondary" style="font-size:.82rem">
                        <i class="fas fa-trash"></i> Vaciar carrito
                    </button>
                </div>
            </div>

            <!-- Resumen -->
            <div class="summary-box">
                <h3><i class="fas fa-receipt"></i> Resumen del pedido</h3>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="subtotal-val">S/ 0.00</span>
                </div>

                <div style="margin:14px 0 6px">
                    <label class="form-label"><i class="fas fa-truck"></i> Empresa de envío</label>
                    <select class="shipping-select" id="envio-select" onchange="updateTotal()">
                        <option value="" data-precio="0">Sin envío / Recojo en tienda</option>
                        <?php foreach ($envios as $e): ?>
                        <option value="<?= $e['id'] ?>" data-precio="<?= $e['precio'] ?>" data-nombre="<?= htmlspecialchars($e['nombre']) ?>">
                            <?= htmlspecialchars($e['nombre']) ?> — S/ <?= number_format($e['precio'], 2) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="summary-row">
                    <span>Costo de envío</span>
                    <span id="envio-val">S/ 0.00</span>
                </div>

                <div class="summary-row total">
                    <span>Total</span>
                    <span id="total-val">S/ 0.00</span>
                </div>

                <a href="<?= BASE_URL ?>/checkout.php" id="checkout-btn" class="btn btn-primary btn-full btn-lg" style="margin-top:20px">
                    <i class="fas fa-lock"></i> Finalizar compra
                </a>

                <a href="<?= BASE_URL ?>/" class="btn btn-secondary btn-full" style="margin-top:10px;font-size:.85rem">
                    <i class="fas fa-arrow-left"></i> Seguir comprando
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function renderCart() {
    const items = Carrito.get();
    const empty = document.getElementById('empty-cart');
    const content = document.getElementById('cart-content');

    if (!items.length) {
        empty.style.display = 'block';
        content.style.display = 'none';
        return;
    }

    empty.style.display = 'none';
    content.style.display = 'grid';

    document.getElementById('cart-items').innerHTML = items.map(item => `
        <div class="cart-item">
            ${item.imagen
                ? `<img class="cart-item-img" src="${item.imagen}" alt="${item.nombre}" onerror="this.outerHTML='<div class=\'cart-item-img-placeholder\'><i class=\'fas fa-box\'></i></div>'">`
                : `<div class="cart-item-img-placeholder"><i class="fas fa-box"></i></div>`}
            <div class="cart-item-info">
                <div class="cart-item-name">${item.nombre}</div>
                <div class="cart-item-price">S/ ${item.precio.toFixed(2)} c/u</div>
            </div>
            <div class="qty-control">
                <button class="qty-btn" onclick="Carrito.setCantidad(${item.id}, ${item.cantidad - 1});renderCart()">−</button>
                <span class="qty-num">${item.cantidad}</span>
                <button class="qty-btn" onclick="Carrito.setCantidad(${item.id}, ${item.cantidad + 1});renderCart()">+</button>
            </div>
            <div class="cart-item-subtotal">S/ ${(item.precio * item.cantidad).toFixed(2)}</div>
            <button class="remove-btn" onclick="Carrito.quitar(${item.id});renderCart()" title="Eliminar">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `).join('');

    updateTotal();
}

function updateTotal() {
    const subtotal = Carrito.total();
    const sel = document.getElementById('envio-select');
    const envio = parseFloat(sel.options[sel.selectedIndex]?.dataset.precio || 0);
    const total = subtotal + envio;

    document.getElementById('subtotal-val').textContent = 'S/ ' + subtotal.toFixed(2);
    document.getElementById('envio-val').textContent = 'S/ ' + envio.toFixed(2);
    document.getElementById('total-val').textContent = 'S/ ' + total.toFixed(2);

    // Guardar selección de envío en sessionStorage para checkout
    const nombre = sel.options[sel.selectedIndex]?.dataset.nombre || '';
    sessionStorage.setItem('sd_envio', JSON.stringify({
        id: sel.value,
        nombre: nombre,
        precio: envio
    }));
}

renderCart();
document.addEventListener('carritoUpdated', renderCart);
</script>
