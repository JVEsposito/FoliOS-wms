<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FoliOS · Estándares de consumo</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/css/office-material-standards.css', 'resources/js/office-material-standards.js'])
@endif
@include('office.partials.arranque')
</head><body><main class="office-app" id="standardsApp" data-folios-workspace="catalogo">
<x-office.navigation domain="materiales" office="estandares" context="ESTÁNDARES DE CONSUMO" icon="≡" />
<div class="custody-shell">
<header class="custody-heading" data-folios-masthead><div><p class="eyebrow">MATERIALES · PRODUCCIÓN</p><h1>Estándares de consumo</h1><p>Materiales que lleva cada caja de un embalaje PT. Si un embalaje tiene estándar propio y de planta, la rebaja usa el propio.</p></div>
<div class="standards-heading-actions"><button id="standardsReload" type="button" class="secondary-button">Actualizar</button><button id="standardNew" type="button" class="primary-button" hidden>Nuevo estándar</button></div></header>
<p id="standardsNotice" role="status" aria-live="polite"></p>
<form id="standardsFilters" class="custody-filters standards-filters" data-folios-toolbar>
<label>Buscar<input name="q" type="search" maxlength="100" placeholder="Código, descripción o embalaje"></label>
<label>Temporada<select name="temporada_id"></select></label>
<label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label>
<label>Origen<select name="origen"><option value="">Todos</option><option value="propio">Propio</option><option value="planta">Planta</option></select></label>
<label>Estado<select name="estado"><option value="activos">Activos</option><option value="inactivos">Inactivos</option><option value="todos">Todos</option></select></label>
<button class="primary-button" type="submit">Consultar</button><button id="standardsExport" class="secondary-button" type="button">Exportar Excel</button>
</form>
<p id="standardsTotal" class="custody-filter-summary"></p>
<div class="custody-card"><div class="custody-table-wrap"><table class="custody-table standards-table"><thead><tr><th>Código</th><th>Cliente</th><th>Embalaje PT</th><th>Origen</th><th>Versión vigente</th><th>Insumos</th><th>Estado</th><th></th></tr></thead><tbody id="standardsRows"></tbody></table></div>
<nav class="standards-pages" aria-label="Páginas de estándares"><button id="standardsPrevious" type="button" class="secondary-button">Anterior</button><span id="standardsPage"></span><button id="standardsNext" type="button" class="secondary-button">Siguiente</button></nav></div>
<section id="standardDetail" class="custody-card standards-detail" hidden aria-live="polite"></section>
<details id="plantStandards" class="custody-card standards-plant">
<summary><span>Estándares de planta</span> <small id="plantSummary">Se leen desde la vista del sistema de planta</small></summary>
<div class="standards-plant__body">
<p class="materials-help">La vista no trae el identificador del estándar ni sus variaciones. Si un artículo trae varias cabeceras se toma la creada más tarde, y un insumo repetido dentro de ella se suma. Cliente y embalaje se reconocen igual que en Pendientes por validar; cada insumo, por el código o código externo de un ítem de Materiales.</p>
<p id="plantStatus" class="standards-plant__status"></p>
<div class="standards-detail__actions"><button id="plantConfigure" type="button" class="secondary-button" hidden>Configurar lectura</button><button id="plantRead" type="button" class="secondary-button" hidden>Leer ahora</button><button id="plantIncorporate" type="button" class="primary-button" hidden>Incorporar listos</button></div>
<form id="plantConfig" class="standards-form__grid standards-plant__config" hidden>
<label>Conexión externa<select name="conexion_id" required></select></label>
<label>Tabla o vista<input name="tabla" maxlength="64" pattern="[A-Za-z0-9_]+" required></label>
<label>Temporada en planta<input name="temporada_origen" maxlength="40" placeholder="T2627" required></label>
<label>Empresas (opcional)<input name="empresas" maxlength="400" placeholder="FCO, FLA"></label>
<label class="standards-plant__check"><input type="checkbox" name="habilitada"> Lectura habilitada</label>
<div class="standards-detail__actions"><button type="submit" class="primary-button">Guardar configuración</button></div>
</form>
<p id="plantNotice" role="status" aria-live="polite"></p>
<div id="plantPending"></div>
<div id="plantRows"></div>
</div>
</details>
</div>

<dialog id="standardDialog" class="standards-dialog" aria-labelledby="standardDialogTitle">
<form id="standardForm" class="standards-form" novalidate>
<header class="standards-dialog__header"><div><p class="eyebrow" id="standardDialogEyebrow">NUEVO ESTÁNDAR PROPIO</p><h2 id="standardDialogTitle">Nuevo estándar</h2><p id="standardDialogHelp">Elige el cliente y el embalaje PT. Cada insumo indica cuánto lleva una caja.</p></div><button type="button" class="standards-dialog__close" data-close aria-label="Cerrar">×</button></header>
<div class="standards-form__body">
<fieldset class="standards-form__grid" id="standardIdentity">
<label>Cliente<select name="cliente_id" required></select></label>
<label>Embalaje PT<select name="envase_validacion_id" required><option value="">Elige primero el cliente</option></select></label>
</fieldset>
<div class="standards-form__grid">
<label>Código<input name="codigo" maxlength="60" placeholder="Por defecto, el código del embalaje"></label>
<label>Descripción<input name="descripcion" maxlength="200"></label>
<label>Vigente desde<input name="vigente_desde" type="date" required></label>
<label>Motivo<input name="motivo" maxlength="500" placeholder="Ej.: cambio de pallet a 98 cajas"></label>
</div>
<div class="standards-lines-heading"><div><h3>Insumos por caja</h3><p>Una línea con variación reemplaza a la línea general del mismo ítem cuando la caja calza (marca, luego variedad, calibre y categoría). «Entero» descuenta unidades completas y arrastra la fracción a la próxima rebaja.</p></div><button id="standardAddLine" type="button" class="secondary-button">Agregar insumo</button></div>
<div id="standardLines" class="standards-lines"></div>
<datalist id="standardVariationValues"></datalist>
<p id="standardFormError" class="standards-form__error" role="alert"></p>
</div>
<footer class="standards-form__footer"><button type="button" class="secondary-button" data-close>Cancelar</button><button id="standardSave" type="submit" class="primary-button">Guardar</button></footer>
</form>
</dialog>
</main></body></html>
