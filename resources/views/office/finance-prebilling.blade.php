<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Prefactura</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-finance.css', 'resources/js/office-finance-prebilling.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">$</div>
                <p class="eyebrow">FoliOS · FINANZAS</p>
                <h1 id="officeAccessTitle">El mes valorizado, en paralelo a la facturación.</h1>
                <p>La prefactura se calcula con los hechos capturados y el tarifario, sin modificar la operación.</p>
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
            <x-office.navigation domain="administracion" office="finanzas-prefactura" context="ADMINISTRACIÓN · FINANZAS" icon="$" />

            <section class="fin-workspace">
                <header class="fin-heading">
                    <div>
                        <p class="eyebrow">FINANZAS · EN PARALELO A LA FACTURACIÓN</p>
                        <h1>Prefactura</h1>
                        <p>El mes valorizado por cliente con los hechos capturados y el tarifario. No emite documentos ni reemplaza la facturación: sirve para compararla.</p>
                    </div>
                    <div class="fin-heading__actions">
                        <label class="fin-month"><span>Mes</span><input id="prebillingMonth" type="month" required></label>
                        <button class="secondary-button" id="prebillingExport" type="button">↓ Descargar Excel</button>
                    </div>
                </header>

                <div class="fin-status" id="prebillingStatus" role="status">Cargando…</div>

                <div class="fin-kpis fin-kpis--datos" id="prebillingKpis" aria-label="Resumen de la prefactura"></div>

                <section class="panel fin-panel" aria-labelledby="prebillingClientsTitle">
                    <div class="fin-panel__heading">
                        <div><p class="eyebrow">POR CLIENTE</p><h2 id="prebillingClientsTitle">Clientes valorizados</h2></div>
                        <small>Abra un cliente para ver sus líneas y los hechos que forman cada una.</small>
                    </div>
                    <form class="fin-filters fin-filters--una" id="prebillingFilters">
                        <label class="fin-filters__search"><span>Buscar cliente</span><input name="q" id="prebillingSearch" type="search" maxlength="100" placeholder="Nombre del cliente"></label>
                    </form>
                    <div class="fin-clientes" id="prebillingClients"></div>
                </section>

                <section class="panel fin-panel" id="prebillingUnlinked" aria-labelledby="prebillingUnlinkedTitle" hidden>
                    <div class="fin-panel__heading">
                        <div><p class="eyebrow">PENDIENTES</p><h2 id="prebillingUnlinkedTitle">Hechos sin cliente vinculado</h2></div>
                        <small>No se valorizan hasta vincular el cliente en los maestros; la captura los vuelve a leer sola.</small>
                    </div>
                    <ul class="fin-pendientes" id="prebillingUnlinkedList"></ul>
                    <a class="fin-boton fin-boton--enlace" href="/oficina/finanzas/hechos">Ver en Hechos valorizables</a>
                </section>
            </section>
        </main>

        <dialog class="fin-history" id="prebillingDetail" aria-labelledby="prebillingDetailTitle">
            <header class="fin-history__heading">
                <div><p class="eyebrow" id="prebillingDetailEyebrow">DETALLE</p><h2 id="prebillingDetailTitle">Hechos de la línea</h2></div>
                <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
            </header>
            <p class="fin-detalle__resumen" id="prebillingDetailSummary"></p>
            <ol class="fin-history__list fin-detalle" id="prebillingDetailList"></ol>
            <footer class="fin-history__actions"><button class="secondary-button" data-fin-close type="button">Cerrar</button></footer>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
