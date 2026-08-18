<?php
$adminPage  = 'pedidos';
$adminTitle = 'Pedidos';
require_once __DIR__ . '/includes/header.php';


$pdo = getDB();
$filtroEstado = $_GET['estado'] ?? '';
$filtroQ      = trim($_GET['q'] ?? '');

$where = '1=1';
$params = [];
if ($filtroEstado) { $where .= ' AND p.estado = :estado'; $params['estado'] = $filtroEstado; }
if ($filtroQ) {
    $where .= ' AND (p.codigo ILIKE :q OR p.cliente_nombre ILIKE :q OR c.nombre ILIKE :q OR c.email ILIKE :q)';
    $params['q'] = "%$filtroQ%";
}

$pedidos = $pdo->prepare("
    SELECT p.*,
           COALESCE(c.nombre, p.cliente_nombre) AS cliente_nombre,
           c.email AS cliente_email,
           COALESCE(c.celular, p.cliente_celular) AS cliente_celular
    FROM pedidos p
    LEFT JOIN clientes c ON c.id = p.cliente_id
    WHERE $where
    ORDER BY p.created_at DESC
    LIMIT 100
");
$pedidos->execute($params);
$pedidos = $pedidos->fetchAll();

$estados = ['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-shopping-bag"></i> Pedidos</h1>
</div>

<!-- Filtros -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">
    <input type="text" id="f-q" class="form-control" placeholder="Buscar código, cliente..." value="<?= htmlspecialchars($filtroQ) ?>" style="width:220px" onkeydown="if(event.key==='Enter')filtrar()">
    <select id="f-estado" class="form-control" style="width:160px" onchange="filtrar()">
        <option value="">Todos los estados</option>
        <?php foreach ($estados as $k => $v): ?>
        <option value="<?= $k ?>" <?= $filtroEstado === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <button onclick="filtrar()" class="btn btn-primary"><i class="fas fa-search"></i> Buscar</button>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Código</th>
                <th>Cliente</th>
                <th>Celular</th>
                <th>Total</th>
                <th>Pago</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla-pedidos">
        <?php if (!$pedidos): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:40px">Sin resultados</td></tr>
        <?php endif; ?>
        <?php foreach ($pedidos as $p): ?>
        <tr id="row-<?= $p['id'] ?>">
            <td><span style="font-family:monospace;font-weight:700;color:var(--primary)"><?= htmlspecialchars($p['codigo']) ?></span></td>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($p['cliente_nombre']) ?></div>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($p['cliente_email'] ?? 'Sin cuenta (invitado)') ?></div>
            </td>
            <td><?= htmlspecialchars($p['cliente_celular']) ?></td>
            <td style="font-weight:700">S/ <?= number_format($p['total'], 2) ?></td>
            <td><span style="text-transform:uppercase;font-size:.78rem;font-weight:700"><?= htmlspecialchars($p['metodo_pago']) ?></span></td>
            <td>
                <select class="form-control" style="padding:4px 8px;font-size:.8rem;width:auto"
                        onchange="cambiarEstado(<?= $p['id'] ?>, this.value)">
                    <?php foreach ($estados as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="color:var(--text-muted);font-size:.85rem"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
            <td>
                <button onclick="verDetalle(<?= $p['id'] ?>)" class="btn btn-secondary" style="padding:5px 10px;font-size:.78rem">
                    <i class="fas fa-eye"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal detalle -->
<div id="modal-detalle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:var(--radius-lg);padding:28px;max-width:500px;width:90%;max-height:80vh;overflow-y:auto">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h3 style="font-weight:700">Detalle del pedido</h3>
            <button onclick="document.getElementById('modal-detalle').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--text-muted)"><i class="fas fa-times"></i></button>
        </div>
        <div id="modal-content"></div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function filtrar() {
    const q = document.getElementById('f-q').value;
    const e = document.getElementById('f-estado').value;
    window.location = `${window.BASE_URL}/admin/pedidos.php?q=${encodeURIComponent(q)}&estado=${e}`;
}

async function cambiarEstado(id, estado) {
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=cambiar_estado', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, estado })
    });
    const data = await res.json();
    if (data.success) {
        showToast('Estado actualizado', 'success');
        if (data.facturacion && !data.facturacion.skipped) {
            if (data.facturacion.success) showToast('Comprobante emitido: ' + data.facturacion.comprobante_numero, 'success');
            else showToast('Error al emitir comprobante: ' + data.facturacion.error, 'error');
        }
    } else {
        showToast('Error: ' + data.error, 'error');
    }
}

async function verDetalle(id) {
    document.getElementById('modal-detalle').style.display = 'flex';
    document.getElementById('modal-content').innerHTML = '<div class="loading-overlay"><div class="spinner"></div></div>';
    const res = await fetch(window.BASE_URL + '/admin/api.php?action=detalle_pedido&id=' + id);
    const data = await res.json();
    if (!data.success) { document.getElementById('modal-content').innerHTML = 'Error'; return; }
    const p = data.pedido;
    const det = data.detalles;
    document.getElementById('modal-content').innerHTML = `
        <div style="font-family:monospace;font-weight:700;color:var(--primary);font-size:1.1rem;margin-bottom:12px">${p.codigo}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.88rem;margin-bottom:16px">
            <div><b>Cliente:</b> ${p.cliente_nombre}</div>
            <div><b>Celular:</b> ${p.cliente_celular}</div>
            ${p.cliente_dni ? `<div><b>DNI:</b> ${p.cliente_dni}</div>` : ''}
            <div><b>Email:</b> ${p.cliente_email || 'Sin cuenta (invitado)'}</div>
            <div><b>Pago:</b> ${(p.metodo_pago||'').toUpperCase()}</div>
            <div><b>Envío:</b> ${p.empresa_envio_nombre || 'Sin envío'}</div>
        </div>
        ${p.direccion_entrega ? `<div style="font-size:.85rem;margin-bottom:12px"><i class="fas fa-map-marker-alt"></i> ${p.direccion_entrega}</div>` : ''}
        <div style="border-top:1px solid var(--border);padding-top:12px">
            ${det.map(d => `<div style="display:flex;justify-content:space-between;font-size:.88rem;padding:5px 0;border-bottom:1px solid var(--border)">
                <span>${d.producto_nombre} <span style="color:var(--text-muted)">x${d.cantidad}</span></span>
                <span style="font-weight:600">S/ ${parseFloat(d.subtotal).toFixed(2)}</span>
            </div>`).join('')}
            <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:5px 0;color:var(--text-muted)"><span>Envío</span><span>S/ ${parseFloat(p.costo_envio).toFixed(2)}</span></div>
            ${p.cupon_codigo ? `<div style="display:flex;justify-content:space-between;font-size:.85rem;padding:5px 0;color:#16a34a"><span>Cupón (${p.cupon_codigo})</span><span>- S/ ${parseFloat(p.cupon_descuento).toFixed(2)}</span></div>` : ''}
            <div style="display:flex;justify-content:space-between;font-weight:700;font-size:1rem;color:var(--primary);padding:8px 0;border-top:2px solid var(--border)"><span>Total</span><span>S/ ${parseFloat(p.total).toFixed(2)}</span></div>
        </div>
        ${p.puntos_estado === 'otorgado' ? `<div style="margin-top:10px;font-size:.83rem;background:#fef9c3;padding:10px;border-radius:6px"><i class="fas fa-star"></i> ${p.puntos_ganados} puntos otorgados al cliente</div>` : ''}
        ${p.puntos_estado === 'revertido' ? `<div style="margin-top:10px;font-size:.83rem;background:#f1f5f9;padding:10px;border-radius:6px"><i class="fas fa-rotate-left"></i> Puntos revertidos (pedido cancelado)</div>` : ''}
        ${p.notas ? `<div style="margin-top:10px;font-size:.83rem;background:var(--surface-3);padding:10px;border-radius:6px"><i class="fas fa-sticky-note"></i> ${p.notas}</div>` : ''}
        ${p.comprobante_estado === 'emitido' ? `<div style="margin-top:10px;font-size:.83rem;background:#dcfce7;padding:10px;border-radius:6px">
            <i class="fas fa-file-invoice"></i> ${(p.comprobante_tipo||'Comprobante').toUpperCase()} ${p.comprobante_numero}
            ${p.comprobante_url ? ` — <a href="${p.comprobante_url}" target="_blank">Ver</a>` : ''}
        </div>` : ''}
        ${p.comprobante_estado === 'error' ? `<div style="margin-top:10px;font-size:.83rem;background:#fee2e2;padding:10px;border-radius:6px">
            <i class="fas fa-triangle-exclamation"></i> Error al emitir comprobante: ${p.comprobante_error}
        </div>` : ''}
    `;
}
</script>
