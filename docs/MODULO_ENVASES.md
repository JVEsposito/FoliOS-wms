# Módulo de Envases

## Objetivo

El dominio de Envases controla existencia y cuenta corriente de bins, totes y esponjas asociados a clientes, propiedad y temporada. Sus movimientos se originan principalmente en Romana, Validación MP y guías internas de despacho.

```text
recepción / compra / arriendo
→ movimiento de existencia y cuenta corriente
→ revisión
→ disponibilidad
→ guía interna GDE-* en borrador
→ reserva FIFO de existencia
→ confirmación o cancelación
→ eventual anulación compensatoria
```

Envases es un dominio independiente de los folios de Frigorífico y del inventario de Materiales.

## Interfaces

La cuenta corriente permite registrar **aperturas y ajustes con signo**, con permiso de administrador y acceso al módulo de cuenta de envases. Un saldo positivo significa que la planta debe envases al cliente; uno negativo significa que el cliente debe envases a la planta. No se limita el saldo a cero.

Desde «Saldos → Registrar apertura o ajuste» se seleccionan cliente, tipo de envase, propiedad, cantidad entera con signo, fecha efectiva, referencia y motivo. La apertura se admite una vez por cliente, temporada, tipo y propiedad y debe preceder al primer movimiento de esa cuenta. Si una apertura es errónea, se revierte y se registra un ajuste: la apertura original permanece en el historial.

Estos asientos siempre tienen `signo_existencia = 0`: modifican obligaciones de devolución y no crean stock ni disponibilidad para despachar. Las compras, recepciones y salidas físicas continúan con sus controles operativos. Una obligación de envases propios tampoco equivale a una compra o ingreso físico.

Los ajustes se registran en la temporada activa, con fecha efectiva transcurrida dentro de su vigencia. Conservan usuario, hora de registro, motivo y referencia. El UUID de operación hace idempotentes los reintentos y rechaza reutilizaciones con datos distintos. «Revertir asiento» crea una única contrapartida con fecha igual o posterior al original; nunca edita o borra el movimiento. Las temporadas históricas siguen siendo de consulta.

El kardex y los estados de cuenta incluyen estos asientos con signo. Recibidos/entregados representan efectos de cuenta y los asientos identificados como apertura, ajuste o reversa no acreditan entrega física. La fuente financiera `envases_movimiento` permite seleccionar el tipo de movimiento; para arriendos por días se puede acordar cantidad diaria y término de período en Finanzas. La obligación de cuenta no se interpreta automáticamente como existencia arrendada.

```text
/oficina/envases/cuenta-corriente
/oficina/envases/despachos
```

La primera oficina consulta movimientos confirmados y reservas. La segunda crea, edita, cancela, confirma y anula guías internas de salida, además de generar sus respaldos PDF.

## Tipos de envase

El alcance actual trata como líneas independientes:

- bins;
- totes;
- esponjas.

No se agregan cantidades de tipos diferentes como una única unidad.

## Propiedad

Cada movimiento o línea identifica su régimen de propiedad:

### Propia

El envase pertenece a la empresa operadora.

### Del cliente

El envase pertenece al cliente relacionado con la recepción o despacho.

### Arrendada

El envase pertenece a un tercero o arrendador. Su cuenta debe conservarse aunque el envase se despache hacia otro cliente.

La propiedad determina qué trazabilidad de origen es obligatoria y qué cuenta se afecta.

## Cuenta corriente

La cuenta corriente se explica mediante movimientos firmados.

Cada movimiento conserva, según corresponda:

- temporada;
- cliente;
- tipo de envase;
- cantidad con signo;
- propiedad;
- origen o documento relacionado;
- fecha y hora;
- usuario;
- estado de revisión;
- observación.

Un saldo nunca se corrige eliminando un movimiento anterior. Las anulaciones y correcciones se realizan mediante nuevos asientos compensatorios.

Cada movimiento tiene dos efectos:

