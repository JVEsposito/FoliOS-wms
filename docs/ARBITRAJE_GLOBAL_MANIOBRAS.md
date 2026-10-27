# Arbitraje global de maniobras

## Propósito

El servidor decide qué maniobras pueden entrar juntas en la frontera operacional. La decisión deja de depender del orden en que cada generador creó sus tareas o del momento en que una tablet intentó tomarlas.

La unidad de arbitraje es la maniobra completa, incluidos todos sus objetivos y pasos físicos. Una maniobra multiobjetivo compite usando su prioridad dominante y su beneficio agregado.

## Precedencia

La comparación es determinista y se aplica en este orden:

1. realidad física ya iniciada o pausada por discrepancia;
2. prioridad operacional: `critica`, `urgente`, `alta`, `normal`;
3. objetivo dominante dentro de la misma prioridad: evacuación de emergencia, despacho directo con camión, segregación de retenidos y operación normal;
4. beneficio neto: `beneficio_estimado - costo_movimientos - riesgo_operacional`;
5. antigüedad de la maniobra;
6. identificador estable como último desempate.

Un beneficio alto nunca puede desplazar una prioridad superior. Tampoco se generan movimientos para mejorar solamente la apariencia del plano: el beneficio proviene de objetivos operacionales ya materializados.

## Destino posible (27-10-2026)

Antes de ocupar un cupo o la alternativa, el árbitro comprueba que el paso actual de la
maniobra tenga hoy al menos un destino que el servidor aceptaría y al que se pueda llegar:
una posición activa, libre, sin reserva, en una banda operativa de PT y con todo libre delante
(las bandas se llenan desde el fondo). Para una recepción con carga se usa el mismo acopio que
valida la reserva (cámara de acopio, grupo o sector); para una concentración, la cámara objetivo
y su grupo principal; para un despeje, la cámara de origen y, si no hay espacio, otra cámara.

Si no existe ninguno, la maniobra queda `sin_destino` con el motivo (por ejemplo, *No hay
posiciones libres junto al acopio de CAR-000024 en Cámara de tránsito 07*). No ocupa cupo, no se
publica a las tablets y se rechaza si un cliente anterior intenta tomarla. Las siguientes en
orden de precedencia pasan a ocupar la frontera. Un paso que la regla no sabe evaluar se
considera posible, como antes: la reserva sigue validando el destino elegido.

Origen: el 26-10-2026 las cuatro maniobras ofrecidas eran pallets urgentes de CAR-000024 sin
destino, y 162 labores con destino esperaban detrás de ellas.

La huella del ciclo incluye la versión del plano de cada cámara de PT: un movimiento o un cambio
de banda produce un ciclo nuevo y una maniobra vuelve a la frontera cuando se libera espacio.
La regla se identifica como `arbitraje_global_v7_destino_posible` y se aplica con o sin turno.
`WMS_PLANNER_DESTINO_POSIBLE=false` vuelve al arbitraje anterior (V5 sin turno, V6 con turno).

## Exclusión de recursos

Antes de publicar cada candidata, el árbitro compara:

- pallets involucrados;
- posiciones de destino;
- bandas y niveles protegidos por una maniobra cerrada.

La candidata de menor precedencia queda `excluida_conflicto` y conserva el identificador de la maniobra que ganó cada recurso. Las restricciones únicas de reservas continúan siendo la última defensa transaccional al asumir y ejecutar.

## Frontera corta

Con la configuración predeterminada:

- hasta tres maniobras compatibles quedan `seleccionada`;
- una cuarta puede quedar `alternativa`, visible pero sin reservas y no asumible;
- el resto permanece `fuera_frontera` hasta el siguiente cambio del estado autoritativo.

Las maniobras en ejecución consumen capacidad y prevalecen sobre toda simulación. Una discrepancia pausada conserva sus recursos porque la realidad física ya pudo cambiar.

Los retiros desde REPA quedan `fuera_planificador`: siguen disponibles por la regla propia del buffer y no consumen los tres cupos del planificador global. Sus recursos sí se respetan cuando la maniobra ya comenzó.

Cuando `WMS_PLANNER_ROLLOUT_CAMERAS` limita el despliegue, una maniobra pendiente
que involucra cualquier cámara no dirigida queda `fuera_rollout`: no se publica,
no puede asumirse y no consume capacidad ni alternativa. Un claim que todavía no
cruzó `RETIRAR PALLET` tampoco obliga al árbitro a conservar el cupo. En cambio,
un paso `en_proceso`, una custodia temporal activa o una discrepancia pausada
constituyen realidad física y conservan sus recursos aunque cambie el rollout.

