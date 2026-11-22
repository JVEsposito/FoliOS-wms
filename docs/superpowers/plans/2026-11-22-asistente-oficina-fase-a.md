# Asistente de Oficina, fase A (sin IA): plan de implementación

> **Para agentes:** sub-skill requerido: superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans. Los pasos usan casillas (`- [ ]`).

**Objetivo:** un asistente de solo lectura en Oficina que responde en español, sin IA, con el catálogo de consultas y la revisión de taras del diseño.

**Arquitectura:**
- Un catálogo de consultas (`app/Services/Asistente/Consultas/*`) reutiliza los servicios de cada dominio.
- Un intérprete por reglas (`InterpreteReglasAsistente`) elige la consulta y sus parámetros a partir del texto.
- `ServicioAsistente` responde, deja registro y nunca termina en blanco.
- El gajo flotante de Oficina llama a `/api/asistente/*`.
- La fase B agrega Haiku sobre el mismo catálogo, sin cambiar los contratos de esta fase.

**Stack:** Laravel 13, PHP 8.3, Blade + JS de Oficina (`resources/js/office-*.js`), PHPUnit y `node --test`.

**Diseño:** `docs/superpowers/specs/2026-11-22-asistente-oficina-design.md`

**Fuera de esta fase (fase B):**
- intérprete Haiku, modo mixto y filtrado de salida hacia Anthropic;
- topes de gasto;
- pantalla de configuración en Administración.

Esta fase deja lista la API de configuración, pero no su pantalla.

## Restricciones globales

- Solo lectura: ninguna consulta escribe datos de dominio. Lo único que se escribe es el registro de preguntas.
- Toda autorización se valida en Laravel. Cada consulta exige el mismo permiso que su oficina de origen (`CatalogoOficinas`).
- El módulo se habilita por perfil (`CatalogoModulosAcceso`). Un usuario con rol `cliente` nunca lo tiene.
- Temporada: las consultas leen la temporada activa por defecto (`ServicioTemporadaActiva`).
- Sin borrado físico: `PreguntaAsistente` usa `ImpideEliminacionFisica`.
- Rango inicial de neto por tote: 8,2 a 9,5 kg. Se guarda en la base, nunca en el código de la interfaz.
- Margen de esponjas e histórico por CSG: media ± k·desviación estándar, con k = 2 configurable. Si hay menos de 10 muestras, no se marca nada y se informa que la historia es insuficiente.
- Interfaz en Gajos vivos: códigos en mono con el prefijo apagado (`officeCode`), estados con glifo (● ◐ ○ ◆ ■) y cantidades en celdas (`palletCells`).
- El gajo no se abre solo ni avisa por iniciativa propia.
- Textos en español neutro, sin voseo.
- `vendor/bin/pint --test` limpio. La suite completa pasa, incluido `InicioFoliosTest`, que compara el catálogo de oficinas con el menú.

## Foco de revisión

1. **Pregunta vacía, solo espacios o de más de 500 caracteres:** responde 422 con un mensaje claro y no queda registro.
2. **Usuario sin permiso del dominio** que pregunta por él, o que ejecuta la consulta directo por clave: responde 403 en la ejecución directa. Por texto, la consulta nunca aparece como respuesta ni como sugerencia.
3. **Fecha imposible** («31-02») **o texto sin ninguna entidad:** no falla; el resultado son sugerencias.
4. **Recepción con cero totes o sin `peso_neto_por_envase`:** no hay división por cero y no se marca.
5. **Temporada sin datos en el rango:** la respuesta es de estado `sin_datos` con una explicación, nunca un error ni una lista vacía muda.

---

### Tarea 1: Módulo, permisos y gates

**Archivos:**
- Modificar `app/Services/Autorizacion/CatalogoModulosAcceso.php`.
- Modificar `app/Services/Autorizacion/AlcanceOperacionalUsuario.php`.
- Modificar `app/Providers/AppServiceProvider.php`.
- Prueba: `tests/Feature/Api/AsistentePermisosTest.php`.

