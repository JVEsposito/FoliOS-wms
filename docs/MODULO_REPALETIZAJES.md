# Módulo de Repaletizajes

## Propósito

Un repaletizaje consolida cajas de dos o más folios tipo `saldo` en un único folio resultante. El resultado puede ser un pallet completo o un saldo consolidado.

## Identificación del resultado

El operador elige una de dos estrategias:

- **Conservar folio:** uno de los saldos participantes conserva su número y debe aportar todas sus cajas al resultado.
- **Otro folio:** el operador escanea o escribe un número que todavía no exista en FoliOS.

El número solo identifica el resultado. Cliente, especie, marca, condición térmica y demás especificaciones se derivan de los folios que aportaron cajas.

## División de un bulto en dos

En **Dividir bulto (1 → 2)** se distribuyen todas las cajas del origen entre
dos salidas. En **Folio resultado 1** o **Folio resultado 2** puedes escribir
o escanear el número original para conservarlo en ese bulto; el otro número
debe ser nuevo. También se pueden escribir dos folios nuevos.

El folio conservado mantiene su identidad y ubicación, con las cajas y la
composición que se le asignaron. El otro resultado queda sin ubicación hasta
registrar su ubicación física. Con dos folios nuevos, la primera salida
hereda la ubicación del origen. Cada CSG, fecha y componente debe cuadrar
completamente, y cada salida debe contener al menos una caja.

La anulación restaura las cantidades, composición y ubicación originales y
deja inactivo el nuevo folio, siempre que no haya operaciones posteriores.

## Reglas de compatibilidad

Una repa se bloquea sin posibilidad de excepción cuando existe diferencia de:

- cliente;
- especie;
- estado térmico.

Las diferencias de marca, variedad, calibre, envase, categoría, CSG, predio o cuartel no bloquean. El sistema muestra una advertencia y registra el campo como `MIX`, conservando la composición por folio y cantidad aportada.

La marca se puede mezclar cuando el cliente lo pide (MIX de MARCA). Cada línea de la composición conserva la marca
de sus cajas: al dividir el MIX o sacar las cajas de una marca, el folio resultante y el saldo que queda recuperan su
marca real. Un folio que ya era MIX sin marca por componente sigue como MIX.

## Estado térmico

Solo se admiten dos contextos:

- todos los saldos en `pendiente_prefrio`: resultado y residuales permanecen pendientes de prefrío;
- todos los saldos en `prefrio_aprobado`: resultado y residuales quedan disponibles.

No se admiten folios con prefrío activo, retenidos, en reproceso ni una combinación de estados térmicos.

## Cantidades

- Un resultado `pallet` debe alcanzar exactamente la capacidad indicada.
- Un resultado `saldo` debe quedar bajo esa capacidad cuando esta se informa.
- Cada origen aporta entre una caja y su cantidad disponible.
- Los orígenes totalmente consumidos quedan agotados e inactivos.
- Los orígenes parcialmente consumidos permanecen como saldo con su cantidad residual.

## Trazabilidad

Cada operación registra:

- código `REPA-AAAA-NNNNNN`;
- UUID idempotente;
- folio resultante y estrategia aplicada;
- cantidad anterior, aportada y posterior de cada origen;
- especificaciones originales y resultantes;
- campos MIX y composición exacta;
- operador, dispositivo, fecha y observación;
- snapshots para una anulación controlada.

## Anulación

Solo supervisión o administración puede anular. La anulación se bloquea cuando un folio involucrado ya posee cargas, reservas, movimientos o procesos de prefrío posteriores. Cuando procede, restaura los saldos y ubicaciones originales; un folio nuevo resultante queda anulado e inactivo.

## Integración con el planificador

Cuando el planificador guiado para tablet está activo, cada resultado tipo pallet con prefrío aprobado genera un objetivo rolling `recepcion_repaletizaje`, aunque todavía no pertenezca a una carga. La labor conserva el origen físico cuando existe, no preasigna destino y declara que el pallet siempre puede salir del área REPA. Los saldos y los pallets pendientes de prefrío continúan en sus circuitos actuales.

La referencia al repaletizaje hace la generación idempotente. Una anulación previa a la ejecución cancela el objetivo; si su retiro ya tuvo ejecución operacional, la anulación se bloquea.

El buffer operacional considera únicamente pallets cuya labor continúa pendiente o
asumida. Menos de ocho conserva prioridad normal, desde ocho usa alta y al llegar
al máximo práctico de diez usa urgente. La categoría crítica permanece reservada
para restricciones superiores como emergencias, retenciones y camión en andén.

## Tarjador y PDA

- Rol `tarjador`: su perfil predeterminado solo tiene el módulo de oficina `frigorifico.repaletizaje` y el módulo móvil `repaletizaje`. En la PDA entra directo a Repaletizaje.
- Validador, Supervisor de frío y Administrador conservan el acceso desde Validación. La anulación sigue reservada a supervisión y administración.
- Permisos: `registrar-repaletizajes`, `anular-repaletizajes` y `consultar-repaletizajes`. Desde una PDA o tablet, el token debe traer el módulo `repaletizaje` o `validacion`.
- Cada repa exige el **turno** (A o B). La fecha operacional es hoy; solo el turno B puede seleccionar ayer al cruzar la medianoche. El servidor calcula hoy y ayer con la zona horaria operacional, incluso si el reloj del dispositivo difiere.
- La pantalla usa el lector integrado (sin teclado en pantalla), recuerda el turno por equipo y muestra la cámara y posición de cada saldo.
- Al confirmar o anular, las cámaras involucradas suben su versión de plano para que tablets y oficina vean el cambio.

## Registro RRPL-01

Se llena solo con las repas guardadas de la temporada activa, sobre la plantilla oficial `resources/templates/repaletizaje/rrpl-01.xlsx`:

- una hoja por fecha operacional, turno y tarjador, con cuatro bloques por hoja;
- cada bloque es un folio resultante: variedad, etiqueta (marca), embalaje (envase), N° REPA, estado del pallet (completo o S/A) y hasta ocho líneas de origen con folio, fecha de embalaje, calibre, CSG y cajas;
- una repa con más de ocho líneas continúa en el bloque siguiente (`REPA-… (1/2)`), y el total va en el último;
- una división genera un bloque por cada folio resultante;
- una repa anulada conserva su bloque con la marca ANULADA y el motivo: el documento nunca pierde un número REPA;
- la firma del jefe de frigorífico se hace sobre la impresión.

Oficina: Frigorífico → Repaletizajes → **Registro RRPL-01**, por fecha, con descarga por turno y tarjador, del día completo o del formulario en blanco.

API:

- `GET /api/validacion/repaletizajes/registro/rrpl-01/planillas?fecha=AAAA-MM-DD`
- `GET /api/validacion/repaletizajes/registro/rrpl-01?fecha=…&turno=…&user_id=…`
- `GET /api/validacion/repaletizajes/registro/rrpl-01/en-blanco`

## Interfaces

- **Oficina:** `/oficina/validacion/repaletizajes`
- **PDA del tarjador:** módulo Repaletizaje
- **PDA de validación:** `Validación → Repaletizajes`

La confirmación requiere conexión al servidor porque actualiza varios folios en una única transacción.
