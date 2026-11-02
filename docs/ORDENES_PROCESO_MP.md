# Órdenes de proceso de materia prima

Fruta a proceso pasa a trabajar contra una orden de proceso que crea el digitador MP a partir de la PRE-SIF, en lugar de recibir línea, turno y número de orden como texto libre en cada entrega.

Especificación acordada el 1 de noviembre de 2026. Se entrega en cuatro PR que se integran en orden:

1. Catálogos de líneas y turnos, y permisos.
2. Órdenes, versiones y lotes de versión, con la oficina del digitador.
3. Fruta a proceso amarrada a la orden (API y APK).
4. Exportación PRE-SIF y vista de programado contra real.

## Reglas de negocio

1. El digitador MP traspasa la PRE-SIF a FoliOS y crea la orden. Las filas que no aplican, vacías u ocultas no se traspasan.
2. El N° de proceso lo ingresa el digitador y va por lote, igual que en la PRE-SIF.
3. Puede haber varias órdenes abiertas a la vez: la planta tiene 5 líneas.
4. En un mismo turno pueden procesarse 3 o 4 clientes; el cliente va por lote, no por orden.
5. Una orden tiene versiones (v1, v2, v3…). Mientras está en proceso no se cierra: solo cambia de versión.
6. La orden se cierra solo al terminar: por entrega completa (automático) o por cancelación. Ambos casos quedan como terminada, con su tipo de cierre.
7. Un lote puede quedar de un turno a otro. Se planifica con horarios estimativos.
8. Entregar un lote que no está en la orden requiere autorización del supervisor y entra a la orden como nueva versión.

## Líneas de proceso

`lineas_proceso` reemplaza el texto libre de línea. La migración crea L1 a L5 con 6.000 kg/h, el rendimiento que usa hoy la PRE-SIF.

| Campo | Uso |
| --- | --- |
| `codigo` | L1…L5, único. |
| `nombre` | Nombre visible. |
| `rendimiento_kg_hora` | Base para calcular horarios estimados de la orden. Entre 100 y 100.000. |
| `activa` | Una línea inactiva no recibe órdenes nuevas. |
| `version` | Bloqueo optimista: un cambio con versión antigua responde 409. |

## Turnos

`App\Enums\TurnoProceso` mantiene A/B como código FoliOS y traduce los códigos de planilla:

| FoliOS | PRE-SIF | Instructivo |
| --- | --- | --- |
| A | T1 | AM |
| B | T2 | PM |

`TurnoProceso::desdeCodigo()` acepta cualquiera de los tres.

## Permisos

Módulo de oficina `materia-prima.ordenes-proceso`.

| Capacidad | Gate | Roles |
| --- | --- | --- |
| Consultar órdenes y catálogos | `consultar-ordenes-proceso` | Administrador, supervisor de frío, digitador MP, consulta |
| Crear y versionar órdenes | `gestionar-ordenes-proceso` | Administrador, supervisor de frío, digitador MP |
| Configurar líneas | `configurar-lineas-proceso` | Administrador, supervisor de frío |

La migración agrega el módulo a los perfiles predeterminados de esos roles. Autorizar una entrega fuera de orden usa la capacidad existente de corrección de Fruta a proceso (administrador y supervisor de frío).

## API

```http
GET /api/materia-prima/ordenes-proceso/catalogos
PUT /api/materia-prima/lineas-proceso/{linea}
```

`catalogos` devuelve las líneas ordenadas y los turnos con sus códigos de planilla. `PUT` acepta `nombre`, `rendimiento_kg_hora`, `activa` y exige `version`.

## Órdenes, versiones y reservas

| Tabla | Qué guarda |
| --- | --- |
| `ordenes_proceso_mp` | La orden estable entre versiones: código `OP-000001`, temporada, línea, fecha, turno de inicio, estado, tipo de cierre y versión vigente. |
| `versiones_orden_proceso_mp` | Cada versión publicada, inmutable: hora de inicio, colación, rendimiento usado, motivo y origen (`digitador` o `fuera_de_orden`). |
| `lotes_version_orden_mp` | Una fila de la PRE-SIF por lote, en secuencia: N° de proceso, bins, totes y kilos programados, embalaje, kg por caja, cajas estimadas, mercado, horario estimado y copias de cliente, lote, especie, variedad, cosecha, CSG, productor, comuna y provincia. |
| `reservas_lote_orden_proceso_mp` | Compromiso vigente de cada lote con cada orden: bins programados, entregados y pendientes. |

`entregas_fruta_proceso` suma `orden_proceso_mp_id`, `version_orden_proceso_mp_id`, `fuera_de_orden` y `autorizado_por_user_id`, nulos para el historial. Fruta a proceso los llena desde la entrega 3/4.

### Estados