**Interfaces:**
- Produce las constantes:
  - `CatalogoModulosAcceso::OFICINA_ASISTENTE = 'administracion.asistente'`;
  - `CatalogoModulosAcceso::OFICINA_ASISTENTE_CONFIGURACION = 'administracion.asistente-configuracion'`.
- Produce `AlcanceOperacionalUsuario::puedeUsarAsistente(User): bool`, con todos los roles internos (todos los casos de `RolUsuario` salvo `Cliente`) sobre `OFICINA_ASISTENTE`.
- Produce `AlcanceOperacionalUsuario::puedeAdministrarAsistente(User): bool`, con `Administrador` sobre `OFICINA_ASISTENTE_CONFIGURACION`.
- Produce las claves `puede_usar_asistente` y `puede_administrar_asistente` en `capacidadesApi`.
- Produce los gates `usar-asistente` y `administrar-asistente`.

- [ ] **Paso 1: prueba que falla.** Casos:
  - `test_perfil_con_modulo_asistente_puede_usarlo`;
  - `test_perfil_sin_modulo_no_puede`;
  - `test_rol_cliente_nunca_puede_aunque_el_perfil_tenga_el_modulo`;
  - `test_solo_administrador_con_modulo_de_configuracion_administra`.

  Cada caso afirma `Gate::forUser($u)->allows(...)` y la clave correspondiente en `capacidadesApi($u)`.
- [ ] **Paso 2:** correr `php artisan test --filter=AsistentePermisosTest` y verificar que falla.
- [ ] **Paso 3: implementar.** Agregar los dos módulos al grupo `administracion` de `macromodulos()` como «Asistente» y «Configuración del asistente», con descripciones en español. El módulo de uso entra en `modulosPredeterminados` para los roles internos.
- [ ] **Paso 4:** correr la prueba y verificar que pasa. Después correr `php artisan test --filter=InicioFoliosTest`; debe pasar.
- [ ] **Paso 5:** commit `feat(asistente): módulo y permisos por perfil`.

### Tarea 2: Registro de preguntas y configuración

**Archivos:**
- Crear `database/migrations/2026_11_22_120000_crear_tablas_asistente.php`.
- Crear `app/Models/PreguntaAsistente.php`, `app/Models/RangoTaraAsistente.php` y `app/Models/ConfiguracionAsistente.php`.
- Prueba: `tests/Feature/Asistente/RegistroAsistenteTest.php`.

**Interfaces:**
- Tabla `asistente_preguntas`:
  - `uuid id`, `user_id` (FK a `users`), `texto` (string 500);
  - `interprete` (string 20; `reglas` en esta fase), `consulta` (string 60, nullable), `parametros` (json, nullable);
  - `estado` (string 20: `respondida` | `sin_datos` | `sugerencias` | `rechazada`), `confianza` (decimal 4,3, nullable);
  - `created_at`.

  El modelo usa `ImpideEliminacionFisica` y no tiene `updated_at`.
- Tabla `asistente_rangos_tara`: `uuid id`, `tipo_envase` (string 30, valores de `TipoEnvaseRomana`), `especie_validacion_id` (nullable; null significa todas las especies), `minimo_kg` y `maximo_kg` (decimal 6,3), `activo`, `timestamps`.

  La migración siembra `totes`, especie null, 8.200 a 9.500.
- Tabla `asistente_configuracion`: una sola fila, con `margen_desviaciones` (decimal 4,2, por defecto 2.00), `modo` (string 20, por defecto `sin_ia`) y `timestamps`. La migración siembra esa fila.
- Produce:
  - `RangoTaraAsistente::vigentePara(string $tipoEnvase, ?string $especieId): ?self`, que prefiere el de la especie y si no hay usa el general;
  - `ConfiguracionAsistente::actual(): self`.

