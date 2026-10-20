<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Configuración Comex</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-comex.css', 'resources/js/office-comex-settings.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">☰</div>
                <p class="eyebrow">FoliOS · COMEX</p>
                <h1 id="officeAccessTitle">Qué documentos pide cada embarque.</h1>
                <p>Cada planta define sus documentos según destino, transporte y cliente.</p>
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
            <x-office.navigation domain="administracion" office="comex-configuracion" context="ADMINISTRACIÓN · COMEX" icon="☰" />

            <section class="cx-workspace">
                <header class="cx-heading">
                    <div>
                        <p class="eyebrow">COMEX · CONFIGURACIÓN</p>
                        <h1>Configuración Comex</h1>
                        <p>Los documentos que se exigen en los expedientes y cómo se sigue a cada cliente. Los cambios valen desde ya para todos los embarques, sin borrar lo ya registrado.</p>
                    </div>
                </header>

                <div class="cx-status" id="settingsStatus" role="status">Cargando…</div>

                <section class="panel cx-panel" aria-labelledby="settingsTypesTitle">
                    <div class="cx-panel__heading">
                        <div><p class="eyebrow">DOCUMENTOS</p><h2 id="settingsTypesTitle">Documentos del expediente</h2></div>
                        <div class="cx-acciones" id="settingsTypesActions"></div>
                    </div>
                    <div class="cx-tipos" id="settingsTypes"></div>
                </section>

                <section class="panel cx-panel" aria-labelledby="settingsClientsTitle">
                    <div class="cx-panel__heading">
                        <div><p class="eyebrow">CLIENTES</p><h2 id="settingsClientsTitle">Cómo se sigue a cada cliente</h2></div>
                        <small>Servicio: la planta sigue los documentos del exportador. Exportación propia: la empresa exporta y se suman sus documentos (por ejemplo, la factura). Sin seguimiento: sus embarques no aparecen en los expedientes.</small>
                    </div>
                    <form class="cx-filters cx-filters--una" id="settingsClientFilters" novalidate>
                        <label class="cx-filters__search"><span>Buscar cliente</span><input id="settingsClientSearch" type="search" maxlength="100" placeholder="Nombre del cliente"></label>
                        <label><span>Gestión</span>
                            <select id="settingsClientManagement">
                                <option value="">Todas</option>
                                <option value="servicio">Servicio</option>
                                <option value="propia">Exportación propia</option>
                                <option value="sin_seguimiento">Sin seguimiento</option>
                            </select>
                        </label>
                    </form>
                    <div class="cx-clientes" id="settingsClients"></div>
                </section>
            </section>
        </main>

        <dialog class="cx-dialog" id="typeDialog" aria-labelledby="typeTitle">
            <form id="typeForm" novalidate>
                <header class="cx-dialog__heading">
                    <div><p class="eyebrow" id="typeEyebrow">DOCUMENTO</p><h2 id="typeTitle">Agregar documento</h2></div>
                    <button class="cx-dialog__close" data-cx-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="cx-form">
                    <label class="cx-form__wide"><span>Nombre</span><input name="nombre" id="typeName" maxlength="120" required></label>
                    <label class="cx-form__wide"><span>Descripción</span><textarea name="descripcion" id="typeDescription" rows="2" maxlength="500"></textarea></label>
                    <label><span>Se necesita</span>
                        <select name="momento" id="typeMoment">
                            <option value="antes_salida">Antes de la salida</option>
                            <option value="despues_salida">Después de la salida</option>
                        </select>
                    </label>
                    <label><span>Se exige</span>
                        <select name="exigencia" id="typeRequirement">
                            <option value="siempre">Siempre</option>
                            <option value="paises">Solo en algunos destinos</option>
                            <option value="opcional">Opcional (no se exige)</option>
                        </select>
                    </label>
                    <fieldset class="cx-form__wide cx-paises" id="typeCountriesField" hidden>
                        <legend>Destinos que lo exigen</legend>
                        <input id="typeCountrySearch" type="search" maxlength="60" placeholder="Buscar país" aria-label="Buscar país">
                        <p class="cx-form__nota" id="typeCountriesChosen"></p>
                        <div class="cx-paises__lista" id="typeCountries"></div>
                    </fieldset>
                    <fieldset class="cx-casillas">
                        <legend>Transporte</legend>
                        <label><input type="checkbox" name="transportes" value="maritimo"> <span>Marítimo</span></label>
                        <label><input type="checkbox" name="transportes" value="aereo"> <span>Aéreo</span></label>
                        <label><input type="checkbox" name="transportes" value="terrestre"> <span>Terrestre</span></label>
                    </fieldset>
                    <fieldset class="cx-casillas">
                        <legend>Clientes</legend>
                        <label><input type="checkbox" name="gestiones" value="servicio"> <span>Servicio</span></label>
                        <label><input type="checkbox" name="gestiones" value="propia"> <span>Exportación propia</span></label>
                    </fieldset>
                    <fieldset class="cx-casillas cx-form__wide">
                        <legend>Para marcarlo listo se pide</legend>
                        <label><input type="checkbox" name="pide_numero" value="1"> <span>Número</span></label>
                        <label><input type="checkbox" name="pide_fecha" value="1"> <span>Fecha de emisión</span></label>
                        <label><input type="checkbox" name="pide_archivo" value="1"> <span>Respaldo (archivo)</span></label>
                    </fieldset>
                    <label><span>Dato que ya registra la operación</span><select name="dato_operacion" id="typeOperationField"><option value="">Ninguno</option></select></label>
                    <label><span>Orden en el expediente</span><input name="orden" id="typeOrder" type="number" min="0" max="9999" step="1"></label>
                    <label class="cx-check cx-form__wide"><input type="checkbox" name="activo" value="1" id="typeActive"> <span>Activo: se pide en los expedientes</span></label>
                    <p class="form-error cx-form__wide" id="typeMessage" role="alert"></p>
                </div>
                <footer class="cx-dialog__actions">
                    <button class="secondary-button" data-cx-close type="button">Cancelar</button>
                    <button class="primary-button" id="typeSubmit" type="submit">Guardar</button>
                </footer>
            </form>
        </dialog>

        <dialog class="cx-dialog" id="clientDialog" aria-labelledby="clientTitle">
            <form id="clientForm" novalidate>
                <header class="cx-dialog__heading">
                    <div><p class="eyebrow">CLIENTE</p><h2 id="clientTitle">Gestión del cliente</h2></div>
                    <button class="cx-dialog__close" data-cx-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="cx-form">
                    <fieldset class="cx-estados cx-form__wide">
                        <legend>Cómo lo sigue Comex</legend>
                        <label><input type="radio" name="gestion" value="servicio"> <span>Servicio</span></label>
                        <label><input type="radio" name="gestion" value="propia"> <span>Exportación propia</span></label>
                        <label><input type="radio" name="gestion" value="sin_seguimiento"> <span>Sin seguimiento</span></label>
                    </fieldset>
                    <p class="cx-form__nota cx-form__wide" id="clientNote"></p>
                    <label class="cx-form__wide"><span>Observación</span><textarea name="observacion" id="clientObservation" rows="2" maxlength="500"></textarea></label>
                    <p class="form-error cx-form__wide" id="clientMessage" role="alert"></p>
                </div>
                <footer class="cx-dialog__actions">
                    <button class="secondary-button" data-cx-close type="button">Cancelar</button>
                    <button class="primary-button" id="clientSubmit" type="submit">Guardar</button>
                </footer>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
