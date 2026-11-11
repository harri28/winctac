<?php
// ============================================================
// IMPORTAR PRODUCTOS — carga masiva desde el Excel "Listado de precios"
// (formato de PRODUCTOS WIN TAC.xlsx / Fox Smart Business) o un CSV.
//
// Usa el mismo motor que Integración ERP (includes/erp_sync.php), con dos
// diferencias: siempre crea los códigos nuevos y los deja VISIBLES (sin
// categoría). Los productos que ya existen se actualizan por código; los que
// no vienen en el archivo no se tocan.
// ============================================================
$adminPage  = 'productos';
$adminTitle = 'Importar productos';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/erp_sync.php';

$pdo      = getDB();
$success  = '';
$error    = '';
$preview  = null;   // paso 1: resultado simulado
$aplicado = null;   // paso 2: resultado real

// Filas ya leídas entre la vista previa y la confirmación: fuera del
// directorio público (temp del sistema), nunca en uploads/.
function importRutaTemporal(string $token): string {
    return sys_get_temp_dir() . '/import_productos_' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
}

// Columnas del listado que existen solo como cálculo o referencia: se
// reconocen para mostrarlas, pero no se guardan.
function importColumnaIgnorada(string $enc): ?string {
    if ($enc === '') return null;
    if (preg_match('/^(item|n|nro|#)$/', $enc)) return 'número de fila';
    if (preg_match('/^costo resultado/', $enc)) return 'cálculo: costo actual − anterior';
    if (preg_match('/utilidad/', $enc)) return 'cálculo: precio − costo';
    return 'columna no reconocida';
}