- [ ] **Paso 1: prueba que falla.** Casos:
  - `test_migracion_siembra_rango_tote_8_2_a_9_5`;
  - `test_rango_de_especie_tiene_prioridad_sobre_el_general`;
  - `test_pregunta_no_se_puede_eliminar`, que espera la excepción del trait;
  - `test_configuracion_actual_tiene_margen_2`.
- [ ] **Paso 2:** correr la prueba y verificar que falla.
- [ ] **Paso 3:** implementar la migración y los modelos.
- [ ] **Paso 4:** correr la prueba y verificar que pasa.
- [ ] **Paso 5:** commit `feat(asistente): registro de preguntas y configuración`.

### Tarea 3: Contrato del catálogo y reconocimiento de entidades

**Archivos:**
- Crear en `app/Services/Asistente/`:
  - `Consultas/ConsultaAsistente.php` (interfaz);
  - `ParametrosConsulta.php`;
  - `ResultadoConsulta.php`;
  - `CatalogoConsultasAsistente.php`;
  - `ReconocedorEntidades.php`;
  - `TextoNormalizado.php`.
- Pruebas: `tests/Unit/Asistente/ReconocedorEntidadesTest.php` y `tests/Feature/Asistente/CatalogoConsultasTest.php`.

**Interfaces:**
- `interface ConsultaAsistente`:
  - `clave(): string`, `titulo(): string`, `descripcion(): string`;
  - `dominio(): string`, con uno de `materia-prima`, `frigorifico`, `materiales`, `consultas`, `gerencia`, `administracion`;
  - `sensible(): bool`, que es `false` en todas las de esta fase;
  - `palabrasClave(): array<int, string>`, ya normalizadas;
  - `puedeEjecutar(User $u): bool`;
  - `ejecutar(User $u, ParametrosConsulta $p): ResultadoConsulta`.
- `final readonly class ParametrosConsulta`:
  - `?CarbonImmutable $desde`, `?CarbonImmutable $hasta`;
  - `?string $clienteId`, `?string $csgId`, `?string $especieId`, `?string $codigo`;
  - `bool $temporadaAnterior = false`.
- `final readonly class ResultadoConsulta`:
  - `string $estado` (`respondida` | `sin_datos`), `string $titulo`, `string $resumen`;
  - `array $cifras`, de la forma `list<array{etiqueta:string, valor:int|float|string, unidad:?string}>`;
  - `array $columnas`, de la forma `list<array{clave:string, titulo:string, tipo?:string}>`;
  - `array $filas`, con un máximo de 50;
  - `?string $enlace`, la ruta de Oficina.

  Tiene `toArray()`.
- `CatalogoConsultasAsistente`:
  - `todas(): list<ConsultaAsistente>`;
  - `disponiblesPara(User): list<ConsultaAsistente>`;
  - `buscar(string $clave): ?ConsultaAsistente`.

  Recibe las consultas por constructor, tagged en el contenedor como `asistente.consultas`.
- `ReconocedorEntidades::reconocer(string $texto): array{desde:?CarbonImmutable, hasta:?CarbonImmutable, cliente_id:?string, csg_id:?string, especie_id:?string, codigo:?string, temporada_anterior:bool, palabras:list<string>}`.
  - Fechas en la zona `config('app.operational_timezone')`:
    - «hoy», «ayer», «esta semana» (de lunes a hoy), «este mes», «la semana pasada», «el mes pasado»;
    - `dd-mm` y `dd-mm-aaaa`, también con «/». Una fecha imposible se ignora.
  - Códigos: `REC-\d+(-\d+)?`, `CAR-\d+`, `PF-\d+`, y un número de folio que exista en `folios.numero_folio`.
  - Clientes, CSG y especies: coincidencia contra código o nombre normalizado de `Cliente`, `CsgValidacion` y `EspecieValidacion`, de la temporada activa cuando corresponda. La coincidencia acepta distancia de Levenshtein ≤ 1 en palabras de 5 letras o más.
  - «temporada pasada» o «temporada anterior» activa `temporada_anterior`.
