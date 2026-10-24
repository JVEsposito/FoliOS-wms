<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FoliOS · Bodegas físicas</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/css/office-material-physical-warehouses.css', 'resources/js/office-material-physical-warehouses.js'])
@endif
@include('office.partials.arranque')
</head><body><main class="office-app" id="physicalWarehousesApp">
<x-office.navigation domain="materiales" office="bodegas" context="BODEGAS FÍSICAS" icon="▦" />
<div class="custody-shell">
<header class="custody-heading"><div><p class="eyebrow">MATERIALES · ALMACENAMIENTO</p><h1>Bodegas físicas</h1><p>Recintos de almacenamiento, separados de los centros de costo. Cada orden retira de una sola bodega.</p></div><div class="warehouse-tools"><button type="button" id="warehouseReload" class="secondary-button">Actualizar</button><button type="button" id="warehouseNew" class="primary-button" hidden>Crear bodega</button></div></header>
<p id="warehouseNotice" role="status" aria-live="polite"></p>
<section class="custody-card warehouse-search"><label>Buscar bodega<input id="warehouseSearch" type="search" placeholder="Código o nombre"></label><label><input id="warehouseInactive" type="checkbox"> Incluir inactivas</label></section>
<div id="warehouseList" class="warehouse-list"></div>
<section class="custody-card" id="warehouseHistoryPanel" hidden><h2 id="warehouseHistoryTitle">Historial de la bodega</h2><div id="warehouseHistory"></div><div class="warehouse-tools"><button type="button" id="warehouseHistoryPrevious" class="secondary-button">Anterior</button><span id="warehouseHistoryPage"></span><button type="button" id="warehouseHistoryNext" class="secondary-button">Siguiente</button></div></section>
</div>
<dialog class="materials-import warehouse-editor" id="warehouseDialog"><form id="warehouseForm">
<header class="materials-import__header"><h2 id="warehouseEditTitle">Bodega física</h2><button type="button" id="warehouseClose" aria-label="Cerrar">×</button></header>
<div class="materials-import__form"><fieldset id="warehouseFields" class="warehouse-fields">
<label>Código<input name="codigo" required maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="BOD-SECUNDARIA"></label>
<label>Nombre<input name="nombre" required minlength="2" maxlength="150"></label>
<label>Descripción<textarea name="descripcion" maxlength="1000" rows="2"></textarea></label>
<label>Operación<select name="requiere_ubicacion_fisica"><option value="0">Por bodega y folio, sin plano de cámara</option><option value="1">Con cámara y sesión de estiba</option></select></label>
<label>Estado<select name="activo"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
<p>Una bodega con stock, reservas u órdenes pendientes conserva su estado y modo de operación. Configura sus medidas y tipos de pallet en Capacidad de bodegas.</p>
<label>Motivo del cambio<textarea name="motivo" required minlength="5" maxlength="1000" rows="2"></textarea></label>
</fieldset><p id="warehouseError" role="alert"></p><button class="primary-button" type="submit">Guardar bodega</button></div>
</form></dialog>
</main></body></html>
