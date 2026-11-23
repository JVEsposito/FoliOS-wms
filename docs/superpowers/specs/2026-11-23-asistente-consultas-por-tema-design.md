# Asistente de Oficina: consultas por tema, con vistas

Fecha: 23-11-2026. Estado: diseño para aprobar. Parte de #740 (cobertura de Haiku), que debe estar en main antes de construir.

## Intención

Que un gerente o supervisor pueda preguntarle al asistente **todo lo que FoliOS sabe, en vez de navegar**. Las 19 consultas de hoy no alcanzan. Lo más pedido son las cuentas de envases, los lotes y los despachos.

No basta con más listados. Las preguntas reales son de tres tipos:

- **Listar o contar:** «¿qué lotes SAG siguen retenidos?».
- **Rastrear:** «¿en cuántos repaletizajes se diluyó el saldo 1234567?».
- **Cuadrar y ordenar:** «¿hay descuadre entre entradas y salidas de envases?», «¿qué folios tienen mayor antigüedad?».

## Objetivos

- Cubrir envases, lotes de materia prima y SAG, despachos y cargas, y folios en cámara. Luego materiales, prefrío y los indicadores del panel gerencial.
- **Mismas cifras que las pantallas.** Cada vista usa el servicio que ya alimenta la oficina correspondiente; no recalcula por su cuenta.
- **Cero respuestas falsas**, con reglas y con Haiku. El banco crece con cada tanda.
- **Que baje la proporción de «Sin consulta»** en el registro del piloto. La compuerta (`asistente:compuerta`) la mide.

## No objetivos

- Comparar periodos («esta semana contra la pasada»), pronosticar, filtrar con umbrales («más de 3 días»). Quedan para después.
- Escribir o actuar en FoliOS. El asistente sigue siendo solo de lectura.
- RRHH y Finanzas. Siguen sin salir de la planta, por el filtro sensible.

## Anti-objetivos

Cualquiera de estos sería un fracaso, aunque la consulta funcione:

- Una cifra que no coincide con la pantalla de la oficina de origen.
- Una vista que muestra lo que el usuario no puede ver en su oficina.
- Que a Haiku salgan datos de la planta, más allá de la pregunta con fichas.
- Un catálogo tan parecido entre sí que las reglas o Haiku elijan mal.

## Restricciones

- Toda regla de negocio y autorización vive en Laravel. Cada vista exige el mismo permiso que su pantalla de origen.
- La API de Anthropic admite hasta 20 herramientas estrictas por pedido. El asistente sigue con tres: `consultar`, `pedir_aclaracion` y `sin_consulta`.
- El catálogo va en la descripción de `consultar` y queda en caché. Un catálogo más largo cuesta poco, pero confunde más si las vistas se parecen.
- La prueba de CI del banco exige cero falsas de las reglas.
- El modo mixto sigue activo solo si `asistente:evaluar --modo=haiku` da cero falsas después de cada despliegue.

## Enfoques considerados

1. **Una consulta por pregunta, como hoy.** Precisa, pero para cubrir «todo» harían falta 70 a 80 consultas. Muchas se parecerían, y eso es lo que produce respuestas falsas.
2. **Consultas por tema, cada una con vistas** (elegido). Unos 20 temas que se distinguen bien entre sí, cada uno con sus vistas. Por ejemplo, «Cuenta de envases» con las vistas saldos, por productor, estado de cuenta, movimientos y descuadres. Las reglas y Haiku eligen tema y vista, y FoliOS valida las dos cosas.
3. **Haiku consulta la base de datos directamente.** Descartado: se saltaría los permisos y las reglas de cálculo (jornadas, anulaciones, propiedad de envases, retenciones con destinos), y habría que mandarle los datos.

El usuario eligió el enfoque 2 el 23-11-2026.

## Diseño

### Tema y vista

- **Tema.** Una consulta (`ConsultaAsistente`) pasa a ser un tema y declara sus vistas con `vistas(): list<VistaConsulta>`.
- **Vista.** Cada `VistaConsulta` trae:
  - `clave`, `titulo` y `descripcion` (una línea, con lo que **no** responde si se presta a confusión);
  - `palabras`, ya normalizadas, que distinguen la vista dentro del tema;
  - `filtros`, que la vista aplica de verdad;
  - `orden`: null, o el criterio de una vista ordenada (ver abajo);
  - `admiteRecomendacion`;
  - un permiso propio, si exige más que el tema.
