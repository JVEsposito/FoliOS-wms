<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07151e">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Inventario CC</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-warehouses.js'])
    @endif
</head>
<body>
<main id="custodyApp" class="office-app is-hidden">
    <x-office.navigation domain="materiales" office="custodia" context="INVENTARIO CC" icon="⌖" />

    <div class="custody-shell">
        <header class="custody-heading">
            <div>
                <p class="eyebrow">INVENTARIO CC · CENTROS DE COSTO</p>
                <h1>Custodia distribuida de materiales</h1>
                <p>Una entrega cambia la custodia; solo un consumo o ajuste disminuye la existencia total de la empresa.</p>
            </div>
            <button class="secondary-button" id="custodyReload" type="button">↻ Actualizar</button>
        </header>

        <section class="custody-metrics">
            <article><span>FOLIOS VIGENTES</span><strong id="custodyFolioCount">0</strong></article>
            <article><span>ALMACENES CON SALDO</span><strong id="custodyWarehouseCount">0</strong></article>
            <article><span>ÍTEMS CON EXISTENCIA</span><strong id="custodyItemCount">0</strong></article>
        </section>

        <div class="custody-tabs" role="tablist" aria-label="Perspectiva del inventario">
            <button class="is-active" data-tab="bodega" type="button" role="tab">Existencia en Bodega</button>
            <button data-tab="centros_costo" type="button" role="tab">Existencia en centros de costo</button>
            <button data-tab="total_empresa" type="button" role="tab">Existencia total empresa</button>
        </div>

        <section class="custody-card">
            <form class="custody-filters" id="custodyFilters">
                <label>Buscar
                    <input name="q" type="search" placeholder="Almacén, cliente, ítem, folio, lote, cantidad, cámara o posición">
                </label>
                <label>Cliente
                    <select name="cliente_id"><option value="">Todos los clientes</option></select>
                </label>
                <label>Ítem
                    <select name="item_id"><option value="">Todos los ítems</option></select>
                </label>
                <label data-custody-filter-warehouse>Almacén / centro de costo
                    <select name="almacen_id"><option value="">Todos los almacenes</option></select>
                </label>
                <label data-custody-filter-camera>Cámara
                    <select name="camara_id"><option value="">Todas las cámaras</option></select>
                </label>
                <button class="secondary-button" id="custodyFiltersReset" type="button">Limpiar</button>
                <button class="primary-button" id="custodyExport" type="button">⇩ Exportar Excel</button>
            </form>
            <div class="custody-filter-summary">
                <span id="custodyResultsSummary">0 registros</span>
                <p class="custody-error" id="custodyFilterError" role="alert"></p>
            </div>
            <div class="custody-table-wrap">
                <table class="custody-table">
                    <thead id="custodyTableHead"></thead>
                    <tbody id="custodyTableBody"></tbody>
                </table>
            </div>
        </section>

        <div class="custody-grid">
            <section class="custody-card" id="custodyMovementPanel">
                <p class="eyebrow">OPERACIÓN POSTERIOR A LA ENTREGA</p>
                <h2>Registrar movimiento</h2>
                <p>Consumir descuenta inventario; devolver y transferir conservan el total; ajustar exige supervisión.</p>
                <form class="custody-form" id="custodyMovementForm">
                    <input type="hidden" name="incidencia_verificacion_id">
                    <p id="custodyIncidentContext" class="is-hidden"></p>
                    <label>Acción
                        <select name="tipo" required>
                            <option value="consumo">Consumir</option>
                            <option value="devolucion">Devolver a Bodega</option>
                            <option value="transferencia">Transferir entre almacenes</option>
                            <option value="ajuste">Ajustar diferencia</option>
                        </select>
                    </label>
                    <label>Almacén origen
                        <select name="almacen_origen_id" required>
                            <option value="">Seleccione el almacén de origen</option>
                        </select>
                        <small id="custodyOriginSummary">Seleccione un almacén para consultar únicamente su existencia disponible.</small>
                    </label>
                    <label>Folio disponible en el almacén
                        <select name="folio_id" aria-describedby="custodyOriginSummary" required disabled>
                            <option value="">Seleccione primero el almacén de origen</option>
                        </select>
                    </label>
                    <label>Almacén destino
                        <select name="almacen_destino_id"></select>
                    </label>
                    <label>Cantidad
                        <input name="cantidad" type="number" step="0.001" required>
                    </label>
                    <label>Documento relacionado
                        <input name="documento_relacionado" maxlength="150" placeholder="Orden, turno o referencia">
                    </label>
                    <label>Cámara destino
                        <select name="camara_destino_id"></select>
                    </label>
                    <label>Posición destino
                        <select name="posicion_destino_id"></select>
                    </label>
                    <label class="wide">Motivo / operación
                        <textarea name="motivo" rows="2" maxlength="1000" placeholder="Producción turno noche, merma, devolución de sobrante…"></textarea>
                    </label>
                    <label class="wide">Justificación de excepción FIFO
                        <textarea name="motivo_excepcion_fifo" rows="2" maxlength="1000" placeholder="Solo cuando no se usa el lote más antiguo del almacén"></textarea>
                    </label>
                    <p class="custody-error" id="custodyMovementError"></p>
                    <div class="custody-actions">
                        <button class="primary-button" type="submit">Registrar movimiento</button>
                    </div>
                </form>
            </section>

            <aside class="custody-card" id="custodyKardexPanel">
                <p class="eyebrow">TRAZABILIDAD</p>
                <h2>Kardex por almacén</h2>
                <p>Consulta en pantalla los últimos 100 eventos o descarga el historial completo según tipo y período.</p>
                <form class="custody-form" id="custodyHistoryFilters">
                    <label class="wide">Historial a exportar
                        <select name="categoria">
                            <option value="todos">Todos los registros</option>
                            <option value="movimientos">Movimientos: entregas, devoluciones y transferencias</option>
                            <option value="consumos">Consumos</option>
                            <option value="ajustes">Ajustes</option>
                        </select>
                    </label>
                    <label>Desde
                        <input name="desde" type="date">
                    </label>
                    <label>Hasta
                        <input name="hasta" type="date">
                    </label>
                    <p class="custody-error" id="custodyHistoryError" role="alert"></p>
                    <div class="custody-actions">
                        <button class="primary-button" id="custodyHistoryExport" type="button">⇩ Exportar historial</button>
                    </div>
                </form>
                <div class="custody-kardex" id="custodyKardex"></div>
            </aside>
        </div>
    </div>
</main>
</body>
</html>
