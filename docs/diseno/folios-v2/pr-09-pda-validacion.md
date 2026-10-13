# PR 9 · PDA: Validación PT

**Rama:** `diseno/pr9-pda-validacion` · **Base:** PR 7 · **Entrega:** especificación
**Título del PR:** `feat(pda): validación PT con el lenguaje de terreno FoliOS v2`
**Láminas:** `laminas/09-pda-escanear.png`, `09-pda-validar.png`, `09-pda-resultado.png` (360 × 740, vertical)
**Archivo:** `mobile/src/screens/ValidationScreen.tsx` (solo estilos y encabezado)

## Importante: las láminas son de lenguaje visual, no de flujo

Las láminas separan el trabajo en tres pantallas (escanear → validar → resultado) y muestran
un formulario reducido. **La PDA real tiene un solo formulario** con: contexto de jornada
(línea y turno), folio, tipo de bulto y cajas, origen comercial (cliente, marca, fecha de
embalaje, composición por CSG con lote MP y proceso), producto (especie, variedad, calibre,
envase), categoría, botones de resultado, sesión y bandeja. **Este PR no cambia campos, orden,
validaciones, cola sin conexión ni textos de acción**: aplica el lenguaje de las láminas a esa
misma pantalla. Dividirla en pasos sería un PR de flujo aparte, a conversar.

## Correspondencia lámina → pantalla real

| Lámina | Elemento real |
|---|---|
| Encabezado con gajo de Frigorífico, “Validación PT”, “Línea 2, turno A”, píldora “En línea / Sin conexión” | `topbar` + `connection`; subtítulo = `workContextSummaryText` (línea y turno) |
| Tarjeta “Pistolea el folio” con campo de 56 dp | `folioInput` + `lookupButton` |
| “Trabajados en esta sesión” con folio mono y chip de estado | `sessionItem`, `queueItem` |
| Pie “Todo sincronizado…” | `statusText` |
| Validar: segmentado “Pallet completo / Saldo” | `typeButton` / `typeButtonActive` |
| Cajas, Categoría, Artículo | `boxInput`, `selectButton` |
| Composición por CSG + “Combinación habilitada en el catálogo” | `originCompositionRow`, `compositionTotalOk` |
| Aprobar (frost) · Observar (ámbar) · Rechazo definitivo (coral) | `approveButton`, `observeButton`, `rejectButton` |
| Resultado: tarjeta verde “Pallet aprobado” y aviso ámbar “4 validaciones esperando sincronización” | `noticeBanner` y `previousQueue` / `sessionItemLocal` |

## Encabezado

Reemplazar el bloque `topbar` por el patrón del PR 7 (sin usar `OperatorHeader`, que está
pensado para la tablet): fondo `#0B1A22`, regla inferior 3 dp `#7DBCEB` (Frigorífico), gajo
`areas.frigorifico.gajo` 30×30, título “Validación PT” (condensada 20/800, hielo), subtítulo
“Línea {línea}, turno {turno}” (13, niebla) y la píldora de conexión del PR 7 (28 dp en PDA).
“Salir” queda como botón de texto 44 dp a la derecha. Sin `eyebrow` “FoliOS · TERRENO”.

## Estilos (reemplazos exactos en `StyleSheet.create`)

Colores de `colors.ts` (PR 7). Donde dice `blocked` en estados (no en el plano) usar `redDark`.

