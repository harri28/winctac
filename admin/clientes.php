<?php
$adminPage  = 'clientes';
$adminTitle = 'Clientes';
require_once __DIR__ . '/includes/header.php';


$pdo = getDB();
$q = trim($_GET['q'] ?? '');

$where = '1=1';
$params = [];
if ($q) {
    $where = 'c.nombre ILIKE :q OR c.email ILIKE :q OR c.celular ILIKE :q OR c.dni ILIKE :q';
    $params['q'] = "%$q%";
}

$clientes = $pdo->prepare("
    SELECT c.*,
           COUNT(p.id)                                                         AS total_pedidos,
           COALESCE(SUM(p.total) FILTER (WHERE p.estado != 'cancelado'), 0)    AS total_gastado,
           MAX(p.created_at)                                                   AS ultimo_pedido
    FROM clientes c
    LEFT JOIN pedidos p ON p.cliente_id = c.id
    WHERE $where
    GROUP BY c.id
    ORDER BY total_gastado DESC
");
$clientes->execute($params);
$clientes = $clientes->fetchAll();

$totals = $pdo->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE activo = TRUE) AS activos
    FROM clientes
")->fetch();
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-users"></i> Clientes</h1>
        <p style="color:var(--text-muted);font-size:.83rem;margin-top:2px">
            <?= $totals['total'] ?> registrados · <?= $totals['activos'] ?> activos
        </p>
    </div>
</div>

<!-- Búsqueda -->
<div style="display:flex;gap:10px;margin-bottom:20px">
    <div style="position:relative;flex:1;max-width:360px">
        <input type="text" id="f-q" class="form-control" value="<?= htmlspecialchars($q) ?>"
               placeholder="Buscar por nombre, email, celular o DNI..."
               onkeydown="if(event.key==='Enter')buscar()"
               style="padding-left:36px">
        <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.85rem"></i>
    </div>
    <button onclick="buscar()" class="btn btn-primary"><i class="fas fa-search"></i> Buscar</button>
    <?php if ($q): ?>
    <a href="<?= BASE_URL ?>/admin/clientes.php" class="btn btn-secondary"><i class="fas fa-times"></i> Limpiar</a>
    <?php endif; ?>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Contacto</th>
                <th>DNI</th>
                <th>Ubicación</th>
                <th>Pedidos</th>
                <th>Total gastado</th>
                <th>Último pedido</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$clientes): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:40px">Sin resultados</td></tr>
        <?php endif; ?>
        <?php foreach ($clientes as $c): ?>
        <tr id="cli-<?= $c['id'] ?>">
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:36px;height:36px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0">
                        <?= strtoupper(substr($c['nombre'], 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:.9rem"><?= htmlspecialchars($c['nombre'] . ' ' . $c['apellidos']) ?></div>
                        <div style="font-size:.75rem;color:var(--text-muted)"><?= htmlspecialchars($c['email']) ?></div>
                    </div>
                </div>
            </td>
            <td style="font-size:.87rem"><?= htmlspecialchars($c['celular'] ?: '—') ?></td>
            <td style="font-family:monospace;font-size:.85rem"><?= htmlspecialchars($c['dni'] ?: '—') ?></td>
            <td style="font-size:.83rem;color:var(--text-muted)">
                <?php $ub = array_filter([$c['distrito'], $c['ciudad']]); echo htmlspecialchars(implode(', ', $ub) ?: '—'); ?>
            </td>
            <td style="text-align:center">
                <span style="background:var(--primary-bg);color:var(--primary);border-radius:20px;padding:2px 10px;font-weight:700;font-size:.82rem">
                    <?= $c['total_pedidos'] ?>
                </span>
            </td>
            <td style="font-weight:700;color:var(--success)">
                <?= $c['total_gastado'] > 0 ? 'S/ ' . number_format($c['total_gastado'], 2) : '—' ?>
            </td>
            <td style="font-size:.82rem;color:var(--text-muted)">
                <?= $c['ultimo_pedido'] ? date('d/m/Y', strtotime($c['ultimo_pedido'])) : '—' ?>
            </td>
            <td>
                <span style="background:<?= $c['activo'] ? 'var(--success-bg)' : 'var(--danger-bg)' ?>;color:<?= $c['activo'] ? 'var(--success)' : 'var(--danger)' ?>;border-radius:20px;padding:2px 10px;font-size:.73rem;font-weight:700">
                    <?= $c['activo'] ? 'Activo' : 'Inactivo' ?>
                </span>
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    <button onclick="verCliente(<?= $c['id'] ?>)" class="btn btn-secondary" style="padding:5px 10px;font-size:.75rem" title="Ver detalle">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="toggleCliente(<?= $c['id'] ?>, <?= $c['activo'] ? 'false' : 'true' ?>)"
                            class="btn <?= $c['activo'] ? 'btn-danger' : 'btn-success' ?>" style="padding:5px 10px;font-size:.75rem"
                            title="<?= $c['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fas fa-<?= $c['activo'] ? 'ban' : 'check' ?>"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal detalle cliente -->
<div id="modal-cli" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:var(--radius-lg);padding:28px;max-width:520px;width:92%;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-weight:700">Detalle del cliente</h3>
            <button onclick="document.getElementById('modal-cli').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--text-muted)">×</button>
        </div>
        <div id="modal-cli-content"></div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
function buscar() {
    window.location = window.BASE_URL + '/admin/clientes.php?q=' + encodeURIComponent(document.getElementById('f-q').value);
}

async function toggleCliente(id, activo) {
    const label = activo ? 'activar' : 'desactivar';
    if (!confirm(`¿${label} este cliente?`)) return;
    const res  = await fetch(window.BASE_URL + '/admin/api.php?action=toggle_cliente', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id, activo: activo === true || activo === 'true'})
    });
    const data = await res.json();
    if (data.success) { showToast('Cliente actualizado', 'success'); setTimeout(() => location.reload(), 700); }
    else showToast(data.error, 'error');
}