- `TextoNormalizado::de(string): string`: minúsculas, sin tildes y con espacios colapsados.

- [ ] **Paso 1: pruebas que fallan.**
  - `test_reconoce_hoy_ayer_y_esta_semana` (con `CarbonImmutable::setTestNow('2026-11-22 10:00')`, afirma límites exactos en la zona operativa).
  - `test_ignora_fecha_imposible_31_02`.
  - `test_reconoce_codigos_rec_car_pf`.
  - `test_reconoce_cliente_por_nombre_con_un_error_de_tipeo`.
  - `test_temporada_pasada`.
  - `test_catalogo_solo_entrega_consultas_permitidas`, con una consulta falsa registrada que exige un gate denegado.
- [ ] **Paso 2:** correr las pruebas y verificar que fallan.
- [ ] **Paso 3:** implementar.
- [ ] **Paso 4:** correr las pruebas y verificar que pasan.
- [ ] **Paso 5:** commit `feat(asistente): contrato del catálogo y entidades`.

### Tarea 4: Consultas de Romana y materia prima

**Archivos:**
- Crear en `app/Services/Asistente/Consultas/`: `TiemposRomana.php`, `KilosRecepciones.php` y `KilosPorCsg.php`.
- Prueba: `tests/Feature/Asistente/ConsultasRomanaTest.php`.
- Fixture: el patrón de `tests/Feature/Api/InformeRecepcionesCsgTest.php::recepcion()`.

**Interfaces:** todas implementan `ConsultaAsistente`. El rango por defecto es «hoy». Las recepciones anuladas o eliminadas (`eliminada_at`) se excluyen.

- **`tiempos-romana`**:
  - Permiso: `puedeConsultarRomana`.
  - Para cerradas: tiempo en planta = `salida_at − ingreso_at`.
  - Para `en_bascula_salida`: espera de destare = ahora − `ingreso_confirmado_at`.
  - Cifras: promedio y máximo en minutos, cantidad de recepciones y cantidad esperando destare.
  - Filas: las 10 más lentas (`numero_recepcion`, cliente, patente, minutos, estado).
  - Enlace: `/oficina/romana`.
  - Palabras clave: tiempo, demora, planta, destare, espera, romana, camion.
- **`kilos-recepciones`**:
  - Permiso: `puedeConsultarRomana`.
  - Suma `peso_bruto`, `peso_tara` y `peso_neto` de las cerradas, agrupadas por cliente.
  - Filas: una por cliente. Cifras: el total.
  - Palabras clave: kilos, kg, neto, bruto, recepcion, recibido.
- **`kilos-por-csg`**:
  - Permiso: `puedeConsultarMateriaPrima`.
  - Suma `lotes_materia_prima.kilos_netos_confirmados` de los lotes no anulados cuyas recepciones ingresaron en el rango, agrupada por `csg_snapshot`.
  - Con `clienteId`, filtra por cliente.
  - Palabras clave: csg, productor, kilos.

- [ ] **Paso 1: pruebas que fallan.**
  - `test_tiempos_romana_calcula_promedio_y_maximo_en_minutos`: dos recepciones de 90 y 30 minutos dan promedio 60 y máximo 90.
  - `test_tiempos_romana_cuenta_las_que_esperan_destare`.
  - `test_kilos_recepciones_suma_por_cliente_y_excluye_anuladas`.
  - `test_kilos_por_csg_agrupa_por_csg_snapshot`.
  - `test_sin_recepciones_en_el_rango_devuelve_sin_datos` (foco 5).
  - `test_usuario_sin_permiso_de_romana_no_puede_ejecutar`.
