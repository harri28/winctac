<?php
$adminPage  = 'reportes';
$adminTitle = 'Reportes';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-chart-bar"></i> Reportes</h1>
</div>

<div class="empty-state" style="padding:60px">
    <i class="fas fa-chart-bar"></i>
    <h3>Próximamente</h3>
    <p>Reportes detallados de ventas, clientes y productos.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
