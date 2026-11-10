<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><meta name="robots" content="noindex">
    <title>FoliOS · Portal de clientes</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/estiba-ui.css', 'resources/css/client-portal.css', 'resources/js/client-portal.js'])
    @endif
    @include('office.partials.arranque', ['soloTema' => true])
</head>
<body class="client-portal estiba-ui">
@php($planta = config('oficina.planta'))
<header class="portal-bar">
    <div class="portal-bar__brand">
        <x-folios-logo surface="dark" class="portal-bar__logo" />
        <div><p class="portal-bar__eyebrow">{{ $planta ? 'Portal de clientes · '.$planta : 'Portal de clientes' }}</p><p class="portal-bar__season" id="portalSeason"></p></div>
    </div>
    <div class="portal-bar__session" id="portalSession" hidden>
        <label class="portal-client-picker" id="portalClientPicker" hidden><span>Exportadora</span><select class="eui-input" id="portalClient"></select></label>
        <strong class="portal-client-name" id="portalClientName"></strong>
        <button class="eui-button eui-button--secondary" type="button" id="portalPasswordOpen">Contraseña</button>
        <button class="eui-button eui-button--secondary" type="button" id="portalLogout">Salir</button>
    </div>
</header>

<main class="portal-main estiba-ui">
    <p class="portal-status" id="portalStatus" role="status" aria-live="polite">Revisando tu sesión…</p>

    <form class="eui-panel portal-card portal-login" id="portalLogin" novalidate hidden>
        <x-estiba.heading eyebrow="Acceso" title="Portal de clientes" description="Consulta tus recepciones, embarques, folios y materiales, y solicita horarios de carga." />
        <label>Correo electrónico<input class="eui-input" name="email" type="email" autocomplete="username" required></label>
        <label>Contraseña<input class="eui-input" name="password" type="password" autocomplete="current-password" required></label>
        <p class="form-error" id="portalLoginError" role="alert"></p>
        <button class="eui-button" type="submit">Ingresar</button>
    </form>

    <form class="eui-panel portal-card portal-password" id="portalPassword" novalidate hidden>
        <x-estiba.heading eyebrow="Seguridad" title="Cambia tu contraseña" description="Usa al menos 10 caracteres con letras y números." level="h2" />
        <label>Contraseña actual<input class="eui-input" name="password_actual" type="password" autocomplete="current-password" required></label>
        <label>Nueva contraseña<input class="eui-input" name="password_nueva" type="password" autocomplete="new-password" minlength="10" required></label>
        <label>Repite la nueva contraseña<input class="eui-input" name="password_nueva_confirmation" type="password" autocomplete="new-password" minlength="10" required></label>
        <p class="form-error" id="portalPasswordError" role="alert"></p>
        <div class="eui-actions"><button class="eui-button eui-button--secondary" type="button" id="portalPasswordCancel">Cancelar</button><button class="eui-button" type="submit">Guardar contraseña</button></div>
    </form>

    <section class="portal-app" id="portalApp" hidden>
        <nav class="portal-tabs" role="tablist" aria-label="Secciones del portal">
            <button type="button" role="tab" data-tab="resumen">Resumen</button>
            <button type="button" role="tab" data-tab="recepciones">Recepciones</button>
            <button type="button" role="tab" data-tab="embarques">Embarques</button>
            <button type="button" role="tab" data-tab="horarios">Horario de carga</button>
            <button type="button" role="tab" data-tab="folios">Folios</button>
            <button type="button" role="tab" data-tab="materiales">Materiales</button>
        </nav>
        <p class="form-error" id="portalError" role="alert"></p>

        <section class="portal-panel" data-panel="resumen" role="tabpanel" aria-labelledby="portalSummaryTitle">
            <h1 class="portal-title" id="portalSummaryTitle">Resumen de la temporada</h1>
            <div class="portal-kpis" id="portalKpis"></div>
        </section>

        <section class="portal-panel" data-panel="recepciones" role="tabpanel" aria-labelledby="portalReceptionsTitle" hidden>
            <h1 class="portal-title" id="portalReceptionsTitle">Kilos recepcionados</h1>
            <form class="eui-panel portal-card portal-filters" id="portalReceptionFilters">
                <label>Desde<input class="eui-input" type="date" name="desde"></label>
                <label>Hasta<input class="eui-input" type="date" name="hasta"></label>
                <button class="eui-button eui-button--secondary" type="submit">Consultar</button>
            </form>
            <div class="portal-kpis" id="portalReceptionTotals"></div>
            <div class="portal-list" id="portalReceptions"></div>
            <div class="portal-pager" id="portalReceptionsPager"></div>
        </section>

        <section class="portal-panel" data-panel="embarques" role="tabpanel" aria-labelledby="portalShipmentsTitle" hidden>
            <h1 class="portal-title" id="portalShipmentsTitle">Embarques programados</h1>
            <div class="portal-list" id="portalShipments"></div>
        </section>

        <section class="portal-panel" data-panel="horarios" role="tabpanel" aria-labelledby="portalSlotsTitle" hidden>
            <h1 class="portal-title" id="portalSlotsTitle">Solicitar horario de carga</h1>
            <p class="portal-help">Elige un bloque libre. Despacho confirma o rechaza la solicitud; mientras tanto el bloque queda reservado para ti.</p>
            <div class="portal-days" id="portalDays" role="group" aria-label="Día"></div>
            <div class="portal-slots" id="portalSlots" role="group" aria-label="Bloques del día"></div>
            <form class="eui-panel portal-card portal-request" id="portalRequest" hidden>
                <h2 id="portalRequestTitle">Solicitud</h2>
                <label>Modalidad<select class="eui-input" name="modalidad">
                    <option value="por_confirmar">Por confirmar</option><option value="maritimo">Marítimo</option>
                    <option value="aereo">Aéreo</option><option value="terrestre">Terrestre</option></select></label>
                <label>Pallets estimados<input class="eui-input" name="cantidad_pallets" type="number" min="1" max="60" inputmode="numeric"></label>
                <label>Booking o referencia<input class="eui-input" name="referencia" maxlength="150"></label>
                <label class="portal-wide">Observación<textarea class="eui-input" name="observacion" maxlength="1000" rows="2"></textarea></label>
                <p class="form-error portal-wide" id="portalRequestError" role="alert"></p>
                <div class="eui-actions portal-wide"><button class="eui-button eui-button--secondary" type="button" id="portalRequestCancel">Cancelar</button><button class="eui-button" type="submit">Enviar solicitud</button></div>
            </form>
            <h2 class="portal-subtitle">Mis solicitudes</h2>
            <div class="portal-list" id="portalRequests"></div>
        </section>

        <section class="portal-panel" data-panel="folios" role="tabpanel" aria-labelledby="portalFoliosTitle" hidden>
            <h1 class="portal-title" id="portalFoliosTitle">Consulta de folios</h1>
            <form class="eui-panel portal-card portal-filters" id="portalFolioFilters">
                <label>N° de folio<input class="eui-input" name="q" inputmode="numeric" maxlength="50" placeholder="Ej. 6000000785"></label>
                <label>Situación<select class="eui-input" name="situacion"><option value="en_planta">En planta</option><option value="despachados">Despachados</option><option value="todos">Todos</option></select></label>
                <button class="eui-button eui-button--secondary" type="submit">Buscar</button>
            </form>
            <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Folio</th><th>Estado</th><th>Producto</th><th>Cajas</th><th>CSG</th><th>Proceso</th></tr></thead><tbody id="portalFolios"></tbody></table></div>
            <div class="portal-pager" id="portalFoliosPager"></div>
        </section>

        <section class="portal-panel" data-panel="materiales" role="tabpanel" aria-labelledby="portalMaterialsTitle" hidden>
            <h1 class="portal-title" id="portalMaterialsTitle">Mis materiales en bodega</h1>
            <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Ítem</th><th>Categoría</th><th>Existencia</th><th>Reservado</th><th>Disponible</th></tr></thead><tbody id="portalMaterials"></tbody></table></div>
        </section>
    </section>
</main>
</body>
</html>