async function verCliente(id) {
    document.getElementById('modal-cli').style.display = 'flex';
    document.getElementById('modal-cli-content').innerHTML = '<div class="loading-overlay"><div class="spinner"></div></div>';
    const res  = await fetch(window.BASE_URL + '/admin/api.php?action=detalle_cliente&id=' + id);
    const data = await res.json();
    if (!data.success) return;
    const c = data.cliente;
    const pedidos = data.pedidos;
    const estadoColors = {pendiente:'#f59e0b',confirmado:'#3b82f6',enviado:'#8b5cf6',entregado:'#10b981',cancelado:'#ef4444'};
    document.getElementById('modal-cli-content').innerHTML = `
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding:16px;background:var(--surface-3);border-radius:10px">
            <div style="width:52px;height:52px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;font-weight:700;flex-shrink:0">
                ${c.nombre.charAt(0).toUpperCase()}
            </div>
            <div>
                <div style="font-weight:700;font-size:1rem">${c.nombre} ${c.apellidos||''}</div>
                <div style="font-size:.83rem;color:var(--text-muted)">${c.email}</div>
                <div style="font-size:.83rem;color:var(--text-muted)">${c.celular||''} ${c.dni ? '· DNI: '+c.dni : ''}</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;margin-bottom:16px">
            <div><span style="color:var(--text-muted)">Dirección:</span> ${c.direccion||'—'}</div>
            <div><span style="color:var(--text-muted)">Distrito:</span> ${c.distrito||'—'}</div>
            <div><span style="color:var(--text-muted)">Ciudad:</span> ${c.ciudad||'—'}</div>
            <div><span style="color:var(--text-muted)">Registro:</span> ${new Date(c.created_at).toLocaleDateString('es-PE')}</div>
        </div>
        <div style="font-weight:700;font-size:.85rem;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04rem">Pedidos (${pedidos.length})</div>
        ${pedidos.length ? pedidos.map(p => `
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:.84rem">
                <span style="font-family:monospace;color:var(--primary);font-weight:600;min-width:130px">${p.codigo}</span>
                <span style="flex:1;color:var(--text-muted)">${new Date(p.created_at).toLocaleDateString('es-PE')}</span>
                <span style="background:${(estadoColors[p.estado]||'#94a3b8')}22;color:${estadoColors[p.estado]||'#94a3b8'};padding:1px 8px;border-radius:20px;font-size:.72rem;font-weight:700">${p.estado}</span>
                <span style="font-weight:700">S/ ${parseFloat(p.total).toFixed(2)}</span>
            </div>
        `).join('') : '<div style="color:var(--text-muted);text-align:center;padding:16px">Sin pedidos</div>'}
    `;
}
</script>
