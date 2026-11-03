<?php
$cfg = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$contactoEmail = trim($cfg['contacto_email'] ?? '');
$contactoCelular = trim($cfg['contacto_celular'] ?? '');
$footerDireccion = trim($cfg['portal_direccion'] ?? '');
$footerWa = whatsappUrl($cfg, 'Hola ' . ($cfg['nombre_tienda'] ?? '') . ', quisiera hacer una consulta.');
$footerRedes = redesSociales($cfg);
?>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-col">
            <div class="footer-col-title"><?= $shopName ?></div>
            <?php if (!empty($cfg['descripcion'])): ?>
            <p class="footer-desc"><?= htmlspecialchars($cfg['descripcion']) ?></p>
            <?php endif; ?>
            <?php if ($footerRedes): ?>
            <div class="social-links">
                <?php foreach ($footerRedes as $red): ?>
                <a href="<?= htmlspecialchars($red['url']) ?>" target="_blank" rel="noopener" aria-label="<?= $red['label'] ?>" title="<?= $red['label'] ?>"><i class="fab <?= $red['icon'] ?>"></i></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="footer-col">
            <div class="footer-col-title">Empresa</div>
            <a href="<?= BASE_URL ?>/nosotros">Nosotros</a>
            <a href="<?= BASE_URL ?>/trabaja-con-nosotros">Trabaja con nosotros</a>
            <a href="<?= BASE_URL ?>/contacto">Contacto</a>
        </div>

        <div class="footer-col">
            <div class="footer-col-title">Tienda</div>
            <a href="<?= BASE_URL ?>/catalogo">Catálogo</a>
            <?php if (!empty($cfg['puntos_activo'])): ?>
            <a href="<?= BASE_URL ?>/cuenta/puntos">Programa de puntos</a>
            <?php endif; ?>
            <?php if (clienteLogueado()): ?>
            <a href="<?= BASE_URL ?>/cuenta/mis-pedidos">Mis pedidos</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/cuenta/login">Iniciar sesión</a>
            <?php endif; ?>
        </div>

        <div class="footer-col">
            <div class="footer-col-title">Contáctanos</div>
            <?php if ($footerWa): ?>
            <a href="<?= htmlspecialchars($footerWa) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            <?php endif; ?>
            <?php if ($contactoCelular): ?>
            <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $contactoCelular)) ?>"><i class="fas fa-phone"></i> <?= htmlspecialchars($contactoCelular) ?></a>
            <?php endif; ?>
            <?php if ($contactoEmail): ?>
            <a href="mailto:<?= htmlspecialchars($contactoEmail) ?>"><i class="fas fa-envelope"></i> <?= htmlspecialchars($contactoEmail) ?></a>
            <?php endif; ?>
            <?php if ($footerDireccion): ?>
            <span class="footer-text"><i class="fas fa-location-dot"></i> <?= htmlspecialchars($footerDireccion) ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/contacto"><i class="fas fa-paper-plane"></i> Escríbenos</a>
        </div>
    </div>
    <div class="footer-copy">© <?= date('Y') ?> <?= $shopName ?>. Todos los derechos reservados.</div>
</footer>

<?php if ($footerWa): ?>
<a href="<?= htmlspecialchars($footerWa) ?>" class="whatsapp-float" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>
<?php endif; ?>

<?php
// ── Banner FLOTANTE: tiene prioridad sobre el pop-up simple ────────────────
// (si ambos están activos, mostramos solo el flotante)
$flotanteBanner = null;
try {
    $fStmt = getDB()->prepare("SELECT * FROM banners_flotantes WHERE tienda_id = ? AND activo = TRUE ORDER BY created_at DESC LIMIT 1");
    $fStmt->execute([TIENDA_ID]);
    $flotanteBanner = $fStmt->fetch();
} catch (Exception $e) {}

// Banner pop-up solo si NO hay flotante activo
$popupBanner = null;
if (!$flotanteBanner) {
    try {
        $popupStmt = getDB()->prepare("SELECT id, imagen_path, enlace_url, enlace_label FROM banners_popup WHERE tienda_id = ? AND activo = TRUE ORDER BY created_at DESC LIMIT 1");
        $popupStmt->execute([TIENDA_ID]);
        $popupBanner = $popupStmt->fetch();
    } catch (Exception $e) {}
}

