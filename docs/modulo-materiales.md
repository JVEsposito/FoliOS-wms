# Módulo de cámaras de materiales

## Objetivo

Controlar materiales almacenados en cámaras o bodegas usando el mismo plano,
sesiones exclusivas y motor de movimientos de FoliOS, pero permitiendo
retiros parciales por cantidad.

## Modelo operacional

- Una cámara se clasifica como `productos` o `materiales` al configurarla.
- Una cámara ocupada no puede cambiar de clasificación.
- Un folio de material representa un único ítem. Una posición puede contener
  varios folios pequeños cuando la operación física los mantiene apilados en
  el mismo bulto o sector autorizado.
- El ítem se selecciona desde el catálogo administrado en oficina.
- El ingreso registra cantidad inicial, unidad de medida, lote, proveedor y
  observación. La unidad se copia desde el catálogo y no puede cambiarse en un
  ítem que ya tenga folios.
- El saldo actual, el saldo reservado y el disponible se conservan separados.
- Los folios de material solo pueden moverse entre cámaras de materiales.

## Catálogos de oficina

Los clientes son transversales y se crean, modifican o desactivan únicamente
en `/oficina/accesos`. Cada temporada conserva una proyección técnica del
cliente global para asociar ítems e inventario sin duplicar el maestro.

En Materiales el administrador mantiene:

1. Ítems: código, nombre, categoría, unidad de medida y referencia externa
   opcional.
2. Proveedores: código, nombre, referencia externa y uno o más clientes
   globales asociados.
3. Destinos: nombre, centro de costo, descripción y referencia externa
   opcional.

Los registros se activan o desactivan; no se eliminan físicamente. Los campos
de integración permiten que una futura sincronización con ERP mantenga la
identidad interna del registro.

## Recepción y conciliación física

Recepción de Materiales distingue cuatro cantidades por ítem:

```text
cantidad documental = lo indicado por la guía
cantidad contada = lo observado físicamente
cantidad aceptada = suma de los bultos que generan folio
cantidad rechazada = parte contada que no ingresa al inventario
```

La conciliación obligatoria es:

```text
cantidad contada = cantidad aceptada + cantidad rechazada
```

La cantidad documental puede diferir de la contada y esa diferencia permanece
visible como antecedente operacional. Los folios se generan exclusivamente por
los bultos aceptados. Un ítem totalmente rechazado conserva su línea documental
y física, pero no crea folios ni saldo de inventario.

El formato del folio es único: `F` + las dos letras configuradas para el cliente
+ un correlativo de siete dígitos. Por ejemplo, el primer folio del cliente
`GE` es `FGE0000001`; las letras centrales cambian según el cliente y no forman
un prefijo fijo compartido.

El contrato mantiene temporalmente `cantidad_recibida` como alias de
`cantidad_aceptada` para no interrumpir clientes móviles anteriores.

### Oficina de recepciones

`/oficina/materiales/recepciones` permite crear, consultar y confirmar
recepciones. La distribución física se ingresa indicando la cantidad aceptada
y las unidades por bulto; el sistema genera todos los bultos y calcula el
último con el diferencial.

La edición y eliminación administrativa son exclusivas del rol administrador.
Una recepción confirmada solo puede corregirse o eliminarse si todos sus folios
conservan el saldo original y todavía no fueron ubicados, reservados, retirados,
bloqueados, despachados ni utilizados en transformaciones u otros procesos.

La eliminación física conserva un snapshot en
`eliminaciones_recepciones_materiales`. Sus números se incorporan a
`folios_materiales_liberados` y el siguiente ingreso del mismo cliente los
consume en orden antes de avanzar el correlativo. Si existían etiquetas
impresas, sus trabajos se invalidan y las copias físicas deben destruirse antes
de reutilizar el número.

Desde la misma oficina se puede descargar el registro de muestreo completado de
cada recepción confirmada o una versión en blanco para uso manual, contingencia
y auditoría. La copia en blanco no depende de una recepción ni arrastra datos de
proveedores o materiales anteriores; conserva porcentajes de referencia,
resultado, observaciones y firmas.

## Bloqueo supervisado

