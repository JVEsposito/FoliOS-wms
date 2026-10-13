# FoliOS v2 · Sistema visual

Fuente de verdad del rediseño de interfaz aprobado el 13-10-2026. Todo PR de la serie
`folios-v2` se implementa contra este documento, las láminas de `laminas/` y los valores de
`design/estiba.tokens.json`. Si una lámina y este documento difieren, **manda este
documento**; si un parche de la serie y este documento difieren, **manda el parche**.

- Logo: el oficial, sin cambios (`public/brand/folios/*`, `<x-folios-logo>`).
- Oficina: clara, lenguaje de **etiqueta**. Modo oscuro opcional con la paleta de campo.
- Tablet (horizontal, 1280 × 800) y PDA (vertical, 360 × 740): **siempre oscuras**.
- Cada **área** tiene su color: es una hoja del gajo del logo.
- Los **estados** tienen forma y texto propios y nunca usan el color de un área.
- Una sola luz de acción: **frost** es la marca y el botón principal.

---

## 1. Paleta

Los valores viven en `design/estiba.tokens.json` (generados a `resources/css/estiba-tokens.css`
y `mobile/src/theme/estibaTokens.ts` con `npm run design:tokens`). En Oficina se consumen
como variables de tema de `resources/css/office-corporate.css`.

### 1.1 Oficina clara (predeterminada)

| Nombre | Token | Variable de Oficina | Valor | Uso |
|---|---|---|---|---|
| Papel | `color.canvas` | `--corporate-canvas` | `#EEF2F1` | Fondo de página |
| Etiqueta | `color.surface` | `--corporate-card` | `#FFFFFF` | Tarjetas, barra de contexto, campos |
| Tinta | `color.text` | `--text-strong` | `#10212B` | Títulos y texto principal |
| Tinta 2 | `color.textSecondary` | `--text-secondary` | `#344B57` | Bajadas y texto secundario |
| Rótulo | `color.muted` | `--text-subtle` | `#4C616C` | Rótulos, metadatos |
| Regla | `color.border` | `--corporate-border` | `#C8D3D7` | Borde de tarjeta |
| Regla suave | `color.borderSoft` | `--line-soft` | `#E1E8EB` | Divisiones internas |
| Borde de campo | `color.inputBorder` | `--input-border` | `#7A8D95` | Campos y botón secundario |
| Navy | `color.navy` | `--corporate-navy` | `#183442` | Menú lateral |
| Frost | `color.primary` = `color.frost` | `--primary`, `--frost` | `#0F5C6E` | Marca y acción principal |
| Frost hover | `color.primaryHover` | `--primary-hover` | `#0A4250` | Hover de acción |
| Fila destacada | `color.selected` | `--surface-hover` | `#EEF6F8` | Hover de filas |
| Sello | `color.seal` | `--seal` | `#B4500B` | Pendientes, atención |
| Fondo de sello | `color.sealSurface` | `--seal-bg` | `#FDF3EC` | Celda o fila en atención |
| Crítico | `color.criticalFill` | `--critical-fill` | `#B3261E` | Relleno de rechazo/bloqueo (texto blanco) |
| Éxito | `signal.success.text` | `--success-text` | `#1E6B45` | Confirmado, aprobado |

### 1.2 Oscuro (tablet, PDA y modo oscuro de Oficina)

| Nombre | Token | Valor | Uso |
|---|---|---|---|
| Noche | `colorDark.canvas` | `#0F212B` | Fondo |
| Muro | `colorDark.surface` | `#15303C` | Tarjetas |
| Escarcha | `colorDark.subtle` | `#1C3B49` | Superficie elevada, hover |
| Cabecera | `colorDark.navy` | `#0B1A22` | Encabezado de tablet/PDA, menú de Oficina oscuro |
| Línea | `colorDark.border` / `borderSoft` | `#2A4D5C` / `#24414F` | Bordes |
| Hielo | `colorDark.text` | `#E8F3F6` | Texto |
| Niebla | `colorDark.muted` | `#9FBCC6` | Rótulos |
| Hielo 2 | `colorDark.textSecondary` | `#C5D9DF` | Texto secundario |
| Frost | `colorDark.primary` | `#5FD3E6` | Acción; texto encima `#062530` |
| Ámbar | `signalDark.warning` | `#F3B94F` sobre `#2C2716`, borde `#7A5F22` | Observado, sin conexión |
| Coral | `signalDark.critical` | `#FF8B7B` sobre `#2E1B1C`, borde `#7D3F38` | Rechazo, no es posible |
| Verde | `signalDark.success` | `#6FD6A0` sobre `#11302A`, borde `#2F6B52` | Aprobado, en línea |
| Deshabilitado | `colorDark.disabledSurface` | `#24414F`, texto `#9FBCC6` | Botón inactivo |