- [ ] **Paso 2:** correr las pruebas y verificar que fallan.
- [ ] **Paso 3:** implementar y registrar las clases con el tag `asistente.consultas` en un `AsistenteServiceProvider` nuevo, registrado en `bootstrap/providers.php`.
- [ ] **Paso 4:** correr las pruebas y verificar que pasan.
- [ ] **Paso 5:** commit `feat(asistente): consultas de Romana y kilos por CSG`.

### Tarea 5: Consultas de Frigorífico y Materiales

**Archivos:**
- Crear en `app/Services/Asistente/Consultas/`: `CajasPt.php`, `FormatosPt.php`, `SaldosPt.php`, `Repaletizajes.php`, `TiemposPrefrio.php`, `TiemposCargas.php`, `ConsumosOt.php` y `ExpedientePorCodigo.php`.
- Prueba: `tests/Feature/Asistente/ConsultasFrigorificoMaterialesTest.php`.

**Interfaces:**
- **`cajas-pt`, `formatos-pt` y `saldos-pt`**:
  - Fuente: `ServicioExistencias::filas(ServicioExistencias::PRODUCTO_TERMINADO, ['cliente' => ...])`.
  - Permiso: `ServicioExistencias::puedeConsultar($u, PRODUCTO_TERMINADO)`.
  - `cajas-pt` agrupa la suma de `cantidad_cajas` por cliente y especie. `formatos-pt` la agrupa por `envase`, que es el formato de embalaje. `saldos-pt` filtra `tipo_bulto` saldo y lista folio, cajas, cámara y posición.
  - Palabras clave:
    - `cajas-pt`: cajas, pallets, pt, existencia;
    - `formatos-pt`: formato, embalaje, envase;
    - `saldos-pt`: saldo, saldos, incompleto.
- **`repaletizajes`**:
  - Permiso: `puedeConsultarRepaletizajes`.
  - Toma los confirmados no anulados con `fecha_operacional` en el rango.
  - Cifras: cantidad y suma de `cantidad_resultante`. Filas: folio resultante y fecha.
  - Palabras clave: repa, repas, repaletizaje.
- **`tiempos-prefrio`**:
  - Permiso: `puedeConsultarPrefrio`.
  - Toma los procesos finalizados en el rango: duración = `finalizado_at − iniciado_at` contra `duracion_objetivo_minutos`.
  - Cifras: promedio y cantidad sobre el objetivo. Filas: túnel, minutos, objetivo y exceso.
  - Palabras clave: prefrio, tunel, proceso, enfriado.
- **`tiempos-cargas`**:
  - Permiso: `puedeConsultarCargas`.
  - Toma las cargas cerradas en el rango: duración = `cerrada_at − publicada_at`.
  - Palabras clave: carga, cargas, despacho, anden, carguio.
- **`consumos-ot`**:
  - Permiso: `puedeConsultarTransformacionesMateriales`, el de «Consumos por OT» en `CatalogoOficinas`.
  - Suma `ImputacionConsumoMaterial.cantidad` y `cantidad_merma` por orden en el rango.
  - Palabras clave: consumo, consumos, ot, orden, merma, estandar.
- **`expediente-por-codigo`**:
  - Permiso: `puede_consultar_oficina_consultas`, el de la Búsqueda Operacional.
  - Delega en `ServicioConsultaOperacional::buscar($codigo)` y entrega los resultados como filas, con enlace a la oficina de cada uno.
  - El intérprete la usa cuando reconoce un código.

- [ ] **Paso 1: pruebas que fallan.**
  - `test_cajas_pt_suma_por_cliente_y_especie`.
  - `test_formatos_pt_agrupa_por_embalaje`.
  - `test_saldos_pt_solo_lista_saldos`.
  - `test_repaletizajes_excluye_anulados`.
  - `test_tiempos_prefrio_marca_los_que_exceden_objetivo`: un proceso de 130 min con objetivo de 120 cuenta como 1 sobre objetivo.
  - `test_tiempos_cargas_promedio`.
  - `test_consumos_ot_suma_por_orden`.
  - `test_expediente_por_codigo_delega_en_busqueda_operacional`.
  - `test_cada_consulta_rechaza_usuario_sin_su_permiso`: data provider sobre las ocho claves (foco 2).
