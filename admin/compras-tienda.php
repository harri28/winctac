<?php
$adminPage  = 'compras_tienda';
$adminTitle = 'Compras en tienda';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/puntos.php';
require_once __DIR__ . '/../includes/erp_sync.php';

$pdo       = getDB();
$success   = '';
$error     = '';
$resImport = null;

// ── Registro manual de un comprobante ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar') {
    $r = registrarCompraTienda($pdo, $_POST, 'manual', (int) $_SESSION['admin_id']);
    if (!$r['ok']) {
        $error = $r['error'];
    } elseif ($r['estado'] === 'otorgado') {
        $success = "Compra registrada: se acreditaron {$r['puntos']} puntos a {$r['cliente']}.";
    } else {
        $success = 'Compra registrada como pendiente: ese DNI todavía no está afiliado. Los puntos se acreditarán cuando el cliente se registre con ese DNI.';
    }
}

// ── Importación del reporte de ventas del ERP (.xlsx / .csv) ──
// Columnas reconocidas: documento del cliente (DNI), comprobante (o serie +
// número), fecha y total.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'importar') {
    try {
        if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona el reporte de ventas exportado del ERP.');
        }
        $filas = erpLeerArchivo($_FILES['archivo']['tmp_name'], basename($_FILES['archivo']['name']));

        $patrones = [
            'dni'         => '/^((n|nro|num|numero) )?(dni|documento|doc|ruc dni|dni ruc)( (del )?cliente)?$/',
            'comprobante' => '/^((n|nro|num|numero) )?(comprobante|documento venta|serie numero|serie y numero)$/',
            'serie'       => '/^serie$/',
            'numero'      => '/^(numero|nro|correlativo)$/',
            'fecha'       => '/^(fecha|fecha emision|fecha de emision|fecha venta)$/',
            'monto'       => '/^(total|monto|importe|importe total|total venta|total s)$/',
        ];
        $mapa = null;
        $idxEnc = 0;
        foreach (array_slice($filas, 0, 20, true) as $i => $fila) {
            $m = [];
            foreach ($fila as $col => $celda) {
                $h = erpNormalizar($celda);
                foreach ($patrones as $campo => $p) {
                    if (!isset($m[$campo]) && preg_match($p, $h)) { $m[$campo] = $col; break; }
                }
            }
            if (isset($m['dni'], $m['fecha'], $m['monto']) && (isset($m['comprobante']) || isset($m['serie'], $m['numero']))) {
                $mapa = $m; $idxEnc = $i; break;
            }
        }
        if (!$mapa) throw new RuntimeException('No se reconocieron las columnas. El archivo debe tener: DNI (documento del cliente), Comprobante (o Serie y Número), Fecha y Total.');

        $resImport = ['total' => 0, 'otorgados' => 0, 'pendientes' => 0, 'puntos' => 0, 'errores' => []];
        foreach (array_slice($filas, $idxEnc + 1) as $n => $fila) {
            $comp = isset($mapa['comprobante']) ? ($fila[$mapa['comprobante']] ?? '')
                : trim(($fila[$mapa['serie']] ?? '') . '-' . ($fila[$mapa['numero']] ?? ''), '-');
            if (trim($comp) === '' && trim($fila[$mapa['dni']] ?? '') === '') continue; // fila vacía / totales
            $resImport['total']++;
            $r = registrarCompraTienda($pdo, [
                'dni' => $fila[$mapa['dni']] ?? '', 'comprobante' => $comp,
                'fecha' => $fila[$mapa['fecha']] ?? '', 'monto' => $fila[$mapa['monto']] ?? '',
            ], 'archivo', (int) $_SESSION['admin_id']);
            if (!$r['ok']) { $resImport['errores'][] = 'Fila ' . ($idxEnc + $n + 2) . ': ' . $r['error']; continue; }
            if ($r['estado'] === 'otorgado') { $resImport['otorgados']++; $resImport['puntos'] += $r['puntos']; }
            else $resImport['pendientes']++;
        }
        $success = "Importación terminada: {$resImport['otorgados']} compras con puntos acreditados ({$resImport['puntos']} pts), "
            . "{$resImport['pendientes']} pendientes de afiliación y " . count($resImport['errores']) . ' con error.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Anular (comprobante anulado en el ERP) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'anular') {
    if (anularCompraTienda($pdo, (int) ($_POST['compra_id'] ?? 0), (int) $_SESSION['admin_id'])) {
        $success = 'Compra anulada; si tenía puntos acreditados, se revirtieron.';
    } else {
        $error = 'No se pudo anular la compra.';
    }
}