Administrador y supervisor de Materiales pueden bloquear un folio activo que no
posea reservas. El bloqueo exige motivo, retira inmediatamente el saldo de la
disponibilidad y no elimina su ubicación ni su existencia física.

La liberación también exige motivo y registra usuario, fecha, estado anterior y
estado resultante:

- un folio ubicado vuelve a `disponible`;
- un folio sin ubicación vuelve a `pendiente_ubicacion`.

Cada acción utiliza UUID idempotente y queda registrada en
`eventos_bloqueos_materiales`. Camareros, despachadores y perfiles de consulta
pueden observar el estado, pero no modificarlo.

El panel gerencial separa stock actual, disponible, reservado, bloqueado y
pendiente de ubicación. Solo se considera disponible el saldo de folios
ubicados en posiciones y cámaras de Materiales activas, con estado
`disponible` y sin motivo de bloqueo.

### Cambio de temporada e inventario

Solo el administrador ejecuta la migración desde `/oficina/accesos`. Puede
copiar ítems y sus asociaciones de cliente hacia una temporada vacía sin
copiar cantidades. Los clientes globales ya están disponibles en todo ciclo.
Si
además selecciona inventario, el destino se activa globalmente en la misma
transacción y cada folio con saldo positivo se vincula al ítem equivalente de
la nueva temporada. El número de folio, la posición, el saldo y el kardex
histórico se conservan.

La migración de inventario se rechaza si existen despachos pendientes o
parciales, reservas abiertas o ítems sin equivalencia en el destino. Cada
ejecución y cada folio trasladado quedan registrados en la auditoría de
migraciones de temporada.

### Importación masiva del catálogo

El administrador puede cargar ítems desde `/oficina/materiales` usando una
planilla CSV o XLSX. La plantilla admite las columnas `temporada_codigo`,
`cliente_codigo`, `codigo`, `nombre`, `categoria`, `unidad_medida`,
`codigo_externo` y `activo`, con un máximo de 5.000 filas de datos por archivo.
La temporada debe existir y `cliente_codigo` debe corresponder a un cliente
activo creado previamente en Accesos.

La carga se ejecuta en dos etapas:

1. previsualización de filas válidas, errores, creaciones y actualizaciones;
2. confirmación transaccional de una planilla sin errores.

La importación solo modifica `items_materiales`: no crea folios, cantidades,
reservas ni movimientos. Un ítem ausente de la planilla conserva su estado y
los campos opcionales vacíos no borran datos existentes. Tampoco se permite
cambiar la unidad de medida cuando el ítem ya tiene folios asociados. Cada
intento guarda el nombre y checksum del archivo, las filas procesadas, el
resumen, el usuario y la fecha de confirmación para auditoría. El archivo
original no se conserva. Si el catálogo cambia entre la previsualización y la
confirmación, la operación se rechaza y exige una nueva previsualización.

## Despacho por cantidades

Un despacho puede crearse desde `/oficina/materiales` por administrador,
supervisor de materiales o despachador. Un supervisor de materiales también
puede crearlo desde una tablet. El camarero de materiales ejecuta retiros sobre
órdenes existentes, pero no crea ni cancela despachos. Cada línea solicita una
cantidad de un ítem. El sistema reserva folios por fecha de ingreso y número de
folio, y devuelve esas reservas como sugerencia FIFO. El despacho queda ligado
a la temporada global activa y no admite ítems de otro ciclo.

Oficina permite elegir entre despacho delegado (reserva para retiro en tablet,
con asignación opcional a un camarero) y entrega directa desde bodega. La
entrega directa admite varios folios e ítems y confirma en una sola transacción
la solicitud, los retiros y la transferencia de custodia al centro de costo.
Un despacho delegado pendiente puede reasignarse con motivo; cada cambio
queda registrado con usuario, fecha y UUID de operación.

FIFO no bloquea la operación: se puede retirar desde otro folio o saltar uno
anterior reservado. Cada retiro distinto del orden y cantidad reservados exige
un motivo de al menos cinco caracteres. La decisión y su motivo quedan en
`retiros_materiales.siguio_fifo` y `motivo_excepcion_fifo`; la trazabilidad
de Oficina muestra las reservas activas, entregas y reasignaciones. Los retiros
anteriores a esta regla pueden conservar `motivo_excepcion_fifo` vacío.

