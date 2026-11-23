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
    @include('office.partials.arranque')
</head>
<body>
<main id="custodyApp" class="office-app is-hidden" data-folios-workspace="catalogo">
    <x-office.navigation domain="materiales" office="custodia" context="INVENTARIO CC" icon="⌖" />

    <div class="custody-shell materials-bandejas">
        <x-folios.cabecera-etiqueta area="materiales" seccion="Materiales · Centros de costo"
            titulo="Inventario CC" codigo="MA · CUSTODIA"
            descripcion="Una entrega cambia la custodia. El consumo o un ajuste modifican la existencia total de la empresa.">
            <button class="secondary-button" id="custodyReload" type="button">Actualizar</button>
        </x-folios.cabecera-etiqueta>

        <section class="folios-indicadores" aria-label="Resumen de custodia">
            <x-folios.ticket-indicador area="materiales" clave="MA" rotulo="Folios vigentes" valor-id="custodyFolioCount" valor="—" />
            <x-folios.ticket-indicador area="materiales" clave="MA" rotulo="Almacenes con saldo" valor-id="custodyWarehouseCount" valor="—" />
            <x-folios.ticket-indicador area="materiales" clave="MA" rotulo="Ítems con existencia" valor-id="custodyItemCount" valor="—" />
        </section>

        <x-folios.bandeja area="materiales" titulo="Existencias por custodia" id="custodyInventoryHeading" panel-id="custodyInventory">
            <x-slot:resumen>
                <span id="custodyResultsSummary" role="status">Consultando inventario…</span>
            </x-slot:resumen>

            <div class="custody-tabs" role="group" aria-label="Perspectiva del inventario">
                <button data-tab="bodega" type="button" aria-pressed="true">Existencia en Bodega</button>
                <button data-tab="centros_costo" type="button" aria-pressed="false">Existencia en centros de costo</button>
                <button data-tab="total_empresa" type="button" aria-pressed="false">Existencia total empresa</button>
            </div>
            <form class="custody-filters" id="custodyFilters" data-folios-toolbar>
                <label>Buscar
                    <input name="q" type="search" placeholder="Folio, ítem, lote o ubicación">
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
                <div class="materials-bandeja-actions">
                    <button class="secondary-button" id="custodyFiltersReset" type="button">Limpiar</button>
                    <button class="secondary-button" id="custodyExport" type="button" disabled>Exportar Excel</button>
                </div>
            </form>
            <p class="materials-bandeja-error" id="custodyFilterError" role="alert"></p>
            <div class="custody-table-wrap" role="region" tabindex="0" aria-label="Detalle de existencias">
                <table class="custody-table">
                    <thead id="custodyTableHead"></thead>
                    <tbody id="custodyTableBody"></tbody>
                </table>
            </div>
        </x-folios.bandeja>

        <div class="custody-grid">
            <x-folios.bandeja area="materiales" titulo="Registrar movimiento" id="custodyMovementHeading" panel-id="custodyMovementPanel" class="is-hidden">
                <p class="materials-help">Consumir descuenta inventario, devolver y transferir conservan el total, y ajustar exige supervisión.</p>
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
            </x-folios.bandeja>

            <x-folios.bandeja area="materiales" titulo="Kardex por almacén" id="custodyKardexHeading" panel-id="custodyKardexPanel" class="is-hidden">
                <p class="materials-help">Consulta los últimos 100 eventos o descarga el historial completo según tipo y período.</p>
                <form class="custody-form" id="custodyHistoryFilters" data-folios-toolbar>
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
                        <button class="secondary-button" id="custodyHistoryExport" type="button">Exportar historial</button>
                    </div>
                </form>
                <div class="custody-kardex" id="custodyKardex"></div>
            </x-folios.bandeja>
        </div>
    </div>
</main>
</body>
</html>
