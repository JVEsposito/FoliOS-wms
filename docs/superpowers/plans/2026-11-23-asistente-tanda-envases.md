# Asistente: tanda de envases (cuenta, existencia y destare) — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el asistente responda sobre la cuenta y la existencia de envases con las mismas cifras que la oficina de Cuenta Envases. Agrega:
- el tema `cuenta-envases`, con las vistas `saldos`, `por-productor`, `estado-cuenta` y `descuadres`;
- el tema `existencia-envases`, con `por-propiedad` y `propios-entregados`;
- la vista `salidas-destare` en `envases-recibidos`.

**Architecture:** Cada vista llama al mismo servicio que la oficina. Dos cálculos que hoy viven en controladores se mueven a sus servicios y los controladores pasan a usarlos:
- los saldos, de `CuentaCorrienteEnvaseController::index` a `ServicioCuentaCorrienteEnvases::saldos()`;
- las salidas pendientes de conciliar, de `ConciliacionSalidaEnvaseController::index` a `ServicioConciliacionSalidaEnvases::pendientes()`.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit sobre MySQL.

**Spec:** `docs/superpowers/specs/2026-11-23-asistente-consultas-por-tema-design.md`, tanda 1. Las vistas `movimientos`, `devoluciones` y `reasignaciones` van en el PR siguiente, para que este se pueda revisar.

## Global Constraints

- Mismas cifras que la oficina: cada vista reutiliza el servicio de origen. Un saldo positivo significa que la planta debe envases al cliente; uno negativo, que el cliente los debe a la planta.
- Permisos:
  - `cuenta-envases` y `existencia-envases` exigen `puedeConsultarCuentaEnvases`;
  - `descuadres` exige además la puerta `ajustar-cuenta-envases`, igual que su pantalla;
  - `salidas-destare` exige el permiso actual de `envases-recibidos`.
- Cero falsas en el banco. Cada vista nueva suma dos preguntas que debe responder y una parecida que no.
- Español neutro; `pint --test` en verde.

## Review Focus

1. **«¿Cuántos bins nos debe el CSG 104512?»**, con CSG y sin cliente, va a `por-productor`, no a `saldos`. Se elige la vista que puede aplicar el filtro reconocido. Lo prueba la Task 2.
2. **CSG con dos clientes:** `por-productor` muestra una fila por cliente; no suma clientes distintos. Lo prueba la Task 3.
3. **`estado-cuenta` sin cliente:** responde pidiendo el cliente, sin cifras de todos. Lo prueba la Task 3.
4. **Usuario sin `ajustar-cuenta-envases`:** no ve `descuadres` en el catálogo, y la API la rechaza con 403. Lo prueba la Task 4.
5. **Una pareja de descuadre que requiere revisión individual** (la previsualización lanza `ConflictoOperacion`): se lista como «requiere revisión individual», sin romper la respuesta. Lo prueba la Task 4.

---

### Task 1: Pendientes de #741

**Files:** Modify `app/Services/Asistente/ServicioAsistente.php` y `app/Services/Asistente/InterpreteReglasAsistente.php`. Test: `tests/Feature/Asistente/VistasAsistenteApiTest.php` y `VistasReglasTest.php`.

**Interfaces:**
- `recomendar()` lanza `DomainException('Esa vista no admite recomendaciones.')` si `! $vista->admiteRecomendacion`.
- `elegirVista()`:
  - devuelve `[null, []]` si el usuario no ve ninguna vista; la respuesta queda en «sin consulta»;
  - antes de puntuar, si la pregunta reconoce cliente, CSG o especie, deja solo las vistas que aplican esos filtros, siempre que quede al menos una;
  - si queda una sola vista, la elige.

- [ ] **Step 1: Failing tests:**
  - `test_recomendar_una_vista_sin_recomendacion_es_regla_de_negocio`: con el modo mixto activo, `POST /api/asistente/recomendaciones/guias-envases` da 422.
  - `test_un_tema_sin_vistas_visibles_no_responde`: con `TemaDePrueba` y `puedeVer` siempre falso, la pregunta da `consulta` null y no lanza error.
  - `test_el_filtro_reconocido_elige_la_vista`: «bodegones de Exportadora Andina» en `TemaDePrueba` da la vista `clientes`.
