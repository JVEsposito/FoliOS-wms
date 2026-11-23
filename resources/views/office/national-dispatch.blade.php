<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Despacho nacional</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-national-dispatch.css', 'resources/js/office-national-dispatch.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <section class="office-access" id="officeAccess" aria-labelledby="nationalAccessTitle">
        <div class="office-access__brand">
            <p class="eyebrow">FoliOS · PRODUCTO TERMINADO</p>
            <h1 id="nationalAccessTitle">Despacho nacional</h1>
        </div>
        <form class="office-access__form" id="officeLoginForm">
            <h2>Acceso de Oficina</h2>
            <label>Correo electrónico <input name="email" type="email" autocomplete="username" required></label>
            <label>Contraseña <input name="password" type="password" autocomplete="current-password" required></label>
            <p class="form-error" id="officeLoginError" role="alert"></p>
            <button class="primary-button" type="submit">Entrar</button>
        </form>
    </section>

    <main class="office-app is-hidden" id="officeApp" data-folios-workspace="registro">
        <x-office.navigation domain="frigorifico" office="despacho-nacional" context="FRIGORÍFICO · DESPACHO" icon="↗" />

        <div class="national-workspace">
            <x-folios.cabecera-etiqueta area="frigorifico" seccion="Frigorífico · Despacho"
                titulo="Despacho nacional" codigo="PT · MERCADO NACIONAL"
                descripcion="Separa cajas de un folio de producto terminado y registra su salida cuando se entregue al destinatario.">
                <button class="secondary-button" id="reloadButton" type="button">Actualizar</button>
            </x-folios.cabecera-etiqueta>

            <p class="national-notice" id="nationalNotice" role="status"></p>

            <section class="folios-indicadores" aria-label="Pendientes de mercado nacional">
                <x-folios.ticket-indicador area="frigorifico" clave="FR" rotulo="Partidas pendientes" valor-id="nationalPending" valor="—" />
                <x-folios.ticket-indicador area="frigorifico" clave="FR" rotulo="Cajas pendientes" valor-id="nationalBoxes" valor="—" />
            </section>

            <x-folios.bandeja area="frigorifico" titulo="Separar cajas" id="nationalSeparationTitle" panel-id="nationalSeparation" class="is-hidden">
                <form id="nationalSearch" class="national-actions">
                    <label>Folio original
                        <input name="numero" required type="search" maxlength="50" autocomplete="off" placeholder="Escanea o escribe el folio">
                    </label>
                    <button type="submit" class="secondary-button">Consultar folio</button>
                </form>

                <form id="nationalSeparationForm" class="national-form is-hidden">
                    <p id="nationalOrigin"></p>
                    <fieldset>
                        <legend>Cajas a separar por componente</legend>
                        <div id="nationalComponents" class="national-components"></div>
                    </fieldset>
                    <p id="nationalBalance" role="status"></p>
                    <label>Motivo de la separación
                        <textarea name="motivo" rows="2" required minlength="5" maxlength="500"></textarea>
                    </label>
                    <label class="national-check">
                        <input name="confirmado" type="checkbox" required>
                        Confirmo que las cajas indicadas están físicamente separadas para mercado nacional.
                    </label>
                    <button type="submit" class="primary-button">Registrar separación</button>
                </form>
                <p class="national-error" id="nationalSearchError" role="alert"></p>
            </x-folios.bandeja>

            <x-folios.bandeja area="frigorifico" titulo="Partidas de mercado nacional" id="nationalListTitle" panel-id="nationalList">
                <x-slot:resumen>
                    <span id="nationalCount" role="status">Consultando partidas…</span>
                </x-slot:resumen>
                <div class="national-actions">
                    <label>Estado
                        <select id="nationalView">
                            <option value="pendiente">Pendientes de despacho</option>
                            <option value="despachada">Historial de salidas</option>
                        </select>
                    </label>
                </div>
                <div class="national-table" role="region" aria-label="Partidas de mercado nacional" tabindex="0">
                    <table>
                        <thead id="nationalColumns"></thead>
                        <tbody id="nationalRows"></tbody>
                    </table>
                </div>
                <nav class="national-actions national-pages" aria-label="Paginación de partidas">
                    <button id="nationalPrevious" class="secondary-button" type="button" disabled>Anterior</button>
                    <span id="nationalPage"></span>
                    <button id="nationalNext" class="secondary-button" type="button" disabled>Siguiente</button>
                </nav>
                <form id="nationalDispatchForm" class="national-form is-hidden">
                    <h3>Registrar salida</h3>
                    <p id="nationalSelection" role="status"></p>
                    <div class="national-destination">
                        <label>Destinatario <input name="destinatario" required maxlength="160"></label>
                        <label>Número de guía <input name="guia" required maxlength="60"></label>
                        <label>Patente (opcional) <input name="patente" maxlength="20"></label>
                    </div>
                    <label class="national-check">
                        <input name="confirmado" type="checkbox" required>
                        Confirmo la salida de las partidas seleccionadas.
                    </label>
                    <button type="submit" class="primary-button" disabled>Confirmar salida</button>
                </form>
                <p class="national-error" id="nationalListError" role="alert"></p>
            </x-folios.bandeja>
        </div>
    </main>
</body>
</html>
