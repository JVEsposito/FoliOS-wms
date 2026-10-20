# Módulo de Transformación de Materiales

## Estado de esta entrega

Esta entrega funda el dominio de transformación sobre el inventario de Bodega ya
consolidado por Recepción de Materiales. No crea una segunda contabilidad ni
convierte una transformación interna en una recepción ficticia.

El módulo incorpora:

- recetas con versiones históricas;
- un único producto principal `material_pt` por receta;
- componentes de entrada `material_mp` o `insumo`;
- identificación explícita del componente principal;
- órdenes en borrador y planificación;
- creación, planificación, seguimiento y cancelación desde Oficina de Materiales;
- reservas FIFO sobre folios existentes, disponibles y ubicados;
- cancelación con liberación transaccional de reservas;
- inicio operacional desde PDA;
- lotes parciales con un único lote abierto por orden;
- consumos reales contra reservas y saldo de inventario;
- control y justificación de excepciones FIFO;
- generación de folios de salida con el correlativo global del cliente;
- cálculo de merma por lote y genealogía de entradas y salidas;
- cierre de orden con liberación del saldo reservado no consumido;
- etiquetas PDF o `.nlbl` editables en ZebraDesigner 3/NiceLabel desde oficina,
  con Code 128 o QR, y envío ZPL/BPL-Z directo desde PDA;
- reversa compensatoria y supervisada del último lote cerrado.

Quedan para entregas posteriores:

- indicadores de transformación en el panel gerencial.

## Principio de inventario único

Los saldos continúan viviendo en:

```text
folios_materiales.cantidad_actual
folios_materiales.cantidad_reservada
movimientos_inventario_materiales
ubicaciones_actuales
```

Las tablas de transformación documentan receta, orden, lote y genealogía. No
mantienen un inventario paralelo.

## Flujo objetivo

```text
Receta versionada
→ orden de transformación
→ planificación
→ reserva FIFO de materiales
→ inicio
→ lotes parciales
→ consumos reales
→ generación de folios de salida `material_pt`
→ folios visibles y pendientes de ubicación
→ estiba en cámara de Materiales
→ disponibilidad para reservas y despacho
→ cierre con merma y desviaciones
```

## Recetas y versiones

Una receta define un ítem de salida de categoría `material_pt` y uno o más
componentes de entrada de categoría `material_mp` o `insumo`.

Cada versión conserva:

- cantidad base de salida para el cálculo proporcional;
- unidades por folio o pallet de salida;
- unidad de medida de salida;
- cantidades estándar por componente;
- unidad de medida de cada componente;
- componente principal;
- factor de conversión;
- merma estándar (heredada: Oficina ya no la pide y guarda 0, porque la merma
  se cuadra al cerrar la orden);
- tolerancia operacional;
- snapshot histórico.

Debe existir exactamente un componente principal. Una versión utilizada por una
orden no se modifica: todo cambio posterior debe crear una nueva versión.

## Órdenes

Estados previstos:

```text
borrador
→ planificada
→ en_proceso
→ pendiente_cierre
→ cerrada

borrador | planificada
→ cancelada
```

La ejecución implementa todos estos estados. La orden pasa a
`pendiente_cierre` cuando la salida acumulada alcanza la cantidad planificada;
también puede cerrarse antes, siempre que se justifique la desviación.

Cada orden conserva temporada global, cliente global, versión de receta,
cantidad planificada, línea, turno, fecha operacional, snapshot de receta y
eventos auditables. La planificación utiliza exclusivamente el snapshot de la
orden; por ello, una nueva versión puede retirar la versión anterior sin
invalidar órdenes en borrador ya creadas.

Oficina de Materiales muestra los requerimientos proporcionales, la
disponibilidad referencial, las reservas por folio y ubicación, el avance de
producción, los lotes, las salidas y la auditoría. Una orden histórica continúa
visible y puede cancelarse para liberar reservas, pero solo una orden de la
temporada global activa puede planificarse y llegar a la PDA.

La reversa de un lote se rechaza si alguna etiqueta directa asociada permanece
pendiente, fue enviada o terminó con resultado indeterminado. Los trabajos se
bloquean dentro de la misma transacción para impedir que una reversa invalide
folios cuya impresión ya comenzó.

### Tarja de armado

Con `MATERIALES_ARMADO_CODIGO_BODEGA` configurado, cada pallet de salida lleva folio único de
armado (`AR` + código del ítem + bodega + correlativo de 5 dígitos) en vez del correlativo del
cliente, y lote de bodega (bodega + guía + fecha + turno + máquina). La orden pide máquina y
turno 01/02, y guía, proveedor y lote se fijan al planificar. Detalle en
[`docs/diseno/folios-v2/tarja-armado.md`](diseno/folios-v2/tarja-armado.md).