- **En la cuenta del cliente** (`signo_cuenta`): `+1` deja envases del cliente a cargo de la planta y `-1` los entrega al cliente. La compra de envases propios usa `0`, porque no le debe nada a ningún cliente.
- **En la existencia física** (`signo_existencia`): `+1` entra a la planta y `-1` sale.

Por eso los envases propios no aparecen en los saldos de clientes. Se ven en **Existencia** y en lo entregado a cada cliente.

### Vistas de la oficina

- **Saldos:** agrupados por cliente y tipo de envase. No muestran filas en 0 de la compra de envases propios. Si al cliente se le entregaron envases propios en la temporada, la línea lo indica.
- **Existencia:** envases en planta por tipo y propiedad (propios, arrendados y de clientes), reservados en guías borrador y disponibles. Para los propios muestra lo ingresado, lo entregado a clientes y lo que queda en planta, y lista los propios entregados a cada cliente. Es de toda la planta en la temporada seleccionada; no depende del cliente ni de las fechas.
- **Movimientos:** cada fila muestra el tipo de movimiento, el efecto en la cuenta (`—` si no la mueve) y el efecto en la existencia. Se ven los 300 más recientes.

Las recepciones no distinguen si vuelven envases propios o del cliente. Por eso de los propios se informa lo entregado (despachado, neto de anulaciones) y no lo que el cliente tiene hoy.

Las fechas **desde** y **hasta** de los filtros son días de planta (`app.operational_timezone`). Un movimiento a las 23:30 del día 7 cuenta en el 7, aunque en UTC ya sea el 8.

### Exportaciones y reportes a clientes

- **Excel de movimientos** (botón `↓ Excel` en Movimientos): trae todos los movimientos de los filtros aplicados, sin el límite de 300. Incluye documento, tipo de movimiento, propiedad, efecto en cuenta, **saldo de cuenta acumulado**, efecto en existencia, revisión y correcciones. El saldo acumulado es el de la cuenta del cliente en la temporada, aunque el filtro muestre un período: la primera fila de un período parte del saldo que traía.
- **Estado de cuenta** (acción de cada cliente en Saldos), en PDF o Excel, para enviarlo al cliente. Toma la temporada y las fechas aplicadas. El resumen por tipo de envase muestra saldo inicial, recibidos, entregados y saldo final, y separa los entregados en propios de la planta, arrendados y del cliente. El detalle trae cada movimiento con su saldo. Se cumple saldo inicial + recibidos − entregados = saldo final. El documento es informativo, no tributario.

El estado de cuenta es la vista del cliente; la trazabilidad interna completa está en el Excel de movimientos:

- **Préstamo interno de bins de planta.** No aparece: el préstamo y su devolución quedan en el Excel de movimientos (ver más abajo).
- **Anulaciones y correcciones.** Una guía anulada no aparece: ni el despacho ni su anulación. Una corrección (de cantidad, de propiedad o de la devolución en recepción) se aplica sobre el movimiento que corrige y no sale como línea propia. Lo que queda en 0 desaparece. Una anulación histórica sin referencia al despacho se informa como descuento de lo entregado (salida con signo negativo).
- **Una línea por guía.** Las líneas de un mismo documento, envase y propiedad se suman (una guía con varios orígenes es una sola entrega).
- **Número de guía.** En los despachos se informa el N° de la guía emitida en el sistema de facturación. Si una guía antigua no lo tiene registrado, se muestra el GDE-… interno.
- Como las anulaciones se aplican sobre el movimiento original, un estado de cuenta de un período ya enviado puede cambiar si después se anula una guía de ese período.

El envío por correo no está implementado: requiere configurar un servidor de correo y registrar el correo de cada cliente. Por ahora el estado de cuenta se descarga y se envía por fuera.

## Orígenes de movimientos

Pueden existir movimientos asociados a:

- recepción de fruta con envases;
- recepción exclusiva de envases;
- compra;
- arriendo;
- confirmación real de Validación MP;
- guía de despacho;
- anulación de guía;
- préstamo interno de bins de planta y su devolución;
- ajustes expresamente autorizados.

Romana conserva la cantidad declarada. Validación MP confirma la cantidad real y deja visible la diferencia.

