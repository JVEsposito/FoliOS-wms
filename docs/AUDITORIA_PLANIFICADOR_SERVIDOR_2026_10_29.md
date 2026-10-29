# Auditoría del planificador y cálculo central de destinos

Fecha: 29 de octubre de 2026. Base: `main` en `9ae3d4a`.

## Alcance y evidencia

Se revisaron generación, arbitraje global, programación de labores por turno, geometría de cámaras, reservas, custodia temporal, apertura de sesiones, confirmación de movimientos y recuperación de tablet. Las fotografías muestran CAR-00025, dos extracciones temporales completadas y un rechazo en el tercer paso. Sin los registros de producción no se puede atribuir ese incidente a una reserva concreta; no se ha intervenido su inventario ni liberado sus custodias.

Hallazgos confirmados en el código:

| Problema | Consecuencia | Corrección |
| --- | --- | --- |
| La tablet elegía sin conocer las protecciones de banda ni todos los espacios SAG. | Una posición aparentemente libre podía ser rechazada repetidamente por el servidor. | El plano expone ambas restricciones; ETag y caché incluyen la versión de protecciones. |
| La factibilidad comprobaba el paso actual; un retiro temporal se consideraba posible sin comprobar el traslado posterior. | Se podía comenzar a despejar antes de asegurar la resolución de la secuencia. | El modo servidor simula todos los pasos pendientes y protege sus bandas antes del primer retiro. |
| Varios generadores solo funcionaban con `compute=tablet`. | Cambiar únicamente la configuración a servidor dejaba de generar labores. | Se conecta el modo servidor a generación, arbitraje, toma, sesiones y continuación. |
| La vigencia de custodia y la de sesión/frontera no coincidían. | Después de vencer el lease, un pallet bajo custodia conservaba su compromiso pero podía quedar sin sesión. | Se reutiliza `conCompromisoVigente` en esos puntos. |
| La tablet ofrecía liberar según el paso actual, sin considerar custodias de pasos anteriores. | El operador recibía una acción imposible y otro error. | La acción requiere que no exista movimiento, pausa ni custodia pendiente. |
| Preparar el paso siguiente formaba parte de la misma transacción que confirmaba el anterior. | Un cambio de capacidad podía revertir el registro de un retiro ya realizado. | La reserva del siguiente destino usa un savepoint; un conflicto conserva movimiento y custodia y registra incidencia. |
| SAG asignaba primero la profundidad mayor, contradiciendo el acceso usado por el resto del planificador (P01 al fondo). | Un pallet colocado delante podía impedir completar el sector. | Se asigna desde P01 hacia la entrada; la capacidad y los pasillos SAG propios no se confunden con stock físico. |
| La puntuación de tablet premiaba más espacio libre. | Podía favorecer dispersión entre bandas equivalentes. | El desempate considera agrupación existente; el servidor reutiliza la afinidad de dominio. |

## Contrato operativo

En `guided + rolling + server`, el servidor decide y valida al tomar la maniobra. La tablet muestra el destino autorizado y confirma acciones físicas. `POST /api/tareas-movimiento/{id}/resolver-destino` recupera el paso propio: no acepta una posición elegida por el cliente y repetirlo conserva la reserva existente. Los endpoints de propuestas de tablet permanecen cerrados en modo servidor.

La simulación no crea ubicaciones ni anticipa stock. Solo la confirmación de cada movimiento cambia el inventario. Los destinos futuros quedan protegidos por banda; el paso vigente recibe su reserva de posición. La protección pertenece a la maniobra y se conserva durante sus devoluciones, sin competir con sus propias sesiones.

El orden de decisión es:

1. Restricciones: cámara y banda habilitadas, alcance de despliegue, usos, SAG, retenciones, ocupación, reservas ajenas, acceso frontal, soporte inferior y profundidad de retorno.
2. Objetivo vigente: cámara y sector de carga definidos en Oficina; recepción coordinada con su carga; destino explícito de SAG/retención; secuencia física y devoluciones completas.
3. Optimización: despeje local cuando es válido; banda homogénea del mismo cliente, marca y envase real; banda vacía; afinidad dentro de mezclas; otras mezclas al final. La cámara preferente solo desempata afinidades equivalentes.

Se conserva el arbitraje de prioridades y funciones por turno y la continuidad de la labor del camarero. No se asignan cámaras por su número visible: se usan los identificadores y configuraciones existentes. La recepción sigue completándose al 100%, la concentración al 80% y la preparación de cargas/SAG según sus objetivos. Una banda protegida se considera temporalmente no disponible para otra maniobra; otras bandas pueden seguir trabajando.

## Validación

Las pruebas de regresión incluyen una maniobra de cinco pasos con dos bloqueadores, caducidad de 30 minutos con custodia, reintentos de resolución, cierre de sesiones, prohibición de liberar, falta de capacidad antes de comenzar y suspensión del destino después de iniciar. También cubren afinidad por envase, cambio de cámara desde Oficina, exclusión de otra maniobra y lecturas actuales después de una escritura desde otra conexión MySQL con un snapshot anterior.

Se mantienen pruebas de recepción, despacho, concentración, sectores, turnos, arbitraje, discrepancias, planos y recuperación de tablet. Las comprobaciones de cliente incluyen TypeScript y pruebas JavaScript. Los resultados concretos se registran en la descripción del PR.

La selección es determinista y conservadora: rechaza una secuencia que no puede resolver con las restricciones actuales. No certifica que sea el óptimo global. Las lecturas del arbitraje se comparten por ciclo; la preparación bloquea cámaras y posiciones dirigidas en orden estable y vuelve a leer ocupación y reservas bajo bloqueo. La geometría se indexa por banda para evitar recorrer toda la planta por cada posición candidata. Falta medir latencia y espera de bloqueos con el volumen real de planta.

## Activación en planta

El PR no cambia configuración ni datos de producción. Para activar el flujo completo:

1. Desplegar backend conservando inicialmente la configuración actual y actualizar las tablets con este cliente.
2. Verificar desde Oficina la programación del turno, dimensiones, usos de banda, cámaras objetivo y alcance del rollout. Revisar las maniobras abiertas y resolver sus discrepancias por el flujo de custodia; no borrar reservas ni inventar movimientos para desbloquearlas.
3. Activar `WMS_PLANNER_MODE=guided`, `WMS_PLANNER_HORIZON=rolling` y `WMS_PLANNER_COMPUTE=server`. Mantener la generación automática habilitada. Conservar los identificadores reales de `WMS_PLANNER_ROLLOUT_CAMERAS` si se usa despliegue parcial.
4. Regenerar la configuración (`php artisan config:cache`), reiniciar workers (`php artisan queue:restart`) y solicitar arbitraje (`php artisan planificador:recalcular-arbitraje --forzar`). Comprobar scheduler, workers y proyección vigente.
5. Ejecutar una recepción y una maniobra de despeje/devolución con tablets actualizadas. Comprobar las posiciones físicas, las asignaciones de Oficina, la ausencia de custodias residuales y los mensajes de Salud/Incidencias. Medir latencia con la concurrencia habitual.

El servidor sigue siendo obligatorio para autorizar un destino nuevo aunque la cobertura wifi sea alta. Durante una caída, el cliente conserva la recuperación de la operación y consulta su estado al reconectar; no calcula un destino alternativo sin autorización. Para cambiar de modo, terminar o resolver primero las maniobras que ya modificaron la realidad física.
