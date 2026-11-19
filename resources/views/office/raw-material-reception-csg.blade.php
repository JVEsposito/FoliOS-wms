<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Informe de recepción por CSG</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite([
            'resources/css/office.css',
            'resources/css/estiba-ui.css',
            'resources/css/office-reception-csg.css',
            'resources/js/office-reception-csg.js',
        ])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <main class="office-app is-hidden" id="officeApp">
        <x-office.navigation domain="materia-prima" office="recepciones-csg" context="MATERIA PRIMA" icon="MP" />
        <section class="report-csg estiba-ui" id="officeContent">
            <x-estiba.heading title="Informe de recepción por CSG"
                description="Kilos netos de recepciones cerradas en la temporada activa, por fecha de ingreso en hora de planta." />

            <form class="eui-panel" id="receptionCsgFilters">
                <fieldset class="report-csg__filters eui-panel__body" id="receptionCsgFields" disabled>
                    <label class="eui-field">Cliente
                        <select name="cliente" required><option value="">Cargando clientes…</option></select>
                    </label>
                    <label class="eui-field">Desde<input name="desde" type="date" required></label>
                    <label class="eui-field">Hasta<input name="hasta" type="date" aria-describedby="receptionCsgDateHelp"></label>
                    <x-estiba.button type="submit">Consultar</x-estiba.button>
                    <span class="eui-field__hint report-csg__date-help" id="receptionCsgDateHelp">Para consultar un solo día, deja «Hasta» vacío.</span>
                </fieldset>
            </form>
            <p class="report-csg__message" id="receptionCsgStatus" role="status">Cargando clientes…</p>
            <p class="report-csg__message" id="receptionCsgError" role="alert" hidden></p>

            <div class="report-csg__result" id="receptionCsgResult" hidden>
                <header class="report-csg__result-heading">
                    <div><h2 id="receptionCsgClient"></h2><p id="receptionCsgPeriod"></p></div>
                    <x-estiba.button id="receptionCsgDownload" variant="secondary" icon="print">Descargar PDF</x-estiba.button>
                </header>

                <dl class="report-csg__totals" id="receptionCsgTotals" aria-label="Totales del período"></dl>
                <div class="report-csg__balance" id="receptionCsgBalance" hidden></div>

                <div class="report-csg__overview">
                    <section class="eui-panel" aria-labelledby="receptionCsgSummaryTitle">
                        <header class="eui-panel__header"><h2 class="eui-panel__title" id="receptionCsgSummaryTitle">Resumen por CSG</h2></header>
                        <div class="eui-table-scroll">
                            <table class="eui-table report-csg__summary">
                                <thead><tr><th scope="col">CSG</th><th scope="col">Productor</th><th scope="col" class="eui-numeric">Lotes</th><th scope="col" class="eui-numeric">Kg netos</th></tr></thead>
                                <tbody id="receptionCsgSummary"></tbody>
                            </table>
                        </div>
                    </section>
                    <section class="eui-panel" aria-labelledby="receptionCsgChartTitle">
                        <header class="eui-panel__header"><h2 class="eui-panel__title" id="receptionCsgChartTitle">Kilos por CSG</h2></header>
                        <ul class="eui-panel__body report-csg__chart" id="receptionCsgChart"></ul>
                    </section>
                </div>

                <section class="eui-panel" aria-labelledby="receptionCsgDetailTitle">
                    <header class="eui-panel__header"><h2 class="eui-panel__title" id="receptionCsgDetailTitle">Detalle de recepciones</h2></header>
                    <div class="eui-table-scroll" tabindex="0" aria-label="Detalle por guía y lote">
                        <table class="eui-table report-csg__detail">
                            <thead><tr><th scope="col">Ingreso</th><th scope="col">Recepción</th><th scope="col">Guía</th><th scope="col">Lote</th><th scope="col">CSG</th><th scope="col">Variedad</th><th scope="col" class="eui-numeric">Kg netos</th></tr></thead>
                            <tbody id="receptionCsgDetail"></tbody>
                        </table>
                    </div>
                    <nav class="report-csg__pager" id="receptionCsgPager" aria-label="Páginas del detalle" hidden>
                        <x-estiba.button id="receptionCsgPrevious" variant="secondary">Anterior</x-estiba.button>
                        <span id="receptionCsgPage" role="status"></span>
                        <x-estiba.button id="receptionCsgNext" variant="secondary">Siguiente</x-estiba.button>
                    </nav>
                </section>
                <p class="report-csg__note">Los kilos por CSG corresponden a lotes confirmados.</p>
            </div>
        </section>
    </main>
</body>
</html>
