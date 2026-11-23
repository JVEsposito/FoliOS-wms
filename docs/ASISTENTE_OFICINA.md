# Asistente de Oficina

El asistente responde en español preguntas sobre la operación desde cualquier oficina, con los datos vivos de FoliOS. Es de solo lectura: no crea, corrige ni anula nada.

Diseño aprobado: [`superpowers/specs/2026-11-22-asistente-oficina-design.md`](superpowers/specs/2026-11-22-asistente-oficina-design.md).

## Estado

**Fase A: sin IA.** Un intérprete por reglas reconoce fechas, códigos, clientes, CSG y especies, y elige la consulta del catálogo que mejor cubre la pregunta. Es el modo por defecto (`sin_ia`).

**Fase B: modo mixto con Haiku (ver abajo).** Las reglas responden lo que entienden con certeza; el resto va a Claude Haiku 5.5 como intérprete. Haiku solo elige una consulta del catálogo: FoliOS la valida, calcula las cifras y escribe la respuesta. Se activa solo si la compuerta lo justifica y el servidor tiene `ANTHROPIC_API_KEY`.

**Pendiente:** la pantalla de configuración con consumo y piloto (PR 7) y las recomendaciones a pedido (PR 8).

El plan de la fase B, con el banco de 60 preguntas reales, el diseño de Haiku como intérprete, los topes decididos (US$20 al mes, 50 a 60 preguntas por usuario al día) y las recomendaciones a pedido, está en [`ASISTENTE_ESTUDIO_HAIKU.md`](ASISTENTE_ESTUDIO_HAIKU.md).

## Dónde aparece

El asistente es un gajo flotante en la esquina inferior derecha de cada oficina, del color del área abierta.

- **Abrir y cerrar:** con un clic, con `Alt+A` o con `Escape`.
- **Mover:** se arrastra a cualquier esquina y la posición queda guardada por usuario en el navegador.
- **Iniciativa propia:** nunca se abre ni avisa solo.

## Quién lo usa

- **Usar el asistente:** el módulo **Asistente** (`administracion.asistente`) se habilita por perfil de acceso. El rol `cliente` nunca lo tiene, aunque el perfil incluya el módulo.
- **Configurarlo:** el módulo **Configuración del asistente** (`administracion.asistente-configuracion`) es solo para el rol administrador. Permite ajustar los rangos de tara y el margen de revisión, y ver el registro de preguntas.
- **Qué responde a cada usuario:** cada consulta exige el mismo permiso que su oficina de origen. Una persona sin acceso a Materiales no recibe consumos, ni como respuesta ni como sugerencia. El backend lo valida en cada llamada.

## Qué responde

| Consulta (clave) | Responde | Filtros que aplica | Permiso (oficina de origen) |
| --- | --- | --- | --- |
| `tiempos-romana` | Tiempo en planta por camión, espera de destare y los 10 más lentos | periodo, cliente | Romana |
| `kilos-recepciones` | Kilos brutos, tara y netos de las recepciones cerradas, por cliente | periodo, cliente, especie, por jornada | Romana |
| `kilos-por-csg` | Kilos netos por CSG según los lotes de materia prima | periodo, cliente, CSG, especie | Materia prima |
| `revisar-taras` | Posibles errores de tara o destare (ver abajo) | periodo, cliente, CSG, especie | Romana |
| `cajas-pt` | Cajas y folios en existencia por cliente y especie | cliente, especie (existencia actual) | Existencias PT |
| `formatos-pt` | Cajas en existencia por formato de embalaje | cliente, especie (existencia actual) | Existencias PT |
| `saldos-pt` | Saldos vigentes con cajas, cámara y posición | cliente, especie (existencia actual) | Existencias PT |
| `repaletizajes` | Repas confirmados en el periodo, con su folio resultante | periodo, cliente | Repaletizajes |
| `tiempos-prefrio` | Duración de procesos finalizados contra su objetivo | periodo | Prefrío |
| `tiempos-cargas` | Duración de las cargas, desde la publicación hasta el cierre | periodo, cliente | Cargas |
| `consumos-ot` | Consumo y merma por orden de trabajo | periodo, cliente | Consumos por OT |
| `expediente-por-codigo` | Lo que la Búsqueda operacional encuentra para un código, más las guías de despacho de envases (`GDE-…` o número de guía) si el usuario ve la cuenta de envases | código | Búsqueda operacional |
| `camiones-en-planta` | Camiones con su recepción abierta en Romana, con etapa y minutos en planta | cliente (ahora) | Romana |
| `envases-recibidos` | Bins, totes y otros envases que llegaron con la fruta, por tipo (validado o, si falta, declarado) | periodo, cliente | Romana |
| `despachos-envases-pendientes` | Guías de despacho de envases en borrador | cliente (ahora) | Cuenta o despacho de envases |
| `estado-cargas` | Cargas que todavía no se cierran, con estado y folios asignados | cliente (ahora) | Cargas |
| `folios-despachados` | Pallets y saldos que salieron en el periodo, por carga, según la salida confirmada | periodo, cliente | Cargas |
| `prefrio-en-curso` | Procesos de prefrío en curso, con minutos transcurridos y cuánto pasaron su objetivo | ahora | Prefrío |
| `validaciones-pt` | Folios validados en el periodo por resultado, con la jornada de la propia validación | periodo | Validación PT |
| `anulaciones-validacion` | Validaciones PT anuladas en el periodo, con folio, motivo y responsable | periodo | Validación PT |

