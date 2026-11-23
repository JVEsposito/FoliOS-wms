<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Demo comercial</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-corporate.css', 'resources/css/office-demo.css', 'resources/js/office-demo.js'])
        @endif
        @include('office.partials.arranque', ['soloTema' => true])
    </head>
    <body class="demo-body">
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand demo-access-brand">
                <div class="office-logo" aria-hidden="true">▶</div>
                <p class="eyebrow">FoliOS · DEMO COMERCIAL</p>
                <h1 id="officeAccessTitle">Presenta el WMS sin exponer la operación real.</h1>
                <p>El acceso verifica una cuenta administradora. Los datos de la demostración se crean después, exclusivamente en esta sesión del navegador.</p>
                <div class="feature-row"><span>Datos ficticios</span><span>Sin temporada en MySQL</span><span>Sesión local</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">AUTORIZACIÓN ADMINISTRATIVA</p><h2>Ingresar para habilitar</h2><p>Solo una cuenta con rol base Administrador puede preparar el escenario demo.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Validar administrador <span>→</span></button>
                <a class="demo-back-link" href="/oficina/administracion">Volver a Administración</a>
            </form>
        </section>

        <main class="demo-activation is-hidden" id="demoActivation" data-demo-activation>
            <section class="demo-activation__card">
                <span class="demo-activation__icon" aria-hidden="true">▶</span>
                <p class="eyebrow">ESCENARIO LOCAL AISLADO</p>
                <h1>Habilitar versión demo</h1>
                <p>Se cargará una temporada ficticia con recepciones, hidrocooler, cámaras, prefrío, materiales, cargas y trazabilidad. Ningún registro será enviado al servidor.</p>
                <div class="demo-safety-grid">
                    <article><strong>0</strong><span>cambios en temporada productiva</span></article>
                    <article><strong>1 pestaña</strong><span>ámbito de la demostración</span></article>
                    <article><strong>Local</strong><span>almacenamiento temporal</span></article>
                </div>
                <label class="demo-confirmation"><input id="demoSafetyConfirmation" type="checkbox"><span>Entiendo que esta oficina contiene únicamente información ficticia y que al cerrar la pestaña el escenario se eliminará.</span></label>
                <p class="form-error" id="demoActivationError" role="alert"></p>
                <div class="demo-activation__actions">
                    <a class="secondary-button" href="/oficina/administracion">Cancelar</a>
                    <button class="primary-button" id="enableDemoButton" type="button" disabled>Habilitar demo en este PC</button>
                </div>
            </section>
        </main>

        <main class="demo-app is-hidden" id="demoApp">
            <header class="demo-topbar">
                <div class="demo-brand">
                    <x-folios.gajo area="operacion" :size="30" />
                    <div><strong>FoliOS</strong><small>RECORRIDO COMERCIAL</small></div>
                    <b>DEMO LOCAL</b>
                </div>
                <div class="demo-session-identity">
                    <span id="demoAdministratorName">Administrador</span>
                    <small id="demoSessionSince">Sesión local</small>
                    <button id="restoreDemoButton" type="button">Restablecer escenario</button>
                    <button id="exitDemoButton" type="button">Salir de demo</button>
                </div>
            </header>

            <aside class="demo-isolation-banner">
                <strong>Modo demostración aislado</strong>
                <span>Todos los nombres, folios, cantidades y movimientos son ficticios. No se consulta ni modifica la temporada productiva.</span>
            </aside>

            <nav class="demo-tabs" aria-label="Áreas de la demostración">
                <button class="is-active" data-demo-tab="summary" type="button" aria-pressed="true">Resumen gerencial</button>
                <button data-demo-tab="raw-material" type="button" aria-pressed="false">Materia Prima</button>
                <button data-demo-tab="refrigerated" type="button" aria-pressed="false">Frigorífico</button>
                <button data-demo-tab="materials" type="button" aria-pressed="false">Materiales</button>
                <button data-demo-tab="traceability" type="button" aria-pressed="false">Trazabilidad</button>
            </nav>

            <section class="demo-content">
                <section class="demo-panel" data-demo-panel="summary">
                    <header class="demo-section-heading">
                        <div><p class="eyebrow">VISIÓN EJECUTIVA</p><h1 id="demoScenarioTitle">Temporada Demo</h1><p id="demoScenarioMeta">Escenario ficticio</p></div>
                        <button class="primary-button" id="advanceDemoButton" type="button">Simular siguiente corte</button>
                    </header>
                    <div class="demo-kpi-grid" id="demoKpiGrid"></div>
                    <div class="demo-dashboard-grid">
                        <section class="demo-card demo-card--wide"><header><div><p class="eyebrow">CAPACIDAD</p><h2>Ocupación por cámara</h2></div><strong id="demoOverallOccupancy">0%</strong></header><div class="demo-bars" id="demoCameraBars"></div></section>
                        <section class="demo-card"><header><div><p class="eyebrow">ATENCIÓN</p><h2>Focos operacionales</h2></div><strong id="demoAlertCount">0</strong></header><div class="demo-alert-list" id="demoAlertList"></div></section>
                        <section class="demo-card demo-card--wide"><header><div><p class="eyebrow">FLUJO INTEGRADO</p><h2>Desde recepción hasta despacho</h2></div></header><div class="demo-flow"><span><b id="demoFlowReceptions">0</b>Recepciones</span><i>→</i><span><b id="demoFlowHydrocooler">0</b>Hidrocooler</span><i>→</i><span><b id="demoFlowPrecooling">0</b>Prefrío</span><i>→</i><span><b id="demoFlowLoads">0</b>Cargas</span></div></section>
                        <section class="demo-card"><header><div><p class="eyebrow">BITÁCORA LOCAL</p><h2>Actividad de la demo</h2></div></header><div class="demo-audit-list" id="demoAuditList"></div></section>
                    </div>
                </section>

                <section class="demo-panel is-hidden" data-demo-panel="raw-material">
                    <header class="demo-section-heading"><div><p class="eyebrow">RECEPCIÓN Y ABASTECIMIENTO</p><h1>Materia Prima</h1><p>Romana, validación, hidrocooler y continuidad de lotes.</p></div></header>
                    <div class="demo-kpi-grid demo-kpi-grid--compact" id="demoRawMaterialKpis"></div>
                    <div class="demo-two-columns">
                        <section class="demo-card"><header><div><p class="eyebrow">HIDROCOOLER</p><h2>Ciclos activos</h2></div></header><div id="demoHydrocoolerList" class="demo-unit-list"></div></section>
                        <section class="demo-card"><header><div><p class="eyebrow">LOTES</p><h2>Continuidad operacional</h2></div></header><div id="demoRawMaterialLots" class="demo-unit-list"></div></section>
                    </div>
                    <section class="demo-card demo-table-card"><header><div><p class="eyebrow">ROMANA</p><h2>Últimas recepciones</h2></div></header><div class="demo-table-wrap"><table><thead><tr><th>Recepción</th><th>Cliente</th><th>Guía</th><th>Envases</th><th>Kilos</th><th>Estado</th></tr></thead><tbody id="demoReceptionRows"></tbody></table></div></section>
                </section>

                <section class="demo-panel is-hidden" data-demo-panel="refrigerated">
                    <header class="demo-section-heading"><div><p class="eyebrow">CADENA DE FRÍO Y DESPACHO</p><h1>Frigorífico</h1><p>Prefrío, cámaras de producto terminado y preparación de cargas.</p></div></header>
                    <div class="demo-two-columns">
                        <section class="demo-card"><header><div><p class="eyebrow">PREFRÍO</p><h2>Procesos en túneles</h2></div><strong id="demoAverageCycle">—</strong></header><div id="demoPrecoolingList" class="demo-unit-list"></div></section>
                        <section class="demo-card"><header><div><p class="eyebrow">CÁMARAS</p><h2>Ocupación actual</h2></div></header><div id="demoCameraList" class="demo-unit-list"></div></section>
                    </div>
                    <section class="demo-card demo-table-card"><header><div><p class="eyebrow">CARGAS</p><h2>Programa de despachos</h2></div></header><div class="demo-table-wrap"><table><thead><tr><th>Carga</th><th>Cliente</th><th>Destino</th><th>Preparación</th><th>Ventana</th><th>Estado</th></tr></thead><tbody id="demoLoadRows"></tbody></table></div></section>
                </section>

                <section class="demo-panel is-hidden" data-demo-panel="materials">
                    <header class="demo-section-heading"><div><p class="eyebrow">INVENTARIO Y ABASTECIMIENTO</p><h1>Materiales</h1><p>Existencia, reservas, disponibilidad y despachos hacia centros de costo.</p></div></header>
                    <div class="demo-kpi-grid demo-kpi-grid--compact" id="demoMaterialKpis"></div>
                    <section class="demo-card demo-table-card"><header><div><p class="eyebrow">INVENTARIO</p><h2>Saldos por ítem</h2></div></header><div class="demo-table-wrap"><table><thead><tr><th>Ítem</th><th>Cliente</th><th>Descripción</th><th>Stock</th><th>Reservado</th><th>Disponible</th><th>Almacén</th></tr></thead><tbody id="demoMaterialRows"></tbody></table></div></section>
                    <section class="demo-card"><header><div><p class="eyebrow">DESPACHOS</p><h2>Movimientos recientes</h2></div></header><div id="demoMaterialDispatches" class="demo-unit-list demo-unit-list--horizontal"></div></section>
                </section>

                <section class="demo-panel is-hidden" data-demo-panel="traceability">
                    <header class="demo-section-heading"><div><p class="eyebrow">EXPEDIENTE TRANSVERSAL</p><h1>Trazabilidad</h1><p>Busca un ejemplo y recorre su historia desde el origen hasta su estado actual.</p></div></header>
                    <form class="demo-search" id="demoTraceabilityForm">
                        <label><span>Folio, lote o material</span><input id="demoTraceabilityQuery" list="demoTraceabilityOptions" placeholder="Ej. DEMO-6000031846" required></label>
                        <datalist id="demoTraceabilityOptions"></datalist>
                        <button class="primary-button" type="submit">Buscar expediente</button>
                    </form>
                    <section class="demo-trace-card" id="demoTraceabilityResult"></section>
                </section>
            </section>
        </main>

        <div class="loading-overlay is-hidden" id="officeLoading" aria-hidden="true"><div class="spinner"></div><p id="officeLoadingText">Preparando escenario demo…</p></div>
        <div class="toast-stack" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
