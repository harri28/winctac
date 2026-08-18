<?php
$pageTitle  = 'Finalizar Compra';
$currentPage = 'checkout';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth_cliente.php';
require_once __DIR__ . '/config/database.php';

$pdo = getDB();
$cliente = null;
if (clienteLogueado()) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $stmt->execute([clienteId()]);
    $cliente = $stmt->fetch();
}
$cfg      = getShopConfig();
$envios   = $pdo->query("SELECT * FROM empresas_envio WHERE activo = TRUE ORDER BY precio ASC")->fetchAll();
$logueado = clienteLogueado();
?>

<style>
.modo-card {
    border: 2px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px 24px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    background: #fff;
}
.modo-card:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.modo-icon {
    width: 56px; height: 56px;
    background: var(--primary-bg);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 1.4rem;
    color: var(--primary);
}
.modo-card h3 { font-size: 1rem; font-weight: 700; margin-bottom: 8px; }
.modo-card p  { font-size: .83rem; color: var(--text-muted); line-height: 1.5; }

.entrega-option {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.entrega-option input[type=radio] { margin-top: 3px; accent-color: var(--primary); flex-shrink: 0; }
.entrega-option.selected { border-color: var(--primary); background: var(--primary-bg); }

.section-step {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08rem;
    color: var(--primary);
    background: var(--primary-bg);
    border-radius: 20px;
    padding: 2px 10px;
    margin-right: 8px;
}
</style>

<div class="page-wrapper">
<div class="container">

<h2 class="section-title" style="margin-bottom:28px">
    <i class="fas fa-lock"></i> Finalizar Compra
</h2>

<?php if (!$logueado): ?>
<!-- ── PASO 1: Elegir modo (solo para no logueados) ── -->
<div id="step-modo">
    <p style="text-align:center;color:var(--text-muted);margin-bottom:28px;font-size:.95rem">
        ¿Cómo deseas continuar con tu pedido?
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:580px;margin:0 auto 32px">

        <div class="modo-card" onclick="elegirModo('invitado')">
            <div class="modo-icon"><i class="fas fa-bolt"></i></div>
            <h3>Continuar sin cuenta</h3>
            <p>Ingresa tus datos y realiza tu pedido rápidamente sin registro</p>
            <button class="btn btn-primary" style="margin-top:18px;width:100%">
                Continuar <i class="fas fa-arrow-right"></i>
            </button>
        </div>

        <div class="modo-card" onclick="elegirModo('registro')">
            <div class="modo-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-user-plus"></i></div>
            <h3>Crear mi cuenta</h3>
            <p>Guarda tus datos para agilizar futuras compras y seguir tus pedidos</p>
            <button class="btn btn-secondary" style="margin-top:18px;width:100%">
                Registrarme <i class="fas fa-arrow-right"></i>
            </button>
        </div>

    </div>
    <p style="text-align:center;font-size:.85rem;color:var(--text-muted)">
        ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/cuenta/login.php?redirect=<?= urlencode(BASE_URL . '/checkout.php') ?>">Inicia sesión aquí</a>
    </p>
</div>
<?php endif; ?>

<!-- ── PASO 2: Formulario ── -->
<div id="step-form" style="<?= $logueado ? '' : 'display:none' ?>">
<div class="checkout-layout">

    <!-- ── Columna izquierda: formulario ── -->
    <div>

        <!-- 1. Datos personales -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-title">
                <span class="section-step">1</span>
                <i class="fas fa-user"></i> Tus datos
                <?php if ($logueado): ?>
                <span style="font-size:.75rem;color:var(--text-muted);font-weight:400;margin-left:8px">
                    Cuenta: <?= htmlspecialchars(clienteNombre()) ?>
                    — <a href="<?= BASE_URL ?>/cuenta/logout.php" style="color:var(--danger)">Cerrar sesión</a>
                </span>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div class="form-group">
                    <label class="form-label">Nombre <span style="color:var(--danger)">*</span></label>
                    <input class="form-control" id="f-nombre"
                           value="<?= htmlspecialchars($cliente['nombre'] ?? '') ?>" placeholder="Tu nombre">
                </div>
                <div class="form-group">
                    <label class="form-label">Apellidos</label>
                    <input class="form-control" id="f-apellidos"
                           value="<?= htmlspecialchars($cliente['apellidos'] ?? '') ?>" placeholder="Tus apellidos">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <div class="form-group">
                    <label class="form-label">Celular <span style="color:var(--danger)">*</span></label>
                    <input class="form-control" id="f-celular" type="tel"
                           value="<?= htmlspecialchars($cliente['celular'] ?? '') ?>" placeholder="999 999 999">
                </div>
                <div class="form-group">
                    <label class="form-label">DNI <span style="color:var(--text-muted);font-size:.75rem">(opcional)</span></label>
                    <input class="form-control" id="f-dni" maxlength="15"
                           value="<?= htmlspecialchars($cliente['dni'] ?? '') ?>" placeholder="12345678">
                </div>
            </div>

            <!-- Campos extra solo para registro (ocultos hasta elegir modo) -->
            <div id="campos-registro" style="display:none">
                <div class="form-group">
                    <label class="form-label">Correo electrónico <span style="color:var(--danger)">*</span></label>
                    <input class="form-control" id="f-email" type="email" placeholder="correo@ejemplo.com">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Contraseña <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" id="f-password" type="password" placeholder="Mín. 6 caracteres">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Confirmar contraseña <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" id="f-password2" type="password" placeholder="Repite la contraseña">
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Tipo de entrega + dirección -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-title">
                <span class="section-step">2</span>
                <i class="fas fa-truck"></i> Tipo de entrega
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px">
                <label class="entrega-option" id="opt-recojo">
                    <input type="radio" name="tipo_entrega" value="recojo" onchange="toggleEntrega(this)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">
                            <i class="fas fa-store" style="color:var(--primary)"></i> Recojo en tienda
                        </div>
                        <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px">
                            Retira tu pedido en nuestro local sin costo de envío
                        </div>
                    </div>
                </label>
                <label class="entrega-option selected" id="opt-delivery">
                    <input type="radio" name="tipo_entrega" value="delivery" checked onchange="toggleEntrega(this)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">
                            <i class="fas fa-motorcycle" style="color:var(--primary)"></i> Delivery a domicilio
                        </div>
                        <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px">
                            Recibe tu pedido en la puerta de tu casa
                        </div>
                    </div>
                </label>
            </div>

            <!-- Dirección (solo visible en delivery) -->
            <div id="campos-direccion">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group">
                        <label class="form-label">Región <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" id="f-region" placeholder="Ej: San Martín">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Provincia <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" id="f-provincia" placeholder="Ej: San Martín">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Distrito <span style="color:var(--danger)">*</span></label>
                    <input class="form-control" id="f-distrito"
                           value="<?= htmlspecialchars($cliente['distrito'] ?? '') ?>" placeholder="Ej: Tarapoto">
                </div>
                <div class="form-group">
                    <label class="form-label">Jirón / Av. / Dirección <span style="color:var(--danger)">*</span></label>
                    <input class="form-control" id="f-jiron"
                           value="<?= htmlspecialchars($cliente['direccion'] ?? '') ?>" placeholder="Ej: Jr. Comercio 123">
                </div>
                <div class="form-group" style="margin-bottom:<?= $envios ? '14px' : '0' ?>">
                    <label class="form-label">
                        Referencia <span style="color:var(--text-muted);font-size:.75rem">(opcional)</span>
                    </label>
                    <input class="form-control" id="f-referencia"
                           placeholder="Ej: Frente al mercado central, casa de color azul">
                </div>

                <?php if ($envios): ?>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label"><i class="fas fa-building"></i> Empresa de envío</label>
                    <select class="form-control" id="f-envio" onchange="actualizarResumen()">
                        <option value="" data-precio="0" data-nombre="">Sin empresa / coordinar por WhatsApp</option>
                        <?php foreach ($envios as $e): ?>
                        <option value="<?= $e['id'] ?>"
                                data-precio="<?= $e['precio'] ?>"
                                data-nombre="<?= htmlspecialchars($e['nombre']) ?>">
                            <?= htmlspecialchars($e['nombre']) ?> — S/ <?= number_format($e['precio'], 2) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. Método de pago -->
        <div class="card">
            <div class="card-title">
                <span class="section-step">3</span>
                <i class="fas fa-credit-card"></i> Método de pago
            </div>
            <div class="payment-options">
                <label class="payment-option" id="opt-billetera">
                    <input type="radio" name="metodo_pago" value="billetera" checked>
                    <div>
                        <div class="payment-option-name">
                            <i class="fas fa-wallet" style="color:#7c3aed"></i> Billetera digital
                        </div>
                        <div class="payment-option-desc">
                            <?php if (!empty($cfg['billetera_qr_path'])): ?>
                            Escanea el QR para pagar (Yape, Plin u otra billetera)
                            <?php else: ?>
                            Yape, Plin u otra billetera con QR
                            <?php endif; ?>
                        </div>
                    </div>
                </label>

                <label class="payment-option" id="opt-pos">
                    <input type="radio" name="metodo_pago" value="pos">
                    <div>
                        <div class="payment-option-name">
                            <i class="fas fa-credit-card" style="color:#0e7490"></i> POS
                        </div>
                        <div class="payment-option-desc">Pago con tarjeta al recibir o recoger tu pedido</div>
                    </div>
                </label>

                <label class="payment-option" id="opt-efectivo" style="display:none">
                    <input type="radio" name="metodo_pago" value="efectivo">
                    <div>
                        <div class="payment-option-name">
                            <i class="fas fa-money-bill-wave" style="color:#16a34a"></i> Efectivo
                        </div>
                        <div class="payment-option-desc">Pagas en el local al recoger tu pedido</div>
                    </div>
                </label>
            </div>

            <div class="form-group" style="margin-top:16px;margin-bottom:0">
                <label class="form-label">Notas para tu pedido <span style="color:var(--text-muted);font-size:.75rem">(opcional)</span></label>
                <textarea class="form-control" id="f-notas" rows="2" placeholder="Ej: dejar en recepción, tocar el timbre 2 veces..."></textarea>
            </div>
        </div>

    </div><!-- /columna izquierda -->

    <!-- ── Columna derecha: resumen ── -->
    <div class="summary-box">
        <h3><i class="fas fa-receipt"></i> Tu pedido</h3>

        <div id="order-items" style="margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:14px">
            <div style="text-align:center;padding:12px;color:var(--text-muted);font-size:.85rem">
                <div class="spinner" style="margin:0 auto 8px"></div>Cargando...
            </div>
        </div>

        <div class="summary-row">
            <span>Subtotal</span>
            <span id="sum-subtotal">—</span>
        </div>
        <div class="summary-row" id="row-envio" style="display:none">
            <span id="sum-envio-label">Envío</span>
            <span id="sum-envio">S/ 0.00</span>
        </div>
        <div class="summary-row" id="row-cupon" style="display:none;color:var(--success,#16a34a)">
            <span id="cupon-label">Cupón</span>
            <span id="sum-cupon">- S/ 0.00</span>
        </div>
        <div class="summary-row total">
            <span>Total</span>
            <span id="sum-total">—</span>
        </div>

        <?php if ($logueado): ?>
        <div class="form-group" style="margin-top:14px;margin-bottom:0">
            <label class="form-label" style="font-size:.8rem">¿Tienes un cupón?</label>
            <div style="display:flex;gap:8px">
                <input class="form-control" id="f-cupon" placeholder="Código del cupón" style="text-transform:uppercase">
                <button type="button" class="btn btn-secondary" onclick="aplicarCupon()" id="btn-cupon" style="white-space:nowrap">Aplicar</button>
            </div>
            <div id="cupon-msg" style="font-size:.78rem;margin-top:6px"></div>
        </div>
        <?php endif; ?>

        <div id="error-msg" class="alert alert-error" style="display:none;margin-top:14px"></div>

        <button class="btn btn-primary btn-full btn-lg" style="margin-top:20px"
                onclick="confirmarPedido()" id="btn-confirmar">
            <i class="fas fa-check"></i> Confirmar pedido
        </button>

        <a href="<?= BASE_URL ?>/carrito.php" class="btn btn-secondary btn-full" style="margin-top:10px;font-size:.85rem">
            <i class="fas fa-arrow-left"></i> Volver al carrito
        </a>

        <?php if (!$logueado): ?>
        <button onclick="volverModo()" class="btn btn-secondary btn-full"
                style="margin-top:8px;font-size:.82rem;color:var(--text-muted)">
            <i class="fas fa-exchange-alt"></i> Cambiar opción
        </button>
        <?php endif; ?>
    </div>

</div><!-- /checkout-layout -->
</div><!-- /step-form -->

</div><!-- /container -->
</div><!-- /page-wrapper -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
// ── Estado global ──
let modoActual = <?= $logueado ? "'logueado'" : 'null' ?>;
let cuponAplicado = null; // { codigo, descuento, nombre }

// ── Paso 1: elegir modo ──
function elegirModo(modo) {
    modoActual = modo;
    document.getElementById('step-modo').style.display = 'none';
    document.getElementById('step-form').style.display  = '';
    document.getElementById('campos-registro').style.display = (modo === 'registro') ? '' : 'none';
    renderResumen();
    restaurarEnvio();
}

// Recupera la empresa de envío elegida en carrito.php (sessionStorage)
function restaurarEnvio() {
    const guardado = sessionStorage.getItem('sd_envio');
    if (!guardado) return;
    try {
        const { id } = JSON.parse(guardado);
        const sel = document.getElementById('f-envio');
        if (sel && id) {
            sel.value = id;
            actualizarResumen();
        }
    } catch (e) {}
}

function volverModo() {
    document.getElementById('step-modo').style.display = '';
    document.getElementById('step-form').style.display  = 'none';
}

// ── Paso 2: formulario ──

// Inicializar estado de payment-options
document.querySelectorAll('.payment-option').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
    });
});
document.querySelector('.payment-option')?.classList.add('selected');

