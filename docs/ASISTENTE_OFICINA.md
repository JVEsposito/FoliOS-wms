# Asistente de Oficina

El asistente responde en español preguntas sobre la operación desde cualquier oficina, con los datos vivos de FoliOS. Es de solo lectura: no crea, corrige ni anula nada.

Diseño aprobado: [`superpowers/specs/2026-11-22-asistente-oficina-design.md`](superpowers/specs/2026-11-22-asistente-oficina-design.md).

## Estado

**Fase A: sin IA.** Un intérprete por reglas reconoce fechas, códigos, clientes, CSG y especies, y elige la consulta del catálogo que mejor cubre la pregunta. Es el modo por defecto (`sin_ia`).

**Fase B: modo mixto con Haiku (ver abajo).** Las reglas responden lo que entienden con certeza; el resto va a Claude Haiku 5.5 como intérprete. Haiku solo elige una consulta del catálogo: FoliOS la valida, calcula las cifras y escribe la respuesta. Se activa solo si la compuerta lo justifica y el servidor tiene `ANTHROPIC_API_KEY`.

**Pendiente:** comparar dos periodos (`comparar_con`).

El plan de la fase B, con el banco de 60 preguntas reales, el diseño de Haiku como intérprete, los topes decididos (US$20 al mes, 50 a 60 preguntas por usuario al día) y las recomendaciones a pedido, está en [`ASISTENTE_ESTUDIO_HAIKU.md`](ASISTENTE_ESTUDIO_HAIKU.md).

## Dónde aparece

El asistente es un gajo flotante en la esquina inferior derecha de cada oficina, del color del área abierta.

- **Abrir y cerrar:** con un clic, con `Alt+A` o con `Escape`.
- **Mover:** se arrastra a cualquier esquina y la posición queda guardada por usuario en el navegador.
- **Iniciativa propia:** nunca se abre ni avisa solo.

## Quién lo usa

- **Usar el asistente:** el módulo **Asistente** (`administracion.asistente`) se habilita por perfil de acceso. El rol `cliente` nunca lo tiene, aunque el perfil incluya el módulo.
- **Configurarlo:** el módulo **Configuración del asistente** (`administracion.asistente-configuracion`) es solo para el rol administrador. Su pantalla está en **Administración → Configuración del asistente** (`/oficina/administracion/asistente`).
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

## Pantalla de configuración

En `/oficina/administracion/asistente`, solo para quien administra el asistente:

- **Modo:** sin IA o mixto con Haiku. El mixto queda deshabilitado si el servidor no tiene `ANTHROPIC_API_KEY`.
- **Topes:** tope de gasto del mes (hasta US$200) y preguntas a Haiku por usuario y jornada (1 a 200). Vacíos, rigen `ASISTENTE_TOPE_MENSUAL_USD` y `ASISTENTE_TOPE_DIARIO_USUARIO`.
- **Piloto:** hasta 20 usuarios con Haiku; la propuesta es de 3 a 5 durante dos semanas. Sin marcas, Haiku queda para todos los que usan el asistente. Fuera del piloto, el asistente responde sin IA y sin aviso.
- **Consumo:** gasto y llamadas del mes contra el tope, con el detalle por usuario.
- **Compuerta:** el botón «Medir 7 días» muestra la decisión de `asistente:compuerta` y las preguntas reales que siguen sin consulta.
- **Revisión de taras:** margen y rangos de neto por envase.
- **Registro:** las preguntas recientes, filtradas por estado, con su consulta y si la interpretó Haiku.

**Piloto propuesto.**
1. Elegir de 3 a 5 usuarios de áreas distintas.
2. Pasar a modo mixto.
3. Revisar cada semana el registro y el consumo.
4. Al cierre de las dos semanas, sumar al banco las preguntas que siguen sin consulta y decidir con la compuerta si Haiku se abre a todos.

## Modo mixto con Haiku

