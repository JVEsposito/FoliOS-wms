# Valores de compra de Materiales

En **Materiales → Recepciones**, seleccionar una recepción confirmada y pulsar
**Valorizar compra**. Se puede registrar inmediatamente después de confirmar el
ingreso o posteriormente. La falta de precio no impide recibir, ubicar, consumir
ni despachar. No se inventan valores para documentos anteriores.

## Importes y unidades

Se registra el precio unitario **neto antes de IVA**, en CLP, USD o EUR, por la
**unidad base original del detalle**. Si se recibieron 2 cajas equivalentes a
200 unidades, el precio solicitado es por UNIDAD, no por CAJA. La pantalla muestra
la cantidad aceptada y la unidad junto al precio. Un precio de caja debe dividirse
por su equivalencia antes de ingresarlo. No se recalculan equivalencias históricas.

Se admiten hasta nueve enteros y seis decimales; el formulario acepta punto o coma
decimal y no separadores de miles. Vacío representa pendiente; cero representa un
valor conocido sin costo. Al dejar un precio pendiente también se retira su moneda.
El total es cantidad aceptada × precio; no incluye lo rechazado. Se calcula con
aritmética decimal y redondeo a mitad hacia arriba por línea: CLP cero decimales,
USD/EUR dos. No se suman monedas distintas ni se convierten a pesos automáticamente.

Estos son valores documentales de ingreso, no una valorización del stock restante.
No se generan facturas, IVA, cuentas por pagar ni asientos de Finanzas.

## Accesos

El permiso granular **Recepciones → Asignar y corregir valores de compra netos**
(`recepciones.valorizar`) se asigna en **Administración → Accesos → Perfiles**.
Admite los niveles base Administrador y Supervisor de Materiales, con acceso a
Recepciones. Puede asignarse al Administrador de bodega y al Supervisor de bodega
nivel 1 sin concederles administración general de usuarios ni otros módulos.
Los nombres y códigos de estos perfiles son configurables en cada instalación;
no se deducen derechos buscando palabras como «admin» o «nivel 1» en su nombre.

El Administrador protegido y el administrador histórico sin perfil conservan su
alcance administrativo. Los perfiles personalizados, incluidos los de bodega,
requieren seleccionar expresamente la nueva acción; no se concede por tener permiso
para confirmar. Los perfiles inactivos, roles de consulta y operadores no escriben
precios aunque intenten invocar directamente el endpoint.

La lectura de valores e historial económico se limita a quienes pueden supervisar
Recepciones. Operadores de recepción conservan sus datos físicos sin recibir precios
ni eventos de valorización. La exportación exige el mismo permiso de consulta.

## Correcciones e historia

Cada guardado exige motivo, UUID de operación y versión conocida. Una transacción
bloquea la recepción y sus líneas, comprueba la temporada activa y registra un evento
`valorizada` con usuario, fecha, motivo, precios anteriores y nuevos, unidades,
cantidades aceptadas y totales. Se admiten cambios parciales por línea. Un UUID
reintentado con el mismo contenido y actor no duplica eventos; cambiar su contenido
o reutilizarlo para otra recepción produce conflicto. Las líneas se comparan por ID,
sin depender del orden del envío.

La operación no modifica folios, inventario, reservas, cantidades ni el snapshot
físico de confirmación. Las recepciones anuladas no admiten cambios ni se exportan
como compras vigentes. Para reconstruir administrativamente una recepción valorizada,
primero deben dejarse sus valores pendientes mediante un cambio auditado; los eventos
anteriores conservan la evidencia. La eliminación administrativa conserva el historial
y los valores en la evidencia existente de eliminación.

Las temporadas cerradas permiten consulta y descarga especificando `temporada_id`,
pero no modificaciones económicas. El cierre no vuelve a valorizar documentos.

## Consulta y descarga

El filtro **Valor de compra** distingue recepciones confirmadas con alguna línea
aceptada pendiente y recepciones con todas las líneas aceptadas valorizadas. Las
líneas aceptadas en cero no crean pendientes. Cada detalle muestra sus valores y su
historial; las tarjetas del listado indican los pendientes de cada recepción.

**Compras netas CSV** descarga todas las páginas de recepciones confirmadas que
cumplen los filtros actuales, no solo las tarjetas visibles. Incluye temporada,
recepción, guía, cliente, proveedor, código/nombre de ítem, unidad base, cantidad
aceptada, precio, moneda, total y estado. No suma monedas ni toma el saldo actual.
Las celdas de texto se protegen contra fórmulas de planilla.

- `POST /api/materiales/recepciones/{recepcionMaterial}/valorizacion`.
- `GET /api/materiales/recepciones?valorizacion=pendiente|completa`.
- `GET /api/materiales/recepciones/valores-compra.csv` (mismos filtros del listado;
  exporta solo confirmadas).

## Despliegue

Respaldar la base operativa y aplicar
`2026_10_26_150000_agregar_valores_compra_recepciones_materiales` con
`php artisan migrate --force`. Compilar Oficina con `npm ci && npm run build`,
limpiar cachés y reiniciar workers. No cambia el runtime móvil ni requiere APK/OTA.

Después, seleccionar la nueva acción en los perfiles reales Administrador de bodega
y Supervisor de bodega nivel 1 y volver a iniciar sesión para actualizar capacidades.
Comprobar: confirmar sin precio, asignarlo después, corregirlo con motivo, distinguir
cero de pendiente, contrastar stock y descargar un filtro. El rollback de esquema
elimina las columnas actuales; respaldar antes. Los eventos conservan su evidencia.