// ── Listado ──
$q      = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$where  = ['ct.tienda_id = ?'];
$params = [TIENDA_ID];
if ($q !== '') {
    $where[] = '(ct.dni ILIKE ? OR ct.comprobante ILIKE ? OR c.nombre ILIKE ? OR c.apellidos ILIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if (in_array($estado, ['otorgado', 'pendiente', 'anulado'], true)) {
    $where[] = 'ct.estado = ?';
    $params[] = $estado;
}
$lista = $pdo->prepare('SELECT ct.*, c.nombre, c.apellidos FROM compras_tienda ct LEFT JOIN clientes c ON c.id = ct.cliente_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY ct.fecha DESC, ct.id DESC LIMIT 200');
$lista->execute($params);
$compras = $lista->fetchAll();

$kpi = $pdo->prepare("SELECT COUNT(*) FILTER (WHERE estado <> 'anulado') AS compras,
    COALESCE(SUM(puntos) FILTER (WHERE estado = 'otorgado'), 0) AS puntos,
    COUNT(*) FILTER (WHERE estado = 'pendiente') AS pendientes,
    COALESCE(SUM(monto) FILTER (WHERE estado <> 'anulado'), 0) AS monto
    FROM compras_tienda WHERE tienda_id = ?");
$kpi->execute([TIENDA_ID]);
$kpi = $kpi->fetch();

$cfgP = getShopConfig();
$estadosLabel = [
    'otorgado'  => ['Puntos acreditados', '#dcfce7', '#166534'],
    'pendiente' => ['Pendiente de afiliación', '#fef9c3', '#854d0e'],
    'anulado'   => ['Anulada', '#f1f5f9', '#94a3b8'],
];
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-cash-register"></i> Compras en tienda</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Compras registradas en el ERP / tienda física que acumulan puntos. Se identifican por el DNI del cliente afiliado
            y por el número de comprobante (no se acredita dos veces el mismo). Regla actual:
            <strong><?= rtrim(rtrim(number_format((float) ($cfgP['puntos_por_sol'] ?? 1), 2), '0'), '.') ?> punto(s) por cada S/ 1</strong>.
        </p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (empty($cfgP['puntos_activo'])): ?>
<div class="alert alert-error">El programa de puntos está desactivado. Actívalo en <a href="<?= BASE_URL ?>/admin/puntos.php">Programa de puntos</a> para registrar compras.</div>
<?php endif; ?>
<?php if ($resImport && $resImport['errores']): ?>
<details class="card" style="margin-bottom:20px;font-size:.85rem" open>
    <summary>Filas con error (<?= count($resImport['errores']) ?>)</summary>
    <ul style="margin:6px 0 0 18px;color:var(--danger)">
        <?php foreach (array_slice($resImport['errores'], 0, 100) as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>

<div class="dash-kpi-grid" style="margin-bottom:20px">
    <?php foreach ([
        ['Compras registradas', number_format($kpi['compras']), 'fa-receipt'],
        ['Monto total', formatMoney((float) $kpi['monto']), 'fa-coins'],
        ['Puntos acreditados', number_format($kpi['puntos']), 'fa-star'],
        ['Pendientes de afiliación', number_format($kpi['pendientes']), 'fa-user-clock'],
    ] as [$lbl, $val, $ico]): ?>
    <div class="card" style="padding:14px">
        <div style="font-size:.78rem;color:var(--text-muted)"><i class="fas <?= $ico ?>"></i> <?= $lbl ?></div>
        <div style="font-size:1.4rem;font-weight:800"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;align-items:start;margin-bottom:20px">
    <div class="card">
        <div class="card-title"><i class="fas fa-plus"></i> Registrar compra</div>
        <form method="POST">
            <input type="hidden" name="accion" value="registrar">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label class="form-label">DNI del cliente</label>
                    <input type="text" name="dni" class="form-control" maxlength="15" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Comprobante</label>
                    <input type="text" name="comprobante" class="form-control" maxlength="40" placeholder="B001-000123" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Total (S/)</label>
                    <input type="number" name="monto" class="form-control" step="0.01" min="0.01" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-star"></i> Registrar y acreditar puntos</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-file-import"></i> Importar ventas del ERP</div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px">
            Exporta del ERP el reporte de ventas/comprobantes (Excel o CSV) con las columnas <strong>DNI</strong> (documento del cliente),
            <strong>Comprobante</strong> (o Serie y Número), <strong>Fecha</strong> y <strong>Total</strong>. Los comprobantes ya registrados se omiten
            y las ventas sin DNI de un cliente se reportan como error.
        </p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="importar">
            <div class="form-group">
                <input type="file" name="archivo" class="form-control" accept=".xlsx,.csv,.txt" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Importar</button>
        </form>
        <div class="form-hint" style="margin-top:10px">También pueden llegar automáticamente por la API: <code>POST /api/erp.php?action=compras</code> (ver Integración ERP).</div>
    </div>
</div>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
    <input type="text" name="q" class="form-control" placeholder="Buscar DNI, comprobante o cliente" value="<?= htmlspecialchars($q) ?>" style="max-width:280px">
    <select name="estado" class="form-control" style="max-width:220px">
        <option value="">Todos los estados</option>
        <?php foreach ($estadosLabel as $k => [$lbl,,]): ?>
        <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Filtrar</button>
</form>

<div class="table-wrapper">
    <table class="data-table">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>DNI</th><th>Cliente</th><th>Total</th><th>Puntos</th><th>Estado</th><th>Origen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): [$lbl, $bg, $fg] = $estadosLabel[$c['estado']] ?? $estadosLabel['pendiente']; ?>
        <tr>
            <td style="font-size:.82rem;white-space:nowrap"><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
            <td style="font-family:monospace"><?= htmlspecialchars($c['comprobante']) ?></td>
            <td style="font-family:monospace"><?= htmlspecialchars($c['dni']) ?></td>
            <td><?= $c['cliente_id'] ? htmlspecialchars(trim($c['nombre'] . ' ' . $c['apellidos'])) : '<span style="color:var(--text-muted)">—</span>' ?></td>
            <td><?= formatMoney((float) $c['monto']) ?></td>
            <td style="font-weight:700"><?= $c['estado'] === 'otorgado' ? '+' . (int) $c['puntos'] : '—' ?></td>
            <td><span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $lbl ?></span></td>
            <td style="font-size:.8rem;color:var(--text-muted)"><?= ['manual' => 'Manual', 'archivo' => 'Archivo', 'api' => 'API'][$c['origen']] ?? $c['origen'] ?></td>
            <td>
                <?php if ($c['estado'] !== 'anulado'): ?>
                <form method="POST" onsubmit="return confirm('¿Anular esta compra? Si tenía puntos acreditados, se descontarán del saldo del cliente.')">
                    <input type="hidden" name="accion" value="anular">
                    <input type="hidden" name="compra_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:.75rem" title="Anular"><i class="fas fa-ban"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$compras): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:40px">Sin compras registradas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