**Periodo:** se cuenta en jornadas operativas, de 07:00 a 06:59 del día siguiente en la zona de la planta (la misma regla de `CalendarioOperativo`). A las 03:00, «hoy» sigue siendo la jornada que empezó ayer a las 07:00. Por defecto es la jornada en curso. Se reconocen:

- «hoy», «ayer», «anteayer», «mañana», «pasado mañana», un día de la semana («el jueves», «el domingo pasado»), «esta semana», «la semana pasada», «este mes», «el mes pasado», una fecha (`22-11` o `22/11/2026`) o un rango de dos fechas;
- turnos de una sola jornada: «turno A», «turno B», «turno noche», «esta mañana» (turno A) y «anoche» (el turno B que terminó; durante el turno B, el que está en curso). Sin regla de turnos configurada, el turno A va de 07:00 a 19:00 y el B de 19:00 a 07:00;
- «la temporada» o «esta temporada»: desde la primera jornada de la temporada activa hasta hoy;
- «temporada pasada» o «temporada anterior»: la temporada productiva anterior a la activa, completa si no se nombra otra fecha.

**Filtros por cliente en producto terminado:** el folio guarda la exportadora como texto, así que el cliente se reconoce por su código o su nombre. Una carga es del cliente si lleva al menos un folio suyo; un repaletizaje, si su folio resultante es suyo. La especie del producto terminado se compara con el texto de la especie del folio.

**Una fila por jornada:** «por día», «día a día», «cada día» o «diario» piden en `kilos-recepciones` una fila por jornada operativa, con la jornada de más kilos en el resumen. En las demás consultas se avisa que no agrupan por día.

**Línea «Entendí»:** cada respuesta empieza con la consulta que se ejecutó, el periodo y los filtros que se aplicaron, y un botón **Corregir** que devuelve la pregunta al cuadro. Si la pregunta nombra un filtro que la consulta no aplica (por ejemplo un cliente en `repaletizajes`, o una fecha en una consulta de existencia actual), se avisa con ◆: la cifra nunca se presenta como filtrada si no lo está.

**Cuándo responde:** el intérprete sin IA ignora las palabras vacías y las que ya reconoció como periodo, cliente, CSG, especie o código. Responde solo si una consulta explica al menos el 60 % de las palabras restantes y ninguna otra empata con ella. Las palabras que comparten dos consultas (por ejemplo «cajas» en `formatos-pt`, «kilos» en `kilos-por-csg`) cuentan la mitad para la consulta secundaria.

**Lo que la fase A no responde aunque las palabras coincidan:** comparaciones y agrupaciones («contra la semana pasada», «qué día entró más»), condiciones numéricas («más de 3 días»), evaluaciones sin cifra («¿cómo viene el CSG…?») y una fecha pasada en una consulta de existencia actual. En esos casos dice por qué y ofrece las consultas parecidas.

**Si no entiende la pregunta:** propone hasta tres consultas que comparten alguna palabra con ella, ya completadas con lo que reconoció, y un enlace a la Búsqueda operacional. Si ninguna se parece, lo dice y ofrece solo la búsqueda; nunca propone consultas al azar. Cada pregunta queda registrada para ampliar el catálogo.

## Banco de preguntas

`resources/asistente/banco-preguntas.json` guarda 60 preguntas reales de la planta, por área, con la consulta que debería responderlas y la que la fase A ejecuta hoy (`responde_hoy`, o `null` si debe preguntar). `BancoPreguntasAsistenteTest` corre en CI y exige cero respuestas falsas: ni otra consulta, ni otro periodo, ni un filtro reconocido sin aviso. Al sumar una consulta, se actualiza `responde_hoy` de las preguntas que pasa a responder. Valle Alto y el CSG 104512 son ejemplos inventados.

