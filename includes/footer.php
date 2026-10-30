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

<div id="toast-container" class="toast-container"></div>
</body>
</html>
