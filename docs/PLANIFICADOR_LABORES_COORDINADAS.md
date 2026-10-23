# Labores coordinadas y funciones prioritarias

Acuerdo funcional: cada camarero conserva continuidad en su función prioritaria y puede
avanzar varias labores ejecutables. Las labores comparten un plan global de folios,
destinos, dependencias y reservas. Operación ahora conserva la vista de toda la planta.

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

## Entrega 3: funciones por turno y ejecución preparada

Oficina configura por turno función principal, funciones secundarias en orden,
disponibilidad y cambios de asignación. Las preferencias no conceden permisos adicionales.

Una carga sin más movimientos útiles pasa a `esperando_dependencias`, con cantidad,
origen y labor que resolverá la espera. El camarero puede asumir otra carga. Cuando el
receptor entrega los últimos pallets, se actualiza la carga anterior sin obligar al
separador a repetir un traslado. Su preparación física termina al quedar todos los
folios donde corresponde y no quedar acciones físicas pendientes.

Prioridades de asignación:

1. Cerrar el movimiento físico en curso y los retornos necesarios de su maniobra.
2. Atender compromisos urgentes y recursos que bloquean operación.
3. Elegir trabajo ejecutable de la función prioritaria entre urgencias comparables.
4. Mantener continuidad y reducir costo total de traslados, despejes y retornos.
5. Ofrecer apoyo en funciones secundarias si no queda trabajo ejecutable propio.

La falta de folios ejecutables de una carga no equivale a falta de trabajo del camarero.
Los cambios de función se realizan al cerrar una maniobra o agotarse el trabajo útil;
pequeñas variaciones de puntaje no provocan alternancia continua de funciones.

| Horizonte | Preparación | Compromiso |
|---|---|---|
| Turno completo | Todas las cargas, túneles, lotes, funciones y dependencias conocidas. | Previsión de demanda y espacio, sin reservar físicamente toda la planta. |
| Anticipación | Próximas liberaciones y entregas; ventana inicial propuesta de 30–60 minutos, configurable. | Secuencias y destinos coordinados, sujetos a confirmación del evento. |
| Ejecución | Movimiento actual y siguientes pasos preparados para cada camarero. | Reservas próximas y comprobación de habilitación física. |

El servidor prepara y publica las secuencias en segundo plano. Tomar el siguiente paso
consulta lo preparado y valida vigencia; no descarga todos los planos ni vuelve a
resolver la planta completa. Los eventos actualizan únicamente las labores afectadas.

## Pruebas de aceptación pendientes para entregas 2 y 3

- Carga asignada antes de liberar túnel: A entrega donde C/D necesita; no aparece una
  ubicación genérica seguida de una reextracción evitable.
- A recibe, B recibe packing externo, C separa A y D separa B: todos conocen destinos
  de cargas A/B/C/D y evitan reservas o movimientos duplicados.
- C agota movimientos útiles de carga A, pendiente de dos folios de túnel: avanza otra
  carga; al entregarlos el receptor, A queda físicamente preparada si no quedan acciones.
- Un lote SAG, retención, falta de aprobación térmica o destino ocupado conserva su
  restricción. Una previsión no habilita por sí misma el movimiento.
- Cambiar turno, función, carga o disponibilidad no reasigna una custodia física iniciada.
- Medir tiempo y tamaño de las consultas de tomar/continuar tarea con el catálogo real,
  sin afirmar una mejora de rendimiento de planta antes de esa medición.
