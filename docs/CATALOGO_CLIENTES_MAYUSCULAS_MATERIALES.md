# Catálogo de Materiales: clientes y mayúsculas

## Consulta por cliente y temporada

**Materiales → Ítems** inicia consultando la temporada activa de Materiales.
La selección queda visible en el filtro y en el texto sobre la tabla. El usuario
puede elegir una temporada histórica o **Todas las temporadas** expresamente.
Si no existe temporada activa, se muestra la consulta de todas las temporadas.

El selector ofrece una opción `CÓDIGO · NOMBRE` por cliente maestro. Al cambiar de
temporada se conserva ese cliente si tiene un registro en ella. Si no lo tiene,
se restablece **Todos**, se limpia la categoría y se consulta nuevamente, de modo
que los controles y las filas representen el mismo filtro. Las categorías también
se obtienen para el cliente y temporada seleccionados.

Con **Todas las temporadas**, seleccionar un cliente reúne sus ítems de todos los
períodos. La columna Temporada distingue cada registro. Dos maestros que comparten
nombre se ofrecen por separado con sus respectivos códigos. Los registros antiguos
sin vínculo a un maestro conservan su opción `CÓDIGO · NOMBRE (histórico)` y su
identidad; no se vinculan por coincidencia de nombre.

La tabla y las descargas XLSX/CSV comparten filtros. La descarga conserva las columnas
de temporada y cliente necesarias para reimportar el catálogo. Descargar incluye
todas las páginas y no modifica datos.

La API incorpora `cliente_global_id` y `temporada_actual=1`, además de
`temporada_seleccionada_id` y `catalogos.clientes_maestros` en la respuesta. El filtro
anterior `cliente_id` sigue representando el registro de Materiales de una sola
temporada. No se permite enviar ambos filtros de cliente simultáneamente. Omitir
los filtros de temporada conserva la consulta completa de la API anterior; Oficina
solicita la temporada activa expresamente al abrirse.

## Textos del catálogo

Los nombres de ítems, categorías comerciales, tipos de ítem y unidades se guardan
en mayúsculas con espacios normalizados desde los formularios y la importación.
Se conservan tildes y Ñ. Los nombres de categorías y tipos mantienen su validación
de duplicados por nombre normalizado, incluso si están inactivos. La categoría de
una habilitación de proveedor recibe el mismo formato.

La migración `2026_10_25_150000_normalizar_etiquetas_catalogo_materiales` aplica el
formato al catálogo existente y a las categorías de proveedores, por bloques y en
una transacción. Conserva UUID, códigos, vínculos, estados, cantidades, fechas,
responsables, fotos, auditorías y snapshots de documentos. No consolida ítems ni
clientes. Si el texto en mayúsculas supera el máximo del campo, rechaza la operación
completa y señala el registro para revisión. El rollback conserva los nombres
normalizados porque la caja original no puede reconstruirse.

Las unidades mantienen códigos y alias canónicos, por ejemplo `kg` y `unidades`,
para las conversiones y la compatibilidad con los dispositivos. Sus nombres y las
etiquetas del catálogo y los selectores de Oficina se muestran en mayúsculas. Los
valores enviados por los selectores y las equivalencias históricas se conservan.

Descargar y reimportar sin editar sigue produciendo cero creaciones y cero
actualizaciones. También conserva la representación de una fila histórica sin
cambios. Una previsualización anterior a la normalización puede perder vigencia;
en ese caso debe previsualizarse nuevamente antes de confirmar.

## Despliegue y verificación

Aplicar `php artisan migrate --force`, compilar Oficina con `npm ci` y
`npm run build`, limpiar cachés y reiniciar los workers. No requiere APK ni OTA.
Los dispositivos reciben los nuevos nombres del catálogo desde el servidor.

Verificar que el catálogo abra en la temporada activa y que un cliente con ítems
en dos períodos figure una vez. Consultar ambos períodos, cambiar de temporada,
descargar el filtro y reimportarlo sin editar. Crear un ítem y una unidad con
minúsculas y comprobar las etiquetas, manteniendo las equivalencias de cantidades.
