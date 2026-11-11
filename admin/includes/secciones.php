<?php
// ============================================================
// Secciones del admin que se muestran como UNA pantalla con pestañas
// (Fidelización, Configuración). Única fuente de verdad para:
//  - el menú lateral (header.php): un solo enlace a la primera pestaña,
//    activo en cualquiera de sus páginas;
//  - la barra de pestañas que cada página imprime con adminSeccionTabs().
// Cada pestaña sigue siendo su propia página (formularios, filtros y
// redirecciones de cada una no cambian).
// Estructura: clave => [título, ícono, [adminPage => [archivo, ícono, etiqueta]]]
// ============================================================

const ADMIN_SECCIONES = [
    'fidelizacion' => ['Fidelización', 'fa-gift', [
        'puntos'         => ['puntos.php',         'fa-star',          'Programa de puntos'],
        'compras_tienda' => ['compras-tienda.php', 'fa-cash-register', 'Compras en tienda'],
        'recompensas'    => ['recompensas.php',    'fa-award',         'Beneficios / Recompensas'],
        'cupones'        => ['cupones.php',        'fa-ticket',        'Cupones'],
        'niveles'        => ['niveles.php',        'fa-layer-group',   'Niveles de cliente'],
    ]],
    'configuracion' => ['Configuración', 'fa-cog', [
        'config'            => ['config.php',            'fa-sliders-h',          'Configuración'],
        'banco'             => ['banco.php',             'fa-university',         'Banco'],
        'facturacion'       => ['facturacion.php',       'fa-file-invoice',       'Facturación'],
        'banners'           => ['banners.php',           'fa-images',             'Banners'],
        'banners_popup'     => ['banners-popup.php',     'fa-window-maximize',    'Banners pop-up'],
        'banners_flotantes' => ['banners-flotantes.php', 'fa-envelope-open-text', 'Banners flotantes'],
    ]],
];

// Entrada del menú lateral para una sección: un enlace a su primera pestaña.
function adminSeccionMenu(string $seccion): array {
    [$titulo, $icono, $tabs] = ADMIN_SECCIONES[$seccion];
    $primera = array_key_first($tabs);
    return ['type' => 'link', 'page' => $primera, 'pages' => array_keys($tabs),
            'href' => BASE_URL . '/admin/' . $tabs[$primera][0], 'icon' => $icono, 'label' => $titulo];
}

// Título de la sección + barra de pestañas (la activa sale de $adminPage).
function adminSeccionTabs(string $seccion): void {
    global $adminPage;
    static $estilos = false;
    [$titulo, $icono, $tabs] = ADMIN_SECCIONES[$seccion];
    if (!$estilos) {
        $estilos = true; ?>
<style>
.seccion-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:22px; overflow-x:auto; scrollbar-width:thin; }
.seccion-tab { padding:10px 16px; font-size:.85rem; font-weight:600; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px;
               display:flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0; text-decoration:none; }
.seccion-tab:hover { color:var(--primary); }
.seccion-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
</style>
<?php } ?>
<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas <?= $icono ?>"></i> <?= $titulo ?></h1>
</div>

<nav class="seccion-tabs" aria-label="Secciones de <?= $titulo ?>">
    <?php foreach ($tabs as $page => [$archivo, $iconoTab, $label]): $esActiva = ($adminPage ?? '') === $page; ?>
    <a href="<?= BASE_URL ?>/admin/<?= $archivo ?>" class="seccion-tab<?= $esActiva ? ' active' : '' ?>"<?= $esActiva ? ' aria-current="page"' : '' ?>>
        <i class="fas <?= $iconoTab ?>"></i> <?= $label ?>
    </a>
    <?php endforeach; ?>
</nav>
<?php
}
