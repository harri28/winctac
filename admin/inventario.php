<?php
$adminPage  = 'inventario';
$adminTitle = 'Inventario';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-warehouse"></i> Inventario</h1>
</div>

<div class="empty-state" style="padding:60px">
    <i class="fas fa-warehouse"></i>
    <h3>Próximamente</h3>
    <p>Historial de movimientos de stock (entradas, salidas y ajustes) para cada producto.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
