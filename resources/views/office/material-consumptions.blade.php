<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Consumos por OT</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-consumptions.js'])
@endif
@include('office.partials.arranque')
</head><body><main class="office-app" id="consumptionApp">
<x-office.navigation domain="materiales" office="consumos" context="CONSUMOS POR ORDEN" icon="⌖" />
<div class="custody-shell"><header class="custody-heading"><div><p class="eyebrow">MATERIALES · PRODUCCIÓN</p><h1>Consumos por orden de trabajo</h1><p>Compara la receta con lo utilizado. Consulta devoluciones, mermas y quién registró cada movimiento.</p></div><button id="reloadConsumptions" class="secondary-button">Actualizar</button></header>
<p id="consumptionNotice" role="status"></p>
<form id="consumptionFilters" class="custody-card custody-filters">
<label>Temporada<select name="temporada_id"><option value="">Temporada activa</option></select></label>
<label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label>
<label>Bodega de componentes<select name="almacen_id"><option value="">Todas</option></select></label>
<label>Desde<input type="date" name="desde"></label><label>Hasta<input type="date" name="hasta"></label>
<label>Proceso<input name="proceso" maxlength="100" placeholder="Proceso exacto"></label><label>Línea<input name="linea" maxlength="100"></label><label>Turno<input name="turno" maxlength="80"></label>
<label>Operador físico<input name="operador" maxlength="150"></label>
<label>Orden o referencia<input name="q" maxlength="100" placeholder="Referencia o identificador de OT"></label>
<label>Estado<select name="estado"><option value="">Todos</option><option value="borrador">Borrador</option><option value="planificada">Planificada</option><option value="en_proceso">En proceso</option><option value="pendiente_cierre">Pendiente de cierre</option><option value="cerrada">Cerrada</option><option value="cancelada">Cancelada</option></select></label>
<button class="primary-button" type="submit">Consultar</button><button id="exportConsumptions" class="secondary-button" type="button">Exportar Excel</button></form>
<p class="materials-help">El estándar se calcula para la salida real. Un retiro pendiente de cuadratura todavía no acredita lo utilizado. Las unidades se mantienen separadas y un insumo sin receta figura sin estándar.</p>
<p id="consumptionTotal"></p><div id="consumptionOrders"></div>
<nav class="custody-filters" aria-label="Páginas de consumos"><button id="consumptionPrevious" class="secondary-button">Anterior</button><span id="consumptionPage"></span><button id="consumptionNext" class="secondary-button">Siguiente</button></nav>
</div></main></body></html>
