<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Control de despacho R-24</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-dispatch-control.css', 'resources/js/office-dispatch-control.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">▤</div>
                <p class="eyebrow">FoliOS · FRIGORÍFICO</p>
                <h1 id="officeAccessTitle">La planilla R-24 de cada despacho, sin papel.</h1>
                <p>El equipo de consolidación la llena en el andén con la PDA; aquí se consulta y se descarga.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app dcx-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="control-despacho" context="FRIGORÍFICO · DESPACHO" icon="▤" />

            <section class="dcx-workspace">
                <header class="dcx-heading panel">
                    <div>
                        <p class="eyebrow">POEMP R-24 · CONSOLIDACIÓN</p>
                        <h1>Control de despacho</h1>
                        <p>Planilla de control de despacho del área de frío: inspección del camión o contenedor, posición y temperatura de cada pallet. La llena el equipo de consolidación desde la PDA.</p>
                    </div>
                    <form class="dcx-filters" id="dcxFilters" role="search">
                        <label><span>Buscar</span><input name="buscar" maxlength="60" autocomplete="off" placeholder="Carga, embarque, contenedor o folio"></label>
                        <label><span>Estado</span>
                            <select name="estado">
                                <option value="">Todas</option>
                                <option value="borrador">En curso</option>
                                <option value="cerrado">Cerradas</option>
                            </select>
                        </label>
                        <label><span>Desde</span><input name="desde" type="date"></label>
                        <label><span>Hasta</span><input name="hasta" type="date"></label>
                        <button class="primary-button" type="submit">Filtrar</button>
                    </form>
                </header>

                <section class="panel dcx-list" aria-labelledby="dcxListTitle">
                    <div class="dcx-list__head">
                        <h2 id="dcxListTitle">Planillas de la temporada</h2>
                        <p id="dcxCount" role="status">—</p>
                    </div>
                    <div class="dcx-table-wrap">
                        <table class="dcx-table">
                            <thead>
                                <tr><th>Carga</th><th>Exportadora</th><th>Contenedor</th><th>Pallets</th><th>Estado</th><th>Inicio</th><th>Cierre</th></tr>
                            </thead>
                            <tbody id="dcxRows"><tr><td colspan="7">Cargando…</td></tr></tbody>
                        </table>
                    </div>
                    <div class="dcx-pager">
                        <button class="secondary-button" id="dcxPrev" type="button">Anterior</button>
                        <span id="dcxPage">1 / 1</span>
                        <button class="secondary-button" id="dcxNext" type="button">Siguiente</button>
                    </div>
                </section>

                <section class="panel dcx-detail is-hidden" id="dcxDetail" aria-labelledby="dcxDetailTitle">
                    <div class="dcx-detail__head">
                        <div>
                            <p class="eyebrow" id="dcxDetailState">—</p>
                            <h2 id="dcxDetailTitle">—</h2>
                            <p id="dcxDetailMeta">—</p>
                        </div>
                        <div class="dcx-detail__actions">
                            <button class="primary-button" id="dcxDownload" type="button">Descargar R-24 (Excel)</button>
                        </div>
                    </div>
                    <div class="dcx-missing" id="dcxMissing" role="status" hidden></div>
                    <div class="dcx-kpis" id="dcxKpis"></div>
                    <div class="dcx-columns">
                        <section aria-labelledby="dcxHeaderTitle"><h3 id="dcxHeaderTitle">Encabezado</h3><div id="dcxHeader"></div></section>
                        <section aria-labelledby="dcxInspectionTitle"><h3 id="dcxInspectionTitle">Inspección visual</h3><ul class="dcx-checks" id="dcxInspection"></ul></section>
                    </div>
                    <section aria-labelledby="dcxPalletsTitle">
                        <h3 id="dcxPalletsTitle">Pallets en el camión</h3>
                        <div class="dcx-table-wrap">
                            <table class="dcx-table dcx-table--pallets">
                                <thead><tr><th>N°</th><th>P/E</th><th>Folio</th><th>Especie</th><th>Cajas</th><th>T° pulpa</th><th>Estado</th><th>Registro</th></tr></thead>
                                <tbody id="dcxPallets"></tbody>
                            </table>
                        </div>
                    </section>
                    <section aria-labelledby="dcxClosingTitle"><h3 id="dcxClosingTitle">Cierre</h3><div id="dcxClosing"></div></section>
                    <section aria-labelledby="dcxPhotosTitle"><h3 id="dcxPhotosTitle">Fotos</h3><div class="dcx-photos" id="dcxPhotos"></div></section>
                    <section class="dcx-reopen" id="dcxReopen" hidden aria-labelledby="dcxReopenTitle">
                        <h3 id="dcxReopenTitle">Reabrir planilla</h3>
                        <p>La planilla vuelve a borrador para que consolidación la corrija desde la PDA. El motivo queda en su historial.</p>
                        <form id="dcxReopenForm" novalidate>
                            <label><span>Motivo *</span><textarea name="motivo" rows="2" maxlength="1000" placeholder="Qué hay que corregir" required></textarea></label>
                            <p class="form-error" id="dcxReopenError" role="alert"></p>
                            <button class="secondary-button" type="submit">Reabrir</button>
                        </form>
                    </section>
                    <section id="dcxHistory" hidden aria-labelledby="dcxHistoryTitle"><h3 id="dcxHistoryTitle">Reaperturas</h3><ul class="dcx-history" id="dcxHistoryList"></ul></section>
                </section>
            </section>
        </main>

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
