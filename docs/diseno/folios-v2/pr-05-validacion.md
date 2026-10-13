# PR 5 · Validación de pallets

**Rama:** `diseno/pr5-validacion` · **Base:** PR 4 · **Entrega:** especificación (implementa el agente)
**Título del PR:** `feat(validacion): resumen del día y registro FoliOS v2`
**Lámina:** `laminas/05-validacion.png` · `html/05-validacion.html`
**Estado actual (PR 1–4):** `capturas/pr04-validacion.png`

## Objetivo

1. Indicadores del día: pallets validados, aprobados, observados, rechazo definitivo y catálogo.
2. Bloque nuevo **“Por línea, hoy”**: tres tarjetas (líneas 1, 2 y 3) con validados,
   observados y rechazos, y quién validó.
3. Registro de validaciones como en la lámina: folio en Azeret, sellos de resultado, fila
   observada con fondo de sello, “Validador y jornada” en una columna.

No cambia ninguna regla de validación, corrección ni exportación RRPP-01.

## 1. Backend: `GET /api/validacion/registro/resumen`

Se **agrega** la clave `dia`; las claves actuales (`temporada`, `articulos_activos`,
`origenes_activos`, `combinaciones_activas`, `observados`) no cambian (las cubre
`ValidacionPalletApiTest`).

```json
"dia": {
  "fecha": "2026-10-13",
  "validados": 186,
  "aprobados": 171,
  "observados": 12,
  "rechazados": 3,
  "conflictos": 0,
  "por_linea": [
    { "linea": 1, "validados": 62, "observados": 3, "rechazados": 2,
      "validadores": [ { "nombre": "Jorge Valdés", "dispositivo": "PDA 04" } ] },
    { "linea": 2, "validados": 71, "observados": 7, "rechazados": 0, "validadores": [] },
    { "linea": 3, "validados": 53, "observados": 2, "rechazados": 1, "validadores": [] }
  ]
}
```

Reglas (en `ValidacionPalletController::resumen`):

- Base: `aplicarFiltros(...)` con los filtros recibidos **excepto `resultado` y `estado`**
  (los indicadores no se vacían al filtrar la tabla por resultado).
- **Vigente** = último intento de cada `numero_folio` dentro de la base, ordenando por
  `recibido_servidor_at` desc y `numero_intento` desc (mismo criterio que `miSesion`).
- `validados` = folios vigentes con `estado = aceptada`. `aprobados/observados/rechazados` =
  vigentes aceptados por `resultado`. `conflictos` = vigentes con `estado = conflicto`.
- `por_linea`: siempre las líneas 1, 2 y 3 (en ese orden, con ceros si no hay datos), con el
  mismo cálculo restringido a `linea_proceso`. `validadores`: usuarios distintos de los
  vigentes de esa línea, ordenados por nombre, con `dispositivo.codigo` de su último intento.
- `fecha`: la fecha del filtro `fecha` (o hoy en `config('app.operational_timezone')`).
- Prueba nueva en `ValidacionPalletApiTest`: con intentos aprobado→observado del mismo folio
  cuenta 1 observado; `?resultado=aprobado` no cambia `dia`; líneas sin datos en cero.

## 2. Pantalla `resources/views/office/validation.blade.php`

### 2.1 Encabezado

```blade
<header class="validation-heading">
  <div><p class="eyebrow">CONTROL DE INGRESO</p><h1>Validación de pallets</h1>
    <p>La aprobación crea el folio pendiente de prefrío; observar conserva la posibilidad de corregir y volver a validar.</p></div>
  <div class="validation-heading__actions">
    <label><span>Temporada visible</span><select id="seasonSelector"></select></label>
    <a class="secondary-button" href="/oficina/validacion/etiquetas">Etiquetas PT</a>
    <button class="primary-button" id="exportValidationRegisterButton" type="button">Descargar RRPP-01</button>
  </div>
</header>
```

- Se quita la clase `panel` del encabezado (el PR 1 ya lo dibuja sin tarjeta).
- `reloadValidationButton` pasa a la cabecera del registro (2.4) como botón secundario
  “Actualizar”. `exportValidationRegisterButton` sale del formulario: su manejador ya lee
  `new FormData(elements.filters)`; verificar que no dependa de estar dentro del `form`.

### 2.2 Indicadores

```blade
<div class="validation-metrics">
  <article><span>PALLETS VALIDADOS</span><strong id="dayValidatedCount">0</strong><small id="dayValidatedLabel">hoy</small></article>
  <article class="validation-metric--success"><span>APROBADOS</span><strong id="dayApprovedCount">0</strong><small>con folio pendiente de prefrío</small></article>
  <article class="validation-metric--attention" data-validation-attention><span>OBSERVADOS</span><strong id="observedCount">0</strong><small>corrección pendiente</small></article>
  <article class="validation-metric--critical"><span>RECHAZO DEFINITIVO</span><strong id="dayRejectedCount">0</strong><small>no vuelven a validarse</small></article>
  <article><span>CATÁLOGO</span><strong>v<span id="catalogVersion">—</span></strong><small><span id="activeArticleCount">0</span> artículos, <span id="activeOriginCount">0</span> orígenes, <span id="activeCombinationCount">0</span> combinaciones</small></article>
</div>
```