## Préstamo interno de bins de planta

Un bin lleva normalmente hasta 24 totes de cereza. A veces el campo manda más (28, 30 o 31) apilados sobre los mismos bins para no enviar otro. La planta necesita un bin más para procesar esa fruta, así que agrega uno propio y se lo presta a la cuenta del cliente.

**En la PDA (Validación MP).** Al contar, la PDA sugiere los bins que faltan con la referencia de la especie:

```text
bins de planta sugeridos = máx(0, ⌈totes / totes por bin⌉ − bins recibidos)
```

Con 1 bin y 31 totes, a 24 por bin, sugiere 1. El validador confirma, cambia o deja 0. Es solo una sugerencia: no bloquea nada. Si la recepción se segrega, cada segmento indica sus bins de planta y el total es la suma. El campo aparece cuando la recepción trae totes, y no se admite en recepciones solo de envases.

La referencia «Totes por bin» se configura por especie en **Administración → Reparto del neto por especie**, junto a los pesos de referencia. Cereza parte con 24. Sin referencia no hay sugerencia, pero el validador puede indicar los bins igual.

**En la cuenta corriente.** Se registran dos asientos con referencia a la recepción:

| Movimiento | Envase y propiedad | Cuenta | Existencia | Cuándo |
|---|---|---:|---:|---|
| Préstamo interno de bins de planta | Bins, propia | −N | 0 | Al confirmar Validación MP |
| Devolución interna de bins de planta | Bins, propia | +N | 0 | En el destare, antes de la salida de envases; lleva el N° de la guía de salida |

El efecto neto es 0 y la existencia de la planta no cambia, porque esos bins nunca salen. El camión se lleva sus propios envases (por ejemplo 1 bin y 31 totes) como cualquier destare:

- **Mismos envases:** la cuenta del cliente queda en 0.
- **Se lleva 2 bins:** la salida descuenta 2 y la cuenta queda en −1. El cliente debe 1 bin.
- **Sale vacío:** la planta le sigue debiendo 1 bin y 31 totes.

En el pesaje acumulativo, Romana cierra antes de Validación MP, así que el préstamo y su devolución se registran juntos al validar.

**Dónde se ve.**

- Kardex, Saldos y Excel de movimientos: muestran ambos asientos. Mientras el camión sigue en planta, Saldos descuenta el bin prestado.
- Estado de cuenta: no lo muestra, porque es trazabilidad interna.
- Finanzas: la fuente `envases_movimiento` permite filtrar el tipo «Préstamo interno de bins de planta» si se decide cobrar ese servicio.
- Romana: indica en el expediente y en el destare los bins prestados y si ya se devolvieron.
- Lote: suma esos bins a sus envases primarios para hidrocooler, cámara y Fruta a proceso. El kilaje y el reparto del neto siguen saliendo de los envases recibidos.

## Revisión

Los movimientos pueden ser revisados u observados por perfiles autorizados.

La revisión:

- no elimina el movimiento;
- identifica al revisor;
- conserva fecha y observación;
- permite distinguir un asiento verificado de uno pendiente o cuestionado.

## Guía interna de despacho

La salida se documenta mediante:

```text
GDE-AAMM-NNNN
```

La guía registra:

- temporada;
- cliente destino;
- fecha y hora de salida;
- líneas de bins, totes y esponjas;
- cantidad;
- propiedad;
- movimiento o saldo de origen cuando corresponde;
- observación;
- usuarios responsables.

La guía es un documento operacional interno. No debe presentarse como factura, guía tributaria o DTE legal.

### Guía de despacho emitida

La guía de despacho tributaria se emite en el sistema de facturación, fuera de FoliOS. El GDE-… sigue siendo la referencia interna de la reserva y la trazabilidad; el número que ve el cliente es el de la guía emitida.