- **Las 19 consultas actuales** quedan como temas de una sola vista, `general`, sin cambiar su clave. `ConsultaBase` arma esa vista con el título, la descripción, las palabras y los filtros de la consulta, así que no hay que tocarlas una por una. Se agrupan solo cuando una tanda crea un tema que se les superpone:
  - `despachos-envases-pendientes` pasa a ser la vista `borrador` de `guias-envases`, en la tanda 1.
  - `cajas-pt`, `formatos-pt` y `saldos-pt` pasan a ser vistas de `folios-camara`, en la tanda 4.
  - El banco se actualiza en el mismo PR.
- **`ParametrosConsulta` suma `vista`.** Va en `toArray()` y `desdeArreglo()`, y `POST /api/asistente/consultas/{clave}` acepta `vista`. Si falta, se usa la vista por defecto del tema.
- **Vista por defecto.** Un tema declara una vista por defecto solo si es la lectura obvia. Por ejemplo, «cuenta de envases de Valle Alto» va a `saldos`. Si no la hay, el asistente ofrece las vistas como sugerencias.
- **Filtros.** `filtros()` del tema es la unión de los de sus vistas. La validación usa siempre los de la vista elegida.

### Cómo eligen las reglas

1. **Tema.** El puntaje del tema se calcula como hoy, con sus palabras clave más las de todas sus vistas. Siguen el umbral 0,6, el empate como sugerencia y `fueraDeAlcance`.
2. **Vista.** Dentro del tema ganador, la vista con más palabras coincidentes.
   - Si ninguna vista tiene palabras de la pregunta, va la vista por defecto o, si no hay, se sugieren las vistas.
   - Si dos vistas empatan, se sugieren esas dos.
3. **Códigos.** Una pregunta con código (folio, guía, lote, REC-, CAR-, GDE-) sigue yendo a `expediente-por-codigo`, que gana vistas. Por ejemplo, «¿en cuántos repaletizajes se diluyó el saldo 1234567?» va a la vista `repaletizajes`.

### Cómo elige Haiku

- `consultar` suma el parámetro **`vista`**: un enum con todas las claves de vista del catálogo permitido, más `general`.
- La descripción del catálogo lista cada tema con sus vistas, filtros y orden.
- `ValidadorLlamadaHaiku` rechaza una vista que no es del tema elegido. `general` se acepta solo si el tema tiene una única vista.
- La comprobación de cobertura de #740 usa los filtros de la vista: un filtro que la vista no aplica queda «sin cubrir».
- `pedir_aclaracion` ofrece pares tema y vista: cada opción suma `vista`.
- El prompt deja de mandar los rankings a `sin_consulta`. «Los más antiguos» o «los que más» se responden con una vista ordenada si existe una con ese orden; si no, siguen siendo `sin_consulta`.

### Vistas ordenadas

- **Orden fijo.** Una vista con `orden` (por ejemplo, antigüedad descendente) muestra las primeras 20 filas, el total y el enlace a la oficina. El usuario no elige otro orden ni otro umbral.
- **Antigüedad de folios.** Se mide desde `fecha_ingreso`, igual que el panel gerencial, con los mismos tramos (`gerencia.antiguedad_pt_tramos_dias`: 7, 14 y 30 días). Así la cifra coincide con lo que el gerente ya ve.

### Pantalla, «Entendí» y sugerencias

- **«Entendí»** nombra el tema y la vista: «Cuenta de envases · Saldos por productor».
- **Sugerencias.** Cada una lleva `clave`, `vista` y el título «Tema · Vista». La pantalla del asistente las muestra igual que hoy.
- **«Qué sé responder».** El catálogo se lista por tema, con sus vistas como sub-ítems.
- **Resultados.** No cambia su forma: `ResultadoConsulta` sigue con cifras, tabla y enlace.

### Banco y compuerta

