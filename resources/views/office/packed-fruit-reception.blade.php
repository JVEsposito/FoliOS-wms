@extends('office.packed-fruit-layout', ['titulo' => 'Recepción de fruta embalada', 'dominio' => 'frigorifico', 'oficina' => 'recepcion-fruta-embalada', 'script' => 'resources/js/office-packed-fruit-reception.js'])
@section('workspace')
<section class="panel rfe-panel"><div class="rfe-actions"><h2>Recepciones</h2><button class="primary-button" id="newReception" type="button">Nueva recepción</button></div>
<form id="filterForm"><div class="rfe-grid"><label>Desde<input name="desde" type="date"></label><label>Hasta<input name="hasta" type="date"></label><label>Cliente<select name="cliente_id"></select></label><label>Planta de origen<select name="planta_origen_id"></select></label><label>Estado<select name="estado"><option value="">Todos</option><option value="borrador">Borrador</option><option value="aceptada">Aceptada</option><option value="anulada">Anulada</option></select></label></div><button class="secondary-button">Filtrar</button></form>
<div class="rfe-table-scroll"><table class="rfe-table"><thead><tr><th>Recepción</th><th>Cliente</th><th>Planta</th><th>Guía</th><th>Estado</th><th>Pallets</th><th></th></tr></thead><tbody id="receptionList"></tbody></table></div><div class="rfe-actions"><button id="previousPage" class="secondary-button" type="button">Anterior</button><span id="pageLabel"></span><button id="nextPage" class="secondary-button" type="button">Siguiente</button></div></section>
<section class="panel rfe-panel is-hidden" id="receptionEditor"><h2 id="editorTitle">Nueva recepción</h2><p class="rfe-note" id="seasonLabel"></p><p class="rfe-warning">Se guarda en borrador. Los pallets todavía no ingresan al inventario.</p>
<form id="receptionForm"><fieldset id="headerFields"><div class="rfe-grid"><label><span>Cliente *</span><select name="cliente_id" required></select></label>
<label><span>Planta de origen *</span><select name="planta_origen_id" required></select></label>
<label><span>N° de guía *</span><input name="numero_guia" type="text" required maxlength="50"></label>
<label><span>Servicio *</span><select name="servicio"><option value="almacenaje">Almacenaje</option><option value="prefrio">Prefrío</option></select></label>
<label><span>Turno *</span><input name="turno" type="text" required maxlength="30"></label>
<label><span>Validador *</span><select name="validador_id" required></select></label>
<label><span>Fecha y hora de recepción *</span><input name="recepcion_at" type="datetime-local" required maxlength="30"></label>
<label><span>Fecha y hora de salida</span><input name="salida_at" type="datetime-local" maxlength="30"></label>
<label><span>Chofer *</span><input name="chofer" type="text" required maxlength="150"></label>
<label><span>RUT del chofer</span><input name="rut_chofer" type="text" maxlength="30"></label>
<label><span>Patente delantera *</span><input name="patente_delantera" type="text" required maxlength="30"></label>
<label><span>Patente del carro</span><input name="patente_carro" type="text" maxlength="30"></label>
<label><span>Llega con prefrío *</span><select name="llega_con_prefrio"><option value="false">No</option><option value="true">Sí</option></select></label>
<label><span>Condición SAG para todos</span><select name="condicion_sag_id"></select></label>
<label><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>
</div></fieldset><p class="rfe-warning is-hidden" id="guideWarning"></p>
<div class="rfe-actions"><h3>Pallets de origen</h3><button class="secondary-button" id="addPallet" type="button">Agregar pallet</button></div><div id="palletList"></div><div class="rfe-actions"><span id="totals"></span><button class="primary-button" id="saveReception">Guardar borrador</button></div></form><button class="secondary-button is-hidden" id="retrySave" type="button">Reintentar el mismo guardado</button>
</section>
@endsection