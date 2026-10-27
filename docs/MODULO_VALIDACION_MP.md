# Módulo de Validación MP

## Objetivo

Validación MP revisa la materia prima recibida por Romana, confirma las cantidades reales de envases y prepara las unidades que Digitación convertirá en lotes.

```text
Romana
→ recepción REC-*
→ bandeja de Validación MP
→ toma exclusiva
→ conteo real de envases
→ revisión visual de tarjas
→ segregación
→ segmentos pendiente_lote
```

Validación MP es diferente de Validación de pallets/PT:

- Validación MP opera una recepción contractual de materia prima.
- Validación PT crea folios de producto terminado antes de Prefrío.

## Identidad y temporada

La recepción se identifica mediante `REC-AAMM-####`, correlativo asignado por Romana al crear el expediente.

El número `REC-*` no es:

- folio;
- lote;
- pallet;
- proceso térmico.

Validación MP hereda la temporada de la recepción. Aunque la temporada global activa cambie posteriormente, la validación continúa ligada al ciclo original de Romana.

## Bandeja operacional

El rol `validador_mp` dispone de una bandeja de recepciones elegibles.

La PDA permite:

- consultar pendientes;
- buscar o pistolear `REC-*`;
- recuperar cliente, temporada, guía y transporte;
- visualizar los envases declarados;
- tomar la recepción;
- confirmar el resultado.

La aplicación se presenta en orientación vertical.

## Toma exclusiva

Una recepción puede estar tomada por un único validador MP.

La toma debe:

1. comprobar que la recepción pertenece a la temporada permitida;
2. comprobar que sigue elegible;
3. bloquear la recepción dentro de la transacción;
4. rechazar una segunda toma concurrente;
5. identificar usuario y dispositivo.

La exclusividad impide que dos PDA confirmen conteos o segregaciones diferentes para la misma recepción.

## Datos heredados desde Romana

Validación MP no vuelve a solicitar datos contractuales que ya existen. Recupera:

- número de recepción;
- temporada;
- cliente;
- guía de despacho;
- patente y transporte;
- conductor cuando corresponde;
- tipo de servicio;
- envases declarados;
- fecha y hora de ingreso.

Los snapshots contractuales permanecen en la recepción original.

## Conteo real de envases

El validador confirma por separado las cantidades reales de:

- bins;
- totes;
- esponjas.

La diferencia entre declarado y observado:

- se calcula y muestra;
- se conserva como evidencia;
- no bloquea automáticamente la validación;
- alimenta el movimiento real de la cuenta corriente de envases.

No deben duplicarse líneas del mismo tipo dentro de una confirmación incompatible ni utilizarse tipos no declarados por la recepción.

### Bins de planta agregados

Cuando llegan más totes de los que caben en los bins recibidos (por ejemplo 31 totes sobre 1 bin, con 24 por bin), la planta agrega bins propios para procesar la fruta. La PDA sugiere cuántos con la referencia «Totes por bin» de la especie y el validador los confirma o corrige en **Bins de planta agregados**. Con segregación se indican por segmento y deben sumar el total.

La confirmación envía `bins_prestados_planta` y, por segmento, `segmentos.*.bins_prestados_planta` (enteros de 0 a 999). Los bins agregados no cambian lo validado. Se prestan a la cuenta del cliente y se devuelven en el destare. El detalle está en [Préstamo interno de bins de planta](MODULO_ENVASES.md#préstamo-interno-de-bins-de-planta).

## Revisión de tarjas

La tarja se revisa visualmente como control operacional.

En el alcance actual:

- no se captura la tarja como identidad;
- no se genera un lote a partir de su número;
- una observación visual puede conservarse dentro del resultado.

## Tipos de recepción

### Fruta con envases

Permite conteo, revisión y segregación de materia prima.

### Fruta con pesaje acumulativo de envases

Conserva el mismo flujo de fruta, tarjas y segregación. Romana configura el tipo de envase y su tara unitaria, y pesa la cantidad completa en una o más tandas. La recepción es visible y puede ser tomada desde su creación, pero Validación MP no puede confirmarla hasta que Romana complete el total declarado y cierre el pesaje.

### Solo envases

Permite confirmar cantidades reales sin crear segmentos de fruta. Puede corresponder, entre otros, a una compra o arriendo.

## Segregación

La materia prima puede separarse mediante combinaciones permitidas de:

- CSG;
- cuartel;
- variedad.

Cada segmento conserva su relación con la recepción y la validación que lo originó.

El estado actual es:

```text
pendiente_lote
```

Este estado significa que existe una unidad operacional segregada disponible en `/oficina/materia-prima`.

## Límite con Frigorífico

Confirmar Validación MP no crea ni modifica:

- `folios`;
- validaciones PT;
- procesos de Prefrío;
- ubicaciones de Cámaras;
- cargas de producto.

Digitación crea una relación explícita entre segmento y lote. No convierte `REC-*` en folio ni reutiliza el identificador del segmento como pallet.

## Cuenta corriente de envases

Al confirmar, las cantidades reales alimentan el dominio de Envases mediante movimientos auditables. Si se agregaron bins de planta, además se registra su préstamo interno a la cuenta del cliente.

La corrección no reescribe el declarado original de Romana. Se conservan:

- cantidad declarada;
- cantidad real;
- diferencia;
- usuario;
- dispositivo;
- fecha;
- recepción de origen.

## Estados conceptuales

La implementación separa el estado de Romana del estado de Validación MP.

Una recepción puede estar:

- abierta o cerrada en Romana;
- pendiente, tomada o confirmada en Validación MP.

Cerrar Romana no equivale a confirmar Validación MP.

La única dependencia de cierre es el tipo `fruta_pesaje_envases`: Validación MP puede preparar la revisión mientras el pesaje sigue abierto, pero su confirmación exige el estado `cerrado` para no consolidar cantidades antes de conocer el neto definitivo.

## API

Todas las rutas requieren `auth:sanctum` y la capacidad `validar-mp`.

```http
GET  /api/validacion-mp/pendientes
GET  /api/validacion-mp/recepciones/buscar/{numeroRecepcion}
GET  /api/validacion-mp/recepciones/{recepcion}/catalogos
POST /api/validacion-mp/recepciones/{recepcion}/tomar
POST /api/validacion-mp/validaciones/{validacionMp}/confirmar
```

## Rol

### `validador_mp`

Puede:

- consultar recepciones pendientes;
- buscar una recepción;
- tomarla de manera exclusiva;
- registrar conteos;
- preparar segregaciones;
- confirmar la validación.

No obtiene por este rol permisos para Validación PT, Prefrío, Cámaras, Cargas, Materiales o Romana.

Administradores y perfiles de supervisión solo deben acceder cuando sus capacidades lo indiquen expresamente.

## Auditoría

La trazabilidad debe permitir responder:

- qué recepción se validó;
- qué temporada y cliente tenía;
- quién la tomó;
- desde qué dispositivo;
- qué envases estaban declarados;
- qué cantidades reales se confirmaron;
- qué diferencias existieron;
- qué segregaciones se crearon;
- cuándo se confirmó.

Los resultados confirmados no se eliminan para recapturar la operación.

## Continuidad

La oficina de Materia prima consume los segmentos confirmados y permite:

- dividirlos en uno o varios lotes;
- capturar GGN, SdP, cosecha y demás trazabilidad;
- confirmar o corregir el neto calculado desde Romana;
- registrar hidrocooler cuando corresponda;
- dejar el lote pendiente de asignación y enviarlo a una cámara de materia prima.

La ampliación de este flujo con bandeja offline, etiquetas o integración ERP queda fuera del alcance actual.
