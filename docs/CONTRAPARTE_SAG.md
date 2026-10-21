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
