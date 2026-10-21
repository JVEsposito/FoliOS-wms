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
                <button class="secondary-button" data-panel="configuracion">Planta y responsables</button>
            </nav>
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