- **Al confirmar la salida** FoliOS pide el N° de la guía emitida (obligatorio) y permite subir su archivo (PDF, JPG, PNG o WebP, hasta 10 MB). El archivo también se puede subir después.
- **Formato.** Se quitan espacios, puntos de miles y ceros a la izquierda: «0012.345» queda como 12345. Se aceptan letras y guion, hasta 20 caracteres.
- **Único.** Un mismo número no puede quedar en dos guías de FoliOS.
- **Guías confirmadas antes de este cambio** muestran «Falta registrar el N° de la guía emitida» y la acción **Registrar N° de guía**. Corregir un número ya registrado exige motivo. Cada registro y corrección queda en los eventos de la guía.
- **Archivo.** Reemplazar el archivo no borra el anterior: queda en el historial de la guía con su huella SHA-256. Se descarga desde la guía (Despacho Envases) y desde el movimiento (Cuenta Envases, «↓ Guía emitida»).
- El PDF interno muestra el N° de la guía emitida.
- Los archivos se guardan en el disco `local`, en `envases/guias-despacho/{guia}`. El reinicio operacional de la temporada los borra junto con las guías. El archivo de temporada no los incluye; se respaldan con el resto de `storage/app/private` del servidor.

## Estados de la guía

```text
borrador
confirmada
cancelada
anulada
```

### Borrador

- permite preparar, revisar y editar las líneas;
- asigna orígenes FIFO y reserva disponibilidad;
- no modifica todavía la existencia física ni la cuenta corriente;
- puede cancelarse sin generar movimientos.

### Confirmada

- descuenta la existencia física;
- genera el movimiento negativo de cuenta corriente;
- queda inmutable;
- conserva la hora exacta de salida.

### Cancelada

- libera la reserva del borrador;
- conserva el motivo, usuario y fecha;
- no genera movimientos de existencia ni cuenta corriente.

### Anulada

- conserva la guía confirmada y su historia;
- genera movimientos compensatorios;
- no borra ni edita los asientos originales.

## Reglas de stock

1. No se confirma una cantidad superior a la existencia compatible.
2. Las líneas duplicadas incompatibles se rechazan.
3. El consumo conjunto de varias líneas no puede exceder el saldo.
4. La propiedad y el movimiento de origen deben coincidir con la línea.
5. Una guía histórica no se confirma ni anula desde otra temporada activa.
6. Una anulación no puede duplicar la devolución del mismo movimiento.

## Idempotencia

La creación utiliza UUID y hash del payload. Las transiciones posteriores se serializan mediante bloqueo transaccional y estado terminal.

- mismo UUID y mismo contenido al crear: resultado existente;
- mismo UUID y contenido diferente: conflicto;
- doble confirmación: no duplica el descuento;
- doble cancelación: no modifica nuevamente la reserva;
- doble anulación: no duplica la compensación.

La oficina incorpora un fallback UUID v4 basado en `crypto.getRandomValues()` para navegadores de red local que no exponen `crypto.randomUUID()`.

## Temporada y cliente

- Las guías y movimientos conservan su temporada.
- La operación vigente se filtra por la temporada global activa.
- El historial de otra temporada permanece consultable, pero no se modifica desde el ciclo actual.
- Los clientes provienen del maestro global administrado desde Accesos.
- Los documentos guardan la referencia y snapshots necesarios para conservar la historia.

## API

### Cuenta corriente

```http
GET  /api/envases/cuenta-corriente/catalogos
GET  /api/envases/cuenta-corriente/movimientos
GET  /api/envases/cuenta-corriente/movimientos/excel
GET  /api/envases/cuenta-corriente/estado-cuenta?cliente_id=…&formato=pdf|xlsx
POST /api/envases/cuenta-corriente/movimientos/{movimiento}/revisar
POST /api/envases/cuenta-corriente/movimientos/{movimiento}/corregir-propiedad
POST /api/envases/cuenta-corriente/movimientos/{movimiento}/corregir-cantidad
```

### Guías de despacho

