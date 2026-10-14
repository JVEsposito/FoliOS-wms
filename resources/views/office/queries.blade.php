<!DOCTYPE html>
<html lang="es">
    @php
        $activeQueriesSection = $queriesSection ?? 'busqueda';
        $queriesSectionMeta = [
            'busqueda' => [
                'title' => 'Búsqueda operacional',
                'description' => 'Encuentra folios, lotes, recepciones y productores en la base interna.',
            ],
            'trazabilidad' => [
                'title' => 'Trazabilidad de lotes',
                'description' => 'Qué folios contienen un lote de materia prima o proceso de packing, y de dónde viene cada folio.',
            ],
            'sag' => [
                'title' => 'Consulta SAG / CSG',
                'description' => 'Verifica productores en el registro público y conserva el resultado trazable.',
            ],
            'productores' => [
                'title' => 'Productores verificados',
                'description' => 'Revisa productores consultados, asociaciones a clientes y antecedentes de temporada.',
            ],
        ];
        $activeQueriesMeta = $queriesSectionMeta[$activeQueriesSection] ?? $queriesSectionMeta['busqueda'];
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Oficina de consultas</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-queries.css', 'resources/js/office-queries.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand queries-access-brand">
                <div class="office-logo" aria-hidden="true">⌕</div>
                <p class="eyebrow">FoliOS · TRAZABILIDAD</p>
                <h1 id="officeAccessTitle">Encuentra el origen y el estado de cada movimiento.</h1>
                <p>Consulta folios, lotes, recepciones y productores CSG desde un solo lugar.</p>
                <div class="feature-row"><span>Búsqueda transversal</span><span>Consulta SAG</span><span>Historial por productor</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">OFICINA DE CONSULTAS</p><h2>Ingresar</h2><p>Disponible para administración, supervisión de frío y digitación de materia prima.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Abrir consultas <span>→</span></button>
            </form>
        </section>

        <main class="office-app queries-app is-hidden" id="officeApp" data-queries-section="{{ $activeQueriesSection }}">
            <x-office.navigation domain="consultas" :office="$navigationOffice ?? 'busqueda'" context="CONSULTAS Y TRAZABILIDAD" icon="⌕" />

            <section class="queries-heading">
                <div><p class="eyebrow">OFICINA DE CONSULTAS</p><h1>{{ $activeQueriesMeta['title'] }}</h1><p>{{ $activeQueriesMeta['description'] }}</p></div>
                <button class="secondary-button queries-refresh" id="reloadButton" type="button">↻ Actualizar</button>
            </section>

            <section class="query-kpis" aria-label="Resumen">
                <article><span>PRODUCTORES CSG</span><strong id="producerCount">0</strong><small>verificados en nuestra base</small></article>
                <article><span>PENDIENTES DE CLIENTE</span><strong id="pendingCount">0</strong><small>requieren asociación</small></article>
                <article><span>PRODUCTORES ASOCIADOS</span><strong id="associatedCount">0</strong><small>con al menos un cliente</small></article>
                <article><span>CONSULTAS SAG HOY</span><strong id="sagTodayCount">0</strong><small>intentos auditados</small></article>
            </section>

            <section class="queries-grid">
                <article class="query-panel query-panel--search" data-queries-view="busqueda">
                    <header><p class="eyebrow">BASE OPERACIONAL</p><h2>Buscar en FoliOS</h2><p>Folios, lotes, productores y recepciones de todas las temporadas.</p></header>
                    <form class="query-search-form" id="globalSearchForm">
                        <input name="q" minlength="2" maxlength="100" placeholder="Folio, lote, CSG, GGN, guía, patente, RUT…" required>
                        <select name="tipo" aria-label="Tipo de registro">
                            <option value="todos">Todos los registros</option>
                            <option value="folios">Folios</option>
                            <option value="lotes">Lotes</option>
                            <option value="productores">Productores</option>
                            <option value="recepciones">Recepciones</option>
                        </select>
                        <button class="primary-button" type="submit">Buscar</button>
                    </form>
                    <div class="search-results" id="searchResults"><div class="query-empty">Escribe al menos dos caracteres para iniciar una búsqueda.</div></div>
                </article>

                <article class="query-panel query-panel--sag" data-queries-view="sag">
                    <header><p class="eyebrow">REGISTRO EXTERNO</p><h2>Consultar productor SAG</h2><p>Un resultado CSG válido se guarda automáticamente como pendiente de asociación a cliente.</p></header>
                    <form class="sag-search-form" id="sagSearchForm">
                        <label><span>Buscar por</span><select name="tipo"><option value="codigo_sag">Código SAG / CSG</option><option value="rut">RUT del productor</option></select></label>
                        <label><span>Valor</span><input name="valor" maxlength="100" autocomplete="off" placeholder="Ej. 105410" required></label>
                        <button class="primary-button" type="submit">Consultar SAG</button>
                    </form>
                    <p class="source-note">Fuente: Sistema de Registro Agrícola del SAG. La disponibilidad depende del servicio público externo.</p>
                    <div class="sag-result" id="sagResult"><div class="query-empty">Aquí aparecerá el resultado de la consulta SAG.</div></div>
                </article>
            </section>

            <section class="query-panel trace-lots-panel" data-queries-view="trazabilidad">
                <header>
                    <p class="eyebrow">MATERIA PRIMA → PRODUCTO TERMINADO</p>
                    <h2>Buscar lote, proceso o folio</h2>
                    <p>Ingresa el número de lote MP o de proceso de packing impreso en la etiqueta del pallet, o un folio, para ver la cadena completa. Los números se repiten entre temporadas: cada consulta corresponde a una sola temporada, y las cerradas siguen disponibles.</p>
                </header>
                <form class="query-search-form trace-lots-form" id="traceLotsForm">
                    <input name="q" minlength="2" maxlength="80" placeholder="Lote MP, proceso de packing o folio" required>
                    <label class="trace-lots-season"><span>Temporada</span>
                        <select name="temporada_id" id="traceLotsSeason"><option value="">Temporada activa</option></select>
                    </label>
                    <button class="primary-button" type="submit">Trazar</button>
                </form>
                <div class="trace-lots-results" id="traceLotsResults"><div class="query-empty">Los folios y lotes relacionados aparecerán aquí.</div></div>
            </section>

            <section class="query-panel producers-panel" data-queries-view="productores">
                <header class="producers-heading">
                    <div><p class="eyebrow">BASE DE PRODUCTORES</p><h2>Productores CSG verificados</h2><p>Los CSG nuevos permanecen pendientes hasta que un responsable los asocie a un cliente.</p></div>
                    <form id="producerFilters"><input name="buscar" placeholder="Buscar CSG, RUT, razón social o predio"><select name="estado"><option value="">Todos</option><option value="pendiente_cliente">Pendientes de cliente</option><option value="asociado">Asociados</option></select><button class="secondary-button" type="submit">Filtrar</button></form>
                </header>
                <div class="producer-list" id="producerList"></div>
            </section>
        </main>

        <dialog class="query-dialog" id="producerDialog">
            <div class="query-dialog__shell">
                <header><div><p class="eyebrow">EXPEDIENTE CSG</p><h2 id="producerDialogTitle">Productor</h2></div><button id="closeProducerDialog" type="button" aria-label="Cerrar">×</button></header>
                <div id="producerDialogBody"></div>
            </div>
        </dialog>

        <dialog class="query-dialog query-dialog--folio" id="folioDialog">
            <div class="query-dialog__shell">
                <header><div><p class="eyebrow">EXPEDIENTE DE TRAZABILIDAD</p><h2 id="folioDialogTitle">Folio</h2></div><button id="closeFolioDialog" type="button" aria-label="Cerrar">×</button></header>
                <div id="folioDialogBody"></div>
            </div>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