- **Formato.** Cada pregunta del banco puede llevar `vista`. La respuesta es correcta si coinciden la consulta y, cuando el banco la indica, también la vista. Elegir otra vista del mismo tema cuenta como falsa.
- **Por cada vista nueva:**
  - al menos dos preguntas que debe responder;
  - al menos una pregunta parecida que **no** debe responder, porque es de otra vista u otro tema, o porque no hay consulta.
- **Las 38 preguntas que hoy no tienen consulta** se reasignan a su vista cuando la tanda la crea, con `consulta_objetivo` y `vista`.
- **CI** sigue exigiendo cero falsas de las reglas. **En el servidor**, después de cada tanda, `asistente:evaluar --modo=haiku` debe dar cero falsas. Si no, el modo mixto vuelve a sin IA hasta corregir.

### Permisos

- **El tema** usa el permiso de su oficina de origen, como hoy.
- **Una vista** puede exigir uno mayor. Por ejemplo, los descuadres de conciliación exigen `ajustar-cuenta-envases`, igual que su pantalla.
- **En el catálogo** que ven las reglas y Haiku solo aparecen las vistas que el usuario puede ejecutar.

### Recomendaciones

Se declaran por vista con `admiteRecomendacion`. Las cinco actuales siguen igual. Candidatas nuevas:

- folios más antiguos: qué despachar primero;
- lotes disponibles a proceso por antigüedad;
- cargas abiertas con incidencias.

Cada una con sus capacidades, nunca personas ni clientes.

## Catálogo por tandas

Cada tanda es un PR con sus vistas, pruebas con datos (`tests/Concerns/Prepara*`), preguntas del banco y la tabla de `docs/ASISTENTE_OFICINA.md`. Al empezar cada tanda, su lista de vistas se confirma con el usuario. Las tandas 2 a 4 quedan aquí como propuesta.

### Tanda 0: infraestructura

Vistas en la interfaz y en `ConsultaBase`, `ParametrosConsulta`, reglas, Haiku, validador, sugerencias, «Entendí», banco, evaluador y catálogo de la pantalla. Las 19 consultas siguen respondiendo igual. Puede ir en el mismo PR que la tanda 1 si queda chico.

### Tanda 1: envases

Las fuentes son `ServicioCuentaCorrienteEnvases`, `ResumenEnvasesPorCsg`, `DetalleEnvasesPorCsg`, `ServicioConciliacionSalidaEnvases`, la revisión de movimientos y las guías de despacho de envases.

| Tema | Vista | Responde | Fuente | Permiso |
| --- | --- | --- | --- | --- |
| `cuenta-envases` | `saldos` (por defecto) | Saldo por cliente y tipo de envase: quién debe a quién | Saldos de Cuenta Envases | `consultar-cuenta-envases` |
| | `por-productor` | Saldo por CSG: «¿cuántos bins nos debe el CSG 104512?» | `ResumenEnvasesPorCsg` | ídem |
| | `estado-cuenta` | Saldo inicial, recibidos, entregados y saldo final por tipo, de un cliente en un periodo; mismas cifras que el PDF | `estadoCuenta()` | ídem |
| | `movimientos` | Movimientos del periodo de un cliente o CSG, con documento y efecto | `kardex()` | ídem |
| | `devoluciones` | Devoluciones de envases de productores | movimientos `DevolucionEnvases` | ídem |
| | `reasignaciones` | Guías reasignadas a otro CSG o cuenta | `ReasignacionEnvases`, `ReasignacionProductorEnvases` | ídem |
| | `descuadres` | Salidas donde la guía de despacho de envases y Romana registraron cantidades distintas, con la diferencia por tipo de envase. Decidido por el usuario el 23-11-2026; no incluye movimientos observados ni cuenta contra físico | `ServicioConciliacionSalidaEnvases` (la diferencia despacho − Romana) | `ajustar-cuenta-envases`, como la pantalla de conciliación |
| `existencia-envases` | `por-propiedad` (por defecto) | Envases en planta por tipo y propiedad (propios, arrendados, de clientes), reservados en guías borrador y disponibles | `existencia()` | `consultar-cuenta-envases` |
| | `propios-entregados` | Envases propios entregados a cada cliente | `propiosEntregados()` | ídem |
| `guias-envases` | `borrador` (por defecto) | Guías en borrador (absorbe `despachos-envases-pendientes`) | guías de despacho de envases | el de la consulta actual |
| | `confirmadas` | Guías confirmadas en el periodo | ídem | ídem |
| | `sin-numero` | Guías confirmadas sin N° de guía emitida | ídem | ídem |
| `envases-recibidos` | `recibidos` (por defecto) | Lo de hoy, sin cambios | | |
| | `salidas-destare` | Camiones que salieron con envases en el destare | `SalidaEnvaseRecepcionRomana` | el de Romana |

