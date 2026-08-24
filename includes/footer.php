<?php
$cfg = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
$contactoEmail = trim($cfg['contacto_email'] ?? '');
$contactoCelular = trim($cfg['contacto_celular'] ?? '');
?>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-col">
            <div style="font-weight:700;color:#fff;margin-bottom:4px"><?= $shopName ?></div>
            <div class="footer-copy">© <?= date('Y') ?> Todos los derechos reservados</div>
        </div>

        <div class="footer-col">
            <?php if (clienteLogueado()): ?>
            <a href="<?= BASE_URL ?>/cuenta/mis-pedidos.php">Mis pedidos</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/cuenta/login.php">Iniciar sesión</a>
            <?php endif; ?>
        </div>

        <?php if ($contactoEmail || $contactoCelular): ?>
        <div class="footer-col">
            <div class="footer-col-title">Contáctanos</div>
            <?php if ($contactoEmail): ?>
            <a href="mailto:<?= htmlspecialchars($contactoEmail) ?>"><i class="fas fa-envelope"></i> <?= htmlspecialchars($contactoEmail) ?></a>
            <?php endif; ?>
            <?php if ($contactoCelular): ?>
            <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $contactoCelular)) ?>"><i class="fas fa-phone"></i> <?= htmlspecialchars($contactoCelular) ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="footer-col">
            <a href="<?= BASE_URL ?>/nosotros.php" target="_blank" rel="noopener" style="color:#fff;font-size:.85rem">
                <i class="fas fa-store"></i> Conócenos
            </a>
        </div>
    </div>
</footer>

<div id="toast-container" class="toast-container"></div>
</body>
</html>
