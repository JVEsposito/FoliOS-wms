# Módulo de Prefrío

## Objetivo

Administrar el tratamiento térmico de pallets y saldos de producto entre Validación y su habilitación para almacenamiento, conservando procesos históricos, eventos auditables y resultados por folio.

```text
Validación
→ pendiente_prefrio
→ carga en túnel
→ proceso térmico
→ verificación
→ habilitación para almacenamiento
→ ubicación en cámara
→ disponible para carga
```

La aprobación térmica no ubica el folio ni lo deja inmediatamente disponible para una carga. Solo lo habilita para que Cámaras pueda recibirlo.

## Túneles y cámaras son dominios diferentes

Una cámara representa almacenamiento físico persistente. Un túnel representa un activo que ejecuta procesos térmicos temporales.

Por esta razón Prefrío utiliza tablas independientes:

- `tuneles_prefrio`;
- `posiciones_tunel_prefrio`;
- `procesos_prefrio`;
- `procesos_prefrio_folios`;
- `eventos_prefrio`;
- `historial_habilitaciones_almacenamiento`.

Los túneles no se incorporan como un tipo adicional de cámara.

## Configuración de túneles

Cada túnel registra:

- código correlativo `TUN-XX`;
- nombre;
- cantidad configurable de posiciones;
- setpoint habitual;
- estado administrativo;
- estado técnico;
- código externo opcional;
- observaciones;
- versión de configuración.

La capacidad puede representar túneles de 20, 22, 40 u otra cantidad par de posiciones dentro de los límites configurados. Cada profundidad contiene dos lados y el plano siempre se recorre desde el fondo hacia la entrada.

### Estado administrativo

```text
activo
inactivo
```

### Estado técnico

```text
operativo
fuera_de_servicio
mantenimiento
```

Un túnel solo puede iniciar procesos cuando se encuentra activo y operativo. No puede redimensionarse ni modificarse mientras mantiene un proceso activo.

## Procesos

Cada ciclo térmico se registra como un proceso independiente con código:

```text
PF-AAAA-NNNNNN
```

Estados:

```text
borrador
cargando
listo_para_iniciar
en_proceso
pendiente_verificacion
aprobado
requiere_reproceso
cancelado
```

Un túnel admite como máximo un proceso activo. Un folio tampoco puede participar simultáneamente en dos procesos activos.

La relación histórica permite que el mismo folio participe posteriormente en otro proceso cuando requiere reproceso.

Los estados `borrador`, `cargando` y `listo_para_iniciar` admiten carga de folios. Si se agrega o retira un folio después de confirmar el armado, el proceso vuelve a `cargando` y debe confirmarse nuevamente antes de iniciar. Desde `en_proceso` la composición queda cerrada.

## Folios y posiciones

Durante la carga, las posiciones representan capacidad física:

- un folio de tipo `pallet` ocupa una posición de forma exclusiva;
- dos o más folios de tipo `saldo` pueden compartir una misma posición;
- los saldos compartidos pueden pertenecer a clientes, variedades, calibres o embalajes distintos;
- cada saldo conserva su identificación, temperaturas, resultado y trazabilidad individual.

Por lo tanto, un túnel de 20 posiciones puede contener más de 20 folios cuando existen saldos apilados físicamente:

```text
TUN-01-P01 = PAL-000001
TUN-01-P02 = SALDO-0001 + SALDO-0002 + SALDO-0003
...
```

La numeración se interpreta por pares:

```text
P01 / P02 = profundidad 1, al fondo
P03 / P04 = profundidad 2
...
último par = junto a la entrada
```

El proceso impide:

- repetir un folio;
- compartir una posición que contiene un pallet completo;
- cargar un pallet completo en una posición ocupada;
- mezclar pallets completos con saldos en una posición;
- usar una posición de otro túnel;
- cargar materiales;
- cargar folios ya habilitados para almacenamiento;
- cargar folios que permanecen ubicados en cámara.

## Condición térmica

La condición térmica se separa del estado comercial y de la ubicación física:

