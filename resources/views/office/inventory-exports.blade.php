@php
    $inventoryType = $inventoryType ?? 'materiales';
    $inventoryDomain = $inventoryDomain ?? 'materiales';
    $inventoryOffice = $inventoryOffice ?? 'exportaciones';
    $inventoryContext = $inventoryContext ?? 'MATERIALES';
    $inventoryArea = $inventoryArea ?? 'Materiales';
    $inventoryTitle = $inventoryTitle ?? 'Existencia de materiales';
    $inventoryDescription = $inventoryDescription ?? 'Consulta y descarga la existencia autorizada para esta área.';
    $inventoryIcon = $inventoryIcon ?? '▦';
@endphp
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · {{ $inventoryTitle }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-inventory-exports.css', 'resources/js/office-inventory-exports.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand inventory-access-brand">
                <div class="office-logo" aria-hidden="true">{{ $inventoryIcon }}</div>
                <p class="eyebrow">FoliOS · {{ strtoupper($inventoryArea) }}</p>
                <h1 id="officeAccessTitle">{{ $inventoryTitle }}, dentro de su área responsable.</h1>
                <p>{{ $inventoryDescription }}</p>
                <div class="feature-row">
                    <span>Corte histórico XLSX</span>
                    <span>Excel conectado</span>
                    <span>Solo lectura</span>
                </div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div>
                    <p class="eyebrow">ACCESO DE OFICINA</p>
                    <h2>Abrir {{ strtolower($inventoryTitle) }}</h2>
                    <p>La descarga respeta el permiso y alcance definidos para {{ strtolower($inventoryArea) }}.</p>
                </div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Abrir existencia <span>→</span></button>
            </form>
        </section>

        <main
            class="office-app inventory-app is-hidden"
            id="officeApp"
            data-inventory-type="{{ $inventoryType }}"
        >
            <x-office.navigation
                :domain="$inventoryDomain"
                :office="$inventoryOffice"
                :context="$inventoryContext"
                :icon="$inventoryIcon"
            />

            <section class="inventory-workspace">
                <header class="inventory-heading panel">
                    <div>
                        <p class="eyebrow">EXISTENCIA DEL ÁREA · {{ strtoupper($inventoryArea) }}</p>
                        <h1>{{ $inventoryTitle }}</h1>
                        <p>{{ $inventoryDescription }} El archivo consulta únicamente la temporada activa.</p>
                    </div>
                    <button class="secondary-button" id="reloadInventoryButton" type="button">↻ Actualizar estado</button>
                </header>

                <form class="panel inventory-filters" id="inventoryFilters" hidden onsubmit="return false"></form>

                <section class="inventory-cards inventory-cards--dedicated" id="inventoryCards" aria-live="polite"></section>

                <section class="inventory-help-grid">
                    <article class="panel inventory-help-card">
                        <span class="inventory-help-card__icon" aria-hidden="true">▦</span>
                        <div>
                            <p class="eyebrow">CORTE ESTÁTICO</p>
                            <h2>Excel de respaldo del área</h2>
                            <p>Genera un archivo <strong>.xlsx</strong> con fecha de corte, usuario y valores numéricos reales. No cambia después de descargarlo.</p>
                            <ul><li>Auditorías y conciliaciones</li><li>Cierres diarios</li><li>Envío controlado a terceros</li></ul>
                        </div>
                    </article>
                    <article class="panel inventory-help-card inventory-help-card--connected">
                        <span class="inventory-help-card__icon" aria-hidden="true">↻</span>
                        <div>
                            <p class="eyebrow">EXCEL CONECTADO</p>
                            <h2>Consulta autoactualizable</h2>
                            <p>Descarga una consulta <strong>.iqy</strong>. Ábrela en Excel, guarda el libro como <strong>.xlsx</strong> y utiliza <strong>Datos → Actualizar todo</strong>.</p>
                            <ol><li>Abre el archivo descargado con Excel.</li><li>Acepta la conexión de solo lectura.</li><li>Guarda el libro como XLSX.</li><li>Activa “Actualizar al abrir” si corresponde.</li></ol>
                        </div>
                    </article>
                </section>

                <section class="panel inventory-connections-panel">
                    <header>
                        <div><p class="eyebrow">SEGURIDAD DEL ÁREA</p><h2>Conexiones emitidas</h2><p>Solo se muestran conexiones de {{ strtolower($inventoryArea) }}. Revócalas cuando el archivo deje de usarse o sea compartido por error.</p></div>
                        <span class="inventory-connection-count" id="inventoryConnectionCount">0</span>
                    </header>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Archivo</th><th>Creada</th><th>Último uso</th><th>Vence</th><th>Estado</th><th></th></tr></thead>
                            <tbody id="inventoryConnectionRows"></tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Preparando existencia…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
