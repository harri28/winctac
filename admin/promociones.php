<?php
$adminPage  = 'promociones';
$adminTitle = 'Promociones';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-percentage"></i> Promociones</h1>
</div>

<div class="empty-state" style="padding:60px">
    <i class="fas fa-percentage"></i>
    <h3>Próximamente</h3>
    <p>Configura descuentos y ofertas por tiempo limitado.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
