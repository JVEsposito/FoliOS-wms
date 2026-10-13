# PR 6 · Recepciones de materiales

**Rama:** `diseno/pr6-recepcion-materiales` · **Base:** PR 5 · **Entrega:** especificación
**Título del PR:** `feat(materiales): recepciones en lista y expediente FoliOS v2`
**Lámina:** `laminas/06-recepcion-materiales.png` · `html/06-recepcion-materiales.html`
**Estado actual (PR 1–4):** `capturas/pr04-materiales_recepciones.png`

## Objetivo

Pasar de una tabla con botones por fila a **lista + expediente**: a la izquierda las
recepciones como tarjetas con su sello; a la derecha el expediente de la seleccionada con la
foto de la guía o factura (obligatoria para confirmar), la foto de la carga (opcional), los
ítems y la acción que corresponda. El formulario de alta y corrección sigue siendo el diálogo
actual (`#materialReceptionDialog`). No cambian permisos, API ni reglas de confirmación.

## 1. Marcado (`resources/views/office/materials.blade.php`, sección `#materialReceptionsWorkspace`)

1. Indicadores: se reemplaza “ANULADAS” por “SIN FOTO DE DOCUMENTO”:

```blade
<div class="material-receptions-metrics">
  <article><span>BORRADORES</span><strong id="materialReceptionDraftCount">0</strong><small>abiertos</small></article>
  <article data-material-reception-attention><span>SIN FOTO DE DOCUMENTO</span><strong id="materialReceptionNoPhotoCount">0</strong><small>no se pueden confirmar</small></article>
  <article><span>CONFIRMADAS</span><strong id="materialReceptionConfirmedCount">0</strong><small>en esta página</small></article>
  <article><span>FOLIOS VISIBLES</span><strong id="materialReceptionFolioCount">0</strong><small>en esta página</small></article>
</div>
```

2. La tabla `.material-receptions-table-wrap` se reemplaza por:

```blade
<div class="material-receptions-layout">
  <section class="material-receptions-list" aria-labelledby="materialReceptionsListTitle">
    <h3 id="materialReceptionsListTitle">Recepciones</h3>
    <div id="materialReceptionsList" role="list"></div>
  </section>
  <section class="material-reception-detail" id="materialReceptionDetail" aria-live="polite">
    <div class="material-reception-detail__empty" id="materialReceptionDetailEmpty">
      <strong>Selecciona una recepción</strong><span>Verás la foto del documento, los ítems y la acción pendiente.</span>
    </div>
    <div class="is-hidden" id="materialReceptionDetailBody">
      <header class="material-reception-detail__head">
        <div><span id="materialReceptionDetailState">Recepción en borrador</span>
          <h3 id="materialReceptionDetailTitle">Guía —</h3>
          <p id="materialReceptionDetailMeta"></p></div>
        <div class="material-reception-detail__actions" id="materialReceptionDetailActions"></div>
      </header>
      <section class="material-reception-photos material-reception-photos--inline" id="materialReceptionDetailPhotos"></section>
      <div class="material-reception-detail__lines" id="materialReceptionDetailLines"></div>
      <footer class="material-reception-detail__foot" id="materialReceptionDetailFoot"></footer>
    </div>
  </section>
</div>
```

La paginación (`.materials-pagination`) queda bajo la lista, dentro de
`.material-receptions-list`.

## 2. JavaScript (`resources/js/office-material-receptions.js`)

1. `receptionElements`: agregar `noPhotoCount`, `detail`, `detailEmpty`, `detailBody`,
   `detailState`, `detailTitle`, `detailMeta`, `detailActions`, `detailPhotos`,
   `detailLines`, `detailFoot`. Quitar `cancelledCount`.
2. `receptionState.selectedId = null` y `receptionState.selected = null` (registro completo
   del `GET /api/materiales/recepciones/{id}`).
3. `renderReceptionList()`:
   - `noPhotoCount` = registros `borrador` sin `fotos_documento`; `.has-attention` en su
     artículo si es > 0.
   - Plantilla de tarjeta (con `receptionEscape`):