```http
GET  /api/envases/guias-despacho/catalogos
GET  /api/envases/guias-despacho
GET  /api/envases/guias-despacho/{guia}
GET  /api/envases/guias-despacho/{guia}/documento
GET  /api/envases/guias-despacho/{guia}/comprobante-anulacion
GET  /api/envases/guias-despacho/{guia}/archivo-guia
POST /api/envases/guias-despacho
PUT  /api/envases/guias-despacho/{guia}
POST /api/envases/guias-despacho/{guia}/confirmar        (numero_guia_despacho obligatorio)
POST /api/envases/guias-despacho/{guia}/cancelar
PUT  /api/envases/guias-despacho/{guia}/numero-guia      (numero_guia_despacho, motivo al corregir)
POST /api/envases/guias-despacho/{guia}/archivo-guia     (multipart: archivo)
POST /api/envases/guias-despacho/{guia}/anular
```

Todas las rutas requieren `auth:sanctum` y el Gate específico de consulta, revisión o gestión.

### Corrección de cantidad después de Validación MP

Desde **Cuenta Envases → Movimientos**, solo un administrador puede reducir la cantidad de un ingreso `solo_envases` validado de la temporada activa. Se exige `cantidad_correcta` (entero positivo menor que la original) y `motivo` (10 a 2000 caracteres). La corrección se rechaza si existen guías, reservas, otros movimientos vinculados, correcciones anteriores o diferencias entre recepción, validación e ingreso. Los despachos propios sin origen también requieren conciliación manual.

La operación conserva el movimiento original y agrega un asiento negativo vinculado con motivo, usuario, fecha, cantidad previa y cantidad corregida. Actualiza el detalle de recepción, el saldo y la existencia física. Las recepciones `solo_envases` no crean segmentos de lotes. La acción admite una sola corrección por recepción y no elimina movimientos históricos.

La versión FoliOS mostrada bajo el lema en la navegación de Oficina se obtiene de `mobile/app.json` al crear la configuración de Laravel; tras desplegar una versión nueva se limpia la caché de configuración.

### Corrección de propiedad después de Validación MP

En **Cuenta Envases → Movimientos**, el administrador puede abrir **Corregir propiedad** en un ingreso de `solo_envases` validado durante la temporada activa. Indica `concepto_envases` (`arriendo` o `compra`) y un `motivo` de 10 a 2000 caracteres. La operación modifica el concepto de la recepción y crea, por cada tipo de envase validado, un asiento que deja en cero el ingreso original y un ingreso con la propiedad correcta. La cantidad física total no cambia. El ingreso original y la trazabilidad (motivo, usuario, fecha e identificador de origen) permanecen en el kardex.

Se rechaza la corrección si alguna línea de esa recepción figura en una guía, incluso si la guía se canceló o anuló; también se bloquea ante salidas propias históricas sin origen trazable. No se puede corregir dos veces la misma recepción. Si la recepción contiene varios tipos de envase, se corrigen todos juntos, porque Romana guarda un único concepto de propiedad para toda ella. La corrección no requiere una migración nueva ni reconstruir los movimientos anteriores. Tras desplegarla, cierra sesión y vuelve a entrar para actualizar las capacidades de la cuenta administrativa.

## Auditoría

El sistema debe poder reconstruir:

- saldo anterior;
- movimiento aplicado;
- saldo resultante;
- documento de origen;
- cliente y temporada;
- propiedad del envase;
- actor y fecha;
- revisión;
- compensaciones posteriores.

Los movimientos, eventos y guías no se eliminan físicamente. Las guías confirmadas antes del versionado documental se reconstruyen una vez, se marcan expresamente como históricas y conservan desde entonces su snapshot y hash.

## Límites actuales

- Las guías no son DTE legales.
- No existe integración automática con contabilidad o ERP.
- No existe telemetría automática de conteo.
- Las oficinas dependen de conexión con el servidor.
- Los catálogos de tipos de envase permanecen acotados al alcance implementado.

## Pendientes

- integración documental o tributaria cuando corresponda;
- conciliaciones y cierres periódicos;
- envío del estado de cuenta por correo (requiere servidor de correo y correo por cliente);
- reportes históricos avanzados;
- recuperación auditada de diferencias operacionales;
- integración ERP;
- eventual operación móvil u offline.