- [ ] **Paso 2:** correr las pruebas y verificar que fallan.
- [ ] **Paso 3:** implementar y registrar con el tag.
- [ ] **Paso 4:** correr las pruebas y verificar que pasan.
- [ ] **Paso 5:** commit `feat(asistente): consultas de frigorífico y materiales`.

### Tarea 6: Revisión de tara y destare

**Archivos:**
- Crear `app/Services/Asistente/RevisionTaras.php` y `app/Services/Asistente/Consultas/RevisarTaras.php`.
- Prueba: `tests/Feature/Asistente/RevisionTarasTest.php`.
- Declarar `$tarasRomanaHistoricas = false` donde la prueba use taras oficiales (ver `tests/TestCase.php`).

**Interfaces:**
- `RevisionTaras::revisar(Temporada $t, CarbonImmutable $desde, CarbonImmutable $hasta, ?string $clienteId): list<array{regla:string, recepcion:string, lote:?string, detalle:string, valor:float, referencia:string, sentido:'bajo'|'sobre'|'distinta'}>`.
- Reglas, cada una con el valor de `regla`:
  - **`neto_por_tote`:** recepciones cerradas con `tipo_envase_calculo_neto = 'totes'` y `peso_neto_por_envase` no nulo, fuera de `RangoTaraAsistente::vigentePara('totes', especie)`. La especie se toma de los lotes de la recepción cuando todos comparten una; si no, se usa el rango general.
  - **`tara_envase`:** `detallesEnvases` de bins o totes cuyo `tara_unitaria_salida` difiere de la tara oficial del `taras_romana_snapshot` de la recepción. La lectura del snapshot sigue la estructura que define `ServicioTarasRomana`.
  - **`esponja`:** `detallesEnvases` de tipo `esponjas` o `esponja_tote` con `tara_unitaria_salida` fuera de la media ± k·desviación de ese tipo en la temporada, excluyendo la propia muestra. Con menos de 10 muestras no se marca.
  - **`historico_csg`:** lotes con `envase_primario` tote. El valor es `kilos_netos_confirmados / cantidad_envases_primarios`, comparado con la media ± k·desviación del mismo `csg_validacion_id` en la temporada (sin el lote) y en la anterior, cuando exista. Con menos de 10 muestras no se marca.
- `k = ConfiguracionAsistente::actual()->margen_desviaciones`.
- Consulta `revisar-taras`:
  - Permiso: `puedeConsultarRomana`.
  - Cifras: cantidad por regla. Filas: los hallazgos.
  - Palabras clave: tara, destare, revisa, revisar, error, raro, sospechoso, esponja, tote.

- [ ] **Paso 1: pruebas que fallan.**
  - `test_neto_por_tote_7_9_se_marca_bajo_el_rango`.
  - `test_neto_por_tote_9_0_no_se_marca`.
  - `test_rango_de_especie_reemplaza_al_general`.
  - `test_tara_de_tote_distinta_a_la_oficial_se_marca`.
  - `test_esponja_fuera_de_dos_desviaciones_se_marca`.
  - `test_esponja_con_menos_de_10_muestras_no_se_marca`.
  - `test_historico_csg_marca_lote_alejado_de_su_csg`.
  - `test_recepcion_con_cero_totes_no_divide_por_cero` (foco 4).
- [ ] **Paso 2:** correr las pruebas y verificar que fallan.
- [ ] **Paso 3:** implementar. La media y la desviación se calculan en PHP sobre las muestras de la temporada. Basta con una consulta por regla, sin bucles N+1.
- [ ] **Paso 4:** correr las pruebas y verificar que pasan.
- [ ] **Paso 5:** commit `feat(asistente): revisión de tara y destare`.

