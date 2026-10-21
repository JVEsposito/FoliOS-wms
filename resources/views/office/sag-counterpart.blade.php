<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Contraparte SAG</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-sag-counterpart.css', 'resources/js/office-sag-counterpart.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <section class="office-access" id="officeAccess">
        <div class="office-access__brand"><p class="eyebrow">FoliOS · FRIGORÍFICO</p><h1>Contraparte SAG</h1><p>Existencias y documentos de la fruta con condición fitosanitaria.</p></div>
        <form class="office-access__form" id="officeLoginForm">
            <h2>Ingresar</h2>
            <label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label>
            <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="primary-button">Ingresar</button>
            <p id="officeLoginError" role="alert"></p>
        </form>
    </section>
    <main class="office-app is-hidden" id="officeApp">
        <x-office.navigation domain="frigorifico" office="contraparte-sag" context="FRIGORÍFICO · SAG" icon="SAG" />
        <section class="sc-workspace">
            <header class="sc-heading"><div><p class="eyebrow">CONTROL FITOSANITARIO</p><h1>Contraparte SAG</h1><p>Existencias, responsables y documentos de la planta.</p></div><a class="secondary-button" href="/oficina/frigorifico/inspeccion-sag">Inspecciones</a></header>
            <p id="scStatus" role="status"></p>
            <nav class="sc-tabs" aria-label="Secciones SAG">
                <button class="secondary-button" data-panel="existencias">Existencias</button>
                <button class="secondary-button" data-panel="documentos">Documentos SAG</button>
                <button class="secondary-button" data-panel="configuracion">Planta y responsables</button>
            </nav>
            <section class="panel sc-panel is-hidden" data-section="documentos">
                <h2>Documentos SAG</h2>
                <div class="sc-actions"><button class="primary-button" id="scNewDocument" data-manage>Nuevo documento</button><button class="secondary-button" id="scReloadDocuments">Actualizar</button></div>
                <div class="sc-actions"><label>Formulario en blanco<select id="scBlankType"><option value="repaletizaje">Repaletizaje</option><option value="anulacion">Anulación / devolución</option></select></label><button class="secondary-button" data-blank="pdf">PDF en blanco</button><button class="secondary-button" data-blank="xlsx">Excel en blanco</button></div>
                <form id="scDocumentFilters" class="sc-grid"><label>Tipo<select name="tipo"><option value="">Todos</option><option value="repaletizaje">Repaletizaje</option><option value="anulacion">Anulación / devolución</option></select></label><label>Estado<select name="estado"><option value="">Todos</option>@foreach (['borrador', 'emitido', 'enviado', 'aprobado', 'rechazado', 'cancelado'] as $estado)<option value="{{ $estado }}">{{ ucfirst($estado) }}</option>@endforeach</select></label><label>Desde<input type="date" name="desde"></label><label>Hasta<input type="date" name="hasta"></label><button class="secondary-button">Filtrar</button></form>
                <div id="scDocuments"></div><div class="sc-actions" id="scDocumentPages"></div>
                <form id="scDocumentForm" class="sc-document-form is-hidden">
                    <h3 id="scDocumentTitle">Nuevo documento</h3>
                    <div class="sc-grid">
                        <label>Tipo<select name="tipo"><option value="repaletizaje">Repaletizaje SAG</option><option value="anulacion">Anulación / devolución</option></select></label>
                        <label>Fecha actividad<input type="date" name="fecha_actividad" required></label>
                        <label>Responsable<select name="responsable_id" required></select></label>
                        <label>Certificados que anula o devuelve<input type="number" name="certificados" min="0" max="10000" value="0"></label>
                        <label>Certificados devueltos<input type="number" name="certificados_devueltos" min="0" max="10000" value="0"></label>
                        <label>Certificados asignados<input type="number" name="certificados_asignados" min="0" max="10000" value="0"></label>
                    </div>
                    <div class="sc-grid" id="scRepaSource"><label>Número REPA<input id="scRepaReference" maxlength="100" placeholder="REPA-…"></label><button type="button" class="secondary-button" id="scLoadRepa">Cargar repa</button></div>
                    <div class="sc-grid is-hidden" id="scFolioSource"><label>Folio<input id="scAddFolioNumber" maxlength="100" inputmode="text" autocomplete="off" placeholder="Escribir o escanear"></label><button type="button" class="secondary-button" id="scAddFolio">Agregar folio</button></div>
                    <div class="sc-scroll" id="scDocumentLines"></div>
                    <label>Observaciones<textarea name="observaciones" maxlength="2000" rows="3"></textarea></label>
                    <p>Los datos se completan desde la operación. La emisión conserva la versión del documento y su detalle.</p>
                    <button class="primary-button" data-manage>Guardar borrador</button>
                </form>
                <article class="sc-detail is-hidden" id="scDocumentDetail"></article>
            </section>
            <section class="panel sc-panel" data-section="existencias">
                <h2>Existencia de productos aprobados</h2>
                <form id="scFilters" class="sc-grid">
                    <label>Folio<input name="folio" maxlength="100" placeholder="Escribir o escanear"></label>
                    <label>Especie<input name="especie" maxlength="100"></label>
                    <label>Movimientos desde<input name="desde" type="date"></label>
                    <label>Hasta<input name="hasta" type="date"></label>
                    <button class="secondary-button">Consultar</button>
                </form>
                <p id="scTotals"></p>
                <button class="secondary-button" id="scInitial" data-manage>Registrar corte inicial</button>
                <p>El corte inicial incorpora las existencias actuales con su fecha de registro. Las actividades posteriores mantienen el historial.</p>
                <div class="sc-scroll"><table><thead><tr><th>Folio</th><th>Cliente</th><th>Especie</th><th>Variedad</th><th>CSG</th><th>Cajas</th><th>Condición</th></tr></thead><tbody id="scStock"></tbody></table></div>
                <div id="scStockPages" class="sc-actions"></div>
                <h2>Entradas y salidas</h2>
                <div class="sc-scroll"><table><thead><tr><th>Fecha</th><th>Actividad</th><th>Número</th><th>Folio</th><th>Entrada cajas</th><th>Salida cajas</th><th>Saldo cajas</th><th>Saldo pallets/saldos</th></tr></thead><tbody id="scMovements"></tbody></table></div>
                <div id="scMovementPages" class="sc-actions"></div>
            </section>
            <section class="panel sc-panel is-hidden" data-section="configuracion">
                <h2>Establecimiento</h2>
                <form id="scPlant" class="sc-grid" data-manage>
                    @foreach (['nombre_establecimiento' => 'Nombre de establecimiento', 'csp' => 'Código CSP', 'region' => 'Región', 'provincia' => 'Provincia', 'comuna' => 'Comuna', 'oficina_sag' => 'Oficina SAG'] as $campo => $nombre)
                        <label>{{ $nombre }}<input name="{{ $campo }}" maxlength="150" required></label>
                    @endforeach
                    <button class="primary-button">Guardar establecimiento</button>
                </form>
                <h2>Responsables</h2><p>Registra los antecedentes de autorización de cada persona ante el SAG.</p>
                <div id="scPeople"></div>
                <form id="scPerson" class="sc-grid" data-manage>
                    <input name="id" type="hidden"><input name="version" type="hidden" value="0">
                    <label>Nombre<input name="nombre" maxlength="150" required></label>
                    <label>RUT<input name="rut" maxlength="20" required></label>
                    <label>Categoría<select name="cargo"><option value="profesional">Contraparte profesional</option><option value="tecnica">Contraparte técnica</option><option value="despachador">Despachador autorizado</option></select></label>
                    <label>Referencia de autorización<input name="referencia_autorizacion" maxlength="250" required></label>
                    <label><input type="checkbox" name="activo" checked> Activo</label>
                    <button class="primary-button">Guardar responsable</button><button class="secondary-button" type="reset">Nuevo</button>
                </form>
            </section>
        </section>
    </main>
    <div class="toast-region" id="officeToasts" aria-live="polite"></div>
</body>
</html>