## Compuerta antes de Haiku

Haiku se conecta solo si las preguntas reales lo justifican (ver `docs/ASISTENTE_ESTUDIO_HAIKU.md`). Dos comandos de solo lectura lo miden; ninguno ejecuta consultas ni registra preguntas:

- `php artisan asistente:evaluar` corre el intérprete sobre el banco con los permisos de un administrador activo (o de `--usuario=correo`). Clasifica cada pregunta como correcta, pregunta, pregunta de más o falsa, y termina con código 1 si hay alguna falsa. Con los datos reales de la planta solo compara la consulta elegida; los periodos y filtros los revisa la prueba de CI.
- `php artisan asistente:compuerta --dias=7` mide el registro de esos días de dos formas: como quedó registrado y reinterpretado con las reglas de hoy, porque una pregunta que antes terminó en sugerencias puede tener ya su consulta. Lista las preguntas reales que siguen sin consulta, para sumarlas al banco, y entrega una decisión.

| Decisión | Cuándo |
| --- | --- |
| `corregir_reglas` | El banco tiene alguna respuesta falsa |
| `datos_insuficientes` | Hay menos de 50 preguntas en el periodo |
| `conectar_haiku` | Más del 15 % de las preguntas reales, reinterpretadas, sigue sin consulta |
| `seguir_sin_ia` | El 15 % o menos sigue sin consulta: conviene sumar consultas antes que Haiku |

Las preguntas de usuarios inactivos no se reinterpretan, y se revisan como máximo las 2.000 más recientes.

## Modo mixto con Haiku

**Enrutamiento.** Toda pregunta pasa primero por las reglas. Si eligen una consulta con certeza, Haiku no se llama. Si no, y el modo es `mixto`:

1. **Tema sensible:** RRHH, Finanzas, sueldos, pagos, personal, conductores, o el nombre completo de un usuario de FoliOS. La pregunta no sale de la planta; se responde con las reglas y se avisa.
2. **Topes:** sin clave, con el tope del mes alcanzado (US$20 por defecto, `ASISTENTE_TOPE_MENSUAL_USD`) o con el límite diario del usuario (50 llamadas por jornada, `ASISTENTE_TOPE_DIARIO_USUARIO`), se responde con las reglas y se dice por qué.
3. **Fichas:** los clientes y CSG reconocidos se cambian por `[CLIENTE_1]` y `[CSG_1]` antes de enviar. Un nombre que el reconocedor no conoce sale tal como se escribió. Los códigos nunca llegan a Haiku: las reglas los resuelven antes.
4. **Llamada:** una sola, a `claude-haiku-5-5`, con `tool_choice: any` y tres herramientas estrictas.
5. **Validación local:** consulta del catálogo del usuario, fichas de la pregunta, especie de la temporada, fechas reales, no futuras y de hasta 400 jornadas, y turno solo con una jornada. Si algo no calza, no se ejecuta nada: el asistente repregunta.
6. **Respuesta:** la arma FoliOS con la línea «Entendí», como en el modo sin IA, con la insignia **Haiku** y el pie «Cifras calculadas por FoliOS».

**Herramientas.** La API admite hasta 20 herramientas estrictas y 24 parámetros opcionales por pedido, así que no hay una herramienta por consulta:

| Herramienta | Uso | Parámetros |
| --- | --- | --- |
| `consultar` | Ejecutar una consulta del catálogo | `consulta` (enum con las claves que el usuario puede ver), `periodo_tipo` (`actual`, `jornada`, `turno`, `rango`, `temporada`, `temporada_anterior`), `desde`, `hasta`, `turno`, `cliente` (ficha), `csg` (ficha), `especie` (enum de la temporada), `agrupar_por` |
| `pedir_aclaracion` | Ofrecer de 2 a 4 consultas | `opciones` |
| `sin_consulta` | Ninguna consulta responde | `motivo`: otro tema, comparación, pronóstico, recomendación o dato no disponible |

Todos los parámetros son obligatorios, con valores neutros (`""`, `todas`, `ninguno`, `nada`). La descripción del catálogo va en `consultar` y ningún nombre de cliente va en un esquema, porque los esquemas estrictos se guardan hasta 24 horas. El prompt de sistema es fijo y queda en caché; la fecha, la jornada y el turno van en el mensaje del usuario para no romper la caché.

**Cuando Haiku no está disponible.** Sin red, con un error de la API o con una respuesta sin herramienta, la llamada queda registrada como `error` y el asistente responde con las reglas y lo avisa.

