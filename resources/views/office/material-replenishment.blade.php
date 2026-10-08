<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Reposición de materiales</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-replenishment.js'])
@endif
</head><body><main class="office-app" id="materialReplenishmentApp">
<x-office.navigation domain="materiales" office="reposicion" context="REPOSICIÓN" icon="▦" />
<div class="custody-shell"><header class="custody-heading"><div><p class="eyebrow">MATERIALES · BODEGA CENTRAL</p><h1>Reposición</h1><p>Prioriza compras según el disponible para entregar y las salidas reales de bodega.</p></div><button id="reload" class="secondary-button">Actualizar</button></header>
<p id="notice" role="status"></p><section class="custody-card"><p id="summary"></p>
<form id="filters" class="custody-filters"><label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label><label>Categoría<select name="categoria"><option value="">Todas</option></select></label>
<label>Estado<select name="estado"><option value="">Pendientes de reposición</option><option value="quiebre">Quiebre</option><option value="bajo_minimo">Bajo mínimo</option><option value="reponer">Reponer</option><option value="normal">Normal</option><option value="sobre_maximo">Sobre máximo</option></select></label>
<label>Consumo<select name="dias"><option value="">Predeterminado</option><option value="30">30 días</option><option value="60">60 días</option><option value="90">90 días</option></select></label><button type="button" id="excel" class="primary-button">Exportar Excel</button></form>
<div class="custody-table-wrap"><table class="custody-table"><thead id="columns"></thead><tbody id="rows"></tbody></table></div></section>
<section class="custody-card" id="detail" hidden><h2 id="itemHeading"></h2><p id="itemSummary"></p><h3>Consumo por semana</h3><div id="weeks"></div>
<h3>Movimientos del período</h3><div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Fecha</th><th>Folio</th><th>Tipo</th><th>Salida neta</th><th>Motivo</th></tr></thead><tbody id="movements"></tbody></table></div><div id="pages"></div>
<h3>Cambios de niveles</h3><div id="history"></div></section></div></main></body></html>
