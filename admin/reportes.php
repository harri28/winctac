<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('No autorizado'); }

$pdo = getDB();

// ── Rango de fechas ──────────────────────────────────────────
$hoy   = date('Y-m-d');
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = $hoy;
if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }
$hastaExcl = date('Y-m-d', strtotime($hasta . ' +1 day'));

$tab = $_GET['tab'] ?? 'resumen';
$tabsValidas = ['resumen', 'ventas', 'productos', 'clientes', 'envios', 'fidelizacion'];
if (!in_array($tab, $tabsValidas, true)) $tab = 'resumen';

// ── Datos por pestaña ────────────────────────────────────────
$data = [];

if ($tab === 'resumen') {
    $k = $pdo->prepare("
        SELECT
            COUNT(*) FILTER (WHERE estado != 'cancelado') AS pedidos,
            COUNT(*) FILTER (WHERE estado = 'cancelado')  AS cancelados,
            COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos,
            COUNT(DISTINCT cliente_id) FILTER (WHERE cliente_id IS NOT NULL AND estado != 'cancelado') AS clientes_compraron
        FROM pedidos WHERE created_at >= ? AND created_at < ?
    ");
    $k->execute([$desde, $hastaExcl]);
    $data['kpis'] = $k->fetch();
    $data['kpis']['ticket_promedio'] = $data['kpis']['pedidos'] > 0
        ? floatval($data['kpis']['ingresos']) / intval($data['kpis']['pedidos']) : 0;

    $nc = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE created_at >= ? AND created_at < ?");
    $nc->execute([$desde, $hastaExcl]);
    $data['clientes_nuevos'] = (int) $nc->fetchColumn();

    $tp = $pdo->prepare("
        SELECT pd.producto_nombre, SUM(pd.cantidad) AS unidades
        FROM pedido_detalles pd JOIN pedidos p ON p.id = pd.pedido_id
        WHERE p.estado != 'cancelado' AND p.created_at >= ? AND p.created_at < ?
        GROUP BY pd.producto_nombre ORDER BY unidades DESC LIMIT 1
    ");
    $tp->execute([$desde, $hastaExcl]);
    $data['top_producto'] = $tp->fetch();

    $tc = $pdo->prepare("
        SELECT COALESCE(c.nombre, p.cliente_nombre) AS nombre, SUM(p.total) AS gasto
        FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE p.estado != 'cancelado' AND p.created_at >= ? AND p.created_at < ?
        GROUP BY COALESCE(c.nombre, p.cliente_nombre) ORDER BY gasto DESC LIMIT 1
    ");
    $tc->execute([$desde, $hastaExcl]);
    $data['top_cliente'] = $tc->fetch();

    $pm = $pdo->prepare("
        SELECT
            COALESCE(SUM(puntos) FILTER (WHERE tipo = 'ganado'), 0)    AS otorgados,
            COALESCE(SUM(-puntos) FILTER (WHERE tipo = 'canjeado'), 0)  AS canjeados,
            COALESCE(SUM(-puntos) FILTER (WHERE tipo = 'revertido'), 0) AS revertidos
        FROM puntos_movimientos WHERE created_at >= ? AND created_at < ?
    ");
    $pm->execute([$desde, $hastaExcl]);
    $data['puntos'] = $pm->fetch();

    $cg = $pdo->prepare("SELECT COUNT(*) FROM puntos_cupones WHERE created_at >= ? AND created_at < ?");
    $cg->execute([$desde, $hastaExcl]);
    $data['cupones_generados'] = (int) $cg->fetchColumn();

    $cu = $pdo->prepare("SELECT COUNT(*) FROM puntos_cupones WHERE used_at >= ? AND used_at < ?");
    $cu->execute([$desde, $hastaExcl]);
    $data['cupones_usados'] = (int) $cu->fetchColumn();

} elseif ($tab === 'ventas') {
    $d = $pdo->prepare("
        SELECT DATE(created_at) AS dia, COUNT(*) AS pedidos,
               COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos
        FROM pedidos WHERE created_at >= ? AND created_at < ?
        GROUP BY DATE(created_at) ORDER BY dia ASC
    ");
    $d->execute([$desde, $hastaExcl]);
    $data['diario'] = $d->fetchAll();

    $e = $pdo->prepare("
        SELECT estado, COUNT(*) AS n, COALESCE(SUM(total),0) AS total
        FROM pedidos WHERE created_at >= ? AND created_at < ?
        GROUP BY estado ORDER BY n DESC
    ");
    $e->execute([$desde, $hastaExcl]);
    $data['por_estado'] = $e->fetchAll();

    $pg = $pdo->prepare("
        SELECT metodo_pago, COUNT(*) AS n, COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'),0) AS total
        FROM pedidos WHERE created_at >= ? AND created_at < ? AND metodo_pago != ''
        GROUP BY metodo_pago ORDER BY n DESC
    ");
    $pg->execute([$desde, $hastaExcl]);
    $data['por_pago'] = $pg->fetchAll();

    $en = $pdo->prepare("
        SELECT tipo_entrega, COUNT(*) AS n, COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'),0) AS total
        FROM pedidos WHERE created_at >= ? AND created_at < ?
        GROUP BY tipo_entrega ORDER BY n DESC
    ");
    $en->execute([$desde, $hastaExcl]);
    $data['por_entrega'] = $en->fetchAll();

} elseif ($tab === 'productos') {
    $p = $pdo->prepare("
        SELECT pd.producto_nombre, pd.producto_codigo,
               COALESCE(cat.nombre, 'Sin categoría') AS categoria,
               SUM(pd.cantidad) AS unidades, SUM(pd.subtotal) AS ingresos,
               COUNT(DISTINCT pd.pedido_id) AS pedidos
        FROM pedido_detalles pd
        JOIN pedidos ped ON ped.id = pd.pedido_id
        LEFT JOIN productos prod ON prod.id = pd.producto_id
        LEFT JOIN categorias cat ON cat.id = prod.categoria_id
        WHERE ped.estado != 'cancelado' AND ped.created_at >= ? AND ped.created_at < ?
        GROUP BY pd.producto_nombre, pd.producto_codigo, categoria
        ORDER BY ingresos DESC
        LIMIT 100
    ");
    $p->execute([$desde, $hastaExcl]);
    $data['productos'] = $p->fetchAll();

    $c = $pdo->prepare("
        SELECT COALESCE(cat.nombre, 'Sin categoría') AS categoria,
               SUM(pd.cantidad) AS unidades, SUM(pd.subtotal) AS ingresos
        FROM pedido_detalles pd
        JOIN pedidos ped ON ped.id = pd.pedido_id
        LEFT JOIN productos prod ON prod.id = pd.producto_id
        LEFT JOIN categorias cat ON cat.id = prod.categoria_id
        WHERE ped.estado != 'cancelado' AND ped.created_at >= ? AND ped.created_at < ?
        GROUP BY categoria ORDER BY ingresos DESC
    ");
    $c->execute([$desde, $hastaExcl]);
    $data['por_categoria'] = $c->fetchAll();

    $data['stock_bajo'] = $pdo->query("
        SELECT nombre, codigo, stock FROM productos
        WHERE activo = TRUE AND stock <= 5 ORDER BY stock ASC
    ")->fetchAll();

} elseif ($tab === 'clientes') {
    $c = $pdo->prepare("
        SELECT c.id, c.nombre, c.email, COUNT(p.id) AS pedidos,
               COALESCE(SUM(p.total),0) AS gasto, MAX(p.created_at) AS ultimo_pedido
        FROM clientes c JOIN pedidos p ON p.cliente_id = c.id
        WHERE p.estado != 'cancelado' AND p.created_at >= ? AND p.created_at < ?
        GROUP BY c.id, c.nombre, c.email
        ORDER BY gasto DESC LIMIT 100
    ");
    $c->execute([$desde, $hastaExcl]);
    $data['clientes'] = $c->fetchAll();

    $n = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE created_at >= ? AND created_at < ?");
    $n->execute([$desde, $hastaExcl]);
    $data['nuevos'] = (int) $n->fetchColumn();
    $data['total_clientes'] = (int) $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();

} elseif ($tab === 'envios') {
    $e = $pdo->prepare("
        SELECT CASE WHEN tipo_entrega = 'recojo' THEN 'Recojo en tienda'
                    ELSE COALESCE(NULLIF(empresa_envio_nombre,''), 'Sin empresa asignada') END AS empresa,
               COUNT(*) AS pedidos, COALESCE(SUM(costo_envio),0) AS costo_total
        FROM pedidos
        WHERE estado != 'cancelado' AND created_at >= ? AND created_at < ?
        GROUP BY empresa ORDER BY pedidos DESC
    ");
    $e->execute([$desde, $hastaExcl]);
    $data['empresas'] = $e->fetchAll();

} elseif ($tab === 'fidelizacion') {
    $pm = $pdo->prepare("
        SELECT
            COALESCE(SUM(puntos) FILTER (WHERE tipo = 'ganado'), 0)       AS otorgados,
            COALESCE(SUM(-puntos) FILTER (WHERE tipo = 'canjeado'), 0)    AS canjeados,
            COALESCE(SUM(-puntos) FILTER (WHERE tipo = 'revertido'), 0)   AS revertidos,
            COALESCE(SUM(puntos) FILTER (WHERE tipo = 'ajuste_admin'), 0) AS ajustes
        FROM puntos_movimientos WHERE created_at >= ? AND created_at < ?
    ");
    $pm->execute([$desde, $hastaExcl]);
    $data['resumen_puntos'] = $pm->fetch();

    $mv = $pdo->prepare("
        SELECT m.created_at, c.nombre AS cliente, c.email, m.tipo, m.puntos, m.nota
        FROM puntos_movimientos m JOIN clientes c ON c.id = m.cliente_id
        WHERE m.created_at >= ? AND m.created_at < ?
        ORDER BY m.created_at DESC LIMIT 200
    ");
    $mv->execute([$desde, $hastaExcl]);
    $data['movimientos'] = $mv->fetchAll();

    $cp = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ?) AS generados,
               COUNT(*) FILTER (WHERE used_at >= ? AND used_at < ?)       AS usados
        FROM puntos_cupones
    ");
    $cp->execute([$desde, $hastaExcl, $desde, $hastaExcl]);
    $data['cupones'] = $cp->fetch();

    $dc = $pdo->prepare("
        SELECT COUNT(*) AS pedidos_con_cupon, COALESCE(SUM(cupon_descuento),0) AS descuento_total
        FROM pedidos WHERE cupon_codigo != '' AND estado != 'cancelado' AND created_at >= ? AND created_at < ?
    ");
    $dc->execute([$desde, $hastaExcl]);
    $data['descuento_cupones'] = $dc->fetch();

    $tr = $pdo->prepare("
        SELECT recompensa_nombre, COUNT(*) AS veces, COALESCE(SUM(puntos_gastados),0) AS puntos_totales
        FROM puntos_cupones WHERE created_at >= ? AND created_at < ?
        GROUP BY recompensa_nombre ORDER BY veces DESC LIMIT 10
    ");
    $tr->execute([$desde, $hastaExcl]);
    $data['top_recompensas'] = $tr->fetchAll();
}

// ── Exportar CSV ─────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tab . '_' . $desde . '_a_' . $hasta . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    switch ($tab) {
        case 'ventas':
            fputcsv($out, ['Fecha', 'Pedidos', 'Ingresos (S/)']);
            foreach ($data['diario'] as $r) {
                fputcsv($out, [$r['dia'], $r['pedidos'], number_format($r['ingresos'], 2, '.', '')]);
            }
            break;
        case 'productos':
            fputcsv($out, ['Producto', 'Código', 'Categoría', 'Unidades', 'Ingresos (S/)', 'Pedidos']);
            foreach ($data['productos'] as $r) {
                fputcsv($out, [$r['producto_nombre'], $r['producto_codigo'], $r['categoria'], $r['unidades'], number_format($r['ingresos'], 2, '.', ''), $r['pedidos']]);
            }
            break;
        case 'clientes':
            fputcsv($out, ['Cliente', 'Email', 'Pedidos', 'Total gastado (S/)', 'Último pedido']);
            foreach ($data['clientes'] as $r) {
                fputcsv($out, [$r['nombre'], $r['email'], $r['pedidos'], number_format($r['gasto'], 2, '.', ''), $r['ultimo_pedido']]);
            }
            break;
        case 'envios':
            fputcsv($out, ['Empresa / Modalidad', 'Pedidos', 'Costo de envío total (S/)']);
            foreach ($data['empresas'] as $r) {
                fputcsv($out, [$r['empresa'], $r['pedidos'], number_format($r['costo_total'], 2, '.', '')]);
            }
            break;
        case 'fidelizacion':
            fputcsv($out, ['Fecha', 'Cliente', 'Email', 'Tipo', 'Puntos', 'Nota']);
            foreach ($data['movimientos'] as $r) {
                fputcsv($out, [$r['created_at'], $r['cliente'], $r['email'], $r['tipo'], $r['puntos'], $r['nota']]);
            }
            break;
        default: // resumen
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Pedidos válidos', $data['kpis']['pedidos']]);
            fputcsv($out, ['Pedidos cancelados', $data['kpis']['cancelados']]);
            fputcsv($out, ['Ingresos (S/)', number_format($data['kpis']['ingresos'], 2, '.', '')]);
            fputcsv($out, ['Ticket promedio (S/)', number_format($data['kpis']['ticket_promedio'], 2, '.', '')]);
            fputcsv($out, ['Clientes que compraron', $data['kpis']['clientes_compraron']]);
            fputcsv($out, ['Clientes nuevos', $data['clientes_nuevos']]);
            fputcsv($out, ['Producto más vendido', $data['top_producto']['producto_nombre'] ?? '—']);
            fputcsv($out, ['Cliente que más gastó', $data['top_cliente']['nombre'] ?? '—']);
            fputcsv($out, ['Puntos otorgados', $data['puntos']['otorgados']]);
            fputcsv($out, ['Puntos canjeados', $data['puntos']['canjeados']]);
            fputcsv($out, ['Cupones generados', $data['cupones_generados']]);
            fputcsv($out, ['Cupones usados', $data['cupones_usados']]);
    }
    fclose($out);
    exit;
}

// ── Render HTML ──────────────────────────────────────────────
$adminPage  = 'reportes';
$adminTitle = 'Reportes';
require_once __DIR__ . '/includes/header.php';

$presets = [
    'hoy'        => ['Hoy', $hoy, $hoy],
    'ayer'       => ['Ayer', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7dias'      => ['Últimos 7 días', date('Y-m-d', strtotime('-6 days')), $hoy],
    'mes'        => ['Este mes', date('Y-m-01'), $hoy],
    'mes_pasado' => ['Mes pasado', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];

$tabsInfo = [
    'resumen'      => ['Resumen general', 'fa-chart-pie'],
    'ventas'       => ['Ventas', 'fa-chart-line'],
    'productos'    => ['Productos', 'fa-box'],
    'clientes'     => ['Clientes', 'fa-users'],
    'envios'       => ['Envíos', 'fa-truck'],
    'fidelizacion' => ['Fidelización', 'fa-star'],
];

function reportUrl($tab, $desde, $hasta, $extra = '') {
    return '?tab=' . urlencode($tab) . '&desde=' . urlencode($desde) . '&hasta=' . urlencode($hasta) . $extra;
}

$estadoColors = ['pendiente' => '#f59e0b', 'confirmado' => '#3b82f6', 'enviado' => '#8b5cf6', 'entregado' => '#10b981', 'cancelado' => '#ef4444'];
$estadoLabels = ['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
$pagoColors   = ['billetera' => '#7c3aed', 'pos' => '#0e7490', 'efectivo' => '#16a34a'];
$tipoMovLabel = ['ganado' => 'Ganado', 'canjeado' => 'Canjeado', 'revertido' => 'Revertido', 'ajuste_admin' => 'Ajuste'];
?>

<style>
.report-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:22px; flex-wrap:wrap; }
.report-tab { padding:10px 16px; font-size:.85rem; font-weight:600; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:6px; }
.report-tab:hover { color:var(--primary); }
.report-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
.report-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }
</style>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-chart-bar"></i> Reportes</h1>
</div>

<!-- Filtro de fecha -->
<div class="card" style="margin-bottom:20px">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
        <?php foreach ($presets as $p):
            [$label, $pd, $ph] = $p;
            $activo = ($desde === $pd && $hasta === $ph);
        ?>
        <a href="<?= reportUrl($tab, $pd, $ph) ?>" class="btn <?= $activo ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:.8rem;padding:6px 12px">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:.78rem">Desde</label>
            <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($desde) ?>" style="width:160px">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:.78rem">Hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($hasta) ?>" style="width:160px">
        </div>
        <button type="submit" class="btn btn-primary" style="font-size:.85rem"><i class="fas fa-filter"></i> Aplicar</button>
        <a href="<?= reportUrl($tab, $desde, $hasta, '&export=1') ?>" class="btn btn-secondary" style="font-size:.85rem;margin-left:auto">
            <i class="fas fa-download"></i> Exportar CSV
        </a>
    </form>
</div>

<!-- Pestañas -->
<div class="report-tabs">
    <?php foreach ($tabsInfo as $key => $info): [$label, $icon] = $info; ?>
    <a href="<?= reportUrl($key, $desde, $hasta) ?>" class="report-tab <?= $tab === $key ? 'active' : '' ?>">
        <i class="fas <?= $icon ?>"></i> <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'resumen'): $k = $data['kpis']; ?>

<div class="report-kpi-grid">
    <div class="stat-card" style="border-top:3px solid var(--primary)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--blue"><i class="fas fa-shopping-bag"></i></div>
            <div>
                <div class="stat-label">Pedidos válidos</div>
                <div class="stat-value" style="color:var(--primary)"><?= $k['pedidos'] ?></div>
                <div class="stat-sub"><?= $k['cancelados'] ?> cancelados</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--success)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--green"><i class="fas fa-coins"></i></div>
            <div>
                <div class="stat-label">Ingresos</div>
                <div class="stat-value stat-value--md" style="color:var(--success)">S/ <?= number_format($k['ingresos'], 2) ?></div>
                <div class="stat-sub">S/ <?= number_format($k['ticket_promedio'], 2) ?> ticket promedio</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #8b5cf6">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--purple"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-label">Clientes que compraron</div>
                <div class="stat-value" style="color:#8b5cf6"><?= $k['clientes_compraron'] ?></div>
                <div class="stat-sub"><?= $data['clientes_nuevos'] ?> nuevos en el periodo</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #f59e0b">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--amber"><i class="fas fa-fire"></i></div>
            <div>
                <div class="stat-label">Producto más vendido</div>
                <div style="font-weight:700;font-size:.92rem;margin-top:4px"><?= htmlspecialchars($data['top_producto']['producto_nombre'] ?? '—') ?></div>
                <div class="stat-sub"><?= $data['top_producto']['unidades'] ?? 0 ?> unidades</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #0284c7">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#e0f2fe;color:#0284c7"><i class="fas fa-crown"></i></div>
            <div>
                <div class="stat-label">Mejor cliente</div>
                <div style="font-weight:700;font-size:.92rem;margin-top:4px"><?= htmlspecialchars($data['top_cliente']['nombre'] ?? '—') ?></div>
                <div class="stat-sub">S/ <?= number_format($data['top_cliente']['gasto'] ?? 0, 2) ?> gastado</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--warning)">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i class="fas fa-star"></i></div>
            <div>
                <div class="stat-label">Puntos otorgados</div>
                <div class="stat-value" style="color:var(--warning)"><?= $data['puntos']['otorgados'] ?></div>
                <div class="stat-sub"><?= $data['puntos']['canjeados'] ?> canjeados</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #7c3aed">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-ticket"></i></div>
            <div>
                <div class="stat-label">Cupones generados</div>
                <div class="stat-value" style="color:#7c3aed"><?= $data['cupones_generados'] ?></div>
                <div class="stat-sub"><?= $data['cupones_usados'] ?> usados</div>
            </div>
        </div>
    </div>
</div>
<p style="color:var(--text-muted);font-size:.85rem;margin-top:16px">
    Resumen de <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>. Para el detalle de cada área, usa las pestañas de arriba.
</p>

<?php elseif ($tab === 'ventas'): ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-chart-bar" style="color:var(--primary)"></i> Ingresos por día</div>
    <?php if (!$data['diario']): ?>
    <div class="empty-state" style="padding:30px"><i class="fas fa-chart-bar"></i><p>Sin ventas en este periodo</p></div>
    <?php else:
        $maxTotal = max(array_column($data['diario'], 'ingresos') ?: [1]) ?: 1;
    ?>
    <div class="bar-chart" style="overflow-x:auto">
        <?php foreach ($data['diario'] as $d): $h = max(8, round(floatval($d['ingresos']) / $maxTotal * 100)); ?>
        <div class="bar-col">
            <span class="bar-col__amount">S/<?= number_format($d['ingresos'], 0) ?></span>
            <div class="bar-col__fill" style="height:<?= $h ?>px"></div>
            <span class="bar-col__label"><?= date('d/m', strtotime($d['dia'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="chart-summary">
        <span style="color:var(--text-muted)">Total periodo:</span>
        <strong style="color:var(--primary)">S/ <?= number_format(array_sum(array_column($data['diario'], 'ingresos')), 2) ?></strong>
        <span style="color:var(--text-muted)"><?= array_sum(array_column($data['diario'], 'pedidos')) ?> pedidos</span>
    </div>
    <?php endif; ?>
</div>

<div class="dash-charts-row dash-charts-row--3">
    <div class="card">
        <div class="card-title"><i class="fas fa-circle-notch" style="color:var(--primary)"></i> Por estado</div>
        <?php if (!$data['por_estado']): ?>
        <div class="empty-state" style="padding:20px"><p>Sin datos</p></div>
        <?php else: $totalE = array_sum(array_column($data['por_estado'], 'n')) ?: 1; ?>
        <div class="status-bars">
            <?php foreach ($data['por_estado'] as $es):
                $pct = round($es['n'] / $totalE * 100);
                $color = $estadoColors[$es['estado']] ?? '#94a3b8';
                $label = $estadoLabels[$es['estado']] ?? $es['estado'];
            ?>
            <div class="status-item">
                <div class="status-item__header"><span class="status-item__name"><?= $label ?></span><span class="status-item__count"><?= $es['n'] ?> (<?= $pct ?>%)</span></div>
                <div class="status-item__track"><div class="status-item__fill" style="background:<?= $color ?>;width:<?= $pct ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-credit-card" style="color:var(--primary)"></i> Por método de pago</div>
        <?php if (!$data['por_pago']): ?>
        <div class="empty-state" style="padding:20px"><p>Sin datos</p></div>
        <?php else: $totalP = array_sum(array_column($data['por_pago'], 'n')) ?: 1; ?>
        <div class="status-bars">
            <?php foreach ($data['por_pago'] as $mp):
                $pct = round($mp['n'] / $totalP * 100);
                $color = $pagoColors[strtolower($mp['metodo_pago'])] ?? '#64748b';
            ?>
            <div class="status-item">
                <div class="status-item__header"><span class="status-item__name"><?= htmlspecialchars(ucfirst($mp['metodo_pago'])) ?></span><span class="status-item__count"><?= $mp['n'] ?> (<?= $pct ?>%)</span></div>
                <div class="status-item__track"><div class="status-item__fill" style="background:<?= $color ?>;width:<?= $pct ?>%"></div></div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">S/ <?= number_format($mp['total'], 2) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-truck" style="color:var(--primary)"></i> Por tipo de entrega</div>
        <?php if (!$data['por_entrega']): ?>
        <div class="empty-state" style="padding:20px"><p>Sin datos</p></div>
        <?php else: $totalEn = array_sum(array_column($data['por_entrega'], 'n')) ?: 1; ?>
        <div class="status-bars">
            <?php foreach ($data['por_entrega'] as $en):
                $pct = round($en['n'] / $totalEn * 100);
                $label = $en['tipo_entrega'] === 'recojo' ? 'Recojo en tienda' : 'Delivery';
                $color = $en['tipo_entrega'] === 'recojo' ? '#0ea5e9' : '#dc2626';
            ?>
            <div class="status-item">
                <div class="status-item__header"><span class="status-item__name"><?= $label ?></span><span class="status-item__count"><?= $en['n'] ?> (<?= $pct ?>%)</span></div>
                <div class="status-item__track"><div class="status-item__fill" style="background:<?= $color ?>;width:<?= $pct ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'productos'): ?>

<?php if ($data['stock_bajo']): ?>
<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i> <?= count($data['stock_bajo']) ?> producto(s) con stock bajo (≤5 unidades):
    <?= implode(', ', array_map(fn($p) => htmlspecialchars($p['nombre']) . ' (' . $p['stock'] . ')', $data['stock_bajo'])) ?>
</div>
<?php endif; ?>

<div class="table-wrapper" style="margin-bottom:20px">
    <div class="section-bar"><h3 class="section-bar__title"><i class="fas fa-box" style="color:var(--primary)"></i> Productos vendidos en el periodo</h3></div>
    <?php if (!$data['productos']): ?>
    <div class="empty-state" style="padding:40px"><i class="fas fa-box-open"></i><p>Sin ventas en este periodo</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Producto</th><th>Categoría</th><th>Unidades</th><th>Ingresos</th><th>Pedidos</th></tr></thead>
        <tbody>
        <?php foreach ($data['productos'] as $p): ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($p['producto_nombre']) ?></td>
            <td><?= htmlspecialchars($p['categoria']) ?></td>
            <td><?= $p['unidades'] ?></td>
            <td class="td-bold" style="color:var(--success)">S/ <?= number_format($p['ingresos'], 2) ?></td>
            <td><?= $p['pedidos'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-tags" style="color:var(--primary)"></i> Ventas por categoría</div>
    <?php if (!$data['por_categoria']): ?>
    <div class="empty-state" style="padding:20px"><p>Sin datos</p></div>
    <?php else: $totalCat = array_sum(array_column($data['por_categoria'], 'ingresos')) ?: 1; ?>
    <div class="status-bars">
        <?php foreach ($data['por_categoria'] as $c): $pct = round(floatval($c['ingresos']) / $totalCat * 100); ?>
        <div class="status-item">
            <div class="status-item__header"><span class="status-item__name"><?= htmlspecialchars($c['categoria']) ?></span><span class="status-item__count">S/ <?= number_format($c['ingresos'], 2) ?> (<?= $pct ?>%)</span></div>
            <div class="status-item__track"><div class="status-item__fill" style="background:var(--primary);width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'clientes'): ?>

<div class="report-kpi-grid" style="margin-bottom:20px">
    <div class="stat-card" style="border-top:3px solid #8b5cf6">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--purple"><i class="fas fa-user-plus"></i></div>
            <div>
                <div class="stat-label">Clientes nuevos</div>
                <div class="stat-value" style="color:#8b5cf6"><?= $data['nuevos'] ?></div>
                <div class="stat-sub">de <?= $data['total_clientes'] ?> registrados en total</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--primary)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--blue"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-label">Compraron en el periodo</div>
                <div class="stat-value" style="color:var(--primary)"><?= count($data['clientes']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <div class="section-bar"><h3 class="section-bar__title"><i class="fas fa-crown" style="color:#f59e0b"></i> Mejores clientes del periodo</h3></div>
    <?php if (!$data['clientes']): ?>
    <div class="empty-state" style="padding:40px"><i class="fas fa-users"></i><p>Sin compras registradas en este periodo</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>#</th><th>Cliente</th><th>Email</th><th>Pedidos</th><th>Total gastado</th><th>Último pedido</th></tr></thead>
        <tbody>
        <?php foreach ($data['clientes'] as $i => $c): ?>
        <tr>
            <td class="td-muted" style="font-weight:700"><?= $i + 1 ?></td>
            <td class="td-bold"><?= htmlspecialchars($c['nombre']) ?></td>
            <td class="td-muted"><?= htmlspecialchars($c['email']) ?></td>
            <td><?= $c['pedidos'] ?></td>
            <td class="td-bold" style="color:var(--success)">S/ <?= number_format($c['gasto'], 2) ?></td>
            <td class="td-muted"><?= date('d/m/Y', strtotime($c['ultimo_pedido'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'envios'): ?>

<div class="table-wrapper">
    <div class="section-bar"><h3 class="section-bar__title"><i class="fas fa-truck" style="color:var(--primary)"></i> Pedidos por empresa / modalidad de envío</h3></div>
    <?php if (!$data['empresas']): ?>
    <div class="empty-state" style="padding:40px"><i class="fas fa-truck"></i><p>Sin pedidos en este periodo</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Empresa / Modalidad</th><th>Pedidos</th><th>Costo de envío total</th></tr></thead>
        <tbody>
        <?php foreach ($data['empresas'] as $e): ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($e['empresa']) ?></td>
            <td><?= $e['pedidos'] ?></td>
            <td class="td-bold" style="color:var(--success)">S/ <?= number_format($e['costo_total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'fidelizacion'): ?>

<div class="report-kpi-grid" style="margin-bottom:20px">
    <div class="stat-card" style="border-top:3px solid var(--warning)">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i class="fas fa-star"></i></div>
            <div><div class="stat-label">Puntos otorgados</div><div class="stat-value" style="color:var(--warning)"><?= $data['resumen_puntos']['otorgados'] ?></div></div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--primary)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--blue"><i class="fas fa-gift"></i></div>
            <div><div class="stat-label">Puntos canjeados</div><div class="stat-value" style="color:var(--primary)"><?= $data['resumen_puntos']['canjeados'] ?></div></div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--danger)">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:var(--danger-bg);color:var(--danger)"><i class="fas fa-rotate-left"></i></div>
            <div><div class="stat-label">Puntos revertidos</div><div class="stat-value" style="color:var(--danger)"><?= $data['resumen_puntos']['revertidos'] ?></div></div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #7c3aed">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-ticket"></i></div>
            <div><div class="stat-label">Cupones generados / usados</div><div class="stat-value stat-value--md" style="color:#7c3aed"><?= $data['cupones']['generados'] ?> / <?= $data['cupones']['usados'] ?></div></div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--success)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--green"><i class="fas fa-tags"></i></div>
            <div>
                <div class="stat-label">Descuento por cupones</div>
                <div class="stat-value stat-value--md" style="color:var(--success)">S/ <?= number_format($data['descuento_cupones']['descuento_total'], 2) ?></div>
                <div class="stat-sub"><?= $data['descuento_cupones']['pedidos_con_cupon'] ?> pedidos</div>
            </div>
        </div>
    </div>
</div>

<div class="table-wrapper" style="margin-bottom:20px">
    <div class="section-bar"><h3 class="section-bar__title"><i class="fas fa-award" style="color:#f59e0b"></i> Recompensas más canjeadas</h3></div>
    <?php if (!$data['top_recompensas']): ?>
    <div class="empty-state" style="padding:30px"><p>Sin canjes en este periodo</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Recompensa</th><th>Veces canjeada</th><th>Puntos totales</th></tr></thead>
        <tbody>
        <?php foreach ($data['top_recompensas'] as $r): ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($r['recompensa_nombre']) ?></td>
            <td><?= $r['veces'] ?></td>
            <td><?= $r['puntos_totales'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="table-wrapper">
    <div class="section-bar"><h3 class="section-bar__title"><i class="fas fa-history" style="color:var(--text-muted)"></i> Historial de movimientos</h3></div>
    <?php if (!$data['movimientos']): ?>
    <div class="empty-state" style="padding:30px"><p>Sin movimientos en este periodo</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Puntos</th><th>Nota</th></tr></thead>
        <tbody>
        <?php foreach ($data['movimientos'] as $m): ?>
        <tr>
            <td class="td-muted" style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
            <td><?= htmlspecialchars($m['cliente']) ?></td>
            <td><?= $tipoMovLabel[$m['tipo']] ?? $m['tipo'] ?></td>
            <td style="font-weight:700;color:<?= $m['puntos'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $m['puntos'] >= 0 ? '+' : '' ?><?= $m['puntos'] ?></td>
            <td class="td-muted" style="font-size:.85rem"><?= htmlspecialchars($m['nota']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