```text
pendiente_prefrio
en_proceso
prefrio_aprobado
requiere_reproceso
condicion_heredada
retenido
```

## Habilitación para almacenamiento

```text
no_habilitado
habilitado
retenido
```

Cámaras evalúa esta habilitación, no la existencia obligatoria de un proceso de Prefrío.

Prefrío es una fuente normal de habilitación, pero no la única. También pueden existir fuentes como:

- condición heredada desde repaletizaje;
- devolución operacional;
- contingencia autorizada;
- regularización manual auditada.

Esto permite que un pallet completo generado posteriormente desde saldos ingrese a cámara conservando una condición térmica heredada, sin fingir un nuevo proceso térmico.

La columna actual del folio permite decidir rápidamente si puede ingresar a cámara. Cada cambio se conserva además como un registro inmutable en `historial_habilitaciones_almacenamiento`, con estado resultante, condición térmica, fuente, proceso de origen, referencia, usuario, dispositivo, fecha, motivo y observación.

Al instalar esta fundación sobre inventario existente, solo los folios activos y disponibles se regularizan como habilitados. Los bloqueados quedan retenidos y los anulados, retirados, despachados o inactivos no reciben una habilitación automática.

## Resultado aprobado

Al aprobar el proceso:

```text
condicion_termica = prefrio_aprobado
habilitacion_almacenamiento = habilitado
fuente_habilitacion = prefrio_aprobado
```

El folio conserva su estado operacional anterior hasta ser ubicado. Al crearse su ubicación en una cámara de producto, el estado operacional pasa a `disponible`.

## Reproceso y retención

Al requerir reproceso:

```text
condicion_termica = requiere_reproceso
habilitacion_almacenamiento = retenido
estado_operacional = bloqueado
```

El folio conserva la relación con el proceso fallido y puede incorporarse a un proceso posterior.

Si un proceso iniciado se cancela, sus folios quedan retenidos. Si se cancela antes del inicio, se conserva la captura histórica sin afirmar que existió tratamiento térmico.

## Eventos auditables

Los eventos admitidos son:

```text
carga_iniciada
pallet_agregado
pallet_retirado
armado_confirmado
proceso_iniciado
inversion_registrada
pausa
reanudacion
deshielo
lectura
verificacion_final
aprobacion
reproceso
cancelacion
```

Cada evento conserva:

- UUID de operación;
- hash del payload;
- proceso;
- folio cuando corresponde;
- usuario;
- dispositivo;
- fecha informada por la operación;
- datos adicionales;
- observación.

Repetir el mismo UUID con el mismo contenido devuelve el estado confirmado. Reutilizarlo con contenido diferente genera conflicto.

Cada proceso utiliza además una versión incremental. Una acción basada en una versión desactualizada recibe un conflicto operacional.

## Registro de prefrío POEMP R-41

La planilla oficial POEMP R-41 se llena desde el proceso. Cada lectura mide 3 sectores del
túnel (ventilador, medio y puerta) con 4 sensores: exterior e interior del lado izquierdo y
del derecho. Son 12 temperaturas por lectura, entre -10 y 40 °C.

- **Lectura horaria.** Durante el enfriado se registra una lectura cada 60 minutos
  (`PREFRIO_INTERVALO_LECTURA_MINUTOS`). Puede quedar parcial: se guardan los sensores
  medidos y el resto queda vacío. Se envía como evento `lectura` con
  `datos.sectores.{sector}.{izq_ex|izq_in|der_in|der_ex}`. La tablet y la oficina avisan
  cuando la próxima lectura venció.
- **Lectura final obligatoria.** Para enviar a verificación (fin del túnel) se exige una
  lectura con los 12 sensores en `datos.lectura_final`. Si la última lectura horaria ya está
  completa, sirve de lectura final y no se pide otra. `PREFRIO_EXIGE_LECTURA_FINAL=false`
  desactiva la exigencia, por ejemplo mientras haya tablets sin la versión nueva.
