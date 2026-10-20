<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Tarifario</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-finance.css', 'resources/js/office-finance-tariffs.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">$</div>
                <p class="eyebrow">FoliOS · FINANZAS</p>
                <h1 id="officeAccessTitle">Precios por cliente, listos para valorizar.</h1>
                <p>El tarifario vive en Finanzas: definir un precio no modifica la operación.</p>
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
            <x-office.navigation domain="administracion" office="finanzas-tarifario" context="ADMINISTRACIÓN · FINANZAS" icon="$" />

            <section class="fin-workspace">
                <header class="fin-heading">
                    <div>
                        <p class="eyebrow">FINANZAS · PRECIOS POR CLIENTE</p>
                        <h1>Tarifario</h1>
                        <p>Precio de cada servicio por unidad de cobro, para un cliente o como tarifa general, con su vigencia. Un cliente con precio propio en un servicio deja de usar la tarifa general de ese servicio. No modifica la operación.</p>
                    </div>
                    <div class="fin-heading__actions">
                        <button class="primary-button" id="tariffNewButton" type="button" hidden>+ Nuevo precio</button>
                    </div>
                </header>

                <div class="fin-status" id="tariffStatus" role="status">Cargando…</div>

                <x-office.panel-switcher
                    id="finance-tariffs"
                    label="Vistas del tarifario"
                    default="prices"
                    :panels="[
                        'prices' => ['label' => 'Precios', 'icon' => '$', 'badge_id' => 'tariffPricesCount'],
                        'services' => ['label' => 'Conceptos de servicio', 'icon' => '≡', 'badge_id' => 'tariffServicesCount'],
                        'currencies' => ['label' => 'Monedas', 'icon' => '¤', 'badge_id' => 'tariffCurrenciesCount'],
                    ]"
                />

                <section class="panel fin-panel" id="finance-tariffs-panel-prices" data-office-panel-group="finance-tariffs" data-office-panel-id="prices" role="tabpanel" aria-labelledby="finance-tariffs-tab-prices">
                    <form class="fin-filters" id="tariffFilters">
                        <label><span>Cliente</span>
                            <select name="cliente" id="tariffClient">
                                <option value="">Todos</option>
                                <option value="general">Tarifa general</option>
                            </select>
                        </label>
                        <label><span>Servicio</span><select name="servicio" id="tariffService"><option value="">Todos los servicios</option></select></label>
                        <label><span>Estado</span>
                            <select name="estado">
                                <option value="actuales">Vigentes y programados</option>
                                <option value="vigente">Vigentes hoy</option>
                                <option value="futura">Programados</option>
                                <option value="vencida">Vencidos</option>
                                <option value="anulada">Anulados</option>
                                <option value="todas">Todos</option>
                            </select>
                        </label>
                    </form>

                    <section class="fin-effective" id="tariffEffective" aria-labelledby="tariffEffectiveTitle" hidden>
                        <div class="fin-panel__heading">
                            <div><p class="eyebrow">LO QUE RIGE HOY</p><h2 id="tariffEffectiveTitle">Tarifa efectiva</h2></div>
                            <small id="tariffEffectiveHelp"></small>
                        </div>
                        <ul class="fin-effective__list" id="tariffEffectiveList"></ul>
                    </section>

                    <div class="fin-table-scroll">
                        <table class="fin-table fin-table--tarifas">
                            <thead><tr><th>Cliente</th><th>Servicio</th><th class="fin-num">Precio y unidad de cobro</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="tariffPricesBody"></tbody>
                        </table>
                    </div>
                </section>

                <section class="panel fin-panel" id="finance-tariffs-panel-services" data-office-panel-group="finance-tariffs" data-office-panel-id="services" role="tabpanel" aria-labelledby="finance-tariffs-tab-services" hidden>
                    <div class="fin-panel__heading">
                        <div><p class="eyebrow">CONCEPTOS DE SERVICIO</p><h2>Qué se cobra y qué proceso lo respalda</h2></div>
                        <button class="secondary-button fin-heading__button" id="serviceNewButton" type="button" hidden>+ Nuevo concepto</button>
                    </div>
                    <p>Cree el concepto, asócielo a un proceso y asígnele un precio general o por cliente. Se aplica a todos los hechos que cumplan sus fechas y reglas.</p>
                    <div class="fin-table-scroll">
                        <table class="fin-table">
                            <thead><tr><th>Concepto</th><th>Proceso y aplicación</th><th>Vigencia y días</th><th>Acciones</th></tr></thead>
                            <tbody id="serviceRows"></tbody>
                        </table>
                    </div>
                    <p>Solo se ofrecen procesos con registros disponibles para Finanzas. Inspecciones SAG y servicios adicionales como adhesivos o sellado requieren incorporar su registro antes de automatizar el cobro.</p>
                </section>

                <section class="panel fin-panel" id="finance-tariffs-panel-currencies" data-office-panel-group="finance-tariffs" data-office-panel-id="currencies" role="tabpanel" aria-labelledby="finance-tariffs-tab-currencies" hidden>
                    <div class="fin-panel__heading">
                        <div><p class="eyebrow">MONEDAS</p><h2>En qué se expresan los precios</h2></div>
                        <button class="secondary-button fin-heading__button" id="tariffNewCurrency" type="button" hidden>+ Nueva moneda</button>
                    </div>
                    <div class="fin-presets" id="tariffCurrencyPresets" hidden></div>
                    <div class="fin-table-scroll">
                        <table class="fin-table">
                            <thead><tr><th>Código</th><th>Nombre</th><th>Símbolo</th><th class="fin-num">Decimales</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="tariffCurrenciesBody"></tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>

        <dialog class="fin-history fin-dialog" id="tariffDialog" aria-labelledby="tariffDialogTitle">
            <form id="tariffForm" novalidate>
                <header class="fin-history__heading">
                    <div><p class="eyebrow" id="tariffDialogEyebrow">PRECIO</p><h2 id="tariffDialogTitle">Nuevo precio</h2></div>
                    <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Cliente</span><select name="cliente_id" id="tariffFormClient"></select></label>
                    <label><span>Concepto de servicio</span><select name="servicio" id="tariffFormService" required></select></label>
                    <label><span>Unidad de cobro</span><select name="unidad" id="tariffFormUnit" required></select></label>
                    <p class="fin-form__preview fin-form__wide" id="tariffFormServiceRules"></p>
                    <button class="fin-boton fin-form__wide" id="tariffFormCreateService" type="button">+ Crear un concepto de servicio</button>
                    <label><span>Moneda</span><select name="moneda" id="tariffFormCurrency" required></select></label>
                    <label><span>Precio unitario</span><input name="precio" id="tariffFormPrice" inputmode="decimal" autocomplete="off" placeholder="Por ejemplo, 0,35" required></label>
                    <label><span>Vigente desde</span><input name="vigente_desde" id="tariffFormFrom" type="date" required></label>
                    <label><span>Vigente hasta <small>(opcional)</small></span><input name="vigente_hasta" id="tariffFormTo" type="date"></label>
                    <label class="fin-form__wide"><span>Nota <small>(opcional)</small></span><textarea name="nota" id="tariffFormNote" rows="2" maxlength="500"></textarea></label>
                    <label class="fin-form__check fin-form__wide" id="tariffFormReplaceRow">
                        <input type="checkbox" name="cerrar_anterior" id="tariffFormReplace" checked>
                        <span>Si este servicio ya tiene un precio vigente en esta unidad, terminarlo el día anterior.</span>
                    </label>
                    <p class="fin-form__preview fin-form__wide" id="tariffFormPreview" aria-live="polite"></p>
                    <p class="form-error fin-form__wide" id="tariffFormMessage" role="alert"></p>
                </div>
                <footer class="fin-history__actions">
                    <button class="secondary-button" data-fin-close type="button">Cancelar</button>
                    <button class="primary-button" id="tariffFormSubmit" type="submit">Guardar precio</button>
                </footer>
            </form>
        </dialog>

        <dialog class="fin-history fin-dialog" id="tariffAnnulDialog" aria-labelledby="tariffAnnulTitle">
            <form id="tariffAnnulForm" novalidate>
                <header class="fin-history__heading">
                    <div><p class="eyebrow">ANULAR PRECIO</p><h2 id="tariffAnnulTitle">¿Anular este precio?</h2></div>
                    <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="fin-form">
                    <p class="fin-form__wide" id="tariffAnnulSummary"></p>
                    <label class="fin-form__wide"><span>Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required placeholder="Por ejemplo, se cargó al cliente equivocado."></textarea></label>
                    <p class="fin-form__preview fin-form__wide">Un precio anulado deja de cobrarse y no se puede volver a editar; su historial se conserva.</p>
                    <p class="form-error fin-form__wide" id="tariffAnnulMessage" role="alert"></p>
                </div>
                <footer class="fin-history__actions">
                    <button class="secondary-button" data-fin-close type="button">Cancelar</button>
                    <button class="danger-button" type="submit">Anular precio</button>
                </footer>
            </form>
        </dialog>

        <dialog class="fin-history" id="tariffHistory" aria-labelledby="tariffHistoryTitle">
            <header class="fin-history__heading">
                <div><p class="eyebrow" id="tariffHistoryEyebrow">HISTORIAL</p><h2 id="tariffHistoryTitle">Historial del precio</h2></div>
                <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
            </header>
            <ol class="fin-history__list" id="tariffHistoryList"></ol>
            <footer class="fin-history__actions"><button class="secondary-button" data-fin-close type="button">Cerrar</button></footer>
        </dialog>

        <dialog class="fin-history fin-dialog" id="currencyDialog" aria-labelledby="currencyDialogTitle">
            <form id="currencyForm" novalidate>
                <header class="fin-history__heading">
                    <div><p class="eyebrow">MONEDA</p><h2 id="currencyDialogTitle">Nueva moneda</h2></div>
                    <button class="fin-history__close" data-fin-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="fin-form">
                    <label><span>Código</span><input name="codigo" maxlength="10" autocomplete="off" placeholder="CLP, UF, USD…" required></label>
                    <label><span>Símbolo</span><input name="simbolo" maxlength="10" autocomplete="off" required></label>
                    <label class="fin-form__wide"><span>Nombre</span><input name="nombre" maxlength="60" autocomplete="off" required></label>
                    <label><span>Decimales de los montos</span><input name="decimales" type="number" min="0" max="6" step="1" required></label>
                    <label class="fin-form__check" id="currencyActiveRow"><input type="checkbox" name="activa"> <span>Activa para precios nuevos</span></label>
                    <p class="fin-form__preview fin-form__wide">Los decimales se usan al redondear montos. El precio unitario admite hasta 6 decimales en cualquier moneda.</p>
                    <p class="form-error fin-form__wide" id="currencyFormMessage" role="alert"></p>
                </div>
                <footer class="fin-history__actions">
                    <button class="secondary-button" data-fin-close type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Guardar moneda</button>
                </footer>
            </form>
        </dialog>

        <dialog class="fin-history fin-dialog" id="serviceDialog" aria-labelledby="serviceDialogTitle">
            <form id="serviceForm">
                <header class="fin-history__heading">
                    <div><p class="eyebrow">CONCEPTO DE SERVICIO</p><h2 id="serviceDialogTitle">Nuevo concepto</h2></div>
                    <button class="fin-history__close" data-service-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Nombre que aparecerá en la prefactura</span><input name="nombre" maxlength="120" required placeholder="Por ejemplo, Frío normal"></label>
                    <label class="fin-form__wide"><span>Proceso que respalda el servicio</span><select name="fuente" required></select></label>
                    <p class="fin-form__preview fin-form__wide" id="serviceProcessHelp"></p>
                    <label class="fin-form__wide"><span>Cómo se aplica</span><select name="aplicacion" required><option value="">Seleccione</option><option value="reemplaza_base">Reemplaza al concepto base en los hechos que correspondan</option><option value="adicional">Suma un cobro adicional al concepto base</option></select></label>
                    <label><span>Vigente desde</span><input name="vigente_desde" type="date" required></label>
                    <label><span>Vigente hasta <small>(opcional)</small></span><input name="vigente_hasta" type="date"></label>
                    <fieldset class="fin-form__wide fin-service-days" id="serviceDays" hidden>
                        <legend>Días de esta estadía del folio</legend>
                        <label><span>Desde el día</span><input name="dia_desde" type="number" min="1" max="65535" step="1" value="1" required></label>
                        <label><span>Hasta el día <small>(vacío: sin límite)</small></span><input name="dia_hasta" type="number" min="1" max="65535" step="1"></label>
                        <p>El ingreso es el día 1, en hora de planta. Se cuenta desde el inicio de esta custodia; un folio nuevo de repaletizaje comienza otra. No representa la antigüedad de la fruta de sus orígenes.</p>
                    </fieldset>
                    <label class="fin-form__wide"><span>Descripción <small>(opcional)</small></span><textarea name="descripcion" maxlength="500" rows="2"></textarea></label>
                    <p class="fin-form__preview fin-form__wide">Sin precio, los hechos del concepto quedan pendientes de valorizar. La unidad de cobro se elige al asignar el precio, entre las cantidades registradas por el proceso. El proceso y las reglas del concepto se conservan: para cambiarlos se finaliza y se crea otro concepto.</p>
                    <p class="form-error fin-form__wide" id="serviceFormError" role="alert"></p>
                </div>
                <footer class="fin-history__actions"><button class="secondary-button" data-service-close type="button">Cancelar</button><button class="primary-button" type="submit">Guardar concepto y asignar precio</button></footer>
            </form>
        </dialog>

        <dialog class="fin-history fin-dialog" id="serviceEndDialog" aria-labelledby="serviceEndTitle">
            <form id="serviceEndForm">
                <header class="fin-history__heading"><div><p class="eyebrow">FINALIZAR CONCEPTO</p><h2 id="serviceEndTitle"></h2></div><button class="fin-history__close" data-service-close type="button" aria-label="Cerrar">×</button></header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Último día de aplicación</span><input name="vigente_hasta" type="date" required></label>
                    <label class="fin-form__wide"><span>Motivo</span><textarea name="motivo" minlength="5" maxlength="500" required rows="2"></textarea></label>
                    <p class="fin-form__preview fin-form__wide">Después de esa fecha deja de aplicarse. Sus precios, hechos y cierres anteriores se conservan.</p>
                    <p class="form-error fin-form__wide" id="serviceEndError" role="alert"></p>
                </div>
                <footer class="fin-history__actions"><button class="secondary-button" data-service-close type="button">Cancelar</button><button class="primary-button" type="submit">Finalizar concepto</button></footer>
            </form>
        </dialog>

        <dialog class="fin-history" id="serviceHistory" aria-labelledby="serviceHistoryTitle">
            <header class="fin-history__heading"><div><p class="eyebrow">HISTORIAL DEL CONCEPTO</p><h2 id="serviceHistoryTitle"></h2></div><button class="fin-history__close" data-service-close type="button" aria-label="Cerrar">×</button></header>
            <ol class="fin-history__list" id="serviceHistoryList"></ol>
            <footer class="fin-history__actions"><button class="secondary-button" data-service-close type="button">Cerrar</button></footer>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
