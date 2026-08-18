<?php
$pageTitle   = 'Nosotros';
$currentPage = 'nosotros';
require_once __DIR__ . '/includes/header.php';

$cfg      = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda Online');
?>

<div class="page-wrapper">
<div class="container" style="max-width:840px">

    <h2 class="section-title" style="margin-bottom:28px">
        <i class="fas fa-store"></i> Sobre Nosotros
    </h2>

    <div class="card" style="margin-bottom:20px">
        <div class="card-title"><i class="fas fa-heart"></i> Quiénes somos</div>
        <p style="font-size:.95rem;color:var(--text-muted);line-height:1.7">
            En <?= $shopName ?> nos dedicamos a ofrecerte productos de calidad con una atención cercana,
            combinando variedad, precios justos y un servicio pensado en tu comodidad. Trabajamos todos los
            días para que tu experiencia de compra sea simple, rápida y confiable.
        </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div class="card">
            <div class="card-title"><i class="fas fa-eye"></i> Visión</div>
            <p style="font-size:.9rem;color:var(--text-muted);line-height:1.7">
                Ser la tienda online de referencia para nuestros clientes, reconocida por su confiabilidad,
                variedad y la calidad de su servicio.
            </p>
        </div>
        <div class="card">
            <div class="card-title"><i class="fas fa-bullseye"></i> Misión</div>
            <p style="font-size:.9rem;color:var(--text-muted);line-height:1.7">
                Brindar a cada cliente una experiencia de compra simple, segura y satisfactoria, todos los días.
            </p>
        </div>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
