# Salud y despliegue del planificador

## Despliegue progresivo

El modo global sigue siendo el techo de seguridad:

```dotenv
WMS_PLANNER_MODE=guided
WMS_PLANNER_COMPUTE=tablet
WMS_PLANNER_HORIZON=rolling
WMS_PLANIFICADOR_AUTOMATICO=true
WMS_PLANNER_ROLLOUT_CAMERAS=CAM-01
WMS_PLANNER_CAMARA_PREFERENTE_DESPACHO=CAM-01
```

Una emergencia solo dirige y bloquea la cámara cuando `guided` coincide con
generación automática, cálculo `tablet` y horizonte `rolling`. Una combinación
incompleta se rechaza antes de crear el plan o modificar labores y bandas.

`WMS_PLANNER_ROLLOUT_CAMERAS` admite UUID o códigos separados por coma. Si la
lista está vacía, se conserva el comportamiento global anterior. Si contiene
cámaras y el modo global es `guided`, solo ellas publican trabajo dirigido; las
demás continúan en `shadow`.

`WMS_PLANNER_CAMARA_PREFERENTE_DESPACHO` admite un código o UUID de cámara PT.
Solo se aplica si la cámara está activa y dentro del rollout dirigido. La
compatibilidad de la banda (cliente, después marca y formato, después banda
libre) precede siempre a la preferencia: una banda con cliente compatible en
otra cámara gana a la preferida vacía. Cuando dos alternativas tienen la misma
afinidad se escoge la preferida. No reserva espacio ni vuelve exclusiva la
cámara; un destino explícito de carga y la separación tienen reglas propias.

## Arranque con pallets anteriores al planificador

La aprobación de Prefrío genera `recepcion_tunel` únicamente cuando ya están
habilitados `guided`, `tablet` y `WMS_PLANIFICADOR_AUTOMATICO=true`. Activar el
planificador después no crea tareas para pallets aprobados anteriormente:
`planificador:recalcular-arbitraje --forzar` solo arbitra maniobras existentes.

Revisar la temporada activa sin escribir:

```bash
php artisan planificador:conciliar-pallets
```

Después de comprobar físicamente dónde están los pallets y quién efectuará
las búsquedas, el supervisor puede incorporar un lote acotado:

```bash
php artisan planificador:conciliar-pallets --aplicar --usuario=supervisor@planta.cl --limite=50
```

El comando admite administradores o supervisores de frío activos, registra
usuario/temporada/cantidad en el log y se puede repetir sin duplicar objetivos.
Solo incorpora pallets completos de la temporada activa, con Prefrío aprobado,
habilitación térmica, sin ubicación, asignación de carga, retención ni tarea
histórica. El diagnóstico distingue los pallets sin ubicación ni objetivo que
cumplen esos requisitos de los que requieren revisar su expediente y regularizar
su estado.
Cada nuevo objetivo es `almacenamiento_pallet` rolling y conserva el ID de su
proceso, pero **no presume que el pallet siga dentro del túnel**: el camarero
debe encontrarlo y confirmar folio y PIN antes de iniciar, incluso si se apagó
la confirmación general. La tarea no asigna destino hasta la frontera física
validada por el servidor. Si el pallet ya está en una cámara sin registrar,
regularizar primero esa ubicación física, sin moverlo solo para crear trabajo.

El rollback operacional es siempre:

```dotenv
WMS_PLANNER_MODE=off
```

`off` prevalece sobre cualquier lista y evita nuevas decisiones dirigidas. Las
maniobras que ya cruzaron `en_proceso` conservan la realidad física como fuente
de verdad y deben cerrarse de acuerdo con sus interbloqueos.

## Ciclos de cámara

Las emergencias y desocupaciones de una misma cámara se registran en ciclos
independientes. Repetir el aviso de un ciclo activo devuelve ese plan; después
de completarlo o cancelarlo, un nuevo aviso crea otro plan y conserva intactos
el motivo, responsables, fechas, tareas, maniobras y movimientos del anterior.
La apertura mantiene el bloqueo transaccional de la cámara para serializar
avisos simultáneos. La selección del último ciclo no depende de la hora del aviso.