### 1.3 Áreas (el gajo)

Orden de las hojas, en sentido horario desde las 12: Materia Prima, Frigorífico, Materiales,
Consultas, Gerencia, Administración. El centro del gajo es **Operación ahora**.

| Área | Clave | ink (gajo, regla) | text (sobre blanco) | tint (etiqueta) | line | bright (sobre navy/oscuro) |
|---|---|---|---|---|---|---|
| Operación ahora | `operacion` | `#0F5C6E` | `#0F5C6E` | `#E3F1F4` | `#9CC3CC` | `#5FD3E6` |
| Materia Prima | `materia-prima` | `#8C1D40` | `#8C1D40` | `#F8E6EC` | `#E3B3C2` | `#F2879F` |
| Frigorífico | `frigorifico` | `#1D6FA8` | `#15588A` | `#E5F0F8` | `#A9CBE6` | `#7DBCEB` |
| Materiales | `materiales` | `#8C5A22` | `#75491A` | `#F5ECE1` | `#DCC0A0` | `#E0AA6C` |
| Consultas | `consultas` | `#5E3F91` | `#5E3F91` | `#EEE8F6` | `#CDBDE8` | `#BBA2EA` |
| Gerencia | `gerencia` | `#4C7A1C` | `#3E5F14` | `#EDF4E3` | `#C3D9A6` | `#A6D16E` |
| Administración | `administracion` | `#4B5862` | `#4B5862` | `#ECEFF1` | `#BFC8CE` | `#B7C4CC` |

En oscuro (`areaDark`): `ink = text = bright`, y tint/line son: Operación `#12313D/#2F6F80`,
Materia Prima `#2B1B24/#7A3A4A`, Frigorífico `#132A3B/#3F6F93`, Materiales `#2A2318/#7A5A35`,
Consultas `#221D34/#5D4B84`, Gerencia `#1B2A18/#4F6D2E`, Administración `#1B2A32/#4F5F69`.

Dónde aparece el color del área: hoja del gajo en el menú, etiqueta sobre el título, regla de
3 px bajo la barra de contexto, marca del subitem activo, pestaña activa, contorno del paso
actual y de la fila seleccionada. **Nunca** en sellos de estado, botones ni cifras.

Variables en Oficina: `--area-<clave>-{ink,text,tint,line,bright}` y las del área activa
`--area-ink`, `--area-text`, `--area-tint`, `--area-line`, `--area-bright`, `--area-gajo`
(las fija `office-folios.css` según la oficina abierta).

Contraste verificado (prueba `FoliosV2FoundationTest` y `DesignTokensTest`): todo texto ≥ 4,5:1;
gajo sobre blanco ≥ 3:1.

## 2. Tipografía

Una sola familia en toda la interfaz, autoalojada (`resources/css/folios-fonts.css`):

- **Archivo** (`"Archivo Variable"`, ejes peso 100–900 y ancho 62–125 %). El ancho angosto
  se obtiene con `font-stretch`, no con otra familia.
- **Azeret Mono** (`"Azeret Mono Variable"`): folios, posiciones, guías, códigos y pesos.
  `letter-spacing: -0.03em` (−0.04em en tamaños ≥ 24 px).
- Roboto Condensed solo vive dentro del logo (SVG), no se carga.