### Tanda 2: lotes de materia prima y SAG (propuesta)

| Tema | Vistas |
| --- | --- |
| `lotes-mp` | `por-estado` (los diez estados de `EstadoLoteMateriaPrima`), `hidrocooler` (pendiente, en curso, retenido), `disponibles-proceso` (con antigüedad), `en-camara`, `entrega-parcial` |
| `retenciones-sag` | `retenidos` (retención general), `con-restricciones` (por destino, según el accessor de `RetencionLoteSag`), `con-observaciones`, `liberados` (periodo) |
| `inspecciones-sag` | `en-curso` (preparación, en inspección, resultado parcial), `finalizadas` (periodo) |
| `ordenes-proceso` | `por-turno` («¿qué lotes entran a proceso en el turno A?»), `pendientes` |
| `expediente-por-codigo` | `trazabilidad`: folios que contienen un lote y lotes de un folio (`ServicioTrazabilidadLotes`) |

### Tanda 3: despachos y cargas (propuesta)

| Tema | Vistas |
| --- | --- |
| `cargas` | `abiertas` (absorbe `estado-cargas`), `listas-para-cerrar`, `retornadas` (Retornada y RetornoParcial), `con-incidencias`, `tiempos` (absorbe `tiempos-cargas`) |
| `embarques` | `proximos` (calendario), `tentativos`, `por-destino` (país de destino de `InstructivoEmbarque`) |
| `folios-despachados` | `general` (hoy), `por-destino`, `por-cliente` con cajas |

### Tanda 4: folios y cámaras (propuesta)

| Tema | Vistas |
| --- | --- |
| `folios-camara` | `antiguedad` (ordenada, por `fecha_ingreso`), `por-cliente`, `retenidos` (retención operacional), `cajas`, `formatos` y `saldos` (absorben `cajas-pt`, `formatos-pt` y `saldos-pt`) |
| `expediente-por-codigo` | `repaletizajes`: en cuántos repaletizajes se diluyó un saldo y en qué folios terminó (`RepaletizajeDetalle`, `ProyeccionTrazabilidadFolio`) |
| `camaras` | `ocupacion`, `maniobras-pausadas` |

### Después

Materiales (bajo mínimo, vencimientos, recepciones provisionales, existencia por cliente), prefrío (temperaturas, punto de rocío) e indicadores del panel gerencial (`ServicioPanelGerencial`).

## Lo que decide quien construye

- El nombre y la forma exacta de `VistaConsulta`, siempre que tenga lo descrito arriba.
- Cómo reutilizar cada servicio de origen sin duplicar cálculos, y los índices que haga falta para responder rápido.
- Los textos de resumen de cada vista, con el estilo de las consultas actuales.
- Si la tanda 0 va sola o junto con la tanda 1.

## Decisiones del usuario

- Enfoque 2, consultas por tema con vistas (23-11-2026).
- Un descuadre de envases es la diferencia entre la guía de despacho y Romana en una misma salida (23-11-2026).
- Orden de las tandas: envases, lotes y SAG, despachos y cargas, folios y cámaras.

## Decisiones de diseño, para revisar

- Antigüedad de folios desde `fecha_ingreso`, con los tramos del panel gerencial, para que coincida con lo que ya ve el gerente.
- Las consultas actuales mantienen su clave; solo se agrupan cuando una tanda crea un tema que se les superpone.
- Las vistas ordenadas muestran las primeras 20 filas, el total y el enlace a la oficina; sin umbrales elegidos por el usuario.
- Elegir otra vista del mismo tema cuenta como respuesta falsa en el banco.
