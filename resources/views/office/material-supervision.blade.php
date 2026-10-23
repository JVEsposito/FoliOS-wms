<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FoliOS · Supervisión de Materiales</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/css/office-material-supervision.css', 'resources/js/office-material-supervision.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
<main class="office-app" id="materialSupervisionApp">
    <x-office.navigation domain="materiales" office="supervision" context="SUPERVISIÓN" icon="▦" />
    <div class="custody-shell">
        <header class="custody-heading">
            <div><p class="eyebrow">MATERIALES · SUPERVISIÓN</p><h1>Supervisión de Materiales</h1><p>Existencias, actividad y diferencias. Abre un registro para ver quién lo realizó y la evidencia disponible.</p></div>
            <button type="button" id="supervisionReload" class="secondary-button">Actualizar</button>
        </header>
        <p id="supervisionNotice" role="status" aria-live="polite"></p>
        <section class="custody-card" aria-label="Consulta de supervisión">
            <form id="supervisionFilters" class="custody-filters">
                <label>Vista<select name="vista"><option value="stock">Existencias actuales</option><option value="inventario">Movimientos de inventario</option><option value="almacenes">Movimientos de almacenes</option><option value="recepciones">Auditoría de recepciones</option><option value="diferencias">Diferencias de inventario</option></select></label>
                <label>Temporada<select name="temporada_id" required></select></label>
                <label>Cliente<select name="cliente_id"><option value="">Todos</option></select></label>
                <label data-filter="almacen">Almacén<select name="almacen_id"><option value="">Todos</option></select></label>
                <label data-filter="periodo">Desde<input name="desde" type="date" required></label>
                <label data-filter="periodo">Hasta<input name="hasta" type="date" required></label>
                <label data-filter="actividad">Usuario<select name="user_id"><option value="">Todos</option></select></label>
                <label data-filter="actividad">Tipo<select name="tipo"><option value="">Todos</option></select></label>
                <label data-filter="estado">Estado de la toma<select name="estado"><option value="">Todos</option><option value="en_conteo">En conteo</option><option value="en_revision">En revisión</option><option value="aprobada">Aprobada</option><option value="anulada">Anulada</option></select></label>
                <label><span id="supervisionSearchLabel">Folio o ítem</span><input name="busqueda" type="search" maxlength="100" placeholder="Código, nombre o folio"></label>
                <button type="submit" class="primary-button">Consultar</button>
            </form>
            <p id="supervisionScope" class="materials-help"></p>
            <div id="supervisionResults" hidden aria-busy="false">
                <div id="supervisionSummary" class="supervision-summary" aria-label="Indicadores del conjunto filtrado"></div>
                <p id="supervisionCount"></p>
                <div class="custody-table-wrap"><table class="custody-table"><caption class="supervision-caption" id="supervisionCaption"></caption><thead id="supervisionColumns"></thead><tbody id="supervisionRows"></tbody></table></div>
                <nav id="supervisionPages" class="supervision-pages" aria-label="Páginas de resultados"></nav>
            </div>
        </section>
        <section id="supervisionDetail" class="custody-card" hidden tabindex="-1" aria-labelledby="supervisionDetailTitle">
            <div class="custody-heading"><h2 id="supervisionDetailTitle">Evidencia del registro</h2><button type="button" id="supervisionClose" class="secondary-button">Cerrar detalle</button></div>
            <p id="supervisionDetailNotice" role="status"></p>
            <dl id="supervisionDetailFields" class="supervision-fields"></dl>
            <div id="supervisionEvidence"></div>
        </section>
    </div>
</main>
</body>
</html>
