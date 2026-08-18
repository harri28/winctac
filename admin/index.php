<?php
$adminPage  = 'dashboard';
$adminTitle = 'Dashboard';

function human_time_diff(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)    return 'hace ' . $diff . 's';
    if ($diff < 3600)  return 'hace ' . floor($diff / 60) . 'min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . 'h';
    return 'hace ' . floor($diff / 86400) . 'd';
}

function trendBadge(float $current, float $prev): string {
    if ($prev <= 0) return '';
    $pct = round(($current - $prev) / $prev * 100, 0);
    if ($pct > 0)  return "<span class='kpi-trend kpi-trend--up'><i class='fas fa-arrow-up'></i> {$pct}%</span>";
    if ($pct < 0)  return "<span class='kpi-trend kpi-trend--down'><i class='fas fa-arrow-down'></i> " . abs($pct) . "%</span>";
    return "<span class='kpi-trend kpi-trend--flat'>sin cambio</span>";
}

require_once __DIR__ . '/includes/header.php';

$pdo = getDB();

// ── Estadísticas generales ──────────────────────────────────
$stats = $pdo->query("
    SELECT
        COUNT(*)                                                                          AS total_pedidos,
        COUNT(*) FILTER (WHERE estado = 'pendiente')                                     AS pendientes,
        COUNT(*) FILTER (WHERE estado = 'confirmado')                                    AS confirmados,
        COUNT(*) FILTER (WHERE estado = 'enviado')                                       AS enviados,
        COUNT(*) FILTER (WHERE estado = 'entregado')                                     AS entregados,
        COUNT(*) FILTER (WHERE estado = 'cancelado')                                     AS cancelados,
        COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE)                          AS hoy,
        COALESCE(SUM(total) FILTER (WHERE DATE(created_at) = CURRENT_DATE
                                    AND estado != 'cancelado'), 0)                       AS ingresos_hoy,
        COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0)                     AS ingresos_total,
        COUNT(DISTINCT cliente_id)                                                       AS clientes_activos
    FROM pedidos
")->fetch();

$totalClientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE activo = TRUE")->fetchColumn();

