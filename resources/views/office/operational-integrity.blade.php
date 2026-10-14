<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Salud operacional</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/office-operational-integrity.css',
                'resources/js/office-operational-integrity.js',
            ])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <main class="office-app integrity-app is-hidden" id="integrityApp">
            <x-office.navigation
                domain="administracion"
                office="integridad-operacional"
                context="ADMINISTRACIÓN & GERENCIA"
                icon="!"
            />

            <div class="integrity-shell">
                <header class="integrity-heading">
                    <div>
                        <p class="eyebrow">CONTROL TRANSVERSAL</p>
                        <h1>Salud operacional</h1>
                        <p>Detecta contradicciones entre folios, procesos, ubicaciones y reservas sin modificar la operación.</p>
                    </div>
                    <div class="integrity-heading__actions">
                        <div>
                            <span>ÚLTIMA AUDITORÍA</span>
                            <strong id="integrityLastAudit">Todavía no ejecutada</strong>
                            <small id="integrityLastAuditDetail">El análisis automático se ejecuta cada 15 minutos.</small>
                        </div>
                        <button class="primary-button" id="integrityRunAudit" type="button">Ejecutar auditoría</button>
                        <button class="secondary-button" id="integrityReload" type="button">↻ Actualizar</button>
                    </div>
                </header>

                <section class="integrity-notice" role="note">
                    <strong>Solo diagnóstico</strong>
                    <span>Esta capa registra hallazgos y resoluciones. No corrige, elimina ni regulariza datos operacionales.</span>
                </section>

                <section class="integrity-metrics" aria-label="Resumen de integridad operacional">
                    <article>
                        <span>HALLAZGOS ACTIVOS</span>
                        <strong id="integrityActiveCount">0</strong>
                        <small>contradicciones vigentes</small>
                    </article>
                    <article class="integrity-metric--critical">
                        <span>CRÍTICOS</span>
                        <strong id="integrityCriticalCount">0</strong>
                        <small>requieren revisión prioritaria</small>
                    </article>
                    <article class="integrity-metric--warning">
                        <span>ADVERTENCIAS</span>
                        <strong id="integrityWarningCount">0</strong>
                        <small>posibles desalineaciones</small>
                    </article>
                    <article class="integrity-metric--resolved">
                        <span>RESUELTOS</span>
                        <strong id="integrityResolvedCount">0</strong>
                        <small>ya no aparecen en la auditoría</small>
                    </article>
                </section>

                <div class="integrity-workspace">
                    <section class="integrity-panel integrity-findings-panel">
                        <div class="integrity-panel__heading">
                            <div>
                                <p class="eyebrow">HALLAZGOS</p>
                                <h2>Contradicciones detectadas</h2>
                            </div>
                            <span id="integrityResultsSummary">0 registros</span>
                        </div>

                        <form class="integrity-filters" id="integrityFilters">
                            <label>
                                <span>Estado</span>
                                <select name="estado">
                                    <option value="activos">Activos</option>
                                    <option value="resueltos">Resueltos</option>
                                    <option value="todos">Todos</option>
                                </select>
                            </label>
                            <label>
                                <span>Severidad</span>
                                <select name="severidad">
                                    <option value="">Todas</option>
                                    <option value="critico">Crítico</option>
                                    <option value="advertencia">Advertencia</option>
                                    <option value="informativo">Informativo</option>
                                </select>
                            </label>
                            <label>
                                <span>Módulo</span>
                                <select name="modulo" id="integrityModuleFilter">
                                    <option value="">Todos los módulos</option>
                                </select>
                            </label>
                            <label>
                                <span>Regla</span>
                                <select name="regla" id="integrityRuleFilter">
                                    <option value="">Todas las reglas</option>
                                </select>
                            </label>
                            <label class="integrity-filter-search">
                                <span>Buscar</span>
                                <input name="q" type="search" maxlength="150" placeholder="Folio, proceso, carga o detalle">
                            </label>
                            <button class="primary-button" type="submit">Filtrar</button>
                            <button class="secondary-button" id="integrityClearFilters" type="button">Limpiar</button>
                        </form>

                        <p class="form-error" id="integrityError" role="alert"></p>
                        <div class="integrity-table-scroll">
                            <table class="integrity-table">
                                <thead>
                                    <tr>
                                        <th>Severidad</th>
                                        <th>Módulo</th>
                                        <th>Hallazgo</th>
                                        <th>Referencia</th>
                                        <th>Detección</th>
                                    </tr>
                                </thead>
                                <tbody id="integrityFindingsBody"></tbody>
                            </table>
                        </div>
                        <nav class="integrity-pagination" aria-label="Paginación de hallazgos">
                            <button class="secondary-button" id="integrityPreviousPage" type="button">← Anterior</button>
                            <span id="integrityPageStatus">Página 1 de 1</span>
                            <button class="secondary-button" id="integrityNextPage" type="button">Siguiente →</button>
                        </nav>
                    </section>

                    <aside class="integrity-panel integrity-history-panel">
                        <div class="integrity-panel__heading">
                            <div>
                                <p class="eyebrow">TRAZABILIDAD</p>
                                <h2>Últimas auditorías</h2>
                            </div>
                        </div>
                        <div class="integrity-history" id="integrityAuditHistory"></div>
                    </aside>
                </div>
            </div>

            <div class="loading is-hidden" id="integrityLoading" aria-hidden="true">
                <span></span>
                <strong id="integrityLoadingText">Consultando integridad…</strong>
            </div>
            <div class="toast-region" id="integrityToasts" aria-live="polite"></div>
        </main>
    </body>
</html>
