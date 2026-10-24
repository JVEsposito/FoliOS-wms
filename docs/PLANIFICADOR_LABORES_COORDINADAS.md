# Labores coordinadas y funciones prioritarias

Acuerdo funcional: cada camarero conserva continuidad en su función prioritaria y puede
avanzar varias labores ejecutables. Las labores comparten un plan global de folios,
destinos, dependencias y reservas. Operación ahora conserva la vista de toda la planta.

La [corrección de pallet único y despeje local](CORRECCION_PALLET_UNICO_Y_DESPEJE_LOCAL.md)
explica la integración pendiente de #508/#509 y la prioridad local para despejes.

## Entrega 1: compatibilidad y contenido de bandas

Implementado en el PR #507:

- Recepción de túnel usa el envase individual del folio. El formato térmico del proceso
  queda separado como `formato_prefrio`; no sustituye un envase ausente.
- La respuesta de tareas recupera el envase vigente del folio también para tareas antiguas,
  sin reescribir su contexto histórico. La tablet prioriza ese dato sobre contexto heredado.
- Los perfiles físicos de una banda y sus entradas reservadas se exponen por separado.
  La afinidad efectiva considera ambos; las entradas no inflan ocupación física.
- Una reserva vencida deja de influir salvo que exista movimiento iniciado o custodia
  física activa. Reubicar dentro de la misma banda no cuenta el mismo folio dos veces.
- Orden de almacenamiento inicial: perfil homogéneo idéntico; banda vacía; perfil idéntico
  dentro de una banda mixta; mismo cliente y marca; mismo cliente; demás alternativas.
  Las últimas alternativas siguen disponibles si no hay capacidad mejor: esta etapa no
  introduce una prohibición absoluta de mezcla. Las restricciones físicas siguen vigentes.
- El precálculo simula las entradas que acaba de proponer: dos pallets iguales pueden
  continuar la misma banda; dos perfiles distintos no ven simultáneamente esa banda vacía.
- Oficina muestra todos los perfiles con cantidades presentes y entradas reservadas.

Esta entrega modifica la selección de destinos de almacenamiento y su información.
No implementa todavía las funciones por turno, la asignación de labores ni la coordinación
anticipada de cargas pendientes en túnel. Se mantienen las rutas específicas de carga,
SAG y movimientos con destino obligatorio.

Para desplegar esta entrega se requiere actualizar servidor, compilar la interfaz web y
actualizar la app de camareros (APK o actualización compatible con su canal). No agrega
migraciones. La selección entre bandas y el precálculo con entradas comprometidas requieren
la versión móvil nueva; corregir el contexto del servidor ayuda a tareas antiguas, pero no
sustituye la actualización del algoritmo de la tablet.

## Entrega 2: coordinación de objetivos y dependencias

Implementado en esta entrega:

- La recepción de túnel, REPA y almacenamiento inicial consulta la asignación vigente
  del folio antes de proponer ubicación, aunque no exista camión. Conserva la labor de
  recepción como objetivo principal y puede vincular el objetivo de concentración.
- El destino usa la cámara definida en Oficina y el grupo conectado de la carga,
  incluyendo posiciones de otras entregas reservadas. Si todavía no hay cámara ni
  grupo, la primera reserva establece el acopio; las siguientes deben extenderlo.
  La carga se bloquea durante esa comprobación y la materialización del destino.
- Las reservas proyectan llegadas sin aumentar stock físico ni el porcentaje de
  concentración. La tablet incorpora también las propuestas anteriores de su propio
  precálculo y descarta el precálculo si cambió la asignación de carga.
- Si no queda destino conectado habilitado, la propuesta espera capacidad; no deriva
  automáticamente a almacenamiento general para generar después otra extracción.
- Cada recepción conserva una sola tarea, maniobra y movimiento. Al terminar se
  actualizan los objetivos de carga vinculados, además del cierre de la recepción.
  Los blockers útiles vinculan también cargas que aún no tenían tareas propias.