**Enrutamiento.** Toda pregunta pasa primero por las reglas. Si eligen una consulta con certeza, Haiku no se llama. Si no, y el modo es `mixto`:

1. **Tema sensible:** RRHH, Finanzas, sueldos, pagos, personal, conductores, o el nombre completo de un usuario de FoliOS. La pregunta no sale de la planta; se responde con las reglas y se avisa.
2. **Topes:** sin clave, con el tope del mes alcanzado (US$20 por defecto, `ASISTENTE_TOPE_MENSUAL_USD`) o con el límite diario del usuario (50 llamadas por jornada, `ASISTENTE_TOPE_DIARIO_USUARIO`), se responde con las reglas y se dice por qué.
3. **Fichas:** los clientes y CSG reconocidos se cambian por `[CLIENTE_1]` y `[CSG_1]` antes de enviar. Un nombre que el reconocedor no conoce sale tal como se escribió. Los códigos nunca llegan a Haiku: las reglas los resuelven antes.
4. **Llamada:** una sola, a `claude-haiku-5-5`, con `tool_choice: any` y tres herramientas estrictas.
5. **Validación local:** consulta del catálogo del usuario, fichas de la pregunta, especie de la temporada, fechas reales, no futuras y de hasta 400 jornadas, y turno solo con una jornada. Si algo no calza, no se ejecuta nada: el asistente repregunta.
6. **Cobertura:** la consulta se ejecuta solo si cubre toda la pregunta. No la cubre si Haiku anota condiciones en `sin_cubrir` (un estado como «pendiente» o «vacíos», un destino, un material, una agrupación) o si pide un filtro que la consulta no tiene: un cliente, CSG, especie o detalle por día que la consulta no admite, u otro día en una existencia, con la misma regla que el modo sin IA. Entonces el asistente responde «Lo más cercano que tengo es «Envases recibidos», pero no cubre «vacíos». ¿Te sirve igual o es alguna de estas?» y la ofrece sin ejecutarla. El registro guarda solo cuántas condiciones faltaron (`parcial: 2 sin cubrir`), nunca su texto.
7. **Respuesta:** la arma FoliOS con la línea «Entendí», como en el modo sin IA, con la insignia **Haiku** y el pie «Cifras calculadas por FoliOS».

**Herramientas.** La API admite hasta 20 herramientas estrictas y 24 parámetros opcionales por pedido, así que no hay una herramienta por consulta:

| Herramienta | Uso | Parámetros |
| --- | --- | --- |
| `consultar` | Ejecutar una consulta del catálogo | `pide` (la cifra o lista y cada condición de la pregunta, escrito antes de elegir), `consulta` (enum con las claves que el usuario puede ver), `sin_cubrir` (condiciones que la consulta no cubre, con las palabras de la pregunta), `periodo_tipo` (`actual`, `jornada`, `turno`, `rango`, `temporada`, `temporada_anterior`), `desde`, `hasta`, `turno`, `cliente` (ficha), `csg` (ficha), `especie` (enum de la temporada), `agrupar_por` |
| `pedir_aclaracion` | Ofrecer de 2 a 4 consultas | `opciones` |
| `sin_consulta` | Ninguna consulta responde | `motivo`: otro tema, comparación, pronóstico, recomendación o dato no disponible |

Todos los parámetros son obligatorios, con valores neutros (`""`, `[]`, `todas`, `ninguno`, `nada`). El orden de `consultar` importa: Haiku escribe primero `pide`, luego elige la consulta y recién entonces revisa qué le falta. La descripción del catálogo va en `consultar` y ningún nombre de cliente va en un esquema, porque los esquemas estrictos se guardan hasta 24 horas. El prompt de sistema es fijo y queda en caché; la fecha, la jornada y el turno van en el mensaje del usuario para no romper la caché.

**Cuando Haiku no está disponible.** Sin red, con un error de la API o con una respuesta sin herramienta, la llamada queda registrada como `error` y el asistente responde con las reglas y lo avisa.

