<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Ítems de materiales</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-items.js'])
@endif
</head><body><main class="office-app">
<x-office.navigation domain="materiales" office="items" context="CATÁLOGO DE ÍTEMS" icon="▦" />
<div class="custody-shell"><header class="custody-heading"><div><p class="eyebrow">MATERIALES</p><h1>Ítems</h1><p>Descarga el catálogo, edita sus columnas y vuelve a importarlo desde Catálogos. Los encabezados grises del Excel son informativos.</p></div><button id="itemReload" class="secondary-button">Actualizar</button></header>
<section class="custody-card"><form id="itemFilters" class="custody-filters"><label>Temporada<select name="temporada_id"><option value="">Todas</option></select></label><label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label><label>Categoría<select name="categoria"><option value="">Todas</option></select></label>
<label>Tipo<select name="tipo_item"><option value="">Todos</option><option value="insumo">Insumo</option><option value="material_mp">Material MP</option><option value="material_pt">Material PT</option><option value="sin_tipo">Sin tipo</option></select></label>
<label>Estado<select name="estado"><option value="todos">Todos</option><option value="activos">Activos</option><option value="inactivos">Inactivos</option></select></label></form>
<div class="materials-actions"><button class="primary-button" type="button" data-export-catalog="xlsx">Descargar catálogo</button><button class="secondary-button" type="button" data-export-catalog="csv">Descargar CSV</button></div>
<p id="itemSummary"></p><p id="itemNotice" role="alert"></p>
<div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Foto</th><th>Temporada</th><th>Cliente</th><th>Código</th><th>Ítem</th><th>Categoría</th><th>Tipo</th><th>Unidad</th><th>Estado</th><th>Mín. / reorden / máx.</th></tr></thead><tbody id="itemRows"></tbody></table></div>
<div class="materials-actions"><button id="itemPrevious" class="secondary-button">Anterior</button><button id="itemNext" class="secondary-button">Siguiente</button></div></section></div></main></body></html>
