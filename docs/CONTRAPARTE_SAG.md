# Contraparte SAG

Oficina → Frigorífico → Contraparte SAG reúne la existencia de productos con condición,
los responsables de la planta y sus documentos fitosanitarios. Inspección SAG conserva
la preparación física, sus traslados y la resolución por destino.

## Instalación de la base

1. Aplicar migraciones. El perfil inicial de Administrador recibe el módulo
   `frigorifico.contraparte-sag`. Se crea el perfil asignable **Contraparte SAG**,
   con acceso a esta pantalla e Inspección SAG.
2. Registrar los datos reales del establecimiento y sus responsables. El perfil de
   acceso no acredita a una persona ante SAG. Cada responsable conserva su categoría
   y referencia de autorización; se desactiva en lugar de eliminarse.
3. Antes de retomar operaciones, usar **Registrar corte inicial**. Incorpora los
   folios existentes con condición declarada o autorización SAG activa de la temporada
   actual, sin inventar inspecciones ni movimientos históricos. Repetir el corte no
   duplica existencias. Los GET no generan cortes ni escriben registros.

La condición declarada recibida desde validación o recepción externa se conserva en
el detalle; no se transforma en una autorización por país.

## Existencias

Cada saldo registra cajas y una unidad por folio no agotado (pallet o saldo), su especie,
cliente, CSG y condición. Las autorizaciones de varios destinos nunca multiplican las
cajas. Una variación deja actividad, referencia, entradas/salidas y saldo por folio.
Las inspecciones y el cierre físico del despacho actualizan estas diferencias dentro
de sus transacciones. El corte puede repetirse para conciliar operaciones previas.

El saldo del movimiento corresponde a ese folio; los totales superiores suman la
existencia del filtro completo. Se pueden consultar temporadas anteriores por API.

## Decisiones de planta

- Toda la fruta es fresca: **FRESCO** es un texto fijo de las planillas SAG.
- Los formularios de repaletizaje y anulación usan códigos **DR**.
- La primera versión implementada de cada formato de planta es **01**.
- La repa se documenta según la solicitud, sin exigir ni imprimir país de destino.
- Las versiones de planta son independientes de las revisiones de documentos oficiales.
- La contraparte registra avisos, entregas y resoluciones con sus respaldos. La app no
  firma por inspectores ni envía correos automáticamente.

Fuentes de diseño: planillas entregadas por la planta y Manual para Externos Programa
Origen v04, 10-07-2024, secciones 6.1.2.2 y 6.8:
https://www.sag.gob.cl/sites/default/files/Manual%20para%20Externos%20v4%202024-07-10.pdf

## Repaletizaje y anulación / devolución

En **Documentos SAG**, crear un borrador y seleccionar un responsable activo. Para la
repa, cargar su código REPA o abrir **Documento SAG** desde su historial. El origen y
resultado se toman del registro físico, incluidas cantidades parciales y divisiones.
Se completa la última actividad y su número cuando aún no estaban registrados.
No se exige ni imprime país de destino.

La anulación / devolución permite seleccionar folios escaneando su número, cantidades
parciales y causal, o devolver certificados sin seleccionar fruta. Generar el formulario
documenta la solicitud: no borra fruta, no anula una repa física y no simula una
resolución del SAG. Esos hechos conservan sus respectivos registros operacionales.

**Emitir** asigna el correlativo por tipo y temporada en una transacción y conserva
una fotografía del formato, establecimiento, responsable y detalle. Solo se edita el
borrador. PDF y Excel de un documento emitido siempre usan esa fotografía, aunque el
catálogo de formatos cambie después. Se pueden descargar ambos formularios en blanco.

El seguimiento distingue emitido, enviado y aprobación o rechazo recibido. El usuario
registra la referencia y el nombre/cargo de quien resuelve; puede adjuntar PDF o fotos
de hasta 10 MB. Cada respaldo queda privado y no se reemplazan archivos anteriores.
Las descargas verifican el documento al que pertenece cada archivo.

