# Asistente: temas con vistas y guías de envases — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que una consulta del asistente pueda ser un tema con varias vistas. Como primera prueba, `despachos-envases-pendientes` pasa a ser el tema `guias-envases`, con las vistas `borrador`, `confirmadas` y `sin-numero`.

**Architecture:**
- `VistaConsulta` describe cada vista. `ConsultaBase` arma una vista `general` para las 19 consultas actuales, así que no hay que tocarlas. Los temas nuevos extienden `ConsultaPorTema`.
- La vista viaja en `ParametrosConsulta`. La eligen las reglas (por palabras, después del tema) o Haiku (parámetro `vista`), y FoliOS la valida antes de ejecutar.
- Las sugerencias pasan a ser pares tema y vista (`OpcionConsulta`).

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit sobre MySQL (CI 8.4).

**Spec:** `docs/superpowers/specs/2026-11-23-asistente-consultas-por-tema-design.md`. Es la tanda 0 más el tema de guías de la tanda 1. El resto de la tanda 1 (cuenta y existencia de envases, salidas en el destare) va en el PR siguiente, con su propio plan.

## Global Constraints

- Código, nombres, mensajes y documentación en español neutro.
- Toda autorización en Laravel. Una vista nunca muestra lo que el usuario no ve en su oficina.
- Cero respuestas falsas en el banco (`BancoPreguntasAsistenteTest`). Elegir otra vista del mismo tema cuenta como falsa.
- Haiku sigue con tres herramientas estrictas, con todos los parámetros obligatorios.
- La API sigue aceptando pedidos sin `vista`: en ese caso rige la vista por defecto del tema.
- `vendor/bin/pint --test` en verde. Mensajes de commit con las dos líneas de atribución de la sesión.

## Review Focus

1. **Botón de una sugerencia de vista:** ejecuta esa vista, porque `parametros` lleva `vista`. Lo prueba la Task 3.
2. **Pantalla abierta antes del despliegue:** si pide una consulta o una recomendación sin `vista`, rige la vista por defecto, sin error. Lo prueba la Task 3.
3. **Vista inexistente:** responde 422 `regla_de_negocio`; nunca 500 ni la vista por defecto. Lo prueba la Task 3.
4. **Haiku manda `general` en un tema con varias vistas:** se rechaza y repregunta; no se ejecuta la vista por defecto en silencio. Lo prueba la Task 5.
5. **Pregunta que nombra el tema sin ninguna palabra de vista, en un tema sin vista por defecto:** el asistente sugiere las vistas, no elige una. Lo prueba la Task 4, con un tema de prueba sin vista por defecto.

---

### Task 1: Vistas en el modelo de consultas

**Files:**
- Create: `app/Services/Asistente/Consultas/VistaConsulta.php`, `app/Services/Asistente/Consultas/ConsultaPorTema.php`
- Modify: `app/Services/Asistente/Consultas/ConsultaAsistente.php`, `app/Services/Asistente/Consultas/ConsultaBase.php`, `app/Services/Asistente/ParametrosConsulta.php`
- Test: `tests/Feature/Asistente/VistasConsultaTest.php`

**Interfaces:**
- Produces:
  - `final readonly class VistaConsulta`, con `const GENERAL = 'general'` y constructor `(string $clave, string $titulo, string $descripcion, array $palabras = [], array $filtros = [], ?string $orden = null, bool $admiteRecomendacion = false)`.
  - En `ConsultaAsistente`:
    - `vistas(): array` devuelve `list<VistaConsulta>`, nunca vacía;
    - `vistaPorDefecto(): ?string`;
    - `vista(?string $clave): ?VistaConsulta`: con null va la vista por defecto; con `general`, la única vista si el tema tiene una sola; si no existe, null;
    - `puedeVer(User $usuario, VistaConsulta $vista): bool`.
  - `abstract class ConsultaPorTema extends ConsultaBase`, con:
    - `abstract protected function vistasDelTema(): array` y `abstract protected function palabrasDelTema(): array`;
    - `abstract protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta`;
    - `palabrasClave()`: las palabras del tema más las palabras sueltas, sin frases, de sus vistas;
    - `filtros()`: la unión de los filtros de sus vistas;
    - `admiteRecomendacion()`: verdadero si alguna vista la admite;
    - `ejecutar()`: resuelve `vista($parametros->vista)` y, si no existe, lanza `DomainException('«{titulo}» no tiene esa vista.')`.
  - En `ParametrosConsulta`:
    - nuevo último parámetro `public ?string $vista = null`;
    - `conVista(?string $vista): self`;
    - `desdeArreglo()` lee `vista` solo si cumple `^[a-z0-9-]{1,40}$`, y `toArray()` la incluye.