// ── Mes actual vs anterior ──────────────────────────────────
$mesActual = $pdo->query("
    SELECT
        COUNT(*) AS pedidos_mes,
        COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos_mes,
        COUNT(*) FILTER (WHERE estado = 'cancelado') AS cancelados_mes
    FROM pedidos
    WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', NOW())
")->fetch();

$mesAnterior = $pdo->query("
    SELECT
        COUNT(*) AS pedidos_mes,
        COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos_mes
    FROM pedidos
    WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', NOW() - INTERVAL '1 month')
")->fetch();

// ── Ayer (para comparar con hoy) ────────────────────────────
$ayer = $pdo->query("
    SELECT
        COUNT(*) AS pedidos_ayer,
        COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS ingresos_ayer
    FROM pedidos
    WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day'
")->fetch();

// ── KPIs derivados ──────────────────────────────────────────
$ticketPromedio = $stats['total_pedidos'] > 0
    ? floatval($stats['ingresos_total']) / intval($stats['total_pedidos'])
    : 0;

$tasaEntrega = $stats['total_pedidos'] > 0
    ? round(intval($stats['entregados']) / intval($stats['total_pedidos']) * 100, 1)
    : 0;

// ── Pedidos pendientes ──────────────────────────────────────
$pedidosPendientes = $pdo->query("
    SELECT p.*,
           COALESCE(c.nombre, p.cliente_nombre) AS cliente_nombre,
           COALESCE(c.celular, p.cliente_celular) AS cliente_celular
    FROM pedidos p
    LEFT JOIN clientes c ON c.id = p.cliente_id
    WHERE p.estado = 'pendiente'
    ORDER BY p.created_at DESC
    LIMIT 10
")->fetchAll();

// ── Actividad reciente ──────────────────────────────────────
$ultimosPedidos = $pdo->query("
    SELECT p.*, COALESCE(c.nombre, p.cliente_nombre) AS cliente_nombre
    FROM pedidos p
    LEFT JOIN clientes c ON c.id = p.cliente_id
    WHERE p.estado != 'pendiente'
    ORDER BY p.created_at DESC
    LIMIT 8
")->fetchAll();

// ── Ventas últimos 7 días ───────────────────────────────────
$ventasSemana = $pdo->query("
    SELECT DATE(created_at) AS dia,
           COUNT(*) AS pedidos,
           COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS total
    FROM pedidos
    WHERE created_at >= NOW() - INTERVAL '7 days'
    GROUP BY DATE(created_at)
    ORDER BY dia ASC
")->fetchAll();

// ── Estado de pedidos ───────────────────────────────────────
$estadoStats = $pdo->query("SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado ORDER BY n DESC")->fetchAll();

// ── Métodos de pago ─────────────────────────────────────────
$pagoStats = $pdo->query("
    SELECT metodo_pago,
           COUNT(*) AS n,
           COALESCE(SUM(total) FILTER (WHERE estado != 'cancelado'), 0) AS total_ingresos
    FROM pedidos
    WHERE metodo_pago != ''
    GROUP BY metodo_pago
    ORDER BY n DESC
")->fetchAll();

// ── Top 5 productos ─────────────────────────────────────────
$topProductos = $pdo->query("
    SELECT pd.producto_nombre,
           SUM(pd.cantidad)             AS total_unidades,
           SUM(pd.subtotal)             AS total_ingresos,
           COUNT(DISTINCT pd.pedido_id) AS n_pedidos
    FROM pedido_detalles pd
    JOIN pedidos p ON p.id = pd.pedido_id
    WHERE p.estado != 'cancelado'
    GROUP BY pd.producto_nombre
    ORDER BY total_unidades DESC
    LIMIT 5
")->fetchAll();

// ── Configuración visual ────────────────────────────────────
$estadoColors = [
    'pendiente'  => '#f59e0b',
    'confirmado' => '#3b82f6',
    'enviado'    => '#8b5cf6',
    'entregado'  => '#10b981',
    'cancelado'  => '#ef4444',
];
$estadoLabels = [
    'pendiente'  => 'Pendiente',
    'confirmado' => 'Confirmado',
    'enviado'    => 'Enviado',
    'entregado'  => 'Entregado',
    'cancelado'  => 'Cancelado',
];
$pagoColors = [
    'billetera' => '#7c3aed',
    'pos'       => '#0e7490',
    'efectivo'  => '#16a34a',
];
?>

<!-- Top bar -->
<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title">Dashboard</h1>
        <p class="admin-page-date"><?= date('l, d \d\e F Y') ?></p>
    </div>
    <a href="<?= BASE_URL ?>/admin/pedidos.php?estado=pendiente" class="btn btn-primary">
        <i class="fas fa-clock"></i> Ver pendientes
        <?php if ($stats['pendientes'] > 0): ?>
        <span class="btn-badge"><?= $stats['pendientes'] ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Quick nav -->
<div class="quick-nav">
    <a href="<?= BASE_URL ?>/admin/pedidos.php" class="quick-nav__item"><i class="fas fa-shopping-bag"></i> Pedidos</a>
    <a href="<?= BASE_URL ?>/admin/productos.php" class="quick-nav__item"><i class="fas fa-box"></i> Productos</a>
    <a href="<?= BASE_URL ?>/admin/clientes.php" class="quick-nav__item"><i class="fas fa-users"></i> Clientes</a>
    <a href="<?= BASE_URL ?>/admin/pedidos.php?estado=enviado" class="quick-nav__item"><i class="fas fa-truck"></i> En camino</a>
    <a href="<?= BASE_URL ?>/admin/config.php" class="quick-nav__item"><i class="fas fa-cog"></i> Configuración</a>
</div>

<!-- KPIs principales -->
<div class="stats-grid dash-kpi-grid" style="margin-bottom:16px">
    <div class="stat-card" style="border-top:3px solid #f59e0b">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--amber"><i class="fas fa-clock"></i></div>
            <div>
                <div class="stat-label">Pendientes</div>
                <div class="stat-value" style="color:#f59e0b"><?= $stats['pendientes'] ?></div>
                <div class="stat-sub">esperando confirmación</div>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--primary)">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--blue"><i class="fas fa-calendar-day"></i></div>
            <div>
                <div class="stat-label">Ventas hoy</div>
                <div class="stat-value" style="color:var(--primary)"><?= $stats['hoy'] ?></div>
                <div class="stat-sub">S/ <?= number_format($stats['ingresos_hoy'], 2) ?> ingresado</div>
                <?= trendBadge(floatval($stats['hoy']), floatval($ayer['pedidos_ayer'])) ?>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #10b981">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#d1fae5;color:#10b981"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="stat-label">Ingresos del mes</div>
                <div class="stat-value stat-value--md" style="color:#10b981">S/ <?= number_format($mesActual['ingresos_mes'], 0) ?></div>
                <div class="stat-sub"><?= $mesActual['pedidos_mes'] ?> pedidos este mes</div>
                <?= trendBadge(floatval($mesActual['ingresos_mes']), floatval($mesAnterior['ingresos_mes'])) ?>
            </div>
        </div>
    </div>
    <div class="stat-card" style="border-top:3px solid #8b5cf6">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--purple"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-label">Clientes registrados</div>
                <div class="stat-value" style="color:#8b5cf6"><?= $totalClientes ?></div>
                <div class="stat-sub"><?= $stats['clientes_activos'] ?> con pedidos</div>
            </div>
        </div>
    </div>
</div>

<!-- KPIs secundarios -->
<div class="stats-grid dash-kpi-grid" style="margin-bottom:28px">
    <div class="stat-card stat-card--secondary">
        <div class="stat-card-inner">
            <div class="stat-icon stat-icon--green"><i class="fas fa-coins"></i></div>
            <div>
                <div class="stat-label">Ingresos totales</div>
                <div class="stat-value stat-value--md" style="color:var(--success)">S/ <?= number_format($stats['ingresos_total'], 0) ?></div>
                <div class="stat-sub"><?= $stats['total_pedidos'] ?> pedidos históricos</div>
            </div>
        </div>
    </div>
    <div class="stat-card stat-card--secondary">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-label">Ticket promedio</div>
                <div class="stat-value stat-value--md" style="color:#d97706">S/ <?= number_format($ticketPromedio, 2) ?></div>
                <div class="stat-sub">por pedido</div>
            </div>
        </div>
    </div>
    <div class="stat-card stat-card--secondary">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#e0f2fe;color:#0284c7"><i class="fas fa-truck"></i></div>
            <div>
                <div class="stat-label">Tasa de entrega</div>
                <div class="stat-value stat-value--md" style="color:#0284c7"><?= $tasaEntrega ?>%</div>
                <div class="stat-sub"><?= $stats['entregados'] ?> entregados</div>
            </div>
        </div>
    </div>
    <div class="stat-card stat-card--secondary">
        <div class="stat-card-inner">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fas fa-ban"></i></div>
            <div>
                <div class="stat-label">Cancelados este mes</div>
                <div class="stat-value stat-value--md" style="color:#dc2626"><?= $mesActual['cancelados_mes'] ?></div>
                <div class="stat-sub"><?= $stats['cancelados'] ?> total histórico</div>
            </div>
        </div>
    </div>
</div>

<!-- Gráficas: 3 columnas -->
<div class="dash-charts-row dash-charts-row--3">

    <!-- Ventas últimos 7 días -->
    <div class="card">
        <div class="card-title"><i class="fas fa-chart-bar" style="color:var(--primary)"></i> Ventas — últimos 7 días</div>
        <?php if (!$ventasSemana): ?>
        <div class="empty-state" style="padding:24px"><i class="fas fa-chart-bar"></i><p>Sin ventas esta semana</p></div>
        <?php else:
            $maxTotal = max(array_column($ventasSemana, 'total') ?: [1]);
        ?>
        <div class="bar-chart">
            <?php foreach ($ventasSemana as $d):
                $h = $maxTotal > 0 ? max(8, round(floatval($d['total']) / $maxTotal * 100)) : 8;
            ?>
            <div class="bar-col">
                <span class="bar-col__amount">S/<?= number_format($d['total'], 0) ?></span>
                <div class="bar-col__fill" style="height:<?= $h ?>px"></div>
                <span class="bar-col__label"><?= date('d/m', strtotime($d['dia'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-summary">
            <span style="color:var(--text-muted)">Total semana:</span>
            <strong style="color:var(--primary)">S/ <?= number_format(array_sum(array_column($ventasSemana, 'total')), 2) ?></strong>
            <span style="color:var(--text-muted)"><?= array_sum(array_column($ventasSemana, 'pedidos')) ?> pedidos</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Estado de pedidos -->
    <div class="card">
        <div class="card-title"><i class="fas fa-circle-notch" style="color:var(--primary)"></i> Estado de pedidos</div>
        <?php if (!$estadoStats): ?>
        <div class="empty-state" style="padding:20px"><p>Sin pedidos aún</p></div>
        <?php else:
            $totalEstados = array_sum(array_column($estadoStats, 'n')) ?: 1;
        ?>
        <div class="status-bars">
            <?php foreach ($estadoStats as $es):
                $pct   = round($es['n'] / $totalEstados * 100);
                $color = $estadoColors[$es['estado']] ?? '#94a3b8';
                $label = $estadoLabels[$es['estado']] ?? $es['estado'];
            ?>
            <div class="status-item">
                <div class="status-item__header">
                    <span class="status-item__name"><?= $label ?></span>
                    <span class="status-item__count"><?= $es['n'] ?> (<?= $pct ?>%)</span>
                </div>
                <div class="status-item__track">
                    <div class="status-item__fill" style="background:<?= $color ?>;width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Métodos de pago -->
    <div class="card">
        <div class="card-title"><i class="fas fa-credit-card" style="color:var(--primary)"></i> Métodos de pago</div>
        <?php if (!$pagoStats): ?>
        <div class="empty-state" style="padding:20px"><p>Sin datos aún</p></div>
        <?php else:
            $totalPagos = array_sum(array_column($pagoStats, 'n')) ?: 1;
        ?>
        <div class="status-bars">
            <?php foreach ($pagoStats as $mp):
                $pct   = round($mp['n'] / $totalPagos * 100);
                $color = $pagoColors[strtolower($mp['metodo_pago'])] ?? '#64748b';
            ?>
            <div class="status-item">
                <div class="status-item__header">
                    <span class="status-item__name"><?= htmlspecialchars(ucfirst($mp['metodo_pago'])) ?></span>
                    <span class="status-item__count"><?= $mp['n'] ?> (<?= $pct ?>%)</span>
                </div>
                <div class="status-item__track">
                    <div class="status-item__fill" style="background:<?= $color ?>;width:<?= $pct ?>%"></div>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">
                    S/ <?= number_format($mp['total_ingresos'], 2) ?> recaudado
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Pedidos pendientes -->
<?php if (!$pedidosPendientes): ?>
<div class="table-wrapper dash-no-pending" style="margin-bottom:28px">
    <div class="dash-no-pending__inner">
        <div class="dash-no-pending__icon"><i class="fas fa-check-circle"></i></div>
        <div>
            <p class="dash-no-pending__title">Sin pedidos pendientes</p>
            <p class="dash-no-pending__sub">Todos los pedidos están gestionados</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="table-wrapper" style="margin-bottom:28px">
    <div class="section-bar">
        <h3 class="section-bar__title">
            <i class="fas fa-exclamation-circle" style="color:#f59e0b"></i>
            Pedidos pendientes de confirmación
            <span class="pending-pill"><?= count($pedidosPendientes) ?></span>
        </h3>
        <a href="<?= BASE_URL ?>/admin/pedidos.php?estado=pendiente" class="btn btn-secondary" style="font-size:.82rem">Ver todos</a>
    </div>
    <table class="data-table">
        <thead><tr>
            <th>Código</th><th>Cliente</th><th>Celular</th><th>Total</th><th>Pago</th><th>Hace</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pedidosPendientes as $p): ?>
        <tr id="row-<?= $p['id'] ?>">
            <td><span class="order-code-mono"><?= htmlspecialchars($p['codigo']) ?></span></td>
            <td class="td-bold"><?= htmlspecialchars($p['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($p['cliente_celular']) ?></td>
            <td class="td-bold">S/ <?= number_format($p['total'], 2) ?></td>
            <td><span class="method-badge"><?= htmlspecialchars($p['metodo_pago']) ?></span></td>
            <td class="td-muted"><?= human_time_diff(strtotime($p['created_at'])) ?></td>
            <td>
                <div class="btn-action-group">
                    <button onclick="accion(<?= $p['id'] ?>,'confirmado')" class="btn btn-success" style="padding:5px 10px;font-size:.78rem">
                        <i class="fas fa-check"></i> Confirmar
                    </button>
                    <button onclick="verDetalle(<?= $p['id'] ?>)" class="btn btn-secondary" style="padding:5px 10px;font-size:.78rem">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="accion(<?= $p['id'] ?>,'cancelado')" class="btn btn-danger" style="padding:5px 10px;font-size:.78rem">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Fila inferior: top productos + actividad reciente -->
<div class="dash-bottom-row">

    <!-- Top 5 productos -->
    <div class="table-wrapper">
        <div class="section-bar">
            <h3 class="section-bar__title">
                <i class="fas fa-fire" style="color:#f59e0b"></i>
                Productos más vendidos
            </h3>
        </div>
        <?php if (!$topProductos): ?>
        <div class="empty-state" style="padding:40px">
            <i class="fas fa-box-open"></i><p>Sin ventas registradas</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th style="width:28px">#</th><th>Producto</th><th>Uds</th><th>Ingresos</th>
            </tr></thead>
            <tbody>
            <?php foreach ($topProductos as $i => $prod): ?>
            <tr>
                <td class="td-muted" style="font-weight:700"><?= $i + 1 ?></td>
                <td class="td-bold" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= htmlspecialchars($prod['producto_nombre']) ?>
                </td>
                <td>
                    <span style="background:var(--primary-bg);color:var(--primary);padding:2px 9px;border-radius:20px;font-size:.76rem;font-weight:700">
                        <?= $prod['total_unidades'] ?>
                    </span>
                </td>
                <td class="td-bold" style="color:var(--success)">S/ <?= number_format($prod['total_ingresos'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Actividad reciente -->
    <div class="table-wrapper">
        <div class="section-bar">
            <h3 class="section-bar__title">
                <i class="fas fa-history" style="color:var(--text-muted)"></i>
                Actividad reciente
            </h3>
        </div>
        <?php if (!$ultimosPedidos): ?>
        <div class="empty-state" style="padding:40px">
            <i class="fas fa-inbox"></i><p>Sin actividad</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Código</th><th>Cliente</th><th>Total</th><th>Estado</th>
            </tr></thead>
            <tbody>
            <?php foreach ($ultimosPedidos as $p):
                $color = $estadoColors[$p['estado']] ?? '#94a3b8';
                $label = $estadoLabels[$p['estado']] ?? $p['estado'];
            ?>
            <tr>
                <td><span class="order-code-mono order-code-mono--sm"><?= htmlspecialchars($p['codigo']) ?></span></td>
                <td style="font-size:.85rem"><?= htmlspecialchars($p['cliente_nombre']) ?></td>
                <td class="td-bold">S/ <?= number_format($p['total'], 2) ?></td>
                <td><span class="status-chip" style="background:<?= $color ?>22;color:<?= $color ?>"><?= $label ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<!-- Modal detalle -->
<div class="modal-overlay" id="modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Detalle del pedido</h3>
            <button class="modal-close-btn" onclick="closeModal()">×</button>
        </div>
        <div id="modal-content"></div>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

async function accion(id, estado) {
    if (!confirm(`¿${estado === 'confirmado' ? 'Confirmar' : 'Cancelar'} este pedido?`)) return;
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=cambiar_estado', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, estado})
    });
    const data = await res.json();
    if (data.success) {
        document.getElementById('row-' + id)?.remove();
        showToast('Estado actualizado', 'success');
    } else {
        showToast(data.error || 'Error', 'error');
    }
}

async function verDetalle(id) {
    document.getElementById('modal').classList.add('open');
    document.getElementById('modal-content').innerHTML = '<div class="loading-overlay"><div class="spinner"></div></div>';
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=detalle_pedido&id=' + id);
    const {success, pedido: p, detalles: det} = await res.json();
    if (!success) {
        document.getElementById('modal-content').innerHTML = '<p class="td-muted" style="padding:16px">Error al cargar el pedido.</p>';
        return;
    }
    document.getElementById('modal-content').innerHTML = `
        <div class="order-code" style="display:inline-block;margin-bottom:14px">${p.codigo}</div>
        <div class="order-info-grid">
            <div><span class="order-info-label">Cliente:</span> <b>${p.cliente_nombre}</b></div>
            <div><span class="order-info-label">Celular:</span> ${p.cliente_celular}</div>
            <div><span class="order-info-label">Pago:</span> ${(p.metodo_pago || '').toUpperCase()}</div>
            <div><span class="order-info-label">Envío:</span> ${p.empresa_envio_nombre || 'Sin envío'}</div>
        </div>
        ${p.direccion_entrega ? `<div class="order-address"><i class="fas fa-map-marker-alt"></i> ${p.direccion_entrega}</div>` : ''}
        <div class="order-items">
            ${det.map(d => `
            <div class="order-item-row">
                <span>${d.producto_nombre} <span class="td-muted">×${d.cantidad}</span></span>
                <b>S/ ${parseFloat(d.subtotal).toFixed(2)}</b>
            </div>`).join('')}
            <div class="order-item-row td-muted"><span>Envío</span><span>S/ ${parseFloat(p.costo_envio).toFixed(2)}</span></div>
            <div class="order-total-row"><span>Total</span><span>S/ ${parseFloat(p.total).toFixed(2)}</span></div>
        </div>
        ${p.notas ? `<div class="order-notes"><i class="fas fa-sticky-note"></i> ${p.notas}</div>` : ''}
    `;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
