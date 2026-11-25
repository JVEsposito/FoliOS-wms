# Asistente: neto por envase — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el asistente responda bien «promedio de totes de 8 Fuegos desde el 23-11 al 24-11».
- Hoy esa pregunta cae en *Revisión de taras* y responde algo que no se preguntó.
- Debe responder el promedio de kilos netos por tote, con mínimo, máximo y recepciones fuera del rango configurado.

**Architecture:**
- **Tema nuevo `neto-por-envase`,** con tres vistas:
  - `totes` y `bins`: una fila por recepción, calculada desde `peso_neto_por_envase` y `cantidad_envase_calculo_neto` de Romana;
  - `por-csg`: una fila por CSG y tipo de envase, calculada desde los lotes, con el mismo cálculo que el histórico por CSG de `RevisionTaras`.
- **Sin vista por defecto:** «promedio de 8 Fuegos» pregunta si son totes, bins o por CSG.
- **Revisión de taras:** deja de tener «promedio», «tote» y «totes» entre sus palabras.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit sobre MySQL.

**Spec:** el pedido del usuario del 25-11-2026, a partir de una captura. El diseño de temas y vistas es `docs/superpowers/specs/2026-11-23-asistente-consultas-por-tema-design.md`. Las preguntas de seguimiento («¿qué rango tienen?») quedan para otro PR.

## Global Constraints

- **Promedio:** neto total dividido por el total de envases, ponderado por envases. No es un promedio de promedios.
- **Rango:** el de `RangoTaraAsistente::vigentePara()`, igual que en *Revisión de taras*. Un rango por especie reemplaza al general.
- **Recepciones:** solo cerradas y no eliminadas de la temporada activa, como en *Kilos recibidos*.
- **Permiso:** `puedeConsultarRomana`.
- **Calidad:** cero falsas en el banco, en español neutro y con `pint --test` en verde.

## Review Focus

1. **Sin tipo de envase:** una recepción sin `peso_neto_por_envase`, o con cero envases, no entra al promedio ni divide por cero.
2. **Rango por especie:** si existe un rango de la especie, reemplaza al general.
3. **«¿Cuántos totes llegaron hoy?»** sigue yendo a *Envases recibidos*.
4. **«Revisa las taras de hoy»** sigue yendo a *Revisión de taras*.
5. **«¿Cuánto pesa un tote vacío?»** queda sin respuesta: es la tara del envase, no el neto.

---

### Task 1: Tema `neto-por-envase`

**Files:**
- Crear `app/Services/Asistente/Consultas/NetoPorEnvase.php`.
- Modificar `app/Providers/AsistenteServiceProvider.php`.
- Modificar `app/Services/Asistente/Haiku/CoberturaConsultaHaiku.php` (límites).
- Probar en `tests/Feature/Asistente/NetoPorEnvaseTest.php`.

**Interfaces:** tema `neto-por-envase`, «Neto por envase», dominio `materia-prima`, filtros periodo, cliente, CSG y especie.

| Vista | Palabras | Calcula |
|---|---|---|
| `totes` | `tote`, `totes` | Promedio, mínimo y máximo, recepciones y recepciones fuera del rango. Filas: recepción, ingreso, cliente, envases, neto, neto por envase y rango |
| `bins` | `bin`, `bins` | Lo mismo que `totes`, para bins |
| `por-csg` | `por csg`, `por productor` | Filas por CSG y envase: lotes, envases, kilos netos y neto por envase |

- **Palabras del tema:** `promedio` y `promedios`. Las frases van en cada vista, porque el intérprete solo lee frases de las vistas.
- **Frases:** `por tote`, `por bin`, `neto por tote`, `neto por bin`, `kilos por tote`, `kilos por bin`, `peso por tote`, `peso promedio`, `promedio de totes` y `promedio de bins`.

- [ ] **Step 1: Failing tests:**
  - `test_totes_promedia_ponderado_con_minimo_maximo_y_fuera_de_rango`;
  - `test_rango_de_especie_reemplaza_al_general`;
  - `test_recepcion_sin_neto_por_envase_no_entra`;
  - `test_bins_no_mezcla_totes`;
  - `test_por_csg_agrupa_lotes_por_csg_y_envase`;
  - `test_filtra_por_cliente`;
  - `test_sin_vista_por_defecto`.
- [ ] **Step 2: Run** `--filter=NetoPorEnvaseTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** «Asistente: neto por envase, con promedio y rango».

### Task 2: Palabras de Revisión de taras, banco y documentación

**Files:**
- Modificar `app/Services/Asistente/Consultas/RevisarTaras.php`.
- Modificar `resources/asistente/banco-preguntas.json`:
  - #9 pasa a `neto-por-envase`/`por-csg`;
  - agregar la pregunta de la captura, una de bins y las negativas del Review Focus.
- Modificar `docs/ASISTENTE_OFICINA.md`.
- Probar en `tests/Feature/Asistente/InterpreteReglasAsistenteTest.php`.

- [ ] **Step 1: Failing test** `test_neto_por_envase_elige_tema_y_vista`, con estos casos:
  - la pregunta de la captura, que hoy da `revisar-taras`;
  - «kilos por bin de Valle Alto»;
  - «neto por tote por CSG».
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** todo `tests/Feature/Asistente`. Expected: PASS, con cero falsas en el banco.
- [ ] **Step 5: Commit** «Asistente: banco y documentación de neto por envase».