- `observedCount` muestra `dia.observados` (antes `observados`).
- `.has-attention` en el artículo de observados cuando `dia.observados > 0` (mismo patrón que
  Romana). `dayValidatedLabel`: “hoy” si `dia.fecha` es hoy; si no, “el dd-mm-aaaa”.

### 2.3 Por línea

Entre indicadores y registro:

```blade
<section class="panel validation-lines" aria-labelledby="validationLinesTitle">
  <h2 id="validationLinesTitle">Por línea, hoy</h2>
  <div class="validation-lines__grid" id="validationLines"></div>
</section>
```

Plantilla por línea (JS, con `escapeHtml`):

```html
<article class="validation-line">
  <header><h3>Línea {linea}</h3><span>{validadores: "Nombre, PDA 04 · Nombre, PDA 02" | "Sin validaciones"}</span></header>
  <div class="validation-line__cells">
    <span><b>{validados}</b>validados</span>
    <span class="{observados > 0 ? 'is-attention' : ''}"><b>{observados}</b>observados</span>
    <span class="{rechazados > 0 ? 'is-critical' : ''}"><b>{rechazados}</b>{rechazados === 1 ? 'rechazo' : 'rechazos'}</span>
  </div>
</article>
```

El título dice “Por línea, hoy” o “Por línea, dd-mm-aaaa” según `dia.fecha`.

### 2.4 Registro

Cabecera del panel:

```blade
<div class="validation-panel__heading">
  <div><p class="eyebrow">TRAZABILIDAD</p><h2>Validaciones recientes</h2></div>
  <button class="secondary-button" id="reloadValidationButton" type="button">↻ Actualizar</button>
</div>
<form class="validation-filters" id="validationFilters"> … mismos campos, en este orden:
  folio, fecha, linea_proceso, turno, user_id (id validationUserFilter), resultado, estado,
  <button class="secondary-button" type="submit">Filtrar</button>
</form>
<p class="validation-help">… texto actual … <button class="validation-link" id="downloadBlankValidationRegisterButton" type="button">Descargar planilla en blanco</button></p>
```

Encabezado de tabla (6 columnas):
`Folio | Artículo | Origen | Resultado | Validador y jornada | <span class="sr-only">Acciones</span>`

Fila (reemplaza la plantilla de `renderHistory`):

```html
<tr class="validation-row validation-row--{resultClass}">
  <td><strong class="validation-code">{numero_folio}</strong>
      <small>{Pallet completo | Saldo}, {cantidad_cajas} cajas{numero_intento > 1 ? ` · intento ${n}` : ''}</small></td>
  <td><strong>{especie} {variedad} {calibre}</strong><small>{envase} · {categoría o "Sin categoría"}</small></td>
  <td><strong>{csgTexto}</strong><small>{cliente} · {marca}</small></td>
  <td>{sello}{motivo ? `<small>${motivo legible}</small>` : ''}</td>
  <td><strong>{usuario.nombre}</strong><small>{jornada}</small>{corregido ? `<small>Corregido {fecha}</small>` : ''}</td>
  <td class="validation-row__action">{puede_corregir ? '<button class="validation-correction-button" data-correct-validation="{id}" type="button">Corregir</button>' : ''}</td>
</tr>
```

- `resultClass`: `conflicto` si `estado === 'conflicto'`; si no, `resultado`.
- `csgTexto`: `catalogo.composicion` es un arreglo de líneas con `csg` (lo arma
  `ServicioCorreccionValidacionPallet`/validación). Con 2 o más CSG **distintos** →
  “CSG 1802 y CSG 0977” (tres o más: “CSG a, CSG b y CSG c”); con uno o sin composición →
  “CSG {catalogo.origen.csg}”.
- `jornada`: “Línea 2, turno A, 08:12 · PDA 02”; sin línea/turno: “Sin jornada histórica · {fecha y hora}”.
- Sello (`.validation-result` existente, nuevo marcado):
  - aprobado: `<span class="validation-result validation-result--aprobado"><i aria-hidden="true"></i>Aprobado</span>`
  - observado: `…--observado` “Observado” · rechazado: `…--rechazado` “Rechazo definitivo” ·
    conflicto: `…--conflicto` “Conflicto”.
- Fila vacía: `colspan="6"`.

## 3. CSS (al final de `resources/css/office-validation.css`)

