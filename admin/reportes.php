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

// Periodo anterior de igual duración (para comparar las cifras del Resumen)
$diasPeriodo   = max(1, (int) round((strtotime($hastaExcl) - strtotime($desde)) / 86400));
$prevDesde     = date('Y-m-d', strtotime($desde . " -$diasPeriodo days"));
$prevHastaExcl = $desde;

// ── Consultas compartidas entre pestañas ─────────────────────

// Serie continua del rango (los tramos sin pedidos salen en 0). Con rangos
// largos se agrupa por semana o por mes para que el gráfico siga legible;
// la exportación CSV siempre va por día.
$granularidad = isset($_GET['export']) || $diasPeriodo <= 92 ? 'day' : ($diasPeriodo <= 366 ? 'week' : 'month');
$granLabel    = ['day' => 'día', 'week' => 'semana', 'month' => 'mes'][$granularidad];

function reporteDiario(PDO $pdo, string $desde, string $hasta, string $gran = 'day'): array {
    if (!in_array($gran, ['day', 'week', 'month'], true)) $gran = 'day';
    $hastaExcl = date('Y-m-d', strtotime($hasta . ' +1 day'));
    // Cada tramo se recorta al rango pedido (la primera semana/mes puede
    // empezar antes de "desde").
    $st = $pdo->prepare("
        SELECT b::date AS dia,
               COUNT(p.id) FILTER (WHERE p.estado != 'cancelado') AS pedidos,
               COUNT(p.id) FILTER (WHERE p.estado = 'cancelado')  AS cancelados,
               COALESCE(SUM(p.total) FILTER (WHERE p.estado != 'cancelado'), 0) AS ingresos,
               COUNT(DISTINCT p.cliente_id) FILTER (WHERE p.estado != 'cancelado') AS clientes
        FROM generate_series(date_trunc('$gran', ?::timestamp), ?::timestamp, interval '1 $gran') AS b
        LEFT JOIN pedidos p ON p.created_at >= GREATEST(b, ?::timestamp)
                           AND p.created_at <  LEAST(b + interval '1 $gran', ?::timestamp)
        GROUP BY b ORDER BY b
    ");
    $st->execute([$desde, $hasta, $desde, $hastaExcl]);
    return $st->fetchAll();
}

// Etiqueta del eje X según la agrupación
function repEtiquetaTramo(string $dia, string $gran): string {
    static $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $t = strtotime($dia);
    if ($gran === 'month') return $meses[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    if ($gran === 'week')  return 'Sem. ' . date('d/m', $t);
    return date('d/m', $t);
}

function reporteKpis(PDO $pdo, string $desde, string $hastaExcl): array {
    $k = $pdo->prepare("
        SELECT
            COUNT(*) FILTER (WHERE estado != 'cancelado') AS pedidos,
            COUNT(*) FILTER (WHERE estado = 'cancelado')  AS cancelados,
            COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos,
            COUNT(DISTINCT cliente_id) FILTER (WHERE cliente_id IS NOT NULL AND estado != 'cancelado') AS clientes_compraron
        FROM pedidos WHERE created_at >= ? AND created_at < ?
    ");
    $k->execute([$desde, $hastaExcl]);
    $r = $k->fetch();
    $r['ticket_promedio'] = $r['pedidos'] > 0 ? floatval($r['ingresos']) / intval($r['pedidos']) : 0;
    return $r;
}

function reportePorEstado(PDO $pdo, string $desde, string $hastaExcl): array {
    $e = $pdo->prepare("
        SELECT estado, COUNT(*) AS n, COALESCE(SUM(total),0) AS total
        FROM pedidos WHERE created_at >= ? AND created_at < ?
        GROUP BY estado ORDER BY n DESC
    ");
    $e->execute([$desde, $hastaExcl]);
    return $e->fetchAll();
}

function reporteTopProductos(PDO $pdo, string $desde, string $hastaExcl, int $limite): array {
    $t = $pdo->prepare("
        SELECT pd.producto_nombre, SUM(pd.cantidad) AS unidades, SUM(pd.subtotal) AS ingresos
        FROM pedido_detalles pd JOIN pedidos p ON p.id = pd.pedido_id
        WHERE p.estado != 'cancelado' AND p.created_at >= ? AND p.created_at < ?
        GROUP BY pd.producto_nombre ORDER BY unidades DESC, ingresos DESC LIMIT $limite
    ");
    $t->execute([$desde, $hastaExcl]);
    return $t->fetchAll();
}

function reportePorCategoria(PDO $pdo, string $desde, string $hastaExcl): array {
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
    return $c->fetchAll();
}

// ── Datos por pestaña ────────────────────────────────────────
$data = [];

if ($tab === 'resumen') {
    $data['kpis']      = reporteKpis($pdo, $desde, $hastaExcl);
    $data['kpis_prev'] = reporteKpis($pdo, $prevDesde, $prevHastaExcl);
    $data['diario']    = reporteDiario($pdo, $desde, $hasta, $granularidad);
    $data['por_estado']    = reportePorEstado($pdo, $desde, $hastaExcl);
    $data['top10']         = reporteTopProductos($pdo, $desde, $hastaExcl, 10);
    $data['por_categoria'] = array_slice(reportePorCategoria($pdo, $desde, $hastaExcl), 0, 10);

    // Clientes que compraron en el periodo: nuevos (su primera compra válida
    // cae en el periodo) vs recurrentes (ya habían comprado antes).
    $nr = $pdo->prepare("
        WITH compradores AS (
            SELECT DISTINCT cliente_id FROM pedidos
            WHERE estado != 'cancelado' AND cliente_id IS NOT NULL AND created_at >= ? AND created_at < ?
        )
        SELECT COUNT(*) FILTER (WHERE NOT EXISTS (
                   SELECT 1 FROM pedidos q WHERE q.cliente_id = c.cliente_id AND q.estado != 'cancelado' AND q.created_at < ?
               )) AS nuevos,
               COUNT(*) AS total
        FROM compradores c
    ");
    $nr->execute([$desde, $hastaExcl, $desde]);
    $data['nuevos_recurrentes'] = $nr->fetch();
    $inv = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE estado != 'cancelado' AND cliente_id IS NULL AND created_at >= ? AND created_at < ?");
    $inv->execute([$desde, $hastaExcl]);
    $data['pedidos_invitados'] = (int) $inv->fetchColumn();

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
    $data['diario']     = reporteDiario($pdo, $desde, $hasta, $granularidad);
    $data['por_estado'] = reportePorEstado($pdo, $desde, $hastaExcl);

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
    $data['top10']         = reporteTopProductos($pdo, $desde, $hastaExcl, 10);
    $data['por_categoria'] = reportePorCategoria($pdo, $desde, $hastaExcl);

    $stockBajoStmt = $pdo->prepare("
        SELECT nombre, codigo, stock FROM productos
        WHERE activo = TRUE AND stock <= 5 AND tienda_id = ? ORDER BY stock ASC
    ");
    $stockBajoStmt->execute([TIENDA_ID]);
    $data['stock_bajo'] = $stockBajoStmt->fetchAll();

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
            fputcsv($out, ['Fecha', 'Pedidos válidos', 'Pedidos cancelados', 'Ingresos (S/)']);
            foreach ($data['diario'] as $r) {
                fputcsv($out, [$r['dia'], $r['pedidos'], $r['cancelados'], number_format($r['ingresos'], 2, '.', '')]);
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
$pagoLabels   = ['billetera' => 'Billetera digital', 'pos' => 'POS', 'efectivo' => 'Efectivo', 'yape' => 'Yape', 'plin' => 'Plin', 'transferencia' => 'Transferencia'];

// ── Gráficos (Chart.js) ──────────────────────────────────────
// Cada gráfico se describe aquí como datos; el JS del final los dibuja
// sobre <canvas id="..."> . Sin datos, se muestra un estado vacío en su lugar.
$repCharts = [];

// Variación vs periodo anterior: [texto, 'up'|'down'|'flat']
function repDelta(float $actual, float $anterior): array {
    if (abs($anterior) < 0.00001) return $actual > 0 ? ['nuevo', 'up'] : ['sin cambios', 'flat'];
    $pct = ($actual - $anterior) / $anterior * 100;
    $txt = ($pct >= 0 ? '▲ +' : '▼ ') . number_format(abs($pct) < 10 ? $pct : round($pct), abs($pct) < 10 ? 1 : 0) . '%';
    return [$txt, $pct > 0.05 ? 'up' : ($pct < -0.05 ? 'down' : 'flat')];
}
function repTieneDatos(array $valores): bool {
    foreach ($valores as $v) if ((float) $v != 0) return true;
    return false;
}
function repLabelCorto(string $s, int $max = 38): string {
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}
// Lienzo del gráfico o estado vacío.
function repCanvas(string $id, bool $hayDatos, int $alto = 280, string $vacio = 'Sin datos en este periodo'): string {
    if (!$hayDatos) return '<div class="empty-state" style="padding:36px 10px"><i class="fas fa-chart-simple"></i><p>' . $vacio . '</p></div>';
    return '<div class="rep-chart" style="height:' . $alto . 'px"><canvas id="' . $id . '"></canvas></div>';
}

if (isset($data['diario'])) {
    $repCharts['c_diario'] = [
        'type'     => 'combo',
        'labels'   => array_map(fn($d) => repEtiquetaTramo($d['dia'], $granularidad), $data['diario']),
        'ingresos' => array_map(fn($d) => round((float) $d['ingresos'], 2), $data['diario']),
        'pedidos'  => array_map(fn($d) => (int) $d['pedidos'], $data['diario']),
    ];
}
if (isset($data['por_estado'])) {
    $repCharts['c_estado'] = [
        'type'   => 'doughnut',
        'labels' => array_map(fn($e) => $estadoLabels[$e['estado']] ?? ucfirst($e['estado']), $data['por_estado']),
        'values' => array_map(fn($e) => (int) $e['n'], $data['por_estado']),
        'extra'  => array_map(fn($e) => (float) $e['total'], $data['por_estado']),
        'colors' => array_map(fn($e) => $estadoColors[$e['estado']] ?? '#94a3b8', $data['por_estado']),
    ];
}
if (isset($data['top10'])) {
    $repCharts['c_top10'] = [
        'type'   => 'hbar', 'unit' => 'unidades',
        'labels' => array_map(fn($p) => repLabelCorto($p['producto_nombre']), $data['top10']),
        'values' => array_map(fn($p) => (int) $p['unidades'], $data['top10']),
    ];
}
if (isset($data['por_categoria'])) {
    $cats = array_slice($data['por_categoria'], 0, 10);
    $repCharts['c_categoria'] = [
        'type'   => 'hbar', 'unit' => 'money',
        'labels' => array_map(fn($c) => repLabelCorto($c['categoria']), $cats),
        'values' => array_map(fn($c) => round((float) $c['ingresos'], 2), $cats),
    ];
}
?>

<style>
.report-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:22px; flex-wrap:wrap; }
.report-tab { padding:10px 16px; font-size:.85rem; font-weight:600; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:6px; }
.report-tab:hover { color:var(--primary); }
.report-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
.report-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }

/* Franja compacta de cifras (Resumen) */
.rep-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
.rep-kpi { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px 14px 8px; display:flex; flex-direction:column; min-width:0; }
.rep-kpi__label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:var(--text-muted); }
.rep-kpi__row { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-top:2px; }
.rep-kpi__value { font-size:1.35rem; font-weight:800; color:var(--text); white-space:nowrap; }
.rep-kpi__delta { font-size:.74rem; font-weight:700; padding:1px 7px; border-radius:20px; white-space:nowrap; }
.rep-kpi__delta.up   { color:#166534; background:var(--success-bg); }
.rep-kpi__delta.down { color:#991b1b; background:var(--danger-bg); }
.rep-kpi__delta.flat { color:var(--text-muted); background:var(--surface-2); }
.rep-kpi__prev { font-size:.72rem; color:var(--text-muted); }
.rep-kpi__spark { height:34px; margin-top:6px; }

/* Gráficos */
.rep-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; margin-bottom:18px; }
.rep-grid .card { margin:0; min-width:0; }
.rep-grid .rep-wide { grid-column:1 / -1; }
.rep-grid--3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
.rep-chart { position:relative; width:100%; }
.rep-card-head { display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.rep-card-head .card-title { margin-bottom:0; }
.rep-card-total { font-size:.82rem; color:var(--text-muted); }
.rep-card-total strong { color:var(--text); }
@media (max-width:1000px) { .rep-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .rep-grid, .rep-grid--3 { grid-template-columns:1fr; } }
@media (max-width:520px)  { .rep-kpis { grid-template-columns:1fr; } }
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

<?php if ($tab === 'resumen'):
    $k = $data['kpis']; $kp = $data['kpis_prev']; $dia = $data['diario'];
    $kpiDefs = [
        // [etiqueta, valor actual, valor anterior, formato, serie diaria]
        ['Ingresos',               (float) $k['ingresos'],            (float) $kp['ingresos'],            'money', array_map(fn($d) => round((float) $d['ingresos'], 2), $dia)],
        ['Pedidos válidos',        (int) $k['pedidos'],               (int) $kp['pedidos'],               'int',   array_map(fn($d) => (int) $d['pedidos'], $dia)],
        ['Ticket promedio',        (float) $k['ticket_promedio'],     (float) $kp['ticket_promedio'],     'money', array_map(fn($d) => $d['pedidos'] > 0 ? round($d['ingresos'] / $d['pedidos'], 2) : 0, $dia)],
        ['Clientes que compraron', (int) $k['clientes_compraron'],    (int) $kp['clientes_compraron'],    'int',   array_map(fn($d) => (int) $d['clientes'], $dia)],
    ];
    $fmt = fn($v, $f) => $f === 'money' ? 'S/ ' . number_format($v, 2) : number_format($v);

    $nr = $data['nuevos_recurrentes'];
    $nuevos = (int) $nr['nuevos']; $recurrentes = (int) $nr['total'] - $nuevos;
    $repCharts['c_clientes'] = ['type' => 'doughnut', 'labels' => ['Nuevos', 'Recurrentes'], 'values' => [$nuevos, $recurrentes], 'colors' => ['#8b5cf6', '#0ea5e9']];
    $pts = $data['puntos'];
    $repCharts['c_puntos'] = [
        'type' => 'bars', 'unit' => 'puntos',
        'labels' => ['Otorgados', 'Canjeados', 'Revertidos'],
        'values' => [(int) $pts['otorgados'], (int) $pts['canjeados'], (int) $pts['revertidos']],
        'colors' => ['#f59e0b', '#10b981', '#ef4444'],
    ];
?>

<div class="rep-kpis">
    <?php foreach ($kpiDefs as $i => [$label, $actual, $anterior, $f, $serie]): [$dTxt, $dCls] = repDelta($actual, $anterior);
        $repCharts['spark_' . $i] = ['type' => 'spark', 'values' => $serie]; ?>
    <div class="rep-kpi">
        <div class="rep-kpi__label"><?= $label ?></div>
        <div class="rep-kpi__row">
            <span class="rep-kpi__value"><?= $fmt($actual, $f) ?></span>
            <span class="rep-kpi__delta <?= $dCls ?>" title="Comparado con <?= date('d/m/Y', strtotime($prevDesde)) ?> – <?= date('d/m/Y', strtotime($prevHastaExcl . ' -1 day')) ?>"><?= $dTxt ?></span>
        </div>
        <div class="rep-kpi__prev">Periodo anterior: <?= $fmt($anterior, $f) ?></div>
        <div class="rep-kpi__spark"><canvas id="spark_<?= $i ?>"></canvas></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="rep-grid">
    <div class="card rep-wide">
        <div class="rep-card-head">
            <div class="card-title"><i class="fas fa-chart-column" style="color:var(--primary)"></i> Ingresos y pedidos por <?= $granLabel ?></div>
            <div class="rep-card-total"><strong>S/ <?= number_format($k['ingresos'], 2) ?></strong> · <?= (int) $k['pedidos'] ?> pedidos · <?= (int) $k['cancelados'] ?> cancelados</div>
        </div>
        <?= repCanvas('c_diario', repTieneDatos($repCharts['c_diario']['pedidos']), 300, 'Sin ventas en este periodo') ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-fire" style="color:var(--primary)"></i> Top 10 productos más vendidos</div>
        <?= repCanvas('c_top10', (bool) $data['top10'], 320) ?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-tags" style="color:var(--primary)"></i> Ventas por categoría</div>
        <?= repCanvas('c_categoria', (bool) $data['por_categoria'], 320) ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-circle-notch" style="color:var(--primary)"></i> Pedidos por estado</div>
        <?= repCanvas('c_estado', (bool) $data['por_estado'], 260) ?>
    </div>
    <div class="card">
        <div class="rep-card-head">
            <div class="card-title"><i class="fas fa-users" style="color:var(--primary)"></i> Clientes nuevos vs recurrentes</div>
            <div class="rep-card-total"><?= (int) $data['clientes_nuevos'] ?> registros nuevos<?= $data['pedidos_invitados'] ? ' · ' . $data['pedidos_invitados'] . ' pedidos de invitados' : '' ?></div>
        </div>
        <?= repCanvas('c_clientes', $nr['total'] > 0, 260, 'Ningún cliente registrado compró en este periodo') ?>
    </div>

    <div class="card rep-wide">
        <div class="rep-card-head">
            <div class="card-title"><i class="fas fa-star" style="color:var(--primary)"></i> Programa de puntos</div>
            <div class="rep-card-total"><strong><?= (int) $data['cupones_generados'] ?></strong> cupones generados · <strong><?= (int) $data['cupones_usados'] ?></strong> usados</div>
        </div>
        <?= repCanvas('c_puntos', repTieneDatos($repCharts['c_puntos']['values']), 200, 'Sin movimientos de puntos en este periodo') ?>
    </div>
</div>

<p style="color:var(--text-muted);font-size:.85rem">
    Del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>, comparado con el
    <?= date('d/m/Y', strtotime($prevDesde)) ?> – <?= date('d/m/Y', strtotime($prevHastaExcl . ' -1 day')) ?> (<?= $diasPeriodo ?> día<?= $diasPeriodo === 1 ? '' : 's' ?>).
</p>

<?php elseif ($tab === 'ventas'):
    $paleta = ['#7c3aed', '#0e7490', '#16a34a', '#f59e0b', '#ef4444', '#3b82f6', '#64748b'];
    $repCharts['c_pago'] = [
        'type'   => 'doughnut',
        'labels' => array_map(fn($m) => $pagoLabels[strtolower($m['metodo_pago'])] ?? ucfirst($m['metodo_pago']), $data['por_pago']),
        'values' => array_map(fn($m) => (int) $m['n'], $data['por_pago']),
        'extra'  => array_map(fn($m) => (float) $m['total'], $data['por_pago']),
        'colors' => array_map(fn($m, $i) => $pagoColors[strtolower($m['metodo_pago'])] ?? $paleta[$i % count($paleta)], $data['por_pago'], array_keys($data['por_pago'])),
    ];
    $repCharts['c_entrega'] = [
        'type'   => 'doughnut',
        'labels' => array_map(fn($e) => $e['tipo_entrega'] === 'recojo' ? 'Recojo en tienda' : 'Delivery', $data['por_entrega']),
        'values' => array_map(fn($e) => (int) $e['n'], $data['por_entrega']),
        'extra'  => array_map(fn($e) => (float) $e['total'], $data['por_entrega']),
        'colors' => array_map(fn($e) => $e['tipo_entrega'] === 'recojo' ? '#0ea5e9' : '#dc2626', $data['por_entrega']),
    ];
    $totIng = array_sum(array_column($data['diario'], 'ingresos'));
    $totPed = array_sum(array_column($data['diario'], 'pedidos'));
    $totCan = array_sum(array_column($data['diario'], 'cancelados'));
?>

<div class="rep-grid rep-grid--3">
    <div class="card rep-wide">
        <div class="rep-card-head">
            <div class="card-title"><i class="fas fa-chart-column" style="color:var(--primary)"></i> Ingresos y pedidos por <?= $granLabel ?></div>
            <div class="rep-card-total">Total: <strong>S/ <?= number_format($totIng, 2) ?></strong> · <?= $totPed ?> pedidos · <?= $totCan ?> cancelados</div>
        </div>
        <?= repCanvas('c_diario', repTieneDatos($repCharts['c_diario']['pedidos']), 320, 'Sin ventas en este periodo') ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-circle-notch" style="color:var(--primary)"></i> Por estado</div>
        <?= repCanvas('c_estado', (bool) $data['por_estado'], 260) ?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-credit-card" style="color:var(--primary)"></i> Por método de pago</div>
        <?= repCanvas('c_pago', (bool) $data['por_pago'], 260) ?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-truck" style="color:var(--primary)"></i> Por tipo de entrega</div>
        <?= repCanvas('c_entrega', (bool) $data['por_entrega'], 260) ?>
    </div>
</div>

<?php elseif ($tab === 'productos'): ?>

<div class="rep-grid">
    <div class="card">
        <div class="card-title"><i class="fas fa-fire" style="color:var(--primary)"></i> Top 10 productos más vendidos</div>
        <?= repCanvas('c_top10', (bool) $data['top10'], 340) ?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-tags" style="color:var(--primary)"></i> Ventas por categoría</div>
        <?= repCanvas('c_categoria', (bool) $data['por_categoria'], 340) ?>
    </div>
</div>

<?php if ($data['stock_bajo']): $nStock = count($data['stock_bajo']); ?>
<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i> <?= number_format($nStock) ?> producto(s) con stock bajo (≤5 unidades):
    <?= implode(', ', array_map(fn($p) => htmlspecialchars($p['nombre']) . ' (' . $p['stock'] . ')', array_slice($data['stock_bajo'], 0, 25))) ?>
    <?= $nStock > 25 ? ' y ' . number_format($nStock - 25) . ' más. <a href="' . BASE_URL . '/admin/productos.php">Ver en Productos</a>' : '' ?>
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

<?php if ($repCharts): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" integrity="sha384-bs/nf9FbdNouRbMiFcrcZfLXYPKiPaGVGplVbv7dLGECccEXDW+S3zjqSKR5ZEaD" crossorigin="anonymous"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return; // sin CDN: la página sigue mostrando cifras y tablas
    const SPECS = <?= json_encode($repCharts, JSON_UNESCAPED_UNICODE) ?>;
    const css = getComputedStyle(document.documentElement);
    const v = (n, d) => (css.getPropertyValue(n) || '').trim() || d;
    const PRIMARY = v('--primary', '#dc2626'), MUTED = v('--text-muted', '#64748b'), BORDER = v('--border', '#e2e8f0');
    const alpha = (hex, a) => { const m = hex.replace('#', '').match(/^([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
        return m ? `rgba(${parseInt(m[1], 16)},${parseInt(m[2], 16)},${parseInt(m[3], 16)},${a})` : hex; };
    const money = n => 'S/ ' + Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num = n => Number(n).toLocaleString('es-PE');

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = MUTED;
    Chart.defaults.borderColor = BORDER;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const builders = {
        // Barras de ingresos + línea de pedidos (eje derecho)
        combo: s => ({
            data: { labels: s.labels, datasets: [
                { type: 'bar', label: 'Ingresos', data: s.ingresos, backgroundColor: alpha(PRIMARY, .75), borderRadius: 4, yAxisID: 'y', order: 2 },
                { type: 'line', label: 'Pedidos', data: s.pedidos, borderColor: '#0ea5e9', backgroundColor: '#0ea5e9', tension: .3, pointRadius: s.labels.length > 45 ? 0 : 3, yAxisID: 'y1', order: 1 },
            ] },
            options: {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 12 } },
                    y:  { beginAtZero: true, ticks: { callback: n => 'S/ ' + num(n) } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
                },
                plugins: { tooltip: { callbacks: { label: c => c.dataset.yAxisID === 'y' ? ' Ingresos: ' + money(c.raw) : ' Pedidos: ' + num(c.raw) } } },
            },
        }),
        // Barras horizontales (ranking)
        hbar: s => ({
            type: 'bar',
            data: { labels: s.labels, datasets: [{ data: s.values, backgroundColor: alpha(PRIMARY, .8), borderRadius: 4, maxBarThickness: 22 }] },
            options: {
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, ticks: { callback: n => s.unit === 'money' ? 'S/ ' + num(n) : num(n), precision: 0 } }, y: { grid: { display: false } } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + (s.unit === 'money' ? money(c.raw) : num(c.raw) + ' ' + s.unit) } } },
            },
        }),
        // Barras verticales con un color por barra
        bars: s => ({
            type: 'bar',
            data: { labels: s.labels, datasets: [{ data: s.values, backgroundColor: s.colors, borderRadius: 6, maxBarThickness: 60 }] },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + num(c.raw) + ' ' + s.unit } } },
            },
        }),
        doughnut: s => ({
            type: 'doughnut',
            data: { labels: s.labels, datasets: [{ data: s.values, backgroundColor: s.colors, borderWidth: 2, borderColor: v('--surface', '#fff') }] },
            options: {
                cutout: '62%',
                plugins: {
                    legend: { position: 'right' },
                    tooltip: { callbacks: { label: c => {
                        const total = c.dataset.data.reduce((a, b) => a + b, 0) || 1;
                        let t = ` ${c.label}: ${num(c.raw)} (${Math.round(c.raw / total * 100)}%)`;
                        if (s.extra) t += ' · ' + money(s.extra[c.dataIndex]);
                        return t;
                    } } },
                },
            },
        }),
        // Mini línea de tendencia (franja de cifras)
        spark: s => ({
            type: 'line',
            data: { labels: s.values.map((_, i) => i), datasets: [{ data: s.values, borderColor: PRIMARY, backgroundColor: alpha(PRIMARY, .12), fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
            options: { scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
                       plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: false, events: [] },
        }),
    };

    Object.entries(SPECS).forEach(([id, spec]) => {
        const canvas = document.getElementById(id);
        if (!canvas || !builders[spec.type]) return;
        const cfg = builders[spec.type](spec);
        cfg.type = cfg.type || 'bar';
        new Chart(canvas, cfg);
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
