<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Calendario de embarques</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-shipment-calendar.css', 'resources/js/office-shipment-calendar.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand shipment-access-brand">
                <div class="office-logo" aria-hidden="true">◷</div>
                <p class="eyebrow">FoliOS · DESPACHO · CALENDARIO</p>
                <h1 id="officeAccessTitle">Planifica cada embarque sin perder de vista una sola ventana.</h1>
                <p>Reserva horarios tentativos, reúne uno o más instructivos y crea la orden operativa para las cámaras cuando el cliente confirme.</p>
                <div class="feature-row"><span>24 horas</span><span>Flujo global</span><span>Sobrecupo auditado</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar al calendario</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Ingresar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="embarques" context="FRIGORÍFICO · PT" icon="◷" />

            <section class="shipment-workspace">
                <header class="shipment-heading panel">
                    <div><p class="eyebrow">PLANIFICACIÓN 24/7</p><h1>Calendario de embarques</h1><p id="seasonSummary">Cargando temporada…</p></div>
                    <div class="shipment-heading__actions">
                        <button class="secondary-button" id="previousPeriod" type="button">← Anterior</button>
                        <button class="secondary-button" id="todayButton" type="button">Hoy</button>
                        <input id="calendarDate" type="date" aria-label="Fecha del calendario">
                        <select id="calendarMode" aria-label="Vista del calendario"><option value="day">Día</option><option value="week" selected>Semana</option></select>
                        <button class="secondary-button" id="nextPeriod" type="button">Siguiente →</button>
                        <button class="primary-button" id="newShipment" type="button">+ Nuevo embarque</button>
                    </div>
                </header>

                <section class="shipment-legend" aria-label="Estados del calendario">
                    <span><i class="legend-dot legend-dot--available"></i>Disponible</span>
                    <span><i class="legend-dot legend-dot--tentative"></i>Tentativo</span>
                    <span><i class="legend-dot legend-dot--confirmed"></i>Confirmado</span>
                    <span><i class="legend-dot legend-dot--overbook"></i>Sobrecupo autorizado</span>
                    <span><i class="legend-dot legend-dot--cancelled"></i>Cancelado</span>
                </section>

                <section class="shipment-calendar panel" aria-label="Ventanas de embarque">
                    <div class="shipment-calendar__scroll" id="calendarScroll">
                        <div class="shipment-grid" id="shipmentGrid" role="grid"></div>
                    </div>
                </section>
            </section>
        </main>

        <dialog class="shipment-dialog" id="shipmentDialog" aria-labelledby="shipmentDialogTitle">
            <form class="shipment-dialog__shell" id="shipmentForm" novalidate>
                <header>
                    <div><p class="eyebrow" id="shipmentDialogEyebrow">SOLICITUD TENTATIVA</p><h2 id="shipmentDialogTitle">Nuevo embarque</h2><p id="shipmentDialogHelp">Selecciona manualmente una ventana abierta.</p></div>
                    <button class="shipment-dialog__close" id="closeShipmentDialog" type="button" aria-label="Cerrar">×</button>
                </header>
                <input name="id" type="hidden"><input name="version_esperada" type="hidden">
                <div class="shipment-form-grid">
                    <label class="field"><span>Cliente / exportadora *</span><select name="cliente_id" required></select></label>
                    <label class="field"><span>Modalidad *</span><select name="modalidad" required><option value="maritimo">Marítimo</option><option value="aereo">Aéreo</option><option value="terrestre">Terrestre</option><option value="por_confirmar">Por confirmar</option></select></label>
                    <label class="field"><span>Fecha *</span><input name="fecha_programada" type="date" required></label>
                    <label class="field"><span>Hora *</span><select name="hora_programada" required></select></label>
                    <label class="field field--wide"><span>Referencia del correo</span><input name="referencia_correo" maxlength="200" placeholder="Asunto, remitente o correlativo"></label>
                    <label class="field field--wide"><span>Observación</span><input name="observacion" maxlength="2000"></label>
                </div>

                <details class="shipment-details">
                    <summary>Datos compartidos del transporte</summary>
                    <div class="shipment-form-grid">
                        <label class="field"><span>Nave / vuelo</span><input name="nave_vuelo" maxlength="150"></label>
                        <label class="field"><span>Naviera / aerolínea / transportista</span><input name="transportista" maxlength="180"></label>
                        <label class="field"><span>Puerto / aeropuerto / paso</span><select name="puerto_embarque_id"></select></label>
                        <label class="field"><span>Contenedor</span><input name="contenedor" maxlength="100"></label>
                        <label class="field"><span>Sello</span><input name="sello" maxlength="100"></label>
                        <label class="field"><span>Patente camión</span><input name="patente_camion" maxlength="30"></label>
                        <label class="field"><span>Patente trasera</span><input name="patente_trasera" maxlength="30"></label>
                        <label class="field field--wide"><span>Documentos</span><input name="documentos" maxlength="2000"></label>
                    </div>
                </details>

                <section class="instructive-section">
                    <div class="instructive-heading"><div><p class="eyebrow">DOCUMENTACIÓN</p><h3>Instructivos del embarque</h3></div><button class="secondary-button" id="addInstruction" type="button">+ Agregar instructivo</button></div>
                    <div class="instructive-list" id="instructionList"></div>
                </section>

                <label class="overbook-authorization is-hidden" id="overbookAuthorization"><input name="autorizar_sobrecupo" type="checkbox"><span><strong>Autorizar sobrecupo</strong><small>Esta excepción queda registrada a nombre del supervisor.</small></span></label>
                <label class="field is-hidden" id="overbookReason"><span>Motivo del sobrecupo *</span><textarea name="motivo_sobrecupo" minlength="10" maxlength="1000" rows="2"></textarea></label>

                <section class="load-confirmation is-hidden" id="loadConfirmation">
                    <div><p class="eyebrow">ORDEN PARA CÁMARAS</p><h3>Confirmar instructivo y crear CAR</h3></div>
                    <div class="shipment-form-grid">
                        <label class="field"><span>Prioridad</span><select name="prioridad"><option value="normal">Normal</option><option value="alta">Alta</option><option value="urgente">Urgente</option></select></label>
                        <label class="field"><span>Cámara objetivo</span><select name="camara_objetivo_id"></select></label>
                        <label class="field"><span>Andén previsto</span><select name="anden_previsto_id"></select></label>
                    </div>
                </section>

                <p class="form-error" id="shipmentFormError" role="alert"></p>
                <footer>
                    <button class="danger-button is-hidden" id="cancelShipment" type="button">Cancelar embarque</button>
                    <span></span>
                    <button class="secondary-button" id="dismissShipment" type="button">Cerrar</button>
                    <button class="primary-button" id="saveShipment" type="submit">Guardar solicitud</button>
                    <button class="primary-button is-hidden" id="confirmShipment" type="button">Confirmar y crear orden CAR</button>
                </footer>
            </form>
        </dialog>

        <template id="instructionTemplate">
            <article class="instruction-card">
                <header><strong>Instructivo <span data-instruction-number></span></strong><button data-remove-instruction type="button" aria-label="Quitar instructivo">×</button></header>
                <div class="shipment-form-grid instruction-fields">
                    <label class="field"><span>N.º externo</span><input data-field="numero_externo" maxlength="150"></label>
                    <label class="field"><span>Recibidor</span><input data-field="recibidor" maxlength="180"></label>
                    <label class="field"><span>País destino</span><select data-field="pais_destino_id"></select></label>
                    <label class="field"><span>Puerto / aeropuerto / paso destino</span><select data-field="puerto_destino_id" disabled></select></label>
                    <label class="field"><span>Pallets</span><input data-field="cantidad_pallets" type="number" min="0" max="999"></label>
                    <label class="field"><span>Cajas</span><input data-field="cantidad_cajas" type="number" min="0" max="999999"></label>
                    <label class="field"><span>Booking</span><input data-field="booking" maxlength="150"></label>
                    <label class="field"><span>SPS</span><input data-field="sps" maxlength="150"></label>
                    <label class="field"><span>DUS</span><input data-field="dus" maxlength="150"></label>
                    <label class="field"><span>Planilla SAG</span><input data-field="planilla_sag" maxlength="150"></label>
                    <label class="field"><span>Sello SAG</span><input data-field="sello_sag" maxlength="150"></label>
                    <label class="field field--wide"><span>Observación</span><input data-field="observacion" maxlength="1000"></label>
                </div>
            </article>
        </template>

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
