# Asistente: tanda de lotes MP y SAG — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el asistente responda sobre lotes de materia prima y lotes retenidos SAG con las mismas cifras que sus pantallas. Agrega:
- el tema `lotes-mp`, con `por-estado` (por defecto) y `antiguedad`;
- el tema `retenciones-sag`, con `vigentes` y `folios-afectados`, sin vista por defecto.

**Architecture:** Cada vista lee lo mismo que su pantalla:
- los conteos por estado del panel gerencial, que excluyen los anulados;
- los bins por entregar de Fruta a proceso: primarios menos entregas no anuladas;
- en SAG, `ServicioRetencionesLoteSag::foliosDelLote()` y el accessor de estado de `RetencionLoteSag`.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit sobre MySQL.

**Spec:** `docs/superpowers/specs/2026-11-23-asistente-consultas-por-tema-design.md`, tanda 2. Vistas confirmadas por el usuario el 24-11-2026: lotes SAG vigentes, pallets con fruta retenida, lotes MP por estado y lotes MP más antiguos. Inspecciones SAG, órdenes de proceso y trazabilidad quedan para después.

## Global Constraints

- Mismas cifras que la pantalla de origen. Sin cálculos propios del asistente.
- Permisos:
  - `retenciones-sag` exige `puedeGestionarRetencionesLoteSag`, como el módulo Lotes retenidos SAG;
  - `lotes-mp` exige `puedeConsultarMateriaPrima`, como Kilos por CSG.
- Cero falsas en el banco. Cada vista nueva suma dos preguntas que debe responder y una parecida que no.
- Español neutro. `pint --test` en verde.

## Review Focus

1. **«lotes retenidos sag?»** va a `retenciones-sag`/`vigentes`, no a `lotes-mp` ni a `kilos-por-csg`. Lo prueba la Task 3.
2. **«lotes retenidos en hidrocooler»** va a `lotes-mp`/`por-estado`, no a SAG. Lo prueba la Task 3.
3. **Liberado con restricciones:** un registro guardado como `retenido` con destinos parciales se muestra como «Liberado con restricciones», según el accessor. Lo prueba la Task 2.
4. **Un pallet despachado** con cajas de un lote retenido no aparece en `folios-afectados`. Tampoco uno de un lote liberado. Lo prueba la Task 2.
5. **«¿Qué lotes entran a proceso en el turno A?»** sigue sin respuesta, porque es de órdenes de proceso. Lo prueba la Task 3, con el banco.

---

### Task 1: Tema `lotes-mp`

**Files:**
- Modify: `app/Enums/EstadoLoteMateriaPrima.php`, que gana `etiqueta()` con los textos de la oficina de materia prima.
- Create: `app/Services/Asistente/Consultas/LotesMateriaPrima.php`.
- Modify: `app/Providers/AsistenteServiceProvider.php`.
- Test: `tests/Feature/Asistente/LotesMateriaPrimaTest.php`.

**Interfaces:** tema `lotes-mp`, «Lotes de materia prima», dominio `materia-prima`.
- **Palabras del tema:** `lote`, `lotes`, `proceso`.
- **Vista `por-estado`** (por defecto):
  - palabras: `estado`, `estados`, `hidrocooler`, `disponibles`, `disponible`, `camara`, `parcial`, `entregados`, `por estado`, `retenidos en hidrocooler`, `retenido en hidrocooler`, `entrega parcial`;
  - filtros: cliente, CSG, especie;
  - filas por estado, en el orden del flujo y sin anulados: lotes, kilos netos y bins por entregar.
- **Vista `antiguedad`:**
  - palabras: `antiguos`, `antiguo`, `antiguedad`, `viejos`, `dias`, `resguardo`, `esperando`, `mas antiguos`, `sin proceso`, `sin entregar`, `siguen sin`;
  - filtros: cliente, CSG, especie;
  - lotes activos, como los cuenta el panel: sin borradores, entregados ni anulados;
  - orden por ingreso a Romana, del más antiguo al más nuevo;
  - columnas: lote, cliente, CSG, estado, cámara, días desde la recepción, bins por entregar y kilos netos.