- **Lecturas antiguas.** Las lecturas de una sola temperatura (`datos.temperatura`) se
  siguen aceptando y aparecen en las observaciones de la planilla como «sin sector».
- **Datos de la planilla.** La temperatura objetivo, el servicio, el supervisor y el jefe de
  frío se editan en la oficina (`PUT registro-r41`). Por defecto el supervisor es quien inició
  el proceso y el jefe de frío quien lo aprobó.
- **Excel.** `registro-r41.xlsx` usa la plantilla oficial. El encabezado sale del proceso:
  «N° Prefrío» es el código del túnel (por ejemplo, TUN-03) y «N° Proceso» es el código del
  proceso (PF-2026-000047). Los procesos de packing van en las observaciones. También salen
  los pallets, la especie, la variedad, las horas de inicio, inversión y término y el tiempo
  total. Los pallets se ubican por lado (impares a la izquierda, pares a la derecha) y
  profundidad desde el fondo. También salen las lecturas, los deshielos y las observaciones. Cada hoja lleva 10 profundidades y 10 lecturas; un túnel más profundo o un
  proceso con más lecturas continúa en otra hoja con el mismo encabezado.

## Roles

### Administrador

Puede:

- crear y modificar túneles;
- consultar y operar procesos;
- aprobar, reprocesar o cancelar;
- revisar toda la trazabilidad.

### Supervisor de frío

Puede:

- consultar y operar procesos;
- aprobar el resultado final;
- enviar a reproceso;
- cancelar justificadamente.

No administra la estructura de los túneles.

### Operador de Prefrío

Puede:

- consultar túneles y procesos;
- crear procesos;
- cargar y retirar folios antes del inicio;
- confirmar armado;
- iniciar;
- registrar inversiones, pausas, reanudaciones, deshielos y lecturas;
- enviar a verificación.

No puede crear túneles, tomar decisiones terminales, operar cámaras, cargas, materiales ni Validación.

### Consulta

Puede revisar túneles, procesos e historial sin ejecutar acciones.

## API

### Consulta

```http
GET /api/prefrio/tuneles
GET /api/prefrio/tuneles/{id}
GET /api/prefrio/folios-disponibles
GET /api/prefrio/procesos
GET /api/prefrio/resumen
GET /api/prefrio/procesos/{id}
GET /api/prefrio/procesos/{id}/registro-r41.xlsx
```

### Operación

```http
POST /api/prefrio/procesos
POST /api/prefrio/procesos/{id}/folios
POST /api/prefrio/procesos/{id}/folios/{asignacion}/retirar
POST /api/prefrio/procesos/{id}/confirmar-armado
POST /api/prefrio/procesos/{id}/iniciar
POST /api/prefrio/procesos/{id}/eventos/{tipo}
POST /api/prefrio/procesos/{id}/verificar
PUT  /api/prefrio/procesos/{id}/registro-r41
```

### Supervisión

```http
POST /api/prefrio/procesos/{id}/aprobar
POST /api/prefrio/procesos/{id}/reprocesar
POST /api/prefrio/procesos/{id}/cancelar
```

### Administración de túneles

```http
GET  /api/administracion/prefrio/tuneles/siguiente-codigo
POST /api/administracion/prefrio/tuneles
PUT  /api/administracion/prefrio/tuneles/{id}
```

## Punto de rocío (chequeos de condensación)

Los chequeos de punto de rocío son mediciones diarias independientes de los procesos de túnel: sirven para vigilar el riesgo de condensación sobre la fruta en sellado, paletizado, ingreso y salida de prefrío, almacenamiento u otra etapa. No bloquean ninguna operación.

Cada medición registra:

- etapa (sellado, paletizado, ingreso prefrío, salida prefrío, almacenamiento u otro);
- punto de control: una sala configurada por supervisión (sellado, paletizado…), un túnel de prefrío activo o una cámara activa;
- T° y humedad relativa del aire;
- T° superficial de la fruta (o de la película) opcional, nunca la de pulpa;
- gotas visibles: sí, no o pendiente;
- lote, pallet o referencia y observación opcionales;
- hora de medición, usuario y tablet;
- opcionalmente, el sellado cuyo aire quedó en el envase y la medición de donde viene la fruta (ver *Mediciones vinculadas*).

