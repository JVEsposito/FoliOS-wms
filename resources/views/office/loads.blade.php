<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">

        <title>FoliOS · Órdenes de carga</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-loads.css', 'resources/js/office-loads.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand loads-access-brand">
                <div class="office-logo" aria-hidden="true">⇥</div>
                <p class="eyebrow">FoliOS · DESPACHO · CARGAS</p>
                <h1 id="officeAccessTitle">Prepara la carga antes de mover un solo pallet.</h1>
                <p>Asigna folios, define prioridades y publica órdenes para que la operación trabaje con una referencia única y trazable.</p>
                <div class="feature-row">
                    <span>Máximo 26 folios</span>
                    <span>Control de versiones</span>
                    <span>Distribución por cámara</span>
                </div>
            </div>

            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div>
                    <p class="eyebrow">ACCESO DE OFICINA</p>
                    <h2>Ingresar a cargas</h2>
                    <p>Disponible para despachadores, supervisores y administradores.</p>
                </div>
                <label>
                    <span>Correo electrónico</span>
                    <input name="email" type="email" autocomplete="username" placeholder="despachador@empresa.cl" required>
                </label>
                <label>
                    <span>Contraseña</span>
                    <input name="password" type="password" autocomplete="current-password" placeholder="••••••••" required>
                </label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a cargas <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            
            <x-office.navigation domain="frigorifico" office="cargas" context="FRIGORÍFICO · PT" icon="▤" />


            <section class="loads-workspace">
                <aside class="load-catalog panel">
                    <div class="panel-heading load-catalog__heading">
                        <div>
                            <p class="eyebrow">DESPACHO</p>
                            <h2>Órdenes de carga</h2>
                        </div>
                        <div class="catalog-actions">
                            <button class="icon-button" id="reloadLoadsButton" type="button" aria-label="Actualizar órdenes">↻</button>
                            <button class="secondary-button compact-button is-hidden" id="directDispatchButton" type="button" title="Registrar salida directa desde Prefrío">⇥ Prefrío</button>
                            <button class="primary-button compact-button" id="newLoadButton" type="button">+ Nueva</button>
                        </div>
                    </div>

                    <div class="load-filters">
                        <label>
                            <span>Buscar</span>
                            <input id="loadSearch" type="search" maxlength="100" placeholder="Código u orden externa">
                        </label>
                        <label>
                            <span>Estado</span>
                            <select id="loadStatusFilter">
                                <option value="">Todos</option>
                                <option value="borrador">Borrador</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="en_preparacion">En preparación</option>
                                <option value="despacho_parcial">Despacho parcial</option>
                                <option value="en_separacion">En separación</option>
                                <option value="separada">Separada</option>
                                <option value="separacion_completa">Separación completa</option>
                                <option value="despachada">En andén</option>
                                <option value="cerrada">Cerrada</option>
                                <option value="cancelada">Cancelada</option>
                            </select>
                        </label>
                        <label class="incident-filter">
                            <input id="loadIncidentFilter" type="checkbox">
                            <span>Solo con incidencias abiertas</span>
                        </label>
                    </div>

                    <div class="load-catalog__status">
                        <div class="load-catalog__summary" id="loadCatalogSummary" role="status" aria-live="polite">0 órdenes</div>
                        <label class="page-size-control">
                            <span>Mostrar</span>
                            <select id="loadPageSize" aria-label="Órdenes por página">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                            </select>
                        </label>
                    </div>
                    <div class="load-catalog__list" id="loadList" aria-label="Listado de órdenes de carga"></div>
                    <div class="pagination" id="loadPagination" aria-label="Paginación de órdenes"></div>
                </aside>

                <section class="load-editor panel">
                    <div class="load-empty" id="loadEmptyState">
                        <span aria-hidden="true">⇥</span>
                        <p class="eyebrow">OFICINA DEL DESPACHADOR</p>
                        <h1>Selecciona una orden o crea un nuevo borrador.</h1>
                        <p>Los borradores pueden guardarse sin folios. La orden solo será visible para la operación cuando la publiques.</p>
                        <button class="primary-button" id="emptyNewLoadButton" type="button">Crear primera orden <span>→</span></button>
                    </div>

                    <div class="is-hidden" id="loadEditorContent">
                        <header class="load-editor__heading">
                            <div>
                                <p class="eyebrow" id="loadEditorEyebrow">NUEVA ORDEN</p>
                                <div class="load-title-row">
                                    <h1 id="loadEditorTitle">Nuevo borrador</h1>
                                    <span class="status-badge status-badge--draft" id="loadStatusBadge">Sin guardar</span>
                                    <span class="priority-badge is-hidden" id="loadPriorityBadge"></span>
                                </div>
                                <p id="loadEditorDescription">Crea el encabezado y luego incorpora los folios.</p>
                            </div>
                            <div class="load-audit is-hidden" id="loadAudit">
                                <span>ACTUALIZACIÓN</span>
                                <strong id="loadVersion">—</strong>
                                <small id="loadUpdatedAt">Sin guardar</small>
                            </div>
                        </header>

                        <form class="load-header-form" id="loadHeaderForm" novalidate>
                            <div class="load-form-grid">
                                <label class="field">
                                    <span id="externalOrderLabel">Número de orden externa</span>
                                    <input name="numero_orden_externa" maxlength="100" placeholder="Ej. OC-2026-001">
                                    <small id="externalOrderHelp">Referencia informada por el cliente.</small>
                                </label>
                                <label class="field">
                                    <span>Prioridad</span>
                                    <select name="prioridad">
                                        <option value="normal">Normal</option>
                                        <option value="alta">Alta</option>
                                        <option value="urgente">Urgente</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Cámara objetivo</span>
                                    <select name="camara_objetivo_id" id="targetCameraSelect">
                                        <option value="">Sin cámara objetivo</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Andén previsto</span>
                                    <select name="anden_previsto_id" id="targetDockSelect">
                                        <option value="">Sin andén previsto</option>
                                    </select>
                                </label>
                                <label class="field field--full">
                                    <span>Observación operacional</span>
                                    <textarea name="observacion" maxlength="1000" rows="3" placeholder="Indicaciones para la preparación de esta carga"></textarea>
                                </label>
                            </div>
                            <p class="form-error" id="loadHeaderError" role="alert"></p>
                            <div class="load-header-actions">
                                <button class="secondary-button is-hidden" id="discardNewLoadButton" type="button">Descartar</button>
                                <button class="primary-button" id="saveLoadButton" type="submit"><span id="saveLoadButtonText">Crear borrador</span> <span>→</span></button>
                            </div>
                        </form>

                        <section class="load-operation is-hidden" id="loadOperation">
                            <div class="load-metrics">
                                <article><span>FOLIOS ASIGNADOS</span><strong id="loadTotalFolios">0 / 26</strong></article>
                                <article><span>CÁMARAS INVOLUCRADAS</span><strong id="loadTotalCameras">0</strong></article>
                                <article><span>CONCENTRACIÓN</span><strong id="loadConcentration">0%</strong></article>
                                <article><span>EN ANDÉN</span><strong id="loadAtDock">0</strong></article>
                                <article><span>INCIDENCIAS ABIERTAS</span><strong id="loadIncidentCount">0</strong></article>
                            </div>

                            <section class="truck-dock-panel is-hidden" id="truckDockPanel" aria-live="polite">
                                <div class="truck-dock-panel__icon" aria-hidden="true">🚚</div>
                                <div>
                                    <p class="eyebrow">PRIORIDAD DE DESPACHO DIRECTO</p>
                                    <h2 id="truckDockTitle">Camión presente en andén</h2>
                                    <p id="truckDockDetail">El WMS priorizará los pallets completos de esta carga hacia el andén.</p>
                                </div>
                                <span class="truck-dock-panel__status">EN ANDÉN</span>
                            </section>

                            <section class="concentration-panel" aria-labelledby="concentrationTitle">
                                <div class="concentration-panel__summary">
                                    <p class="eyebrow">SEGUIMIENTO FÍSICO</p>
                                    <h2 id="concentrationTitle">Concentración de la carga</h2>
                                    <p id="loadConcentrationMessage">La concentración se calcula con posiciones correlativas y bandas consecutivas.</p>
                                </div>
                                <div class="concentration-panel__progress">
                                    <div class="concentration-progress" aria-hidden="true"><span id="loadConcentrationBar"></span><i></i></div>
                                    <div><strong id="loadConcentratedFolios">0 de 0 concentrados</strong><span id="loadMainCluster">Sin grupo principal</span></div>
                                </div>
                            </section>

                            <div class="distribution-block">
                                <div>
                                    <p class="eyebrow">DISTRIBUCIÓN ACTUAL</p>
                                    <h2>Folios por cámara</h2>
                                </div>
                                <div class="distribution-list" id="loadDistribution"></div>
                            </div>

                            <div class="folio-add" id="folioAddSection">
                                <div>
                                    <p class="eyebrow">INCORPORACIÓN MASIVA</p>
                                    <h2 id="folioAddTitle">Agregar folios</h2>
                                    <p id="folioAddHelp">Pega o escanea uno o varios folios, separados por salto de línea, coma o punto y coma. El lote completo se rechaza si uno presenta problemas.</p>
                                </div>
                                <div class="folio-add__controls">
                                    <textarea id="folioInput" rows="5" aria-labelledby="folioAddTitle" aria-describedby="folioAddHelp folioInputCount" placeholder="FOLIO-001&#10;FOLIO-002&#10;FOLIO-003"></textarea>
                                    <div class="folio-add__footer">
                                        <span id="folioInputCount" aria-live="polite">0 folios únicos detectados</span>
                                        <button class="primary-button" id="addFoliosButton" type="button">Agregar a la orden</button>
                                    </div>
                                </div>
                                <section class="available-folios" aria-labelledby="availableFoliosTitle">
                                    <div class="available-folios__heading">
                                        <div>
                                            <p class="eyebrow">EXISTENCIA DISPONIBLE</p>
                                            <h3 id="availableFoliosTitle">Seleccionar desde inventario</h3>
                                        </div>
                                        <div class="available-folios__tools">
                                            <label>
                                                <span class="sr-only">Buscar folio disponible</span>
                                                <input id="availableFolioSearch" type="search" maxlength="100" placeholder="Folio, variedad, calibre, marca, cámara o posición">
                                            </label>
                                            <label class="page-size-control">
                                                <span>Mostrar</span>
                                                <select id="availableFolioPageSize" aria-label="Folios por página">
                                                    <option value="10" selected>10</option>
                                                    <option value="25">25</option>
                                                    <option value="50">50</option>
                                                </select>
                                            </label>
                                            <button class="icon-button" id="reloadAvailableFoliosButton" type="button" aria-label="Actualizar folios disponibles">↻</button>
                                        </div>
                                    </div>
                                    <p class="available-folios__summary" id="availableFolioSummary" role="status" aria-live="polite">Cargando existencia…</p>
                                    <div class="available-folios__table-scroll" id="availableFolioList">
                                        <table class="available-folios__table">
                                            <thead>
                                                <tr>
                                                    <th class="available-folios__check">
                                                        <input id="availableFolioSelectPage" type="checkbox" aria-label="Seleccionar todos los folios de esta página">
                                                    </th>
                                                    <th>Folio</th>
                                                    <th>Tipo</th>
                                                    <th>Variedad / calibre</th>
                                                    <th>Marca / exportadora</th>
                                                    <th>Condición SAG</th>
                                                    <th>Cámara</th>
                                                    <th>Posición</th>
                                                    <th>Ingreso</th>
                                                </tr>
                                            </thead>
                                            <tbody id="availableFolioTableBody"></tbody>
                                        </table>
                                    </div>
                                    <div class="available-folios__footer">
                                        <span>La selección se conserva al cambiar de página.</span>
                                        <div class="pagination" id="availableFolioPagination" aria-label="Paginación de folios disponibles"></div>
                                    </div>
                                </section>
                                <div class="folio-errors is-hidden" id="folioErrors" role="alert"></div>
                            </div>

                            <div class="folio-table-block">
                                <div class="folio-table-heading">
                                    <div>
                                        <p class="eyebrow">DETALLE DE LA ORDEN</p>
                                        <h2>Folios asignados</h2>
                                    </div>
                                    <span id="folioTableHint">El borrador todavía no es visible para el camarero.</span>
                                </div>
                                <div class="table-scroll">
                                    <table class="folio-table">
                                        <thead>
                                            <tr>
                                                <th>Folio</th>
                                                <th>Tipo</th>
                                                <th>Estado en carga</th>
                                                <th>Ubicación actual</th>
                                                <th>Asignado</th>
                                                <th><span class="sr-only">Acciones</span></th>
                                            </tr>
                                        </thead>
                                        <tbody id="folioTableBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <section class="load-incidents" id="loadIncidentsSection" aria-labelledby="loadIncidentsTitle">
                                <div class="load-incidents__heading">
                                    <div>
                                        <p class="eyebrow">ALERTAS DE TERRENO</p>
                                        <h2 id="loadIncidentsTitle">Incidencias de la carga</h2>
                                    </div>
                                    <span id="loadIncidentsSummary">Sin incidencias registradas</span>
                                </div>
                                <div class="load-incidents__list" id="loadIncidentsList"></div>
                            </section>

                            <footer class="load-command-bar" id="loadCommandBar">
                                <div>
                                    <strong id="loadCommandTitle">Orden en borrador</strong>
                                    <span id="loadCommandDescription">Publica la orden cuando la asignación esté lista.</span>
                                </div>
                                <div>
                                    <button class="danger-button" id="cancelLoadButton" type="button">Cancelar orden</button>
                                    <button class="secondary-button is-hidden" id="truckDockButton" type="button">Camión en andén</button>
                                    <button class="primary-button is-hidden" id="closeDispatchButton" type="button">Confirmar salida del camión <span>→</span></button>
                                    <button class="primary-button" id="publishLoadButton" type="button">Publicar para operación <span>→</span></button>
                                </div>
                            </footer>
                        </section>
                    </div>
                </section>
            </section>
        </main>

        <dialog class="office-dialog" id="incidentResolutionDialog" aria-labelledby="incidentResolutionTitle">
            <form id="incidentResolutionForm" method="dialog" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">RESOLUCIÓN COMERCIAL</p>
                        <h2 id="incidentResolutionTitle">Resolver incidencia</h2>
                        <p id="incidentResolutionContext">Selecciona cómo continuará el folio.</p>
                    </div>
                    <button class="dialog-close" data-close-dialog type="button" aria-label="Cerrar">×</button>
                </header>
                <fieldset class="resolution-options">
                    <legend>Tipo de resolución</legend>
                    <label><input name="resolucion" type="radio" value="despacho_parcial" required><span><strong>A · Despacho parcial</strong><small>Retira el folio afectado y libera su reserva.</small></span></label>
                    <label><input name="resolucion" type="radio" value="reemplazo"><span><strong>B · Reemplazar folio</strong><small>Selecciona otro folio con especificaciones equivalentes.</small></span></label>
                    <label><input name="resolucion" type="radio" value="reparado"><span><strong>C · Incidencia resuelta</strong><small>El folio vuelve a la cola de extracción.</small></span></label>
                </fieldset>
                <section class="replacement-picker is-hidden" id="replacementPicker" aria-labelledby="replacementPickerTitle">
                    <div>
                        <strong id="replacementPickerTitle">Folios equivalentes disponibles</strong>
                        <input id="replacementSearch" type="search" maxlength="100" placeholder="Buscar folio o ubicación">
                    </div>
                    <p id="replacementSummary">Selecciona un reemplazo.</p>
                    <div class="replacement-list" id="replacementList"></div>
                </section>
                <label class="field">
                    <span>Observación</span>
                    <textarea name="observacion" maxlength="1000" rows="3" placeholder="Acuerdo con cliente o detalle de la resolución"></textarea>
                </label>
                <p class="form-error" id="incidentResolutionError" role="alert"></p>
                <footer>
                    <button class="secondary-button" data-close-dialog type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Confirmar resolución <span>→</span></button>
                </footer>
            </form>
        </dialog>

        <dialog class="office-dialog" id="closeDispatchDialog" aria-labelledby="closeDispatchTitle">
            <form id="closeDispatchForm" method="dialog" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">SALIDA DOCUMENTAL</p>
                        <h2 id="closeDispatchTitle">Confirmar salida del camión</h2>
                        <p id="closeDispatchContext">Todos los folios deben encontrarse en andén.</p>
                    </div>
                    <button class="dialog-close" data-close-dialog type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="dialog-grid">
                    <label class="field"><span>Patente *</span><input name="patente" maxlength="20" autocomplete="off" required placeholder="ABCD12"></label>
                    <label class="field"><span>Conductor *</span><input name="conductor" maxlength="150" required placeholder="Nombre completo"></label>
                    <label class="field field--full"><span>Fecha y hora física de salida *</span><input name="ocurrido_at" type="datetime-local" required></label>
                    <label class="field field--full"><span>Observación de salida</span><textarea name="observacion" maxlength="1000" rows="3"></textarea></label>
                </div>
                <p class="form-error" id="closeDispatchError" role="alert"></p>
                <footer>
                    <button class="secondary-button" data-close-dialog type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Cerrar despacho <span>→</span></button>
                </footer>
            </form>
        </dialog>

        <dialog class="office-dialog" id="truckDockDialog" aria-labelledby="truckDockDialogTitle">
            <form id="truckDockForm" method="dialog" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">PRESENCIA FÍSICA</p>
                        <h2 id="truckDockDialogTitle">Registrar camión en andén</h2>
                        <p>Activa la prioridad crítica: los pallets completos saldrán directamente al andén cuando estén accesibles.</p>
                    </div>
                    <button class="dialog-close" data-close-dialog type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="dialog-grid">
                    <label class="field field--full"><span>Andén *</span><select id="truckDockSelect" name="anden_id" required><option value="">Selecciona un andén disponible</option></select></label>
                    <label class="field"><span>Patente *</span><input name="patente" maxlength="20" autocomplete="off" required placeholder="ABCD12"></label>
                    <label class="field"><span>Conductor</span><input name="conductor" maxlength="150" placeholder="Opcional"></label>
                    <label class="field field--full"><span>Fecha y hora de llegada *</span><input name="ingresada_at" type="datetime-local" required></label>
                    <label class="field field--full"><span>Observación</span><textarea name="observacion" maxlength="1000" rows="3" placeholder="Ej.: camión listo para iniciar carga"></textarea></label>
                </div>
                <p class="form-error" id="truckDockError" role="alert"></p>
                <footer>
                    <button class="secondary-button" data-close-dialog type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Confirmar presencia <span>→</span></button>
                </footer>
            </form>
        </dialog>

        <dialog class="office-dialog" id="releaseDockDialog" aria-labelledby="releaseDockDialogTitle">
            <form id="releaseDockForm" method="dialog" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">LIBERAR ANDÉN</p>
                        <h2 id="releaseDockDialogTitle">Finalizar presencia del camión</h2>
                        <p id="releaseDockContext">La prioridad de despacho directo dejará de generar movimientos.</p>
                    </div>
                    <button class="dialog-close" data-close-dialog type="button" aria-label="Cerrar">×</button>
                </header>
                <label class="field">
                    <span>Motivo *</span>
                    <textarea name="motivo" maxlength="1000" rows="4" required placeholder="Ej.: camión retirado del andén"></textarea>
                </label>
                <p class="form-error" id="releaseDockError" role="alert"></p>
                <footer>
                    <button class="secondary-button" data-close-dialog type="button">Cancelar</button>
                    <button class="danger-button" type="submit">Liberar andén</button>
                </footer>
            </form>
        </dialog>

        <dialog class="office-dialog office-dialog--wide" id="directDispatchDialog" aria-labelledby="directDispatchTitle">
            <form id="directDispatchForm" method="dialog" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">PREFRÍO → DESPACHO / ANDÉN</p>
                        <h2 id="directDispatchTitle">Registrar salida directa desde Prefrío</h2>
                        <p>Para folios aprobados que salieron sin ubicación en cámara. No se crearán ubicaciones ni movimientos ficticios.</p>
                    </div>
                    <button class="dialog-close" data-close-dialog type="button" aria-label="Cerrar">×</button>
                </header>

                <div class="direct-dispatch-layout">
                    <section class="direct-dispatch-data" aria-label="Datos del despacho">
                        <div class="dialog-grid">
                            <label class="field"><span>Orden externa</span><input name="numero_orden_externa" maxlength="100" placeholder="Opcional"></label>
                            <label class="field"><span>Prioridad *</span><select name="prioridad" required><option value="normal">Normal</option><option value="alta">Alta</option><option value="urgente">Urgente</option></select></label>
                            <label class="field"><span>Andén *</span><select id="directDockSelect" name="anden_id" required><option value="">Selecciona un andén</option></select></label>
                            <label class="field"><span>Patente *</span><input name="patente" maxlength="20" autocomplete="off" required placeholder="ABCD12"></label>
                            <label class="field"><span>Conductor *</span><input name="conductor" maxlength="150" required placeholder="Nombre completo"></label>
                            <label class="field"><span>Fecha y hora física de salida *</span><input name="ocurrido_at" type="datetime-local" required></label>
                            <label class="field field--full"><span>Observación</span><textarea name="observacion" maxlength="1000" rows="3" placeholder="Ej.: despacho físico ya realizado"></textarea></label>
                        </div>
                    </section>

                    <section class="direct-folio-picker" aria-labelledby="directFoliosTitle">
                        <div class="direct-folio-picker__heading">
                            <div>
                                <strong id="directFoliosTitle">Folios aprobados en Prefrío y sin ubicación</strong>
                                <small id="directSelectionSummary">0 seleccionados · máximo 26</small>
                            </div>
                            <input id="directFolioSearch" type="search" maxlength="100" placeholder="Buscar folio, variedad, marca o SAG">
                        </div>
                        <label class="field">
                            <span>Folios a despachar *</span>
                            <textarea id="directFoliosInput" name="folios" rows="3" required placeholder="Escribe o pega folios separados por coma, espacio o salto de línea"></textarea>
                            <small>También puedes marcarlos en la lista.</small>
                        </label>
                        <div class="direct-folio-toolbar">
                            <label><input id="directSelectPage" type="checkbox"> Seleccionar página</label>
                            <span id="directCandidateSummary">Cargando folios…</span>
                        </div>
                        <div class="direct-folio-list" id="directFolioList"></div>
                        <div class="pagination" id="directFolioPagination" aria-label="Paginación de folios"></div>
                    </section>
                </div>

                <p class="form-error" id="directDispatchError" role="alert"></p>
                <footer>
                    <button class="secondary-button" data-close-dialog type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Registrar salida directa <span>→</span></button>
                </footer>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
