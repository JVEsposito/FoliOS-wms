<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Expedientes de embarque</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-comex.css', 'resources/js/office-comex-files.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">⚓</div>
                <p class="eyebrow">FoliOS · COMEX</p>
                <h1 id="officeAccessTitle">Cada embarque con sus documentos al día.</h1>
                <p>Comex lee el calendario de embarques y sus cargas sin modificarlos, y lleva el expediente de cada uno.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Comex</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="administracion" office="comex-expedientes" context="ADMINISTRACIÓN · COMEX" icon="⚓" />

            <section class="cx-workspace">
                <header class="cx-heading">
                    <div>
                        <p class="eyebrow">COMEX · LEÍDO DEL CALENDARIO DE EMBARQUES</p>
                        <h1>Expedientes de embarque</h1>
                        <p>Los documentos que pide cada embarque según su destino, transporte y cliente, con lo que falta antes de la salida. No modifica el calendario ni las cargas.</p>
                    </div>
                    <form class="cx-range" id="filesRange" novalidate>
                        <label><span>Desde</span><input id="filesFrom" name="desde" type="date" required></label>
                        <label><span>Hasta</span><input id="filesTo" name="hasta" type="date" required></label>
                        <button class="secondary-button" type="submit">Ver</button>
                    </form>
                </header>

                <div class="cx-status" id="filesStatus" role="status">Cargando…</div>

                <section class="panel cx-panel cx-empty-config" id="filesSetup" hidden>
                    <div>
                        <p class="eyebrow">PRIMER PASO</p>
                        <h2>Todavía no hay documentos configurados</h2>
                        <p id="filesSetupText">Comex necesita saber qué documentos pide cada embarque.</p>
                    </div>
                    <div class="cx-acciones" id="filesSetupActions"></div>
                </section>

                <div class="cx-kpis" id="filesKpis" aria-label="Resumen de los expedientes"></div>

                <section class="panel cx-panel" aria-labelledby="filesListTitle">
                    <div class="cx-panel__heading">
                        <div><p class="eyebrow">EMBARQUES DEL PERÍODO</p><h2 id="filesListTitle">Expedientes</h2></div>
                        <small>Primero lo más urgente. Abra un embarque para registrar sus documentos.</small>
                    </div>
                    <form class="cx-filters" id="filesFilters" novalidate>
                        <label class="cx-filters__search"><span>Buscar</span><input id="filesSearch" type="search" maxlength="100" placeholder="Embarque, cliente, contenedor, booking, destino"></label>
                        <label><span>Cliente</span><select id="filesClient"><option value="">Todos</option></select></label>
                        <label><span>Situación</span>
                            <select id="filesAlert">
                                <option value="">Todas</option>
                                <option value="salio_incompleto">Salió incompleto</option>
                                <option value="urgente">Urgente</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="completo">Completo</option>
                                <option value="cancelado">Cancelado</option>
                                <option value="sin_seguimiento">Sin seguimiento</option>
                            </select>
                        </label>
                        <label class="cx-check"><input id="filesAll" type="checkbox"> <span>Incluir cancelados y clientes sin seguimiento</span></label>
                    </form>
                    <div class="cx-embarques" id="filesList"></div>
                </section>
            </section>
        </main>

        <dialog class="cx-dialog cx-dialog--ancho" id="fileDetail" aria-labelledby="fileDetailTitle">
            <header class="cx-dialog__heading">
                <div>
                    <p class="eyebrow" id="fileDetailEyebrow">EXPEDIENTE</p>
                    <h2 id="fileDetailTitle">Embarque</h2>
                    <p class="cx-dialog__sub" id="fileDetailSubtitle"></p>
                </div>
                <button class="cx-dialog__close" data-cx-close type="button" aria-label="Cerrar">×</button>
            </header>
            <div class="cx-dialog__body" id="fileDetailBody"></div>
            <footer class="cx-dialog__actions">
                <button class="secondary-button" id="fileDetailPacking" type="button">↓ Packing list (Excel)</button>
                <button class="secondary-button" data-cx-close type="button">Cerrar</button>
            </footer>
        </dialog>

        <dialog class="cx-dialog" id="documentDialog" aria-labelledby="documentTitle">
            <form id="documentForm" novalidate>
                <header class="cx-dialog__heading">
                    <div><p class="eyebrow" id="documentEyebrow">DOCUMENTO</p><h2 id="documentTitle">Registrar documento</h2></div>
                    <button class="cx-dialog__close" data-cx-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="cx-form">
                    <p class="cx-form__nota cx-form__wide" id="documentDescription"></p>
                    <fieldset class="cx-estados cx-form__wide">
                        <legend>Estado</legend>
                        <label><input type="radio" name="estado" value="en_tramite"> <span>En trámite</span></label>
                        <label><input type="radio" name="estado" value="listo"> <span>Listo</span></label>
                        <label><input type="radio" name="estado" value="no_aplica"> <span>No aplica</span></label>
                    </fieldset>
                    <label><span id="documentNumberLabel">Número</span><input name="numero" id="documentNumber" maxlength="120" autocomplete="off"></label>
                    <label><span id="documentDateLabel">Fecha de emisión</span><input name="fecha_emision" id="documentDate" type="date"></label>
                    <p class="cx-form__referencia cx-form__wide" id="documentReference" hidden></p>
                    <label class="cx-form__wide"><span id="documentFileLabel">Respaldo (PDF, JPG, PNG o WebP)</span><input name="archivo" id="documentFile" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"></label>
                    <p class="cx-form__nota cx-form__wide" id="documentCurrentFile"></p>
                    <label class="cx-form__wide" id="documentReasonField" hidden><span>Por qué no aplica</span><textarea name="motivo" id="documentReason" rows="2" maxlength="500" placeholder="Por ejemplo, el destino no exige inspección en origen."></textarea></label>
                    <label class="cx-form__wide"><span>Observación</span><textarea name="observacion" id="documentNote" rows="2" maxlength="1000"></textarea></label>
                    <p class="form-error cx-form__wide" id="documentMessage" role="alert"></p>
                </div>
                <footer class="cx-dialog__actions">
                    <button class="secondary-button" data-cx-close type="button">Cancelar</button>
                    <button class="primary-button" id="documentSubmit" type="submit">Guardar</button>
                </footer>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
