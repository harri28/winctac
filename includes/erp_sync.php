<?php
// ============================================================
// INTEGRACIÓN ERP — Sincronización de productos (stock, costo y precio)
// desde el ERP de la tienda (Fox Smart Business en WIN TAC).
//
// Dos vías comparten el mismo motor (erpSincronizarProductos):
//  - admin/erp.php: el admin sube el "Listado de precios" exportado del ERP
//    (Excel .xlsx o CSV), ve una vista previa y confirma.
//  - api/erp.php:   el ERP (o un conector) envía los productos por API REST
//    autenticada con el token de config.erp_api_token.
//
// Los productos se emparejan por `codigo` dentro de la tienda (TIENDA_ID).
// El precio de venta se recalcula igual que en admin/api.php
// (guardar_producto): costo_actual × (1 + lista_porcentaje / 100).
// ============================================================

// Campos que entiende el motor; todo salvo `codigo` es opcional por fila.
const ERP_CAMPOS = ['codigo', 'nombre', 'marca', 'um', 'costo', 'stock', 'minimo_porcentaje', 'lista_porcentaje', 'precio'];

// ── Lectura de archivos ──────────────────────────────────────

// Devuelve las filas del archivo como listas de strings (sin interpretar).
function erpLeerArchivo(string $path, string $nombreOriginal): array {
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return erpLeerXlsx($path);
    if ($ext === 'csv' || $ext === 'txt') return erpLeerCsv($path);
    throw new RuntimeException('Formato no soportado: sube el listado en Excel (.xlsx) o CSV.');
}

function erpLeerCsv(string $path): array {
    $contenido = file_get_contents($path);
    if ($contenido === false) throw new RuntimeException('No se pudo leer el archivo.');
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    // Los ERP de escritorio en Windows suelen exportar en Windows-1252
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }
    $lineas = preg_split('/\r\n|\r|\n/', $contenido);
    $primera = $lineas[0] ?? '';
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    if (substr_count($primera, "\t") > substr_count($primera, $sep)) $sep = "\t";

    $filas = [];
    foreach ($lineas as $linea) {
        if (trim($linea) === '') continue;
        $filas[] = array_map('trim', str_getcsv($linea, $sep));
    }
    return $filas;
}

function erpLeerXlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión zip de PHP para leer Excel. Exporta el listado como CSV o instala php-zip.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('El archivo Excel está dañado o no es un .xlsx válido.');

    $compartidos = [];
    $xmlSS = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlSS !== false) {
        $ss = simplexml_load_string($xmlSS);
        foreach ($ss->si as $si) {
            $partes = $si->xpath('.//*[local-name()="t"]');
            $compartidos[] = implode('', array_map('strval', $partes));
        }
    }

    // Primera hoja del libro (la que el ERP llena con el listado)
    $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($hoja === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) { $hoja = $zip->getFromName($n); break; }
        }
    }
    $zip->close();
    if ($hoja === false) throw new RuntimeException('El Excel no tiene hojas con datos.');

    $sheet = simplexml_load_string($hoja);
    $filas = [];
    foreach ($sheet->sheetData->row as $row) {
        $fila = [];
        foreach ($row->c as $c) {
            $col = erpColumnaIndice(preg_replace('/\d+/', '', (string) $c['r']));
            $tipo = (string) $c['t'];
            if ($tipo === 's') {
                $v = $compartidos[(int) $c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $v = implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]')));
            } else {
                $v = (string) $c->v;
            }
            $fila[$col] = trim($v);
        }
        if (!$fila) continue;
        $max = max(array_keys($fila));
        $completa = [];
        for ($i = 0; $i <= $max; $i++) $completa[] = $fila[$i] ?? '';
        $filas[] = $completa;
    }
    return $filas;
}

function erpColumnaIndice(string $letras): int {
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) $n = $n * 26 + (ord($l) - 64);
    return $n - 1;
}

// ── Mapeo de columnas ────────────────────────────────────────

function erpNormalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9%]+/', ' ', $s));
}