Los cambios de estado no envían correos ni acceden a plataformas SAG. El perfil de
consulta puede leer y descargar, pero no crear, emitir ni adjuntar. Un documento de
temporada cerrada conserva su consulta y sus descargas; las escrituras requieren la
temporada activa.

## Cambio de mercado

El lote de Inspección SAG ofrece **Formulario cambio de mercado**, también disponible
en Documentos SAG cargando su código. Recupera los destinos anteriores conservados al
crear el lote y los destinos solicitados. El cambio se documenta con F-CER-PPS-PA-002,
versión de planta 01. Programa, motivo, última actividad y su número se revisan antes
de emitir. El cálculo de kilos usa cajas por peso neto del envase, incluida composición
mixta; si falta el peso, se completa expresamente. No se sustituye un dato faltante por
cero. Los kilos y destinos revisados quedan guardados con el documento emitido.

La emisión no autoriza el nuevo mercado. La resolución por destino se registra en el
lote mediante el flujo existente de Inspección SAG. Se pueden adjuntar la resolución
y los certificados devueltos o asignados al seguimiento documental.

## Despacho y Comex

Desde el calendario de embarques, el expediente de Comex o Documentos SAG se carga
el embarque y su carga efectiva. Se excluyen folios descartados o reemplazados y se
conserva una línea por composición del pallet mixto. Con varios instructivos se exige
elegir uno y marcar expresamente los pallets que le corresponden. No se reparte fruta
entre destinos por suposición.

F-CER-PPS-PA-001 usa versión de planta 01. Agrupa los productos, totaliza envases y kilos
y agrega el anexo por folio con variedad, provincia, comuna, CSG y CSP. Recupera el CSP
de origen para fruta externa y el de la planta para fruta propia. Los datos faltantes
del origen y los kilos se completan antes de emitir. Los números de folio y códigos
conservan ceros iniciales en Excel. Los antecedentes completos acompañan la planilla.

La modalidad habitual produce **Datos para Multipuerto**, con correlativo interno y
referencia oficial separados. La modalidad **Contingencia** requiere indicar el motivo.
No reemplaza la emisión ni la validación oficial en Multipuerto. Inspección, NIMF 15 y
tratamiento se declaran expresamente; un prefrío no se transforma automáticamente en
tratamiento fitosanitario. Comex consulta los documentos SAG vinculados mediante su
conexión de lectura. El seguimiento de su planilla oficial conserva su respaldo y estado.

## PDA, tablet y celular

La APK ofrece **Contraparte SAG** para capturar los cuatro tipos, escanear folios,
guardar/editar borradores, registrar emisión/envío/resolución y compartir PDF o Excel.
Consulta responsables activos, busca fuentes por código y pagina documentos. Las
capturas locales se separan por servidor, usuario y equipo. Un envío sin respuesta se
reintenta con el mismo identificador, también después de cerrar la aplicación.

La migración habilita el módulo móvil en el perfil **Contraparte SAG** y en los perfiles
Administrador predeterminados. Otros perfiles se habilitan desde Administración,
con Oficina → Contraparte SAG y Tablet → Contraparte SAG. La persona debe volver a
iniciar sesión para recibir los permisos. El dispositivo mantiene su autorización
existente. Un token móvil de otro módulo no obtiene acceso por tener un rol supervisor.

No se agregan dependencias nativas ni se cambia el runtime. Las APK compatibles reciben
la actualización OTA en sus canales respectivos: `production` (tablet/celular) y `pda`.
Primero desplegar las migraciones y los assets del backend, luego publicar ambos bundles.

Prueba de planta: configurar establecimiento y responsable; registrar el corte inicial;
crear una repa con condición sin exigir destinos, anulación parcial, cambio de mercado
y despacho de cinco pallets (incluido uno mixto). Revisar cantidades, kilos, CSP/CSG,
firmas, anexos y ambos formatos. Registrar la referencia y adjuntar los respaldos reales
desde Oficina. La emisión documental no modifica por sí sola el inventario ni las
autorizaciones fitosanitarias.