// Tipo de entrega
function toggleEntrega(radio) {
    document.querySelectorAll('.entrega-option').forEach(el => el.classList.remove('selected'));
    radio.closest('.entrega-option').classList.add('selected');
    const esDelivery = radio.value === 'delivery';
    document.getElementById('campos-direccion').style.display = esDelivery ? '' : 'none';

    // "Efectivo" solo aplica cuando el cliente recoge en tienda
    const optEfectivo = document.getElementById('opt-efectivo');
    optEfectivo.style.display = esDelivery ? 'none' : '';
    if (esDelivery && optEfectivo.querySelector('input').checked) {
        optEfectivo.classList.remove('selected');
        const optBilletera = document.getElementById('opt-billetera');
        optBilletera.querySelector('input').checked = true;
        optBilletera.classList.add('selected');
    }

    actualizarResumen();
}

// Resumen del pedido
function actualizarResumen() {
    const subtotal = Carrito.total();
    const tipo     = document.querySelector('input[name=tipo_entrega]:checked')?.value || 'delivery';
    let envio = 0, envioNombre = '';

    if (tipo === 'delivery') {
        const sel = document.getElementById('f-envio');
        if (sel && sel.value) {
            envio      = parseFloat(sel.options[sel.selectedIndex].dataset.precio || 0);
            envioNombre = sel.options[sel.selectedIndex].dataset.nombre || '';
        }
        const rowEnvio = document.getElementById('row-envio');
        rowEnvio.style.display = '';
        document.getElementById('sum-envio-label').textContent = envioNombre ? `Envío (${envioNombre})` : 'Envío';
        document.getElementById('sum-envio').textContent = 'S/ ' + envio.toFixed(2);
    } else {
        document.getElementById('row-envio').style.display = 'none';
    }

    document.getElementById('sum-subtotal').textContent = 'S/ ' + subtotal.toFixed(2);

    const rowCupon = document.getElementById('row-cupon');
    let descuento = 0;
    if (cuponAplicado) {
        descuento = Math.min(cuponAplicado.descuento, subtotal + envio);
        rowCupon.style.display = '';
        document.getElementById('cupon-label').textContent = 'Cupón (' + cuponAplicado.nombre + ')';
        document.getElementById('sum-cupon').textContent = '- S/ ' + descuento.toFixed(2);
    } else {
        rowCupon.style.display = 'none';
    }

    document.getElementById('sum-total').textContent = 'S/ ' + (subtotal + envio - descuento).toFixed(2);
}

