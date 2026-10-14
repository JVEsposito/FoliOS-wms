<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Estatus operativo de despacho</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-load-status.css', 'resources/js/office-load-status.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">⇥</div>
                <p class="eyebrow">FoliOS · DESPACHO · ESTATUS OPERATIVO</p>
                <h1 id="officeAccessTitle">Observa cada carga publicada desde la cámara hasta el camión.</h1>
                <p>Concentración, separación, andenes e incidencias actualizados desde la operación real.</p>
                <div class="feature-row"><span>Cargas activas</span><span>Actualización automática</span><span>Solo datos reales</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar al estatus operativo</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Ingresar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="despacho-estatus" context="FRIGORÍFICO · PT" icon="⇥" />

            <section class="load-status-workspace" id="officeContent">
                <header class="load-status-command">
                    <div>
                        <p class="eyebrow">DESPACHO · INFORMACIÓN EN VIVO</p>
                        <h1>Estatus operativo</h1>
                        <p id="loadStatusSeason">Consultando cargas publicadas…</p>
                    </div>
                    <div class="load-status-live" id="loadStatusLive" data-tone="neutral"><i></i><span><strong>Verificando operación</strong><small id="loadStatusUpdated">Sin lectura</small></span></div>
                    <button class="secondary-button" id="reloadLoadStatus" type="button">↻ Actualizar</button>
                </header>

                <div class="load-status-layout">
                    <aside class="load-status-rail panel">
                        <header><div><p class="eyebrow">CARGAS PUBLICADAS</p><h2>Prioridad operacional</h2></div><strong id="activeLoadCount">0</strong></header>
                        <label class="load-status-search"><span>Buscar carga</span><input id="activeLoadSearch" type="search" placeholder="CAR, orden o embarque"></label>
                        <div class="load-status-list" id="activeLoadList"></div>
                    </aside>

                    <section class="load-status-detail panel" id="activeLoadDetail" aria-live="polite">
                        <div class="load-status-empty" id="activeLoadEmpty">
                            <span aria-hidden="true">⇥</span><p class="eyebrow">DESPACHO PT</p>
                            <h2>Sin cargas activas</h2><p>Las órdenes aparecerán aquí después de publicarse para la operación.</p>
                        </div>
                        <div class="is-hidden" id="activeLoadContent">
                            <header class="load-status-heading">
                                <div><p id="activeLoadBreadcrumb">Cargas · Estatus operativo</p><div><h2 id="activeLoadCode">CAR-000000</h2><span id="activeLoadState"></span><span id="activeLoadPriority"></span></div></div>
                                <a class="secondary-button" id="openLoadManagement" href="/oficina/frigorifico/despacho/cargas">Gestionar carga →</a>
                            </header>
                            <section class="load-status-facts">
                                <article><span>ORDEN / EMBARQUE</span><strong id="activeLoadReference">—</strong><small id="activeLoadSchedule">Sin programación</small></article>
                                <article><span>CÁMARA OBJETIVO</span><strong id="activeLoadCamera">—</strong><small id="activeLoadCluster">Sin grupo principal</small></article>
                                <article><span>CAMIÓN / ANDÉN</span><strong id="activeLoadTruck">Sin camión</strong><small id="activeLoadDock">Sin presencia registrada</small></article>
                                <article><span>INCIDENCIAS</span><strong id="activeLoadIncidents">0 abiertas</strong><small>Requieren resolución trazable</small></article>
                            </section>
                            <section class="load-status-progress">
                                <article><header><div><strong>Concentración</strong><span>Grupo físico principal</span></div><b id="concentrationValue">0%</b></header><div class="load-status-meter"><i id="concentrationBar"></i></div><footer><span id="concentrationComplete">0 concentrados</span><span id="concentrationPending">0 pendientes</span></footer></article>
                                <article><header><div><strong>Separación</strong><span>Avance hacia andén</span></div><b id="separationValue">0%</b></header><div class="load-status-meter"><i id="separationBar"></i></div><footer><span id="separationComplete">0 en andén</span><span id="separationPending">0 pendientes</span></footer></article>
                                <article class="load-status-progress__dispatch"><header><div><strong>Despacho directo</strong><span>Carga hacia camión</span></div><b id="dispatchState">En espera</b></header><div id="dispatchMessage">Registra la presencia del camión desde Cargas para activar la prioridad a andén.</div></article>
                            </section>
                            <div class="load-status-bottom">
                                <section><header><div><p class="eyebrow">DISTRIBUCIÓN ACTUAL</p><h3>Ubicaciones</h3></div><span id="locationSummary">0 folios</span></header><div class="load-location-list" id="loadLocationList"></div></section>
                                <section><header><div><p class="eyebrow">DETALLE OPERACIONAL</p><h3>Folios de la carga</h3></div><span id="folioSummary">0 folios</span></header><div class="load-status-table-scroll"><table><thead><tr><th>Folio</th><th>Ubicación</th><th>Estado</th></tr></thead><tbody id="activeLoadFolios"></tbody></table></div></section>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </main>
        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Consultando operación…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
