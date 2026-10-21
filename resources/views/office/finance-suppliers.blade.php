<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#07151e"><meta name="color-scheme" content="light dark">
    <title>FoliOS · Proveedores</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-finance.css', 'resources/css/office-finance-suppliers.css', 'resources/js/office-finance-suppliers.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body data-compras-zone="{{ config('app.operational_timezone', 'America/Santiago') }}">
<section class="office-access" id="officeAccess">
    <div class="office-access__brand"><div class="office-logo" aria-hidden="true">$</div><p class="eyebrow">FoliOS · FINANZAS</p><h1>Proveedores, documentos y gastos.</h1><p>La información necesaria para revisar cada compra y su registro contable.</p></div>
    <form class="office-access__form" id="officeLoginForm"><h2>Ingresar a Proveedores</h2><label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label><label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label><p id="officeLoginError" role="alert"></p><button class="primary-button" type="submit">Entrar</button></form>
</section>
<main class="office-app is-hidden" id="officeApp">
    <x-office.navigation domain="administracion" office="finanzas-proveedores" context="FINANZAS · PROVEEDORES" icon="$" />
    <section class="fin-workspace cp-workspace">
        <header class="fin-heading"><div><p class="eyebrow">FINANZAS · COMPRAS Y GASTOS</p><h1>Proveedores</h1><p>Ficha financiera, documentos recibidos y diario auxiliar en pesos chilenos.</p></div></header>
        <p id="cpMessage" class="fin-status" role="status" aria-live="polite">Cargando…</p>
        <nav class="cp-tabs" aria-label="Vistas de proveedores">
            <button type="button" data-cp-tab="proveedores" aria-pressed="true">Directorio</button><button type="button" data-cp-tab="documentos" aria-pressed="false">Documentos recibidos</button><button type="button" data-cp-tab="diario" aria-pressed="false">Diario contable</button><button type="button" data-cp-tab="cuentas" aria-pressed="false">Plan de cuentas</button>
        </nav>
        <section class="panel cp-panel" data-cp-panel="proveedores">
            <form id="cpSearch" class="cp-toolbar"><label>Buscar proveedor<input name="buscar" type="search" maxlength="100" placeholder="Razón social o RUT"></label><button type="submit" class="secondary-button">Buscar</button><button id="cpNewSupplier" type="button" class="primary-button" data-cp-manage hidden>Nuevo proveedor</button></form>
            <div class="cp-table" id="cpSuppliers"></div><nav id="cpSupplierPages" class="cp-pages" aria-label="Páginas de proveedores"></nav>
        </section>
        <section class="panel cp-panel" data-cp-panel="documentos" hidden>
            <div class="cp-toolbar"><label>Proveedor seleccionado<strong id="cpSelected">Seleccione un proveedor en el directorio</strong></label><button id="cpNewDocument" type="button" class="primary-button" data-cp-manage hidden>Ingresar documento</button><button id="cpReloadDocuments" type="button" class="secondary-button">Actualizar</button></div>
            <p>Ingreso de documentos recibidos; no se emiten DTE. Las guías no generan deuda ni gasto. Una factura puede referenciar varias guías, incluso si se factura por partes.</p>
            <div class="cp-table" id="cpDocuments"></div><nav id="cpDocumentPages" class="cp-pages" aria-label="Páginas de documentos"></nav>
        </section>
        <section class="panel cp-panel" data-cp-panel="diario" hidden>
            <form id="cpJournalFilters" class="cp-toolbar"><label>Desde<input name="desde" type="date" required></label><label>Hasta<input name="hasta" type="date" required></label><button class="secondary-button" type="submit">Consultar diario</button></form>
            <p>Diario auxiliar de todos los proveedores. El IVA soportado queda por revisar; su ingreso aquí no determina el crédito fiscal ni reemplaza al RCV del SII.</p><div id="cpJournal"></div><nav id="cpJournalPages" class="cp-pages" aria-label="Páginas del diario"></nav>
        </section>
        <section class="panel cp-panel" data-cp-panel="cuentas" hidden>
            <div class="cp-toolbar"><button id="cpInitPlan" type="button" class="secondary-button" data-cp-manage hidden>Instalar plan inicial</button><button id="cpNewAccount" type="button" class="primary-button" data-cp-manage hidden>Crear cuenta</button></div><p>Revise la clasificación con contabilidad. Las compras de existencias y de activo fijo se asignan a cuentas de activo; los servicios consumidos, a gastos. La cuenta de control de proveedores es 2101; el IVA por revisar, 1108.</p><div class="cp-table" id="cpAccounts"></div>
        </section>
    </section>