Cada retiro:

- exige una sesión activa y el bloqueo de la cámara correspondiente;
- registra usuario, tablet, posición, destino y centro de costo;
- descuenta únicamente la cantidad confirmada;
- conserva el folio en su posición mientras tenga saldo;
- libera la posición y cierra el folio cuando su saldo llega a cero;
- actualiza el kardex dentro de la misma transacción MySQL.

La creación de despachos y los retiros reciben un UUID de operación. Repetir
la misma solicitud devuelve el resultado ya confirmado y no duplica reservas
ni descuentos.

## Pantallas

- `/oficina/camaras`: crea y administra cámaras de productos o materiales.
- `/oficina/materiales`: mantiene catálogos, crea órdenes, consulta existencia
  y revisa despachos.
- `/oficina/materiales/recepciones`: registra guías, confirma folios y permite
  la corrección o eliminación controlada por administrador.
- `/`: operación web de cámara, incluyendo ingreso y retiro de materiales.
- `mobile/`: cliente Expo/React Native con las mismas operaciones para la APK.

## Fuera de esta entrega

- sincronización efectiva con ERP;
- cola offline persistente;
- equivalencias o conversiones entre unidades de medida.


## Custodia, movimientos y vencimiento

La custodia se distribuye por almacén: Bodega Central, almacenes físicos y
centros de costo virtuales. Entregar material transfiere custodia; consumirlo
reduce la existencia total. Oficina permite ajustes supervisados con motivo,
devoluciones y consumo directo de insumos imputado a un centro de costo.
Las reservas de despachos y transformación se toman de Bodega Central por
FEFO (primero la fecha de vencimiento, luego fabricación e ingreso; los folios
sin fecha quedan después de los fechados).

Un folio con fecha de vencimiento permanece vigente durante toda esa fecha
calendario en `America/Santiago`. Vence a las 00:00 del día siguiente, incluso
si el procesamiento programado todavía no se ejecutó. Sin fecha, no vence.
La regla central está en `FolioMaterial::estaVencido` y se aplica en el servidor
al reservar, retirar/entregar, transferir y consumir, incluido el cierre de un
lote de transformación. Una justificación de excepción FIFO no habilita un
folio vencido. El mensaje identifica su número y fecha.

`materiales:procesar-vencimientos` se programa diariamente a las **00:15 de
Chile**, sin ejecuciones superpuestas. En una transacción libera las reservas
activas de los folios recién vencidos, conserva lo ya consumido y reasigna la
cantidad pendiente a otros folios válidos por FEFO. Si faltan cantidades, la
línea queda parcialmente reservada y expone **Reserva insuficiente por
vencimiento** en Oficina y en la API. No modifica cantidades físicas ni
ubicaciones. El bloqueo usa motivo **Vencido** y registra un evento en
`eventos_bloqueos_materiales` con `user_id = null` (Sistema). Cada ejecución
registra sus cantidades procesadas y líneas insuficientes en
`procesamientos_vencimientos_materiales`. Repetirla no duplica bloques ni
reservas. El procesamiento alcanza folios activos de la temporada vigente.

Una devolución desde un centro de costo virtual a un almacén físico puede
retornar material vencido; conserva su bloqueo. Otros bloqueos manuales
continúan impidiendo operaciones. El descarte se registra mediante un ajuste
negativo supervisado con motivo, sin alterar el historial.

La liberación normal rechaza los vencidos. Un Supervisor de Materiales o
Administrador puede **Corregir vencimiento**, indicando nueva fecha y motivo
obligatorios (por ejemplo, reanálisis). Se auditan las fechas anterior/nueva,
el usuario y el motivo; `operacion_id` asegura reintentos idempotentes. Una
fecha vigente retira el bloqueo automático y deja el folio disponible si
está ubicado, o pendiente de ubicación en otro caso. Si antes había un
bloqueo manual, se conserva. La corrección no borra movimientos ni recupera
reservas históricas automáticamente.

