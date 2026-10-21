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
