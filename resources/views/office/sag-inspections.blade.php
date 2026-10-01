<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Inspección SAG</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-sag-inspections.css', 'resources/js/office-sag-inspections.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand sag-access-brand">
                <div class="office-logo" aria-hidden="true">SAG</div>
                <p class="eyebrow">FoliOS · FRIGORÍFICO</p>
                <h1 id="officeAccessTitle">Inspecciones y mercados con trazabilidad por pallet.</h1>
                <p>Prepara muestreos USDA, inspecciones de origen o en línea, fumigaciones y cambios de mercado sin perder las autorizaciones anteriores.</p>
                <div class="feature-row"><span>AO · AU · AF</span><span>País o bloque UE</span><span>Historial acumulativo</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Inspección SAG</h2><p>Disponible para administración, supervisión de frío y consulta.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Ingresar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="inspeccion-sag" context="FRIGORÍFICO · PT" icon="SAG" />

            <section class="sag-workspace">
                <header class="panel sag-heading">
                    <div>
                        <p class="eyebrow">CONTROL FITOSANITARIO</p>
                        <h1>Inspección SAG</h1>
                        <p>SI significa sin resolución SAG activa. AO, AU y AF se conservan y los destinos aprobados se acumulan.</p>
                    </div>
                    <button class="secondary-button" id="reloadSagButton" type="button">↻ Actualizar</button>
                </header>

                <section class="sag-metrics" aria-label="Resumen SAG">
                    <article><span>LOTES ACTIVOS</span><strong id="activeLotsMetric">0</strong></article>
                    <article><span>PALLETS EN INSPECCIÓN</span><strong id="activePalletsMetric">0</strong></article>
                    <article><span>FINALIZADOS HOY</span><strong id="finishedTodayMetric">0</strong></article>
                    <article><span>APROBACIONES HOY</span><strong id="approvalsTodayMetric">0</strong></article>
                </section>

                <nav class="sag-switcher" aria-label="Secciones de Inspección SAG">
                    <button class="is-active" data-sag-panel="resumen" type="button">Resumen</button>
                    <button data-sag-panel="nueva" type="button">Nueva inspección</button>
                    <button data-sag-panel="activos" type="button">Lotes activos</button>
                    <button data-sag-panel="historial" type="button">Historial</button>
                </nav>

                <section class="sag-panel" data-sag-section="resumen">
                    <div class="sag-summary-grid">
                        <article class="panel sag-rule-card"><p class="eyebrow">NOMENCLATURA</p><h2>Estado SAG visible</h2><div class="sag-code-grid"><span><strong>SI</strong> Sin inspección activa</span><span><strong>AO</strong> Aprobado Origen</span><span><strong>AU</strong> Aprobado USDA</span><span><strong>AF</strong> Aprobado Fumigación</span></div></article>
                        <article class="panel sag-rule-card"><p class="eyebrow">REGLA DE MERCADO</p><h2>Las aprobaciones se acumulan</h2><p>Segregar, rechazar o salir sin resolución no revoca mercados anteriores. En cambio de mercado, el destino aprobado se agrega al pallet.</p></article>
                        <article class="panel sag-rule-card"><p class="eyebrow">EXCEPCIÓN UE</p><h2>País individual o bloque completo</h2><p>Seleccionar UE cubre transversalmente a sus 27 miembros y guarda una fotografía histórica de esa composición.</p></article>
                    </div>
                    <div class="panel sag-table-panel"><div class="sag-panel-heading"><div><p class="eyebrow">OPERACIÓN ACTUAL</p><h2>Últimos lotes</h2></div></div><div class="table-scroll"><table><thead><tr><th>Lote</th><th>Tipo</th><th>Estado</th><th>Pallets</th><th>Destinos</th><th>Creación</th><th>Acción</th></tr></thead><tbody id="recentLotsBody"></tbody></table></div></div>
                </section>

                <section class="sag-panel is-hidden" data-sag-section="nueva">
                    <form class="panel sag-builder" id="sagBuilderForm">
                        <div class="sag-panel-heading"><div><p class="eyebrow">PREPARACIÓN</p><h2>Armar lote de inspección</h2><p>Cliente/exportadora y especie son obligatorios. Los filtros 3 al 7 son combinables y opcionales.</p></div></div>
                        <div class="sag-form-grid sag-form-grid--lot">
                            <label><span>Tipo de inspección *</span><select name="tipo" required><option value="">Seleccionar tipo de inspección</option></select></label>
                            <label><span>Número de inspección SAG</span><input name="numero_inspeccion_sag" maxlength="100" placeholder="Número informado por SAG"></label>
                            <label><span>Referencia de correo</span><input name="referencia_correo" maxlength="250" placeholder="Asunto, remitente o correlativo"></label>
                            <label><span>Cantidad solicitada</span><input name="cantidad_solicitada" type="number" min="1" max="1000" placeholder="Se completa con la selección"></label>
                            <label class="sag-wide"><span>Observación</span><input name="observacion" maxlength="2000" placeholder="Antecedentes de la solicitud"></label>
                        </div>

                        <fieldset class="sag-filter-fieldset"><legend>Filtros para llegar a los folios</legend><div class="sag-form-grid sag-form-grid--filters">
                            <label><span>1. Cliente / exportadora *</span><select name="cliente" required><option value="">Seleccionar</option></select></label>
                            <label><span>2. Especie *</span><select name="especie" required><option value="">Seleccionar</option></select></label>
                            <label><span>3. Variedad</span><select name="variedad" data-optional-filter disabled><option value="">Todas</option></select></label>
                            <label><span>4. Condición SAG</span><select name="condicion_sag" data-optional-filter disabled><option value="">Con o sin condición</option><option value="con">Con condición</option><option value="sin">Sin condición</option></select></label>
                            <label><span>5. CSG</span><select name="csg" data-optional-filter disabled><option value="">Todos</option></select></label>
                            <label><span>6. Fecha ingreso</span><input name="fecha_ingreso" type="date" data-optional-filter disabled></label>
                            <label><span>7. Condición térmica</span><select name="condicion_termica" data-optional-filter disabled><option value="">Todas</option></select></label>
                            <button class="secondary-button" id="searchSagFoliosButton" type="button" disabled>Buscar pallets</button>
                        </div></fieldset>

                        <div class="sag-selection-layout">
                            <section>
                                <div class="sag-panel-heading sag-panel-heading--compact"><div><p class="eyebrow">PALLETS ELEGIBLES</p><h3 id="folioSelectionTitle">Define cliente y especie o busca un folio</h3></div><span id="selectedFoliosCount">0 seleccionados</span></div>
                                <div class="sag-direct-search">
                                    <label for="singleFolioSearch"><span>Buscar pallet individual</span><small>Escribe o escanea el número de folio exacto para agregarlo sin cargar una lista masiva.</small></label>
                                    <input id="singleFolioSearch" type="search" maxlength="100" autocomplete="off" placeholder="Número de folio">
                                    <button class="secondary-button" id="searchSingleFolioButton" type="button">Buscar y agregar</button>
                                </div>
                                <div class="table-scroll sag-folio-scroll"><table><thead><tr><th></th><th>Folio</th><th>Variedad</th><th>CSG</th><th>Condición térmica</th><th>Ubicación</th><th>Estado SAG</th><th>Destinos aprobados</th></tr></thead><tbody id="eligibleFoliosBody"><tr><td colspan="8" class="empty-cell">Usa los filtros para listar pallets o busca un folio individual.</td></tr></tbody></table></div>
                            </section>
                            <aside class="sag-destinations"><div><p class="eyebrow">MERCADO DE INSPECCIÓN</p><h3>Destino país o bloque</h3><p>UE reemplaza automáticamente selecciones individuales de sus miembros.</p></div><input id="destinationSearch" type="search" placeholder="Buscar país o bloque"><div class="sag-destination-options" id="destinationOptions" role="group" aria-label="Destinos de inspección"></div><div class="sag-selected-destinations" id="selectedDestinationPills"></div><small>Marca uno o varios destinos; no necesitas mantener Ctrl/Cmd.</small></aside>
                        </div>
                        <p class="form-error" id="builderError" role="alert"></p>
                        <div class="sag-builder-actions"><span id="builderSummary">Selecciona pallets y destinos.</span><button class="primary-button" id="createSagLotButton" type="submit">Crear lote en preparación</button></div>
                    </form>
                </section>

                <section class="sag-panel is-hidden" data-sag-section="activos">
                    <div class="panel sag-table-panel"><div class="sag-panel-heading"><div><p class="eyebrow">SEGUIMIENTO</p><h2>Lotes activos</h2></div></div><div class="table-scroll"><table><thead><tr><th>Lote</th><th>Tipo</th><th>Estado</th><th>Pallets</th><th>Destinos</th><th>Responsable</th><th>Acción</th></tr></thead><tbody id="activeLotsBody"></tbody></table></div></div>
                </section>

                <section class="sag-panel is-hidden" data-sag-section="historial">
                    <div class="panel sag-table-panel"><div class="sag-panel-heading"><div><p class="eyebrow">TRAZABILIDAD</p><h2>Historial de inspecciones</h2></div></div><div class="table-scroll"><table><thead><tr><th>Lote</th><th>Tipo</th><th>Estado</th><th>Pallets</th><th>Destinos</th><th>Finalización</th><th>Acción</th></tr></thead><tbody id="historyLotsBody"></tbody></table></div></div>
                </section>

                <section class="panel sag-detail is-hidden" id="sagLotDetail">
                    <div class="sag-panel-heading"><div><p class="eyebrow">DETALLE DEL LOTE</p><h2 id="sagDetailTitle">Lote</h2><p id="sagDetailSubtitle"></p></div><div class="sag-detail-actions"><select id="detailActionSelect" aria-label="Acciones del lote"><option value="">Seleccionar acción</option></select><button class="secondary-button" id="closeSagDetailButton" type="button">Cerrar</button></div></div>
                    <div class="sag-detail-destinations" id="sagDetailDestinations"></div>
                    <div class="sag-result-list" id="sagResultList"></div>
                    <p class="form-error" id="detailError" role="alert"></p>
                </section>
            </section>
        </main>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
