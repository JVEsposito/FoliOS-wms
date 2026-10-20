# Información operacional para el camarero

## Propósito de los movimientos

Labores muestra el propósito, el objetivo y su motivo tanto en la cola como durante
la ejecución. Se distinguen despacho, separación/preparación de cargas, segregación
de retenidos, inspección SAG/USDA, recepciones, almacenamiento y reordenamientos.

Si una maniobra resuelve varios objetivos, se presenta el principal y se enumeran
los demás. El paso físico se explica por separado: retirar temporalmente un
bloqueador para un despacho no significa que ese bloqueador se vaya a despachar.
Las confirmaciones de folio/PIN y las reglas de movimiento mantienen su contrato.

## Cargas activas y terminadas

Desde Labores, **Cargas activas y terminadas del turno** abre una consulta de estado
sin perder la tarea ni las sesiones de cámara en curso. Usa el permiso existente
`consultar-cargas-operacion`; no requiere acceso al catálogo de Oficina.

`GET /api/cargas/operacion-turno` incluye las cargas activas de la temporada vigente
y las cerradas, despachadas o canceladas durante el turno actual. No expone borradores
ni cancelaciones de cargas nunca publicadas. Las cerradas y despachadas conservan el
detalle de sus asignaciones históricas aunque ya se hayan liberado sus reservas.

Se muestra el estado, prioridad, orden, progreso de separación, pallets pendientes,
en andén y despachados, incidencias, cámara objetivo, andén, patente, embarque,
instructivos y observación. El detalle permite consultar cada pallet y su ubicación.
La vista solo consulta información y se actualiza automáticamente; si la conexión
falla conserva la última respuesta indicando que está sin actualizar.

El turno usa `VentanasVerificacion`, la misma ventana operacional ya configurada
con `VERIFICACIONES_TURNOS_INICIO` y `VERIFICACIONES_TURNOS_DURACION_HORAS`.
Por omisión son 06–14, 14–22 y 22–06 en la zona operacional de la planta.
La pantalla muestra las fechas y horas efectivas. Los intervalos son `[inicio, fin)`
y el ETag cambia al comenzar otro turno aunque no se modifique ninguna carga.
Para una carga cerrada manda `cerrada_at`, para una cancelada `cancelada_at`;
el estado legado `despachada`, que no tiene fecha propia, usa `updated_at`.

## Solicitudes de inspección SAG/USDA

Se crean en **Oficina → Frigorífico → Inspección SAG** (`POST /api/inspeccion-sag/lotes`),
seleccionando los pallets y destinos. No se ingresan como movimientos manuales de
tablet. `ServicioInspeccionSag::crear` sincroniza la preparación física del lote
con el planificador dirigido cuando está habilitado.

Muestreo USDA e Inspección Origen requieren preparación física y pueden generar un
plan `preparacion_inspeccion`, con su reserva de sector y movimientos correspondientes.
Inspección en línea, fumigación y cambio de mercado no requieren esa preparación.
Si falta capacidad o el despliegue dirigido no está activo, crear la solicitud no
garantiza que aparezca inmediatamente una maniobra ejecutable en la tablet.
