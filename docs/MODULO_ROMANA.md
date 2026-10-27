# Módulo de Romana

## Objetivo

Romana es el punto de inicio contractual de la recepción. Registra lo que ingresó físicamente a la planta y conserva cliente, temporada, guía, transporte, envases declarados y, cuando corresponde, los pesos observados.

La oficina se encuentra en:

```text
/oficina/romana
```

## Separación respecto de Frigorífico

Romana y Frigorífico son dominios independientes.

Una recepción de Romana no crea, habilita ni mueve:

- folios;
- validaciones de pallets/PT;
- procesos de Prefrío;
- posiciones de Cámara;
- cargas de producto.

El correlativo `REC-*` identifica exclusivamente el expediente contractual de recepción. No es un folio ni un número de lote.

La trazabilidad de materia prima usa asociaciones explícitas entre recepción,
Validación MP, segmentos y lotes, sin reutilizar identificadores ni convertir el
cierre de Romana en una transición de Frigorífico. Aún falta vincular los
folios de producto terminado con los lotes de materia prima que los originaron.

## Temporada global

Cada recepción exige la temporada global activa y guarda:

- `temporada_id`;
- código de temporada como snapshot;
- nombre de temporada como snapshot.

Accesos es el único módulo que crea, edita, activa o migra temporadas.

Una recepción histórica conserva su temporada aunque posteriormente se active otro ciclo. La migración de temporada no copia ni transforma recepciones de Romana.

## Cliente global

La recepción se relaciona con el maestro global `clientes`.

Romana puede recibir cualquier cliente operacional activo. Al crear la recepción conserva código y nombre como snapshot contractual, por lo que una modificación posterior del maestro no altera el expediente ni el Aviso de Recibo.

El ingreso exige seleccionar el tipo de camión (`termo` o `plano`). Se muestra en el expediente y en el Aviso de Recibo, y las correcciones administrativas conservan el valor anterior y el posterior. Las recepciones anteriores a la incorporación de este dato quedan sin tipo informado; se exige al editarlas o corregirlas.

## Correlativo

El número de recepción se asigna al crear el expediente, dentro de la misma transacción que registra el peso bruto tradicional, la configuración inicial del pesaje acumulativo o el ingreso documental exclusivo de envases.

Formato:

```text
REC-AAMM-####
```

Ejemplo:

```text
REC-2607-0001
```

La secuencia es mensual y se bloquea al incrementarse para impedir duplicados bajo concurrencia.

Asignar el correlativo desde el inicio permite que Validación MP busque, pistolee y tome la recepción antes de que el camión regrese a destare.

## Estados de pesaje

| Estado | Significado | Acciones permitidas |
|---|---|---|
| `en_bascula_ingreso` | Se registraron los antecedentes y, cuando corresponde, el bruto de entrada. | Editar datos permitidos o confirmar ingreso. |
| `en_pesaje_envases` | La recepción pesa todos los envases declarados en una o más tandas. | Registrar o anular tandas; editar la configuración solo mientras no existan lecturas activas; cerrar al completar el total exacto. |
| `en_bascula_salida` | El ingreso fue confirmado. La fruta espera destare y la recepción exclusiva de envases espera cierre documental. | Registrar tara o cerrar sin kilos, según el tipo; corrección administrativa mientras Validación MP siga pendiente. |
| `cerrado` | Se completó el cálculo de pesos o el cierre documental sin kilos. | Consultar, descargar PDF o corregir administrativamente mientras Validación MP siga pendiente. |

No se permite volver a un estado anterior. Después de confirmar el ingreso los antecedentes contractuales quedan congelados para la operación normal. Solo un administrador puede corregirlos mientras Validación MP no haya tomado la recepción; la corrección exige motivo, conserva valores anteriores y posteriores, incrementa la versión y no cambia el estado.

Si la recepción ya está cerrada, una corrección del bruto o de la cantidad del envase seleccionado recalcula peso neto y neto por envase. No puede retirarse el tipo de envase usado para ese cálculo.

El estado de pesaje es independiente del avance de Validación MP. Una recepción puede estar cerrada en Romana y continuar pendiente, tomada o confirmada en el flujo de Validación MP.

## Datos transaccionales

La recepción registra:

- número `REC-*`;
- temporada y snapshots;
- cliente y snapshots;
- fecha y hora de ingreso;
- patente de camión;
- tipo de camión (termo o plano, obligatorio en ingresos nuevos);
- patente opcional de carro;
- RUT y nombre del conductor;
- tipo de servicio;
- número de guía;
- peso bruto, cuando la recepción contiene fruta;
- tara medida del camión, cuando corresponde;
- indicador de salida del camión sin los envases;
- tara unitaria y tara total calculada de los envases retenidos en planta;
- peso neto;
- tipo y cantidad de envases elegidos para el cálculo individual;
- peso neto calculado por envase;
- tipo de envase sometido a pesaje acumulativo;
- tara unitaria configurada para ese envase;
- cantidad pesada y pendiente;
- lecturas de cada tanda con cantidad, bruto, tara total y neto;
- observaciones de ingreso y cierre;
- versión;
- usuarios responsables;
- UUID y hash de payload para idempotencia.

Los envases se almacenan como líneas independientes, entre ellas:

- bins;
- totes;
- esponjas.

La recepción puede corresponder a:

- fruta con pesaje tradicional del camión;
- fruta con pesaje acumulativo de todos los envases;
- una operación exclusiva de envases por compra o arriendo.

Una recepción `solo_envases` no exige ni almacena peso bruto, tara o peso neto. Permite seleccionar la fecha efectiva de ingreso —hoy o una fecha anterior— y conserva la hora real en que se registró el expediente. La fecha seleccionada queda incluida en el evento auditable. Las recepciones con fruta mantienen fecha y hora automáticas. El expediente conserva además correlativo, guía, cliente, transporte, cantidades, concepto y trazabilidad, y se cierra documentalmente después de confirmar el ingreso.

En el pesaje tradicional de fruta, si el camión sale sin los envases que traían la fruta, la lectura de salida representa únicamente la tara del camión. Romana exige entonces la tara unitaria de cada tipo declarado y calcula:

```text
tara de envases = Σ(cantidad declarada × tara unitaria)
peso neto fruta = peso bruto - tara camión - tara de envases
```

La lectura real de la báscula y la tara calculada de los envases se conservan por separado en el expediente y en su evento de cierre.

En el pesaje acumulativo se declara un solo tipo de envase y su cantidad total. Por ejemplo, una guía con 60 bins puede registrarse en lecturas de 1, 3 o cualquier número que no supere el saldo pendiente. Cada lectura calcula:

```text
tara total de la tanda = tara unitaria × cantidad pesada
peso neto de la tanda = peso bruto de la tanda - tara total de la tanda
```

Los acumulados de bruto, tara y neto se recalculan utilizando únicamente lecturas vigentes. Una lectura errónea puede anularse con motivo antes del cierre. No se permite cerrar con menos o más envases que los declarados.

La guía no puede repetirse para el mismo cliente dentro de la misma temporada. El RUT se normaliza y valida con módulo 11. La tara debe ser positiva y menor al bruto.

## Flujo

```text
crear recepción y asignar REC-*
→ revisar antecedentes
→ confirmar ingreso
→ retorno del camión vacío
→ registrar tara
→ si sale sin envases, configurar tara por tipo y descontarla
→ calcular peso neto
→ seleccionar envase y calcular neto individual
→ cerrar
→ emitir Aviso de Recibo
```

Crear o cerrar Romana no genera automáticamente inventario frigorífico.

Flujo alternativo de pesaje acumulativo:

```text
crear recepción y asignar REC-*
→ seleccionar envase, total declarado y tara unitaria
→ registrar tandas de 1, 3 o N envases
→ acumular bruto, tara y neto
→ completar exactamente el total declarado
→ cerrar
→ emitir Aviso de Recibo
```

Flujo exclusivo de envases:

```text
crear recepción sin kilos y asignar REC-*
→ confirmar ingreso documental
→ cerrar sin bruto, tara ni neto
→ emitir Aviso de Recibo
```

## Validación MP

Las recepciones elegibles se publican al rol `validador_mp` mediante una bandeja operacional.

Validación MP:

