<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#102f43">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Operación ahora</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/estiba-ui.css',
                'resources/css/office-operation-now.css',
                'resources/js/office-operation-now.js',
            ])
        @endif
    </head>
    <body>
        <main class="office-app operation-now-app is-hidden" id="operationNowApp">
            <x-office.navigation domain="administracion" office="operacion-ahora" context="CENTRO DE CONTROL" icon="OA" />

            <div class="operation-now estiba-ui" id="officeContent" data-density="compact">
                <header class="operation-now-command">
                    <div class="operation-now-command__title">
                        <p class="operation-now-eyebrow">CENTRO DE CONTROL · INFORMACIÓN EN VIVO</p>
                        <h1>Operación ahora</h1>
                        <p>Personal, infraestructura y excepciones vigentes de la planta.</p>
                    </div>
                    <div class="operation-now-command__date" aria-label="Fecha y hora operacional">
                        <span id="operationDate">—</span>
                        <strong id="operationTime">--:--:--</strong>
                        <small id="operationTimezone">Hora operacional</small>
                        <small id="operationShift">Turno sin configurar</small>
                    </div>
                    <div class="operation-now-command__actions">
                        <x-estiba.button class="operation-now-refresh" id="operationRefresh" variant="secondary" icon="refresh">Actualizar</x-estiba.button>
                        <button type="button" class="operation-now-pin-button" id="operationPinsOpen" hidden>PIN de operadores</button>
                    </div>
                </header>

                <section class="operation-now-syncbar" aria-label="Estado y sincronización de la operación">
                    <div class="operation-now-syncbar__state">
                        <span>ESTADO DE LOS DATOS</span>
                        <strong class="operation-now-live" id="operationLiveSignal" data-tone="neutral">
                            <i aria-hidden="true"></i><span id="operationLiveText">Sin consultar</span>
                        </strong>
                        <small id="operationSeason">Temporada sin consultar</small>
                        <small id="operationUpdatedAt">Última lectura: —</small>
                    </div>
                    <div class="operation-now-syncbar__latest">
                        <span>ÚLTIMA OPERACIÓN RECIBIDA</span>
                        <small class="operation-now-command__latest" id="syncLatestOperation">Sin actividad registrada</small>
                        <small class="operation-now-command__latest-context" id="syncLatestContext">Esperando evidencia de dispositivos</small>
                    </div>
                    <dl class="operation-now-syncbar__metrics" aria-label="Sincronizaciones de hoy">
                        <div data-tone="success"><dt>Aceptadas</dt><dd id="syncAccepted">0</dd></div>
                        <div data-tone="warning"><dt>Pendientes</dt><dd id="syncPending">0</dd></div>
                        <div data-tone="info"><dt>Procesando</dt><dd id="syncProcessing">0</dd></div>
                        <div data-tone="critical"><dt>Rechazadas</dt><dd id="syncRejected">0</dd></div>
                        <div data-tone="critical"><dt>Conflictos</dt><dd id="syncConflict">0</dd></div>
                    </dl>
                </section>

                <div class="operation-now-connection" id="operationConnection" role="status" hidden>
                    <strong id="operationConnectionTitle">Información conservada</strong>
                    <span id="operationConnectionDetail">Se mantiene la última lectura disponible.</span>
                </div>

                <div class="operation-now-dashboard" aria-busy="true" id="operationWorkspace">
                    <section class="operation-now-panel operation-now-panel--planner" id="operationPlannerPanel" data-tone="neutral" aria-labelledby="operationPlannerTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v5H4zM4 15h7v5H4zM15 15h5v5h-5zM8 9v3h9v3M8 12H7v3"/></svg>
                                <div><h2 id="operationPlannerTitle">Puesto de mando del planificador</h2><span>Qué ordena el planificador y qué ocurre en planta</span></div>
                            </div>
                            <div class="operation-now-panel__tools operation-now-planner__signals">
                                <strong class="operation-now-planner__cycle" id="plannerCycleMeta">Consultando ciclo…</strong>
                                <button class="operation-now-planner__compare" id="plannerComparisonOpen" type="button" disabled>Comparar ciclo</button>
                                <span class="operation-now-planner__badge" id="plannerModeSignal" data-tone="neutral">Modo —</span>
                                <span class="operation-now-planner__badge" id="plannerFreshnessSignal" data-tone="neutral">Vigencia —</span>
                                <span class="operation-now-planner__badge" id="plannerHealthSignal" data-tone="neutral">Salud —</span>
                            </div>
                        </header>
                        <div class="operation-now-panel__body operation-now-planner">
                            <div class="operation-now-planner__process-alert" id="plannerProcessAlert" role="status" aria-live="polite" hidden></div>
                            <dl class="operation-now-planner__context" aria-label="Configuración vigente del planificador">
                                <div><dt>Cámaras dirigidas</dt><dd id="plannerRollout">—</dd></div>
                                <div><dt>Maniobras simultáneas</dt><dd id="plannerCapacity">—</dd></div>
                            </dl>
                            <dl class="operation-now-planner__summary" aria-label="Decisiones del ciclo vigente">
                                <div data-decision="en_ejecucion"><dt>En curso</dt><dd id="plannerRunningCount">0</dd></div>
                                <div data-decision="pausada"><dt>Pausadas</dt><dd id="plannerPausedCount">0</dd></div>
                                <div data-decision="sin_objetivo"><dt>Pallets sin ubicación ni objetivo</dt><dd id="plannerUnpositionedCount">0</dd></div>
                                <div data-decision="seleccionada"><dt>Listas para tomar</dt><dd id="plannerSelectedCount">0</dd></div>
                                <div data-decision="alternativa"><dt>En espera</dt><dd id="plannerAlternativeCount">0</dd></div>
                                <div data-decision="excluida_conflicto"><dt>Bloqueadas por otra</dt><dd id="plannerConflictCount">0</dd></div>
                                <div data-decision="fuera_rollout"><dt>Sin planificador</dt><dd id="plannerRolloutCount">0</dd></div>
                            </dl>
                            <div class="operation-now-planner__risks" id="plannerRiskRows" aria-label="Riesgos físicos actuales"><span>Evaluando riesgos…</span></div>
                            <div class="operation-now-table-scroll operation-now-planner__decisions">
                                <table class="operation-now-table operation-now-table--planner">
                                    <caption class="office-visually-hidden">Decisiones de arbitraje del ciclo operacional vigente</caption>
                                    <thead><tr><th scope="col">Orden / situación</th><th scope="col">Maniobra</th><th scope="col">Estado / progreso</th><th scope="col">Movimiento actual</th><th scope="col">Responsable</th><th scope="col">Motivo</th></tr></thead>
                                    <tbody id="plannerDecisionRows"><tr><td colspan="6"><div class="operation-now-empty">Consultando planificador…</div></td></tr></tbody>
                                </table>
                            </div>
                            {{-- Parámetros de despliegue: útiles para administración, ruido para supervisión. --}}
                            <details class="operation-now-planner__technical" id="plannerTechnical" hidden>
                                <summary>Detalle técnico</summary>
                                <dl>
                                    <div><dt>Modo</dt><dd id="plannerTechnicalMode">—</dd></div>
                                    <div><dt>Cálculo</dt><dd id="plannerCompute">—</dd></div>
                                    <div><dt>Horizonte</dt><dd id="plannerHorizon">—</dd></div>
                                </dl>
                            </details>
                        </div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--operators" aria-labelledby="operationOperatorsTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <div><h2 id="operationOperatorsTitle">Camareros activos</h2><span>Ubicación y labor vigente</span></div>
                            </div>
                            <strong class="operation-now-panel__count" id="operatorPanelCount">—</strong>
                        </header>
                        <div class="operation-now-panel__body operation-now-operator-list" id="operationOperatorList"><div class="operation-now-empty">Consultando sesiones…</div></div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--cameras" aria-labelledby="operationCamerasTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V8l8-4 8 4v12M8 20v-6h8v6M9 9h.01M15 9h.01"/></svg>
                                <div><h2 id="operationCamerasTitle">Cámaras</h2><span>Ocupación y estado ambiental</span></div>
                            </div>
                            <div class="operation-now-panel__tools"><strong class="operation-now-panel__summary" id="cameraPanelSummary">—</strong><a href="/oficina/frigorifico/camaras">Ver detalle <span aria-hidden="true">→</span></a></div>
                        </header>
                        <div class="operation-now-panel__body operation-now-table-scroll">
                            <table class="operation-now-table operation-now-table--cameras">
                                <caption class="office-visually-hidden">Ocupación y control ambiental de cámaras de producto terminado</caption>
                                <thead><tr><th scope="col">Cámara</th><th scope="col">Ocupación</th><th scope="col">T° actual</th><th scope="col">Control</th></tr></thead>
                                <tbody id="operationCameraRows"><tr><td colspan="4"><div class="operation-now-empty">Consultando cámaras…</div></td></tr></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--precooling" aria-labelledby="operationPrecoolingTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 1.5 5.5L18 4l-1.5 5.5L22 8l-4.5 3.5L22 14l-5.5-1.5L18 18l-4.5-3.5L12 22l-1.5-7.5L6 18l1.5-5.5L2 14l4.5-2.5L2 8l5.5 1.5L6 4l4.5 3.5L12 2Z"/></svg>
                                <div><h2 id="operationPrecoolingTitle">Prefrío</h2><span>Estado, progreso y producto</span></div>
                            </div>
                            <div class="operation-now-panel__tools"><strong class="operation-now-panel__summary" id="precoolingPanelSummary">—</strong><a href="/oficina/prefrio">Ver detalle <span aria-hidden="true">→</span></a></div>
                        </header>
                        <div class="operation-now-panel__body operation-now-table-scroll">
                            <table class="operation-now-table operation-now-table--precooling">
                                <caption class="office-visually-hidden">Estado, progreso temporal y producto de los túneles de Prefrío</caption>
                                <thead><tr><th scope="col">Túnel</th><th scope="col">Estado</th><th scope="col">Progreso</th><th scope="col">Producto</th></tr></thead>
                                <tbody id="operationTunnelList"><tr><td colspan="4"><div class="operation-now-empty">Consultando túneles…</div></td></tr></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--incidents" aria-labelledby="operationIncidentsTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.73 3h15.34a2 2 0 0 0 1.73-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                                <div><h2 id="operationIncidentsTitle">Incidencias abiertas</h2><span>Condiciones que requieren atención</span></div>
                            </div>
                            <div class="operation-now-panel__tools"><strong class="operation-now-panel__summary" id="incidentPanelSummary">—</strong><a href="/oficina/frigorifico/discrepancias">Revisar <span aria-hidden="true">→</span></a></div>
                        </header>
                        <div class="operation-now-panel__body operation-now-table-scroll">
                            <table class="operation-now-table operation-now-table--incidents">
                                <caption class="office-visually-hidden">Incidencias y discrepancias operacionales abiertas</caption>
                                <thead><tr><th scope="col">Antigüedad</th><th scope="col">Origen</th><th scope="col">Prioridad</th><th scope="col">Folio / contexto</th><th scope="col">Reporte</th></tr></thead>
                                <tbody id="operationIncidentRows"><tr><td colspan="5"><div class="operation-now-empty">Consultando incidencias…</div></td></tr></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--facility" aria-labelledby="operationFacilityTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-5h6v5"/></svg>
                                <div><h2 id="operationFacilityTitle">Vista de planta</h2><span id="operationFacilitySubtitle">Plano físico y estado en vivo de la planta</span></div>
                            </div>
                            <div class="operation-now-panel__tools operation-now-map-tools">
                                <span class="operation-now-map-status" id="operationFacilityStatus">Cargando plano…</span>
                                <button class="operation-now-map-button" id="operationMapZoomOut" type="button" title="Alejar plano" aria-label="Alejar plano">−</button>
                                <strong id="operationMapZoomLabel">100 %</strong>
                                <button class="operation-now-map-button" id="operationMapZoomIn" type="button" title="Acercar plano" aria-label="Acercar plano">+</button>
                                <button class="operation-now-map-action" id="operationMapExpand" type="button">Ampliar</button>
                                <button class="operation-now-map-action operation-now-map-action--primary" id="operationMapEdit" type="button" hidden>Editar plano</button>
                            </div>
                        </header>
                        <div class="operation-now-panel__body operation-now-facility plant-view">
                            <aside class="plant-legend" aria-label="Leyenda del plano">
                                <strong>Recintos</strong>
                                <span data-legend="camara">Cámara</span>
                                <span data-legend="tunel">Túnel prefrío</span>
                                <span data-legend="anden">Muelle / andén</span>
                                <span data-legend="repa">REPA</span>
                                <span data-legend="recepcion_mp">Recepción MP</span>
                                <span data-legend="materiales">Materiales</span>
                                <span data-legend="pasillo">Pasillo</span>
                                <span data-legend="no_operativo">No operativo</span>
                                <span data-legend="acceso">Acceso / puerta</span>
                                <strong>En la cámara</strong>
                                <span data-legend="equipo">Equipo móvil</span>
                                <span data-legend="personal">Personal</span>
                                <strong>Ocupación</strong>
                                <span data-legend="success">Bajo 70 %</span>
                                <span data-legend="warning">70 a 90 %</span>
                                <span data-legend="critical">Sobre 90 %</span>
                                <span data-legend="mantencion">Mantención</span>
                            </aside>
                            <div class="operation-now-map-viewport" id="operationFacilityViewport">
                                <div class="operation-now-map-stage plant-stage" id="operationFacilityMap"><div class="operation-now-empty">Preparando vista operacional…</div></div>
                            </div>
                        </div>
                    </section>

                    <section class="operation-now-panel operation-now-panel--alerts" id="operationAlertsPanel" data-tone="neutral" aria-labelledby="operationAlertsTitle">
                        <header class="operation-now-panel__heading">
                            <div class="operation-now-panel__title">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.73 3h15.34a2 2 0 0 0 1.73-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                                <div><h2 id="operationAlertsTitle">Alertas operacionales</h2><span>Condiciones verificables que requieren atención</span></div>
                            </div>
                            <strong class="operation-now-panel__count" id="operationAlertCount">—</strong>
                        </header>
                        <div class="operation-now-panel__body operation-now-alert-list" id="operationAlertRows"><div class="operation-now-empty">Evaluando alertas…</div></div>
                    </section>

                    <nav class="operation-now-shortcuts" aria-label="Accesos rápidos">
                        <strong>ACCESOS RÁPIDOS</strong>
                        <a href="/oficina/frigorifico/camaras"><span>Cámaras</span><small>Ocupación y posiciones</small><b aria-hidden="true">→</b></a>
                        <a href="/oficina/prefrio"><span>Prefrío</span><small>Procesos y túneles</small><b aria-hidden="true">→</b></a>
                        <a href="/oficina/frigorifico/despacho/estatus"><span>Estatus de despacho</span><small>Cargas vigentes</small><b aria-hidden="true">→</b></a>
                        <a href="/oficina/frigorifico/discrepancias"><span>Incidencias</span><small>Supervisión operativa</small><b aria-hidden="true">→</b></a>
                    </nav>
                </div>

                <footer class="operation-now-footer">
                    <span>Los avances de prefrío representan tiempo transcurrido, no progreso térmico.</span>
                    <span>El plano operacional conserva su geometría y superpone el estado vivo de cada recinto.</span>
                </footer>
            </div>

            <div class="operation-now-loading is-hidden" id="operationLoading" aria-hidden="true"><span aria-hidden="true"></span><strong id="operationLoadingText">Consultando la operación…</strong></div>
            <div class="toast-region" id="operationToasts" aria-live="polite"></div>

            <dialog class="operation-supervision" id="operationSupervisionDialog" aria-labelledby="operationSupervisionTitle">
                <article class="operation-supervision__surface">
                    <header class="operation-supervision__header">
                        <div><p>SUPERVISIÓN OPERACIONAL</p><h2 id="operationSupervisionTitle">Detalle de maniobra</h2><span>Explicación, evidencia física e intervenciones autorizadas</span></div>
                        <button type="button" id="operationSupervisionClose" aria-label="Cerrar detalle de maniobra">Cerrar</button>
                    </header>
                    <div class="operation-supervision__content" id="operationSupervisionContent">
                        <div class="operation-now-empty">Selecciona una maniobra para revisar su detalle.</div>
                    </div>
                </article>
            </dialog>

            <dialog class="operation-cycle-comparison operation-pin-panel" id="operationPinsDialog" aria-labelledby="operationPinsTitle">
                <article class="operation-cycle-comparison__surface">
                    <header class="operation-cycle-comparison__header" data-estiba-contrast="navy">
                        <div><p>SUPERVISIÓN DE TURNO</p><h2 id="operationPinsTitle">PIN de operadores</h2><span>Restablecer deja al operador sin PIN: lo vuelve a crear en la tablet al iniciar su próxima tarea.</span></div>
                        <button type="button" id="operationPinsClose" aria-label="Cerrar PIN de operadores">Cerrar</button>
                    </header>
                    <div class="operation-cycle-comparison__content" id="operationPinsContent">
                        <div class="operation-now-empty">Cargando operadores…</div>
                    </div>
                </article>
            </dialog>

            <dialog class="operation-cycle-comparison" id="operationCycleComparisonDialog" aria-labelledby="operationCycleComparisonTitle">
                <article class="operation-cycle-comparison__surface">
                    <header class="operation-cycle-comparison__header" data-estiba-contrast="navy">
                        <div><p>HISTORIAL DEL PLANIFICADOR</p><h2 id="operationCycleComparisonTitle">Comparar ciclos</h2><span>Qué cambió y por qué respecto de la evaluación anterior</span></div>
                        <button type="button" id="operationCycleComparisonClose" aria-label="Cerrar comparación de ciclos">Cerrar</button>
                    </header>
                    <div class="operation-cycle-comparison__content" id="operationCycleComparisonContent">
                        <div class="operation-now-empty">Selecciona comparar para revisar el historial inmediato.</div>
                    </div>
                </article>
            </dialog>

            <dialog class="operation-map-dialog" id="operationMapDialog" aria-labelledby="operationMapDialogTitle">
                <div class="operation-map-dialog__shell">
                    <header class="operation-map-dialog__header">
                        <div><p>PLANTA PRINCIPAL</p><h2 id="operationMapDialogTitle">Plano operacional</h2><span id="operationMapDialogHint">Vista ampliada de recintos y estados</span></div>
                        <div class="operation-map-dialog__actions">
                            <button class="operation-now-map-button" id="operationEditorZoomOut" type="button" aria-label="Alejar plano">−</button>
                            <strong id="operationEditorZoomLabel">100 %</strong>
                            <button class="operation-now-map-button" id="operationEditorZoomIn" type="button" aria-label="Acercar plano">+</button>
                            <button class="operation-now-map-action" id="operationMapClose" type="button">Cerrar</button>
                            <button class="operation-now-map-action operation-now-map-action--primary" id="operationMapSave" type="button" hidden>Guardar plano</button>
                        </div>
                    </header>
                    <div class="operation-map-editor" id="operationMapEditor">
                        <aside class="operation-map-catalog" id="operationMapCatalog" hidden>
                            <div class="operation-map-catalog__heading"><strong>RECINTOS DISPONIBLES</strong><span>Selecciona para incorporar</span></div>
                            <div class="operation-map-catalog__items" id="operationMapCatalogItems"></div>
                            <form class="operation-map-zone-form" id="operationMapZoneForm">
                                <strong>DIBUJAR ÁREA</strong>
                                <label>Nombre<input id="operationMapZoneName" maxlength="100" placeholder="Ej. Patio de recepción" required></label>
                                <label>Tipo<select id="operationMapZoneType"><option value="repa">REPA (buffer en vivo)</option><option value="recepcion_mp">Recepción MP (en vivo)</option><option value="materiales">Materiales</option><option value="packing">Packing</option><option value="bodega">Bodega</option><option value="pasillo">Pasillo (circulación)</option><option value="no_operativo">No operativo (sin tránsito)</option><option value="patio">Patio</option><option value="oficina">Oficina</option><option value="muelle">Muelle</option><option value="otro">Otro</option></select></label>
                                <button type="submit">Agregar área</button>
                            </form>
                            <div class="operation-map-network" id="operationMapNetworkSummary" data-tone="neutral"><strong>RED DE LA PLANTA</strong><span>Sin conexiones</span></div>
                            <div class="operation-map-help"><strong>Cómo editar</strong><span>Arrastra para mover. Usa la esquina inferior para redimensionar. Selecciona un recinto para rotarlo o quitarlo.</span><strong>Conexiones</strong><span>Selecciona un recinto o pasillo, pulsa «Conectar con…» y elige el elemento vecino. Ambos deben compartir un borde: la puerta se dibuja en ese borde.</span></div>
                        </aside>
                        <main class="operation-map-dialog__viewport" id="operationEditorViewport">
                            <div class="operation-now-map-stage operation-now-map-stage--editor plant-stage" id="operationEditorStage"></div>
                        </main>
                        <aside class="operation-map-inspector" id="operationMapInspector" hidden>
                            <strong>ELEMENTO SELECCIONADO</strong>
                            <span id="operationMapSelectionName">Ninguno</span>
                            <div><button id="operationMapRotate" type="button">Rotar 90°</button><button id="operationMapRemove" type="button">Quitar</button></div>
                            <button class="operation-map-inspector__connect" id="operationMapConnect" type="button" aria-pressed="false">Conectar con…</button>
                            <strong>CONEXIONES</strong>
                            <ul class="operation-map-inspector__connections" id="operationMapConnectionList"></ul>
                        </aside>
                    </div>
                </div>
            </dialog>
        </main>
    </body>
</html>