- [ ] **Step 2: Run** `php artisan test --filter='VistasAsistenteApiTest|VistasReglasTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** la misma prueba. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: recomendación y elección de vista según la vista».

### Task 2: Saldos en el servicio y tema `cuenta-envases` (saldos)

**Files:**
- Modify: `app/Services/Envases/ServicioCuentaCorrienteEnvases.php` y `app/Http/Controllers/Api/CuentaCorrienteEnvaseController.php`.
- Create: `app/Services/Asistente/Consultas/CuentaEnvases.php`.
- Modify: `app/Providers/AsistenteServiceProvider.php`.
- Test: `tests/Feature/Asistente/CuentaEnvasesTest.php`.

**Interfaces:**
- `ServicioCuentaCorrienteEnvases::saldos(string $temporadaId, ?string $clienteId = null, ?string $tipoEnvase = null): list<array{cliente_id: string, cliente: array{codigo: string, nombre: string}, tipo_envase: string, saldo: int}>`. Es la misma consulta del `index`, que ahora la llama. El `index` sigue sumando `propios_entregados`.
- Tema `cuenta-envases`, «Cuenta de envases», dominio `envases`, vista por defecto `saldos`:
  - palabras del tema: `cuenta`, `deuda`; secundarias: `envases`, `envase`, `bins`, `bin`, `totes`, `tote`, `esponjas`;
  - vista `saldos`: palabras `saldo`, `saldos`, `debe`, `debemos`, `deben`; filtro `cliente`. Muestra la temporada activa, en filas cliente, envase, saldo y quién debe a quién («Planta debe» o «Cliente debe»).
  - Descripción del tema para Haiku: deja claro que no responde existencia física ni envases recibidos hoy.

- [ ] **Step 1: Failing tests** in `CuentaEnvasesTest`, con movimientos creados con `MovimientoEnvase::create`:
  - `test_saldos_suma_la_cuenta_por_cliente_y_envase`: +10 y −4 en bins de un cliente dan 6, «Planta debe».
  - `test_saldos_no_suma_compras_de_propios`: un movimiento con `signo_cuenta` 0 no aparece.
  - `test_la_oficina_y_el_asistente_dan_el_mismo_saldo`: `GET /api/envases/cuenta-corriente` (`index`) y la vista coinciden.
- [ ] **Step 2: Run** `php artisan test --filter=CuentaEnvasesTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test --filter='CuentaEnvasesTest|CuentaCorrienteEnvase'`. Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: saldos de la cuenta de envases».

### Task 3: Vistas `por-productor` y `estado-cuenta`

**Files:** Modify `app/Services/Asistente/Consultas/CuentaEnvases.php`. Test: `CuentaEnvasesTest`.

**Interfaces:**
- **`estado-cuenta`:**
  - palabras: `estado de cuenta`, `cartola`; filtros `periodo` y `cliente`;
  - sin cliente, responde con `ResultadoConsulta::sinDatos` y el texto «Dime de qué cliente: el estado de cuenta es por cliente.»;
  - con cliente, llama a `estadoCuenta($cliente, $temporada, $desde, $hasta)`. `$desde` y `$hasta` son las jornadas del periodo; con periodo `actual`, null, que es la temporada completa;
  - muestra por envase: saldo inicial, entradas, salidas y saldo final, con las mismas cifras del PDF.
- **`por-productor`:**
  - palabras: `productor`, `productores`, `por productor`, `por csg`; filtros `cliente` y `csg`;
  - sin cliente pero con CSG, toma como clientes los de `csg->productor->clientes` que el usuario puede ver, hasta 3;
  - de cada cliente usa `estadoCuenta(...)['resumen_csg']`, filtrado por el código del CSG si viene;
  - filas: cliente, CSG, envase y saldo final;
  - sin cliente ni CSG, responde pidiendo el cliente.

- [ ] **Step 1: Failing tests:**
  - `test_estado_cuenta_coincide_con_el_documento`
  - `test_estado_cuenta_sin_cliente_pide_el_cliente`
  - `test_por_productor_con_csg_busca_el_cliente`: con una devolución que trae `datos.devolucion.productor` = CSG 104512.
  - `test_por_productor_no_suma_clientes_distintos`
- [ ] **Step 2–4:** Run, implement, run. Expected: FAIL, luego PASS.
- [ ] **Step 5: Commit** «Asistente: estado de cuenta y saldo por productor».

### Task 4: Pendientes de conciliación en el servicio y vista `descuadres`

**Files:** Modify `app/Services/Envases/ServicioConciliacionSalidaEnvases.php`, `app/Http/Controllers/Api/ConciliacionSalidaEnvaseController.php` y `CuentaEnvases.php`. Test: `CuentaEnvasesTest`.

**Interfaces:**
- `ServicioConciliacionSalidaEnvases::pendientes(string $temporadaId, ?string $clienteId = null, ?string $buscar = null, bool $manual = false): Builder`:
  - es la consulta del `index`, sin paginar;
  - el `index` pagina sobre ella.
- **Vista `descuadres`:**
  - palabras: `descuadre`, `descuadres`, `diferencia`, `diferencias`, `doble salida`, `conciliar`; filtro `cliente`;
  - `puedeVer()` exige `Gate::forUser($usuario)->allows('ajustar-cuenta-envases')`;
  - toma hasta 20 parejas y, de cada una, llama a `previsualizar($recepcion, $guia, 'romana', true)`;
  - filas: despacho, recepción, cliente, envase, Romana, despacho y diferencia;
  - una pareja que lanza `ConflictoOperacion` va en una fila con la nota «requiere revisión individual».

- [ ] **Step 1: Failing tests:**
  - `test_descuadres_lista_la_doble_salida_con_su_diferencia`: Romana con 10 bins y guía con 12 dan una diferencia de 2.
  - `test_descuadres_exige_ajustar_la_cuenta`: un supervisor sin la puerta da 403 en la API y no ve la vista en el catálogo.
  - `test_la_oficina_lista_las_mismas_parejas`
- [ ] **Step 2–4:** Run, implement, run.
- [ ] **Step 5: Commit** «Asistente: descuadres entre guía y Romana».

### Task 5: Tema `existencia-envases`

**Files:** Create `app/Services/Asistente/Consultas/ExistenciaEnvases.php`; modify el proveedor. Test: `tests/Feature/Asistente/ExistenciaEnvasesTest.php`.

**Interfaces:**
- Tema «Envases en planta», vista por defecto `por-propiedad`.
- Palabras del tema: `existencia`, `existencias`, `stock`, `quedan`, `inventario`. Secundarias: las de envases.
- **`por-propiedad`:**
  - palabras: `arrendados`, `arrendado`, `arrendadas`, `propios`, `propias`, `disponibles`; sin filtros;
  - llama a `existencia($temporadaId)['tipos']`;
  - filas por envase: propios, de clientes, arrendados, total en planta, reservado y disponible.
- **`propios-entregados`:**
  - palabras: `entregados`, `entregamos`, `propios entregados`; filtro `cliente`;
  - llama a `propiosEntregados($temporadaId, $clienteId)`.

- [ ] **Step 1: Failing tests:**
  - `test_por_propiedad_coincide_con_la_existencia_de_la_oficina`
  - `test_propios_entregados_por_cliente`
- [ ] **Step 2–5:** Run, implement, run y commit «Asistente: existencia de envases».

### Task 6: `envases-recibidos` con la vista `salidas-destare`

**Files:** Modify `app/Services/Asistente/Consultas/EnvasesRecibidos.php`, que pasa a extender `ConsultaPorTema`. Test: `tests/Feature/Asistente/EnvasesRecibidosTest.php`.

**Interfaces:**
- **Vista `recibidos`** (por defecto): el cálculo de hoy, sin cambios.
- **Vista `salidas-destare`:**
  - palabras: `destare`, `salieron`, `salieron con envases`, `devueltos en destare`; filtros `periodo` y `cliente`;
  - suma `SalidaEnvaseRecepcionRomana` de las recepciones no anuladas ni eliminadas cuyo `salida_at` cae en el periodo;
  - filas: recepción, cliente, patente, envase y cantidad.

- [ ] **Step 1: Failing tests:**
  - `test_salidas_destare_del_periodo`
  - `test_recibidos_sigue_igual`
- [ ] **Step 2–5:** Run, implement, run y commit «Asistente: salidas de envases en el destare».

### Task 7: Banco, documentación y PR

**Files:** `resources/asistente/banco-preguntas.json` y `docs/ASISTENTE_OFICINA.md`.

- [ ] **Step 1: Banco.** Cambian estas preguntas existentes:

  | # | Pregunta | Antes | Ahora |
  | --- | --- | --- | --- |
  | 17 | «¿Qué camiones salieron con envases en el destare hoy?» | null | `envases-recibidos`, vista `salidas-destare` |
  | 18 | «¿Cuántos bins nos debe el CSG 104512?» | null | `cuenta-envases`, vista `por-productor` |
  | 22 | «Estado de cuenta de envases de Valle Alto de este mes» | null | `cuenta-envases`, vista `estado-cuenta` |
  | 23 | «¿Cuántos bins arrendados quedan en existencia?» | null | `existencia-envases`, vista `por-propiedad` |

  Preguntas nuevas, desde la 66. Cada vista nueva suma dos que debe responder y una parecida que no:
  - «¿Cuántos bins le debemos a Valle Alto?» → `saldos`;
  - «¿Hay descuadres de envases entre guía y Romana?» → `descuadres`;
  - «¿Qué diferencias tiene la cuadratura de hoy?» → sigue en null;
  - «¿Cuántos bins propios le entregamos a Valle Alto?» → `propios-entregados`;
  - «¿Cuántos totes hay disponibles?» → `por-propiedad`;
  - «¿Cuánta fruta queda en existencia?» → null; es producto terminado, no envases;
  - «¿Cuántos envases salieron en el destare ayer?» → `salidas-destare`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Asistente`. Expected: PASS con cero falsas. Una falsa se corrige con palabras o vistas, nunca cambiando la expectativa del banco.
- [ ] **Step 3: Documentación.** En la tabla del catálogo, agregar los temas nuevos y la vista nueva de `envases-recibidos`.
- [ ] **Step 4: Verificación completa.** Correr `vendor/bin/pint --test && npm run test:js`; después, revisión final, PR hacia main y CI.
