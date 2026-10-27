# Concentración rolling de cargas

## Objetivo

El planificador puede transformar una carga publicada y dispersa en un objetivo operacional `concentracion_carga` sin convertir el orden visual de la cámara en un fin por sí mismo.

La regla central es:

> Mover solamente cuando el movimiento aumenta de forma demostrable la concentración física de la carga o despeja el acceso imprescindible para conseguirlo.

## Definición de carga junta

Se reutiliza la métrica existente de `CalculadorConcentracionCarga`.

Una carga cumple el objetivo cuando al menos el 80 % de sus pallets completos vigentes están:

- en andén; o
- dentro del componente físico conectado más grande de una misma cámara y nivel.

Dos posiciones forman parte del mismo componente si:

- están en la misma banda y sus profundidades son consecutivas; o
- están en bandas consecutivas y su profundidad es igual o adyacente.

El planificador y la API usan la misma definición; no existe un segundo concepto de concentración para las tareas rolling.

## Universo del porcentaje

El denominador incluye pallets completos vigentes de la carga en estado:

- `pendiente`;
- `con_incidencia`;
- `en_anden`.

Un pallet pendiente que todavía no tenga ubicación física sigue contando en el total, aunque no pueda convertirse todavía en candidato de movimiento.

Esto evita declarar una carga concentrada mientras aún faltan pallets por incorporarse físicamente desde otros procesos.

## Cámara objetivo

La cámara objetivo se determina en este orden:

1. `carga.camara_objetivo_id`, si fue definida explícitamente;
2. cámara del grupo físico principal ya existente.

Desde el 26-10-2026, si no existe ninguna de las dos señales, las recepciones de la carga
estiman antes del primer pallet una cámara con bandas libres para toda la carga (la preferente
de despacho, luego la que guarda más pallets del mismo cliente y luego por código). El acopio
crece donde cabe lo que falta de la carga; ver *Sector de acopio con capacidad* en
[Labores coordinadas](PLANIFICADOR_LABORES_COORDINADAS.md).

Cuando existe una cámara objetivo explícita pero todavía no hay un grupo dentro de ella, la frontera publica como máximo un pallet semilla. Después de ejecutarlo se recalcula la geometría antes de publicar más trabajo.

## Frontera corta de maniobras

La frontera limita **maniobras independientes ejecutables**, no la profundidad
de razonamiento ni la cantidad de pasos físicos internos:

```text
min(WMS_PLANNER_FRONTIER_MAX, maniobras mínimas necesarias para alcanzar 80 %)
```

Ejemplo:

```text
10 pallets
7 concentrados
objetivo = 8

→ se publica como máximo 1 maniobra de concentración
```

Una maniobra puede contener más de cuatro pasos si necesita retirar blockers,
mover el pallet objetivo y devolver los pallets temporales. Con el valor por
defecto `4`, tres camareros pueden ejecutar hasta tres maniobras incompatibles
entre sí y la cuarta puede permanecer ofrecida sin reservar demasiado futuro.

## Selección de candidatos

Primero se consideran pallets de la propia carga que:

- estén pendientes;
- posean ubicación física;
- estén fuera del grupo principal;
- sean físicamente accesibles.

Si existe al menos un candidato directo, se prefiere sobre cualquier despeje.

Solo cuando no existe un pallet directo accesible se simula la cadena completa
de blockers. Antes de publicar, el planificador debe demostrar que cada pallet
desplazado termina en un destino útil o posee un retorno obligatorio.

## Destino calculado en tablet

Las tareas `concentrar_carga` nacen con cámara objetivo, pero sin posición exacta.

La tablet solo puede proponer una posición que:

- esté activa y libre;
- pertenezca a una banda operativa con uso `transito_pt`;
- pertenezca a la cámara objetivo;
- toque físicamente el componente principal con la misma regla de vecindad usada por la métrica.

La puntuación favorece posiciones que tengan más contactos con el grupo principal.

El servidor vuelve a validar la misma geometría antes de materializar la reserva física. Una propuesta que no aumente el componente es rechazada.

## Maniobras cerradas y blockers

La unidad auditable es:

```text
objetivo → maniobra → pasos físicos → movimientos inmutables
```

Para cada blocker se intenta primero un destino permanente que ayude a otro
objetivo activo. Si no existe, la maniobra contiene explícitamente:

1. extracción temporal;
2. movimiento del pallet objetivo;
3. retorno a la banda en su nueva profundidad lógica.

La extracción temporal crea custodia operacional, no una posición ficticia.
La banda queda protegida durante la secuencia y la maniobra no puede declararse
completa mientras exista una custodia activa. Los pasos futuros permanecen
bloqueados; solo el paso actual puede ser asumido o iniciado.

El costo de la maniobra cuenta pallet objetivo, blockers, destinos útiles y
retornos. El scoring conceptual es `beneficio total - costo físico total -
riesgo operacional`.

## Ejecución scanless

La tablet muestra el folio, origen, destino y `paso X de Y`. El camino normal no
exige escanear folio ni posición porque ambos ya forman parte del estado
autoritativo y de la secuencia validada.

Al pulsar `RETIRAR PALLET`, el paso cruza el punto de no retorno. Si la realidad
no coincide, el camarero usa `NO COINCIDE`; la maniobra se pausa, se conserva lo
ya ejecutado y solo puede recalcularse el sufijo todavía no iniciado.

## Prioridad de despacho directo

La presencia de un camión en andén domina la concentración.

Cuando existe una presencia activa:

- no se publica nuevo trabajo de concentración;
- las maniobras todavía reversibles se cancelan;
- una maniobra que ya modificó la realidad física debe cerrar sus pasos y
  retornos antes de cambiar de objetivo.

Cuando el camión deja el andén, la carga vuelve a evaluarse desde su estado físico real.

## Condición de término

Si el porcentaje llega a 80 %:

- se cancelan maniobras reversibles que hayan dejado de ser necesarias;
- el plan se completa cuando no queda ninguna maniobra física abierta ni
  custodia temporal pendiente.

Si posteriormente la carga sigue operativa y vuelve a quedar bajo el umbral, el mismo objetivo persistente puede reactivarse manteniendo trazabilidad.

## Recálculo reactivo

La concentración se recalcula después de publicar cambios relevantes de carga y después del commit físico de una ubicación.

El recálculo por movimiento se limita a las cargas afectadas por:

- el folio movido;
- la banda/nivel de origen;
- la banda/nivel de destino.

No se recorren todas las cargas de la temporada después de cada movimiento.

## Compatibilidad

El flujo histórico `TareaCarga` permanece disponible durante la transición. `concentracion_carga` no lo elimina ni cambia su contrato.

El nuevo objetivo solo dirige trabajo cuando la configuración corresponde a cálculo rolling en tablet. `off`, `shadow` y configuraciones no dirigidas mantienen sus contratos de rollback/auditoría.
