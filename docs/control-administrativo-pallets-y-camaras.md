# Administración de pallets y prioridades de cámaras

En **Operación ahora → Maniobras**, el administrador dispone de **Administrar pallets y cámaras** y de **Administrar pallet** junto a cada maniobra con folio. También puede abrir el panel desde la cabecera de una cámara en Frigorífico y desde Configuración de cámaras. Los supervisores y camareros conservan la consulta y sus operaciones habituales; estas intervenciones requieren un administrador activo autorizado para administrar cámaras.

## Liberar, designar y corregir

1. Busca el folio exacto. El panel muestra su ubicación actual y las tareas vigentes, incluso si el planificador no tiene un ciclo disponible.
2. Selecciona la acción:
   - **Liberar tareas y reservas:** cancela las tareas reversibles del folio y libera sus reservas. Conserva la ubicación física. Si la necesidad sigue vigente, el planificador puede proponer una tarea nueva.
   - **Designar otro destino:** cancela las tareas reversibles y crea una nueva tarea con cámara, banda, posición y nivel elegidos por el administrador. Su destino queda fijo; la ubicación cambia cuando el camarero confirma el movimiento.
   - **Corregir ubicación verificada:** registra dónde está realmente el pallet después de comprobarlo físicamente. Requiere marcar la confirmación física y escribir el motivo. Registra una corrección administrativa inmutable con origen, destino, administrador, motivo y fecha, y cancela las tareas obsoletas. No inventa una tablet ni una sesión de estiba.
3. Para las dos últimas acciones elige la cámara e ingresa las coordenadas. Agrega un motivo y registra la intervención.

Una maniobra de varios pasos se cancela como unidad; no se abandonan pasos aislados ni pallets temporales. Liberar y designar se rechazan si ya comenzó la ejecución física. Una corrección verificada puede resolver el movimiento permanente del propio pallet que quedó en proceso; no permite abandonar otros movimientos en ejecución ni custodias temporales. Esos casos se resuelven en **Discrepancias** y con su retorno físico correspondiente.

Se comprueban temporada activa, pallet activo, habilitación térmica, estado de despacho, posición operativa, ocupación, reservas de tareas, protecciones de bandas, inspección SAG, sesiones manuales y emergencia. Las correcciones describen una ubicación ya comprobada: no ordenan un movimiento y pueden reflejar huecos físicos existentes. Para un traslado nuevo se conserva además la comprobación de profundidad y soporte.

Las versiones evitan intervenir un pallet o plano que cambió desde su consulta. Si la respuesta de red no se confirma, reintenta sin cambiar el formulario: se conserva el identificador del intento para evitar repetir la intervención. El historial del panel muestra las últimas intervenciones administrativas; la auditoría completa conserva todos los cambios.

## Prioridad general por cámara

El formulario **Prioridad general por cámara** permite elegir:

| Prioridad | Se utiliza al buscar destinos para |
|---|---|
| Uso general | Destinos sin una cámara especializada disponible |
| Despacho prioritario | Nuevas cargas, acopio y concentración de despacho |
| Almacenamiento prioritario | Pallets sin una finalidad de despacho o recepción de túnel |
| Salida de túneles prioritaria | Tareas de recepción de túnel |

Cada cámara tiene una finalidad prioritaria; varias cámaras pueden compartirla. El orden es cámara de la finalidad solicitada, cámara de uso general y otras cámaras. Dentro de cada nivel se conservan las afinidades y desempates existentes. Se aplica en el servidor y en el cálculo local de la tablet. La configuración técnica anterior de cámara preferente de despacho se conserva como desempate dentro del mismo nivel.

La prioridad no convierte en válidos destinos ocupados, fuera de servicio, protegidos o incompatibles. Si no existe capacidad válida en una cámara prioritaria, se busca una alternativa. Se respetan los destinos explícitos de administración o de una carga, los retornos calculados y el despeje local de una maniobra. No se cambian reservas físicas que ya están en ejecución. Al guardar se solicita un nuevo cálculo; las tablets reciben la prioridad y su versión en los planos y en la frontera física.

## Integración

Aplicar la migración habitual. Agrega prioridad y versión a las cámaras y admite dispositivo nulo en operaciones y movimientos exclusivamente para el nuevo tipo de corrección administrativa auditada. La validación ordinaria sigue exigiendo usuario, dispositivo y sesiones válidos para los movimientos de camareros. No hay modificación automática de ubicaciones al aplicar la migración.
