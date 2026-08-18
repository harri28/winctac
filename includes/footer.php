<?php
$cfg = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$waNum = preg_replace('/\D/', '', $cfg['whatsapp_numero'] ?? '');
?>
<footer class="site-footer">
    <div class="footer-inner">
        <div>
            <div style="font-weight:700;color:#fff;margin-bottom:4px"><?= $shopName ?></div>
            <div class="footer-copy">© <?= date('Y') ?> Todos los derechos reservados</div>
        </div>
        <div class="footer-links">
            <a href="<?= BASE_URL ?>/">Inicio</a>
            <?php if (clienteLogueado()): ?>
            <a href="<?= BASE_URL ?>/cuenta/mis-pedidos.php">Mis pedidos</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/cuenta/login.php">Iniciar sesión</a>
            <?php endif; ?>
            <?php if ($waNum): ?>
            <a href="https://wa.me/51<?= $waNum ?>" target="_blank">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>
            <?php endif; ?>
        </div>

        <div class="footer-about">
            <div class="footer-about-title">Conócenos</div>
            <a href="<?= BASE_URL ?>/nosotros.php" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size:.82rem">
                <i class="fas fa-store"></i> Sobre nosotros
            </a>
        </div>
    </div>
</footer>

<div id="toast-container" class="toast-container"></div>
</body>
</html>