### Tarea 7: Intérprete por reglas, servicio y API

**Archivos:**
- Crear `app/Services/Asistente/InterpreteReglasAsistente.php` y `app/Services/Asistente/ServicioAsistente.php`.
- Crear `app/Http/Controllers/Api/AsistenteController.php` y `app/Http/Requests/PreguntaAsistenteRequest.php`.
- Crear `routes/asistente.php` y registrarla en `bootstrap/app.php` como las demás.
- Prueba: `tests/Feature/Api/AsistenteApiTest.php`.

**Interfaces:**
- `InterpreteReglasAsistente::interpretar(User $u, string $texto): array{consulta:?ConsultaAsistente, parametros:ParametrosConsulta, confianza:float, alternativas:list<ConsultaAsistente>}`.
  - Puntaje por consulta permitida = (palabras clave presentes) / (palabras significativas del texto, con un mínimo de 1).
  - Si se reconoció un código, gana `expediente-por-codigo` con confianza 1.
  - Gana la consulta de mayor puntaje si es ≥ 0,34. Si no, `consulta` es null y `alternativas` trae las 3 de mayor puntaje; si todas valen 0, las 3 primeras del catálogo permitido.
- `ServicioAsistente::preguntar(User $u, string $texto): array{tipo:'respuesta'|'sugerencias', modo:'sin_ia', resultado:?array, sugerencias:list<array{clave:string, titulo:string, parametros:array}>, busqueda:string}`.
  - Siempre registra una `PreguntaAsistente`.
  - Con sugerencias, `busqueda` lleva el texto para la búsqueda operacional.
- `ServicioAsistente::ejecutar(User $u, string $clave, ParametrosConsulta $p): ResultadoConsulta`. Lanza `OperacionNoAutorizada` (403) si la consulta no está permitida.
- Rutas, con `auth:sanctum`, el gate `usar-asistente` y throttle de 30 por minuto:
  - `POST /api/asistente/preguntas` con `{texto}`, validado `required|string|max:500` después de recortar espacios;
  - `GET /api/asistente/consultas` devuelve el catálogo permitido (`clave`, `titulo`, `descripcion`);
  - `POST /api/asistente/consultas/{clave}` con `{desde?, hasta?, cliente_id?, csg_id?, especie_id?, codigo?}`.
- Con el gate `administrar-asistente`:
  - `GET /api/asistente/configuracion` y `PUT /api/asistente/configuracion`, con `{margen_desviaciones (0.5–5), rangos:[{tipo_envase, especie_validacion_id?, minimo_kg, maximo_kg, activo}]}` y `minimo_kg < maximo_kg`;
  - `GET /api/asistente/preguntas?estado=sugerencias`, paginado de 50 en 50.

- [ ] **Paso 1: pruebas que fallan.**
  - `test_pregunta_tiempos_de_romana_hoy_responde_tiempos_romana`.
  - `test_pregunta_con_codigo_rec_abre_expediente`.
  - `test_pregunta_sin_sentido_devuelve_tres_sugerencias_y_busqueda` (foco 3).
  - `test_texto_vacio_o_espacios_o_501_caracteres_da_422_y_no_registra` (foco 1).
  - `test_sugerencias_excluyen_consultas_sin_permiso` (foco 2).
  - `test_ejecutar_consulta_no_permitida_da_403`.
  - `test_usuario_sin_modulo_recibe_403`.
  - `test_cliente_recibe_403`.
  - `test_configuracion_solo_para_administrar_asistente_y_valida_minimo_menor_que_maximo`.
  - `test_cada_pregunta_queda_registrada_con_estado`.