| Estado | Cuándo |
| --- | --- |
| `programada` | Al publicar la v1. El borrador vive en la oficina hasta publicar. |
| `en_proceso` | Con la primera entrega (3/4). |
| `terminada` + `completa` | Cuando ningún lote de la versión vigente tiene bins pendientes. |
| `terminada` + `cancelacion` | Cancelada con motivo por administrador o supervisor de frío. Las entregas hechas se conservan y los bins pendientes quedan libres. |

Una orden terminada no admite versiones.

### Reglas al publicar

- Solo lotes de la temporada de la orden, en bins, disponibles desde cámara o Hidrocooler.
- Un lote aparece una vez por versión. Un lote puede estar en varias órdenes si la suma de bins pendientes en órdenes abiertas no supera su saldo en cámara.
- No se quita un lote con bins entregados en la orden ni se programa por debajo de lo entregado.
- Desde la v2 se exigen `motivo_cambio` y la `version` vigente; otra persona que publicó antes produce 409.
- Kilos y totes programados son proporcionales a los bins sobre el lote completo.
- Cajas estimadas = kilos programados × 90 % ÷ kg por caja, como la PRE-SIF V2. Sin kg por caja no se estiman.
- Comuna y provincia no existen en los maestros: se proponen desde la última orden con el mismo CSG.

### Horarios estimados

`CalculadoraHorariosOrdenProceso` encadena los lotes desde la hora de inicio con kilos ÷ rendimiento de la línea. La colación se aplica una vez: si cae dentro de un lote lo alarga; si un lote empezaría dentro de ella, parte al terminar. Un turno que cruza la medianoche sigue al día siguiente.

### Concurrencia

Orden de bloqueo en toda operación sobre órdenes: primero la orden, después los lotes ordenados por id, después sus reservas. Las reservas y entregas de un lote solo se escriben con ese lote bloqueado.

La orden guarda `lote_ids_vigentes`, así la versión anterior se conoce con la orden bloqueada. Antes de bloquear los lotes solo hay lecturas con bloqueo (temporada y línea con bloqueo compartido): la primera lectura simple abre el snapshot REPEATABLE READ después de tener los lotes y ve todas sus entregas confirmadas sin bloquear filas de entregas. Así las órdenes no compiten con Retornos de Packing, que bloquea entregas antes que lotes.

`OrdenesProcesoMpConcurrenciaTest` lanza procesos PHP separados contra MySQL: dos órdenes en líneas distintas por los mismos bins (una 201, otra 422) y dos versiones simultáneas de la misma orden (una 200, otra 409).

### Reinicio operacional

El reinicio de una temporada de prueba borra sus órdenes, versiones, filas y reservas después de las entregas y antes de los lotes. Las líneas se conservan.

### API de órdenes

```http
GET  /api/materia-prima/ordenes-proceso?estado=abiertas|programada|en_proceso|terminada&linea_proceso_id=&desde=&hasta=
GET  /api/materia-prima/ordenes-proceso/lotes-programables?orden_id=&buscar=
GET  /api/materia-prima/ordenes-proceso/{orden}
POST /api/materia-prima/ordenes-proceso
POST /api/materia-prima/ordenes-proceso/{orden}/versiones
POST /api/materia-prima/ordenes-proceso/{orden}/cancelar
```

Crear y versionar usan `operacion_id`: repetir la misma solicitud devuelve la misma orden y el mismo identificador con otros datos responde 409. Cancelar exige `version` y `motivo` y el gate `cancelar-ordenes-proceso` (administrador y supervisor de frío).

## Oficina

`/oficina/materia-prima/ordenes-proceso` muestra las órdenes por línea con su avance, el detalle de la versión vigente con entregado y pendiente por lote, y el historial de versiones. El digitador arma la orden buscando lotes con bins libres, ordena la secuencia y completa N° de proceso, embalaje, kg por caja, mercado, comuna y provincia. Supervisión ajusta nombre, rendimiento y estado de cada línea.

## Fruta a proceso con orden

El camarero entrega contra la versión vigente de una orden abierta. Línea y número de orden salen de la orden: `linea_proceso` guarda el nombre de la línea y `numero_orden` el N° de proceso de la fila (o el código OP- si la fila no lo tiene), así Finanzas y los retornos por bin siguen leyendo los mismos campos. El turno es el real del viaje, no el de la orden.

| Caso | Resultado |
| --- | --- |
| Lote en la versión vigente | Se descuenta de su reserva. La primera entrega pasa la orden a `en_proceso`; sin pendientes termina `completa`. |
| Más bins que los pendientes de la fila | 422 en `cantidad_envases`: el digitador debe publicar otra versión. |
| La tablet tenía una versión anterior | Sigue si la fila publicada completa no cambió; si cambió proceso, embalaje, mercado, cantidad, secuencia, horario u otro dato del lote, o el lote salió, 409. La versión es obligatoria y debe pertenecer a la orden seleccionada. |
| Lote fuera de la orden | Exige `autorizacion.user_id` de un supervisor de frío o administrador y su PIN operacional. Entra a la orden como versión nueva de origen `fuera_de_orden`, creada por el supervisor, con los bins del viaje. La entrega queda con `fuera_de_orden` y `autorizado_por_user_id`. |
| Orden terminada o cancelada | 409. |
| Anular una entrega | Devuelve los bins a la reserva. Una orden terminada completa se reabre; en una cancelada lo anulado vuelve al lote, no a la orden. |

