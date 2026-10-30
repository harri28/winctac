# Integración del catálogo con el ERP de WIN TAC

El catálogo virtual se alimenta desde el ERP de la empresa, **Fox Smart Business
(FoxSoft)**: códigos, descripciones, marcas, unidad de medida, costos, márgenes,
precios y stock. Hay dos mecanismos, que usan el mismo motor
(`includes/erp_sync.php`) y las mismas reglas.

| Mecanismo | Cuándo se usa | Dónde |
|---|---|---|
| **Importación del listado del ERP** (Excel/CSV) | Hoy, de forma manual: el administrador exporta el listado desde Fox y lo sube | Admin → Productos → **Integración ERP** |
| **API REST de sincronización** | Sincronización automática, cuando FoxSoft (o un conector) pueda enviar los datos | `POST /api/erp.php?action=productos` |

Cada sincronización queda registrada en el **historial** (fecha, origen, filas
leídas, actualizadas, creadas, sin cambios, no encontradas y con error), en la
tabla `erp_sincronizaciones`.

## Reglas de sincronización

- **Emparejamiento por código.** Cada fila se busca por el `codigo` del producto
  dentro de la tienda (sin distinguir mayúsculas). El código del ERP es el mismo
  que se usó al cargar el catálogo.
- **Precio de venta = costo actual × (1 + % lista / 100)**, redondeado a 2
  decimales: la misma fórmula que usa el formulario de Productos del panel. Si
  la fila no trae costo ni % lista pero sí un precio, se usa ese precio.
- **Costo anterior.** Cuando el costo cambia, el costo previo pasa a
  `costo_anterior`, igual que en el listado del ERP.
- **Stock** se reemplaza por el del ERP (un solo almacén).
- **Campos vacíos no borran datos.** Si una columna no viene o viene vacía, se
  conserva el valor del catálogo. Las marcas numéricas del ERP (dato inválido)
  se ignoran.
- **Productos nuevos** (códigos que no existen en el catálogo) solo se crean si
  se marca la opción. Se crean **ocultos** para que el administrador les asigne
  categoría e imagen antes de publicarlos.
- **Atómico.** Todo el archivo o la petición se aplica en una sola transacción:
  si algo falla, no se guarda nada.
- No se modifican categorías, imágenes, descripción técnica, etiquetas ni la
  visibilidad de los productos existentes.

## 1. Importación del listado del ERP

1. En Fox Smart Business, exportar a Excel el **Listado de precios** (columnas
   ITEM, CODIGO, DESCRIPCION, MARCA, UM, COSTO Anterior/Actual, MINIMO %,
   LISTA % / Precio, STOCK).
2. En el panel: **Productos → Integración ERP → Importar listado del ERP**,
   elegir el archivo y pulsar **Ver vista previa**.
3. La vista previa muestra las columnas reconocidas, cuántos productos se
   actualizarán, cuáles no existen en el catálogo y ejemplos de cambios de
   precio y stock (antes → después). **Hasta aquí no se ha guardado nada.**
4. Pulsar **Aplicar cambios**. El resultado queda en el historial.

Formatos aceptados:
- **Excel `.xlsx`** con el formato del Listado de precios de Fox (encabezado de
  dos filas con celdas combinadas), que se detecta automáticamente.
- **CSV** (separado por `;`, `,` o tabulador; UTF-8 o Windows-1252) con una
  fila de encabezados. Nombres reconocidos: `codigo`, `descripcion`/`nombre`,
  `marca`, `um`, `costo`/`costo actual`, `stock`, `minimo %`, `lista %`,
  `precio`. Admite decimales con coma o punto.

## 2. API REST de sincronización

**Activación:** en Admin → Integración ERP, pulsar **Generar token**. Sin
token, la API responde `503` (desactivada). Regenerar el token invalida el
anterior.

**Autenticación:** cabecera `Authorization: Bearer <token>` en todas las
peticiones. Si falta o es incorrecto, responde `401`.

### Verificar conexión

```http
GET https://www.wintac.com.pe/api/erp.php?action=ping
Authorization: Bearer <token>
```
```json
{"success": true, "tienda_id": 1, "hora_servidor": "2026-10-30T10:00:00-05:00"}
```

### Sincronizar productos

```http
POST https://www.wintac.com.pe/api/erp.php?action=productos
Authorization: Bearer <token>
Content-Type: application/json
```
```json
{
  "crear_nuevos": false,
  "simular": false,
  "productos": [
    {"codigo": "12 0001", "costo": 6.00, "lista_porcentaje": 33.33, "stock": 11},
    {"codigo": "ABL0002", "nombre": "ABLANDADOR DE CARNE BADIA 127.6 GR FRASCO",
     "marca": "BADIA", "um": "UND", "costo": 8.2998, "stock": 13}
  ]
}
```

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `codigo` | texto | sí | Código del artículo en el ERP |
| `nombre` | texto | para crear | Descripción comercial |
| `marca`, `um` | texto | no | Marca y unidad de medida |
| `costo` | número | no | Costo actual (S/) |
| `stock` | número | no | Stock actual |
| `minimo_porcentaje`, `lista_porcentaje` | número | no | Márgenes (%) |
| `precio` | número | no | Precio final, si no se envía costo y % lista |
| `crear_nuevos` | bool | no | Crear (ocultos) los códigos que no existan |
| `simular` | bool | no | Calcula y responde sin guardar nada (para pruebas) |

Respuesta:
```json
{
  "success": true, "simulado": false, "total": 2,
  "actualizados": 1, "creados": 0, "sin_cambios": 1,
  "no_encontrados": [], "errores": []
}
```

