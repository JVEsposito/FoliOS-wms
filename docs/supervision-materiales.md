# Supervisión inicial de Materiales (PR 7 del plan)

Acceso: **Materiales → Supervisión**, `/oficina/materiales/supervision`.
Reutiliza `materiales.inventario` y la autorización de consulta de kardex:
Administrador, Supervisor de Materiales y Consulta con ese módulo habilitado.
Los perfiles personalizados conservan sus restricciones. No agrega facultades
para modificar, aprobar o anular operaciones. No requiere una APK nueva.

## Lecturas y alcance

- **Existencias actuales**: saldos de folios activos por almacén, temporada,
  cliente y búsqueda de folio o ítem. Cantidades separadas por unidad y folios
  distintos, incluso cuando están repartidos entre almacenes. Disponible excluye
  reservas, bloqueos operativos, motivos de bloqueo y vencimientos. La reserva
  puede coincidir con material bloqueado; ambos indicadores no se suman.
- **Movimientos de inventario / almacenes**: registros separados, con usuario,
  tipo, cantidad, referencias y saldos anteriores/resultantes cuando existen.
  No se suman las fuentes: un retiro puede tener un reflejo en ambas.
- **Auditoría de recepciones**: eventos de documentos conservados y registros de
  eliminación. La eliminación sigue consultable sin la recepción original; su
  detalle muestra hasta 100 eventos y 100 líneas del respaldo, con sus totales.
  Los eventos archivados no vuelven a contarse en el listado. No expone fotos,
  rutas de almacenamiento, hashes ni antecedentes de transporte del respaldo.
- **Diferencias de inventario**: resultados vigentes, excluyendo coincidencias y
  versiones sustituidas por reconteos. Separa faltantes de sobrantes por unidad;
  muestra la decisión y el estado de la toma, incluidas las anuladas. El usuario
  identifica al responsable del conteo, no a quien tomó cada decisión.

Fechas y usuario filtran actividad; no se aplican a las existencias actuales.
El almacén filtra saldos y movimientos de almacenes, donde existe esa referencia.
Las fechas son días corridos inclusivos en `app.operational_timezone` (Chile por
defecto), con un máximo de 366 días por consulta. El servidor rechaza filtros
incompatibles. Se pueden leer temporadas anteriores sin modificarlas.

Los indicadores agregan el conjunto filtrado completo; el listado pagina de a
25 registros. El detalle permanece dentro del panel. Las tres rutas API son
GET, requieren sesión y autorización, y responden con `Cache-Control: no-store`.

La capacidad y asignación de bodegas, registros provisionales, consumos y
comparaciones de períodos permanecen en las etapas siguientes del plan.
