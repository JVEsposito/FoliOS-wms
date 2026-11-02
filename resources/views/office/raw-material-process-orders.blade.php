<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Órdenes de proceso</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-raw-material-process-orders.css', 'resources/js/office-raw-material-process-orders.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <main class="office-app" id="ordersApp">
            <x-office.navigation domain="materia-prima" office="ordenes-proceso" context="MATERIA PRIMA" icon="≡" />

            <section class="orders-workspace">
                <header class="orders-heading">
                    <div>
                        <p class="eyebrow">PRE-SIF · CÁMARA MP → PACKING</p>
                        <h1>Órdenes de proceso</h1>
                        <p>Programa los lotes de cada línea y turno. Mientras la orden está en proceso solo cambia de versión.</p>
                    </div>
                    <div class="orders-heading__actions">
                        <button class="secondary-button" id="ordersReload" type="button">↻ Actualizar</button>
                        <button class="primary-button" id="ordersNew" type="button" hidden>+ Nueva orden</button>
                    </div>
                </header>

                <p class="orders-notice" id="ordersNotice" role="status"></p>

                <form class="orders-filters" id="ordersFilters">
                    <label>Estado
                        <select name="estado">
                            <option value="abiertas">Abiertas</option>
                            <option value="programada">Programadas</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="terminada">Terminadas</option>
                        </select>
                    </label>
                    <label>Línea<select name="linea_proceso_id"><option value="">Todas</option></select></label>
                    <label>Desde<input type="date" name="desde"></label>
                    <label>Hasta<input type="date" name="hasta"></label>
                    <button class="secondary-button" type="submit">Filtrar</button>
                </form>

                <div class="orders-list" id="ordersList" aria-live="polite"></div>

                <section class="orders-panel" id="orderDetail" hidden aria-labelledby="orderDetailTitle"></section>

                <section class="orders-panel" id="orderEditor" hidden aria-labelledby="orderEditorTitle">
                    <header class="orders-panel__heading">
                        <div><p class="eyebrow" id="orderEditorEyebrow">NUEVA ORDEN</p><h2 id="orderEditorTitle">Programar proceso</h2></div>
                        <button class="secondary-button" id="orderEditorClose" type="button">Cerrar</button>
                    </header>
                    <form id="orderEditorForm" novalidate>
                        <div class="orders-grid">
                            <label>Fecha<input type="date" name="fecha" required></label>
                            <label>Línea<select name="linea_proceso_id" required></select></label>
                            <label>Turno de inicio<select name="turno_inicio" required></select></label>
                            <label>Hora de inicio<input type="time" name="hora_inicio" required></label>
                            <label>Colación desde<input type="time" name="colacion_desde"></label>
                            <label>Colación hasta<input type="time" name="colacion_hasta"></label>
                        </div>
                        <label class="orders-reason" id="orderReasonField" hidden>Motivo del cambio<input name="motivo_cambio" maxlength="500" placeholder="Por qué cambia la programación"></label>

                        <h3>Lotes en secuencia</h3>
                        <div class="orders-table-wrap">
                            <table class="orders-table">
                                <thead><tr><th>#</th><th>Lote</th><th>Cliente</th><th>Total programado</th><th>N° proceso</th><th>Embalaje pendiente</th><th>Kg por caja</th><th>Mercado</th><th>Comuna</th><th>Provincia</th><th></th></tr></thead>
                                <tbody id="orderRows"></tbody>
                            </table>
                        </div>

                        <p class="orders-muted">El total programado incluye los bins ya entregados y los pendientes. Cambiar el embalaje aquí se aplica a los pendientes; para una entrega realizada usa «Corregir embalaje» en su detalle.</p>
                        <div id="orderChangeSummary" aria-live="polite"></div>
                        <label class="orders-reduction-confirm" id="orderReductionField" hidden><input type="checkbox" id="orderConfirmReduction">Confirmo que quiero reducir la programación y liberar los bins indicados.</label>

                        <div class="orders-picker">
                            <label>Agregar lote<input type="search" id="lotSearch" maxlength="80" placeholder="N° de lote o CSG"></label>
                            <div id="lotCandidates" class="orders-candidates"></div>
                        </div>

                        <p class="form-error" id="orderEditorError" role="alert"></p>
                        <div class="orders-panel__actions">
                            <button class="primary-button" type="submit" id="orderPublish">Publicar v1</button>
                        </div>
                    </form>
                </section>

                <dialog id="deliveryPackagingDialog" class="orders-packaging-dialog" aria-labelledby="deliveryPackagingTitle">
                    <form id="deliveryPackagingForm">
                        <h2 id="deliveryPackagingTitle">Corregir embalaje de entrega</h2>
                        <p id="deliveryPackagingContext"></p>
                        <p>Esta corrección conserva los bins entregados y los pendientes de la orden.</p>
                        <label>Nuevo embalaje<input name="embalaje" maxlength="150" required></label>
                        <label>Kilos por caja<input name="kilos_por_caja" type="number" min="0.001" max="9999999.999" step="0.001"></label>
                        <label>Motivo de la corrección<textarea name="motivo" minlength="3" maxlength="500" required></textarea></label>
                        <p id="deliveryPackagingError" class="form-error" role="alert"></p>
                        <div class="orders-panel__actions"><button type="submit" class="primary-button">Guardar corrección</button><button type="button" class="secondary-button" id="deliveryPackagingCancel">Cancelar</button></div>
                    </form>
                </dialog>

                <section class="orders-panel" id="linesPanel" hidden aria-labelledby="linesTitle">
                    <header class="orders-panel__heading"><div><p class="eyebrow">CONFIGURACIÓN</p><h2 id="linesTitle">Líneas de proceso</h2></div></header>
                    <p>El rendimiento calcula los horarios estimados de cada orden nueva o versión.</p>
                    <div id="linesList" class="orders-lines"></div>
                </section>
            </section>
        </main>
    </body>
</html>