// Busca la fila de encabezados (la que tiene "código") y arma un encabezado
// por columna. Soporta el formato de dos filas del "Listado de precios" de
// Fox Smart Business (COSTO → Anterior/Actual, LISTA → % / Precio, celdas
// combinadas), además de un CSV simple de una sola fila de encabezados.
// Devuelve ['mapa' => [campo => índice], 'filas' => [[campo => valor]], 'ignoradas' => int].
function erpMapearFilas(array $filas): array {
    $idxEnc = null;
    foreach (array_slice($filas, 0, 20, true) as $i => $fila) {
        foreach ($fila as $celda) {
            if (in_array(erpNormalizar($celda), ['codigo', 'cod', 'cod articulo', 'codigo articulo', 'sku'], true)) { $idxEnc = $i; break 2; }
        }
    }
    if ($idxEnc === null) throw new RuntimeException('No se encontró la columna "Código" en las primeras filas del archivo.');

    $enc = $filas[$idxEnc];
    $sub = $filas[$idxEnc + 1] ?? [];
    $colCodigo = null;
    foreach ($enc as $i => $c) {
        if (in_array(erpNormalizar($c), ['codigo', 'cod', 'cod articulo', 'codigo articulo', 'sku'], true)) { $colCodigo = $i; break; }
    }
    // La fila siguiente es un sub-encabezado si no trae un código de producto
    $tieneSub = $sub && trim($sub[$colCodigo] ?? '') === '' && count(array_filter($sub, fn($v) => trim($v) !== '')) > 0;

    $grupo = '';
    $encabezados = [];
    $total = max(count($enc), count($sub));
    for ($i = 0; $i < $total; $i++) {
        if (trim($enc[$i] ?? '') !== '') $grupo = erpNormalizar($enc[$i]);
        $s = $tieneSub ? erpNormalizar($sub[$i] ?? '') : '';
        $encabezados[$i] = trim($grupo . ' ' . $s);
        if (trim($enc[$i] ?? '') === '' && $s === '') $encabezados[$i] = ''; // columna vacía tras un grupo
    }

    $reglas = [
        'codigo'            => ['/^(codigo|cod|cod articulo|codigo articulo|sku)$/'],
        'nombre'            => ['/^(descripcion|nombre|articulo|producto)( |$)/'],
        'marca'             => ['/^marca/'],
        'um'                => ['/^(um|u m|unidad|unidad de medida)$/'],
        'costo'             => ['/^costo actual/', '/^costo( s)?$/', '/^costo unitario/'],
        'stock'             => ['/^(stock|saldo|existencia|cantidad)/'],
        'minimo_porcentaje' => ['/^minimo %$/'],
        'lista_porcentaje'  => ['/^lista %$/', '/^(margen|% lista)$/'],
        'precio'            => ['/^lista precio/', '/^(precio|precio venta|pvp)( s)?$/'],
    ];
    $mapa = [];
    foreach ($reglas as $campo => $patrones) {
        foreach ($patrones as $p) {
            foreach ($encabezados as $i => $h) {
                if ($h !== '' && !in_array($i, $mapa, true) && preg_match($p, $h)) { $mapa[$campo] = $i; break 2; }
            }
        }
    }

    $datos = [];
    $ignoradas = 0;
    foreach (array_slice($filas, $idxEnc + ($tieneSub ? 2 : 1)) as $fila) {
        $codigo = trim($fila[$mapa['codigo']] ?? '');
        if ($codigo === '') { $ignoradas++; continue; }
        $item = [];
        foreach ($mapa as $campo => $i) $item[$campo] = trim($fila[$i] ?? '');
        $datos[] = $item;
    }
    return ['mapa' => $mapa, 'encabezados' => $encabezados, 'filas' => $datos, 'ignoradas' => $ignoradas];
}

