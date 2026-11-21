<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Rebaja por estándar</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/css/office-material-standards.css', 'resources/js/office-material-standard-consumption.js'])
@endif
@include('office.partials.arranque')
</head><body><main class="office-app" id="standardsApp" data-page="rebaja-estandar" data-folios-workspace="registro">
<x-office.navigation domain="materiales" office="rebajas-estandar" context="REBAJA POR ESTÁNDAR" icon="⇣" />
<div class="custody-shell">
<header class="custody-heading" data-folios-masthead><div><p class="eyebrow">MATERIALES · PRODUCCIÓN</p><h1>Rebaja por estándar</h1><p>Descuenta del almacén de Packing los materiales de lo validado en PT, según el estándar de cada embalaje. Lo que falte por saldo queda pendiente y se descuenta en la próxima rebaja.</p></div>
<div class="standards-heading-actions"><button id="rebateHistoryReload" type="button" class="secondary-button">Ver rebajas</button></div></header>
<p id="rebateNotice" role="status" aria-live="polite"></p>
<form id="rebateFilters" class="custody-filters standards-filters" data-folios-toolbar>
<label>Día de proceso desde<input name="desde" type="date" required></label>
<label>Hasta<input name="hasta" type="date" required></label>
<label>Almacén de Packing<select name="almacen_id" required></select></label>
<label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label>
<label>Marca<input name="marca" maxlength="150" placeholder="Todas"></label>
<label>Proceso<input name="proceso" maxlength="100" placeholder="Todos"></label>
<button class="primary-button" type="submit">Vista previa</button>
</form>
<p id="rebateTotals" class="custody-filter-summary"></p>
<section class="custody-card" id="rebateGroupsCard" hidden><h2 class="standards-section-title">Producción y ajustes por rebajar</h2>
<p class="materials-help">Día de proceso es la fecha de embalaje del pallet. Un pallet ya incluido en una rebaja no vuelve a aparecer: si después se corrige o se anula, aparece como ajuste (positivo si falta descontar, negativo si se descontó de más). Los grupos sin estándar o con insumos sin homologar no se pueden rebajar.</p>
<div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th><input type="checkbox" id="rebateAll" aria-label="Elegir todos los grupos listos" checked></th><th>Día</th><th>Cliente</th><th>Marca</th><th>Proceso</th><th>Embalaje</th><th>Cajas</th><th>Estándar</th></tr></thead><tbody id="rebateGroups"></tbody></table></div></section>
<section class="custody-card" id="rebateMaterialsCard" hidden><h2 class="standards-section-title">Materiales a descontar</h2>
<div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th>Ítem</th><th>Rebaja</th><th>Teórico</th><th>Fracción anterior</th><th>Pendiente anterior</th><th>A descontar</th><th>Disponible</th><th>Se descuenta</th><th>Queda pendiente</th></tr></thead><tbody id="rebateMaterials"></tbody></table></div>
<form id="rebateConfirm" class="standards-confirm" hidden><label>Observación<input name="observacion" maxlength="500" placeholder="Opcional"></label><button id="rebateSubmit" class="primary-button" type="submit">Confirmar rebaja</button></form>
</section>
<section class="custody-card" id="rebateBalancesCard" hidden><h2 class="standards-section-title">Pendientes y fracciones del almacén</h2>
<p class="materials-help">Pendiente positivo: falta descontar por falta de saldo. Negativo: se descontó de más por una corrección posterior y queda a favor. La fracción es lo que aún no completa una unidad (Entero) o una milésima (Decimal).</p><div id="rebateBalances"></div></section>
<section class="custody-card" id="rebateHistoryCard" hidden><h2 class="standards-section-title">Rebajas confirmadas</h2>
<form id="rebateExport" class="standards-confirm standards-export"><label>Confirmadas desde<input name="desde" type="date" required></label><label>Hasta<input name="hasta" type="date" required></label>
<div class="standards-heading-actions"><button type="submit" class="secondary-button" value="consumos">Excel de materiales</button><button type="submit" class="secondary-button" value="produccion">Excel de producción</button></div></form>
<div id="rebateHistory"></div><div id="rebateDetail" aria-live="polite"></div></section>
</div>
</main></body></html>