- busca o pistolea `REC-*`;
- toma la recepción de forma exclusiva;
- hereda cliente, temporada, guía y transporte;
- compara envases declarados con cantidades reales;
- revisa visualmente tarjas;
- crea segregaciones provisionales;
- confirma el movimiento real de envases.

Los segmentos resultantes permanecen `pendiente_lote` hasta que la oficina de Materia prima distribuya sus envases y confirme uno o varios lotes.

Una recepción con pesaje acumulativo aparece desde su creación en la bandeja de Validación MP y puede ser tomada. Su confirmación queda bloqueada hasta que Romana pese el total declarado y cierre la recepción.

## Cuenta corriente de envases

Los envases declarados y luego confirmados alimentan el dominio de Envases mediante movimientos auditables.

Romana no modifica saldos de forma opaca: la cuenta se explica con movimientos firmados y puede distinguir propiedad propia, del cliente o arrendada según el origen de la operación.

Si Validación MP agregó bins de planta, el expediente los muestra. El destare los devuelve internamente antes de registrar los envases que salen: el camión no se los lleva. Ver [Préstamo interno de bins de planta](MODULO_ENVASES.md#préstamo-interno-de-bins-de-planta).

## Trazabilidad

Los eventos de Romana incluyen, entre otros:

```text
ingreso_registrado
ingreso_actualizado
correccion_administrativa
ingreso_confirmado
pesaje_envases_registrado
pesaje_envases_anulado
recepcion_cerrada
```

Cada evento identifica:

- recepción;
- usuario;
- operación idempotente;
- fecha;
- transición;
- datos relevantes.

Los eventos y recepciones no se eliminan físicamente para corregir la historia.

## Aviso de Recibo

Una recepción cerrada expone un PDF con:

- número de recepción;
- horas de entrada y salida;
- temporada y cliente;
- servicio y guía;
- envases declarados;
- camión, carro y conductor;
- bruto, tara del camión, tara calculada de envases y peso neto cuando corresponde;
- indicación expresa de “sin registro de kilos” para recepciones exclusivas de envases;
- para pesaje acumulativo: tara unitaria, total pesado, cantidad de tandas y neto promedio por envase;
- observaciones;
- espacios de firma.

El endpoint rechaza recepciones abiertas.

La oficina también permite descargar una planilla en blanco sin crear ni cerrar
una recepción. Mantiene el mismo encabezado controlado, antecedentes de
transporte, campos de pesaje, observaciones y firmas, y se identifica como copia
para contingencia, trazabilidad y auditoría.

## Integración gerencial

`/oficina/gerencia` muestra:

- camiones en ingreso;
- recepciones con pesaje acumulativo abierto;
- recepciones pendientes de destare o cierre documental;
- recepciones cerradas del día;
- clientes recibidos;
- envases declarados;
- peso neto diario;
- tendencia de siete días;
- alertas por camiones pendientes de salida.

## API

### Consulta

```http
GET /api/romana/catalogos
GET /api/romana/registro-pesaje/en-blanco
GET /api/romana/recepciones
GET /api/romana/recepciones/{id}
GET /api/romana/recepciones/{id}/aviso-recibo
```

### Operación

```http
POST /api/romana/recepciones
PUT /api/romana/recepciones/{id}
POST /api/romana/recepciones/{id}/confirmar-ingreso
POST /api/romana/recepciones/{id}/pesajes-envases
POST /api/romana/recepciones/{id}/pesajes-envases/{pesaje}/anular
POST /api/romana/recepciones/{id}/cerrar
PUT /api/romana/recepciones/{id}/corregir
```

Todas las rutas requieren `auth:sanctum` y el Gate correspondiente.

## Roles

| Rol | Consulta | Operación | Corrección administrativa |
|---|---:|---:|---:|
| `administrador` | Sí | Sí | Sí |
| `supervisor_frio` | Sí | Sí | No |
| `operador_romana` | Sí | Sí | No |
| `despachador` | Sí | No | No |
| `consulta` | Sí | No |

## Usuario local

En `local` y `testing`:

- usuario: `romana@estiba.local`;
- contraseña: `password`.

## Pendientes

- vínculo entre producto terminado y lotes de materia prima de origen para recall;
- telemetría directa desde la báscula;
- firma digital o integración documental externa;
- integración ERP;
- operación offline completa.
