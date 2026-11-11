<?php
// ============================================================
// Barra de pestañas del módulo Fidelización. La incluyen las cinco
// pantallas del módulo justo después de admin/includes/header.php; la
// pestaña activa sale de $adminPage. Cada pestaña es su propia página, así
// formularios, filtros y redirecciones de cada una siguen igual.
// El menú lateral enlaza "Fidelización" a la primera (Programa de puntos).
// ============================================================

const FIDELIZACION_TABS = [
    'puntos'         => ['puntos.php',         'fa-star',          'Programa de puntos'],
    'compras_tienda' => ['compras-tienda.php', 'fa-cash-register', 'Compras en tienda'],
    'recompensas'    => ['recompensas.php',    'fa-award',         'Beneficios / Recompensas'],
    'cupones'        => ['cupones.php',        'fa-ticket',        'Cupones'],
    'niveles'        => ['niveles.php',        'fa-layer-group',   'Niveles de cliente'],
];
?>
<style>
.fid-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:22px; overflow-x:auto; scrollbar-width:thin; }
.fid-tab { padding:10px 16px; font-size:.85rem; font-weight:600; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px;
           display:flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0; text-decoration:none; }
.fid-tab:hover { color:var(--primary); }
.fid-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
</style>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-gift"></i> Fidelización</h1>
</div>

<nav class="fid-tabs" aria-label="Secciones de Fidelización">
    <?php foreach (FIDELIZACION_TABS as $page => [$archivo, $icono, $label]): $esActiva = ($adminPage ?? '') === $page; ?>
    <a href="<?= BASE_URL ?>/admin/<?= $archivo ?>" class="fid-tab<?= $esActiva ? ' active' : '' ?>"<?= $esActiva ? ' aria-current="page"' : '' ?>>
        <i class="fas <?= $icono ?>"></i> <?= $label ?>
    </a>
    <?php endforeach; ?>
</nav>
