<?php
$adminPage  = 'erp';
$adminTitle = 'Integración ERP';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/erp_sync.php';

$pdo     = getDB();
$success = '';
$error   = '';
$preview = null;   // resultado simulado (paso 1)
$aplicado = null;  // resultado real (paso 2)

// Archivo ya leído entre la vista previa y la confirmación: se guarda fuera
// del directorio público (sys temp), nunca en uploads/.
function erpRutaTemporal(string $token): string {
    return sys_get_temp_dir() . '/erp_import_' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
}

$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'previsualizar') {
    try {
        if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona el archivo exportado del ERP.');
        }
        $nombreArchivo = basename($_FILES['archivo']['name']);
        $mapeo = erpMapearFilas(erpLeerArchivo($_FILES['archivo']['tmp_name'], $nombreArchivo));
        if (!$mapeo['filas']) throw new RuntimeException('El archivo no tiene filas de productos debajo de los encabezados.');
        if (!array_intersect(['costo', 'stock', 'precio'], array_keys($mapeo['mapa']))) {
            throw new RuntimeException('No se reconoció ninguna columna de costo, precio o stock.');
        }
        $crearNuevos = isset($_POST['crear_nuevos']);
        $preview = erpSincronizarProductos($pdo, $mapeo['filas'], 'archivo', $nombreArchivo, $crearNuevos, true);
        $preview['mapa'] = $mapeo['mapa'];
        $preview['encabezados'] = $mapeo['encabezados'];
        $preview['archivo'] = $nombreArchivo;
        $preview['crear_nuevos'] = $crearNuevos;

        $token = bin2hex(random_bytes(16));
        file_put_contents(erpRutaTemporal($token), json_encode($mapeo['filas'], JSON_UNESCAPED_UNICODE));
        $_SESSION['erp_import'] = ['token' => $token, 'archivo' => $nombreArchivo, 'crear_nuevos' => $crearNuevos, 'tienda_id' => TIENDA_ID];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'aplicar') {
    $imp = $_SESSION['erp_import'] ?? null;
    $ruta = $imp ? erpRutaTemporal($imp['token']) : '';
    if (!$imp || $imp['tienda_id'] !== TIENDA_ID || !is_file($ruta) || !hash_equals($imp['token'], $_POST['token'] ?? '')) {
        $error = 'La vista previa expiró. Vuelve a subir el archivo.';
    } else {
        try {
            $filas = json_decode(file_get_contents($ruta), true) ?: [];
            $aplicado = erpSincronizarProductos($pdo, $filas, 'archivo', $imp['archivo'], $imp['crear_nuevos'], false);
            $success = 'Sincronización aplicada: ' . $aplicado['actualizados'] . ' productos actualizados'
                . ($aplicado['creados'] ? ', ' . $aplicado['creados'] . ' creados (ocultos hasta revisarlos)' : '') . '.';
        } catch (Throwable $e) {
            $error = 'No se pudo aplicar la sincronización: ' . $e->getMessage();
        }
        @unlink($ruta);
        unset($_SESSION['erp_import']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'generar_token') {
    $pdo->prepare('UPDATE config SET erp_api_token = ?, updated_at = NOW() WHERE id = ?')
        ->execute([bin2hex(random_bytes(24)), TIENDA_ID]);
    $success = 'Se generó un nuevo token. El anterior dejó de funcionar.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'revocar_token') {
    $pdo->prepare("UPDATE config SET erp_api_token = '', updated_at = NOW() WHERE id = ?")->execute([TIENDA_ID]);
    $success = 'La API de integración quedó desactivada.';
}

$tokenStmt = $pdo->prepare('SELECT erp_api_token FROM config WHERE id = ?');
$tokenStmt->execute([TIENDA_ID]);
$apiToken = (string) $tokenStmt->fetchColumn();

$histStmt = $pdo->prepare('SELECT * FROM erp_sincronizaciones WHERE tienda_id = ? ORDER BY created_at DESC LIMIT 20');
$histStmt->execute([TIENDA_ID]);
$historial = $histStmt->fetchAll();

$camposLabel = [
    'codigo' => 'Código', 'nombre' => 'Descripción', 'marca' => 'Marca', 'um' => 'UM', 'costo' => 'Costo actual',
    'stock' => 'Stock', 'minimo_porcentaje' => '% Mínimo', 'lista_porcentaje' => '% Lista', 'precio' => 'Precio lista',
];
$resultado = $aplicado ?? $preview;
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-plug"></i> Integración ERP</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Carga y actualiza productos, costos, precios y stock del catálogo desde el sistema ERP de la empresa (Fox Smart Business).
            Los productos se emparejan por <strong>código</strong>; el precio de venta se recalcula como costo × (1 + % lista).
        </p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($resultado): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-title">
        <i class="fas <?= $aplicado ? 'fa-circle-check' : 'fa-eye' ?>"></i>
        <?= $aplicado ? 'Resultado de la sincronización' : 'Vista previa — todavía no se guardó nada' ?>
        <?php if (!empty($resultado['archivo'])): ?><span style="font-weight:400;color:var(--text-muted)">· <?= htmlspecialchars($resultado['archivo']) ?></span><?php endif; ?>
    </div>

    <div class="dash-kpi-grid" style="margin-bottom:16px">
        <?php foreach ([
            ['Filas leídas', $resultado['total'], 'fa-list'],
            [$aplicado ? 'Actualizados' : 'Se actualizarán', $resultado['actualizados'], 'fa-rotate'],
            [$aplicado ? 'Creados' : 'Se crearán', $resultado['creados'], 'fa-plus'],
            ['Sin cambios', $resultado['sin_cambios'], 'fa-equals'],
            ['No encontrados', count($resultado['no_encontrados']), 'fa-circle-question'],
            ['Con error', count($resultado['errores']), 'fa-triangle-exclamation'],
        ] as [$lbl, $num, $ico]): ?>
        <div class="card" style="padding:14px">
            <div style="font-size:.78rem;color:var(--text-muted)"><i class="fas <?= $ico ?>"></i> <?= $lbl ?></div>
            <div style="font-size:1.4rem;font-weight:800"><?= number_format($num) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($preview): ?>
    <div style="font-size:.85rem;margin-bottom:12px">
        <strong>Columnas reconocidas:</strong>
        <?php foreach ($camposLabel as $campo => $lbl): ?>
            <?php if (isset($preview['mapa'][$campo])): ?>
            <span class="badge" style="background:var(--success-bg);color:#166534;margin:2px"><?= $lbl ?> ← "<?= htmlspecialchars($preview['encabezados'][$preview['mapa'][$campo]]) ?>"</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($resultado['cambios']): ?>
    <div class="table-wrapper" style="margin-bottom:12px">
        <table class="data-table">
            <thead><tr><th>Código</th><th>Producto</th><th>Precio</th><th>Stock</th></tr></thead>
            <tbody>
            <?php foreach ($resultado['cambios'] as $c): ?>
            <tr>
                <td style="font-family:monospace"><?= htmlspecialchars($c['codigo']) ?></td>
                <td><?= htmlspecialchars($c['nombre']) ?> <?php if ($c['tipo'] === 'nuevo'): ?><span class="badge" style="background:#dbeafe;color:#1e40af">nuevo</span><?php endif; ?></td>
                <td><?= $c['precio'][0] !== null ? formatMoney($c['precio'][0]) . ' → ' : '' ?><strong><?= formatMoney($c['precio'][1]) ?></strong></td>
                <td><?= $c['stock'][0] !== null ? $c['stock'][0] . ' → ' : '' ?><strong><?= $c['stock'][1] ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-hint">Se muestran hasta 25 cambios de ejemplo.</div>
    <?php endif; ?>

    <?php if ($resultado['no_encontrados']): ?>
    <details style="margin-top:10px;font-size:.85rem">
        <summary>Códigos del archivo que no existen en el catálogo (<?= count($resultado['no_encontrados']) ?>)</summary>
        <div style="font-family:monospace;color:var(--text-muted);margin-top:6px"><?= htmlspecialchars(implode(', ', array_slice($resultado['no_encontrados'], 0, 300))) ?></div>
    </details>
    <?php endif; ?>
    <?php if ($resultado['errores']): ?>
    <details style="margin-top:10px;font-size:.85rem" open>
        <summary>Filas con error (<?= count($resultado['errores']) ?>)</summary>
        <ul style="margin:6px 0 0 18px;color:var(--danger)">
            <?php foreach (array_slice($resultado['errores'], 0, 50) as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>

    <?php if ($preview): ?>
    <form method="POST" style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
        <input type="hidden" name="accion" value="aplicar">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['erp_import']['token'] ?? '') ?>">
        <button type="submit" class="btn btn-primary" <?= ($preview['actualizados'] + $preview['creados']) ? '' : 'disabled' ?>>
            <i class="fas fa-check"></i> Aplicar <?= number_format($preview['actualizados'] + $preview['creados']) ?> cambios
        </button>
        <a href="<?= BASE_URL ?>/admin/erp.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;align-items:start">

    <!-- Importación por archivo -->
    <div class="card">
        <div class="card-title"><i class="fas fa-file-excel" style="color:#16a34a"></i> Importar listado del ERP</div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:14px">
            En Fox Smart Business exporta el <strong>Listado de precios</strong> (Excel) y súbelo aquí. También acepta CSV con
            columnas <code>codigo, descripcion, marca, um, costo, stock, lista %, precio</code>. Antes de guardar verás una vista previa.
        </p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="previsualizar">
            <div class="form-group">
                <label class="form-label">Archivo (.xlsx o .csv)</label>
                <input type="file" name="archivo" class="form-control" accept=".xlsx,.csv,.txt" required>
            </div>
            <label style="display:flex;gap:8px;align-items:flex-start;font-size:.85rem;margin-bottom:14px;cursor:pointer">
                <input type="checkbox" name="crear_nuevos" value="1" style="margin-top:3px">
                <span>Crear los productos que no existan en el catálogo. Quedan <strong>ocultos</strong> hasta que les asignes categoría e imagen y los actives en Productos.</span>
            </label>
            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Ver vista previa</button>
        </form>
    </div>

    <!-- API REST -->
    <div class="card">
        <div class="card-title"><i class="fas fa-code"></i> API de sincronización</div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:14px">
            Para sincronización automática: el ERP o su conector envía los productos a este endpoint con el token.
            Usa exactamente las mismas reglas que la importación por archivo.
        </p>
        <div class="form-group">
            <label class="form-label">Endpoint</label>
            <input type="text" class="form-control" readonly value="POST <?= htmlspecialchars(BASE_URL) ?>/api/erp.php?action=productos" onclick="this.select()">
        </div>
        <?php if ($apiToken): ?>
        <div class="form-group">
            <label class="form-label">Token (cabecera <code>Authorization: Bearer …</code>)</label>
            <input type="text" class="form-control" readonly value="<?= htmlspecialchars($apiToken) ?>" onclick="this.select()" style="font-family:monospace;font-size:.8rem">
            <div class="form-hint">Compártelo solo con el proveedor del ERP. Si se filtra, genera uno nuevo.</div>
        </div>
        <?php else: ?>
        <div class="alert" style="background:var(--surface-3);font-size:.85rem">La API está desactivada: genera un token para activarla.</div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <form method="POST" onsubmit="return <?= $apiToken ? "confirm('El token actual dejará de funcionar. ¿Continuar?')" : 'true' ?>">
                <input type="hidden" name="accion" value="generar_token">
                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> <?= $apiToken ? 'Regenerar token' : 'Generar token' ?></button>
            </form>
            <?php if ($apiToken): ?>
            <form method="POST" onsubmit="return confirm('¿Desactivar la API de integración?')">
                <input type="hidden" name="accion" value="revocar_token">
                <button type="submit" class="btn btn-secondary"><i class="fas fa-ban"></i> Desactivar</button>
            </form>
            <?php endif; ?>
        </div>
        <details style="margin-top:14px;font-size:.8rem">
            <summary>Ejemplo de petición</summary>
<pre style="background:var(--surface-3);padding:10px;border-radius:6px;overflow-x:auto;margin-top:6px">curl -X POST "<?= htmlspecialchars(BASE_URL) ?>/api/erp.php?action=productos" \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"productos":[{"codigo":"12 0001","costo":6.00,"stock":11}]}'</pre>
        </details>
    </div>
</div>

<!-- Historial -->
<div class="card" style="margin-top:20px">
    <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Historial de sincronizaciones</div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Fecha</th><th>Origen</th><th>Referencia</th><th>Filas</th><th>Actualizados</th><th>Creados</th><th>Sin cambios</th><th>No encontrados</th><th>Errores</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td style="font-size:.82rem"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                <td><span class="badge" style="background:<?= $h['origen'] === 'api' ? '#dbeafe;color:#1e40af' : '#dcfce7;color:#166534' ?>"><?= $h['origen'] === 'api' ? 'API' : 'Archivo' ?></span></td>
                <td style="font-size:.82rem"><?= htmlspecialchars($h['referencia']) ?></td>
                <td><?= number_format($h['total']) ?></td>
                <td><?= number_format($h['actualizados']) ?></td>
                <td><?= number_format($h['creados']) ?></td>
                <td><?= number_format($h['sin_cambios']) ?></td>
                <td><?= number_format($h['no_encontrados']) ?></td>
                <td style="<?= $h['errores'] ? 'color:var(--danger);font-weight:600' : '' ?>"><?= number_format($h['errores']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$historial): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:30px">Todavía no hay sincronizaciones.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
