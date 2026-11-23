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
        @include('office.partials.arranque')
    </head>
    <body>
        <main class="office-app discrepancies-app is-hidden" id="discrepanciesApp" data-folios-workspace="registro">
            <x-office.navigation
                domain="frigorifico"
                office="discrepancias"
                context="FRIGORÍFICO · PT"
                icon="!"
            />

            <div class="discrepancies-shell estiba-ui">
                <x-folios.cabecera-etiqueta area="frigorifico" seccion="Frigorífico · Supervisión"
                    titulo="Discrepancias de maniobras" codigo="FR · CONTROL DE MANIOBRAS" class="discrepancies-heading"
                    descripcion="Revisa el reporte físico del camarero y resuelve el paso en disputa.">
                    <button class="eui-button eui-button--secondary" id="discrepanciesReload" type="button">Actualizar</button>
                </x-folios.cabecera-etiqueta>

                <x-estiba.alert class="discrepancies-notice" title="El estado físico manda" tone="warning">
                    Reanudar conserva lo ya ejecutado. Cancelar solo está disponible cuando no existe una tarea en proceso ni custodia temporal activa.
                </x-estiba.alert>

                <section class="discrepancies-metrics folios-indicadores" aria-label="Resumen de discrepancias">
                    <x-folios.ticket-indicador area="frigorifico" clave="FR" rotulo="Abiertas" valor-id="openDiscrepanciesCount" valor="—" detalle="Requieren decisión" />
                    <x-folios.ticket-indicador area="frigorifico" clave="FR" rotulo="Resueltas" valor-id="resolvedDiscrepanciesCount" valor="—" />
                    <x-folios.ticket-indicador area="frigorifico" clave="FR" rotulo="Pallets no encontrados" valor-id="missingPalletsCount" valor="—" detalle="Abiertas por localizar" />
                </section>

                <x-folios.bandeja area="frigorifico" titulo="Casos de la temporada activa" id="discrepanciesRegisterTitle">
                    <x-slot:resumen><span id="discrepanciesResults" role="status"></span></x-slot:resumen>
                    <form class="discrepancies-filters" id="discrepanciesFilters" data-folios-toolbar>
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

                    <p class="form-error" id="discrepanciesError" role="alert"></p>
                    <div class="discrepancies-list" id="discrepanciesList"><div class="folios-bandeja__estado">Consultando discrepancias…</div></div>
                    <nav class="discrepancies-pagination" aria-label="Paginación de discrepancias">
                        <button class="eui-button eui-button--secondary" id="discrepanciesPrevious" type="button" disabled>← Anterior</button>
                        <span id="discrepanciesPage">Página 1 de 1</span>
                        <button class="eui-button eui-button--secondary" id="discrepanciesNext" type="button" disabled>Siguiente →</button>
                    </nav>
                </x-folios.bandeja>
            </div>

            <div class="loading is-hidden" id="discrepanciesLoading" aria-hidden="true">
                <span></span><strong id="discrepanciesLoadingText">Consultando discrepancias…</strong>
            </div>
            <div class="toast-region" id="discrepanciesToasts" aria-live="polite"></div>
        </main>
    </body>
</html>