- [ ] **Step 1: Write the failing tests** in `VistasConsultaTest` (usa `PreparaAsistente`)
  - `test_una_consulta_actual_tiene_una_vista_general`: `app(KilosRecepciones::class)->vistas()` tiene una vista, con clave `general`, el mismo título y los mismos filtros que la consulta. `vista(null)` y `vista('general')` la devuelven, y `vista('otra')` es null.
  - `test_un_tema_une_palabras_y_filtros_de_sus_vistas`: con un tema anónimo de prueba (`new class extends ConsultaPorTema`) de dos vistas, A con `['periodo']` y B con `['cliente']`, sin vista por defecto:
    - `filtros()` es `['periodo', 'cliente']`;
    - `vista(null)` es null;
    - `vista('general')` es null;
    - `ejecutar()` con una vista desconocida lanza `DomainException`.
  - `test_los_parametros_llevan_la_vista`: `ParametrosConsulta::desdeArreglo(['vista' => 'borrador'])->toArray()['vista']` es `'borrador'`, `desdeArreglo(['vista' => 'BORRA DOR'])->vista` es null y `(new ParametrosConsulta)->conVista('x')->vista` es `'x'`.
- [ ] **Step 2: Run** `php artisan test --filter=VistasConsultaTest`. Expected: FAIL, porque la clase `VistaConsulta` no existe.
- [ ] **Step 3: Implement** según *Interfaces*. `ConsultaBase::vistas()` devuelve `[new VistaConsulta(GENERAL, titulo(), descripcion(), palabrasClave(), filtros(), null, admiteRecomendacion())]`, `vistaPorDefecto()` devuelve `general` y `puedeVer()` delega en `puedeEjecutar()`.
- [ ] **Step 4: Run** `php artisan test --filter='VistasConsultaTest|ConsultasTanda'`. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: vistas en el modelo de consultas».

### Task 2: Tema `guias-envases`

**Files:**
- Create: `app/Services/Asistente/Consultas/GuiasEnvases.php`
- Delete: `app/Services/Asistente/Consultas/DespachosEnvasesPendientes.php`
- Modify:
  - `app/Providers/AsistenteServiceProvider.php`: registra `GuiasEnvases` en lugar de `DespachosEnvasesPendientes`;
  - `tests/Feature/Asistente/ConsultasTandaUnoTest.php`: sus usos de `DespachosEnvasesPendientes` pasan a la vista `borrador`.
- Test: `tests/Feature/Asistente/GuiasEnvasesTest.php`

**Interfaces:**
- Consumes: `ConsultaPorTema`, `VistaConsulta` (Task 1).
- Produces: el tema `guias-envases`, con título «Guías de despacho de envases», dominio `envases` y el mismo permiso de hoy (`puedeConsultarCuentaEnvases` o `puedeGestionarDespachoEnvases`). Vista por defecto: `borrador`.

| Vista | Título | Filtros | Palabras | Calcula |
| --- | --- | --- | --- | --- |
| `borrador` | En borrador | `cliente` | `borrador`, `borradores`, `pendiente`, `pendientes`, `por confirmar`, `sin confirmar` | la lógica actual de `DespachosEnvasesPendientes` |
| `confirmadas` | Confirmadas | `periodo`, `cliente` | `confirmada`, `confirmadas`, `confirmaron`, `confirmo`, `salida`, `salidas` | estado `Confirmada` con `salida_at` en las jornadas del periodo; columnas despacho, guía, cliente, patente y salida |
| `sin-numero` | Sin N° de guía emitida | `cliente` | `numero`, `numeros`, `emitida`, `emitidas`, `registrar`, `sin numero` | estado `Confirmada` con `numero_guia_despacho` null; columnas despacho, cliente y salida |

- Palabras del tema: `gde`. Palabras secundarias: `despacho`, `despachos`, `envases`, `envase`, `guia`, `guias`.
- Descripción de `sin-numero`: «Guías ya confirmadas que todavía no tienen registrado el N° de la guía emitida.»

- [ ] **Step 1: Write the failing tests** in `GuiasEnvasesTest`, con guías creadas como en `ConsultasTandaUnoTest`:
  - `test_borrador_lista_solo_las_guias_en_borrador`
  - `test_confirmadas_cuenta_las_salidas_del_periodo`: una confirmada ayer, una hoy y una en borrador. El periodo de ayer da 1 fila.
  - `test_sin_numero_lista_las_confirmadas_sin_guia_emitida`
  - `test_sin_vista_rige_borrador`: `ejecutar()` con `new ParametrosConsulta` da el mismo resultado que con la vista `borrador`.
