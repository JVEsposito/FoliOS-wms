<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#183442">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Discrepancias de maniobras</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/estiba-ui.css',
                'resources/css/office-discrepancies.css',
                'resources/js/office-discrepancies.js',
            ])
        @endif
    </head>
    <body>
        <main class="office-app discrepancies-app is-hidden" id="discrepanciesApp">
            <x-office.navigation
                domain="frigorifico"
                office="discrepancias"
                context="FRIGORÍFICO · PT"
                icon="!"
            />

            <div class="discrepancies-shell estiba-ui">
                <x-estiba.heading
                    class="discrepancies-heading"
                    eyebrow="Supervisión operacional"
                    title="Discrepancias de maniobras"
                    description="Revisa el estado físico informado por el camarero y decide si la maniobra puede reanudarse o debe cancelarse."
                >
                    <button class="eui-button eui-button--secondary" id="discrepanciesReload" type="button">Actualizar</button>
                </x-estiba.heading>

                <x-estiba.alert class="discrepancies-notice" title="El estado físico manda" tone="warning">
                    Reanudar conserva lo ya ejecutado. Cancelar solo está disponible cuando no existe una tarea en proceso ni custodia temporal activa.
                </x-estiba.alert>

                <section class="discrepancies-metrics" aria-label="Resumen de discrepancias">
                    <article class="eui-panel"><span>ABIERTAS</span><strong id="openDiscrepanciesCount">0</strong><small>requieren decisión</small></article>
                    <article class="eui-panel"><span>RESUELTAS</span><strong id="resolvedDiscrepanciesCount">0</strong><small>temporada activa</small></article>
                    <article class="eui-panel"><span>PALLETS NO ENCONTRADOS</span><strong id="missingPalletsCount">0</strong><small>abiertas · localizar físicamente</small></article>
                </section>

                <section class="eui-panel discrepancies-panel">
                    <form class="discrepancies-filters" id="discrepanciesFilters">
                        <label>
                            <span>Estado</span>
                            <select class="eui-input" name="estado">
                                <option value="abierta">Abiertas</option>
                                <option value="resuelta">Resueltas</option>
                                <option value="todas">Todas</option>
                            </select>
                        </label>
                        <label>
                            <span>Tipo</span>
                            <select class="eui-input" name="tipo">
                                <option value="">Todos</option>
                                <option value="pallet_no_encontrado">Pallet no encontrado</option>
                            </select>
                        </label>
                        <label class="discrepancies-search">
                            <span>Buscar</span>
                            <input class="eui-input" name="q" type="search" maxlength="120" placeholder="Folio, maniobra o detalle">
                        </label>
                        <button class="eui-button" type="submit">Filtrar</button>
                        <button class="eui-button eui-button--secondary" id="discrepanciesClear" type="button">Limpiar</button>
                    </form>

                    <div class="discrepancies-panel__heading">
                        <div><p class="eyebrow">BANDEJA</p><h2>Casos de la temporada activa</h2></div>
                        <span id="discrepanciesResults">0 registros</span>
                    </div>
                    <p class="form-error" id="discrepanciesError" role="alert"></p>
                    <div class="discrepancies-list" id="discrepanciesList"></div>
                    <nav class="discrepancies-pagination" aria-label="Paginación de discrepancias">
                        <button class="eui-button eui-button--secondary" id="discrepanciesPrevious" type="button">← Anterior</button>
                        <span id="discrepanciesPage">Página 1 de 1</span>
                        <button class="eui-button eui-button--secondary" id="discrepanciesNext" type="button">Siguiente →</button>
                    </nav>
                </section>
            </div>

            <div class="loading is-hidden" id="discrepanciesLoading" aria-hidden="true">
                <span></span><strong id="discrepanciesLoadingText">Consultando discrepancias…</strong>
            </div>
            <div class="toast-region" id="discrepanciesToasts" aria-live="polite"></div>
        </main>
    </body>
</html>