## Reservas FIFO

Al planificar una orden, el sistema calcula cada requerimiento en proporción a la
cantidad base de la versión:

```text
cantidad_requerida =
    cantidad_estandar_componente
    × cantidad_planificada_salida
    ÷ cantidad_base_salida
```

Solo pueden reservarse folios que:

- correspondan al ítem requerido;
- estén activos;
- estén ubicados en una cámara de Materiales;
- tengan estado operacional `disponible`;
- no posean motivo de bloqueo;
- tengan saldo no reservado.

La reserva utiliza FIFO por fecha de ingreso y número de folio. La cantidad se
refleja en `folios_materiales.cantidad_reservada`, por lo que también queda
indisponible para despachos concurrentes.

Si falta saldo para cualquier componente, toda la planificación se revierte.

## Cancelación

Una orden solo puede cancelarse sin compensaciones mientras permanezca en
`borrador` o `planificada`.

La cancelación de una orden planificada:

- bloquea la orden y las reservas;
- devuelve las cantidades reservadas a cada folio;
- cambia las reservas a `liberada`;
- registra usuario, operación, motivo y evento;
- es idempotente por UUID de comando cuando coinciden orden, usuario y motivo;
- rechaza como conflicto cualquier reutilización alterada del mismo UUID.

Una orden con consumos futuros no podrá cancelarse directamente: deberá
revertirse mediante movimientos compensatorios.

## Reversa supervisada de lotes

Administrador y supervisor de Materiales pueden revertir el último lote cerrado
mientras la orden permanezca `en_proceso` o `pendiente_cierre`. La operación
exige un motivo, versión conocida y UUID idempotente.

La reversa se rechaza cuando:

- existe otro lote abierto;
- se intenta revertir un lote que no es el más reciente de la orden;
- la orden ya fue cerrada;
- alguna etiqueta del lote ya fue enviada a impresión directa;
- el folio de salida fue ubicado, reservado o modificado;
- un folio de entrada posee movimientos de inventario posteriores.

La historia no se elimina. El lote pasa a `anulado`, el folio de salida queda
anulado con saldo cero y se registran movimientos `reversa_transformacion`. Los
saldos y reservas consumidos vuelven a sus folios de entrada.

Cuando un folio de entrada había quedado completamente agotado, la reversa lo
reactiva como `pendiente_ubicacion`. Conserva la cantidad reservada para la
orden, pero debe ser ubicado nuevamente desde Cámaras antes de poder cerrar un
nuevo lote. Un folio parcialmente consumido conserva su ubicación vigente.

## Ejecución PDA, lotes y genealogía

La PDA lista las órdenes planificadas y en ejecución. El operador puede iniciar
una orden, abrir un lote parcial, pistolear los folios reservados, registrar
cantidades reales y cerrar el lote. Solo puede existir un lote abierto por
orden.

Cada lote vincula:

```text
folios de entrada
→ cantidades consumidas
→ lote de transformación
→ un folio de salida con prefijo por cliente
```

Cuando la versión define `unidades_por_folio_salida`, el siguiente lote debe
corresponder exactamente a esa capacidad o al remanente final de la orden. Por
ejemplo, 12.000 unidades con 120 por folio producen 100 lotes y, al cerrar cada
uno, 100 folios. Las versiones históricas sin esta regla conservan sus lotes
parciales libres.

### Folios de salida reservados

Si la versión define `unidades_por_folio_salida`, al planificar la orden se
reservan del correlativo los números de todos sus folios teóricos
(`folios_salida_reservados_transformacion`): uno por cada folio completo y el
último con el remanente. Por ejemplo, 80 unidades con 30 por folio reservan
tres números con 30, 30 y 20.

- Oficina puede imprimir esas etiquetas antes de producir, con la cantidad
  teórica (`reserva_ids` en `POST …/ordenes/{orden}/etiquetas`). El número aún
  no existe en inventario.
- Al cerrar un lote, el folio de salida se crea con el número pistoleado
  (`folio_salida`), que debe estar reservado para la orden y sin usar. Si no
  viene, toma el primero en secuencia con la misma cantidad teórica del lote,
  o el primero disponible. Si ya no quedan reservados, usa el correlativo.
- Si la etiqueta anticipada ya se imprimió y la cantidad real difiere de la
  teórica, el folio queda marcado para reimpresión. Reimprimir la etiqueta del
  folio producido no exige motivo en ese caso: se registra «La cantidad real
  difiere de la cantidad teórica impresa».