// ── Cupón de puntos ──
async function aplicarCupon() {
    const input = document.getElementById('f-cupon');
    const msg   = document.getElementById('cupon-msg');
    const codigo = input.value.trim().toUpperCase();
    if (!codigo) return;

    const btn = document.getElementById('btn-cupon');
    btn.disabled = true;
    msg.style.color = '';
    msg.textContent = 'Validando...';

    const tipo = document.querySelector('input[name=tipo_entrega]:checked')?.value || 'delivery';
    let costoEnvio = 0;
    if (tipo === 'delivery') {
        const sel = document.getElementById('f-envio');
        if (sel && sel.value) costoEnvio = parseFloat(sel.options[sel.selectedIndex].dataset.precio || 0);
    }

    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=validar_cupon', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                codigo,
                subtotal: Carrito.total(),
                costo_envio: costoEnvio
            })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Cupón no válido');

        cuponAplicado = { codigo, descuento: data.descuento, nombre: data.nombre };
        msg.style.color = 'var(--success,#16a34a)';
        msg.textContent = '¡Cupón aplicado!';
        actualizarResumen();
    } catch (e) {
        cuponAplicado = null;
        msg.style.color = 'var(--danger)';
        msg.textContent = e.message;
        actualizarResumen();
    } finally {
        btn.disabled = false;
    }
}

