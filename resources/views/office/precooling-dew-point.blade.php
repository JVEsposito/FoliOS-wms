<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#102f43">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Punto de rocío</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-prefrio.css', 'resources/css/office-prefrio-dew-point.css', 'resources/js/office-prefrio-dew-point.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand prefrio-access-brand">
                <div class="office-logo" aria-hidden="true">❄</div>
                <p class="eyebrow">FoliOS · PREFRÍO</p>
                <h1 id="officeAccessTitle">Chequea cada día el riesgo de condensación de la fruta.</h1>
                <p>Temperatura y humedad del aire, punto de rocío y margen frente a la superficie de la fruta en sellado, paletizado y prefrío.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Prefrío</h2><p>Disponible para administración, supervisión de frío, operación de prefrío y consulta.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a Prefrío <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="punto-rocio" context="FRIGORÍFICO · PT" icon="❄" />

            <section class="prefrio-workspace dew-workspace">
                <header class="prefrio-heading prefrio-command dew-command">
                    <div class="prefrio-heading__title"><p class="eyebrow">FRIGORÍFICO · PREFRÍO · CONDENSACIÓN</p><h1>Punto de rocío</h1><p>Chequeos diarios del aire y de la fruta. Si la superficie queda bajo el punto de rocío, puede condensar agua antes de sellar o al salir del túnel.</p></div>
                    <div class="dew-day" aria-label="Día operacional">
                        <span>DÍA OPERACIONAL</span>
                        <div>
                            <button class="secondary-button" id="previousDayButton" type="button" aria-label="Día anterior">‹</button>
                            <input id="dewDateInput" type="date" aria-label="Fecha">
                            <button class="secondary-button" id="nextDayButton" type="button" aria-label="Día siguiente">›</button>
                            <button class="secondary-button" id="todayButton" type="button">Hoy</button>
                        </div>
                    </div>
                    <div class="prefrio-heading__actions">
                        <button class="primary-button is-hidden" id="newReadingButton" type="button">+ Registrar medición</button>
                        <button class="secondary-button is-hidden" id="roomsButton" type="button">Salas</button>
                        <button class="secondary-button is-hidden" id="marginsButton" type="button">Márgenes</button>
                        <button class="secondary-button" id="reloadDewPointButton" type="button">↻ Actualizar</button>
                    </div>
                </header>
                <p class="dew-message" id="dewPointMessage" role="status" aria-live="polite"></p>

                <div class="prefrio-metrics dew-metrics">
                    <article data-tone="primary"><span>REGISTROS</span><strong id="readingCount">0</strong><small id="surfaceCount">0 con T° superficial</small></article>
                    <article data-tone="info"><span>ROCÍO MÁXIMO</span><strong id="maxDewPoint">—</strong><small>Aire más húmedo del día</small></article>
                    <article data-tone="neutral"><span>MARGEN MÍNIMO</span><strong id="minMargin">—</strong><small id="minMarginHelp">Superficie − rocío de cada medición</small></article>
                    <article data-tone="warning"><span>ALERTAS</span><strong id="alertCount">0</strong><small id="pendingCount">0 sin acción</small></article>
                    <article data-tone="danger"><span>GOTAS VISIBLES</span><strong id="dropCount">0</strong><small>Condensación observada</small></article>
                    <article data-tone="warning"><span>FUERA DE PLAN</span><strong id="outOfPlanCount">0</strong><small>Rocío sobre el plan de la etapa</small></article>
                </div>

                <section class="panel dew-panel dew-plan-panel" aria-labelledby="planTitle">
                    <div class="prefrio-panel__heading" data-estiba-contrast="navy"><div><p class="eyebrow">DEL PACKING AL CONTENEDOR</p><h2 id="planTitle">Plan de cadena de frío</h2></div><button class="secondary-button is-hidden" id="editPlanButton" type="button">Editar plan</button></div>
                    <ol class="dew-chain" id="chainStrip" aria-label="Etapas planificadas y su medición más reciente del día"></ol>
                    <p class="dew-note" id="chainNote">Rocío máximo = T° más baja de la fruta en ese aire − margen de la etapa. En sellado manda además la fruta más fría de las etapas siguientes, porque ese aire queda en la bolsa.</p>
                </section>

                <div class="dew-grid">
                    <section class="panel dew-panel dew-chart-panel">
                        <div class="prefrio-panel__heading" data-estiba-contrast="navy"><div><p class="eyebrow">AVANCE DEL DÍA</p><h2>Temperaturas y punto de rocío</h2></div><span id="chartSummary">Sin mediciones</span></div>
                        <div class="dew-chart"><canvas id="dewChart" aria-label="Evolución de T° ambiente, punto de rocío y T° superficial del día" role="img"></canvas><p class="dew-empty is-hidden" id="chartEmpty">Aún no hay mediciones este día.</p></div>
                        <p class="dew-note">Si la línea de rocío queda sobre la de superficie, esa superficie puede condensar agua.</p>
                    </section>
                    <section class="panel dew-panel dew-history-panel">
                        <div class="prefrio-panel__heading" data-estiba-contrast="navy"><div><p class="eyebrow">ÚLTIMOS 14 DÍAS</p><h2>Chequeos y alertas</h2></div></div>
                        <div class="dew-history" id="historyStrip"></div>
                    </section>
                </div>

                <section class="panel dew-panel">
                    <div class="prefrio-panel__heading" data-estiba-contrast="navy"><div><p class="eyebrow">REGISTRO</p><h2>Mediciones del día</h2></div></div>
                    <div class="dew-filters" id="stageFilters" role="group" aria-label="Filtrar por etapa"></div>
                    <div class="dew-table-scroll"><table class="dew-table"><thead><tr><th>Hora</th><th>Etapa y punto</th><th>Aire</th><th>T° sup.</th><th>Rocío</th><th>Margen</th><th>Estado</th><th>Gotas</th><th>Registró</th><th aria-label="Acciones"></th></tr></thead><tbody id="readingsBody"></tbody></table></div>
                </section>

                <p class="dew-footnote">El punto de rocío describe el aire de la sala (aproximación de Magnus). Dentro de bolsas selladas, el agua superficial y el vapor de la fruta pueden condensar aunque la sala cumpla. El margen mínimo de cada etapa (por defecto <span id="minimumMarginLabel">2</span> °C) es un criterio preventivo interno, no una norma; en cámaras con humedad alta el margen natural es menor.</p>
            </section>

            <dialog class="prefrio-dialog dew-dialog" id="readingDialog" aria-labelledby="readingDialogTitle">
                <form method="dialog" class="prefrio-dialog__shell" id="readingForm" novalidate>
                    <div class="prefrio-dialog__heading"><div><p class="eyebrow">CHEQUEO DE CONDENSACIÓN</p><h2 id="readingDialogTitle">Registrar medición</h2><p>Mide T° y humedad del aire. Para evaluar la fruta, ingresa su T° superficial (o la de la película), no la de pulpa.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                    <div class="prefrio-form-grid dew-form-grid">
                        <label><span>Etapa *</span><select name="etapa" required></select></label>
                        <label><span>Punto de control *</span><select name="punto" required></select></label>
                        <label><span>Fecha y hora *</span><input name="medido_at" type="datetime-local" required></label>
                        <label><span>Lote, pallet o referencia</span><input name="referencia" maxlength="80" placeholder="Opcional"></label>
                        <label class="dew-number"><span>T° ambiente *</span><div><input name="temperatura_ambiente_c" type="number" step="0.1" min="-30" max="60" inputmode="decimal" required><b>°C</b></div></label>
                        <label class="dew-number"><span>Humedad relativa *</span><div><input name="humedad_relativa" type="number" step="0.1" min="0.1" max="100" inputmode="decimal" required><b>%</b></div></label>
                        <label class="dew-number"><span>T° superficial fruta</span><div><input name="temperatura_superficie_c" type="number" step="0.1" min="-30" max="60" inputmode="decimal"><b>°C</b></div></label>
                        <fieldset class="dew-drops"><legend>¿Gotas visibles? *</legend><label><input type="radio" name="gotas_visibles" value="no"><span>No</span></label><label><input type="radio" name="gotas_visibles" value="si"><span>Sí</span></label><label><input type="radio" name="gotas_visibles" value="pendiente" checked><span>Pendiente</span></label></fieldset>
                        <fieldset class="prefrio-field-wide dew-links">
                            <legend>Vincular con mediciones anteriores <small>(opcional)</small></legend>
                            <label><span>Aire del envase · sellado de referencia</span><select name="sellado_referencia_id"></select><small>Compara la T° superficial de esta medición con el rocío del aire que quedó dentro de la bolsa al sellar.</small></label>
                            <label><span>Fruta que llega · medición de origen</span><select name="origen_referencia_id"></select><small>Compara la T° superficial con que llega la fruta con el rocío del aire de este punto.</small></label>
                        </fieldset>
                        <label class="prefrio-field-wide"><span>Observación</span><textarea name="observacion" maxlength="1000" rows="2"></textarea></label>
                    </div>
                    <output class="dew-result" id="readingResult" data-tone="neutral" aria-live="polite">Ingresa T° y humedad para calcular el punto de rocío.</output>
                    <p class="form-error" id="readingError" role="alert"></p>
                    <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" id="saveReadingButton" value="default" type="submit">Guardar medición</button></div>
                </form>
            </dialog>

            <dialog class="prefrio-dialog prefrio-dialog--compact dew-dialog" id="noteDialog" aria-labelledby="noteDialogTitle">
                <form method="dialog" class="prefrio-dialog__shell" id="noteForm" novalidate>
                    <div class="prefrio-dialog__heading"><div><p class="eyebrow" id="noteEyebrow">ALERTA</p><h2 id="noteDialogTitle">Registrar acción correctiva</h2><p id="noteDescription"></p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                    <label class="prefrio-field-wide dew-note-field"><span id="noteLabel">Acción correctiva *</span><textarea name="texto" maxlength="1000" rows="3" required></textarea></label>
                    <p class="form-error" id="noteError" role="alert"></p>
                    <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" id="saveNoteButton" value="default" type="submit">Guardar</button></div>
                </form>
            </dialog>

            <dialog class="prefrio-dialog prefrio-dialog--compact dew-dialog" id="roomsDialog" aria-labelledby="roomsDialogTitle">
                <form method="dialog" class="prefrio-dialog__shell" id="roomForm" novalidate>
                    <div class="prefrio-dialog__heading"><div><p class="eyebrow">PUNTOS DE CONTROL</p><h2 id="roomsDialogTitle">Salas</h2><p>Zonas sin registro propio, como sellado o paletizado. Los túneles de prefrío y las cámaras activas aparecen solos.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                    <div class="dew-room-list" id="roomList"></div>
                    <div class="dew-room-new"><label><span>Nueva sala</span><input name="nombre" maxlength="80" placeholder="Ej.: Sala de sellado"></label><button class="primary-button" id="addRoomButton" value="default" type="submit">Agregar</button></div>
                    <p class="form-error" id="roomError" role="alert"></p>
                </form>
            </dialog>

            <dialog class="prefrio-dialog dew-dialog" id="planDialog" aria-labelledby="planDialogTitle">
                <form method="dialog" class="prefrio-dialog__shell" id="planForm" novalidate>
                    <div class="prefrio-dialog__heading"><div><p class="eyebrow">CADENA DE FRÍO</p><h2 id="planDialogTitle">Editar plan</h2><p>Por etapa, la T° del aire planificada y la T° superficial más baja que tendrá la fruta en ese aire. FoliOS calcula el rocío y la humedad máximos con el margen de cada etapa. Deja la fruta vacía para sacar una etapa del plan; el margen de cada etapa se ajusta en «Márgenes».</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                    <div class="dew-table-scroll"><table class="dew-table dew-plan-table"><thead><tr><th>Etapa</th><th>T° aire</th><th>T° fruta mínima</th><th>Margen</th><th>Rocío máx.</th><th>HR máx.</th></tr></thead><tbody id="planRows"></tbody></table></div>
                    <p class="dew-note">La propuesta completa solo las etapas frías vacías con una cámara a −1 °C y pulpa entre −0,5 y 0,5 °C: salida de prefrío y traslado con la fruta a −1 °C, almacenamiento, despacho y carga a −0,5 °C. Packing, sellado, paletizado e ingreso a prefrío dependen de tu proceso.</p>
                    <p class="form-error" id="planError" role="alert"></p>
                    <div class="dialog-actions"><button class="secondary-button" id="suggestPlanButton" type="button">Completar con la propuesta</button><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" id="savePlanButton" value="default" type="submit">Guardar plan</button></div>
                </form>
            </dialog>

            <dialog class="prefrio-dialog prefrio-dialog--compact dew-dialog" id="marginsDialog" aria-labelledby="marginsDialogTitle">
                <form method="dialog" class="prefrio-dialog__shell" id="marginsForm" novalidate>
                    <div class="prefrio-dialog__heading"><div><p class="eyebrow">CRITERIO PREVENTIVO</p><h2 id="marginsDialogTitle">Margen mínimo por etapa</h2><p>Diferencia mínima entre la T° superficial y el punto de rocío. Bajo ella la medición queda como «Margen bajo». Rige para las mediciones nuevas y para sus comparaciones vinculadas.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                    <div class="dew-margin-list" id="marginList"></div>
                    <p class="form-error" id="marginError" role="alert"></p>
                    <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" id="saveMarginsButton" value="default" type="submit">Guardar márgenes</button></div>
                </form>
            </dialog>

            <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
            <div class="toast-region" id="officeToasts" aria-live="polite"></div>
        </main>
    </body>
</html>