- [ ] **Step 1: Failing tests:**
  - `test_por_estado_cuenta_lotes_y_kilos_sin_anulados`;
  - `test_por_estado_muestra_bins_por_entregar_de_una_entrega_parcial`;
  - `test_antiguedad_ordena_por_ingreso_y_excluye_borradores_y_entregados`;
  - `test_antiguedad_filtra_por_csg`.
- [ ] **Step 2: Run** `--filter=LotesMateriaPrimaTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: lotes de materia prima por estado y antigüedad».

### Task 2: Tema `retenciones-sag`

**Files:**
- Create: `app/Services/Asistente/Consultas/RetencionesSag.php`.
- Modify: `app/Providers/AsistenteServiceProvider.php`.
- Test: `tests/Feature/Asistente/RetencionesSagTest.php`.

**Interfaces:** tema `retenciones-sag`, «Lotes retenidos SAG», dominio `frigorifico`, sin vista por defecto.
- **Palabras del tema:** `sag`. **Secundarias:** `lote`, `lotes`.
- **Vista `vigentes`:**
  - palabras: `retenido`, `retenidos`, `retenida`, `retenidas`, `retencion`, `retenciones`, `vigentes`, `observaciones`, `restricciones`, `restriccion`, `destinos`, `lotes retenidos`, `con observaciones`;
  - filtro: cliente;
  - registros no liberados de la temporada activa;
  - columnas: lote, cliente, estado (con el accessor), destinos (con el mismo texto de la oficina), detección, pallets en planta y cajas del lote en planta.
- **Vista `folios-afectados`:**
  - palabras: `pallets`, `pallet`, `folios`, `folio`, `cajas`, `donde`, `ubicacion`, `fruta retenida`, `pallets retenidos`;
  - filtro: cliente;
  - un pallet por fila, con `foliosDelLote()`, solo si está activo y fuera de Despachado, Anulado, Agotado o Retirado definitivo;
  - columnas: folio, lote, cliente, estado de la retención, cajas del lote, ubicación y carga.
- **Límites para Haiku:** no lista lotes liberados ni pallets ya despachados.

- [ ] **Step 1: Failing tests:**
  - `test_vigentes_muestra_estado_destinos_y_pallets_en_planta`;
  - `test_vigentes_no_incluye_liberados_y_muestra_liberado_con_restricciones`;
  - `test_folios_afectados_excluye_despachados_y_lotes_liberados`;
  - `test_sin_el_modulo_no_se_ofrece`.
- [ ] **Step 2: Run** `--filter=RetencionesSagTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: lotes retenidos SAG y pallets con fruta retenida».

### Task 3: Reglas, banco y documentación

**Files:**
- Modify: `resources/asistente/banco-preguntas.json`:
  - #40 → `retenciones-sag`/`vigentes`;
  - #30 → `lotes-mp`/`antiguedad`;
  - nuevas: dos por vista y negativas.
- Modify: `app/Services/Asistente/Haiku/CoberturaConsultaHaiku.php`, con los límites de los dos temas.
- Modify: `docs/ASISTENTE_OFICINA.md`, con sus filas en el catálogo.
- Test: `tests/Feature/Asistente/VistasReglasTest.php`.

- [ ] **Step 1: Failing tests:**
  - `test_lotes_retenidos_sag_va_a_vigentes`;
  - `test_retenidos_en_hidrocooler_va_a_lotes_mp`;
  - `test_pallets_con_fruta_retenida_va_a_folios_afectados`.
- [ ] **Step 2: Run.** Expected: FAIL hasta registrar las palabras.
- [ ] **Step 3: Implement.** Si el banco no da cero falsas, ajustar las palabras.
- [ ] **Step 4: Run** todo `tests/Feature/Asistente` y `tests/Unit/Asistente`. Expected: PASS, con cero falsas en el banco.
- [ ] **Step 5: Commit** «Asistente: banco y documentación de la tanda de lotes y SAG».