- Revertir un lote anula su número reservado y reserva uno nuevo con la misma
  cantidad teórica. Cerrar o cancelar la orden anula los números no usados.
  Los números anulados no se reutilizan, porque su etiqueta puede estar
  impresa.
- Las órdenes planificadas antes de este cambio, o sin unidades por folio,
  conservan el flujo anterior: el número se asigna al cerrar el lote.

El origen de los materiales se remonta a Recepción de Materiales y sus guías de
proveedor. `REC-*` pertenece a Romana y no forma parte de esta genealogía.

Los folios producidos utilizan el mismo correlativo global por cliente que
Recepción de Materiales:

```text
F + código de cliente de 2 letras + correlativo de 7 dígitos
```

Nacen como existencia visible, `pendiente_ubicacion` y no reservable. La
primera estiba los promoverá a `disponible`, salvo que estén bloqueados.

El cierre de lote es una sola transacción:

- valida que todos los folios pertenezcan a reservas activas de la orden;
- exige consumo para cada componente de la receta;
- descuenta `cantidad_actual` y `cantidad_reservada`;
- retira la ubicación y desactiva el folio cuando su saldo llega a cero;
- registra consumo, movimiento de inventario, usuario y dispositivo;
- genera el folio de material de salida y su movimiento positivo;
- calcula merma y cierra el lote;
- revierte todo si falla cualquiera de estos pasos.

Si se selecciona un folio posterior mientras un folio FIFO anterior conserva
saldo, el motivo de excepción es obligatorio.

## Modo cuadratura al cierre

Una orden con folios de salida reservados se inicia desde la tablet en modo
`cuadratura_cierre` (`modo_operacion`). Las órdenes ya iniciadas, o iniciadas
por una tablet que no envía el modo, siguen por lote.

1. **Retiro al iniciar.** El camarero pistolea cada folio reservado al llevarlo
   a la línea (`folios_retirados` en `POST …/ordenes/{orden}/iniciar`). Todo lo
   reservado sale del inventario con un movimiento `consumo_transformacion`
   (`sentido: retiro_linea`). Un folio que queda vacío se retira de su
   posición.
2. **Confirmación de folios.** Al completar un pallet, el camarero pistolea su
   etiqueta reservada y confirma la cantidad real
   (`POST …/ordenes/{orden}/folios-salida/confirmar`). Se crea el lote ya
   cerrado con su folio de salida, sin consumos. Abrir y cerrar lotes queda
   bloqueado en este modo.
3. **Cuadratura al cerrar.** Por cada componente se declaran la merma y lo sin
   armar (`cuadratura` y `folios_creados` en `POST …/ordenes/{orden}/cerrar`):

   ```text
   retirado = utilizado + merma + sin armar
   teórico  = cantidad estándar × salida real / cantidad base
   diferencia = utilizado − teórico
   ```

   Lo sin armar vuelve a sus folios de origen, empezando por el último retirado,
   con un movimiento `devolucion_transformacion`. Un folio que había quedado
   vacío vuelve como `pendiente_ubicacion` para guardarlo desde Cámaras. Si la
   diferencia supera la tolerancia del componente, el motivo es obligatorio. La
   cuadratura queda en `cuadraturas_transformacion_materiales`.
4. **Cancelar** una orden en este modo devuelve todo lo retirado a sus folios.
   Revertir un folio confirmado anula su salida y reserva un número nuevo.

## Merma

La merma global se calculará únicamente sobre el componente principal, evitando
sumar unidades incompatibles.

```text
salida_teorica =
    consumo_real_componente_principal × factor_conversion

merma_estandar =
    salida_teorica × porcentaje_merma_estandar

salida_esperada =
    salida_teorica − merma_estandar

merma_real =
    salida_teorica − salida_real

desviacion_merma =
    merma_real − merma_estandar
```

Los componentes auxiliares tendrán desviación de consumo individual:

```text
consumo_real_componente − consumo_estandar_componente
```

Una merma real negativa se conserva como dato operacional; no se rechaza por sí
misma.

## Permisos

- administrador y supervisor de Materiales: crear recetas, crear y planificar
  órdenes, cancelar antes del consumo;
- administrador, supervisor y camarero de Materiales: iniciar órdenes, abrir y
  cerrar lotes y cerrar la orden desde PDA;
- solo administrador y supervisor de Materiales: revertir el último lote
  cerrado con motivo obligatorio;
- despachador y consulta: lectura según su capacidad de consulta vigente;
- el login publica capacidades explícitas para consultar transformaciones,
  gestionarlas, operarlas y administrar recetas.