</main>
<dialog class="cp-dialog" id="cpSupplierDialog"><form id="cpSupplierForm">
    <h2>Ficha financiera del proveedor</h2><p>Vincule el proveedor de Materiales cuando exista. Para servicios y otros gastos puede crear una ficha independiente.</p>
    <div class="cp-toolbar"><label>Buscar en Materiales<input id="cpMaterialSearch" type="search" maxlength="100"></label><button id="cpFindMaterial" type="button" class="secondary-button">Buscar en Materiales</button></div>
    <div class="cp-grid"><label>Vínculo con Materiales<select name="proveedor_material_id" id="cpMaterial"><option value="">Sin vínculo con Materiales</option></select></label>
    <label>RUT<input name="rut" required maxlength="16" placeholder="76123456-0"></label><label>Razón social<input name="razon_social" required maxlength="180"></label>
    @foreach (['giro' => 'Giro', 'direccion' => 'Dirección', 'contacto' => 'Contacto', 'email' => 'Correo', 'telefono' => 'Teléfono', 'categoria' => 'Categoría'] as $campo => $etiqueta)
        <label>{{ $etiqueta }}<input name="{{ $campo }}" type="{{ $campo === 'email' ? 'email' : 'text' }}" maxlength="{{ $campo === 'direccion' ? 250 : ($campo === 'telefono' ? 40 : ($campo === 'categoria' ? 80 : 180)) }}"></label>
    @endforeach
    <label>Estado<select name="activo"><option value="1">Activo</option><option value="0">Inactivo para nuevas compras</option></select></label><label>Pagos<select name="pagos_bloqueados"><option value="0">Habilitados</option><option value="1">Bloqueados para revisión</option></select></label>
    <label id="cpSupplierReason" hidden>Motivo del cambio<input name="motivo" minlength="5" maxlength="500"></label></div>
    <p data-cp-error role="alert"></p><footer><button type="button" class="secondary-button" data-cp-close>Cancelar</button><button type="submit" class="primary-button">Guardar ficha</button></footer>
</form></dialog>
<dialog class="cp-dialog cp-dialog--wide" id="cpDocumentDialog"><form id="cpDocumentForm">
    <h2>Ingresar documento recibido</h2><p id="cpDocumentSupplier"></p><div class="cp-grid">
        <label>Tipo<select name="tipo" id="cpDocumentType"></select></label><label>Folio / número de respaldo<input name="folio" required maxlength="40"></label>
        <label>Emisión<input name="fecha_emision" type="date" required></label><label>Recepción de la factura/documento<input name="fecha_recepcion" type="date" required></label><label>Vencimiento<input name="vencimiento" type="date" required></label>
        <label>Respaldo del plazo excepcional (si supera 30 días)<input name="respaldo_plazo" maxlength="500" minlength="10"></label>
        @foreach (['neto' => 'Neto afecto', 'exento' => 'Exento / no gravado', 'iva' => 'IVA recibido', 'otros' => 'Otros importes / impuestos'] as $campo => $etiqueta)
            <label>{{ $etiqueta }} · CLP<input name="{{ $campo }}" inputmode="numeric" pattern="[0-9]{1,15}" value="0" required></label>
        @endforeach
        <label>Explicación si el IVA difiere del 19%<input name="observacion_iva" maxlength="500" minlength="5"></label><label>Respaldo / orden de compra / recepción<textarea name="respaldo" required minlength="5" maxlength="1000"></textarea></label>
    </div>
    <div class="cp-toolbar"><h3>Distribución contable del costo</h3><button type="button" id="cpAddLine" class="secondary-button">Agregar línea</button></div><p>Distribuya neto + exento + otros. Registre el IVA en su campo, fuera de esta distribución.</p><div id="cpCostLines"></div><p id="cpTotal" role="status"></p>
    <div class="cp-toolbar"><label>Buscar factura o guía por folio<input id="cpReferenceSearch" type="search" maxlength="40"></label><button id="cpFindReferences" type="button" class="secondary-button">Buscar referencias</button></div>
    <div class="cp-grid"><label>Factura de referencia (obligatoria para notas)<select name="referencia_id" id="cpInvoiceReference"></select></label><label>Guías asociadas a esta factura<select name="guias" id="cpGuideReferences" multiple size="4"></select></label></div>
    <p data-cp-error role="alert"></p><footer><button type="button" class="secondary-button" data-cp-close>Cancelar</button><button type="submit" class="primary-button">Guardar borrador</button></footer>
</form></dialog>
<dialog class="cp-dialog" id="cpActionDialog"><form id="cpActionForm"><h2 id="cpActionTitle"></h2><p id="cpActionDescription"></p><label>Fecha contable<input name="fecha_contable" type="date" required></label><label id="cpActionReason">Motivo del reverso interno<textarea name="motivo" minlength="5" maxlength="500"></textarea></label><p>Este registro no modifica documentos tributarios ante el SII. La anulación interna conserva el original y su reverso.</p><p data-cp-error role="alert"></p><footer><button type="button" class="secondary-button" data-cp-close>Cancelar</button><button type="submit" class="primary-button">Confirmar</button></footer></form></dialog>
<dialog class="cp-dialog" id="cpAccountDialog"><form id="cpAccountForm"><h2>Cuenta contable</h2><label>Código<input name="codigo" required maxlength="30" pattern="[A-Za-z0-9._\-]+"></label><label>Nombre<input name="nombre" required maxlength="180"></label><label>Tipo<select name="tipo"><option value="gasto">Gasto</option><option value="activo">Activo</option><option value="tesoreria">Tesorería</option><option value="iva">IVA por revisar</option><option value="pasivo">Pasivo</option></select></label><label>Estado<select name="activa"><option value="1">Activa</option><option value="0">Inactiva</option></select></label><p data-cp-error role="alert"></p><footer><button type="button" class="secondary-button" data-cp-close>Cancelar</button><button type="submit" class="primary-button">Guardar cuenta</button></footer></form></dialog>
<dialog class="cp-dialog cp-dialog--wide" id="cpDetailDialog"><h2>Detalle y trazabilidad</h2><div id="cpDetail"></div><footer><button type="button" class="secondary-button" data-cp-close>Cerrar</button></footer></dialog>
</body></html>