| Rol | Peso | Ancho | Tamaño | Interlínea |
|---|---|---|---|---|
| Título de página (masthead) | 800 | 66 % | `clamp(2.5rem, 3.4vw, 4rem)` (40–64 px) | 0.92 |
| Otros h1 (estados vacíos) | 800 | 66 % | `clamp(2rem, 2.6vw, 2.75rem)` | 0.92 |
| Título de tarjeta (h2/h3) | 800 | 72 % | 26 px (`1.625rem`) | 1 |
| Cifra de indicador | 800 | 70 % | 36 px | 1 |
| Cuerpo | 400–500 | 100 % | 16 px | 1.5 |
| Celda de tabla | 400 | 100 % | 14.5 px | 1.35 |
| Rótulo (k) | 500 | 100 % | 12 px | 1.2 |
| Encabezado de tabla | 600 | 100 % | 12 px, sin mayúsculas forzadas | 1.2 |
| Etiqueta de área / sello | 700 | 100 % | 13 px | 1 |
| Código (Azeret) | 700 | — | 15–18 px en filas, 26 px en expedientes | 1.2 |

Los textos que el marcado ya trae en mayúsculas (eyebrows existentes) se respetan; la
interfaz nueva no fuerza mayúsculas con CSS.

## 3. Forma

- Radios: sello 4 px, control (botón, campo) 6 px, tarjeta 8 px, controles táctiles 12 px.
- Reglas: 1 px (tarjeta, divisiones), 2 px (bajo encabezado de tabla, en tinta), 3 px (regla
  del área bajo la barra de contexto).
- Sin sombras en Oficina clara. Diálogos y expedientes flotantes: `--corporate-shadow-raised`.
- Controles de Oficina: 40–44 px de alto. Tablet/PDA: ≥ 56 px; acción principal 60–78 px.

## 4. Componentes de Oficina

| Componente | Dónde | Especificación |
|---|---|---|
| Shell | `<x-office.navigation>`, `office-shell.css` | Menú navy de 248 px (224 px entre 1200 y 1399 px): logo, búsqueda, Operación ahora, seis áreas con su gajo, oficinas del área activa anidadas, identidad al pie. Barra de contexto blanca: temporada, planta, datos, rótulo de servidor opcional, modo oscuro; regla de 3 px del área. |
| Gajo | `<x-folios.gajo area="…">` | SVG de 6 hojas; `.is-area` toma el color del área; `area="operacion"` agranda el centro. |
| Etiqueta de área | `<x-folios.area-tag area="…">` y `.eyebrow` del encabezado | 28 px de alto, borde 1.5 px `line`, fondo `tint`, texto `text`, gajo de 16 px. |
| Encabezado de página | `.xxx-heading` existentes, `<x-estiba.heading>` | Etiqueta de área, h1 masthead, bajada en Tinta 2 (máx. 68 ch), acciones a la derecha. Sin fondo ni borde. |
| Tarjeta | `.panel`, `[class$="-panel"]`, `<x-estiba.panel>` | Blanca, borde 1 px Regla, radio 8. Cabecera sin franja navy: rótulo 12 px + título 26 px angosto, división inferior 1 px Regla suave. |
| Indicadores | `[class$="-kpis"]`, `[class$="-metrics"]` | Una sola tarjeta dividida en celdas por líneas suaves; cifra primero (36 px angosta), rótulo 12 px, detalle 14 px. Celda en atención: fondo Fondo de sello y cifra en Sello. |
| Tabla | `table` en `.office-app`, `<x-estiba.table>` | Sin líneas verticales; encabezado 12 px Rótulo con regla inferior de 2 px en Tinta; filas con división 1 px Regla suave; hover Fila destacada. |
| Botón principal | `.primary-button`, `.eui-button` | Frost, texto blanco (oscuro: `#5FD3E6` con `#062530`), 44 px, radio 6, peso 700. Uno por decisión. |
| Botón secundario | `.secondary-button` | Blanco, borde 2 px Borde de campo, texto Tinta. |
| Botón de peligro | `.danger-button`, `.eui-button--critical` | Blanco, borde 2 px y texto Crítico; hover fondo de crítico. |
| Campo | `input`, `select`, `textarea` | Blanco, borde 1.5 px Borde de campo, radio 6. |
| Sello de estado | `<x-estiba.signal>` y sellos de cada módulo | 28 px, borde 1.5 px, radio 4, 13 px/700 y forma: éxito/info círculo, atención/reservado/temporal rombo, crítico/bloqueado cuadrado, neutro círculo hueco. Rechazo definitivo: relleno Crítico con texto blanco. |
| Foco | todo control | Contorno 2 px Frost con separación 2 px. |

## 5. Campo: tablet y PDA

