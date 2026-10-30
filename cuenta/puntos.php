<?php
// La auth debe resolverse ANTES de includes/header.php: ese archivo ya
// imprime HTML, así que un header('Location: ...') después fallaría en
// silencio (headers already sent) en vez de redirigir a login.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/puntos.php';

requireClienteAuth();

// Clientes registrados antes de que el DNI fuera obligatorio: lo completan
// aquí para acumular puntos también en la tienda física.
$dniMsg = '';
$dniError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['completar_dni'])) {
    $pdo = getDB();
    $dni = normalizarDocumento($_POST['dni'] ?? '');
    $actual = $pdo->prepare('SELECT dni FROM clientes WHERE id = ?');
    $actual->execute([clienteId()]);
    $existe = $pdo->prepare("SELECT 1 FROM clientes WHERE UPPER(REPLACE(dni, ' ', '')) = ? AND id <> ?");
    $existe->execute([$dni, clienteId()]);
    if (trim((string) $actual->fetchColumn()) !== '') {
        $dniError = 'Tu cuenta ya tiene un DNI registrado.';
    } elseif (!documentoValido($dni)) {
        $dniError = 'Ingresa un DNI de 8 dígitos o un carné de extranjería válido.';
    } elseif ($existe->fetchColumn()) {
        $dniError = 'Ese DNI ya está registrado en otra cuenta.';
    } else {
        $pdo->prepare('UPDATE clientes SET dni = ? WHERE id = ?')->execute([$dni, clienteId()]);
        $acreditados = acreditarComprasPendientes($pdo, clienteId(), $dni);
        $dniMsg = 'DNI registrado. Desde ahora tus compras en tienda física también suman puntos.'
            . ($acreditados ? " Se acreditaron $acreditados puntos de compras anteriores." : '');
    }
}

$pageTitle  = 'Mis Puntos';
$currentPage = 'puntos';
require_once __DIR__ . '/../includes/header.php';

$pdo = getDB();

$saldo = $pdo->prepare('SELECT puntos_saldo, dni FROM clientes WHERE id = ?');
$saldo->execute([clienteId()]);
$clienteRow = $saldo->fetch();
$saldo = (int) $clienteRow['puntos_saldo'];
$clienteDni = trim($clienteRow['dni'] ?? '');
$cfgPuntos = getShopConfig();
$porSol = rtrim(rtrim(number_format((float) ($cfgPuntos['puntos_por_sol'] ?? 1), 2), '0'), '.');

$recompensas = $pdo->query('SELECT * FROM puntos_recompensas WHERE activo = TRUE ORDER BY costo_puntos ASC')->fetchAll();

