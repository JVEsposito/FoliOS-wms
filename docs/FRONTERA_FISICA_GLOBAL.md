# Frontera física global

La frontera física global une el arbitraje de maniobras con la materialización
del próximo destino. La tablet ya no calcula una frontera separada por plan:
trabaja sobre una sola fotografía autoritativa de las maniobras que el camarero
tiene tomadas, aunque provengan de objetivos operacionales distintos.

## Contrato operacional

- El servidor arbitra globalmente las maniobras antes de publicar destinos.
- La tablet simula y propone; no bloquea posiciones por sí sola.
- Solo el paso actual de cada maniobra puede recibir una reserva física.
- `WMS_PLANNER_FRONTIER_MAX` limita maniobras independientes, no los pasos
  internos de una maniobra.
- Un pallet o una posición no pueden participar en dos maniobras activas.
- La aceptación puede ser parcial: una propuesta válida no se pierde porque
  otra haya quedado obsoleta o compita por el mismo destino.
- Después de `RETIRAR PALLET`, la tarea queda `en_proceso`; su destino no puede
  cambiar y su reserva no puede liberarse por vencimiento.
- La lista de cámaras del rollout es una frontera física del servidor, no una
  sugerencia de interfaz: se validan origen, destino conocido y destino
  propuesto en cada materialización.

## Snapshot global

`GET /api/frontera-fisica/snapshot` devuelve una fotografía específica para el
usuario y dispositivo autenticados. Incluye:

- versión SHA-256 del estado físico;
- ciclo y resumen del arbitraje global;
- configuración efectiva del planificador;
- versiones de plano y revisiones de reservas solamente de las cámaras activas
  de producto terminado autorizadas por el rollout;
- paso actual tomado de cada maniobra, con versiones de tarea, plan, maniobra y
  reserva;
- decisión de arbitraje y bandera `materializable`.

La versión cambia cuando cambia una cámara, una reserva, una tarea, su plan o el
ciclo de arbitraje. Un snapshot obsoleto se rechaza completo antes de reservar
la primera posición.

## Materialización

`POST /api/frontera-fisica/materializar` recibe hasta el máximo configurado de
propuestas. Cada propuesta identifica:

- tarea y posición destino;
- versión de la tarea y de su propio plan;
- versión conocida del plano de la cámara;
- versión del planificador, puntaje y motivo calculados por la tablet.

La tablet intersecta sus candidatos con `camaras` del snapshot. El servidor
vuelve a comprobar propiedad del claim, secuencia actual, arbitraje, todas las
cámaras del movimiento, generación automática, rollout, versiones,
compatibilidad física, ocupación y reservas. Las propuestas
se procesan en orden y devuelven `aceptadas`, `rechazadas`, `recalcular` y un
nuevo snapshot global.

## Rechazos y pallets sin destino

Cada propuesta rechazada al materializar queda registrada en
`incidencias_destino_planificador` con el motivo, la posición y cámara propuestas, la versión de
reglas de la tablet (`planner_version`), el usuario y la tablet. La frontera por plan de clientes
anteriores registra igual sus rechazos.

Cuando la tablet no encuentra posición para el pallet que tiene tomado, lo informa con
`POST /api/frontera-fisica/sin-destino` (`tarea_id`, `planner_version`, `camaras_evaluadas`).
Solo la tablet que tiene la tarea tomada puede informarlo; no cambia la tarea ni su reserva, y el
camarero sigue viendo su mensaje.

Un mismo motivo de una tarea dentro de `WMS_PLANNER_INCIDENCIAS_DESTINO_MINUTOS` (10 por
defecto) suma repeticiones sobre la misma fila. Registrar es telemetría: un fallo al guardar
nunca cambia la respuesta de la operación.

`TABLET_PLANNER_VERSION` cambia cada vez que cambian las reglas con que la tablet elige
destinos (`rolling-global-6-sector-acopio` desde el sector de acopio), para saber qué tablets
tienen las reglas vigentes.

## Replanificación segura

Al terminar un paso, el servidor avanza la misma maniobra y la tablet vuelve a
consultar la frontera global. Solo se recalcula el sufijo todavía no iniciado.
Las extracciones temporales continúan bajo custodia explícita y conservan su
retorno obligatorio; nunca se crean posiciones ficticias para representar un
pallet retirado.

## Compatibilidad

Los endpoints por plan de `GET /api/planes-operacionales/{plan}/snapshot` y
`POST /api/planes-operacionales/{plan}/frontera` permanecen disponibles para
clientes anteriores. La bandeja actual usa la frontera física global. En modos
`off`, `shadow`, `server`, `batch` o con generación automática desactivada no se
habilita la materialización global.