**Oficina → Materiales → Vencimientos** muestra Por vencer y Vencidos, por
fecha, con filtros de cliente, categoría y almacén, cantidades, ubicación y
exportación Excel. La alerta se configura en `config/materiales.php` mediante
`MATERIALES_DIAS_ALERTA_VENCIMIENTO` (30 días por defecto) y admite una
excepción nullable por ítem (`dias_alerta_vencimiento`, editable en Catálogos;
0 avisa solo el mismo día). Inventario, Consultas y la PDA identifican el
vencimiento; el panel gerencial cuenta folios y suma cantidades **por unidad
de medida**, sin mezclar unidades incompatibles. El listado de Vencidos
incluye también los recién vencidos antes del procesamiento nocturno.

Siguen pendientes las siguientes entregas: verificaciones específicas de
Materiales, conteo e inventario físico, mínimos de stock, ampliación del panel
gerencial y valorización por costo.

## Verificación ciega de ubicación en materiales

El camarero de materiales recibe una ronda por turno, separada de frío. Los
turnos y días sin repetir son compartidos; por defecto se asignan cinco
posiciones y se compara cantidad con tolerancia del 2 %. En `config/verificaciones.php`
cada contenido tiene su configuración. Las rondas conservan el contenido, la
verificación de cantidad y la tolerancia vigentes al asignarse.

Se eligen posiciones activas en cámaras de materiales activas, repartiendo
entre cámaras y combinando ocupadas con algunas vacías. Se excluyen posiciones
recientes y posiciones que contengan cualquier folio reservado para despacho,
transformación o con una tarea/retiro en curso. Los bloqueados y vencidos se
incluyen porque su existencia física también debe comprobarse.

En **Labores** de la tablet/PDA, el camarero ve cámara, banda y posición;
escanea todos los folios y cuenta en la unidad del ítem. **Confirmar posición**
registra todo junto; marcar vacía también requiere confirmar. La API ciega no
publica folios ni cantidades esperadas, ni antes ni después de registrar. La
consulta de un código escaneado devuelve solo su unidad, nunca su ubicación o
saldo. Los reintentos conservan `operacion_id` y se controlan por versión.

La comparación transaccional bloquea folios y saldos y usa la custodia del
almacén **en esa cámara**, nunca el total empresa. Guarda cada comparación en
`verificaciones_ubicacion_folios`: `coincide`, `diferencia_cantidad`,
`folio_faltante` o `folio_sobrante`, con la otra posición cuando se conoce. Un
movimiento/retiro posterior a la asignación deja el ítem `no_aplica` y lo
reemplaza; no registra una discrepancia sobre datos que ya cambiaron.

Cada diferencia abre una incidencia de origen **verificación** en Operación
ahora, con ubicación y cantidades. **La verificación no ajusta inventario.**
Supervisor de Materiales/Administrador puede usar **Revisar ajuste** para
registrar una diferencia aprobada en el flujo de almacén, con motivo. El ajuste
queda enlazado a la incidencia y la resuelve, con usuario y fecha. Se rechazan
incidencias de otra temporada, folio o cámara y los reintentos no duplican ajustes.

Operación ahora separa rondas de frío y materiales. Gerencia → Materiales muestra
por cámara los últimos 7 y 30 días: exactitud de ubicación (posiciones sin folios
faltantes/sobrantes), exactitud de cantidad (folios presentes con cantidad dentro
de tolerancia) y cumplimiento de rondas completadas/generadas. `no_aplica` no se
cuenta como una diferencia; las posiciones vacías verificadas sí cuentan en
exactitud de ubicación. Si no hay muestras, se muestra **Sin datos**.

### Activación y actualización

Desplegar primero las migraciones y el backend, después la actualización OTA de
la tablet/PDA. Activar `VERIFICACIONES_HABILITADAS=true` y
`VERIFICACIONES_MATERIALES_HABILITADAS=true`. Opciones específicas:
`VERIFICACIONES_MATERIALES_POSICIONES_POR_RONDA=5`,
`VERIFICACIONES_MATERIALES_VERIFICAR_CANTIDAD=true` y
`VERIFICACIONES_MATERIALES_TOLERANCIA_CANTIDAD_PCT=2`.
Los datos históricos de frío conservan contenido `productos`; sus folios únicos
siguen en el esquema original. La verificación de materiales queda desactivada
hasta habilitarla expresamente.