- Antes de reservar, iniciar o registrar el movimiento se comprueba la carga vigente.
  Si cambia la asignación después de tomar una tarea, se debe liberar y volver a tomar;
  si cambia su cámara, se recalcula el destino. Un movimiento iniciado conserva su
  destino y responsable. Los cambios de una carga solicitan además recalcular las
  labores de otras cargas cuyos blockers contribuían a su acopio.
- Oficina expone las recepciones pendientes con origen y responsable, separadas del
  progreso físico. Las consultas condicionales se invalidan al cambiar esas tareas.
- La llegada del camión conserva el flujo existente de despacho directo. La previsión
  de acopio no habilita un pallet sin autorización térmica ni sustituye controles SAG,
  de retención, de bandas o de reservas de posiciones.

Requiere servidor, compilación web y actualización de la app de camareros. No agrega
migraciones. Se prepara sobre #507: ese PR debe integrarse primero. Se conserva el
umbral operacional existente del 80%; no se lo presenta como preparación física del
100% de la carga. No implementa aún las funciones por turno, el estado personal de
espera ni la ejecución preparada en servidor de la entrega 3. La geometría conectada
reutiliza el criterio vigente de concentración; no constituye un optimizador global
de todas las secuencias de extracción de la planta ni una garantía de tiempo de respuesta.

Antes de elegir ubicación, cada folio debe resolver su carga o lote comprometido, aunque
todavía permanezca en túnel. La existencia de un camión en andén determina si puede
ejecutarse salida directa; no determina si el planificador debe conocer la carga.

Ejemplo con cuatro camareros:

| Camarero | Labor | Coordinación obligatoria |
|---|---|---|
| A | Recibir túnel 1 | Entregar los folios de carga a sus destinos previstos; almacenar el resto por compatibilidad. |
| B | Recibir packing externo | Respetar validación y habilitación térmica; integrar los destinos futuros de carga. |
| C | Separar carga A | Llevar los pallets que deba despejar a destinos útiles de sus propias cargas o lotes. |
| D | Separar carga B | La misma regla, incluyendo cargas C, D u otras que todavía no tengan camarero asignado. |

Un traslado tiene un solo ejecutor y una sola reserva física. Puede satisfacer recepción
y preparación de carga simultáneamente; se vinculan y actualizan ambos objetivos. No se
duplican tareas ejecutables ni se contabiliza dos veces el movimiento en productividad.

El destino de una carga debe servir a su secuencia de extracción. Se consideran acceso,
profundidad, capacidad comprometida y despejes/retornos para evitar un ahorro inmediato
que produzca más movimientos al terminar la carga.

Las entregas previstas son dependencias: quien separa la carga conoce qué viene del túnel
y dónde llegará. Una tarea ya iniciada conserva su custodia y destino; los cambios de
demanda recalculan el trabajo pendiente afectado.

## Auditoría previa al despliegue

La [auditoría de tablets](AUDITORIA_PLANIFICADOR_TABLETS.md) documenta las correcciones
adicionales de confirmaciones, reservas, recálculos, lecturas repetidas y legibilidad.
Los objetivos secundarios ahora se actualizan mediante solicitudes durables en cola,
confirmadas junto al movimiento físico; ya no se recalculan dentro de su respuesta.

## Entrega 3A: labores y funciones por turno

Implementada sobre `main`, que ya integra #513:

- **Operación ahora → Personal y equipos → Labores por turno** y
  **Frigorífico → Labores por turno** abren `/oficina/frigorifico/labores-turno`.
  Requiere supervisar cámaras de productos y acceso al módulo Cámaras.
- Cada turno define nombre y fechas de inicio/término en la zona de planta
  (`APP_OPERATIONAL_TIMEZONE`, por defecto America/Santiago). Puede cruzar medianoche;
  no se superpone con otro turno de la temporada y dura como máximo 36 horas.
- Cada camarero autorizado tiene disponibilidad, función principal, apoyos ordenados y,
  opcionalmente, túnel, carga o preparación SAG preferidos. Se puede copiar la dotación
  a otro horario. Quitar una persona de la solicitud la deja no disponible, sin borrar
  su historial. Las funciones son preferencias y no conceden permisos adicionales.
