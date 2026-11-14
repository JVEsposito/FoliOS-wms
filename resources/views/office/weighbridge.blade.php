<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Romana</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-weighbridge.css', 'resources/js/office-weighbridge.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand weighbridge-access-brand">
                <div class="office-logo" aria-hidden="true">⚖</div>
                <p class="eyebrow">FoliOS · ROMANA</p>
                <h1 id="officeAccessTitle">Registra exactamente lo que ingresó al frigorífico.</h1>
                <p>El pesaje inicial es el contrato operacional con el cliente: guía, transporte, envases y kilos quedan trazados desde el primer minuto.</p>
                <div class="feature-row"><span>Pesaje en dos tiempos</span><span>Correlativo inviolable</span><span>Registro de pesaje RPR-01</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Romana</h2><p>Disponible para operador de romana, supervisión, administración y consulta.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a Romana <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            
            <x-office.navigation domain="materia-prima" office="romana" context="MATERIA PRIMA" icon="⚖" />


            <section class="weighbridge-workspace">
                <header class="weighbridge-heading">
                    <div><p class="eyebrow">RECEPCIÓN CONTRACTUAL</p><h1>Control de Romana</h1><p>Ingreso cargado, retorno vacío y cierre documental en una sola trazabilidad.</p></div>
                    <div class="weighbridge-heading__actions">
                        <button class="secondary-button" id="downloadBlankWeighingFormButton" type="button">↓ Planilla de pesaje en blanco</button>
                        <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                        <button class="primary-button" id="newReceptionButton" type="button">+ Registrar ingreso</button>
                    </div>
                </header>

                <section class="panel weighbridge-gatehouse" id="romanaGatehouse" aria-live="polite" hidden>
                    <div><p class="eyebrow">INGRESOS PORTERÍA</p><h2 id="romanaGatehouseCount">Consultando llegadas…</h2><p id="romanaGatehouseStatus"></p></div>
                    <a class="secondary-button" href="/oficina/porteria?romana=1">Ver ingresos y PDF diario →</a>
                    <ul id="romanaGatehouseRecent"></ul>
                </section>

                <div class="weighbridge-kpis">
                    <article><span>EN ROMANA · INGRESO</span><strong id="entryCount">0</strong><small>Pendientes de confirmar ingreso</small></article>
                    <article><span>PESAJE DE ENVASES</span><strong id="containerWeighingCount">0</strong><small>Recepciones con pesaje acumulativo abierto</small></article>
                    <article class="weighbridge-kpi--attention" data-weighbridge-attention="exit"><span>PENDIENTES DE CIERRE</span><strong id="exitCount">0</strong><small>Destare o cierre documental pendiente</small></article>
                    <article><span>RECEPCIONES CERRADAS</span><strong id="closedCount">0</strong><small>Según los filtros aplicados</small></article>
                    <article class="weighbridge-kpi--weight"><span>PESO NETO RECEPCIONADO</span><strong id="netWeight">0 kg</strong><small>Bruto menos tara total documentada</small></article>
                </div>

                <section class="panel weighbridge-list-panel">
                    <div class="weighbridge-panel-heading">
                        <div><p class="eyebrow">TRÁNSITO Y CIERRES</p><h2>Recepciones</h2></div>
                        <form class="weighbridge-filters" id="receptionFilters">
                            <input name="buscar" maxlength="100" placeholder="Recepción, guía, patente, cliente">
                            <select name="temporada_id"><option value="">Todas las temporadas</option></select>
                            <select name="estado"><option value="">Recepciones vigentes</option><option value="anulada">Anuladas</option><option value="en_bascula_ingreso">En báscula ingreso</option><option value="en_pesaje_envases">Pesaje acumulativo</option><option value="en_bascula_salida">Salida / cierre pendiente</option><option value="cerrado">Cerrado</option></select>
                            <input name="desde" type="date" aria-label="Desde">
                            <input name="hasta" type="date" aria-label="Hasta">
                            <button class="secondary-button" type="submit">Filtrar</button>
                        </form>
                    </div>
                    <div class="weighbridge-table-scroll">
                        <table class="weighbridge-table">
                            <thead><tr><th scope="col">Recepción</th><th scope="col">Cliente y guía</th><th scope="col">Transporte y declaración</th><th scope="col" class="rec-weight">Peso</th></tr></thead>
                            <tbody id="receptionTableBody"></tbody>
                        </table>
                    </div>
                    <div class="weighbridge-pagination"><span id="paginationSummary">0 recepciones</span><div><button id="previousPageButton" type="button">← Anterior</button><button id="nextPageButton" type="button">Siguiente →</button></div></div>
                </section>

                <section class="panel reception-detail is-hidden" id="receptionDetail">
                    <div class="weighbridge-panel-heading">
                        <div><p class="eyebrow">EXPEDIENTE DE RECEPCIÓN</p><h2 id="detailTitle">Recepción</h2><p id="detailSubtitle"></p></div>
                        <div class="detail-actions">
                            <button class="secondary-button rec-delete-button is-hidden" id="deleteReceptionButton" type="button">Eliminar recepción</button>
                            <button class="secondary-button is-hidden" id="annulReceptionButton" type="button">Anular recepción</button>
                            <button class="secondary-button is-hidden" id="editReceptionButton" type="button">Editar ingreso</button>
                            <button class="secondary-button is-hidden" id="correctGrossWeightButton" type="button">Corregir peso bruto</button>
                            <button class="secondary-button is-hidden" id="integralCorrectionButton" type="button">Corregir recepción validada</button>
                            <button class="primary-button is-hidden" id="confirmEntryButton" type="button">Confirmar ingreso</button>
                            <button class="primary-button is-hidden" id="addContainerWeighingButton" type="button">+ Registrar tanda</button>
                            <button class="primary-button is-hidden" id="closeReceptionButton" type="button">Registrar destare y cerrar</button>
                            <button class="secondary-button is-hidden" id="correctExitButton" type="button">Corregir salida de envases</button>
                            <button class="secondary-button is-hidden" id="downloadReceiptButton" type="button">↓ Registro de pesaje (RPR-01)</button>
                            <button class="secondary-button is-hidden" id="downloadRc02ReceptionButton" type="button">↓ RC-02 Recepción</button>
                            <button class="secondary-button is-hidden" id="downloadRc02DispatchButton" type="button">↓ RC-02 Despacho</button>
                            <button class="secondary-button" id="closeDetailButton" type="button">Cerrar detalle</button>
                        </div>
                    </div>
                    <div class="reception-detail__grid" id="detailFacts"></div>
                    <div class="reception-detail__bottom"><section><p class="eyebrow">TRAZABILIDAD</p><h3>Línea de tiempo</h3><div class="weighbridge-timeline" id="detailTimeline"></div></section><section><p class="eyebrow">CONTROL DE PESO</p><h3>Balance del pesaje</h3><div class="weight-balance" id="weightBalance"></div></section></div>
                    <section class="container-weighing-panel is-hidden" id="containerWeighingPanel">
                        <div class="weighbridge-panel-heading"><div><p class="eyebrow">PESAJE ACUMULATIVO</p><h3>Lecturas por tanda</h3></div><strong id="containerWeighingProgress">0 / 0</strong></div>
                        <div class="container-weighing-list" id="containerWeighingList"></div>
                    </section>
                </section>
            </section>
        </main>

        <dialog class="weighbridge-dialog weighbridge-dialog--compact" id="annulReceptionDialog" aria-labelledby="annulReceptionTitle">
            <form class="weighbridge-dialog__shell" id="annulReceptionForm">
                <header class="weighbridge-dialog__heading"><div><p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p><h2 id="annulReceptionTitle">Anular recepción</h2></div></header>
                <p id="annulReceptionSummary"></p>
                <p id="annulReceptionDescription">Se retirará de las recepciones vigentes y de los pendientes de Validación MP. Los ingresos confirmados se revertirán con trazabilidad. Si tiene lotes, reservas o correcciones vinculadas, se indicará qué debe resolverse.</p>
                <label class="field"><span id="annulReceptionReasonLabel">Motivo de anulación</span><textarea name="motivo" required minlength="10" maxlength="2000" rows="3"></textarea></label>
                <p class="form-error" id="annulReceptionError" role="alert"></p>
                <footer class="dialog-actions"><button class="secondary-button" id="annulReceptionCancel" type="button">Volver</button><button class="primary-button" type="submit">Confirmar anulación</button></footer>
            </form>
        </dialog>
        <dialog class="weighbridge-dialog integral-dialog" id="integralCorrectionDialog" aria-label="Corregir recepción validada"></dialog>
        <dialog class="weighbridge-dialog weighbridge-dialog--compact" id="grossWeightDialog" aria-labelledby="grossWeightTitle">
            <form class="weighbridge-dialog__shell" id="grossWeightForm">
                <header class="weighbridge-dialog__heading"><div><p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p><h2 id="grossWeightTitle">Corregir peso bruto</h2><p id="grossWeightDescription"></p></div></header>
                <label class="field is-hidden" id="grossWeightReadingField"><span>Tanda de pesaje *</span><select name="pesaje_id"></select></label>
                <p id="grossWeightCurrent"></p>
                <label class="field weight-field"><span>Peso bruto corregido *</span><div><input name="peso_bruto" type="number" min="0.001" max="200000" step="0.001" inputmode="decimal" required><b>kg</b></div></label>
                <p id="grossWeightPreview" aria-live="polite"></p>
                <label class="field"><span>Motivo de la corrección *</span><textarea name="motivo_correccion" rows="3" minlength="10" maxlength="1000" required></textarea></label>
                <p>Se conserva la tara registrada y se recalculan los kilos de los lotes, incluidos los confirmados sin entregas a proceso. Se mantienen sus números, estados y ubicaciones. Si ya hubo entregas, se requiere conciliación con ellas.</p>
                <div id="grossWeightLots"></div>
                <a id="grossWeightLotsLink" class="secondary-button" target="_blank" rel="noopener">Ver lotes de esta recepción</a>
                <p class="form-error" id="grossWeightError" role="alert"></p>
                <footer class="dialog-actions"><button class="secondary-button" id="grossWeightCancel" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar peso bruto</button></footer>
            </form>
        </dialog>
        <dialog class="weighbridge-dialog" id="receptionDialog">
            <form method="dialog" class="weighbridge-dialog__shell" id="receptionForm" novalidate>
                <div class="weighbridge-dialog__heading"><div><p class="eyebrow">INGRESO A ROMANA</p><h2 id="receptionDialogTitle">Registrar ingreso</h2><p id="receptionDialogDescription">Captura los antecedentes documentales y el peso del camión cargado.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="recepcion_id" type="hidden">
                <div class="weighbridge-form-grid">
                    <label class="field field--span-2"><span>Temporada global *</span><select name="temporada_id" required><option value="">Seleccionar temporada activa</option></select></label>
                    <label class="field field--span-2"><span>Cliente *</span><select name="cliente_id" required><option value="">Seleccionar cliente activo</option></select></label>
                    <label class="field"><span>Tipo de recepción *</span><select name="tipo_recepcion" required></select></label>
                    <label class="field" id="receptionSpeciesField"><span>Especie de la fruta *</span><select name="especie_validacion_id" required></select></label>
                    <label class="field is-hidden" id="containerEntryDateField"><span>Fecha de ingreso *</span><input name="fecha_ingreso" type="date" max="{{ now(config('app.operational_timezone'))->toDateString() }}" disabled><small>Disponible solo para ingresos documentales de envases.</small></label>
                    <label class="field is-hidden" id="containerConceptField"><span>Concepto de envases *</span><select name="concepto_envases"></select></label>
                    <fieldset class="field field--span-2 container-return is-hidden" id="containerReturnFields"><legend>Devolución de un productor</legend>
                        <small>Los envases que el productor devuelve abonan su cuenta con la propiedad con que salieron. Busca la guía de salida para precargar el productor y la propiedad.</small>
                        <div class="container-return__lookup"><label><span>Guía de salida que se devuelve</span><input name="devolucion_guia_salida" maxlength="80" autocomplete="off" placeholder="Ej.: 2324"></label><button class="secondary-button" type="button" id="containerReturnLookup">Buscar salida</button></div>
                        <p class="container-return__exit" id="containerReturnExit" aria-live="polite"></p>
                        <label><span>Productor CSG que devuelve *</span><select name="devolucion_productor_csg_id"><option value="">Seleccionar productor</option></select></label>
                        <label><span>Hora real del reingreso</span><input name="hora_ingreso" type="time"><small>Si la fecha es anterior a hoy, indica la hora en que reingresó el camión.</small></label>
                    </fieldset>
                    <label class="field" id="serviceField"><span>Servicio de fruta *</span><select name="tipo_servicio" required></select></label>
                    <label class="field"><span>Guía de despacho *</span><input name="numero_guia_despacho" maxlength="80" required></label>
                    <div class="field field--span-2" id="receptionGuidesControl"><button class="secondary-button" type="button" id="addReceptionGuide">+ Agregar otra guía</button><small>Un mismo camión y cliente pueden traer varias guías. Se mantiene un solo pesaje.</small></div>
                    <section class="field field--span-2 is-hidden" id="receptionGuidesPanel" aria-label="Desglose por guía"><p>Declara los envases de cada guía. Validación MP conservará este desglose y permitirá separar por origen.</p><div id="receptionGuidesList"></div></section>
                    <fieldset class="field field--span-2 container-lines" id="standardContainerLines"><legend>Envases declarados en la guía *</legend></fieldset>
                    <fieldset class="field field--span-2 container-weighing-config is-hidden" id="containerWeighingFields"><legend>Envases del pesaje acumulativo *</legend><div class="container-weighing-lines" id="containerWeighingLines"></div><button class="secondary-button container-weighing-add" type="button" id="addContainerWeighingLine">+ Agregar tipo de envase</button><small>Agrega una fila por cada tipo de envase con fruta, por ejemplo bins y totes. Indica cuántos se pesarán, su tara y si son del cliente, propios o arrendados por planta. Después podrás pesar por tandas, que pueden mezclar tipos, hasta completar el total.</small></fieldset>
                    <label class="field"><span>Patente camión *</span><input name="patente_camion" maxlength="10" autocomplete="off" placeholder="ABCD12" required></label>
                    <label class="field"><span>Tipo de camión *</span><select name="tipo_camion" required><option value="">Seleccionar tipo</option><option value="termo">Camión termo</option><option value="plano">Camión plano</option></select></label>
                    <label class="field"><span>Patente carro</span><input name="patente_carro" maxlength="10" autocomplete="off" placeholder="Opcional"></label>
                    <label class="field"><span>RUT conductor *</span><input name="rut_conductor" maxlength="12" placeholder="12.345.678-5" required></label>
                    <label class="field field--span-2"><span>Nombre conductor *</span><input name="nombre_conductor" maxlength="150" required></label>
                    <label class="field weight-field" id="grossWeightField"><span>Peso bruto *</span><div><input name="peso_bruto" type="number" min="1" max="200000" step="0.001" inputmode="decimal" required><b>kg</b></div><small>Lectura del camión cargado sobre la romana.</small></label>
                    <label class="field field--span-2"><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>
                    <label class="field weight-field is-hidden" id="administrativeTareField"><span>Peso tara corregido *</span><div><input name="peso_tara" type="number" min="1" max="200000" step="0.01" inputmode="decimal"><b>kg</b></div><small>Disponible únicamente para una recepción ya cerrada.</small></label>
                    <label class="field is-hidden" id="administrativeNetContainerField"><span>Envase contenedor para neto unitario *</span><select name="tipo_envase_calculo_neto"></select></label>
                    <label class="field field--span-2 is-hidden" id="administrativeCorrectionField"><span>Motivo de la corrección administrativa *</span><textarea name="motivo_correccion" maxlength="1000"></textarea><small>Quedará registrado junto al usuario, fecha y valores anteriores en la trazabilidad del expediente.</small></label>
                </div>
                <p class="form-error" id="receptionFormError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" id="saveReceptionButton" value="default" type="submit">Guardar pesaje de ingreso</button></div>
            </form>
        </dialog>

        <dialog class="weighbridge-dialog" id="containerWeighingDialog">
            <form method="dialog" class="weighbridge-dialog__shell weighbridge-dialog__shell--compact" id="containerWeighingForm" novalidate>
                <div class="weighbridge-dialog__heading"><div><p class="eyebrow">LECTURA DE ROMANA</p><h2>Registrar tanda de envases</h2><p id="containerWeighingDescription">Ingresa cuántos envases de cada tipo están sobre la báscula y su peso bruto conjunto.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <div class="container-weighing-quantities" id="containerWeighingQuantities"></div>
                <label class="field weight-field"><span>Peso bruto de la tanda *</span><div><input name="peso_bruto" type="number" min="0.001" max="200000" step="0.001" inputmode="decimal" required><b>kg</b></div></label>
                <label class="field"><span>Observación</span><textarea name="observacion" maxlength="1000"></textarea></label>
                <div class="net-preview"><span>TARA TOTAL</span><strong id="containerWeighingTarePreview">—</strong></div>
                <div class="net-preview"><span>PESO NETO DE LA TANDA</span><strong id="containerWeighingNetPreview">—</strong></div>
                <p class="form-error" id="containerWeighingFormError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Guardar lectura</button></div>
            </form>
        </dialog>

        <dialog class="weighbridge-dialog" id="tareDialog">
            <form method="dialog" class="weighbridge-dialog__shell weighbridge-dialog__shell--compact" id="tareForm" novalidate>
                <div class="weighbridge-dialog__heading"><div><p class="eyebrow">BÁSCULA DE SALIDA</p><h2>Registrar destare</h2><p id="tareDescription">Captura la lectura del camión vacío.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <div class="tare-count-note" id="tareCountNote" role="note" hidden><strong>Conteo de Validación MP</strong><ul id="tareCountNoteList"></ul><small>Queda en la observación de cierre de la hoja de pesaje (RPR-01) que firma el chofer.</small></div>
                <label class="field weight-field"><span>Peso tara *</span><div><input name="peso_tara" type="number" min="1" max="200000" step="0.01" inputmode="decimal" required><b>kg</b></div></label>
                    <label class="field"><span>Envases con fruta para repartir el neto *</span><select name="tipo_envase_calculo_neto" multiple size="4" required></select><small>En envases anidados elige un solo nivel; si contienen fruta independiente, elige ambos.</small></label>
                    <label class="field"><span>Salida de envases *</span><select name="modo_salida_envases" required>
                        <option value="mismos">Se va con los mismos envases</option>
                        <option value="diferentes">Se va con más o menos envases</option>
                        <option value="vacio">Se va vacío</option>
                    </select></label>
                    <label class="field" id="outboundGuideField"><span>N° de guía de salida *</span><input name="numero_guia_salida" maxlength="80" required></label>
                    <label class="field is-hidden" id="outboundProducerField"><span>Productor (CSG) que recibe los envases *</span><select name="salida_productor_csg"></select><small>El camión trae varios CSG: la salida se carga en la cuenta de este productor.</small></label>
                    <fieldset class="outbound-container-tares" id="outboundContainerTares"><legend>Envases y tara unitaria por tipo</legend><div id="outboundContainerTareList"></div><small>La entrada usa las cantidades validadas en la PDA. La diferencia por tipo ajusta el neto de fruta.</small>
                        <label class="field is-hidden" id="outboundExtraField"><span>Agregar otro tipo que sale</span><select id="outboundExtraType"></select><button class="secondary-button" type="button" id="outboundAddType">Agregar tipo</button></label>
                    </fieldset>
                <fieldset class="outbound-container-tares" id="dispatchInspectionPanel"><legend>RC-02 · Inspección de despacho</legend><div id="dispatchInspectionItems"></div><label class="field"><span>Observación de inspección</span><textarea id="dispatchInspectionObservation" maxlength="2000"></textarea></label></fieldset>
                <label class="field is-hidden" id="exitCorrectionReasonField"><span>Motivo de corrección *</span><textarea name="motivo_correccion" minlength="10" maxlength="1000"></textarea></label>
                <label class="field"><span>Observación de cierre</span><textarea name="observacion" maxlength="2000"></textarea></label>
                <div class="net-preview" id="containerTarePreviewRow"><span>DIFERENCIA DE TARA DE ENVASES</span><strong id="containerTarePreview">—</strong></div>
                <div class="net-preview"><span>PESO NETO CALCULADO</span><strong id="netWeightPreview">—</strong></div>
                <div class="net-preview"><span>NETO POR ENVASE CONTENEDOR</span><strong id="netPerContainerPreview">—</strong></div>
                <p class="form-error" id="tareFormError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Cerrar y emitir RPR-01</button></div>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