function renderResumen() {
    const items = Carrito.get();
    if (!items.length) { window.location = window.BASE_URL + '/carrito.php'; return; }

    document.getElementById('order-items').innerHTML = items.map(i =>
        `<div class="summary-row">
            <span style="font-size:.85rem">${i.nombre} <span style="color:var(--text-muted)">×${i.cantidad}</span></span>
            <span style="font-size:.85rem;font-weight:600">S/ ${(i.precio * i.cantidad).toFixed(2)}</span>
        </div>`
    ).join('');

    actualizarResumen();
}

<?php if ($logueado): ?>
renderResumen();
restaurarEnvio();
<?php endif; ?>

// ── Confirmar pedido ──
async function confirmarPedido() {
    const items = Carrito.get();
    if (!items.length) { window.location = window.BASE_URL + '/carrito.php'; return; }

    const errDiv  = document.getElementById('error-msg');
    errDiv.style.display = 'none';

    const nombre    = document.getElementById('f-nombre').value.trim();
    const apellidos = document.getElementById('f-apellidos').value.trim();
    const celular   = document.getElementById('f-celular').value.trim();
    const dni       = document.getElementById('f-dni').value.trim();
    const tipo      = document.querySelector('input[name=tipo_entrega]:checked')?.value || 'delivery';
    const metodo    = document.querySelector('input[name=metodo_pago]:checked')?.value || '';

    // Validaciones básicas
    if (!nombre) { mostrarError('El nombre es requerido.'); return; }
    if (!celular) { mostrarError('El celular es requerido.'); return; }
    if (!metodo)  { mostrarError('Selecciona un método de pago.'); return; }

    // Validaciones de registro
    let crearCuenta = null;
    if (modoActual === 'registro') {
        const email     = document.getElementById('f-email').value.trim();
        const password  = document.getElementById('f-password').value;
        const password2 = document.getElementById('f-password2').value;
        if (!email)              { mostrarError('El correo electrónico es requerido.'); return; }
        if (password.length < 6) { mostrarError('La contraseña debe tener al menos 6 caracteres.'); return; }
        if (password !== password2) { mostrarError('Las contraseñas no coinciden.'); return; }
        crearCuenta = { email, password, nombre, apellidos, celular, dni };
    }

    // Dirección (solo delivery)
    let region = '', provincia = '', distrito = '', jiron = '', referencia = '';
    let empresaId = null, empresaNombre = '', costoEnvio = 0;

    if (tipo === 'delivery') {
        region     = document.getElementById('f-region').value.trim();
        provincia  = document.getElementById('f-provincia').value.trim();
        distrito   = document.getElementById('f-distrito').value.trim();
        jiron      = document.getElementById('f-jiron').value.trim();
        referencia = document.getElementById('f-referencia').value.trim();

        if (!region)   { mostrarError('La región es requerida.'); return; }
        if (!provincia){ mostrarError('La provincia es requerida.'); return; }
        if (!distrito) { mostrarError('El distrito es requerido.'); return; }
        if (!jiron)    { mostrarError('La dirección (Jirón/Av.) es requerida.'); return; }

        const sel = document.getElementById('f-envio');
        if (sel && sel.value) {
            empresaId    = parseInt(sel.value);
            empresaNombre = sel.options[sel.selectedIndex].dataset.nombre || '';
            costoEnvio   = parseFloat(sel.options[sel.selectedIndex].dataset.precio || 0);
        }
    }

    const direccionCompleta = tipo === 'delivery'
        ? [jiron, referencia ? 'Ref: ' + referencia : '', distrito, provincia, region]
            .filter(Boolean).join(', ')
        : 'Recojo en tienda';

    const btn = document.getElementById('btn-confirmar');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div> Procesando...';

    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=crear_pedido', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                items,
                metodo_pago:          metodo,
                empresa_envio_id:     empresaId,
                empresa_envio_nombre: empresaNombre,
                costo_envio:          costoEnvio,
                direccion_entrega:    direccionCompleta,
                notas:                document.getElementById('f-notas').value.trim(),
                tipo_entrega:         tipo,
                cliente_nombre:       nombre + (apellidos ? ' ' + apellidos : ''),
                cliente_celular:      celular,
                cliente_dni:          dni,
                crear_cuenta:         crearCuenta,
                cupon_codigo:         cuponAplicado ? cuponAplicado.codigo : '',
            })
        });

        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Error al crear pedido');

        Carrito.limpiar();
        sessionStorage.removeItem('sd_envio');
        window.location = window.BASE_URL + '/confirmacion.php?pedido=' + encodeURIComponent(data.codigo);
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Confirmar pedido';
        mostrarError(e.message);
    }
}

function mostrarError(msg) {
    const errDiv = document.getElementById('error-msg');
    errDiv.textContent = msg;
    errDiv.style.display = 'block';
    errDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>
