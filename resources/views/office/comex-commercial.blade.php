<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#07151e"><meta name="color-scheme" content="light dark"><title>FoliOS · Actores y lugares COMEX</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-comex.css', 'resources/js/office-comex-commercial.js'])
@endif
@include('office.partials.arranque')
</head><body>@include('office.partials.comex-commercial-access')
<main class="office-app is-hidden" id="officeApp"><x-office.navigation domain="administracion" office="comex-comercial" context="ADMINISTRACIÓN · COMEX" icon="⚓" /><section class="cx-workspace"><header class="cx-heading"><div><p class="eyebrow">COMEX · COMERCIAL</p><h1>Actores y lugares</h1><p>Identidades y funciones comerciales, con puertos, terminales y puntos de entrega. Los roles de cada negocio se eligen al preparar el acuerdo.</p></div></header><p id="ccMessage" class="cx-status" role="status">Cargando…</p><section class="panel cx-panel"><form id="ccSearch" class="cx-filters"><label><span>Directorio</span><select name="catalogo"><option value="partes">Actores comerciales y logísticos</option><option value="lugares">Puertos, terminales y lugares</option></select></label><label><span>Nombre, código o identidad</span><input name="buscar" type="search" maxlength="100"></label><button type="submit" class="secondary-button">Buscar</button></form><button id="ccNew" type="button" class="primary-button" hidden>Agregar ficha</button><div id="ccRows" class="cx-table-scroll"></div><div id="ccPages" class="cx-acciones"></div></section></section></main>
<dialog id="ccPartyDialog" class="cx-dialog"><form id="ccPartyForm"><header class="cx-dialog__heading"><h2>Actor comercial o logístico</h2><button type="button" data-cc-close class="cx-dialog__close" aria-label="Cerrar">×</button></header><div class="cx-form">
<label><span>Código interno permanente</span><input name="codigo" pattern="[A-Z0-9_\-]+" maxlength="40" required></label>
<label><span>País de identidad fiscal</span><select name="pais_iso2" required></select></label>
<label><span>RUT chileno o tax ID extranjero</span><input name="tax_id" maxlength="80" required></label>
<label><span>Razón social</span><input name="razon_social" maxlength="200" required></label>
<label><span>Dirección</span><input name="direccion" maxlength="300" required></label>
<label><span>Ciudad</span><input name="ciudad" maxlength="120" required></label>
<label><span>Contacto comercial (opcional)</span><input name="contacto" maxlength="200"></label>
<fieldset class="cx-form__wide"><legend>Funciones habilitadas</legend><div id="ccPartyRoles" class="cx-casillas"></div></fieldset><label><span>Buscar cliente de la planta (opcional)</span><input id="ccClientSearch" type="search" maxlength="100"></label><button type="button" data-cc-action="clients" class="secondary-button">Buscar cliente</button><label><span>Vínculo explícito con cliente operativo</span><select name="cliente_operacion_id" ></select></label>
<p class="cx-form__nota cx-form__wide">Vincule solo si es la misma identidad. El nombre del cliente no determina quién vende, exporta o recibe la fruta. El vínculo confirmado se conserva.</p><label><span>Estado para nuevos acuerdos</span><select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></label>
<label><span>Fuente y respaldo de los datos</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
<label><span>Motivo de la modificación</span><textarea name="motivo" minlength="10" maxlength="1000"></textarea></label>
<p class="form-error cx-form__wide" role="alert" data-cc-error></p></div><footer class="cx-acciones"><button type="button" data-cc-close class="secondary-button">Cancelar</button><button type="submit" class="primary-button">Guardar ficha</button></footer></form></dialog>
<dialog id="ccPlaceDialog" class="cx-dialog"><form id="ccPlaceForm"><header class="cx-dialog__heading"><h2>Puerto, terminal o lugar</h2><button type="button" data-cc-close class="cx-dialog__close" aria-label="Cerrar">×</button></header><div class="cx-form">
<label><span>Código interno permanente</span><input name="codigo" pattern="[A-Z0-9_\-]+" maxlength="40" required></label>
<label><span>Tipo</span><select name="tipo" required></select></label>
<label><span>Nombre</span><input name="nombre" maxlength="200" required></label>
<label><span>País</span><select name="pais_iso2" required></select></label>
<label><span>Localidad</span><input name="localidad" maxlength="120" required></label>
<label><span>Código externo verificado (opcional)</span><input name="codigo_externo" maxlength="50"></label>
<label><span>Dirección</span><input name="direccion" maxlength="300"></label>
<label><span>Buscar puerto para un terminal</span><input id="ccPortSearch" type="search" maxlength="100"></label><button type="button" data-cc-action="ports" class="secondary-button">Buscar puerto</button><label><span>Puerto asociado (solo terminales)</span><select name="puerto_id" ></select></label>
<label><span>Estado para nuevos acuerdos</span><select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></label>
<label><span>Fuente y respaldo de los datos</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
<label><span>Motivo de la modificación</span><textarea name="motivo" minlength="10" maxlength="1000"></textarea></label>
<p class="form-error cx-form__wide" role="alert" data-cc-error></p></div><footer class="cx-acciones"><button type="button" data-cc-close class="secondary-button">Cancelar</button><button type="submit" class="primary-button">Guardar ficha</button></footer></form></dialog>
<dialog id="ccHistoryDialog" class="cx-dialog"><header class="cx-dialog__heading"><h2>Historial de la ficha</h2><button type="button" data-cc-close class="cx-dialog__close" aria-label="Cerrar">×</button></header><div id="ccHistory" class="cx-table-scroll"></div></dialog></body></html>