Hasta 20 000 productos por petición. El ERP puede enviar el catálogo completo
(los productos sin cambios se cuentan y no se tocan) o solo lo modificado.

### Opciones de conexión con Fox Smart Business

Fox Smart Business es una aplicación de escritorio en el local, así que la
sincronización automática depende de lo que confirme FoxSoft (ver
`Solicitud Foxsoft.docx`):
- **Si FoxSoft tiene módulo de API/webhooks:** configurarlo para que llame al
  endpoint anterior.
- **Si solo hay acceso a su base de datos:** un script programado en la PC del
  local (tarea de Windows cada X minutos) lee artículos, costo y stock de la
  base de Fox y los envía a la API. No hace falta abrir puertos en el local,
  porque la conexión sale del local hacia la web.
- **Mientras tanto:** se usa la importación del listado desde el panel.

## 3. Compras de la tienda física → Programa de puntos

Las compras registradas en el ERP (comprobantes de la tienda física) también
acumulan puntos, con la misma regla que las compras web:
**puntos = piso(total × puntos por cada S/ 1)** (Admin → Fidelización →
Programa de puntos).

- **Identificación del cliente:** por su **DNI/CE**, que es obligatorio al
  afiliarse (registro web). Los clientes registrados antes pueden completarlo
  en *Mis puntos*.
- **Sin doble acreditación:** el número de comprobante es único por tienda.
  Un comprobante ya registrado se rechaza.
- **DNI aún no afiliado:** la compra queda *pendiente de afiliación* y sus
  puntos se acreditan automáticamente cuando esa persona se registra (o
  completa su DNI).
- **Comprobante anulado en el ERP:** se anula en el panel y los puntos se
  revierten (movimiento *Revertido* en el historial del cliente).
- Cada acreditación queda en el historial del cliente como
  *Compra en tienda B001-000123*.

Tres formas de registrar las compras, todas con las mismas reglas:

| Forma | Dónde |
|---|---|
| Manual (un comprobante) | Admin → Fidelización → **Compras en tienda** → *Registrar compra* |
| Reporte de ventas del ERP (.xlsx/.csv con DNI, Comprobante o Serie+Número, Fecha y Total) | Admin → Fidelización → **Compras en tienda** → *Importar ventas del ERP* |
| Automática por API | `POST /api/erp.php?action=compras` |

### API: registrar compras

```http
POST https://www.wintac.com.pe/api/erp.php?action=compras
Authorization: Bearer <token>
Content-Type: application/json
```
```json
{
  "compras": [
    {"dni": "45678912", "comprobante": "B001-000123", "fecha": "2026-10-29", "monto": 85.90}
  ]
}
```
`fecha` acepta `AAAA-MM-DD` o `DD/MM/AAAA`. Respuesta, con el resultado de
cada comprobante (los errores no afectan a los demás):
```json
{
  "success": true, "total": 1, "otorgados": 1, "pendientes": 0, "errores": 0, "puntos": 85,
  "resultados": [{"comprobante": "B001-000123", "estado": "otorgado", "puntos": 85}]
}
```
`estado` puede ser `otorgado`, `pendiente` (DNI no afiliado aún) o `error`
(con el motivo en `error`).

## 4. Gestión de productos desde el panel

Además de la sincronización, el administrador gestiona el catálogo en
**Admin → Productos**:
- **Agregar** un producto: botón *Nuevo producto* (nombre, código, marca, UM,
  categoría, costo, % mínimo y % lista, con el precio calculado; stock, imagen
  principal, hasta 4 imágenes adicionales, ficha técnica y etiquetas de búsqueda).
- **Modificar / actualizar:** botón *Editar* de cada fila, con los mismos campos.
- **Publicar u ocultar** un producto (o todos) sin borrarlo, y **eliminarlo**.
- Búsqueda, filtros por categoría y estado, y paginación sobre todo el catálogo.
- **Categorías:** Admin → Productos → Categorías.

### Guion de demostración (evidencia)

1. Admin → Productos → *Nuevo producto*: crear un producto de prueba con
   costo y % lista, y comprobar el precio calculado.
2. Verlo publicado en `/catalogo` y en su página `/producto/{id}/{nombre}`.
3. Editarlo (cambiar costo y stock) y comprobar el cambio en la tienda.
4. Integración ERP: subir el Listado de precios exportado de Fox, mostrar la
   vista previa, aplicar y mostrar el historial.
5. API: `ping` y una petición con `"simular": true`, con la respuesta.
6. Ocultar o eliminar el producto de prueba.

### Guion de demostración — Programa de puntos

1. Admin → Fidelización → **Beneficios / Recompensas**: tener al menos una
   recompensa activa (por ejemplo, 100 pts = S/ 10 de descuento).
2. **Afiliación:** `/cuenta/registro` con nombre, DNI, celular y correo
   (DNI y celular obligatorios).
3. **Compra web:** hacer un pedido con esa cuenta y confirmarlo en Admin →
   Pedidos; los puntos se acreditan.
4. **Compra en tienda física / ERP:** Admin → Compras en tienda → registrar un
   comprobante con el DNI del cliente (o importar el reporte de ventas).
5. **Consulta de saldo en línea:** `/cuenta/puntos` muestra el saldo y el
   historial (compra web y *Compra en tienda …*).
6. **Canje:** en `/cuenta/puntos`, *Canjear* una recompensa; se genera un
   cupón `PTS-XXXXXX` y se descuentan los puntos.
7. Usar el cupón en el checkout de un nuevo pedido.