```css
/* FoliOS v2 · Validación (PR 5). */
.office-app .validation-workspace { padding-inline: 12px !important; }
.office-app .validation-heading__actions { flex-wrap: wrap; align-items: flex-end; gap: 10px; }

.office-app .validation-metrics > article.validation-metric--success > strong { color: var(--success-text) !important; }
.office-app .validation-metrics > article.validation-metric--critical > strong { color: var(--danger-text) !important; }
.office-app .validation-metrics > article.has-attention { background: var(--seal-bg) !important; }
.office-app .validation-metrics > article.has-attention > strong { color: var(--seal) !important; }

.office-app .validation-lines { display: grid !important; grid-template-columns: 140px minmax(0, 1fr); overflow: hidden; }
.office-app .validation-lines > h2 { margin: 0; padding: 18px; border-right: 1px solid var(--line-soft); font-size: 1.625rem !important; font-stretch: var(--font-condensed-stretch); line-height: 1.05; }
.office-app .validation-lines__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); }
.office-app .validation-line { display: grid; gap: 8px; padding: 14px 16px; border-left: 1px solid var(--line-soft); }
.office-app .validation-line:first-child { border-left: 0; }
.office-app .validation-line > header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; }
.office-app .validation-line h3 { margin: 0; font-size: 1.25rem !important; font-stretch: var(--font-condensed-stretch); font-weight: 800; }
.office-app .validation-line > header > span { color: var(--text-subtle); font-size: .8125rem; }
.office-app .validation-line__cells { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; }
.office-app .validation-line__cells > span { display: grid; gap: 2px; padding: 6px 8px; border-radius: 4px; background: var(--surface-muted); color: var(--text-subtle); font-size: .75rem; }
.office-app .validation-line__cells b { color: var(--text-strong); font-size: 1.125rem; font-stretch: var(--font-condensed-stretch); font-weight: 800; }
.office-app .validation-line__cells > .is-attention { background: var(--seal-bg); }
.office-app .validation-line__cells > .is-attention b { color: var(--seal); }
.office-app .validation-line__cells > .is-critical b { color: var(--danger-text); }

.office-app .validation-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; padding: 0 18px 12px; }
.office-app .validation-link { padding: 0; border: 0; background: none; color: var(--frost); font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }

.office-app .validation-table tr.validation-row > td { padding: 12px !important; vertical-align: top; }
.office-app .validation-table tr.validation-row--observado > td { background: var(--seal-bg) !important; }
.office-app .validation-table .validation-code { font-family: var(--font-mono) !important; font-size: 1rem; letter-spacing: -.03em; white-space: nowrap; }
.office-app .validation-table td strong { display: block; color: var(--text-strong); font-size: .90625rem; font-weight: 600; }
.office-app .validation-table td small { display: block; margin-top: 2px; color: var(--text-subtle); font-size: .78125rem; }
.office-app .validation-result { display: inline-flex !important; align-items: center; gap: 7px; height: 28px; padding: 0 10px !important; border: 1.5px solid currentColor !important; border-radius: 4px !important; background: var(--corporate-card) !important; font-size: .8125rem !important; font-weight: 700 !important; text-transform: none !important; white-space: nowrap; }
.office-app .validation-result > i { width: 8px; height: 8px; flex: 0 0 8px; background: currentColor; }
.office-app .validation-result--aprobado { color: var(--success-text) !important; }
.office-app .validation-result--aprobado > i { width: 9px; height: 5px; margin-top: -3px; border-bottom: 2px solid currentColor; border-left: 2px solid currentColor; background: none; transform: rotate(-45deg); }
.office-app .validation-result--observado { color: var(--seal) !important; }
.office-app .validation-result--observado > i { transform: rotate(45deg); }
.office-app .validation-result--rechazado { border-color: var(--critical-fill) !important; background: var(--critical-fill) !important; color: var(--on-critical-fill) !important; }
.office-app .validation-result--conflicto { color: var(--danger-text) !important; }
.office-app .validation-row__action { text-align: right; white-space: nowrap; }
.office-app .validation-correction-button { padding: 0 !important; border: 0 !important; background: none !important; color: var(--frost) !important; font-weight: 700; text-decoration: underline; min-height: 0 !important; }

@media (max-width: 1199px) {
  .office-app .validation-lines { grid-template-columns: 1fr; }
  .office-app .validation-lines > h2 { border-right: 0; border-bottom: 1px solid var(--line-soft); }
  .office-app .validation-lines__grid { grid-template-columns: 1fr; }
  .office-app .validation-line { border-left: 0; border-top: 1px solid var(--line-soft); }
}
```

## 4. Pruebas

- `InterfazOficinaValidacionTest`: ver `id="validationLines"`, `id="dayValidatedCount"`,
  `data-validation-attention`, encabezado de 6 columnas; siguen vigentes sus aserciones.
- `ValidacionPalletApiTest`: casos de `dia` descritos en § 1.
- JS: si se extrae el armado de `csgTexto` y `jornada` a `resources/js/shared/validation-row.js`,
  probarlo con `node --test`.

## Criterios de aceptación

- [ ] Con datos de tres líneas, la pantalla coincide con la lámina en estructura, medidas y colores
      (los textos de acción son los de la aplicación).
- [ ] Filtrar por resultado no cambia indicadores ni “Por línea”.
- [ ] A 1280 px sin desplazamiento horizontal; bajo 1200 px las líneas se apilan.
- [ ] Contraste: “Rechazo definitivo” blanco sobre `#B3261E` (claro) y `#2A0B07` sobre `#FF8B7B` (oscuro).
- [ ] `php artisan test` y `npm run test:js` verdes.
