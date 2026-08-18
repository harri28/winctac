<?php
$adminPage  = 'cupones';
$adminTitle = 'Cupones';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Ajuste manual de puntos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajuste_manual'])) {
    $email  = trim($_POST['email_cliente'] ?? '');
    $puntos = intval($_POST['puntos'] ?? 0);
    $nota   = trim($_POST['nota'] ?? '');

    $c = $pdo->prepare('SELECT id FROM clientes WHERE email = ?');
    $c->execute([$email]);
    $clienteId = $c->fetchColumn();

    if (!$clienteId) {
        $error = 'No se encontró un cliente con ese correo.';
    } elseif ($puntos === 0) {
        $error = 'La cantidad de puntos debe ser distinta de 0.';
    } elseif (!$nota) {
        $error = 'La nota es obligatoria para el ajuste manual.';
    } else {
        $pdo->prepare('
            INSERT INTO puntos_movimientos (cliente_id, tipo, puntos, nota, admin_id)
            VALUES (?, \'ajuste_admin\', ?, ?, ?)
        ')->execute([$clienteId, $puntos, $nota, $_SESSION['admin_id']]);
        $pdo->prepare('UPDATE clientes SET puntos_saldo = puntos_saldo + ? WHERE id = ?')
            ->execute([$puntos, $clienteId]);
        $success = 'Ajuste aplicado correctamente.';
    }
}

$q = trim($_GET['q'] ?? '');
$where = '1=1';
$params = [];
if ($q) {
    $where = 'c.nombre ILIKE :q OR c.email ILIKE :q';
    $params['q'] = "%$q%";
}

$movimientos = $pdo->prepare("
    SELECT m.*, c.nombre AS cliente_nombre, c.email AS cliente_email, p.codigo AS pedido_codigo
    FROM puntos_movimientos m
    JOIN clientes c ON c.id = m.cliente_id
    LEFT JOIN pedidos p ON p.id = m.pedido_id
    WHERE $where
    ORDER BY m.created_at DESC
    LIMIT 100
");
$movimientos->execute($params);
$movimientos = $movimientos->fetchAll();

$cupones = $pdo->query("
    SELECT cu.*, c.nombre AS cliente_nombre, c.email AS cliente_email,
           CASE WHEN cu.estado = 'activo' AND cu.expira_at IS NOT NULL AND cu.expira_at < NOW()
                THEN 'vencido' ELSE cu.estado END AS estado_mostrar
    FROM puntos_cupones cu
    JOIN clientes c ON c.id = cu.cliente_id
    ORDER BY cu.created_at DESC
    LIMIT 100
")->fetchAll();

$tipoLabel = ['ganado' => 'Ganado', 'canjeado' => 'Canjeado', 'revertido' => 'Revertido', 'ajuste_admin' => 'Ajuste admin'];
$estadoBadge = ['activo' => ['#dcfce7', '#166534'], 'usado' => ['#f1f5f9', '#475569'], 'vencido' => ['#fee2e2', '#991b1b']];
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-ticket"></i> Cupones e historial de puntos</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-user-edit"></i> Ajuste manual de puntos</div>
    <form method="POST" style="display:grid;grid-template-columns:1.4fr 0.8fr 1.8fr auto;gap:12px;align-items:end">
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Correo del cliente</label>
            <input type="email" name="email_cliente" class="form-control" placeholder="cliente@correo.com" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Puntos (+/-)</label>
            <input type="number" name="puntos" class="form-control" placeholder="Ej: 50 o -50" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Nota (obligatoria)</label>
            <input type="text" name="nota" class="form-control" placeholder="Motivo del ajuste" required>
        </div>
        <div>
            <button type="submit" name="ajuste_manual" class="btn btn-primary">
                <i class="fas fa-check"></i> Aplicar
            </button>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-history"></i> Historial de puntos por cliente</div>
    <form method="GET" style="margin-bottom:14px;max-width:360px">
        <input type="text" name="q" class="form-control" placeholder="Buscar por nombre o correo..." value="<?= htmlspecialchars($q) ?>">
    </form>
    <table class="data-table">
        <thead>
            <tr><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Puntos</th><th>Pedido</th><th>Nota</th></tr>
        </thead>
        <tbody>
        <?php foreach ($movimientos as $m): ?>
        <tr>
            <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
            <td><?= htmlspecialchars($m['cliente_nombre']) ?><br><span style="font-size:.75rem;color:var(--text-muted)"><?= htmlspecialchars($m['cliente_email']) ?></span></td>
            <td><?= $tipoLabel[$m['tipo']] ?? $m['tipo'] ?></td>
            <td style="font-weight:700;color:<?= $m['puntos'] >= 0 ? '#16a34a' : '#dc2626' ?>">
                <?= $m['puntos'] >= 0 ? '+' : '' ?><?= (int)$m['puntos'] ?>
            </td>
            <td><?= $m['pedido_codigo'] ? htmlspecialchars($m['pedido_codigo']) : '—' ?></td>
            <td style="font-size:.85rem;color:var(--text-muted)"><?= htmlspecialchars($m['nota']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$movimientos): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Sin movimientos todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-ticket-alt"></i> Cupones generados</div>
    <table class="data-table">
        <thead>
            <tr><th>Código</th><th>Cliente</th><th>Recompensa</th><th>Estado</th><th>Expira</th><th>Generado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cupones as $c): [$bg, $fg] = $estadoBadge[$c['estado_mostrar']] ?? ['#f1f5f9', '#475569']; ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($c['codigo']) ?></td>
            <td><?= htmlspecialchars($c['cliente_nombre']) ?><br><span style="font-size:.75rem;color:var(--text-muted)"><?= htmlspecialchars($c['cliente_email']) ?></span></td>
            <td><?= htmlspecialchars($c['recompensa_nombre']) ?></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;background:<?= $bg ?>;color:<?= $fg ?>">
                    <?= ucfirst($c['estado_mostrar']) ?>
                </span>
            </td>
            <td><?= $c['expira_at'] ? date('d/m/Y', strtotime($c['expira_at'])) : 'No expira' ?></td>
            <td><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$cupones): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Sin cupones generados todavía</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