| Clave | Nuevo valor |
|---|---|
| `screen`, `boot` | `backgroundColor: colors.background` (sin cambio de clave; valor v2 por PR 7) |
| `formPanel`, `sidePanel` | `borderRadius: 16`, `borderWidth: 0`, `backgroundColor: colors.panel`, relleno 18 (compacto 14) |
| `label` | `color: colors.muted`, `fontSize: 13`, `fontWeight: '600'`, **sin** `textTransform` ni `letterSpacing` |
| `sectionEyebrow` | `color: colors.muted`, `fontSize: 13`, `fontWeight: '600'`, sin `letterSpacing` |
| `sectionTitle`, `sideTitle`, `title` | `fontFamily: o.font.condensed`, `fontWeight: '800'`; tamaños 22 / 22 / 20 |
| `folioInput` | `minHeight: 56`, `borderRadius: 10`, `borderWidth: 1.5`, `borderColor: '#648D9D'`, `backgroundColor: colors.background`, `fontFamily: o.font.mono`, `fontSize: 22`, `fontWeight: '600'`, `letterSpacing: -0.5` |
| `lookupButton` | `borderRadius: 10`, `backgroundColor: colors.cyan`, `borderColor: colors.cyan`; `lookupButtonText` `color: colors.accentText`, 15/800 |
| `typeButton` | `minHeight: 46`, `borderRadius: 10`, `borderWidth: 1.5`, `borderColor: '#3F6B7C'`, `backgroundColor: 'transparent'`; `typeButtonText` `color: colors.textSecondary` 15/700 |
| `typeButtonActive` | `backgroundColor: colors.cyan`, `borderColor: colors.cyan`; `typeButtonTextActive` `color: colors.accentText` |
| `boxInput`, `traceInput`, `selectButton` | `minHeight: 48`, `borderRadius: 10`, `borderWidth: 1.5`, `borderColor: '#648D9D'`, `backgroundColor: colors.background`; cifras de `boxInput` con `fontFamily: o.font.mono`, 22/700 |
| `originCompositionRow` | `borderWidth: 0`, `borderRadius: 10`, `backgroundColor: colors.panel` |
| `compositionTotalOk` / `Pending` | sin cambio de colores; agregar marca de texto “✓ ” / “◆ ” al inicio del texto |
| `resultButton` | `minHeight: 60`, `borderRadius: 12`, `borderWidth: 2` |
| `approveButton` | `backgroundColor: colors.cyan`, `borderColor: colors.cyan`. Agregar `approveText: { color: colors.accentText }` y aplicarlo junto a `resultIcon`/`resultButtonText` solo en Aprobar. `resultButtonText` pasa a 16/800 y conserva `textTransform` |
| `observeButton` | `backgroundColor: colors.amberDark`, `borderColor: '#7A5F22'`, texto `colors.amber` 16/700 |
| `rejectButton` | `backgroundColor: 'transparent'`, `borderColor: '#7D3F38'`, texto `colors.red` 15/700 |
| `resultButtonDisabled` (nuevo) | `backgroundColor: '#24414F'`, `borderColor: '#24414F'`, `opacity: 1`; usarlo **en lugar de** `disabled` solo en los tres botones de resultado (el resto sigue con `disabled`) |
| `sessionMetric` | `borderWidth: 0`, `borderRadius: 10`, `backgroundColor: colors.background`; `sessionMetricValue` condensada 20/800 |
| `sessionMetricPositive/Warning/Critical` | fondos `greenDark`/`amberDark`/`redDark`, borde 1 `#2F6B52`/`#7A5F22`/`#7D3F38` |
| `sessionItem`, `queueItem` | tarjeta: `paddingHorizontal: 12`, `paddingVertical: 11`, `borderRadius: 10`, `borderBottomWidth: 0`, `backgroundColor: colors.panel`, `marginBottom: 6` |
| `queueFolio` | `fontFamily: o.font.mono`, 17/700, `letterSpacing: -0.5` |
| `connectionOffline`, `errorBanner`, `reviewRejected` | `backgroundColor: colors.redDark` (antes `colors.blocked`) |
| `previousQueue`, `sessionItemLocal` | `backgroundColor: colors.amberDark`, `borderColor: '#7A5F22'`, radio 12 |

Chips de estado en la lista de sesión (si hoy son texto): píldora 28 dp, radio 999, borde 1,
13/700 con “✓” Aprobado (`green` sobre `greenDark`), “◆” Observado (`amber` sobre
`amberDark`), “■” Rechazado (`red` sobre `redDark`).

`o` es `operatorTheme` (PR 7): importar solo para `o.font`.

## Criterios de aceptación

- [ ] Ningún campo, validación ni texto de acción cambia (diff de lógica vacío fuera de estilos,
      encabezado e imports).
- [ ] Textos ≥ 11 dp (`MobileLegibilityTest`), objetivos táctiles ≥ 44 dp.
- [ ] En PDA 360×740: foto de folio vacío, formulario a medio llenar, aprobado en línea y
      aprobado sin conexión con cola pendiente; comparar con las láminas en color y forma.
- [ ] `npm run test:js`, `npm run typecheck` y `npm run test:catalog` verdes.