// "1,234.50" / "1.234,50" / "12.5" → float; null si viene vacío o no es número.
function erpNumero($v): ?float {
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return (float) $v;
    $v = trim(str_replace(['S/', ' ', '%'], '', (string) $v));
    if ($v === '') return null;
    if (preg_match('/^-?[\d.]+,\d+$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v);
    else $v = str_replace(',', '', $v);
    return is_numeric($v) ? (float) $v : null;
}

// ── Motor de sincronización ──────────────────────────────────

// $filas: lista de ['codigo' => ..., y opcionales de ERP_CAMPOS].
// $simular = true calcula todo dentro de una transacción y hace rollback
// (vista previa del admin). Con $simular = false queda registrada en
// erp_sincronizaciones.
function erpSincronizarProductos(PDO $pdo, array $filas, string $origen, string $referencia, bool $crearNuevos, bool $simular): array {
    $res = [
        'total' => count($filas), 'actualizados' => 0, 'creados' => 0, 'sin_cambios' => 0,
        'no_encontrados' => [], 'errores' => [], 'cambios' => [],
    ];

    $existentes = [];
    $stmt = $pdo->prepare('SELECT id, codigo, nombre, marca, um, precio, stock, costo_anterior, costo_actual, minimo_porcentaje, lista_porcentaje FROM productos WHERE tienda_id = ? AND codigo <> \'\'');
    $stmt->execute([TIENDA_ID]);
    foreach ($stmt as $p) $existentes[mb_strtoupper(trim($p['codigo']))] = $p;

    $upd = $pdo->prepare('UPDATE productos SET nombre = ?, marca = ?, um = ?, precio = ?, stock = ?, costo_anterior = ?, costo_actual = ?,
        minimo_porcentaje = ?, lista_porcentaje = ?, updated_at = NOW() WHERE id = ? AND tienda_id = ?');
    $ins = $pdo->prepare('INSERT INTO productos (tienda_id, nombre, codigo, marca, um, precio, stock, costo_anterior, costo_actual,
        minimo_porcentaje, lista_porcentaje, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)');

    $vistos = [];
    $pdo->beginTransaction();
    try {
        foreach ($filas as $n => $f) {
            $fila = $n + 1;
            $codigo = trim((string) ($f['codigo'] ?? ''));
            if ($codigo === '' || mb_strlen($codigo) > 50) { $res['errores'][] = "Fila $fila: código vacío o demasiado largo"; continue; }
            $clave = mb_strtoupper($codigo);
            if (isset($vistos[$clave])) { $res['errores'][] = "Fila $fila: código $codigo repetido en el archivo (se usó la primera aparición)"; continue; }
            $vistos[$clave] = true;

            $costo  = erpNumero($f['costo'] ?? null);
            $stock  = erpNumero($f['stock'] ?? null);
            $minimo = erpNumero($f['minimo_porcentaje'] ?? null);
            $lista  = erpNumero($f['lista_porcentaje'] ?? null);
            $precio = erpNumero($f['precio'] ?? null);
            if (($costo !== null && $costo < 0) || ($stock !== null && $stock < 0) || ($precio !== null && $precio < 0)) {
                $res['errores'][] = "Fila $fila ($codigo): costo, stock o precio negativo"; continue;
            }
            $nombre = mb_substr(trim((string) ($f['nombre'] ?? '')), 0, 200);
            $marca  = mb_substr(trim((string) ($f['marca'] ?? '')), 0, 100);
            // El listado del ERP trae algunas marcas como número (dato basura):
            // se ignoran para no pisar la marca corregida en el catálogo.
            if (is_numeric($marca)) $marca = '';
            $um     = mb_substr(trim((string) ($f['um'] ?? '')), 0, 20);

            $p = $existentes[$clave] ?? null;
            if (!$p) {
                if (!$crearNuevos) { $res['no_encontrados'][] = $codigo; continue; }
                if ($nombre === '') { $res['errores'][] = "Fila $fila ($codigo): producto nuevo sin descripción"; continue; }
                $nuevoLista = $lista ?? 0;
                $nuevoPrecio = ($costo > 0 && $nuevoLista > 0) ? round($costo * (1 + $nuevoLista / 100), 2) : ($precio ?? 0);
                $ins->execute([TIENDA_ID, $nombre, $codigo, $marca, $um, $nuevoPrecio, (int) ($stock ?? 0), $costo ?? 0, $costo ?? 0, $minimo ?? 0, $nuevoLista]);
                $res['creados']++;
                if (count($res['cambios']) < 25) $res['cambios'][] = ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'nuevo', 'precio' => [null, $nuevoPrecio], 'stock' => [null, (int) ($stock ?? 0)]];
                continue;
            }

            $nCosto  = $costo ?? (float) $p['costo_actual'];
            $nLista  = $lista ?? (float) $p['lista_porcentaje'];
            $nMinimo = $minimo ?? (float) $p['minimo_porcentaje'];
            $nStock  = $stock !== null ? (int) round($stock) : (int) $p['stock'];
            if ($nCosto > 0 && $nLista > 0)  $nPrecio = round($nCosto * (1 + $nLista / 100), 2);
            elseif ($precio !== null)        $nPrecio = round($precio, 2);
            else                             $nPrecio = (float) $p['precio'];
            $nNombre = $nombre !== '' ? $nombre : $p['nombre'];
            $nMarca  = $marca !== '' ? $marca : $p['marca'];
            $nUm     = $um !== '' ? $um : $p['um'];

            $cambiaCosto = abs($nCosto - (float) $p['costo_actual']) > 0.00005;
            $hayCambio = $cambiaCosto || abs($nPrecio - (float) $p['precio']) > 0.004 || $nStock !== (int) $p['stock']
                || abs($nLista - (float) $p['lista_porcentaje']) > 0.004 || abs($nMinimo - (float) $p['minimo_porcentaje']) > 0.004
                || $nNombre !== $p['nombre'] || $nMarca !== $p['marca'] || $nUm !== $p['um'];
            if (!$hayCambio) { $res['sin_cambios']++; continue; }

            // costo_anterior guarda el costo previo solo cuando el costo cambia
            $costoAnterior = $cambiaCosto ? (float) $p['costo_actual'] : (float) $p['costo_anterior'];
            $upd->execute([$nNombre, $nMarca, $nUm, $nPrecio, $nStock, $costoAnterior,
                $nCosto, $nMinimo, $nLista, $p['id'], TIENDA_ID]);
            $res['actualizados']++;
            if (count($res['cambios']) < 25) {
                $res['cambios'][] = ['codigo' => $codigo, 'nombre' => $nNombre, 'tipo' => 'actualizado',
                    'precio' => [(float) $p['precio'], $nPrecio], 'stock' => [(int) $p['stock'], $nStock]];
            }
        }

        if ($simular) {
            $pdo->rollBack();
        } else {
            $pdo->prepare('INSERT INTO erp_sincronizaciones (tienda_id, origen, referencia, total, actualizados, creados, sin_cambios, no_encontrados, errores, detalle)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([TIENDA_ID, $origen, mb_substr($referencia, 0, 200), $res['total'], $res['actualizados'], $res['creados'], $res['sin_cambios'],
                    count($res['no_encontrados']), count($res['errores']),
                    json_encode(['no_encontrados' => array_slice($res['no_encontrados'], 0, 200), 'errores' => array_slice($res['errores'], 0, 200)], JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $res;
}
