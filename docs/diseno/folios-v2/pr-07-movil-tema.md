# PR 7 · Móvil: terreno siempre oscuro y encabezado por área

**Rama:** `diseno/pr7-movil-tema` · **Base:** PR 4 (independiente de PR 5 y 6; puede ir en
paralelo) · **Entrega:** parche `0005`
**Título del PR:** `feat(movil): terreno siempre oscuro y encabezado por área (FoliOS v2)`
**Lámina:** `laminas/08-tablet-maniobra.png` (encabezado) y `laminas/00-sistema.png` (paleta oscura)

## Qué cambia para el usuario

- La tablet del camarero deja el tema claro y pasa a la paleta oscura de terreno (noche,
  muro, hielo, frost), igual que la PDA. Las pantallas que ya eran oscuras (Validación PT,
  Prefrío, Materiales…) ajustan sus valores a la misma paleta.
- Encabezado: gajo del área (Frigorífico por defecto), título angosto, “Nombre, rol”, estado de
  conexión en píldora y regla inferior de 3 px del color del área. El logo completo sigue en el
  ingreso (`LoginScreen`).
- Sellos en píldora con borde del tono; folio destacado en frost.

**OTA:** no agrega módulos nativos ni fuentes. Las imágenes del gajo viajan en la
actualización. Se publica con `npm run update:production` / `update:pda` como siempre.

## Archivos

| Archivo | Cambio |
|---|---|
| `mobile/src/theme/operatorTheme.ts` | Paleta oscura en literal (= `colorDark`/`signalDark`), claves nuevas `textSecondary`, `successBorder`, `warningBorder`, `criticalBorder`, `infoBorder`; radios `control 10`, `panel 14`, `pill 999`; tipos `caption 13`, `small 15`, `folio 30`, `display 36`; `font.condensed` (`sans-serif-condensed` en Android) y `font.mono`. Usa `Platform?.OS` porque las pruebas de `mobile/tests` simulan `react-native` sin `Platform`. |
| `mobile/src/theme/areas.ts` (nuevo) | `areas[AreaKey]` con `label`, `bright`, `tint`, `line` (de `areaDark`) y `gajo` (PNG). |
| `mobile/assets/gajo/gajo-*.png` (nuevos, 7) | 120×120, transparentes, hojas en reposo `#2F5262`, hoja del área en su `bright`, centro calado (Operación: centro relleno frost). |
| `mobile/src/components/operator/OperatorHeader.tsx` | Reescrito. Props nuevas opcionales `area` (por defecto `frigorifico`) y `title`. Conserva `navigation`, `connected`, `modeLabel`, `deviceName`, `role`, `userName` y el rótulo accesible “Estado de conexión: …”. |
| `mobile/src/components/operator/OperatorPrimitives.tsx` | Sellos en píldora con borde del tono; código destacado en `o.color.primary`, peso 800. |
| `mobile/src/components/operator/*.tsx`, `screens/OperationalWorkspaceScreen.tsx`, `screens/OperationalScreen.tsx` | Colores fijos → claves del tema (`textSecondary`, `borderStrong`, `primary`, `critical`, `criticalBorder`, `colors.redDark`, `colors.red`). |
| `mobile/src/theme/colors.ts` | Valores v2 con las mismas claves (+ `redDark`, `textSecondary`). `cyan` y `cyanDark` sin cambios. Colores del plano (`free`, `pallet`, `saldo`, `blocked`) sin cambios. |
| `mobile/src/components/ui/EstibaPrimitives.tsx` | Usa `colorDark`/`signalDark` sobre los tokens claros. |
| Pruebas | `OperatorTabletVisualTest.mjs` (pares de contraste oscuros), `BrandIdentityTest.mjs` (encabezado con gajo), nueva `FoliosV2MobileTest.mjs`. |

## Medidas del encabezado

| Elemento | Valor |
|---|---|
| Alto | ≥ 76 dp; relleno 16/8 |
| Fondo / regla | `#0B1A22` / 3 dp `areas[area].bright` |
| Gajo | 38×38 dp |
| Título | `font.condensed`, 22, peso 800, `#E8F3F6` |
| Subtítulo | 15, `#9FBCC6`: “{nombre}, {rol en minúsculas}” |
| Píldora de conexión | ≥ 36 dp, radio 999, borde 1; en línea `#11302A/#2F6B52` texto `#6FD6A0` con “✓”; sin conexión `#2C2716/#7A5F22` texto `#F3B94F` con “◆” |
| Dispositivo | 13, `#9FBCC6`, a la derecha (en compacto, bajo el título) |

## Cómo aplicar y verificar

```bash
git switch -c diseno/pr7-movil-tema diseno/pr4-romana
git am --keep-cr parches/0005-*.patch
npm run test:js
cd mobile && npm ci && npm run typecheck && npm run test:catalog
```

## Criterios de aceptación

- [ ] `npm run test:js`, `npm run typecheck` y `npm run test:catalog` verdes.
- [ ] **En equipo** (tablet 1280×800 horizontal y PDA 360×740): sin textos oscuros sobre fondo
      oscuro; encabezado como en la lámina de tablet; foto de cada pantalla adjunta al PR.
- [ ] El gajo se ve nítido (PNG de 120 px para 38 dp).
