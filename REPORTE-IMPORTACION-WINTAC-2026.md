# Reporte: Importación de catálogo WIN TAC (2026)

Documenta la revisión y preparación del catálogo de wintac.shop a partir del archivo
`PRODUCTOS WIN TAC.xlsx`, y las decisiones tomadas para la importación.

## 1. Origen de los datos

- Archivo: `PRODUCTOS WIN TAC.xlsx` (hoja "Hoja1"), entregado por el cliente.
- Estructura: coincide con el esquema de costeo ya implementado en el sistema —
  ITEM, CÓDIGO, DESCRIPCIÓN, MARCA, UM, COSTO (Anterior/Actual/Resultado),
  MÍNIMO (%/Precio/Utilidad), LISTA (%/Precio/Utilidad), STOCK — más una
  columna adicional "Catálogo" con códigos de barra (EAN), no usada en el sistema.
- **8,957 productos** en total (no ~5,000 como se estimó inicialmente).

## 2. Calidad de datos encontrada

| Chequeo | Resultado |
|---|---|
| Descripciones vacías | 0 |
| Códigos vacíos | 0 |
| Códigos duplicados | 0 |
| Marcas vacías | 0 |
| Precios negativos o en cero | 0 |
| Textos que exceden los límites de columna (nombre/código/marca) | 0 |
| Acentos y "ñ" | Codificados correctamente (verificado, ej. "CASTAÑA") |
| Marca con valor numérico en vez de texto | 35 filas |
| Stock con valores extremos (ej. 1,440,960 unidades; mediana real: 10) | ~10 filas, no filtradas por decisión del cliente |
| % de margen (MÍNIMO/LISTA) que excede rangos normales | 668 filas — ver sección 4 |

## 3. Decisiones tomadas (confirmadas con el cliente)

1. **Categoría**: se asigna aleatoriamente entre las categorías ya activas de wintac
   (vía `ORDER BY RANDOM()` al momento de insertar) — no hay columna de categoría en
   la hoja de origen.
2. **Stock**: se importa tal cual viene en la hoja, sin ajustar los valores extremos.
3. **Marca numérica (35 filas)**: se reemplaza por el texto `"Genérico"`.
4. **Columna "Catálogo" (código de barras)**: se ignora por completo, no se importa
   (no existe un campo para esto en el sistema).
5. **Imágenes**: no se incluyen en la importación masiva — se suben una por una desde
   el panel admin después, igual que se hizo con GenPharma.
6. **Catálogo actual de wintac**: se elimina por completo (`DELETE FROM productos
   WHERE tienda_id = 1`) y se reemplaza íntegramente por estos 8,957 productos.
   Verificado que esto no afecta pedidos existentes — `pedido_detalles` guarda una
   copia de los datos del producto al momento de la compra, no depende de que el
   producto siga existiendo.

## 4. Problema encontrado y corregido: rango de las columnas de porcentaje

Al probar la importación localmente (antes de tocar producción), varias filas
fallaron: para productos con costo casi cero (ej. costo actual = S/ 0.01), el
porcentaje de margen que trae la hoja se dispara a valores absurdos pero
matemáticamente correctos — hasta **13,799,900%** en el caso más extremo (668 filas
superan 10,000%).

Las columnas `minimo_porcentaje` y `lista_porcentaje` eran `DECIMAL(6,2)` (máximo
9,999.99) y no soportaban estos valores. Se ampliaron a `DECIMAL(12,2)` mediante una
migración nueva (`productos_minimo_porcentaje_widen`, `productos_lista_porcentaje_widen`
en `database/migrar.php`) — el precio de venta calculado (`costo_actual × (1 + %
lista / 100)`) sigue siendo correcto en todos los casos; solo cambia el rango que
puede almacenar el campo de porcentaje.

## 5. ⚠️ Hallazgo y corrección: rendimiento del panel admin con este volumen

Con los 8,957 productos cargados, la página **`admin/productos.php` (lista de
productos) pesaba 29.3 MB** — no tenía paginación, cargaba todos los productos en
una sola tabla HTML, con el objeto completo de cada producto embebido como JSON en
cada fila (para el botón de editar). Esto habría hecho el panel de administración
muy lento o directamente inutilizable una vez subido este catálogo.

**Corregido:** se implementó paginación real del lado del servidor (`LIMIT`/`OFFSET`
en la consulta SQL, 20 productos por página) con una barra de paginación centrada
("‹ 1 2 … n Siguiente ›"), y el buscador/filtros de categoría y estado pasaron a
funcionar contra todo el catálogo vía parámetros GET (`?buscar=&cat=&estado=&pagina=`),
no solo contra lo visible en pantalla como antes.

- Peso de la página: **29.3 MB → 109 KB** (reducción de ~270x).
- Verificado: paginación, búsqueda, filtro por categoría/estado y el modal de
  edición (con datos reales de la fila) funcionan correctamente contra las 8,957 filas.

El catálogo público (`catalogo.php`/Inicio, que consume `api/index.php?action=productos`)
pesa **2.2 MB** de JSON con este volumen — funciona, pero es notablemente más pesado
que con GenPharma (1,325 productos ≈ 370 KB). Es un problema menor comparado con el
del admin, pero vale la pena tenerlo en cuenta a futuro si el catálogo sigue creciendo.

## 6. Verificación local (antes de tocar producción)

- Se vació `productos` de `tienda_id = 1` en la base local y se corrió el script de
  importación completo: **8,957 filas insertadas, 0 errores**.
- Verificaciones puntuales:
  - `ACE0108` → precio S/ 9.50, stock 244, marca CAPRI, UM BOT — coincide con la hoja.
  - `TIN0001` (caso extremo) → costo actual S/ 0.01, % lista 14,900.00, precio S/ 1.50
    — coincide con la hoja, ya no rompe el guardado.
  - Marca "Genérico" aplicada a exactamente 35 productos.
  - Suma total de stock: 9,051,374 unidades (coincide con el archivo de origen).
  - Distribución de categorías razonablemente pareja entre las categorías activas.

## 7. Archivos generados/modificados

- `database/importacion_wintac_2026.sql` — script de importación (8,957 INSERTs,
  categoría aleatoria vía subconsulta, precio recalculado desde costo+%).
- `database/schema.sql` / `database/migrar.php` — ampliación de
  `minimo_porcentaje`/`lista_porcentaje` a `DECIMAL(12,2)`.

## 8. Pasos pendientes para producción

1. Desplegar el código (incluye el fix de paginación del admin — commit pendiente
   de confirmar).
2. Correr en el VPS de wintac la migración de ampliación de columnas
   (`minimo_porcentaje`/`lista_porcentaje` a `DECIMAL(12,2)`).
3. Correr el `DELETE FROM productos WHERE tienda_id = 1;` (ya entregado por
   separado para ejecutar en paralelo).
4. Copiar `database/importacion_wintac_2026.sql` al servidor y ejecutarlo con `psql`.