La migración asigna el ciclo 1 a las referencias existentes. Los demás
generadores conservan ese valor y su unicidad habitual. Si ya existen ciclos
posteriores, revertir esa migración se rechaza antes de modificar el esquema;
el rollback operacional mediante `WMS_PLANNER_MODE=off` sigue disponible.

## Snapshot de salud

`GET /api/administracion/planificador/salud` requiere permiso de consulta de
integridad operacional. Por defecto cubre las últimas ocho horas y acepta:

- `desde` y `hasta`, con una ventana máxima de siete días;
- `camara_id`, para observar una sola cámara durante el piloto.

La respuesta incluye un `snapshot_version` SHA-256, la configuración efectiva
por cámara, métricas de planes, maniobras, tareas, movimientos, reservas y
discrepancias, más señales actuales de riesgo. `critico` identifica leases
vencidos todavía activos o maniobras completadas con custodia temporal;
`advertencia` identifica tareas estancadas, custodias activas o discrepancias
abiertas.

En `shadow` y `guided`, consultar salud lee el último resultado persistido sin
ejecutar el árbitro. `vigencia_arbitraje` informa si el resultado está actual,
pendiente, recalculándose, atrasado o en error. La sección
`metricas.arbitraje` expone:

- `ciclos_nuevos`, donde un ciclo significa un estado autoritativo diferente y
  no una llamada o refresco;
- decisiones totales, maniobras únicas y desglose `por_decision`, incluido
  `fuera_rollout`;
- maniobras excluidas por conflicto y recursos involucrados por tipo (`folio`,
  `posicion`, `banda`, `otro`);
- el último ciclo de la ventana con capacidad, frontera y decisiones.

El filtro `camara_id` atribuye decisiones por pasos, reservas de banda o
custodias temporales asociados a esa cámara. El ciclo persistido sigue siendo
global y un estado idéntico se reutiliza. Consultar repetidamente Salud u
Operación ahora no crea ciclos, no infla métricas y no bloquea maniobras.

Los cambios en planes, maniobras, pasos, reservas de banda, custodias, cámaras o
temporadas solicitan `RecalcularArbitrajePlanificador` después del commit. La
cola deduplica por temporada. El scheduler ejecuta cada minuto el watchdog
`planificador:recalcular-arbitraje`; este recupera solicitudes pendientes y
refresca una proyección que exceda `WMS_PLANNER_ARBITRATION_REFRESH_SECONDS`.
El panel considera atrasada una evaluación al superar
`WMS_PLANNER_ARBITRATION_STALE_SECONDS`. El contrato expone versiones solicitada
y calculada, espera, duración del último cálculo y contadores de ejecuciones
exitosas o fallidas sin publicar el detalle interno de la excepción.

Los recálculos especializados de carga, segregación y cámara tienen además una
solicitud persistida por fuente. REPA usa la tarea como fuente y la temporada
como objetivo para evitar una única fila disputada por toda la operación.
`proyecciones_pendientes` resume el trabajo sin confirmar y separa fallos
recuperables, agotados y descartes por fuentes eliminadas. Las filas confirmadas
se eliminan y el scheduler recupera las restantes con
`planificador:recuperar-proyecciones`.

Una vez corregido el error de un cálculo agotado, ejecutar manualmente
`planificador:recuperar-proyecciones --reintentar-agotados --limite=200` con
el worker activo. El scheduler periódico mantiene el comando sin esa opción.
Agregar `--tipo=concentracion_carga` cuando solo se haya corregido ese tipo de
proyección. La ejecución manual queda registrada en el log de Laravel.

Las mismas señales actuales de salud se reutilizan en el puesto de mando de
`Operación ahora`, sin ejecutar allí las métricas históricas de planes,
movimientos y arbitraje. Así el refresco operacional conserva un costo acotado y
el endpoint administrativo mantiene su permiso más restrictivo.

Para ampliar el rollout, mida una o dos jornadas de la cámara piloto, conserve
el snapshot de cada ventana y agregue el siguiente código solo cuando la salud
permanezca estable y la desviación entre plan y ejecución sea aceptable.