**Qué se registra.** Cada llamada queda en `asistente_llamadas_ia`, con modelo, herramienta, consulta, tokens, costo y duración. Nunca se guarda el texto enviado ni los resultados. La pregunta queda en `asistente_preguntas` con `interprete` = `haiku`.

**Configuración.** `ANTHROPIC_API_KEY` va solo en el `.env` del servidor. El modo se cambia con `PUT /api/asistente/configuracion` (`modo`: `sin_ia` o `mixto`); `mixto` se rechaza si falta la clave. `GET /api/asistente/configuracion` informa en `ia` si hay clave, el modelo, el consumo del mes y los topes.

**Cobertura comprobada en el servidor.** Cada consulta declara al intérprete lo que responde y sus límites. Antes de aceptar una elección de Haiku, FoliOS comprueba que no cambie u omita los clientes, CSG, especies y periodos que reconoció, ni pida filtros que la consulta no aplica. También detecta confusiones conocidas entre estados, eventos, unidades y dominios: pendientes frente a validaciones terminadas, retornos frente a repaletizajes, producción histórica frente a existencias actuales, insumos de cartón frente a fruta embalada, y folios frente a cajas o kilos. La revisión de taras no sustituye un informe de promedios por CSG ni de salidas de envases. Si no hay cobertura, se ofrece una aclaración sin ejecutar la consulta. Se conservan las condiciones concretas que Haiku declaró en `sin_cubrir` y los filtros no admitidos que FoliOS detectó; si esas listas están vacías, la incompatibilidad detectada por el servidor también impide ejecutar la consulta.

**Evaluación con Haiku real.** `php artisan asistente:evaluar --modo=haiku` corre el banco como el modo mixto, con llamadas reales que cuentan en los topes, e informa llamadas exitosas, errores, preguntas sin API disponible, tokens y costo. Termina con código 1 si hay respuestas falsas, si no hubo llamadas exitosas con tokens, o si la API falló o quedó inaccesible por topes/piloto. El mismo criterio se aplica con `--json`: cero falsas no equivale a aprobación si la evaluación quedó incompleta.

La columna **Detalle** muestra lo que Haiku entendió que se pide (`pide`), la consulta que no cubrió todo y qué le faltó, o el motivo del rechazo. Las preguntas del banco son de ejemplo, así que ese detalle se muestra en la evaluación; el registro de llamadas conserva solo la cantidad de condiciones faltantes, no el texto. Las pruebas de CI usan respuestas grabadas y nunca llaman a la API.

La primera evaluación real (23-11-2026) dio 8 falsas de 38 llamadas: Haiku eligió consultas parecidas dejando fuera una condición (totes «vacíos», lo que «falta por validar», pallets que «volvieron de cargas», cajas «a Estados Unidos», cajas «de cartón», cajas «embaladas ayer» en una existencia). La comprobación de cobertura responde a eso. El modo mixto se activa solo con una evaluación completa y sin falsas.

Después de una evaluación de 38 llamadas, un usuario con el límite de 50 dispone como máximo de otras 12 en esa jornada, descontando otros usos. Antes de repetir el banco, revisar el cupo en Configuración del asistente; si no alcanza, esperar la siguiente jornada o evaluar con otro administrador autorizado que tenga cupo. No borrar los registros de consumo ni aumentar los topes automáticamente.

## Recomendaciones a pedido

Solo existen si el usuario las pide y siempre llevan el aviso de FoliOS.

- **Cuándo se ofrecen:** el botón **Pedir recomendación** aparece bajo una respuesta con datos, con el asistente en modo mixto y la clave configurada, y solo en las consultas que lo admiten: `prefrio-en-curso`, `tiempos-prefrio`, `estado-cargas`, `tiempos-cargas` y `revisar-taras`. Si la pregunta pide una recomendación («¿qué me recomiendas…?»), el asistente responde con los datos y señala el botón. Nunca aparece sola.
- **Qué se envía:** la consulta se vuelve a ejecutar con los permisos del usuario. A Haiku va solo ese resultado:
  - las cifras;
  - hasta 30 filas, con las columnas numéricas, los códigos y algunos textos de estado o instalación (túnel, estado, etapa);
  - los clientes como fichas;
  - sin texto libre ni personas;
  - las capacidades que vienen al caso: posiciones por túnel activo, andenes activos o rangos de neto por envase.