$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'previsualizar') {
    try {
        if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $codigoError = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new RuntimeException(in_array($codigoError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo es demasiado grande (máximo 10 MB).' : 'Selecciona el archivo Excel de productos.');
        }
        $nombreArchivo = basename($_FILES['archivo']['name']);
        $mapeo = erpMapearFilas(erpLeerArchivo($_FILES['archivo']['tmp_name'], $nombreArchivo));
        if (!$mapeo['filas']) throw new RuntimeException('El archivo no tiene filas de productos debajo de los encabezados.');
        if (!isset($mapeo['mapa']['nombre'])) throw new RuntimeException('No se encontró la columna "Descripción" (nombre del producto).');

        $preview = erpSincronizarProductos($pdo, $mapeo['filas'], 'archivo', $nombreArchivo, true, true, true);
        $preview['mapa'] = $mapeo['mapa'];
        $preview['encabezados'] = $mapeo['encabezados'];
        $preview['archivo'] = $nombreArchivo;

        $token = bin2hex(random_bytes(16));
        file_put_contents(importRutaTemporal($token), json_encode($mapeo['filas'], JSON_UNESCAPED_UNICODE));
        $_SESSION['prod_import'] = ['token' => $token, 'archivo' => $nombreArchivo, 'tienda_id' => TIENDA_ID];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'aplicar') {
    $imp  = $_SESSION['prod_import'] ?? null;
    $ruta = $imp ? importRutaTemporal($imp['token']) : '';
    if (!$imp || $imp['tienda_id'] !== TIENDA_ID || !is_file($ruta) || !hash_equals($imp['token'], $_POST['token'] ?? '')) {
        $error = 'La vista previa expiró. Vuelve a subir el archivo.';
    } else {
        try {
            $filas = json_decode(file_get_contents($ruta), true) ?: [];
            $aplicado = erpSincronizarProductos($pdo, $filas, 'archivo', $imp['archivo'], true, false, true);
            $success = 'Importación aplicada: ' . number_format($aplicado['creados']) . ' productos creados y '
                . number_format($aplicado['actualizados']) . ' actualizados.';
        } catch (Throwable $e) {
            $error = 'No se pudo aplicar la importación (no se guardó nada): ' . $e->getMessage();
        }
        @unlink($ruta);
        unset($_SESSION['prod_import']);
    }
}

$camposLabel = [
    'codigo' => 'Código', 'nombre' => 'Descripción', 'marca' => 'Marca', 'um' => 'UM',
    'costo_anterior' => 'Costo anterior', 'costo' => 'Costo actual',
    'minimo_porcentaje' => '% Mínimo', 'precio_minimo' => 'Precio mínimo',
    'lista_porcentaje' => '% Lista', 'precio' => 'Precio de venta',
    'stock' => 'Stock', 'codigo_barras' => 'Código de barras',
];
$resultado = $aplicado ?? $preview;
?>

<div class="admin-topbar">
    <div>
        <h1 class="admin-page-title"><i class="fas fa-file-import"></i> Importar productos</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Sube el Excel del <strong>Listado de precios</strong>. Los productos se emparejan por <strong>código</strong>:
            los nuevos se crean visibles (sin categoría) y los existentes se actualizan. Los que no vengan en el archivo no se tocan.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/productos.php" class="btn btn-secondary" style="font-size:.83rem"><i class="fas fa-arrow-left"></i> Volver a productos</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="<?= BASE_URL ?>/admin/productos.php">Ver productos →</a></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($resultado): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-title">
        <i class="fas <?= $aplicado ? 'fa-circle-check' : 'fa-eye' ?>"></i>
        <?= $aplicado ? 'Resultado de la importación' : 'Vista previa — todavía no se guardó nada' ?>
        <?php if (!empty($resultado['archivo'])): ?><span style="font-weight:400;color:var(--text-muted)">· <?= htmlspecialchars($resultado['archivo']) ?></span><?php endif; ?>
    </div>

    <div class="dash-kpi-grid" style="margin-bottom:16px">
        <?php foreach ([
            ['Filas leídas', $resultado['total'], 'fa-list'],
            [$aplicado ? 'Creados' : 'Se crearán', $resultado['creados'], 'fa-plus'],
            [$aplicado ? 'Actualizados' : 'Se actualizarán', $resultado['actualizados'], 'fa-rotate'],
            ['Sin cambios', $resultado['sin_cambios'], 'fa-equals'],
            ['Con error', count($resultado['errores']), 'fa-triangle-exclamation'],
        ] as [$lbl, $num, $ico]): ?>
        <div class="card" style="padding:14px">
            <div style="font-size:.78rem;color:var(--text-muted)"><i class="fas <?= $ico ?>"></i> <?= $lbl ?></div>
            <div style="font-size:1.4rem;font-weight:800"><?= number_format($num) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($preview): ?>
    <?php $campoPorColumna = array_flip($preview['mapa']); ?>
    <div class="table-wrapper" style="margin-bottom:14px">
        <table class="data-table">
            <thead><tr><th>Columna del Excel</th><th>Se guarda en</th></tr></thead>
            <tbody>
            <?php foreach ($preview['encabezados'] as $i => $enc): if ($enc === '') continue; ?>
            <tr>
                <td style="font-family:monospace"><?= htmlspecialchars($enc) ?></td>
                <td>
                    <?php if (isset($campoPorColumna[$i])): ?>
                        <span class="badge" style="background:var(--success-bg);color:#166534"><i class="fas fa-check"></i> <?= $camposLabel[$campoPorColumna[$i]] ?? $campoPorColumna[$i] ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.85rem">No se guarda (<?= importColumnaIgnorada($enc) ?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-hint" style="margin-bottom:12px">El precio de venta y el precio mínimo se recalculan como costo actual × (1 + %), igual que al editar un producto.</div>
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

    <?php if ($resultado['errores']): ?>
    <details style="margin-top:10px;font-size:.85rem" open>
        <summary>Filas con error (<?= count($resultado['errores']) ?>) — se omiten, el resto se importa</summary>
        <ul style="margin:6px 0 0 18px;color:var(--danger)">
            <?php foreach (array_slice($resultado['errores'], 0, 50) as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>

    <?php if ($preview): ?>
    <form method="POST" style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Importando…'">
        <input type="hidden" name="accion" value="aplicar">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['prod_import']['token'] ?? '') ?>">
        <button type="submit" class="btn btn-primary" <?= ($preview['actualizados'] + $preview['creados']) ? '' : 'disabled' ?>>
            <i class="fas fa-check"></i> Importar <?= number_format($preview['actualizados'] + $preview['creados']) ?> productos
        </button>
        <a href="<?= BASE_URL ?>/admin/importar-productos.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$preview): ?>
<div class="card" style="max-width:720px">
    <div class="card-title"><i class="fas fa-file-excel" style="color:#16a34a"></i> Subir archivo</div>
    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:10px">
        Formato estándar (el del Listado de precios): <code>ITEM · CODIGO · DESCRIPCION · MARCA · UM · COSTO (Anterior / Actual / Resultado) ·
        MINIMO (% / Precio / Utilidad) · LISTA (% / Precio / Utilidad) · STOCK · Catálogo</code>.
        También acepta CSV con encabezados <code>codigo, descripcion, marca, um, costo anterior, costo, minimo %, precio minimo, lista %, precio, stock, codigo de barras</code>.
    </p>
    <form method="POST" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Leyendo archivo…'">
        <input type="hidden" name="accion" value="previsualizar">
        <div class="form-group">
            <label class="form-label">Archivo (.xlsx o .csv, máx. 10 MB)</label>
            <input type="file" name="archivo" class="form-control" accept=".xlsx,.csv,.txt" required>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Ver vista previa</button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