- [ ] **Step 2: Run** `php artisan test --filter=GuiasEnvasesTest`. Expected: FAIL, porque la clase `GuiasEnvases` no existe.
- [ ] **Step 3: Implement** `GuiasEnvases`, moviendo el cálculo de borrador sin cambiarlo. Enlace: `/oficina/envases/despachos`.
- [ ] **Step 4: Run** `php artisan test --filter='GuiasEnvasesTest|ConsultasTandaUnoTest'`. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: guías de envases como tema con tres vistas».

### Task 3: API, respuestas, sugerencias y «Entendí» con vista

**Files:**
- Create: `app/Services/Asistente/OpcionConsulta.php`
- Modify: `app/Http/Controllers/Api/AsistenteController.php` (validación de `vista`), `app/Services/Asistente/ServicioAsistente.php`, `app/Services/Asistente/LineaEntendido.php`
- Test: `tests/Feature/Asistente/VistasAsistenteApiTest.php`

**Interfaces:**
- Consumes: Task 1 y Task 2.
- Produces:
  - `final readonly class OpcionConsulta`, con:
    - constructor `(ConsultaAsistente $consulta, ?VistaConsulta $vista = null)`;
    - `static de(ConsultaAsistente|OpcionConsulta $opcion): self`;
    - `titulo(): string`: «Tema · Vista» si el tema tiene más de una vista; si no, el título del tema;
    - `descripcion(): string`;
    - `clave(): string`: «clave/vista», para quitar repetidos.
  - `ServicioAsistente::sugerir()` acepta `list<ConsultaAsistente|OpcionConsulta>`. Cada sugerencia lleva `clave`, `vista` (null si no aplica), `titulo`, `descripcion` y `parametros`, que incluye `vista`.
  - `ejecutar()`, `recomendar()` y `responder()` resuelven la vista con `vista($parametros->vista)`:
    - si la vista no existe, `DomainException('Esa consulta no tiene la vista pedida.')`;
    - si el usuario no la puede ver, `OperacionNoAutorizada`;
    - si existe, se ejecuta con `conVista($vista->clave)`.
  - `catalogo()` suma `vistas`: una lista de `{clave, titulo, descripcion}` con solo las vistas que el usuario puede ver.
  - `recomendable()` usa `$vista->admiteRecomendacion`.
  - `LineaEntendido::construir()`:
    - `consulta` es `OpcionConsulta::titulo()`;
    - las fichas y los avisos usan los filtros de la vista.
  - Validación del controlador: `'vista' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9\-]+$/']`.

- [ ] **Step 1: Write the failing tests** in `VistasAsistenteApiTest` (`PreparaAsistente`, `actingAs` del administrador):
  - `test_ejecutar_una_vista`: `POST /api/asistente/consultas/guias-envases` con `{vista: 'sin-numero'}` da 200, con `data.parametros.vista` = `sin-numero` y `data.entendido.consulta` = «Guías de despacho de envases · Sin N° de guía emitida».
  - `test_sin_vista_rige_la_por_defecto`: sin `vista` da `data.parametros.vista` = `borrador`.
  - `test_una_vista_inexistente_es_regla_de_negocio`: `{vista: 'anuladas'}` da 422 con `codigo` = `regla_de_negocio`.
  - `test_una_consulta_de_una_vista_sigue_igual`: `POST .../kilos-recepciones` sin `vista` da 200 y `data.entendido.consulta` = «Kilos recepciones», sin «·».
  - `test_el_catalogo_trae_las_vistas`: en `GET /api/asistente/consultas`, la entrada `guias-envases` trae las tres vistas.
  - `test_una_sugerencia_de_vista_lleva_la_vista_en_sus_parametros`: `sugerir()`, por reflexión o por un endpoint que sugiera, con `new OpcionConsulta(guias, vista confirmadas)` deja `sugerencias.0.parametros.vista` = `confirmadas` y `sugerencias.0.titulo` = «Guías de despacho de envases · Confirmadas».