- [ ] **Paso 2:** correr las pruebas y verificar que fallan.
- [ ] **Paso 3:** implementar. El controlador es delgado y la lógica vive en `ServicioAsistente`.
- [ ] **Paso 4:** correr las pruebas y verificar que pasan.
- [ ] **Paso 5:** commit `feat(asistente): intérprete por reglas y API`.

### Tarea 8: El gajo asistente en Oficina

**Archivos:**
- Crear `resources/views/components/folios/asistente.blade.php`.
- Modificar `resources/views/components/office/navigation.blade.php` para incluir el componente al final.
- Crear `resources/js/office-asistente.js` y `resources/js/shared/asistente-ui.js` (funciones puras).
- Modificar `resources/js/office-navigation.js` para importar `./office-asistente.js`.
- Crear `resources/css/office-asistente.css` e importarlo desde `resources/css/office.css`.
- Prueba: `tests/JavaScript/AsistenteUiTest.mjs`.

**Interfaces:**
- `asistente-ui.js` exporta:
  - `esquinaMasCercana(x, y, ancho, alto): 'arriba-izquierda'|'arriba-derecha'|'abajo-izquierda'|'abajo-derecha'`;
  - `renderResultado(resultado, escape): string`, que arma el HTML de cifras y filas, aplica `officeCode` a los códigos y escapa todo texto;
  - `renderSugerencias(sugerencias, escape): string`.
- **Comportamiento:**
  - Solo se monta si `usuario.capacidades.puede_usar_asistente` es verdadero. No existe en el portal de clientes.
  - El botón es la hoja del gajo (`<x-folios.gajo :area="...">` con el área de la oficina), de 48 px, del color `var(--area-ink)`.
  - Está cerrado por defecto, abajo a la derecha. El usuario lo arrastra a otra esquina y la posición queda en `localStorage` por usuario, con lectura protegida por try/catch.
  - Abre y cierra con un clic, con `Alt+A` o con `Escape`.
  - El panel lateral, de 380 px, tiene historial, un campo con envío por Enter y un rótulo de modo «Sin IA».
  - Las sugerencias son botones que llaman a `POST /api/asistente/consultas/{clave}`.
  - Usa el token de Oficina (`estiba_wms_office_token`) como `Authorization: Bearer`.
  - Nunca se abre solo.
  - Es accesible: `aria-expanded` en el botón, `role="dialog"` con etiqueta en el panel y foco al campo al abrir.

- [ ] **Paso 1: pruebas que fallan.**
  - `esquinaMasCercana` con los cuatro cuadrantes.
  - `renderResultado` escapa `<script>` en una fila.
  - `renderResultado` con `estado: 'sin_datos'` muestra el resumen.
  - `renderSugerencias` con tres entradas produce tres botones.
- [ ] **Paso 2:** correr `npm run test:js` y verificar que fallan.
- [ ] **Paso 3:** implementar el componente, el JS y el CSS (tokens existentes, sin colores nuevos). Leer antes `C:/Users/user/.claude/plugins/cache/impeccable/impeccable/4.10.0/skills/impeccable/reference/craft-floor.md`.
- [ ] **Paso 4:** correr `npm run test:js` y `npm run build`, y verificar que pasan. Después correr el detector `impeccable detect --json` sobre los archivos nuevos y corregir lo que corresponda.
- [ ] **Paso 5:** commit `feat(asistente): gajo asistente en Oficina`.

### Tarea 9: Documentación y verificación final

**Archivos:**
- Crear `docs/ASISTENTE_OFICINA.md`, con qué responde, permisos, rangos, registro, API y fase B pendiente.
- Modificar `README.md`, en «Documentación de producto», para enlazarlo.

- [ ] **Paso 1:** escribir la documentación.
- [ ] **Paso 2:** correr `vendor/bin/pint --test`, `php artisan test`, `npm run test:js` y `npm run build`. Todo debe pasar.
- [ ] **Paso 3:** commit `docs(asistente): documento del módulo`.
