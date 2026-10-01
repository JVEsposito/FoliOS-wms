<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#102f43">
        <meta name="color-scheme" content="light dark">

        <title>FoliOS · Cámaras</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-cameras.css', 'resources/js/office-cameras.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">❄</div>
                <p class="eyebrow">FoliOS · OFICINA</p>
                <h1 id="officeAccessTitle">Consulta la disponibilidad antes de preparar una carga.</h1>
                <p>Las áreas consultan ocupación y disponibilidad. La creación, estructura y desactivación de cámaras pertenecen exclusivamente a Administración.</p>
                <div class="feature-row">
                    <span>Bandas verticales</span>
                    <span>Trazabilidad</span>
                    <span>Sin código de tablet</span>
                </div>
            </div>

            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div>
                    <p class="eyebrow">ACCESO DE OFICINA</p>
                    <h2>Ingresar a cámaras</h2>
                    <p>La consulta está disponible según el área; la configuración requiere perfil administrador.</p>
                </div>
                <label>
                    <span>Correo electrónico</span>
                    <input name="email" type="email" autocomplete="username" placeholder="supervisor@empresa.cl" required>
                </label>
                <label>
                    <span>Contraseña</span>
                    <input name="password" type="password" autocomplete="current-password" placeholder="••••••••" required>
                </label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a oficina <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp" data-camera-mode="{{ $cameraMode ?? 'operacion' }}">
            
            <x-office.navigation :domain="$navigationDomain ?? 'frigorifico'" :office="$navigationOffice ?? 'camaras'" context="CÁMARAS" icon="❄" />

            <section class="camera-ops" id="cameraOperations" aria-labelledby="cameraOperationsTitle">
                <header class="camera-ops__command">
                    <div>
                        <p class="camera-ops__eyebrow">FRIGORÍFICO · CONTROL DE CÁMARAS</p>
                        <h1 id="cameraOperationsTitle">Cámaras PT</h1>
                        <p>Ocupación, bandas, afinidad y actividad física con información vigente del WMS.</p>
                    </div>
                    <div class="camera-ops__command-status" aria-live="polite">
                        <span>ESTADO DE LA VISTA</span>
                        <strong id="cameraOpsStatus">Consultando cámaras…</strong>
                        <small id="cameraOpsUpdatedAt">Última lectura: —</small>
                    </div>
                    <button class="camera-ops__refresh" id="cameraOpsRefresh" type="button">
                        <x-estiba.icon name="refresh" /> Actualizar
                    </button>
                </header>

                <div class="camera-ops__layout">
                    <aside class="camera-ops-panel camera-ops__catalog" aria-labelledby="cameraOpsCatalogTitle">
                        <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                            <div>
                                <h2 id="cameraOpsCatalogTitle">Cámaras disponibles</h2>
                                <p>Selecciona una cámara para revisar su operación.</p>
                            </div>
                            <strong class="camera-ops-panel__count" id="cameraOpsCount">—</strong>
                        </header>
                        <div class="camera-ops__camera-list" id="operationalCameraList">
                            <div class="camera-ops-empty">Consultando cámaras PT…</div>
                        </div>
                    </aside>

                    <section class="camera-ops__detail" id="cameraOpsDetail" aria-live="polite">
                        <div class="camera-ops-empty camera-ops-empty--page" id="cameraOpsEmpty">
                            <strong id="cameraOpsEmptyTitle">Selecciona una cámara</strong>
                            <span id="cameraOpsEmptyMessage">El plano, las bandas y los eventos se mostrarán aquí.</span>
                        </div>

                        <div class="camera-ops__workspace is-hidden" id="cameraOpsWorkspace">
                            <header class="camera-ops__camera-heading">
                                <div>
                                    <p class="camera-ops__eyebrow">CÁMARA SELECCIONADA</p>
                                    <h2 id="cameraOpsName">—</h2>
                                    <p id="cameraOpsMeta">Sin información operacional.</p>
                                </div>
                                <div class="camera-ops__access" id="cameraOpsAccess" data-tone="neutral">Sin consultar</div>
                            </header>

                            <section class="camera-ops__vacating" id="cameraVacatingSection" aria-live="polite">
                                <div>
                                    <strong>Desocupación programada</strong>
                                    <span>El planificador busca espacio compatible en otras cámaras; los camareros ejecutan los traslados.</span>
                                    <span id="cameraVacatingStatus">Sin desocupación programada.</span>
                                    <small>Usa Actualizar para consultar el avance.</small>
                                </div>
                                <button type="button" id="cameraVacatingStart" hidden>Vaciar cámara</button>
                                <button type="button" id="cameraVacatingCancel" hidden>Cancelar vaciado</button>
                            </section>
                            <dialog class="camera-ops__vacating-dialog" id="cameraVacatingDialog" aria-labelledby="cameraVacatingTitle">
                                <form id="cameraVacatingForm">
                                    <h3 id="cameraVacatingTitle">Vaciar cámara</h3>
                                    <p id="cameraVacatingHelp"></p>
                                    <label>Motivo obligatorio<textarea name="motivo" minlength="3" maxlength="500" required></textarea></label>
                                    <label id="cameraVacatingConfirmation">Escribe el código exacto de la cámara<input name="confirmacion" autocomplete="off" required></label>
                                    <p class="camera-ops__vacating-error" id="cameraVacatingError" role="alert"></p>
                                    <div class="camera-ops__vacating-actions">
                                        <button type="button" id="cameraVacatingClose">Volver</button>
                                        <button type="submit" id="cameraVacatingSubmit">Iniciar vaciado</button>
                                    </div>
                                </form>
                            </dialog>

                            <section class="camera-ops__emergency" id="cameraEmergency" data-tone="neutral" aria-live="polite">
                                <div id="cameraEmergencyStatus">Sin evacuación de emergencia activa.</div>
                                <button type="button" id="cameraEmergencyDeclare" hidden>Declarar evacuación de emergencia</button>
                                <button type="button" id="cameraEmergencyCancel" hidden>Cancelar emergencia</button>
                            </section>
                            <dialog class="camera-ops__emergency-dialog" id="cameraEmergencyDialog" aria-labelledby="cameraEmergencyDialogTitle">
                                <form id="cameraEmergencyForm">
                                    <h3 id="cameraEmergencyDialogTitle">Declarar evacuación de emergencia</h3>
                                    <p id="cameraEmergencyDialogHelp">La acción es inmediata.</p>
                                    <label>Motivo obligatorio<textarea name="motivo" minlength="3" maxlength="500" required></textarea></label>
                                    <label id="cameraEmergencyConfirmationLabel">Escribe el código exacto de la cámara<input name="confirmacion" autocomplete="off" required></label>
                                    <p class="camera-ops__emergency-error" id="cameraEmergencyError" role="alert"></p>
                                    <div class="camera-ops__emergency-actions">
                                        <button type="button" id="cameraEmergencyClose">Volver</button>
                                        <button type="submit" id="cameraEmergencySubmit">Confirmar emergencia</button>
                                    </div>
                                </form>
                            </dialog>

                            <section class="camera-ops__summary" aria-label="Resumen de capacidad">
                                <div><span>CAPACIDAD EFECTIVA</span><strong id="cameraOpsCapacity">—</strong></div>
                                <div><span>OCUPADAS</span><strong id="cameraOpsOccupied">—</strong></div>
                                <div><span>RESERVADAS</span><strong id="cameraOpsReserved">—</strong></div>
                                <div><span>DISPONIBLES</span><strong id="cameraOpsAvailable">—</strong></div>
                                <div><span>SIN POSICIÓN</span><strong id="cameraOpsUnpositioned">—</strong></div>
                                <div class="camera-ops__summary-meter">
                                    <span>OCUPACIÓN COMPROMETIDA</span>
                                    <strong id="cameraOpsOccupancy">—</strong>
                                    <i aria-hidden="true"><b id="cameraOpsOccupancyBar"></b></i>
                                </div>
                            </section>

                            <div class="camera-ops__grid">
                                <section class="camera-ops-panel camera-ops-panel--map" aria-labelledby="cameraBandMapTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraBandMapTitle">Plano operacional por bandas</h2><p>Fondo arriba · entrada abajo</p></div>
                                        <span class="camera-ops-panel__summary" id="cameraBandMapSummary">—</span>
                                    </header>
                                    <div class="camera-ops__band-map" id="cameraBandMap"></div>
                                </section>

                                <section class="camera-ops-panel camera-ops-panel--environment" aria-labelledby="cameraEnvironmentTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraEnvironmentTitle">Control ambiental</h2><p>Registro horario vigente</p></div>
                                        <span class="camera-ops-panel__signal" id="cameraEnvironmentStatus" data-tone="neutral">Sin consultar</span>
                                    </header>
                                    <div class="camera-ops__environment">
                                        <div class="camera-ops__temperature"><span>PROMEDIO</span><strong id="cameraTemperatureAverage">SIN REGISTRO</strong></div>
                                        <dl>
                                            <div><dt>Inicio</dt><dd id="cameraTemperatureStart">—</dd></div>
                                            <div><dt>Medio</dt><dd id="cameraTemperatureMiddle">—</dd></div>
                                            <div><dt>Fondo</dt><dd id="cameraTemperatureEnd">—</dd></div>
                                        </dl>
                                        <p id="cameraEnvironmentEvidence">No existe una lectura disponible.</p>
                                    </div>
                                </section>

                                <section class="camera-ops-panel camera-ops-panel--band-detail" aria-labelledby="cameraBandDetailTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraBandDetailTitle">Detalle de banda</h2><p>Contexto de la selección actual</p></div>
                                    </header>
                                    <div class="camera-ops__band-detail" id="cameraBandDetail">
                                        <div class="camera-ops-empty">Selecciona una banda del plano o de la tabla.</div>
                                    </div>
                                </section>

                                <section class="camera-ops-panel camera-ops-panel--bands" aria-labelledby="cameraBandTableTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraBandTableTitle">Bandas operacionales</h2><p>Estado, capacidad, uso y afinidad real</p></div>
                                    </header>
                                    <div class="camera-ops__table-scroll">
                                        <table class="camera-ops__table">
                                            <caption class="office-visually-hidden">Bandas operacionales de la cámara seleccionada</caption>
                                            <thead><tr><th>Banda</th><th>Estado</th><th>Capacidad</th><th>Usos</th><th>Afinidad</th></tr></thead>
                                            <tbody id="cameraBandRows"></tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="camera-ops-panel camera-ops-panel--maneuvers" aria-labelledby="cameraManeuversTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraManeuversTitle">Maniobras activas</h2><p>Reservas físicas vigentes</p></div>
                                        <strong class="camera-ops-panel__count" id="cameraManeuverCount">—</strong>
                                    </header>
                                    <div class="camera-ops__event-list" id="cameraManeuverList"></div>
                                </section>

                                <section class="camera-ops-panel camera-ops-panel--recent" aria-labelledby="cameraRecentTitle">
                                    <header class="camera-ops-panel__header" data-estiba-contrast="navy">
                                        <div><h2 id="cameraRecentTitle">Eventos recientes</h2><p>Movimientos registrados para esta cámara</p></div>
                                    </header>
                                    <div class="camera-ops__event-list" id="cameraRecentList"></div>
                                </section>
                            </div>
                        </div>
                    </section>
                </div>
            </section>


            <div class="configuration-module-tabs is-hidden" id="configurationModuleTabs" role="tablist" aria-label="Configuración de infraestructura">
                <button class="is-active" id="cameraModuleButton" type="button" role="tab" aria-selected="true" aria-controls="officeWorkspace">Cámaras</button>
                <button id="dockModuleButton" type="button" role="tab" aria-selected="false" aria-controls="dockWorkspace">Andenes</button>
            </div>

            <section class="office-workspace" id="officeWorkspace" role="tabpanel" aria-labelledby="cameraModuleButton">
                <aside class="camera-catalog panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow" id="cameraCatalogEyebrow">CONFIGURACIÓN</p><h2 id="cameraCatalogTitle">Cámaras creadas</h2></div>
                        <button class="icon-button" id="reloadOfficeButton" type="button" aria-label="Actualizar">↻</button>
                    </div>
                    <div class="camera-catalog__list" id="officeCameraList" aria-live="polite"></div>
                </aside>

                <section class="configuration panel">
                    <div class="configuration__heading">
                        <div>
                            <p class="eyebrow" id="configurationEyebrow">NUEVO PLANO</p>
                            <h1 id="configurationTitle">Crear cámara</h1>
                            <p id="configurationDescription">Define la estructura y revisa cada banda antes de confirmar.</p>
                        </div>
                        <div class="next-code"><span id="cameraCodeLabel">PRÓXIMO CÓDIGO</span><strong id="nextCameraCode">CAM-—</strong></div>
                    </div>

                    <form id="createCameraForm" novalidate>
                        <div class="form-grid">
                            <label class="field field--wide"><span>Nombre de la cámara *</span><input name="nombre" maxlength="150" placeholder="Ej. Cámara de tránsito norte" required></label>
                            <label class="field"><span>Tipo *</span><select name="tipo" required><option value="transito">Tránsito</option><option value="almacenaje">Almacenaje</option><option value="preparacion">Preparación</option><option value="despacho">Despacho</option></select></label>
                            <label class="field"><span>Contenido permitido *</span><select name="contenido" required><option value="productos">Producto terminado</option><option value="materiales">Materiales</option><option value="materia_prima">Materia prima</option></select></label>
                            <label class="field"><span>Bandas *</span><input name="bandas" type="number" min="1" max="40" value="3" required></label>
                            <label class="field"><span>Posiciones por banda *</span><input name="posiciones_por_banda" type="number" min="1" max="40" value="4" required></label>
                            <label class="field"><span>Niveles *</span><input name="niveles" type="number" min="1" max="10" value="2" required></label>
                            <label class="field"><span>Numeración de bandas *</span><select name="sentido_numeracion_bandas" required><option value="izquierda_a_derecha">Izquierda → derecha (B01 a la izquierda)</option><option value="derecha_a_izquierda">Derecha → izquierda (B01 a la derecha)</option></select></label>
                        </div>

                        <div class="capacity-summary">
                            <span><strong id="previewCapacity">24</strong> posiciones totales</span>
                            <span><strong id="previewActive">24</strong> operativas</span>
                            <span><strong id="previewDisabled">0</strong> fuera de servicio</span>
                        </div>

                        <div class="preview-heading">
                            <div><p class="eyebrow">VISTA PREVIA</p><h2>Bandas verticales</h2></div>
                            <div class="level-tabs" id="previewLevelTabs"></div>
                        </div>
                        <div class="orientation"><strong>↑ FONDO</strong><span><b id="previewBandDirection">B01 a la izquierda.</b> Haz clic sobre una posición para marcarla fuera de servicio.</span></div>
                        <div class="camera-preview" id="cameraPreview"></div>
                        <div class="orientation orientation--entrance"><strong>↓ ENTRADA</strong><span>La posición P01 se ocupa primero.</span></div>

                        <p class="form-error" id="createCameraError" role="alert"></p>
                        <div class="form-actions">
                            <button class="danger-button is-hidden" id="deactivateCameraButton" type="button">Desactivar cámara</button>
                            <button class="secondary-button is-hidden" id="cancelEditCameraButton" type="button">Cancelar edición</button>
                            <button class="secondary-button" id="resetCameraButton" type="button">Restablecer plano</button>
                            <button class="primary-button" id="saveCameraButton" type="submit"><span id="saveCameraButtonText">Crear cámara y posiciones</span> <span>→</span></button>
                        </div>
                    </form>
                </section>
            </section>

            <section class="office-workspace is-hidden" id="dockWorkspace" role="tabpanel" aria-labelledby="dockModuleButton">
                <aside class="camera-catalog panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">DESPACHO</p><h2>Andenes creados</h2></div>
                        <button class="icon-button" id="reloadDocksButton" type="button" aria-label="Actualizar andenes">↻</button>
                    </div>
                    <div class="camera-catalog__list" id="officeDockList" aria-live="polite"></div>
                </aside>

                <section class="configuration panel" id="dockConfiguration">
                    <div class="configuration__heading">
                        <div>
                            <p class="eyebrow" id="dockFormEyebrow">NUEVO ANDÉN</p>
                            <h1 id="dockFormTitle">Crear andén</h1>
                            <p id="dockFormDescription">Registra los puntos físicos donde se concentran y despachan las cargas.</p>
                        </div>
                        <div class="next-code"><span>CÓDIGO SUGERIDO</span><strong id="nextDockCode">AND-01</strong></div>
                    </div>

                    <form id="dockForm" novalidate>
                        <div class="dock-form-grid">
                            <label class="field"><span>Código *</span><input name="codigo" maxlength="30" placeholder="AND-01" pattern="[A-Za-z0-9-]+" required></label>
                            <label class="field"><span>Nombre del andén *</span><input name="nombre" maxlength="100" placeholder="Ej. Andén principal" required></label>
                            <label class="field"><span>Código externo</span><input name="codigo_externo" maxlength="100" placeholder="Opcional: ERP-AND-01"></label>
                        </div>

                        <label class="dock-status-toggle">
                            <input name="activo" type="checkbox" checked>
                            <span><strong>Andén activo</strong><small>Los andenes inactivos conservan su historial, pero no aparecen al preparar o despachar cargas.</small></span>
                        </label>

                        <p class="form-error" id="dockFormError" role="alert"></p>
                        <div class="form-actions">
                            <button class="secondary-button is-hidden" id="cancelEditDockButton" type="button">Cancelar edición</button>
                            <button class="primary-button" id="saveDockButton" type="submit"><span id="saveDockButtonText">Crear andén</span> <span>→</span></button>
                        </div>
                    </form>
                </section>
            </section>
        </main>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
