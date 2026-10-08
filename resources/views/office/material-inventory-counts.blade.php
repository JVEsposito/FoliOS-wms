<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Toma de inventario</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-inventory-counts.js'])
@endif
</head><body><main class="office-app" id="inventoryCountApp">
<x-office.navigation domain="materiales" office="tomas" context="TOMA DE INVENTARIO" icon="⌖" />
<div class="custody-shell"><header class="custody-heading"><div><p class="eyebrow">MATERIALES</p><h1>Toma de inventario</h1><p>Conteo ciego por cámara. La operación continúa durante la toma.</p></div><button id="reload" class="secondary-button">Actualizar</button></header>
<p id="notice" role="status"></p>
<section class="custody-card"><h2>Nueva toma</h2><form id="create" class="custody-form"><label>Cámaras<select name="camara_ids" multiple required></select></label><label>Categoría<select name="categoria"><option value="">Todas</option></select></label><label>Camareros asignados<select name="camarero_ids" multiple required></select></label><button class="primary-button">Crear borrador</button></form></section>
<section class="custody-card"><h2>Tomas</h2><select id="takes"><option value="">Selecciona una toma</option></select></section>
<section class="custody-card" id="detail" hidden><h2 id="heading"></h2><p id="scope"></p><p id="metrics"></p><div id="controls" class="custody-filters"></div><p id="progress"></p>
<form id="filters" class="custody-filters"><label>Resultado<select name="tipo"><option value="">Todos</option><option value="faltante">Faltante</option><option value="sobrante">Sobrante</option><option value="diferencia_cantidad">Diferencia de cantidad</option><option value="coincide">Coincide</option></select></label><label>Ítem<select name="item_id"><option value="">Todos</option></select></label><label>Categoría<select name="categoria"><option value="">Todas</option></select></label></form>
<div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Folio / posición</th><th>Ítem</th><th>Esperado</th><th>Contado</th><th>Diferencia absoluta / %</th><th>Resultado / acción</th></tr></thead><tbody id="rows"></tbody></table></div>
<h3>Totales por ítem y categoría</h3><div id="totals"></div></section>
</div></main>
<dialog id="decision"><form id="decide"><h2>Resolver diferencia</h2><p id="decisionContext"></p><label>Acción<select name="accion" required><option value="ajustar">Ajustar</option><option value="reubicar">Reubicar</option><option value="reubicar_y_ajustar">Reubicar y ajustar</option><option value="aceptar_sin_ajuste">Aceptar sin ajuste</option><option value="recontar">Recontar</option></select></label><label id="destinationLabel">Posición destino<select name="posicion_destino_id"></select></label><label>Motivo<textarea name="motivo" required maxlength="2000"></textarea></label><p id="decisionError" role="alert"></p><button class="primary-button">Guardar decisión</button><button type="button" id="cancelDecision" class="secondary-button">Cancelar</button></form></dialog>
</body></html>
