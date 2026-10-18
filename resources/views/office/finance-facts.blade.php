<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Hechos valorizables</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-finance.css', 'resources/js/office-finance-facts.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">$</div>
                <p class="eyebrow">FoliOS · FINANZAS</p>
                <h1 id="officeAccessTitle">Lo que la operación registró, listo para valorizar.</h1>
                <p>Finanzas lee la operación sin modificarla. Cada corrección queda como una versión nueva del hecho.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Finanzas</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="administracion" office="finanzas-hechos" context="ADMINISTRACIÓN · FINANZAS" icon="$" />

            <section class="fin-workspace">
                <header class="fin-heading">
                    <div>
                        <p class="eyebrow">FINANZAS · SOLO LECTURA DE LA OPERACIÓN</p>
                        <h1>Hechos valorizables</h1>
                        <p>Lo que la operación registró y Finanzas puede cobrar. Se lee cada 10 minutos sin modificar la operación; una corrección o una anulación queda como versión nueva.</p>
                    </div>
                    <div class="fin-heading__actions">
                        <label class="fin-month"><span>Mes</span><input id="financeMonth" type="month" required></label>
                        <button class="primary-button" id="financeCaptureButton" type="button" hidden>↻ Leer ahora</button>
                    </div>
                </header>

                <div class="fin-status" id="financeStatus" role="status">Cargando…</div>

                <div class="fin-kpis" id="financeKpis" aria-label="Resumen del mes por fuente"></div>

                <x-office.panel-switcher
                    id="finance-facts"
                    label="Vistas de hechos valorizables"
                    default="facts"
                    :panels="[
                        'facts' => ['label' => 'Hechos', 'icon' => '$', 'badge_id' => 'financeFactsCount'],
                        'reads' => ['label' => 'Lecturas', 'icon' => '↻', 'badge_id' => 'financeReadsCount'],
                    ]"
                />

                <section class="panel fin-panel" id="finance-facts-panel-facts" data-office-panel-group="finance-facts" data-office-panel-id="facts" role="tabpanel" aria-labelledby="finance-facts-tab-facts">
                    <form class="fin-filters" id="financeFilters">
                        <label><span>Cliente</span><select name="cliente" id="financeClient"><option value="">Todos los clientes</option></select></label>
                        <label><span>Fuente</span><select name="fuente" id="financeSource"><option value="">Todas las fuentes</option></select></label>
                        <label><span>Estado</span>
                            <select name="estado">
                                <option value="vigentes">Vigentes</option>
                                <option value="anulados">Anulados</option>
                                <option value="todos">Todos</option>
                            </select>
                        </label>
                        <label class="fin-filters__search"><span>Buscar</span><input name="q" type="search" maxlength="100" placeholder="Folio, recepción, carga o cliente"></label>
                    </form>
                    <div class="fin-table-scroll">
                        <table class="fin-table">
                            <thead><tr><th>Fecha</th><th>Fuente</th><th>Referencia</th><th>Cliente</th><th class="fin-num">Medidas</th><th>Detalle</th><th>Versión</th></tr></thead>
                            <tbody id="financeFactsBody"></tbody>
                        </table>
                    </div>
                    <nav class="fin-pages" aria-label="Páginas de hechos">
                        <button class="secondary-button" id="financePrevious" type="button">← Anterior</button>
                        <span id="financePageStatus">Página 1</span>
                        <button class="secondary-button" id="financeNext" type="button">Siguiente →</button>
                    </nav>
                </section>

                <section class="panel fin-panel" id="finance-facts-panel-reads" data-office-panel-group="finance-facts" data-office-panel-id="reads" role="tabpanel" aria-labelledby="finance-facts-tab-reads" hidden>
                    <div class="fin-panel__heading"><div><p class="eyebrow">LECTURAS DE LA OPERACIÓN</p><h2>Cuándo se leyó y qué se encontró</h2></div><small id="financeReadsHelp"></small></div>
                    <div class="fin-table-scroll">
                        <table class="fin-table">
                            <thead><tr><th>Inicio</th><th>Origen</th><th>Estado</th><th class="fin-num">Duración</th><th>Encontrado</th></tr></thead>
                            <tbody id="financeReadsBody"></tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>

        <dialog class="fin-history" id="financeHistory" aria-labelledby="financeHistoryTitle">
            <header class="fin-history__heading">
                <div><p class="eyebrow" id="financeHistorySource">HISTORIAL</p><h2 id="financeHistoryTitle">Versiones del hecho</h2></div>
                <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
            </header>
            <ol class="fin-history__list" id="financeHistoryList"></ol>
            <footer class="fin-history__actions"><button class="secondary-button" data-fin-close type="button">Cerrar</button></footer>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