- El guardado bloquea la temporada, compara la versión y registra actor y cambios.
  Un conflicto 409 conserva el borrador en pantalla; Actualizar tampoco borra lo escrito.
- Una labor agrupa la recepción de un proceso de túnel, una recepción externa, una
  carga, una preparación SAG u otro plan. Dos índices únicos reservan una sola labor
  por camarero y un solo camarero/tablet por labor. Cerrojos persistidos por clave evitan
  los bloqueos de huecos al crear la primera labor concurrentemente. La toma y la reserva de la tarea
  comparten transacción: un rechazo no deja una labor huérfana. Se conserva además
  la exclusión por folio de #513, incluso entre planes diferentes.
- Mientras quedan movimientos publicados, el operador conserva esa labor. Los cambios
  de función, disponibilidad o turno se aplican después de terminar o liberar la maniobra
  ya tomada. Un movimiento iniciado o una custodia nunca vence por el cambio de turno.
- Sin tareas útiles publicadas, una carga incompleta pasa a `esperando_dependencias`
  y el camarero puede avanzar otra. Se guarda porcentaje físico y recepciones pendientes;
  el recálculo en cola actualiza esa espera cuando llegan los pallets. El 80% operacional
  no se presenta como 100% físico. La recepción conserva el acopio compartido de la carga.
- Entre urgencias iguales, el arbitraje V6 favorece cobertura de funciones y continuidad
  antes del tipo de objetivo y el beneficio. Respeta recursos, rollout y cupos físicos
  existentes: cuatro camareros no elevan automáticamente el límite de tres maniobras.
  La explicación conserva el peso de turno y el orden de comparación; su replay histórico
  reproduce la decisión sin consultar la configuración actual. Sin turno vigente se usa V5.
- La bandeja ordena antes de paginar, muestra turno, principal, apoyos, labor actual y
  cargas en espera. Las respuestas antiguas no sustituyen el contexto de una lectura
  posterior a una mutación. No añade consultas ODBC ni reconstrucciones de catálogos.
- El comando programado `planificador:recalcular-arbitraje` comprueba cada minuto los
  límites de turno y labores inactivas. Una labor sin pallet comprometido vence tras
  15 minutos de inactividad; una maniobra aún comprometida conserva continuidad.
  Oficina puede liberar una labor sin reservas ni custodia. No se borran labores ni eventos.

Para desplegar: ejecutar migraciones, compilar web, actualizar la app de camareros y
mantener activos scheduler y worker. El servidor sigue comprobando propiedad aunque
una tablet antigua no muestre el resumen nuevo. Crear turnos no reasigna una maniobra
que ya estaba tomada al habilitar esta versión.

Pruebas automatizadas cubren edición concurrente, horario nocturno, permisos, temporada,
continuidad multipallet, espera/recepción/otra carga, cuatro funciones, replay V6,
orden previo a paginación, reversión de toma fallida y carreras reales MySQL con dos procesos.
El navegador prueba guardado y recuperación de conflicto en 1280 y 390 px.

## Entrega 3B: horizonte anticipado pendiente

Esta entrega persiste funciones, propiedad y esperas. La selección geométrica conserva
la frontera móvil y la proyección global existentes. La preparación completa de todas
las secuencias del turno y una ventana anticipada configurable siguen pendientes:

| Horizonte | Preparación prevista | Compromiso |
|---|---|---|
| Turno completo | Cargas, túneles, lotes y dependencias conocidas. | Previsión de demanda y espacio. |
| Anticipación | Próximas liberaciones y entregas; ventana propuesta de 30–60 minutos. | Secuencias y destinos sujetos al evento. |
| Ejecución | Movimiento actual y siguientes pasos preparados. | Reservas próximas y comprobación física. |

La migración del cálculo geométrico restante a servidor debe validarse separadamente.
Antes de desplegar en planta, medir latencia y tamaño de tomar/continuar tarea con su
catálogo real y verificar el cambio de turno con recepciones y cargas simultáneas.