```html
<article class="material-reception-card{id === selectedId ? ' is-selected' : ''}" role="listitem">
  <button type="button" class="material-reception-card__open" data-action="select" data-id="{id}" aria-pressed="{id === selectedId}">
    <span class="material-reception-card__k">Guía</span>
    <strong class="material-reception-card__code">{numero_guia_despacho}</strong>
    <span class="material-reception-card__who">{cliente.nombre}, {proveedor.nombre}</span>
    <small>{receptionDate(fecha_documento)} · {detalles_count} ítems · {folios} folios</small>
  </button>
  {sello}
</article>
```

   - Sello: borrador sin foto → `<span class="material-reception-state material-reception-state--sin-foto">Sin foto</span>`;
     borrador con foto → `--borrador` “Borrador”; `--confirmada` “Confirmada”; `--anulada` “Anulada”.
   - Vacío: `<p class="empty-state">No existen recepciones para los filtros seleccionados.</p>`.
   - Si `selectedId` ya no está en la página, limpiar el expediente.
4. Clic en `data-action="select"` → `selectReception(id)`: `GET /api/materiales/recepciones/{id}`,
   guarda `receptionState.selected`, re-pinta la lista (marca) y llama `renderReceptionDetail()`.
5. `renderReceptionDetail()`:
   - `detailState`: “Recepción en borrador” / “Recepción confirmada” / “Recepción anulada”.
   - `detailTitle`: `Guía {numero_guia_despacho}` (clase de código, ver CSS).
   - `detailMeta`: `Cliente {cliente.nombre} ({cliente.codigo}), proveedor {proveedor.nombre}{orden_compra ? `, OC ${orden_compra}` : ''}`.
   - `detailActions`: **los mismos botones que hoy tenía la fila**, con los mismos `data-action`
     y permisos: “Ver formulario” (`view`), “Registro de muestreo” (`sampling`, solo
     confirmada), y para administrador “Editar” (`edit`, no anulada) y “Eliminar” (`delete`).
     El manejador de clic actual se registra también sobre `detailActions`.
   - Fotos: **segunda instancia** de `createReceptionPhotosPanel(detailPhotos, { getReception: () => receptionState.selected, … })`
     con los mismos `token/uuid/canManage/canAdminister`. `onChange` actualiza
     `selected.fotos`/`fotos_documento` y re-pinta lista y pie. `onConfirm` hace lo mismo que el
     del diálogo (POST `/confirmar` con `operacion_id` y `version_conocida`) y luego recarga lista
     y expediente.
   - `detailLines`: por cada `detalles[]`:

```html
<article class="material-reception-line">
  <strong>{item.nombre}</strong><code>{item.codigo}</code><b>{cantidad} {item.unidad_medida}</b>
  <small>{resumenBultos}</small>
</article>
```

     `cantidad` = `cantidad_aceptada ?? cantidad_recibida`. `resumenBultos`: “{n} bultos de
     {tamaño}” (tamaño = mayor `bultos[].cantidad`; si hay un bulto menor, “{n-1} de {a} y 1 de {b}”),
     más “, vence mm-aaaa” (primer `fecha_vencimiento`) y “, lote del proveedor {lote}” si existen;
     sin vencimiento: “, sin vencimiento”.
   - `detailFoot`: borrador → “Al confirmar se generan {total de bultos} folios.” (el botón
     **Confirmar recepción** lo dibuja el panel de fotos); confirmada → “Generó {n} folios:
     {primero} a {último}” desde `snapshot_confirmacion.folios`; anulada → “Recepción anulada.”
6. Al cerrar el diálogo, si `receptionState.selected` coincide con lo editado, volver a
   llamar `selectReception`.

## 3. Panel de fotos (`resources/js/office-material-reception-photos.js`)

Solo cambian textos y clases del encabezado de cada grupo (la prueba de navegador sigue
buscando `[data-upload="documento"]` y el botón “Confirmar recepción”):