- [ ] **Step 2: Run** `php artisan test --filter=VistasAsistenteApiTest`. Expected: FAIL.
- [ ] **Step 3: Implement** según *Interfaces*. Donde `ServicioAsistente` une opciones de Haiku y de las reglas, normaliza con `OpcionConsulta::de()` y quita los repetidos con `OpcionConsulta::clave()`.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Asistente`. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: la API, las sugerencias y «Entendí» llevan la vista».

### Task 4: Las reglas eligen la vista; el banco la exige

**Files:**
- Modify:
  - `app/Services/Asistente/InterpreteReglasAsistente.php`;
  - `resources/asistente/banco-preguntas.json`: la #20 y las preguntas nuevas 61 a 64;
  - `tests/Feature/Asistente/BancoPreguntasAsistenteTest.php`.
- Test: `tests/Feature/Asistente/VistasReglasTest.php`

**Interfaces:**
- Consumes: Task 1 y Task 3 (`OpcionConsulta`).
- Produces: `interpretar()` devuelve:
  - si hay vista: `consulta` = el tema y `parametros` con `vista` = la clave elegida;
  - si no: `consulta` null, `alternativas` como `list<OpcionConsulta>` y `motivo` = «"{titulo}" tiene varias vistas. ¿Cuál quieres?».

  Algoritmo de `elegirVista(ConsultaAsistente $consulta, string $normalizado, array $significativas): array{0: ?VistaConsulta, 1: list<VistaConsulta>}`:
  1. Si el tema tiene una sola vista, va esa.
  2. Puntaje de cada vista:
     - una frase de sus palabras (con espacio) presente en `" {$normalizado} "` vale 2;
     - una palabra suelta que `coincide()` con alguna de las significativas vale 1.
  3. Gana el mejor puntaje mayor que 0 si es único. Si hay empate, las empatadas van como sugerencias.
  4. Si todas dan 0: va la vista por defecto o, si no hay, todas las vistas como sugerencias (hasta 4).

  `fueraDeAlcance()` recibe ahora los filtros de la vista elegida, en vez de `$consulta->filtros()`.
- Banco:
  - **#20:** `responde_hoy` = `guias-envases` y `vista` = `borrador`.
  - **Preguntas nuevas**, con `area` «Envases», `clase` `reglas_bastan` y `consulta_objetivo` igual a `responde_hoy`:

  | # | Pregunta | `responde_hoy` | `vista` |
  | --- | --- | --- | --- |
  | 61 | «¿Qué guías de envases se confirmaron ayer?» | `guias-envases` | `confirmadas` |
  | 62 | «¿Qué guías de envases faltan por confirmar?» | `guias-envases` | `borrador` |
  | 63 | «¿Qué guías confirmadas no tienen número de guía emitida?» | `guias-envases` | `sin-numero` |
  | 64 | «¿Qué guías de envases se anularon esta semana?» (parecida: no existe la vista) | null | — |

  La #64 lleva `clase` `falta_consulta` y `objetivo` «guías anuladas».
- Prueba del banco:
  - deja de exigir exactamente 60: exige al menos 60, numeradas de 1 a n, y que cada `vista` exista en su consulta;
  - `test_ninguna_respuesta_falsa` también compara `parametros->vista` cuando la pregunta trae `vista`.

- [ ] **Step 1: Write the failing tests**:
  - En el banco, las preguntas 61 a 64 y la #20.
  - `VistasReglasTest::test_frase_le_gana_a_la_raiz`: «guías sin confirmar» da `borrador`.
  - `VistasReglasTest::test_sin_palabras_de_vista_rige_la_por_defecto`: «guías de envases» da `borrador`.
  - `VistasReglasTest::test_un_tema_sin_vista_por_defecto_sugiere_sus_vistas`: registra en el catálogo un tema de prueba de dos vistas sin vista por defecto. La pregunta solo con palabras del tema da `consulta` null y dos `alternativas` que son `OpcionConsulta`.
- [ ] **Step 2: Run** `php artisan test --filter='BancoPreguntasAsistenteTest|VistasReglasTest'`. Expected: FAIL. Las preguntas 61 a 63 no traen la vista; la #64 puede elegir `anulaciones-validacion`.
- [ ] **Step 3: Implement** `elegirVista()` y el cambio de `fueraDeAlcance()`. Si la #64 da falsa, se corrige con las palabras de `anulaciones-validacion` o con `fueraDeAlcance`, nunca cambiando el banco.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Asistente`. Expected: PASS, con cero falsas.
- [ ] **Step 5: Commit** «Asistente: las reglas eligen la vista y el banco la exige».

### Task 5: Haiku elige y FoliOS valida la vista; el evaluador la compara