- **Aviso fijo:** «Solo es una recomendación de Haiku, basada en los datos de planta y las capacidades registradas en FoliOS. No es una instrucción: confírmala antes de actuar». Lo pone FoliOS siempre. Haiku además debe devolver la frase de reconocimiento en un campo obligatorio; si no lo hace, la recomendación no se muestra.
- **Cifras verificadas:** cada número del texto debe estar en los datos enviados, escrito como «9,5», «9.5» o «1.234». Si hay uno que no está, la recomendación no se muestra y el usuario lo sabe.
- **Límites:** es solo lectura y cuenta en el límite diario y en el tope del mes. Respeta el piloto, y no hay recomendación sobre una respuesta sin datos.
- **Registro:** `asistente_recomendaciones` guarda los datos enviados, la respuesta de Haiku, si se mostró o se rechazó y por qué, para revisarlas.

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
| `GET` y `PUT /api/asistente/configuracion` | configurar el asistente | Margen (0,5 a 5), rangos de neto por envase y `modo` (`sin_ia` o `mixto`), `tope_mensual_usd`, `limite_diario_usuario` y `usuarios_piloto`; el `GET` informa el consumo de Haiku y los topes vigentes en `ia` |
| `GET /api/asistente/preguntas?estado=` | configurar el asistente | Registro de preguntas, con `estado` `respondida`, `sin_datos` o `sugerencias` |
| `GET /api/asistente/compuerta?dias=` | configurar el asistente | El informe de `asistente:compuerta` (1 a 90 días; 7 por defecto) |
| `POST /api/asistente/recomendaciones/{clave}` | usar el asistente y el permiso de la consulta | Recomendación a pedido sobre el resultado de la consulta, con los mismos parámetros que `consultas/{clave}`; solo en modo mixto |
| `GET /api/asistente/consumo` | configurar el asistente | Gasto y llamadas a Haiku del mes, por día y por usuario |
| `GET /api/asistente/usuarios` | configurar el asistente | Usuarios activos que pueden usar el asistente: los candidatos al piloto |

## Agregar una consulta

1. Implementar `App\Services\Asistente\Consultas\ConsultaAsistente`. Lo habitual es extender `ConsultaBase`.
2. Exigir en `puedeEjecutar()` el mismo permiso de la oficina de origen.
3. Si admite recomendaciones, devolver `true` en `admiteRecomendacion()` y las cifras de planta que vienen al caso en `capacidades()`.
4. Declarar en `filtros()` solo los filtros que la consulta aplica de verdad (`periodo`, `cliente`, `csg`, `especie`, `codigo`, `por_dia`), y en `palabrasSecundarias()` las palabras que comparte con otra consulta.
5. Sumarla a `AsistenteServiceProvider::CONSULTAS`.
6. Si falta un cálculo, agregarlo en el servicio del dominio, no en la consulta.

## Datos

| Tabla | Contenido |
| --- | --- |
| `asistente_preguntas` | Registro de cada pregunta. No se borra. |
| `asistente_rangos_tara` | Rangos de neto por envase y especie |
| `asistente_configuracion` | Una sola fila, con el margen, el modo (`sin_ia` o `mixto`), los topes propios y los usuarios del piloto |
| `asistente_llamadas_ia` | Una fila por llamada a Haiku: tokens, costo, herramienta y resultado. No se borra. |
| `asistente_recomendaciones` | Cada recomendación pedida: datos enviados, respuesta, si se mostró y por qué. No se borra. |