```js
`<h4><span>${tipo === 'documento' ? 'Guía o factura' : 'Foto de la carga'}</span>` +
`<em class="${tipo === 'documento' ? 'is-required' : ''}">${tipo === 'documento' ? 'Obligatoria' : 'Opcional'}, ${group.length} de 5</em></h4>`
```

Texto de la etiqueta de carga: “Agregar foto” (documento) y “Subir fotos de referencia”
(referencial). El `<h3>Fotos</h3>` inicial se mantiene en el diálogo y se oculta con CSS en
`.material-reception-photos--inline`.

## 4. CSS (al final de `resources/css/office-materials.css`)

```css
/* FoliOS v2 · Recepciones de materiales (PR 6). */
.office-app .material-receptions-workspace { min-width: 0 !important; }
.office-app .material-receptions-metrics > article.has-attention { background: var(--seal-bg) !important; }
.office-app .material-receptions-metrics > article.has-attention > strong { color: var(--seal) !important; }

.office-app .material-receptions-layout { display: grid; grid-template-columns: minmax(300px, 1fr) minmax(0, 2fr); gap: 20px; align-items: start; }
.office-app .material-receptions-list { overflow: hidden; border: 1px solid var(--corporate-border); border-radius: var(--radius-card); background: var(--corporate-card); }
.office-app .material-receptions-list > h3 { margin: 0; padding: 18px 20px 12px; font-size: 1.625rem !important; font-stretch: var(--font-condensed-stretch); font-weight: 800; }
.office-app .material-reception-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: start; padding: 14px 20px; border-top: 1px solid var(--line-soft); }
.office-app .material-reception-card.is-selected { background: var(--area-tint); box-shadow: inset 0 0 0 2px var(--area-ink); }
.office-app .material-reception-card__open { display: grid; gap: 2px; min-width: 0; padding: 0 !important; border: 0 !important; background: none !important; color: var(--text-strong) !important; text-align: left; cursor: pointer; min-height: 0 !important; }
.office-app .material-reception-card__k { color: var(--text-subtle); font-size: .75rem; font-weight: 500; }
.office-app .material-reception-card__code { font-family: var(--font-mono) !important; font-size: 1.0625rem; letter-spacing: -.03em; }
.office-app .material-reception-card__who { font-size: .875rem; }
.office-app .material-reception-card small { color: var(--text-subtle); font-size: .78125rem; }

.office-app .material-reception-state { display: inline-flex; align-items: center; gap: 7px; height: 28px; padding: 0 10px; border: 1.5px solid currentColor; border-radius: 4px; background: var(--corporate-card); font-size: .8125rem; font-weight: 700; white-space: nowrap; }
.office-app .material-reception-state::before { width: 8px; height: 8px; content: ""; background: currentColor; }
.office-app .material-reception-state--borrador { border-style: dashed; color: var(--text-strong); }
.office-app .material-reception-state--borrador::before { display: none; }
.office-app .material-reception-state--sin-foto { color: var(--seal); }
.office-app .material-reception-state--sin-foto::before { transform: rotate(45deg); }
.office-app .material-reception-state--confirmada { color: var(--success-text); }
.office-app .material-reception-state--confirmada::before { width: 9px; height: 5px; margin-top: -3px; border-bottom: 2px solid currentColor; border-left: 2px solid currentColor; background: none; transform: rotate(-45deg); }
.office-app .material-reception-state--anulada { color: var(--text-subtle); text-decoration: line-through; }

.office-app .material-reception-detail { border: 1px solid var(--corporate-border); border-radius: var(--radius-card); background: var(--corporate-card); }
.office-app .material-reception-detail__empty { display: grid; gap: 6px; padding: 48px 24px; color: var(--text-subtle); text-align: center; }
.office-app .material-reception-detail__empty strong { color: var(--text-strong); font-size: 1.125rem; }
.office-app .material-reception-detail__head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 20px; padding: 18px 22px; border-bottom: 2px dashed var(--corporate-border); }
.office-app .material-reception-detail__head span { color: var(--text-subtle); font-size: .75rem; }
.office-app .material-reception-detail__head h3 { margin: 4px 0; font-family: var(--font-mono) !important; font-size: 1.875rem !important; font-stretch: 100% !important; font-weight: 700; letter-spacing: -.04em; }
.office-app .material-reception-detail__head p { margin: 0; color: var(--text-secondary); font-size: .875rem; }
.office-app .material-reception-detail__actions { display: flex; flex-wrap: wrap; gap: 8px; }

.office-app .material-reception-photos--inline { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border-bottom: 1px solid var(--line-soft); }
.office-app .material-reception-photos--inline > h3 { display: none; }
.office-app .material-reception-photos--inline > section { display: grid; gap: 12px; padding: 18px 22px; }
.office-app .material-reception-photos--inline > section + section { border-left: 1px solid var(--line-soft); }
.office-app .material-reception-photos h4 { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 0; }
.office-app .material-reception-photos h4 > span { font-size: 1.25rem; font-stretch: var(--font-condensed-stretch); font-weight: 800; }
.office-app .material-reception-photos h4 > em { height: 24px; padding: 0 8px; border: 1.5px dashed var(--input-border); border-radius: 4px; color: var(--text-subtle); font-size: .75rem; font-style: normal; font-weight: 700; line-height: 21px; }
.office-app .material-reception-photos h4 > em.is-required { border-style: solid; border-color: var(--success-text); color: var(--success-text); }
.office-app .material-reception-photo-grid img { width: 116px; height: 140px; border-radius: 6px; object-fit: cover; }
.office-app .material-reception-photos label { display: grid; place-items: center; min-height: 140px; border: 2px dashed var(--input-border); border-radius: 6px; color: var(--frost); font-weight: 700; cursor: pointer; }
.office-app .material-reception-photos label input { position: absolute; width: 1px; height: 1px; opacity: 0; }

.office-app .material-reception-detail__lines { padding: 6px 22px; }
.office-app .material-reception-line { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 4px 18px; align-items: baseline; padding: 12px 0; border-bottom: 1px solid var(--line-soft); }
.office-app .material-reception-line:last-child { border-bottom: 0; }
.office-app .material-reception-line strong { font-size: 1rem; }
.office-app .material-reception-line code { color: var(--text-subtle); font-size: .8125rem; font-weight: 700; }
.office-app .material-reception-line b { font-size: 1rem; }
.office-app .material-reception-line small { grid-column: 1 / -1; color: var(--text-subtle); font-size: .8125rem; }
.office-app .material-reception-detail__foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 22px; border-top: 1px solid var(--line-soft); background: var(--surface-muted); border-radius: 0 0 var(--radius-card) var(--radius-card); }

@media (max-width: 1279px) {
  .office-app .material-receptions-layout { grid-template-columns: 1fr; }
}
```

