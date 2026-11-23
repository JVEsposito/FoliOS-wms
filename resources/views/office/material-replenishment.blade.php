@php($niveles = $niveles ?? false)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · {{ $niveles ? 'Niveles de stock' : 'Reposición de materiales' }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-material-replenishment.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
<main class="office-app" id="materialReplenishmentApp" data-levels-mode="{{ $niveles ? 'true' : 'false' }}" data-folios-workspace="analisis">
    <x-office.navigation domain="materiales" :office="$niveles ? 'niveles-stock' : 'reposicion'" :context="$niveles ? 'NIVELES DE STOCK' : 'REPOSICIÓN'" icon="▦" />

    <div class="custody-shell materials-bandejas">
        <x-folios.cabecera-etiqueta area="materiales" seccion="Materiales · Bodega Central"
            :titulo="$niveles ? 'Niveles de stock' : 'Reposición'" :codigo="$niveles ? 'MA · NIVELES' : 'MA · REPOSICIÓN'"
            :descripcion="$niveles ? 'Configura el mínimo, punto de reorden y máximo por ítem.' : 'Prioriza compras según el stock disponible para entregar y las salidas reales de bodega.'">
            <button type="button" id="reload" class="secondary-button">Actualizar</button>
        </x-folios.cabecera-etiqueta>

        <section class="folios-indicadores" aria-label="Indicadores de la temporada activa">
            <x-folios.ticket-indicador area="materiales" clave="MA" rotulo="Ítems en quiebre" valor-id="replenishmentOut" valor="—" detalle="Temporada activa" />
            <x-folios.ticket-indicador area="materiales" clave="MA" rotulo="Ítems bajo mínimo" valor-id="replenishmentLow" valor="—" detalle="Temporada activa" />
        </section>

        <x-folios.bandeja area="materiales" :titulo="$niveles ? 'Ítems y niveles definidos' : 'Ítems para revisar'" id="replenishmentHeading" panel-id="replenishmentList">
            <x-slot:resumen>
                <span id="summary" role="status">Consultando stock…</span>
            </x-slot:resumen>
            <form id="filters" class="custody-filters" data-folios-toolbar>
                <label>Cliente
                    <select name="cliente_id"><option value="">Todos</option></select>
                </label>
                <label>Categoría
                    <select name="categoria"><option value="">Todas</option></select>
                </label>
                @unless ($niveles)
                    <label>Estado
                        <select name="estado">
                            <option value="">Pendientes de reposición</option>
                            <option value="quiebre">Quiebre</option>
                            <option value="bajo_minimo">Bajo mínimo</option>
                            <option value="reponer">Reponer</option>
                            <option value="normal">Normal</option>
                            <option value="sobre_maximo">Sobre máximo</option>
                        </select>
                    </label>
                @endunless
                <label>Consumo
                    <select name="dias">
                        <option value="">Predeterminado</option>
                        <option value="30">30 días</option>
                        <option value="60">60 días</option>
                        <option value="90">90 días</option>
                    </select>
                </label>
                <button type="button" id="excel" class="secondary-button" disabled @if ($niveles) hidden @endif>Exportar Excel</button>
            </form>
            <p id="notice" class="materials-bandeja-error" role="alert"></p>
            <div class="custody-table-wrap" role="region" tabindex="0" aria-label="Stock y consumo por ítem">
                <table class="custody-table">
                    <thead id="columns"></thead>
                    <tbody id="rows"></tbody>
                </table>
            </div>
        </x-folios.bandeja>

        <x-folios.bandeja area="materiales" titulo="Detalle del ítem" id="replenishmentDetailHeading" panel-id="detail" tabindex="-1" hidden>
            <h3 id="itemHeading"></h3>
            <p id="itemSummary"></p>
            <p id="detailNotice" class="materials-bandeja-error" role="alert"></p>

            @if ($niveles)
                <form id="levelsForm" class="custody-form" hidden>
                    <label>Stock mínimo<input name="stock_minimo" type="number" min="0" step="0.001" placeholder="Sin nivel"></label>
                    <label>Punto de reorden<input name="punto_reorden" type="number" min="0" step="0.001" placeholder="Sin nivel"></label>
                    <label>Stock máximo<input name="stock_maximo" type="number" min="0" step="0.001" placeholder="Sin nivel"></label>
                    <p id="levelsUnit" class="wide materials-help"></p>
                    <p id="levelsError" class="wide materials-bandeja-error" role="alert"></p>
                    <div class="custody-actions">
                        <button type="submit" class="primary-button">Guardar niveles</button>
                    </div>
                </form>
            @endif

            <div id="replenishmentHistory">
                <h3>Consumo por semana</h3>
                <div id="weeks"></div>

                <h3>Movimientos del período</h3>
                <div class="custody-table-wrap" role="region" tabindex="0" aria-label="Movimientos del ítem">
                    <table class="custody-table">
                        <thead>
                            <tr><th scope="col">Fecha</th><th scope="col">Folio</th><th scope="col">Tipo</th><th scope="col">Salida neta</th><th scope="col">Motivo</th></tr>
                        </thead>
                        <tbody id="movements"></tbody>
                    </table>
                </div>
                <nav id="pages" class="materials-bandeja-actions" aria-label="Páginas de movimientos"></nav>

                <h3>Cambios de niveles</h3>
                <div id="history"></div>
            </div>
        </x-folios.bandeja>
    </div>
</main>
</body>
</html>
