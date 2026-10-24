<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FoliOS · Capacidad de bodegas</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/css/office-material-capacity.css', 'resources/js/office-material-capacity.js', 'resources/js/office-material-bodegas.js', 'resources/js/office-material-routing.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body><main class="office-app" id="materialCapacityApp">
    <x-office.navigation domain="materiales" office="capacidad" context="CAPACIDAD DE BODEGAS" icon="▦" />
    <div class="custody-shell">
        <header class="custody-heading"><div><p class="eyebrow">MATERIALES · ESPACIO FÍSICO</p><h1>Capacidad de bodegas</h1><p>Dimensiones de almacenes físicos, zonas útiles y cargas. Todas las medidas se ingresan en metros.</p></div><button type="button" id="capacityReload" class="secondary-button">Actualizar</button></header>
        <p id="capacityNotice" role="status" aria-live="polite"></p>
        <section class="custody-card"><label>Almacén físico<select id="capacityWarehouse"></select></label><p id="capacityMetrics"></p>
            <p class="materials-help">La superficie y el volumen útiles corresponden a las zonas definidas. Los pasillos y espacios de seguridad deben quedar fuera de ellas.</p>
        </section>
        <details class="custody-card" id="bodegaDirectory"><summary>Bodegas físicas: altas y estado</summary>
            <p>Una bodega nueva comienza sin stock. Traslada materiales mediante una transferencia interna desde Inventario CC, indicando la cámara y posición de destino. Las dimensiones se configuran por separado.</p>
            <p id="bodegaNotice" role="status" aria-live="polite"></p>
            <div class="capacity-actions"><button id="bodegaReload" type="button" class="secondary-button">Actualizar bodegas</button><button id="bodegaNew" type="button" class="secondary-button" hidden>Crear bodega</button></div>
            <div id="bodegaList" class="capacity-type-list"></div>
            <form id="bodegaForm" hidden><h2 id="bodegaTitle">Bodega física</h2><fieldset id="bodegaFields" class="capacity-grid" disabled>
                <label>Código permanente<input name="codigo" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="BOD-NORTE" required></label>
                <label>Nombre<input name="nombre" maxlength="180" minlength="3" required></label>
                <label>Descripción<input name="descripcion" maxlength="1000"></label>
                <label>Estado<select name="activo"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
            </fieldset><p>El código se conserva. Bodega Central permanece activa. Para desactivar otra bodega debe quedar sin existencias, reservas ni despachos u órdenes pendientes.</p>
                <div class="capacity-actions"><button type="submit" class="primary-button">Guardar bodega</button><button id="bodegaCancel" type="button" class="secondary-button">Cancelar edición</button></div>
            </form>
            <details id="bodegaHistory"><summary>Historial de bodegas</summary><div id="bodegaHistoryRows"></div><nav id="bodegaHistoryPages" class="capacity-actions" aria-label="Páginas del historial de bodegas"></nav></details>
        </details>
        <details class="custody-card" id="routingPanel"><summary>Asignación automática al aprobar recepciones</summary>
            <p>Las reglas se evalúan de menor a mayor prioridad. La primera coincidencia por cliente y tipo de material asigna la bodega de cada folio nuevo. Sin coincidencia activa se conserva Central. Ninguna regla cambia el inventario anterior.</p>
            <p>La asignación deja cámara y posición pendientes. La capacidad de referencia no equivale a espacio libre: verifica dimensiones y ocupación antes de ubicar.</p>
            <p id="routingNotice" role="status" aria-live="polite"></p>
            <div class="capacity-actions"><button id="routingReload" type="button" class="secondary-button">Actualizar reglas</button><button id="routingNew" type="button" class="secondary-button" hidden>Crear regla</button></div>
            <div id="routingRows" class="capacity-type-list"></div><nav id="routingPages" class="capacity-actions" aria-label="Páginas de reglas"></nav>
            <form id="routingForm" hidden><h2 id="routingTitle">Regla de recepción</h2><fieldset id="routingFields" class="capacity-grid" disabled>
                <label>Nombre<input name="nombre" required minlength="3" maxlength="150"></label>
                <label>Prioridad (menor primero)<input name="prioridad" type="number" min="1" max="9999" step="1" value="10" required></label>
                <label>Cliente<select name="cliente_id"><option value="">Todos los clientes</option></select></label>
                <label>Tipo de material<select name="categoria_operacional"><option value="">Todos los tipos</option><option value="insumo">Insumo</option><option value="material_mp">Material de embalaje sin preparar</option><option value="material_pt">Material preparado para línea</option></select></label>
                <label>Bodega de ingreso<select name="almacen_material_id" required></select></label>
                <label>Estado<select name="activo"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
                <label>Motivo del cambio<textarea name="motivo" minlength="5" maxlength="1000" required></textarea></label>
            </fieldset><div class="capacity-actions"><button type="submit" class="primary-button">Guardar regla</button><button id="routingCancel" type="button" class="secondary-button">Cancelar regla</button></div></form>
            <details id="routingHistory"><summary>Historial de reglas</summary><div id="routingHistoryRows"></div><nav id="routingHistoryPages" class="capacity-actions" aria-label="Páginas de historial de reglas"></nav></details>
        </details>
        <details class="custody-card" id="capacityTypes"><summary>Tipos de pallet y medidas de referencia</summary>
            <p>La altura total incluye el pallet y la carga. Cada tipo conserva límites de apilamiento y peso; las medidas reales pueden registrarse por separado.</p>
            <div id="capacityTypeList" class="capacity-type-list"></div>
            <button type="button" id="capacityNewType" class="secondary-button" hidden>Crear tipo de pallet</button>
            <form id="capacityTypeForm" hidden><h2 id="capacityTypeTitle">Tipo de pallet</h2><fieldset id="capacityTypeFields" class="capacity-grid">
                <label>Código<input name="codigo" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*"></label>
                <label>Nombre<input name="nombre" required minlength="2" maxlength="150"></label>
                <label>Largo (m)<input name="largo_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Ancho (m)<input name="ancho_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Altura total de referencia (m)<input name="altura_total_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Máximo de niveles apilados<input name="max_niveles" type="number" min="1" max="20" step="1" required></label>
                <label>Peso máximo por pallet (kg)<input name="peso_maximo_kg" type="number" min="0.001" max="1000000" step="0.001" placeholder="Sin límite definido"></label>
                <label>Estado<select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></label>
            </fieldset><button type="submit" class="primary-button">Guardar tipo de pallet</button></form>
        </details>
        <details class="custody-card" id="capacityConfiguration"><summary>Dimensiones y zonas útiles del almacén</summary>
            <form id="capacityWarehouseForm"><fieldset id="capacityWarehouseFields"><div class="capacity-grid">
                <label>Largo total (m)<input name="largo_m" type="number" min="0.001" max="1000" step="0.001" required></label>
                <label>Ancho total (m)<input name="ancho_m" type="number" min="0.001" max="1000" step="0.001" required></label>
                <label>Altura útil máxima (m)<input name="altura_util_m" type="number" min="0.001" max="50" step="0.001" required></label>
            </div><p>Define rectángulos útiles que no se superpongan. X e Y se miden desde una misma esquina del almacén. Un nivel significa sin apilar.</p>
            <div id="capacityZones"></div><button type="button" id="capacityAddZone" class="secondary-button">Agregar zona útil</button></fieldset>
            <button type="submit" id="capacitySaveWarehouse" class="primary-button" hidden>Guardar dimensiones y zonas</button></form>
        </details>
        <section class="custody-card"><h2>Simular un formato de carga</h2>
            <p>Compara un tipo de pallet o sus medidas reales con cada zona. El resultado es una referencia de acomodo uniforme; no representa lugares libres ni una autorización de almacenamiento.</p>
            <form id="capacitySimulationForm"><fieldset class="capacity-grid" id="capacitySimulationFields">
                <label>Tipo de pallet<select name="tipo_pallet_id" required></select></label>
                <label>Largo real (m)<input name="largo_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Ancho real (m)<input name="ancho_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Altura total real (m)<input name="altura_total_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Niveles permitidos por la carga<input name="max_niveles" type="number" min="1" max="20" step="1" required></label>
                <label>Peso por unidad física (kg)<input name="peso_unitario_kg" type="number" min="0.001" max="1000000" step="0.001" placeholder="Sin dato"></label>
            </fieldset><button type="submit" class="primary-button">Simular capacidad de referencia</button></form>
            <div id="capacitySimulationResult" aria-live="polite"></div>
        </section>
        <section class="custody-card"><h2>Medidas reales de las cargas</h2>
            <p>La cantidad de material no permite deducir cuántos pallets físicos hay. Registra cuántas unidades físicas tienen estas medidas. Si cambia la versión del saldo, se solicitará verificar la medición.</p>
            <form id="capacitySearchForm" class="capacity-search"><label>Folio o material<input name="busqueda" type="search" maxlength="100"></label><button class="secondary-button" type="submit">Buscar cargas</button></form>
            <p id="capacityStockNotice" role="status"></p>
            <div class="custody-table-wrap"><table class="custody-table"><caption>Cargas del almacén seleccionado · temporada activa</caption><thead><tr><th>Folio / material</th><th>Cantidad actual</th><th>Medición</th><th>Dimensiones (m)</th><th>Unidades físicas</th><th>Acciones</th></tr></thead><tbody id="capacityStockRows"></tbody></table></div>
            <nav id="capacityStockPages" class="capacity-actions" aria-label="Páginas de cargas"></nav>
            <form id="capacityMeasurementForm" hidden><h3 id="capacityMeasurementTitle"></h3><fieldset class="capacity-grid" id="capacityMeasurementFields">
                <label>Tipo de pallet<select name="tipo_pallet_id" required></select></label>
                <label>Largo real (m)<input name="largo_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Ancho real (m)<input name="ancho_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Altura total real (m)<input name="altura_total_m" type="number" min="0.001" max="20" step="0.001" required></label>
                <label>Niveles permitidos por la carga<input name="max_niveles" type="number" min="1" max="20" step="1" required></label>
                <label>Unidades físicas con estas medidas<input name="bultos_fisicos" type="number" min="1" max="100000" step="1" required></label>
                <label>Peso por unidad física (kg)<input name="peso_unitario_kg" type="number" min="0.001" max="1000000" step="0.001" placeholder="Sin dato"></label>
            </fieldset><div class="capacity-actions"><button type="submit" class="primary-button">Guardar medidas reales</button><button type="button" id="capacityCancelMeasurement" class="secondary-button">Cerrar medición</button></div></form>
        </section>
        <details class="custody-card" id="capacityHistory"><summary>Historial de cambios</summary>
            <p id="capacityHistoryScope">Tipos, dimensiones de almacenes y mediciones, con responsable y valores anteriores.</p>
            <button type="button" id="capacityAllHistory" class="secondary-button">Ver todos los cambios</button>
            <div id="capacityHistoryRows"></div><nav id="capacityHistoryPages" class="capacity-actions" aria-label="Páginas del historial"></nav>
        </details>
    </div>
</main></body></html>