$cupones = $pdo->prepare("
    SELECT *, CASE WHEN estado = 'activo' AND expira_at IS NOT NULL AND expira_at < NOW()
                   THEN 'vencido' ELSE estado END AS estado_mostrar
    FROM puntos_cupones WHERE cliente_id = ? ORDER BY created_at DESC
");
$cupones->execute([clienteId()]);
$cupones = $cupones->fetchAll();

$movimientos = $pdo->prepare('SELECT * FROM puntos_movimientos WHERE cliente_id = ? ORDER BY created_at DESC LIMIT 50');
$movimientos->execute([clienteId()]);
$movimientos = $movimientos->fetchAll();

$tipoLabel   = ['ganado' => 'Ganado', 'canjeado' => 'Canjeado', 'revertido' => 'Revertido', 'ajuste_admin' => 'Ajuste'];
$estadoLabel = ['activo' => 'Activo', 'usado' => 'Usado', 'vencido' => 'Vencido'];
?>

<div class="page-wrapper">
    <div class="container">
        <div class="section-header" style="margin-bottom:24px">
            <h2 class="section-title"><i class="fas fa-star"></i> Mis Puntos</h2>
            <a href="<?= BASE_URL ?>/cuenta/mis-pedidos" class="btn btn-secondary"><i class="fas fa-list"></i> Mis pedidos</a>
        </div>

        <div class="card" style="margin-bottom:24px;text-align:center;padding:28px">
            <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:6px">Tu saldo actual</div>
            <div style="font-size:2.4rem;font-weight:800;color:var(--primary)"><?= $saldo ?> <span style="font-size:1rem;font-weight:600;color:var(--text-muted)">puntos</span></div>
            <div style="font-size:.85rem;color:var(--text-muted);margin-top:8px">
                Ganas <strong><?= $porSol ?> punto(s) por cada S/ 1</strong> en compras confirmadas de la web y en compras de nuestra tienda física<?= $clienteDni ? ' con tu DNI ' . htmlspecialchars($clienteDni) : '' ?>.
                Canjéalos abajo por cupones de descuento y usa el código en el checkout.
            </div>
        </div>

        <?php if ($dniMsg): ?><div class="alert alert-success"><?= htmlspecialchars($dniMsg) ?></div><?php endif; ?>
        <?php if (!$clienteDni): ?>
        <div class="card" style="margin-bottom:24px;border-left:4px solid var(--primary)">
            <div class="card-title"><i class="fas fa-id-card"></i> Completa tu DNI</div>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px">
                Registra tu DNI para acumular puntos también cuando compres en nuestra tienda física. Si ya compraste antes identificándote con él, esos puntos se acreditarán ahora.
            </p>
            <?php if ($dniError): ?><div class="alert alert-error"><?= htmlspecialchars($dniError) ?></div><?php endif; ?>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap">
                <input type="hidden" name="completar_dni" value="1">
                <input type="text" name="dni" class="form-control" maxlength="15" inputmode="numeric" placeholder="DNI (8 dígitos)" style="max-width:220px" required>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Guardar DNI</button>
            </form>
        </div>
        <?php endif; ?>

        <h3 style="margin-bottom:14px"><i class="fas fa-award"></i> Recompensas disponibles</h3>
        <?php if (!$recompensas): ?>
        <div class="empty-state" style="padding:40px">
            <i class="fas fa-award"></i>
            <h3>Aún no hay recompensas disponibles</h3>
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-bottom:32px">
            <?php foreach ($recompensas as $r): $alcanza = $saldo >= $r['costo_puntos']; ?>
            <div class="card" style="text-align:center">
                <div style="font-size:1.1rem;font-weight:700;margin-bottom:6px">
                    <?= $r['tipo'] === 'porcentaje' ? number_format($r['valor'], 0) . '% de descuento' : 'S/ ' . number_format($r['valor'], 2) . ' de descuento' ?>
                </div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:4px"><?= htmlspecialchars($r['nombre']) ?></div>
                <?php if ($r['compra_minima'] > 0): ?>
                <div style="font-size:.78rem;color:var(--text-muted)">Compra mínima: S/ <?= number_format($r['compra_minima'], 2) ?></div>
                <?php endif; ?>
                <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:14px">
                    <?= $r['vigencia_dias'] > 0 ? 'Cupón válido por ' . $r['vigencia_dias'] . ' días' : 'Cupón sin vencimiento' ?>
                </div>
                <button class="btn <?= $alcanza ? 'btn-primary' : 'btn-secondary' ?> btn-full"
                        <?= $alcanza ? '' : 'disabled' ?> onclick="canjear(<?= $r['id'] ?>, this)">
                    <?= $alcanza ? 'Canjear por ' . $r['costo_puntos'] . ' pts' : 'Te faltan ' . ($r['costo_puntos'] - $saldo) . ' pts' ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h3 style="margin-bottom:14px"><i class="fas fa-ticket"></i> Mis cupones</h3>
        <?php if (!$cupones): ?>
        <div class="empty-state" style="padding:40px">
            <i class="fas fa-ticket"></i>
            <h3>Todavía no tienes cupones</h3>
        </div>
        <?php else: ?>
        <div class="card" style="margin-bottom:32px;overflow-x:auto">
            <table class="data-table">
                <thead><tr><th>Código</th><th>Recompensa</th><th>Estado</th><th>Expira</th></tr></thead>
                <tbody>
                <?php foreach ($cupones as $c): ?>
                <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($c['codigo']) ?></td>
                    <td><?= htmlspecialchars($c['recompensa_nombre']) ?></td>
                    <td><span class="badge badge-<?= $c['estado_mostrar'] === 'activo' ? 'confirmado' : 'cancelado' ?>"><?= $estadoLabel[$c['estado_mostrar']] ?? $c['estado_mostrar'] ?></span></td>
                    <td><?= $c['expira_at'] ? date('d/m/Y', strtotime($c['expira_at'])) : 'No expira' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h3 style="margin-bottom:14px"><i class="fas fa-history"></i> Historial de movimientos</h3>
        <?php if (!$movimientos): ?>
        <div class="empty-state" style="padding:40px">
            <i class="fas fa-history"></i>
            <h3>Todavía no tienes movimientos</h3>
        </div>
        <?php else: ?>
        <div class="card" style="overflow-x:auto">
            <table class="data-table">
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Puntos</th><th>Nota</th></tr></thead>
                <tbody>
                <?php foreach ($movimientos as $m): ?>
                <tr>
                    <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                    <td><?= $tipoLabel[$m['tipo']] ?? $m['tipo'] ?></td>
                    <td style="font-weight:700;color:<?= $m['puntos'] >= 0 ? '#16a34a' : '#dc2626' ?>">
                        <?= $m['puntos'] >= 0 ? '+' : '' ?><?= (int)$m['puntos'] ?>
                    </td>
                    <td style="font-size:.85rem;color:var(--text-muted)"><?= htmlspecialchars($m['nota']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
async function canjear(recompensaId, btn) {
    if (!confirm('¿Canjear esta recompensa? Se descontarán los puntos de tu saldo.')) return;
    btn.disabled = true;
    const original = btn.innerHTML;
    btn.innerHTML = '<div class="spinner"></div>';

    try {
        const res = await fetch(window.BASE_URL + '/api/index.php?action=canjear_recompensa', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ recompensa_id: recompensaId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'No se pudo canjear la recompensa');

        showToast(`¡Listo! Tu cupón es ${data.codigo}`, 'success');
        setTimeout(() => window.location.reload(), 1500);
    } catch (e) {
        showToast(e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = original;
    }
}
</script>
