# PR 8 · Tablet: maniobra en ejecución

**Rama:** `diseno/pr8-tablet-maniobra` · **Base:** PR 7 · **Entrega:** especificación
**Título del PR:** `feat(tablet): maniobra en ejecución en dos columnas (FoliOS v2)`
**Lámina:** `laminas/08-tablet-maniobra.png` · `html/08-tablet-maniobra.html` (1280 × 800, horizontal)
**Archivo:** `mobile/src/components/operator/OperatorTaskExecution.tsx` (y sus estilos)

## Importante: la lámina simplifica el flujo

La lámina muestra un campo “Escanea la etiqueta de la posición” y un botón “Confirmar
ubicación” que se habilita al escanear. **Ese paso no existe en la aplicación** y este PR no lo
crea. Se conserva el flujo real:

- Antes de retirar: confirmación con los últimos dígitos del folio y PIN
  (`OperatorFolioConfirmation`), sin cambios.
- Durante la maniobra: el botón principal es el de `buildOperatorManeuverAction` (“RETIRAR
  PALLET”, “CONFIRMAR PALLET RETIRADO”, “CONFIRMAR MOVIMIENTO”, “CONFIRMAR ENTREGA EN ANDÉN”),
  habilitado según `primaryDisabled` como hoy.
- Excepciones: “NO COINCIDE” y “NO ES POSIBLE” (flujo `OperatorExceptionReport`) y “Liberar
  antes de iniciar”, sin cambios.

Lo que cambia es la **disposición y el estilo**.

## Disposición (ancho ≥ 1000 dp; bajo eso se mantiene la columna única actual)

```
┌ OperatorHeader (PR 7) ───────────────────────────────────────────────┐
├ izquierda 340 dp ──────────┬ derecha (flex 1) ─────────────────────────┤
│ Tarjeta “Mi maniobra”      │ Destino                                    │
│  - rótulo, folio (34 mono) │  - rótulo según verbo                      │
│  - labor · objetivos       │  - texto grande (76 mono, frost)           │
│  - origen → destino (mono) │  - motivo (16, hielo 2), a la derecha      │
│ Pasos (lista vertical)     │ PhysicalPattern (plano del pasillo)        │
│ [NO COINCIDE][NO ES POSIBLE]│ Avisos (destino pendiente / reserva vencida)│
│ [Liberar antes de iniciar] │ Botón principal (76 dp, ancho completo)    │
│                            │ Ayuda bajo el botón (15, niebla)           │
├────────────────────────────┴────────────────────────────────────────────┤
│ SequencePanel · CustodyPanel · OperatorPhysicalContextPanel (debajo)    │
│ Pie: ‹ VOLVER A MANIOBRAS · CommitmentBadge                              │
└──────────────────────────────────────────────────────────────────────────┘
```

- Contenedor de columnas: `flexDirection: 'row'`, `gap: 20`, `padding: 20`, sobre `o.color.canvas`.
- El encabezado propio de la pantalla (“Mi maniobra / Ejecución paso a paso” + insignia
  EN EJECUCIÓN/PREPARADA + título de la maniobra) se reduce a una fila bajo el
  `OperatorHeader`: botón volver 48×48 (borde 1.5 `o.color.borderStrong`, radio 10), título
  de la maniobra (condensada 22/800) e insignia `OperatorStatusBadge` (sin cambios de texto).

### Columna izquierda

| Bloque | Datos (ya existentes) | Estilo |
|---|---|---|
| Mi maniobra | rótulo “Mi maniobra”; `OperatorEntityCode prominent` con `task.folio.numero_folio`; `operationalTaskLabel(task.plan.tipo)` y, si `maneuverObjectiveCount(task) > 1`, “· {n} objetivos”; `operationalTaskPositionLabel(task.origen)` → `action.destination` | Tarjeta `o.color.surface`, radio 14, relleno 18; rótulo 14/600 niebla; folio 34 mono frost; texto 15 hielo 2; ruta 15 mono 600 hielo |
| Pasos | `currentStep`, `totalSteps`, `action.instruction` (los mismos de la barra de progreso actual) | Lista vertical, gap 8. Cada paso: fila 40 dp círculo + texto; hecho: fondo `successSurface`, círculo `success` con “✓” en `onPrimary`; actual: fondo `selected`, contorno 2 `primary`, círculo `primary` con número en `onPrimary`, texto `action.instruction` y “Ahora”; pendiente: fondo `surface`, círculo con borde 2 `borderStrong` |
| Excepciones | botones actuales | Alto 60, radio 12, borde 2 `criticalBorder`, texto `critical` 18/700, fondo transparente. “Liberar antes de iniciar”: borde 2 `borderStrong`, texto hielo |

### Columna derecha

| Bloque | Datos | Estilo |
|---|---|---|
| Rótulo | `RETIRAR` → “Retira el pallet de”; `UBICAR` → “Lleva el pallet a”; `ENTREGAR` → “Entrega el pallet en” | 16/600 niebla |
| Destino | `RETIRAR`: `operationalTaskPositionLabel(task.origen)`; otros: `action.destination` | `o.font.mono`, 76 (56 si el texto supera 14 caracteres), peso 700, `letterSpacing: -3`, frost |
| Motivo | `operationalTaskReason(task)` | 16, hielo 2, alineado a la derecha, ancho máx. 260 |
| Plano | `<PhysicalPattern task={task} />` | Contenedor `o.color.canvas`, radio 12, relleno 16 |
| Botón principal | `action.primaryLabel`, `runPrimary`, `primaryDisabled` | Alto 76, radio 14, fondo `primary`, texto `onPrimary` 24/800; deshabilitado: fondo `disabled`, texto `disabledText`; sin flecha “›” |
| Ayuda | `action.instruction` | 15, niebla, margen superior −6 |

Toda la sección derecha va en una tarjeta `o.color.surface`, radio 18, relleno 24/26.

## Lo que no cambia

Textos de acción, permisos, llamadas a la API, temporizadores (`CommitmentBadge`),
`PausedManeuver`, `OperatorFolioConfirmation`, `OperatorExceptionReport`, pruebas existentes de
`OperatorTaskExecutionTest.mjs` y `OperatorTabletVisualTest.mjs` (si una aserción de estructura
deja de cumplirse, ajustar la aserción, no el comportamiento, y explicarlo en el PR).

## Criterios de aceptación

- [ ] En tablet 1280×800 horizontal, la pantalla sin desplazamiento hasta el botón principal.
- [ ] Bajo 1000 dp de ancho (y en PDA), la columna única actual con los estilos del PR 7.
- [ ] Mismas llamadas y mismos textos de botones que antes (comparar con la rama base).
- [ ] Foto en equipo de: maniobra preparada (RETIRAR), en movimiento (UBICAR) y entrega en andén.