**Qué se registra.** Cada llamada queda en `asistente_llamadas_ia`, con modelo, herramienta, consulta, tokens, costo y duración. Nunca se guarda el texto enviado ni los resultados. La pregunta queda en `asistente_preguntas` con `interprete` = `haiku`.

**Configuración.** `ANTHROPIC_API_KEY` va solo en el `.env` del servidor. El modo se cambia con `PUT /api/asistente/configuracion` (`modo`: `sin_ia` o `mixto`); `mixto` se rechaza si falta la clave. `GET /api/asistente/configuracion` informa en `ia` si hay clave, el modelo, el consumo del mes y los topes.

**Evaluación con Haiku real.** `php artisan asistente:evaluar --modo=haiku` corre el banco como el modo mixto, con llamadas reales que cuentan en los topes, e informa llamadas, tokens y costo. Las pruebas de CI usan respuestas grabadas y nunca llaman a la API.

## Revisión de tara y destare

Usa los criterios con que la planta revisa hoy:

1. **Neto por tote:** el neto por tote de la recepción debe estar dentro del rango normal. El rango arranca en **8,2 a 9,5 kg** y es configurable por envase y por especie. Un rango de la especie tiene prioridad sobre el general.
2. **Tara del envase:** la tara de bins y totes debe ser la oficial que la recepción congeló al crearse. En recepciones nuevas el backend ya impide otra tara, así que esta revisión encuentra sobre todo correcciones y recepciones antiguas.
3. **Esponjas:** la tara medida se compara con lo habitual para ese tipo de esponja en la temporada.
4. **Histórico por CSG:** el neto por tote de cada lote se compara con lo habitual para su CSG en la temporada activa y en la anterior. Se calcula por lote, después de Validación MP.

«Lo habitual» es la media ± k desviaciones estándar, con **k = 2** configurable. Si hay menos de 10 muestras no se marca nada, porque la historia no alcanza.

## API

Todas las rutas usan `auth:sanctum` y tienen un límite de 30 solicitudes por minuto.

| Método y ruta | Permiso | Uso |
| --- | --- | --- |
| `POST /api/asistente/preguntas` `{texto}` | usar el asistente | Pregunta en texto libre, de 1 a 500 caracteres. Responde `entendido` (`consulta`, `fichas`, `no_aplicados`) y `resultado`, o `sugerencias` con `mensaje` y `busqueda` |
| `GET /api/asistente/consultas` | usar el asistente | Catálogo de consultas permitidas para el usuario |
| `POST /api/asistente/consultas/{clave}` | usar el asistente y el permiso de la consulta | Ejecuta una consulta con `desde` y `hasta` (jornadas), `turno` (`A` o `B`), `periodo` (etiqueta), `cliente_id`, `csg_id`, `especie_id`, `codigo` o `temporada_anterior` o `agrupar` (`dia`). Responde con `entendido` y `resultado` |
| `GET` y `PUT /api/asistente/configuracion` | configurar el asistente | Margen (0,5 a 5), rangos de neto por envase y `modo` (`sin_ia` o `mixto`); el `GET` informa el consumo de Haiku en `ia` |
| `GET /api/asistente/preguntas?estado=` | configurar el asistente | Registro de preguntas, con `estado` `respondida`, `sin_datos` o `sugerencias` |
| `GET /api/asistente/compuerta?dias=` | configurar el asistente | El informe de `asistente:compuerta` (1 a 90 días; 7 por defecto) |

## Agregar una consulta

1. Implementar `App\Services\Asistente\Consultas\ConsultaAsistente`. Lo habitual es extender `ConsultaBase`.
2. Exigir en `puedeEjecutar()` el mismo permiso de la oficina de origen.
3. Declarar en `filtros()` solo los filtros que la consulta aplica de verdad (`periodo`, `cliente`, `csg`, `especie`, `codigo`, `por_dia`), y en `palabrasSecundarias()` las palabras que comparte con otra consulta.
4. Sumarla a `AsistenteServiceProvider::CONSULTAS`.
5. Si falta un cálculo, agregarlo en el servicio del dominio, no en la consulta.

## Datos

| Tabla | Contenido |
| --- | --- |
| `asistente_preguntas` | Registro de cada pregunta. No se borra. |
| `asistente_rangos_tara` | Rangos de neto por envase y especie |
| `asistente_configuracion` | Una sola fila, con el margen y el modo (`sin_ia` o `mixto`) |
| `asistente_llamadas_ia` | Una fila por llamada a Haiku: tokens, costo, herramienta y resultado. No se borra. |