El servidor calcula con la aproximación de Magnus (a = 17,625; b = 243,04 °C), igual que la calculadora de cerezas:

- punto de rocío del aire;
- vapor de agua en g/m³;
- margen = T° superficial − punto de rocío;
- estado: **Medir superficie** (sin T° superficial), **Condensación posible** (margen < 0), **Margen bajo** (margen menor al mínimo) o **Margen favorable**.

El margen mínimo es 2 °C por defecto (`PREFRIO_PUNTO_ROCIO_MARGEN_MINIMO_C`) y supervisión puede ajustarlo por etapa desde la oficina (**Márgenes**). Es un criterio preventivo interno, no una norma. En mantención la humedad alta es la deseada: una cámara a 0 °C y 92 % HR tiene el rocío en −1,1 °C, de modo que con la fruta a 0 °C el margen natural es cercano a 1 °C. Cada medición guarda el margen que se le aplicó.

### Mediciones vinculadas

Una medición puede vincular hasta dos mediciones anteriores (por defecto de las últimas 72 horas, `PREFRIO_PUNTO_ROCIO_HORAS_REFERENCIA`), cada una con un sentido distinto:

- **Aire del envase · sellado de referencia.** El aire de la sala de sellado queda dentro de la bolsa y viaja con la fruta. Se compara la T° superficial de esta medición con el punto de rocío de ese sellado: si la fruta (o la película) baja de ese rocío, puede condensar dentro del envase. Solo se vinculan mediciones de etapa sellado, y una medición de sellado no lleva este vínculo porque ya evalúa su propio aire. Como la fruta también transpira dentro de la bolsa, ese rocío es un mínimo: el riesgo real puede ser mayor.
- **Fruta que llega · medición de origen.** La fruta llega con la T° superficial que tenía en la medición de origen (por ejemplo, salida de prefrío) y queda expuesta al aire de este punto (por ejemplo, la cámara de mantención). Se compara esa T° superficial con el punto de rocío de esta medición. La medición de origen debe tener T° superficial.

Las dos comparaciones usan el margen mínimo de la etapa de la medición que se registra, se guardan con la medición y alertan igual que el margen propio: condensación posible o margen bajo en cualquiera de ellas deja la medición como alerta y avisa a supervisión de frío. El aviso indica cuál comparación lo provocó (por ejemplo, «Condensación posible en el envase»). Si después se anula la medición vinculada, la comparación guardada se conserva y queda marcada como anulada.

Dentro de bolsas selladas, el agua superficial y el vapor de la fruta pueden condensar aunque la sala cumpla.

### Plan de cadena de frío

El plan fija, para cada etapa de la cadena (packing, sellado, paletizado, ingreso y salida de prefrío, traslado, almacenamiento, despacho / andén y carga de contenedor), la **T° del aire planificada** y la **T° superficial más baja que tendrá la fruta en ese aire**. Lo edita supervisión de frío en *Editar plan*; una etapa sin T° de fruta queda fuera del plan.

Con eso FoliOS calcula:

- **rocío máximo** = T° de fruta − margen mínimo de la etapa;
- en **sellado**, además, el rocío máximo del aire que queda en la bolsa: la fruta más fría de las etapas siguientes menos su margen. Manda el menor de los dos (se muestra «por el envase»);
- **HR máxima** a la T° de aire planificada para no superar ese rocío. Si la fruta está más caliente que el aire (túnel, cámara), no hay límite de HR;
- aviso **cerca de congelación** cuando el aire o la fruta planificados quedan a menos de 0,5 °C de la T° de congelación de la fruta (`PREFRIO_TEMPERATURA_CONGELACION_C`, por defecto −1,7 °C, referencia para cereza dulce).

