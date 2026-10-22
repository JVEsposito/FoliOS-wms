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
    <body data-finance-zone="{{ config('app.operational_timezone') }}">
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
                        'charges' => ['label' => 'Cargos acordados', 'icon' => '+'],
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

                <section class="panel fin-panel" id="finance-tariffs-panel-charges" data-office-panel-group="finance-tariffs" data-office-panel-id="charges" role="tabpanel" aria-labelledby="finance-tariffs-tab-charges" hidden>
                    <div class="fin-panel__heading"><div><p class="eyebrow">CARGOS ACORDADOS</p><h2>Lo que se cobra por acuerdo o declaración</h2></div><div class="fin-acciones"><button class="secondary-button" id="chargeNew" type="button" hidden>+ Nuevo cargo</button><button class="secondary-button" id="chargeFromRrhh" type="button" hidden>Desde dotación RRHH</button></div></div>
                    <p>Puede cobrar cualquier concepto operativo por ejecución, compromiso o disponibilidad. El estado real del servicio se conserva; un servicio cancelado puede seguir siendo cobrable según su acuerdo.</p>
                    <label><span>Mes del cargo</span><input id="chargeMonth" type="month"></label>
                    <p id="chargeStatus" role="status"></p>
                    <div class="fin-table-scroll"><table class="fin-table"><thead><tr><th>Cliente y concepto</th><th>Cantidad y fecha</th><th>Acuerdo y ejecución</th><th>Estado y acciones</th></tr></thead><tbody id="chargeRows"></tbody></table></div>
                    <div class="fin-acciones"><button class="fin-boton" id="chargePrev" type="button">Anterior</button><span id="chargePage"></span><button class="fin-boton" id="chargeNext" type="button">Siguiente</button></div>
                </section>

                <section class="panel fin-panel" id="finance-tariffs-panel-services" data-office-panel-group="finance-tariffs" data-office-panel-id="services" role="tabpanel" aria-labelledby="finance-tariffs-tab-services" hidden>
                    <div class="fin-panel__heading">
                        <div><p class="eyebrow">CONCEPTOS DE SERVICIO</p><h2>Qué se cobra y qué proceso lo respalda</h2></div>
                        <button class="secondary-button fin-heading__button" id="serviceNewButton" type="button" hidden>+ Nuevo concepto</button>
                    </div>
                    <p>Cree conceptos automáticos o acordados/manuales y asígneles precios generales o por cliente. La base económica del cobro se conserva separada del estado de ejecución.</p>
                    <div class="fin-table-scroll">
                        <table class="fin-table">
                            <thead><tr><th>Concepto</th><th>Proceso y aplicación</th><th>Vigencia y días</th><th>Acciones</th></tr></thead>
                            <tbody id="serviceRows"></tbody>
                        </table>
                    </div>
                    <p>Todo concepto operativo puede cobrarse por acuerdo, compromiso o disponibilidad, aunque no se ejecute. Use la modalidad acordada/manual para registrar sus cantidades y respaldo; la captura automática usa los procesos realizados.</p>
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
                    <p class="fin-form__preview fin-form__wide">Un precio negativo genera un abono. El monto resulta de multiplicar la cantidad por el precio, conservando ambos signos.</p>
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

        <dialog class="fin-history fin-dialog" id="dotacionChargeDialog" aria-labelledby="dotacionChargeTitle">
            <form id="dotacionChargeForm"><header class="fin-history__heading"><h2 id="dotacionChargeTitle">Cargo desde dotación RRHH</h2><button type="button" class="fin-history__close" data-dotacion-close aria-label="Cerrar">×</button></header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Concepto acordado/manual</span><select name="servicio" required></select></label>
                    <label><span>Mes de las asignaciones</span><input id="dotacionChargeMonth" type="month" required></label>
                    <p class="fin-form__preview fin-form__wide">La programación permite cobrar compromisos o disponibilidad aunque no haya asistencia. La cantidad y el cliente se leen desde RRHH. Se muestra la dotación sin nombres ni documentos personales.</p>
                    <div class="fin-table-scroll fin-form__wide"><table class="fin-table"><thead><tr><th>Asignación</th><th>Programado</th><th>Asistido</th><th>Elegir</th></tr></thead><tbody id="dotacionChargeRows"></tbody></table></div>
                    <div class="fin-acciones fin-form__wide"><button type="button" class="fin-boton" id="dotacionChargePrev">Anterior</button><span id="dotacionChargePage"></span><button type="button" class="fin-boton" id="dotacionChargeNext">Siguiente</button></div>
                    <label><span>Cantidad que se cobrará</span><select name="medida"><option value="horas_programadas">Horas persona programadas</option><option value="horas_asistidas">Horas persona asistidas</option><option value="jornadas_programadas">Jornadas persona programadas</option><option value="jornadas_asistidas">Jornadas persona asistidas</option></select></label>
                    <p class="fin-form__preview fin-form__wide" id="dotacionChargeQuantity"></p>
                    <p class="fin-form__preview fin-form__wide" id="dotacionChargeUnit"></p>
                    <label class="fin-form__wide"><input type="checkbox" name="confirmar_unidad" required> Confirmo que la unidad del concepto y su tarifa corresponden a esta medida.</label>
                    <label class="fin-form__wide" id="dotacionChargeReplacement" hidden><input type="checkbox" name="confirmar_reemplazo"> Confirmo crear una nueva revisión que reemplaza el cargo anulado. Su historial se conserva.</label>
                    <label><span>Fecha del cobro</span><input name="fecha" type="date" required></label>
                    <label><span>Base económica</span><select name="base_cobro"><option value="acuerdo">Acuerdo comercial</option><option value="disponibilidad">Disponibilidad o reserva</option><option value="compromiso">Compromiso contratado</option><option value="ejecucion">Prestación realizada</option></select></label>
                    <label class="fin-form__wide"><span>Contrato, orden o respaldo del cobro</span><textarea name="respaldo" minlength="5" maxlength="500" rows="3" required></textarea></label>
                    <p class="fin-form__preview fin-form__wide" id="dotacionChargeNotice"></p><p class="form-error fin-form__wide" id="dotacionChargeError" role="alert"></p>
                </div><footer class="fin-history__actions"><button type="button" class="secondary-button" data-dotacion-close>Cancelar</button><button type="submit" class="primary-button" id="dotacionChargeSubmit" disabled>Guardar borrador</button></footer>
            </form>
        </dialog>
        <dialog class="fin-history fin-dialog" id="chargeDialog" aria-labelledby="chargeTitle">
            <form id="chargeForm"><header class="fin-history__heading"><div><p class="eyebrow">CARGO ACORDADO</p><h2 id="chargeTitle">Nuevo cargo</h2></div><button class="fin-history__close" data-charge-close type="button" aria-label="Cerrar">×</button></header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Concepto acordado/manual</span><select name="servicio" required></select></label>
                    <label class="fin-form__wide"><span>Cliente</span><select name="cliente_id" required></select></label>
                    <label><span>Cantidad</span><input name="cantidad" inputmode="decimal" required></label><label><span>Fecha del cobro</span><input name="fecha" type="date" required></label>
                    <label id="chargePeriodField" hidden><span>Hasta el día (incluido)</span><input name="fecha_hasta" type="date" disabled></label>
                    <p class="fin-form__preview fin-form__wide">Puede registrar cantidades negativas para abonos o ajustes, con su respaldo. El resultado conserva el signo de cantidad × precio.</p>
                    <p class="fin-form__preview fin-form__wide" id="chargeUnit"></p>
                    <label><span>Base económica del cobro</span><select name="base_cobro" required><option value="acuerdo">Acuerdo comercial</option><option value="compromiso">Compromiso contratado</option><option value="disponibilidad">Disponibilidad o reserva</option><option value="ejecucion">Prestación realizada</option></select></label>
                    <label><span>Ejecución al registrar el cargo</span><select name="estado_ejecucion" required><option value="no_aplica">No aplica</option><option value="programado">Programado</option><option value="realizado">Realizado</option><option value="no_realizado">No realizado</option><option value="cancelado">Cancelado</option></select></label>
                    <label class="fin-form__wide"><span>Respaldo del cobro (acuerdo, orden, contrato o explicación)</span><textarea name="respaldo" minlength="5" maxlength="500" required rows="3"></textarea></label>
                    <p class="fin-form__preview fin-form__wide">Se guarda como borrador. Al aprobarlo pasa a la prefactura con el precio del concepto, sin registrar una ejecución ficticia. La anulación económica se decide por separado.</p>
                    <p id="chargeError" class="form-error fin-form__wide" role="alert"></p>
                </div><footer class="fin-history__actions"><button class="secondary-button" data-charge-close type="button">Cancelar</button><button class="primary-button" type="submit">Guardar borrador</button></footer>
            </form>
        </dialog>
        <dialog class="fin-history" id="chargeHistory" aria-labelledby="chargeHistoryTitle"><header class="fin-history__heading"><h2 id="chargeHistoryTitle">Historial del cargo</h2><button class="fin-history__close" data-charge-close type="button" aria-label="Cerrar">×</button></header><div class="fin-form" id="chargeHistoryBody"></div></dialog>
        <dialog class="fin-history fin-dialog" id="chargePeriodEnd" aria-labelledby="chargePeriodEndTitle"><form id="chargePeriodEndForm"><header class="fin-history__heading"><h2 id="chargePeriodEndTitle">Terminar servicio por período</h2><button type="button" class="fin-history__close" data-charge-close aria-label="Cerrar">×</button></header><div class="fin-form"><label><span>Último día cobrable (incluido)</span><input name="fecha_hasta" type="date" required></label><label class="fin-form__wide"><span>Motivo del término anticipado</span><textarea name="motivo" minlength="5" maxlength="500" required></textarea></label><p class="fin-form__preview fin-form__wide">Conserva los días anteriores. Un cambio de un período cerrado se presenta como diferencia.</p><p id="chargePeriodEndError" class="form-error fin-form__wide" role="alert"></p></div><footer class="fin-history__actions"><button type="button" class="secondary-button" data-charge-close>Cancelar</button><button type="submit" class="primary-button">Guardar término</button></footer></form></dialog>

        <dialog class="fin-history fin-dialog" id="serviceDialog" aria-labelledby="serviceDialogTitle">
            <form id="serviceForm">
                <header class="fin-history__heading">
                    <div><p class="eyebrow">CONCEPTO DE SERVICIO</p><h2 id="serviceDialogTitle">Nuevo concepto</h2></div>
                    <button class="fin-history__close" data-service-close type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="fin-form">
                    <label class="fin-form__wide"><span>Nombre que aparecerá en la prefactura</span><input name="nombre" maxlength="120" required placeholder="Por ejemplo, Frío normal"></label>
                    <label class="fin-form__wide"><span>Área del servicio</span><input name="area" maxlength="60" value="general" list="serviceAreas" required placeholder="materiales, envases, packing, RRHH…"><datalist id="serviceAreas"><option value="materiales"><option value="envases"><option value="packing"><option value="recepcion"><option value="frigorifico"><option value="logistica"><option value="rrhh"><option value="comex"><option value="servicios"><option value="general"></datalist></label>
                    <label class="fin-form__wide"><span>Cómo se registran las cantidades</span><select name="fuente" required></select></label>
                    <p class="fin-form__preview fin-form__wide" id="serviceProcessHelp"></p>
                    <fieldset class="fin-form__wide fin-service-days" id="serviceSourceFilters" hidden><legend>Aplicar solo a estas condiciones</legend><p>Filtre ítem, receta, unidad u otras dimensiones del proceso. Para cobrar cantidades de materiales seleccione su unidad de medida.</p><div data-source-filter-rows></div><button type="button" class="fin-boton" data-source-filter-add>+ Agregar filtro</button></fieldset>
                    <fieldset class="fin-form__wide fin-service-days" id="serviceManualFields" hidden>
                        <legend>Unidad del concepto</legend>
                        <label><span>Unidad de cobro</span><input name="unidad_libre" maxlength="60" placeholder="hora persona, jornada, bin, documento…"></label>
                        <label><span>Proceso asociado (opcional)</span><input name="proceso_referencia" maxlength="120" placeholder="RRHH, inspección, embalaje…"></label>
                        <p>Nombre y unidad libres. El cobro se aprueba con su cantidad y acuerdo, incluso si el proceso no se ejecuta.</p>
                    </fieldset>
                    <fieldset class="fin-form__wide fin-service-days" id="serviceRule" hidden>
                        <legend>Cálculo de la cantidad</legend>
                        <p>Combine medidas de igual unidad. El primer término fija la fecha del servicio. Los clientes y temporadas se mantienen separados. Los procesos originales conservan sus propios precios: revise cuáles desea cobrar.</p>
                        <div data-rule-terms></div>
                        <button type="button" class="fin-boton" data-rule-add>+ Agregar término</button>
                        <fieldset><legend>Agrupar por referencias comunes (hasta cuatro)</legend><div data-rule-groups></div></fieldset>
                        <label class="fin-form__check"><input type="checkbox" data-rule-complete checked><span>Exigir aportes de todos los términos para cobrar</span></label>
                        <button type="button" class="fin-boton" id="serviceRulePreviewButton">Simular cantidad</button>
                        <div data-rule-preview role="status" aria-live="polite"></div>
                    </fieldset>
                    <label class="fin-form__wide"><span>Cómo se aplica</span><select name="aplicacion" required><option value="">Seleccione</option><option value="reemplaza_base">Reemplaza al concepto base en los hechos que correspondan</option><option value="adicional">Suma un cobro adicional al concepto base</option></select></label>
                    <label><span>Vigente desde</span><input name="vigente_desde" type="date" required></label>
                    <label><span>Vigente hasta <small>(opcional)</small></span><input name="vigente_hasta" type="date"></label>
                    <fieldset class="fin-form__wide fin-service-days" id="serviceDays" hidden>
                        <legend>Días desde el inicio del servicio</legend>
                        <label><span>Desde el día</span><input name="dia_desde" type="number" min="1" max="65535" step="1" value="1" required></label>
                        <label><span>Hasta el día <small>(vacío: sin límite)</small></span><input name="dia_hasta" type="number" min="1" max="65535" step="1"></label>
                        <p>El inicio del servicio es el día 1, en hora de planta. En frigorífico se cuenta desde el inicio de la custodia; un folio nuevo de repaletizaje comienza otra. Los acuerdos por período aplican estos rangos a su fecha inicial.</p>
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
