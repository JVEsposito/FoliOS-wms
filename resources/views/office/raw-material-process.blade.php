<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Fruta a proceso</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-raw-material-process.css', 'resources/js/office-raw-material-process.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand process-access-brand">
                <div class="office-logo" aria-hidden="true">→</div>
                <p class="eyebrow">FoliOS · MATERIA PRIMA</p>
                <h1 id="officeAccessTitle">Controla la entrega y el retorno de fruta desde Packing.</h1>
                <p>Cada viaje conserva su origen y cada resultado genera un sublote interno pendiente de ubicación.</p>
                <div class="feature-row"><span>Viajes parciales</span><span>Retornos clasificados</span><span>Trazabilidad completa</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Fruta a proceso</h2><p>Disponible para camareros, supervisión y administración autorizada.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar al módulo <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="materia-prima" office="fruta-proceso" context="MATERIA PRIMA" icon="→" />

            <section class="process-workspace">
                <header class="process-heading">
                    <div><p class="eyebrow">CÁMARA MP ↔ PACKING</p><h1>Fruta a proceso</h1><p id="seasonDescription">Cargando circuito de proceso…</p></div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <div class="process-kpis">
                    <article><span>LOTES ABIERTOS</span><strong id="openLotsCount">0</strong><small>Con bins disponibles</small></article>
                    <article><span>BINS DISPONIBLES</span><strong id="availableBinsCount">0</strong><small>Aún en cámara</small></article>
                    <article><span>BINS ENTREGADOS</span><strong id="deliveredBinsCount">0</strong><small>Movimientos vigentes</small></article>
                    <article><span>LOTES COMPLETADOS</span><strong id="completedLotsCount">0</strong><small>Saldo cero</small></article>
                    <article><span>VIAJES POR RETORNAR</span><strong id="pendingReturnsCount">0</strong><small>Sin cierre de Packing</small></article>
                    <article><span>BINS RETORNADOS</span><strong id="returnedBinsCount">0</strong><small>Nuevos sublotes internos</small></article>
                    <article><span>KILOS RECUPERADOS</span><strong id="recoveredKilosCount">0</strong><small>Cuando fueron informados</small></article>
                    <article><span>PENDIENTES DE UBICACIÓN</span><strong id="pendingLocationCount">0</strong><small>Sublotes sin cámara</small></article>
                </div>

                <nav class="process-section-tabs" aria-label="Etapas de Fruta a proceso">
                    <button class="is-active" data-process-section="entregas" type="button">1. Entregas a Packing</button>
                    <button onclick="window.location.href='/oficina/materia-prima/retornos-packing'" type="button">2. Retornos de Packing</button>
                </nav>

                <section class="panel process-panel">
                    <div class="process-panel__heading">
                        <div><p class="eyebrow" id="panelEyebrow">CONTROL DE DESPACHO INTERNO</p><h2 id="panelTitle">Lotes disponibles para proceso</h2><p id="panelDescription">Desde cámara MP o directamente desde Hidrocooler, conservando el origen de cada viaje.</p></div>
                        <form id="processFilters">
                            <input name="buscar" maxlength="100" placeholder="Lote, cliente, CSG u orden">
                            <select name="estado"><option value="abiertos">Abiertos</option><option value="completados">Completados</option><option value="">Todos</option></select>
                            <button class="secondary-button" type="submit">Filtrar</button>
                        </form>
                    </div>
                    <div class="process-lot-list" id="processLotList"></div>
                </section>
            </section>
        </main>

        <dialog class="process-dialog" id="deliveryDialog">
            <form method="dialog" id="deliveryForm" novalidate>
                <div class="process-dialog__heading"><div><p class="eyebrow">VIAJE FÍSICO A PACKING</p><h2 id="deliveryTitle">Registrar entrega</h2><p id="deliveryDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="lote_id" type="hidden">
                <div class="delivery-summary" id="deliverySummary"></div>
                <div class="delivery-fields">
                    <label><span>Cantidad de bins *</span><input name="cantidad_envases" type="number" min="1" max="100000" inputmode="numeric" required></label>
                    <label><span>Kilos enviados</span><input name="kilos_enviados" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" placeholder="Opcional"></label>
                    <label><span>Línea de proceso *</span><input name="linea_proceso" maxlength="50" autocomplete="off" placeholder="Ej. Línea 1" required></label>
                    <label><span>Turno *</span><select name="turno" required><option value="">Seleccionar</option><option value="A">Turno A</option><option value="B">Turno B</option></select></label>
                    <label><span>N° de orden *</span><input name="numero_orden" maxlength="80" autocomplete="off" required></label>
                    <label class="field-wide"><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>
                </div>
                <p class="form-error" id="deliveryError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Confirmar viaje</button></div>
            </form>
        </dialog>

        <dialog class="process-dialog process-dialog--wide" id="returnDialog">
            <form method="dialog" id="returnForm" novalidate>
                <div class="process-dialog__heading"><div><p class="eyebrow">PACKING → CÁMARA MP</p><h2 id="returnTitle">Registrar retorno</h2><p id="returnDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="entrega_id" type="hidden">
                <div class="delivery-summary" id="returnSummary"></div>
                <div class="return-origins-heading"><div><strong>Viajes de origen</strong><small>Selecciona todos los viajes incluidos en este retorno físico y decide cuáles quedan cerrados.</small></div></div>
                <div class="return-origins" id="returnOrigins"></div>
                <div class="return-results-heading"><div><strong>Resultados de Packing</strong><small>Se creará un sublote interno por cada fila, sin duplicarlo entre los orígenes.</small></div><button class="secondary-button" id="addReturnResult" type="button">+ Agregar resultado</button></div>
                <div class="return-results" id="returnResults"></div>
                <label class="return-observation"><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>
                <p class="form-error" id="returnError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Crear sublotes</button></div>
            </form>
        </dialog>

        <dialog class="process-dialog" id="locationDialog">
            <form method="dialog" id="locationForm" novalidate>
                <div class="process-dialog__heading"><div><p class="eyebrow">PENDIENTE DE UBICACIÓN</p><h2 id="locationTitle">Asignar sublote a cámara</h2><p id="locationDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="sublote_id" type="hidden">
                <div class="delivery-fields location-fields">
                    <label class="field-wide"><span>Cámara de materia prima *</span><select name="camara_id" required></select></label>
                    <label class="field-wide"><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>
                </div>
                <p class="form-error" id="locationError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Confirmar ubicación</button></div>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