## Auditoría e idempotencia

Cada estado relevante produce un `snapshot_version` SHA-256 y un ciclo persistido en:

- `ciclos_arbitraje_maniobras`;
- `decisiones_arbitraje_maniobras`.

El ciclo registra orden, decisión, puntaje, beneficio neto, motivo, conflictos y
alcance resuelto del rollout. Cada decisión conserva además una explicación JSON
versionada con:

- factor decisivo;
- aporte exacto de prioridad, objetivo dominante y beneficio neto;
- capacidad, ocupantes físicos y cupos disponibles al evaluar;
- restricciones y recursos requeridos;
- nombres operacionales de los recursos en conflicto y de la maniobra que los ocupa;
- snapshot de maniobra, objetivo y pasos utilizado por ese ciclo.

Los identificadores internos permanecen en el documento persistido para permitir
la reproducción determinista. El contrato público del puesto de mando los
filtra y entrega solamente nombres, folios y etiquetas operacionales. Refrescar la
bandeja sin cambios reutiliza el mismo ciclo y la misma explicación; un cambio de
versión, estado de paso, prioridad, objetivo, custodia, recurso o cámaras dirigidas
genera uno nuevo.

## Comparación histórica inmediata

`GET /api/operacion-ahora/planificador/comparacion` contrasta el ciclo confirmado
vigente con su antecedente inmediato dentro de la misma temporada. La consulta es
de solo lectura, no ejecuta el árbitro y no forma parte del polling periódico del
puesto de mando: se solicita únicamente cuando un usuario abre la comparación.

El servicio empareja internamente las decisiones por maniobra, pero su contrato
público no entrega UUID. Para cada diferencia publica nombres operacionales,
objetivo, folio y ruta física, junto con los estados anterior y actual. Distingue:

- maniobras incorporadas al ciclo;
- maniobras retiradas del ciclo;
- maniobras con cambios de decisión, orden, prioridad, puntaje, beneficio neto,
  factor decisivo, objetivo, folio o ruta;
- maniobras que permanecieron sin cambios, solo como conteo agregado.

La identidad histórica se obtiene primero de la explicación persistida, de modo
que una edición posterior de la maniobra no reescribe visualmente lo que observó
el árbitro. Los ciclos anteriores al contrato de explicabilidad mantienen un
fallback compatible con el nombre actual de la maniobra. La respuesta limita el
detalle a cien cambios, informa si fue truncada y conserva el resumen completo.

## Replay determinista

`GET /api/operacion-ahora/planificador/replay?ciclo=actual|anterior` vuelve a
ejecutar en memoria las reglas exactas de la versión histórica y contrasta el
resultado con la decisión persistida. El usuario inicia esta verificación desde
la comparación de ciclos; no forma parte del polling periódico.

El replay es una consulta pura: no ejecuta el árbitro vigente, no crea ciclos,
no reserva recursos, no materializa tareas y no altera maniobras. Para cada
maniobra verifica orden, decisión, pesos de prioridad y objetivo, beneficio neto,
puntaje y factor decisivo. El contrato público informa nombres operacionales,
folio y ruta, pero nunca publica UUID.

Solo se reproduce una versión para la que exista un motor histórico compatible y
un snapshot completo. Se admiten `arbitraje_global_v4_explicabilidad`, `v5_pausa_fisica`,
`v6_funciones_turno` y `v7_destino_posible`, con explicación versión 1. En V7 cada decisión
conserva en `componentes.destino` si se evaluó su destino y con qué resultado; el replay usa
ese dato y no vuelve a leer las cámaras. Los ciclos
anteriores se muestran como `informacion_insuficiente`; el sistema no inventa
datos faltantes ni aplica retrospectivamente las reglas actuales. La respuesta
limita el detalle a cien verificaciones, conserva el resumen completo y prioriza
las diferencias para facilitar la auditoría.

## Contrato con tablet

La bandeja `GET /api/tareas-movimiento?asignacion=disponibles`:

- publica únicamente seleccionadas y la alternativa global, además de labores expresamente fuera del planificador;
- respeta el orden decidido por el servidor;
- expone el resumen del ciclo y la decisión de cada maniobra.

La tablet muestra la alternativa como espera no accionable. Aunque un cliente antiguo intente tomarla, el servidor recalcula el ciclo dentro de la operación y rechaza la toma si no ganó la frontera vigente.

Este incremento no adelanta el siguiente paso del roadmap: la frontera física dinámica continuará resolviendo destinos próximos y recalculando únicamente el sufijo no iniciado.