- Siempre oscuro (paleta 1.2). Fuente del sistema mientras no se carguen fuentes en la app
  (ver PR 10): títulos `sans-serif-condensed` peso 800, cifras y folios `monospace`.
- Encabezado: fondo Cabecera `#0B1A22`, gajo del área (PNG de `assets-movil/gajo/`), título
  angosto, contexto en Niebla, regla inferior de 3 px en `bright` del área.
- Chips de estado: 28–36 px, radio completo, borde 1 px, ícono de forma (✓ éxito, rombo
  atención, cuadrado crítico, círculo información).
- Acción principal: Frost `#5FD3E6` con texto `#062530`, 60–78 px, radio 12–14, peso 800.
  Deshabilitada: `#24414F` con texto `#9FBCC6`.
- Secundarias de excepción: borde 2 px coral `#7D3F38` y texto `#FF8B7B` sobre transparente.

## 6. Láminas de referencia

| Archivo | Muestra | PR |
|---|---|---|
| `laminas/00-sistema.png`, `html/00-sistema.html` | Paleta, áreas, tipografía | 1–3 |
| `laminas/04-romana.png`, `html/04-romana.html` | Oficina · Romana | 4 |
| `laminas/05-validacion.png`, `html/05-validacion.html` | Oficina · Validación de pallets | 5 |
| `laminas/06-recepcion-materiales.png`, `html/06-recepcion-materiales.html` | Oficina · Recepción de materiales | 6 |
| `laminas/08-tablet-maniobra.png`, `html/08-tablet-maniobra.html` | Tablet · maniobra en ejecución (1280 × 800) | 8 |
| `laminas/09-pda-*.png`, `html/09-pda-*.html` | PDA · escanear, validar, resultado (360 × 740) | 9 |

Las láminas usan cifras, nombres y folios de ejemplo. El marcado HTML es una referencia de
medidas y colores, no código para copiar a producción. Donde una lámina muestra un dato que
la aplicación no tiene (p. ej. “Turno” en la barra de contexto) el PR correspondiente dice
qué se hace.

## 7. Serie de PR

| PR | Rama | Contenido | Entrega |
|---|---|---|---|
| 1 | `diseno/pr1-fundaciones` | Tokens v2, fuentes, temas claro/oscuro, capa transversal de Oficina, este documento | Parche `0001` |
| 2 | `diseno/pr2-shell` | Shell por áreas, gajo, Gerencia separada de Administración | Parche `0002` |
| 3 | `diseno/pr3-componentes` | x-estiba v2, etiqueta de área, catálogo | Parche `0003` |
| 4 | `diseno/pr4-romana` | Romana: filas con avance, expediente | Parche `0004` |
| 5 | `diseno/pr5-validacion` | Validación de pallets: resumen del día por resultado y por línea (incluye API) | Especificación `pr-05-validacion.md` |
| 6 | `diseno/pr6-recepcion-materiales` | Recepciones de materiales: lista y detalle con documento | Especificación `pr-06-recepcion-materiales.md` |
| 7 | `diseno/pr7-movil-tema` | Móvil: tema oscuro único y encabezado por área (independiente de 5 y 6) | Parche `0005` |
| 8 | `diseno/pr8-tablet-maniobra` | Tablet: maniobra en ejecución | Especificación `pr-08-tablet-maniobra.md` |
| 9 | `diseno/pr9-pda-validacion` | PDA: validación de pallets | Especificación `pr-09-pda-validacion.md` |
| 10 (opcional) | `diseno/pr10-fuentes-movil` | Archivo y Azeret en la app | Especificación `pr-10-fuentes-movil.md` |

Pendientes de diseño (heredan PR 1–3 pero no tienen lámina final): Operación ahora,
Panel gerencial, Cámaras, pantallas de acceso. Se diseñan antes de abrir su PR.

Cada `pr-XX-*.md` es a la vez el cuerpo del PR (copiar desde “Qué cambia” hasta el final) y la
especificación. En la descripción del PR en GitHub, las imágenes se enlazan con la ruta del
archivo en la rama, por ejemplo
`https://github.com/JVEsposito/FoliOS-wms/blob/diseno/pr4-romana/docs/diseno/folios-v2/capturas/pr04-romana.png?raw=true`.