Cada medición guarda el rocío máximo del plan vigente al registrarse y muestra la HR que el plan habría permitido a la T° medida. Si su rocío lo supera queda **fuera de plan**: se ve en el día, en la franja de la cadena y en el registro, pero no notifica ni pide acción correctiva (eso lo deciden los márgenes y las comparaciones). Cambiar el plan no reinterpreta lo ya medido.

Ejemplo con cámara a −1 °C y pulpa entre −0,5 y 0,5 °C (margen de 0,5 °C en las etapas frías): sellado a 12 °C con HR ≤ 39 % para que el aire de la bolsa no condense con la fruta a −1 °C en salida y traslado; traslado a 4 °C con HR ≤ 67 %; despacho a 4 °C con HR ≤ 69 %; contenedor a 0 °C con HR ≤ 93 %. La cámara no tiene límite de HR porque la fruta queda sobre la T° del aire.

Una medición con condensación posible, margen bajo o gotas visibles queda como alerta: se avisa a supervisión de frío en Operación ahora (crítica si hay condensación o gotas) y la alerta se mantiene hasta que supervisión registre una acción correctiva. Supervisión también puede anular una medición mal tomada indicando el motivo; la medición anulada queda visible, pero no cuenta en el resumen.

Reglas de registro:

- cada medición usa un `operacion_id` (UUID); repetirlo con el mismo contenido no duplica y reutilizarlo con otro contenido genera conflicto;
- la hora de medición no puede estar en el futuro y solo se aceptan los últimos 7 días (`PREFRIO_PUNTO_ROCIO_DIAS_RETROACTIVOS`);
- el día operacional se calcula en la zona horaria de la planta.

La oficina `/oficina/prefrio/punto-rocio` muestra por día el resumen (registros, rocío máximo, margen mínimo, alertas y gotas), el gráfico de T° ambiente, punto de rocío y T° superficial, los últimos 14 días, el filtro por etapa y el registro completo. Permite registrar y corregir desde oficina, y a supervisión registrar acciones, anular y administrar salas. En la tablet, la pestaña **Punto de rocío** de Prefrío registra la medición con el cálculo en vivo y lista los chequeos del día; requiere conexión y un reintento tras perder la red no duplica la medición.

```http
GET  /api/prefrio/punto-rocio?fecha=YYYY-MM-DD&etapa=…    (consultar)
POST /api/prefrio/punto-rocio                             (operar)
POST /api/prefrio/punto-rocio/{medicion}/accion           (supervisar)
POST /api/prefrio/punto-rocio/{medicion}/anular           (supervisar)
POST /api/prefrio/punto-rocio/salas                       (supervisar)
PUT  /api/prefrio/punto-rocio/salas/{sala}                (supervisar)
PUT  /api/prefrio/punto-rocio/margenes                    (supervisar)
PUT  /api/prefrio/punto-rocio/plan                        (supervisar)
```

## Interfaces implementadas

La oficina `/oficina/prefrio` permite configurar túneles, crear y filtrar procesos, consultar el plano en dos lados desde el fondo hacia la entrada, revisar el historial y tomar decisiones de aprobación, reproceso o cancelación según las capacidades del usuario.

La aplicación móvil entrega al operador:

- catálogo de túneles y procesos activos;
- búsqueda o escaneo de folios habilitados para Prefrío;
- carga en posiciones libres o compartidas exclusivamente por saldos;
- visualización separada de posiciones físicas y cantidad total de folios;
- retiro individual de cada folio sin afectar a los demás ocupantes;
- confirmación de armado e inicio;
- lecturas, inversión, pausa, reanudación y deshielo;
- envío a verificación;
- caché local y bandeja de salida por usuario y dispositivo.

Cada comando móvil se guarda antes de enviarse y mantiene su UUID. Si una operación de un proceso entra en conflicto o error, las acciones posteriores del mismo proceso quedan detenidas para impedir que se transmitan sobre una versión inválida.

La entrega incorpora reglas de dominio, esquema, permisos, API, oficina, operación móvil, contratos públicos y pruebas.

Quedan para entregas posteriores:

- fotografías;
- telemetría automática;
- integración con el ERP.