### Toma ocasional de inventario por cámara

Oficina → Materiales → **Tomas de inventario**. El supervisor de materiales o administrador crea un borrador con cámaras activas y categoría opcional, y abre la toma asignando camareros. Las posiciones se ordenan por cámara, banda, posición y nivel y se reparten en bloques contiguos equilibrados (como máximo una posición de diferencia entre camareros). Se congelan los saldos locales y sus posiciones; no se bloquea la operación durante el conteo. Una cámara no puede participar en dos tomas abiertas simultáneamente.

En PDA → Labores aparece la toma asignada. Usa la misma captura ciega de la verificación: escanear cada folio, indicar cantidad en su unidad o marcar posición vacía, y confirmar la posición. La API del camarero publica exclusivamente posiciones asignadas y progreso, antes y después del conteo. No publica la foto, folios esperados, cantidades, diferencias ni lecturas anteriores. Cada posición tiene su versión e idempotencia, permitiendo conteos simultáneos de camareros diferentes.

El esperado se fija al confirmar cada posición, bajo bloqueo de folios y saldos. Es el saldo local vigente, equivalente a la foto inicial más la variación operativa de ese saldo. Se conservan la cantidad inicial, esperado al conteo y referencias a movimientos de ese almacén; nunca se usa el total distribuido del folio. Una transferencia a Packing durante el conteo reduce el esperado de bodega. Las entradas nuevas también se incluyen. Los materiales de otra categoría quedan fuera de la comparación. Los recontroles conservan el historial y sustituyen los resultados vigentes de la posición.

Cuando termina el conteo, el supervisor envía la toma a revisión y decide por diferencia: ajustar, reubicar, **reubicar y ajustar**, aceptar sin ajuste o recontar. La revisión muestra diferencias absolutas y porcentuales, filtros, totales por ítem/categoría separados por unidad, exactitud por folio y exportación Excel. Un folio desconocido exige identificarlo mediante el flujo normal, recontar o aceptar sin ajuste; no crea inventario ficticio. Un sobrante localizado en otra posición se reubica, evitando duplicar su saldo.

**Aprobar y aplicar** ejecuta todo en una sola transacción. Los ajustes usan el flujo de almacenes y quedan enlazados a la toma, con motivo y aprobador. Se aplica el delta detectado al saldo vigente; movimientos legítimos posteriores al conteo se conservan. Si el folio cambió de posición, se exige recontar. Las reubicaciones conservan saldo y custodia, registran origen/destino/aprobador y no permiten reservas o maniobras activas. Si un sobrante conocido está mal ubicado y además tiene otra cantidad, elige **Reubicar y ajustar** en ese resultado, con destino a la posición donde se contó, y **Aceptar sin ajuste** en su faltante asociado. La acción combinada reubica y aplica contado menos saldo local guardado al contar, conservando las salidas posteriores. Ambos registros quedan enlazados al resultado y a la toma; si falla uno, se revierte todo. La revisión muestra ese saldo y delta y Excel los incluye. Los conteos anteriores que no guardaron ese saldo requieren recontar para esta acción; si la cantidad coincide, usa solo Reubicar. Un saldo no puede recibir dos acciones de corrección independientes. La toma aprobada es inmutable; una anulación previa no modifica inventario. El acta PDF incluye alcance, fechas, responsables, resultados, ajustes aplicados, decisiones y firmas del supervisor y administrador.

**Resolver sin ajuste** está disponible en Operación ahora para incidencias de verificación, tanto frío como materiales. Exige el supervisor del contenido o administrador, motivo obligatorio y tipo `reubicado`, `error_de_conteo` u `otro`. Registra responsable, fecha y operación idempotente. Nunca altera saldos.

Despliegue: aplicar `php artisan migrate --force` después de #400, publicar la interfaz de Oficina y luego la OTA de tablet/PDA. No hay calendario automático ni nueva bandera de activación para las tomas ocasionales. Las banderas de verificación solo controlan las rondas de turno.

