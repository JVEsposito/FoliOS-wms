# Lector de presample USDA — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que «Leer presample» lea el certificado escaneado y deje N.º de lote, rango de lote y cajas a timbrar listos para revisar y aplicar al diagrama.

**Architecture:**
- `LectorPresampleUsda` coordina la lectura con Claude, la confirmación con Tesseract y las comprobaciones.
- La llamada a Anthropic reutiliza `ClienteAnthropic` y `PresupuestoHaiku`.
- En la oficina, una función pura de `shared/sag-presample.js` decide qué campos se completan y qué avisos se muestran.

**Tech Stack:** Laravel 13 y PHP 8.3. Tesseract y `pdftoppm` como procesos externos. JS de la oficina, probado con `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-26-lector-presample-usda-design.md`

## Global Constraints

- **Permiso:** consulta de Inspección SAG (`consultar-inspeccion-sag`).
- **Archivo:** PDF de una página o PNG/JPEG, de hasta 10 MB.
- **Costo:** cuenta en el tope mensual del asistente y se registra con la herramienta `leer_presample`. No cuenta en el límite diario de preguntas.
- **Sin Claude:** Tesseract solo, con todas las cajas en «revisar», sin lote ni rangos y con aviso.
- **Español neutro.** Textos de interfaz en español. `pint --test` y `npm run test:js` en verde.

## Review Focus

1. **Claude devuelve las cajas fila por fila** en vez de columna por columna. El orden creciente falla y se bloquea, sin aplicar nada.
2. **El presample no trae el tamaño de la muestra.** La cantidad de cajas no se puede comparar, y se avisa en vez de bloquear.
3. **Claude incluye un número del encabezado,** como 3432 o 69, entre las cajas. Si pasa del tamaño de la partida, se bloquea.
4. **Tesseract no está instalado.** La lectura con Claude sigue y todas las cajas quedan en «revisar».
5. **El diagrama ya tiene otro N.º.** No se reemplaza y se avisa.

---

### Task 1: Lector en el servidor

**Files:**
- Crear `app/Services/InspeccionSag/LectorPresampleUsda.php`.
- Modificar:
  - `config/sag.php` (`pdftoppm`);
  - `app/Http/Controllers/Api/DiagramaPalletsSagController.php` (`leerPresample`);
  - `routes/diagramas-sag.php`;
  - `app/Services/Asistente/Haiku/PresupuestoHaiku.php`: `llamadasHoy()` excluye `leer_presample` y se agrega `puedeGastar()`.
- Probar en `tests/Feature/Api/LectorPresampleUsdaTest.php`, con Claude falso (`Http::fake`) y binarios falsos escritos por la prueba.

**Interfaces:**
- `LectorPresampleUsda::leer(UploadedFile $archivo, User $usuario): array`. Devuelve:

  ```php
  [
      'fuente' => 'claude'|'tesseract',
      'lote' => ?string,
      'partida' => ?int,
      'muestra' => ?int,
      'pallets' => ?int,
      'rangos' => list<array{desde: string, hasta: string}>,
      'cajas' => list<array{numero: int, estado: 'confirmada'|'revisar'}>,
      'problemas' => list<string>,
      'avisos' => list<string>,
  ]
  ```

- `POST /api/sag/diagramas/leer-presample`, con el campo `archivo`.

**Pruebas (Step 1):**
- `test_lee_lote_rangos_y_cajas_y_confirma_con_tesseract`: muestra 69, rangos 12358039 a 12358058, cajas 3 y 743 en «revisar» y el resto confirmadas.
- `test_bloquea_si_la_cantidad_no_coincide_con_la_muestra`.
- `test_bloquea_cajas_fuera_de_orden_o_repetidas` y `test_bloquea_una_caja_sobre_la_partida`.
- `test_bloquea_si_los_rangos_no_suman_los_pallets`.
- `test_sin_clave_responde_solo_con_tesseract_y_avisa`.
- `test_registra_la_llamada_sin_contar_en_el_limite_diario`.
- `test_rechaza_pdf_de_mas_de_una_pagina_y_otros_formatos`.
- `test_exige_permiso_de_consulta_sag`.

**Steps:**
- [ ] **Step 1:** Escribir las pruebas que fallan.
- [ ] **Step 2:** Correr `--filter=LectorPresampleUsdaTest`. Expected: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Correr de nuevo. Expected: PASS.
- [ ] **Step 5:** Commit «Diagramas SAG/USDA: lector de presample con Claude y Tesseract».

### Task 2: Revisión y aplicación en la oficina

**Files:**
- Crear `resources/js/shared/sag-presample.js` con `aplicarPresample(campos, lectura)`.
- Modificar `resources/js/office-sag-pallet-diagrams.js` y `resources/views/office/sag-pallet-diagrams.blade.php`.
- Probar en `tests/JavaScript/sag-presample.test.mjs`.

**Interfaces:**
- `aplicarPresample({numero, lote, folio}, lectura) → {campos, avisos, marcas}`.
- Solo completa campos vacíos. Si un campo ya tiene otro valor, lo avisa.
- El folio queda como «12358039 a 12358058», o «A a B y C a D» si vienen dos rangos.
- Con problemas, devuelve `marcas: []` y no cambia ningún campo.

**Pruebas (Step 1):**
- `completa campos vacíos y devuelve las cajas`.
- `no reemplaza un N.º distinto y lo avisa`.
- `con problemas no aplica nada`.
- `dos rangos se unen con y`.

**Steps:**
- [ ] **Step 1:** Escribir las pruebas que fallan.
- [ ] **Step 2:** Correr `npm run test:js`. Expected: FAIL.
- [ ] **Step 3:** Implementar la función, el formulario y la revisión.
- [ ] **Step 4:** Correr de nuevo. Expected: PASS.
- [ ] **Step 5:** Commit «Diagramas SAG/USDA: revisar y aplicar el presample».

### Task 3: Documentación

- [ ] Actualizar `docs/DIAGRAMAS_PALLETS_SAG.md` con «Leer presample», el costo y `SAG_PDFTOPPM_BINARY`/`poppler-utils`.
- [ ] Commit «Diagramas SAG/USDA: documentar el lector de presample».