**Files:**
- Modify:
  - `app/Services/Asistente/Haiku/HerramientasHaiku.php`;
  - `app/Services/Asistente/Haiku/ValidadorLlamadaHaiku.php`;
  - `app/Services/Asistente/Haiku/InterpreteHaiku.php`;
  - `app/Services/Asistente/ServicioAsistente.php`: los casos de Haiku usan `OpcionConsulta`;
  - `app/Services/Asistente/EvaluadorBancoAsistente.php`;
  - `app/Console/Commands/EvaluarAsistente.php`.
- Test: `tests/Feature/Asistente/InterpreteHaikuTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3 y 4.
- Produces:
  - `HerramientasHaiku::definir(User $usuario, array $consultas): array`:
    - `consultar` lleva sus propiedades en este orden: `pide`, `consulta`, `vista`, `sin_cubrir` y el resto;
    - `vista` es un enum con las claves únicas de las vistas visibles, más `general`;
    - en `pedir_aclaracion`, cada opción es `{consulta, vista}`, las dos obligatorias;
    - el catálogo lista cada tema y, si tiene más de una vista, sus vistas con descripción, filtros y orden.
  - Prompt:
    - la regla 1 cambia «rankings» por «rankings que ninguna vista ordenada responde»;
    - agrega: «En `vista`, elige la vista del tema que responde la pregunta. `general` solo si el tema no tiene vistas. Una vista con orden responde "los más antiguos" o "los que más".».
  - `ValidadorLlamadaHaiku::validar(array $entrada, array $permitidas, array $fichas, User $usuario)`:
    - devuelve `{consulta, vista, parametros, sin_cubrir}`;
    - rechaza con «la vista elegida no es de esa consulta», incluido `general` en un tema de varias vistas;
    - rechaza con «no tienes acceso a esa vista»;
    - `filtrosSinCubrir()` usa `$vista->filtros`.
  - `InterpreteHaiku::interpretar()`:
    - suma `vista` (`?VistaConsulta`) al resultado;
    - `opciones` pasa a ser `list<OpcionConsulta>`;
    - la aclaración descarta los pares inválidos.
  - El evaluador:
    - suma `vista_elegida`;
    - la pregunta es `correcta` solo si coinciden la consulta y la `vista` del banco, cuando la trae;
    - con Haiku, la columna *Elegida* muestra `clave · vista` cuando la vista no es `general`.

- [ ] **Step 1: Write the failing tests** in `InterpreteHaikuTest`:
  - `test_haiku_elige_tema_y_vista`: `consultar` con `{consulta: 'guias-envases', vista: 'confirmadas', periodo_tipo: 'jornada', desde: '2026-11-21'}` da una respuesta con `data.parametros.vista` = `confirmadas`.
  - `test_general_en_un_tema_con_vistas_no_se_ejecuta`: `{consulta: 'guias-envases', vista: 'general'}` da `sugerencias`, con un mensaje que contiene «la vista elegida no es de esa consulta».
  - `test_una_vista_de_otro_tema_no_se_ejecuta`: `{consulta: 'kilos-recepciones', vista: 'borrador'}` da `sugerencias`.
  - `test_la_aclaracion_ofrece_temas_y_vistas`: `pedir_aclaracion` con `[{guias-envases, sin-numero}, {kilos-recepciones, general}]` da sugerencias con los títulos «Guías de despacho de envases · Sin N° de guía emitida» y «Kilos recepciones».
  - `test_las_herramientas_cumplen_los_limites_de_la_api` actualizada: las primeras cuatro propiedades son `['pide', 'consulta', 'vista', 'sin_cubrir']`, y el enum de `vista` contiene `general`, `borrador` y `sin-numero`.
  - En las respuestas grabadas ya existentes, `consultar()` agrega `'vista' => 'general'` por defecto.
- [ ] **Step 2: Run** `php artisan test --filter=InterpreteHaikuTest`. Expected: FAIL.
- [ ] **Step 3: Implement** según *Interfaces*.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Asistente`. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: Haiku elige la vista y FoliOS la valida».

### Task 6: Documentación y verificación completa

**Files:**
- Modify: `docs/ASISTENTE_OFICINA.md`
  - fila de `guias-envases` con sus vistas, en lugar de `despachos-envases-pendientes`;
  - sección «Temas y vistas»: cómo eligen las reglas, el parámetro `vista` de la API, `vista` en `consultar` y en el banco.

- [ ] **Step 1: Update docs** según la lista de arriba.
- [ ] **Step 2: Run** `vendor/bin/pint --test && php artisan test tests/Feature/Asistente && npm run test:js`. Expected: todo en verde.
- [ ] **Step 3: Commit** «Asistente: documentación de temas y vistas». Después, push y PR hacia main, con el cuerpo de PR de la sesión.