Prueba operativa: abrir una toma pequeña, realizar una salida durante el conteo, revisar una diferencia y una reubicación, aprobar, verificar saldos/kardex, descargar Excel/acta y contrastar el PDF impreso con los responsables de planta.


## Niveles de stock y reposición

Oficina → Materiales → Ítems permite al administrador o supervisor de materiales configurar mínimo, punto de reorden y máximo en la unidad del ítem, con hasta tres decimales. Son opcionales; los niveles presentes deben cumplir mínimo ≤ reorden ≤ máximo. El historial conserva valores anteriores/nuevos, responsable y fecha. El catálogo CSV/XLSX acepta `stock_minimo`, `punto_reorden`, `stock_maximo`; una celda vacía conserva el valor anterior, cero sí lo modifica. La previsualización valida también los niveles que se conservan. Los niveles se copian al migrar el catálogo de temporada.

La reposición usa exclusivamente el saldo local de **Bodega Central**, descontando reservas de despachos y transformación. Un folio bloqueado o vencido aporta cero disponible. La columna reservado informa todas las reservas de bodega; si un saldo reservado también está bloqueado, su cantidad se excluye una sola vez. El total empresa incluye los otros almacenes y es informativo. La fecha de vencimiento se evalúa en Chile incluso antes de ejecutarse el proceso de bloqueo diario.

Consumo: salidas de Bodega Central por entrega/retiro, consumo directo y transferencia hacia otro almacén, más consumos de transformación y despachos anteriores sin registro de almacén. No se duplica el espejo del kardex. Se restan devoluciones a bodega y restauraciones de entradas de una transformación revertida; los ajustes, consumos en Packing y transferencias de regreso no cuentan. Las salidas de folios agotados siguen contando. El período incluye hoy y los N−1 días anteriores, desde medianoche en America/Santiago hasta ahora. Promedio diario = consumo neto / N; cobertura = disponible / promedio, usando el promedio sin redondear. Si el neto es cero o negativo, se muestra «sin consumo»; las devoluciones netas negativas siguen visibles en el detalle. El detalle semanal utiliza semanas de lunes a domingo en Chile y pagina los movimientos de 100 en 100.

Estados, en orden de prioridad: quiebre (disponible cero con mínimo configurado), bajo mínimo (menor al mínimo), sobre máximo (mayor al máximo), reponer (menor o igual al reorden), normal. Sin niveles no hay alertas. La sugerencia es máximo − disponible, o reorden × 2 − disponible si falta máximo, redondeada hacia arriba a unidades completas, nunca negativa. Si no hay máximo ni reorden, no hay objetivo de compra y la sugerencia queda vacía.

Oficina → Materiales → **Reposición** muestra por defecto quiebres, bajos mínimos y reponer, ordenados por cobertura; los ítems sin consumo van al final. Permite cliente/categoría/estado y períodos de 30, 60 o 90 días. Excel usa las mismas filas y filtros. El detalle muestra consumo semanal, movimientos que lo componen e historial de niveles. La cabecera de Materiales indica quiebres y bajos mínimos y enlaza esta vista. Gerencia muestra cantidades por estado, cobertura media de los ítems con consumo positivo y los diez con menor cobertura.

Después de confirmar cambios de saldo, reservas, bloqueos, vencimientos, catálogo o movimientos se recalcula el ítem. El aviso operacional se dirige al supervisor de materiales solo al pasar a quiebre o bajo mínimo; se muestra también en los avisos de Oficina y puede marcarse leído. La revisión persistida y bloqueada del ítem evita avisos duplicados; recuperarse y caer nuevamente genera uno nuevo. No se modifica inventario. El cálculo diario idempotente `materiales:recalcular-reposicion` corre a las **06:00 de Chile**; requiere scheduler activo. `MATERIALES_REPOSICION_DIAS_CONSUMO=30` configura el período por defecto (1–365 días). Despliegue: migración, assets de Oficina y ejecución inicial del comando. No se requiere OTA nueva para esta vista.
