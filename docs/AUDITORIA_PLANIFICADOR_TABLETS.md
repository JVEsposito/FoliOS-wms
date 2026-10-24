# Auditoría del planificador y operación en tablets

Revisión sobre [#507](https://github.com/JVEsposito/FoliOS-wms/pull/507) y
[#508](https://github.com/JVEsposito/FoliOS-wms/pull/508). Complementa el acuerdo de
[labores coordinadas](PLANIFICADOR_LABORES_COORDINADAS.md).

La integración posterior de #508 y #509 quedó en ramas intermedias y no llegó a
`main`. La [corrección de pallet único y despeje local](CORRECCION_PALLET_UNICO_Y_DESPEJE_LOCAL.md)
reúne esas entregas y las correcciones adicionales en un PR dirigido a `main`.

## Hallazgos y correcciones

| Prioridad | Hallazgo | Cambio verificable |
|---|---|---|
| Alta | Una confirmación perdida podía presentarse como fallo; volver a confirmar construía otro UUID. | Guardar la entrega antes del POST, incluyendo UUID, sesiones, versiones y fecha. Consultar el resultado propio al reiniciar o reanudar. Una entrega confirmada no se reenvía; un reintento conserva el cuerpo y UUID originales. |
| Alta | El estado `busy` se liberaba en funciones anidadas; dos toques podían iniciar acciones antes del siguiente render. | Una sola acción propietaria, bloqueo síncrono y mensaje de progreso por operación. El bloqueo dura hasta terminar la acción y su continuación. |
| Alta | Un GET iniciado antes de tomar una labor podía terminar después y borrar la tarea activa. | Lecturas compartidas por canal y generación; las escrituras invalidan respuestas anteriores. Mantener la tarea tomada localmente incluso si falla su refresco. |
| Alta | El contexto de carga podía proceder de un snapshot MySQL anterior a una reasignación. | Comprobar la reserva vigente mediante lectura con bloqueo, incluso si el snapshot no mostraba asignación. Ante cambio, rechazar la reserva y pedir actualizar. |
| Alta | El recálculo de objetivos secundarios corría después del commit en la misma petición. Una excepción podía parecer un movimiento fallido aunque el stock ya estuviera confirmado. | Solicitud durable dentro de la transacción física; publicación del trabajo después del commit. El worker actualiza los objetivos sin extender la respuesta de entrega. |
| Media | Si el GET posterior a abrir una sesión de cámara fallaba, la sesión no quedaba registrada para cerrarla. | Registrar la sesión antes del GET y cerrarla al abortar la preparación. Adquirir las dos cámaras en orden estable. |
| Media | La verificación de turno se esperaba junto con las tareas y podía demorar toda la continuación. | Actualización independiente: una verificación lenta no retiene las labores propias ni el cierre del movimiento. |
| Media | Cada precálculo repetía catálogo de cámaras y planos; una cámara de carga cambiada podía quedar fuera de la selección móvil. | Usar el alcance y la carga vigente del snapshot. Reutilizar planos por versión y revisión de reservas, con vigencia máxima de 30 segundos; dos descargas simultáneas por cálculo. Al ejecutar, volver a leer el plano y validar en servidor. |
| Media | La proyección del acopio consultaba nuevamente la misma carga para cada tarea. | Cargar planes, asignaciones, ubicaciones y reservas por lote. La lectura compartida sirve para propuestas; no sustituye las comprobaciones con bloqueo de una escritura. |
| Media | El origen conocido del túnel aparecía como «Por calcular»; el envase no era visible y algunos datos esenciales se recortaban. | Mostrar túnel y posición, packing externo o custodia; envase en portada, cola y ejecución; destino «propuesto» separado de «reservado»; resumen sin corte de valores y rutas de cola con dos líneas. |

## Garantías comprobadas

- La entrega física, la tarea completada y la solicitud durable se confirman juntas.
  Un rollback no deja una solicitud huérfana. Un fallo al publicar el job conserva la
  solicitud y no convierte el commit en error para el operador.
- La consulta de recuperación es de lectura: exige el usuario responsable y el mismo
  dispositivo, permite consultar tareas históricas finalizadas y no revela operaciones
  de otros usuarios o tablets. Tarea y operación se leen en una misma transacción.
- La captura pendiente se separa por servidor, usuario y dispositivo. No contiene token
  ni PIN. Se conserva si no puede consultarse el servidor o si el almacenamiento está
  dañado. Una captura distinta no puede sobrescribirla, incluso con escrituras locales
  simultáneas. No constituye una cola para mover stock sin conexión.
- Ante una respuesta ambigua se muestra «Confirmación pendiente» con el folio y una
  acción de revisión. Se impide iniciar otra maniobra mientras exista esa incertidumbre.
  Si el servidor rechaza el intento y confirma que no terminó, se permite corregir la
  misma tarea. Las restricciones físicas y de sesión siguen verificándose en servidor.
- Un movimiento iniciado tiene prioridad para continuar; no se mezcla con una nueva
  labor. Se conserva el control de retornos y custodias de la misma maniobra.

## Evidencia de velocidad y recuperación

Las pruebas de lote comparan una recepción con 25 recepciones de una misma carga:
la cantidad de consultas de proyección permanece constante, con un máximo de 15, y
cada resultado coincide con la lectura individual anterior. El número representa ese
bloque de acopio, no todas las consultas del endpoint ni una medición del servidor planta.

Las pruebas móviles ejecutan el componente real con hooks de React y APIs simuladas.
Cubren doble toque, GET antiguo posterior al claim, verificación que no termina,
fallo de lectura tras abrir sesión, respuesta perdida antes y después del commit,
reinicio, fallo al guardar o borrar la captura, consulta de recuperación caída, captura
ilegible, rechazo confirmado y orden de adquisición de cámaras. Cubren ubicación,
traslado, extracción temporal, entrega a andén y salida directa desde prefrío.

Las pruebas MySQL usan dos conexiones reales con REPEATABLE READ: Oficina cambia la
reserva después de abrir el snapshot de la tablet. Se rechazan tanto una asignación
anterior como una ausencia de asignación obsoleta, sin reservar destino ni mover stock.
Las reservas y los escenarios de recepción/carga, SAG, restricciones térmicas y
movimientos iniciados conservan sus pruebas de regresión.

Los componentes de ejecución se comprueban a 360, 800 y 1280 dp para folio, envase,
origen y acción. Son pruebas de render y comportamiento; no sustituyen la revisión
visual ni las mediciones en las tablets reales.

## Validación local de esta revisión

- 100 pruebas PHP / 910 aserciones sobre MySQL: recepción, concentración, frontera,
  arbitraje, reservas, movimientos atómicos, incidencias, recálculos y recuperación.
- 351 pruebas JavaScript; 96 pruebas móviles con el componente real de React.
- TypeScript sin errores; Pint y `git diff --check` aprobados.
- La exportación Android/PDA queda en el pipeline habitual del PR. No se ejecutó
  un despliegue, una actualización de las tablets ni una prueba de carga en planta.

## Despliegue y límites

1. Integrar #507, después #508 y finalmente esta corrección. Publicar primero el servidor
   y luego actualizar la app de camareros por su canal habitual. No agrega migraciones.
2. Mantener la cola operacional y el scheduler existentes activos. El job usa la cola
   operativa por defecto y el comando `planificador:recuperar-proyecciones` recupera
   solicitudes pendientes; no usa la cola exclusiva de integración ODBC. Con conexión
   `sync` las proyecciones se conservan pendientes y no se procesan en la petición.
3. Comprobar salud del planificador, trabajos pendientes y antigüedad de proyecciones
   durante el piloto. El stock confirmado no depende del éxito posterior del recálculo.

Las sesiones actuales siguen siendo exclusivas por cámara. Ordenar su adquisición y
cerrarlas ante un fallo no habilita edición simultánea de la misma cámara. Separar
reservas de movimiento guiado y edición manual requiere otro cambio de contrato y
pruebas de concurrencia. Tampoco se garantiza cierre inmediato si se pierde la respuesta
al POST de apertura o falla el propio cierre de sesión; sigue aplicando la recuperación
de sesiones existente.

Todavía falta la entrega 3: funciones prioritarias por turno, continuidad hacia otras
cargas al agotar los movimientos útiles, espera explícita por dependencias y secuencias
preparadas en servidor. Estos cambios reducen lecturas repetidas de la implementación
actual; no equivalen al motor anticipado completo.

En planta falta medir p50/p95 de abrir, tomar, iniciar y confirmar una labor con cuatro
tablets, incluyendo dos cargas cuyos pallets salen del mismo túnel. Verificar pérdida
de Wi-Fi después de confirmar, avance de otra carga cuando falten pallets y uso de una
misma cámara por operadores distintos. La meta de operación fluida debe aceptarse con
esos datos; no se afirma un tiempo de 1–2 segundos sin medirlo en ese entorno.