El botón “Confirmar recepción” del panel de fotos (`button[data-confirm]`) toma el estilo de
botón principal: agregarle `class="primary-button"` en la plantilla del panel.

## 5. Pruebas

- `InterfazOficinaMaterialesTest`: ver `id="materialReceptionDetail"`,
  `id="materialReceptionNoPhotoCount"` y `data-material-reception-attention`.
- `tests/Browser/OfficeMaterialReceptionPhotosTest.mjs` sigue verde (no cambia el contrato).
- Prueba de navegador nueva (mismo patrón de rutas simuladas): seleccionar una tarjeta pide
  `GET /api/materiales/recepciones/{id}`, marca `aria-pressed="true"` y muestra
  “Guía {n}”; con un borrador sin foto, “Confirmar recepción” está deshabilitado en el expediente.

## Criterios de aceptación

- [ ] A 1440 px, lista y expediente lado a lado como en la lámina; bajo 1280 px, apilados y sin
      desplazamiento horizontal (corrige el desborde previo de esta pantalla).
- [ ] Confirmar desde el expediente produce exactamente las mismas llamadas que desde el diálogo.
- [ ] Ningún botón por fila: las acciones viven en el expediente.
- [ ] `php artisan test`, `npm run test:js` y `npm run test:browser` verdes.