El PIN se verifica antes de abrir la transacción, para que los intentos fallidos se conserven aunque la entrega se rechace.

### Transición

`FRUTA_PROCESO_EXIGE_ORDEN` (`config/materia_prima.php`) queda en `false`: la APK anterior sigue enviando línea, turno y número de orden como texto. Al pasarlo a `true`, un viaje sin `orden_proceso_mp_id` responde 422. Se activa cuando todas las tablets de cámara tienen la APK con órdenes.

Durante la transición, un viaje sin orden solo consume bins libres después de descontar las reservas pendientes de todas las órdenes. Si la cantidad requiere bins reservados, debe elegirse la orden correspondiente. El control se realiza en servidor con el lote bloqueado, también frente a publicaciones simultáneas.

### Bloqueos

Entrega con orden: orden → lotes de la orden e incluido el entregado, por id → reserva. Anulación: orden → entrega → lote, el mismo orden entrega → lote que ya usaban anulación y Retornos de Packing. La temporada se lee con bloqueo compartido para que el snapshot se abra con el lote bloqueado (antes se abría al leer la temporada, antes del lote).

`FrutaProcesoOrdenesConcurrenciaTest`: dos camareros por 6 bins de 10 programados (uno 200, otro 422) y un viaje simultáneo con una versión que baja el lote; en ambos casos la reserva cuadra con las entregas vigentes.

### API de la tablet

```http
GET  /api/materia-prima/fruta-proceso/ordenes?linea_proceso_id=
GET  /api/materia-prima/fruta-proceso/autorizadores
POST /api/materia-prima/fruta-proceso/lotes/{lote}/entregas
     { orden_proceso_mp_id, version_orden_proceso_mp_id, cantidad_envases, turno, kilos_enviados?, observacion?,
       autorizacion?: { user_id, pin } }
```

`/fruta-proceso/catalogos` suma `lineas` y `turnos`. Cada lote informa `ordenes` (órdenes abiertas con bins pendientes de ese lote) y cada entrega su `orden_proceso`.

### APK y oficina

La tablet suma la pestaña Órdenes: elige línea, ve los lotes en secuencia con lo entregado, lo pendiente, la cámara y el horario estimado, y entrega desde la fila (el siguiente lote queda destacado). Al registrar un viaje elige En orden, Fuera de orden (orden de la línea, supervisor y PIN) o Sin orden mientras no se exija. La oficina de Fruta a proceso permite elegir la orden del lote al registrar un viaje.

Al abrir una fila desde Órdenes, la tablet vuelve a consultar el lote. Si esa orden ya no tiene pendientes, pide actualizar y elegir otra vez; nunca selecciona automáticamente otra orden del mismo lote. Los lotes con salida directa de Hidrocooler se muestran con cámara nula.

Los nombres de línea admiten 60 caracteres tanto en el catálogo como en entregas y orígenes de retornos por bin. La migración `2026_11_02_100000` amplía las columnas existentes sin truncar nombres.

## PRE-SIF y programado contra real

`GET /api/materia-prima/ordenes-proceso/{orden}/pre-sif?formato=xlsx|pdf` genera la PRE-SIF desde la versión vigente, con el nombre `pre-sif_op-000001_v1_t1_l1_02-11-26`.

- **Encabezado**: título, orden y versión, fecha, versión (V1, V2…), turno con código de planilla (T1/T2), línea, CSP de la planta (`PLANTA_CSP`), horario de inicio y vuelta de colación.
- **Columnas**: las 20 de la planilla actual. Variedad agronómica y comercial repiten la variedad del lote, y N° de cajas muestra queda en blanco porque FoliOS no la registra.
- **Tiempo estimado**: kilos ÷ rendimiento, sin colación, en h:mm como la planilla. El horario de término sí incluye la colación.
- **Colación**: va como fila separadora después del último lote que termina antes de salir.
- **Total**: bins, totes y kilos.
- **Formatos**: el Excel se arma como OOXML propio, con filas que se ajustan al texto. El PDF es A4 horizontal con el logo de planta: ajusta la altura de cada fila al texto completo y pagina por altura disponible, repitiendo el encabezado sin recortar campos ni el total.

El detalle de la orden suma por lote los viajes, el primer y el último viaje en hora de planta y el desfase en minutos del primer viaje contra el inicio estimado (positivo: empezó tarde). La oficina lo muestra en las columnas Real y Desfase, junto a programado, entregado y pendiente, y descarga la PRE-SIF en Excel o PDF.