// ── Render del banner FLOTANTE (con formulario) ────────────────────────────
if ($flotanteBanner):
    $bgColor = preg_match('/^#[0-9a-fA-F]{6}$/', $flotanteBanner['color_fondo']) ? $flotanteBanner['color_fondo'] : '#fcd34d';
    $txColor = preg_match('/^#[0-9a-fA-F]{6}$/', $flotanteBanner['color_texto']) ? $flotanteBanner['color_texto'] : '#1e293b';
    $tieneImg = $flotanteBanner['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $flotanteBanner['imagen_path']);
?>
<div class="flotante-overlay" id="flotante-banner" role="dialog" aria-modal="true" aria-label="Suscripción">
    <div class="flotante-box" style="background:<?= htmlspecialchars($bgColor) ?>;color:<?= htmlspecialchars($txColor) ?>">
        <button type="button" class="flotante-close" onclick="cerrarFlotante()" aria-label="Cerrar">&times;</button>

        <div class="flotante-content">
            <h2 class="flotante-titulo"><?= nl2br(htmlspecialchars($flotanteBanner['titulo'])) ?></h2>
            <?php if ($flotanteBanner['subtitulo']): ?>
            <p class="flotante-subtitulo"><?= htmlspecialchars($flotanteBanner['subtitulo']) ?></p>
            <?php endif; ?>

            <form id="flotante-form" onsubmit="return enviarFlotante(event)">
                <!-- Nombre + Apellido en una misma fila -->
                <div class="flotante-row">
                    <div class="flotante-field">
                        <label>Nombre</label>
                        <input type="text" name="nombre" required maxlength="100" autocomplete="given-name">
                    </div>
                    <div class="flotante-field">
                        <label>Apellido</label>
                        <input type="text" name="apellido" required maxlength="100" autocomplete="family-name">
                    </div>
                </div>

                <div class="flotante-field">
                    <label>Correo electrónico <span>*</span></label>
                    <input type="email" name="email" required maxlength="150" autocomplete="email">
                </div>

                <div class="flotante-field">
                    <label>Número de celular</label>
                    <input type="tel" name="celular" required maxlength="20" autocomplete="tel" pattern="[0-9+\s\-()]{6,20}">
                </div>

                <div class="flotante-field">
                    <label>Fecha de cumpleaños</label>
                    <input type="date" name="cumpleanos" required>
                </div>

                <button type="submit" class="flotante-submit">
                    <?= htmlspecialchars($flotanteBanner['boton_texto'] ?: 'Enviar') ?>
                </button>

                <div class="flotante-legal">
                    Al suscribirte aceptas nuestros
                    <a href="<?= BASE_URL ?>/terminos-condiciones" target="_blank" rel="noopener">Términos y condiciones</a>
                    y nuestra
                    <a href="<?= BASE_URL ?>/politicas-privacidad" target="_blank" rel="noopener">Política de privacidad</a>.
                </div>
            </form>

            <!-- Mensaje de éxito (oculto hasta que llegue la respuesta) -->
            <div id="flotante-exito" style="display:none;text-align:center;padding:20px 0">
                <i class="fas fa-check-circle" style="font-size:2.5rem;margin-bottom:10px"></i>
                <p style="font-size:1rem;margin:0 0 14px"><?= htmlspecialchars($flotanteBanner['mensaje_exito']) ?></p>
                <div id="flotante-codigo-box" style="display:none;margin:14px 0">
                    <div style="font-size:.78rem;opacity:.75;margin-bottom:4px">Tu código:</div>
                    <div id="flotante-codigo" style="font-weight:800;font-size:1.4rem;letter-spacing:1px;padding:12px 20px;background:rgba(0,0,0,.08);border-radius:8px;display:inline-block"></div>
                </div>
                <button type="button" class="flotante-submit" onclick="cerrarFlotante()" style="margin-top:8px">Cerrar</button>
            </div>
        </div>

        <?php if ($tieneImg): ?>
        <div class="flotante-img">
            <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($flotanteBanner['imagen_path']) ?>" alt="">
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function() {
    const BANNER_ID = <?= (int) $flotanteBanner['id'] ?>;
    const KEY = 'sd_flotante_cerrado_' + BANNER_ID;
    const overlay = document.getElementById('flotante-banner');
    if (!overlay) return;

    let yaCerrado = false;
    try { yaCerrado = localStorage.getItem(KEY) === '1'; } catch (e) {}
    if (yaCerrado) { overlay.remove(); return; }

    setTimeout(() => overlay.classList.add('open'), 800);

    overlay.addEventListener('click', (e) => { if (e.target === overlay) window.cerrarFlotante(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.cerrarFlotante(); });

    window.cerrarFlotante = function() {
        overlay.classList.remove('open');
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        setTimeout(() => overlay.remove(), 250);
    };

    window.enviarFlotante = async function(e) {
        e.preventDefault();
        const form = document.getElementById('flotante-form');
        const btn  = form.querySelector('.flotante-submit');
        const txtOriginal = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Enviando...';

        const data = Object.fromEntries(new FormData(form).entries());
        data.banner_id = BANNER_ID;

        try {
            const res = await fetch(window.BASE_URL + '/api/index.php?action=suscribir_banner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Error al enviar');

            form.style.display = 'none';
            document.getElementById('flotante-exito').style.display = 'block';
            if (json.codigo) {
                document.getElementById('flotante-codigo').textContent = json.codigo;
                document.getElementById('flotante-codigo-box').style.display = 'block';
            }
            // Al enviar exitosamente, marcar como cerrado para no mostrar de nuevo
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
        } catch (err) {
            btn.disabled = false;
            btn.textContent = txtOriginal;
            if (typeof showToast === 'function') showToast(err.message || 'No se pudo enviar. Intenta más tarde.', 'error');
            else alert(err.message || 'No se pudo enviar.');
        }
        return false;
    };
})();
</script>
<?php endif; ?>

<?php
// ── Render del banner POP-UP simple (sólo si NO hay flotante activo) ───────
if ($popupBanner && $popupBanner['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $popupBanner['imagen_path'])):
?>
<div class="popup-banner-overlay" id="popup-banner" role="dialog" aria-modal="true" aria-label="Mensaje promocional">
    <div class="popup-banner-box">
        <button type="button" class="popup-banner-close" onclick="cerrarPopupBanner()" aria-label="Cerrar">&times;</button>
        <?php if ($popupBanner['enlace_url']): ?>
        <a href="<?= htmlspecialchars($popupBanner['enlace_url']) ?>" class="popup-banner-img-link" onclick="cerrarPopupBanner()">
            <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($popupBanner['imagen_path']) ?>" alt="" class="popup-banner-img">
        </a>
        <div class="popup-banner-cta">
            <a href="<?= htmlspecialchars($popupBanner['enlace_url']) ?>" class="btn btn-primary" onclick="cerrarPopupBanner()">
                <?= htmlspecialchars($popupBanner['enlace_label'] ?: 'Ver más') ?>
            </a>
        </div>
        <?php else: ?>
        <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($popupBanner['imagen_path']) ?>" alt="" class="popup-banner-img">
        <?php endif; ?>
    </div>
</div>
<script>
(function() {
    const BANNER_ID = <?= (int) $popupBanner['id'] ?>;
    const KEY = 'sd_popup_cerrado_' + BANNER_ID;
    const overlay = document.getElementById('popup-banner');
    if (!overlay) return;

    // Si el visitante ya cerró este banner, no lo mostramos. Si el admin
    // publica uno nuevo (id distinto) la key cambia y vuelve a aparecer.
    let yaCerrado = false;
    try { yaCerrado = localStorage.getItem(KEY) === '1'; } catch (e) {}
    if (yaCerrado) { overlay.remove(); return; }

    // Pequeño delay para que no choque con la carga inicial de la página.
    setTimeout(() => overlay.classList.add('open'), 500);

    overlay.addEventListener('click', (e) => { if (e.target === overlay) window.cerrarPopupBanner(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.cerrarPopupBanner(); });

    window.cerrarPopupBanner = function() {
        overlay.classList.remove('open');
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        setTimeout(() => overlay.remove(), 250);
    };
})();
</script>
<?php endif; ?>

<div id="toast-container" class="toast-container"></div>
</body>
</html>
