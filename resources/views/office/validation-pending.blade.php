<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Pendientes por validar</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-validation-pending.css', 'resources/js/office-validation-pending.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">◷</div>
                <p class="eyebrow">FoliOS · VALIDACIÓN</p>
                <h1 id="officeAccessTitle">Lo que está en bodega y todavía no pasa por Validación.</h1>
                <p>FoliOS lee el stock del sistema de origen, solo para consultar. La validación sigue siendo la única puerta a Frigorífico.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a pendientes por validar</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="pendientes-validacion" context="FRIGORÍFICO · PT" icon="◷" />

            <section class="pending-workspace">
                <header class="pending-heading">
                    <div>
                        <p class="eyebrow">CONTROL DE INGRESO · SISTEMA DE ORIGEN</p>
                        <h1>Pendientes por validar</h1>
                        <p>Compara los pallets que el sistema de origen tiene en la bodega de la planta con lo validado en FoliOS. Nada de esta pantalla crea ni cambia folios.</p>
                    </div>
                    <div class="pending-heading__actions">
                        <button class="secondary-button" id="pendingExcelButton" type="button">↓ Excel</button>
                        <button class="primary-button" id="pendingReadButton" type="button">↻ Leer ahora</button>
                    </div>
                </header>

                <div class="pending-source" id="pendingSource" role="status">Cargando estado de la lectura…</div>
                <div class="pending-catalog-note" id="pendingCatalogNote" role="status" hidden></div>

                <div class="pending-kpis">
                    <article><span>EN STOCK DE ORIGEN</span><strong id="kpiOrigin">—</strong><small>pallets y saldos en bodega</small></article>
                    <article class="pending-kpi--ok"><span>VALIDADOS</span><strong id="kpiValidated">—</strong><small>con folio en FoliOS</small></article>
                    <article class="pending-kpi--attention"><span>PENDIENTES</span><strong id="kpiPending">—</strong><small id="kpiPendingBoxes">— cajas</small></article>
                    <article class="pending-kpi--attention"><span>POR RESOLVER</span><strong id="kpiUnresolved">—</strong><small>observados, rechazados o anulados</small></article>
                    <article class="pending-kpi--critical"><span>DIFERENCIAS DE STOCK</span><strong id="kpiDifferences">—</strong><small>comparación del stock actual</small></article>
                    <article><span>SOLO EN FOLIOS</span><strong id="kpiOnlyFolios">—</strong><small>en planta, sin stock en origen</small></article>
                </div>

                <x-office.panel-switcher
                    id="pending-validation"
                    label="Vistas de pendientes por validar"
                    default="pending"
                    :panels="[
                        'pending' => ['label' => 'Pendientes', 'icon' => '◷', 'badge_id' => 'tabPendingCount'],
                        'unresolved' => ['label' => 'Por resolver', 'icon' => '!', 'badge_id' => 'tabUnresolvedCount'],
                        'differences' => ['label' => 'Cuadraturas', 'icon' => '≠', 'badge_id' => 'tabReconciliationCount'],
                        'only-folios' => ['label' => 'Solo en FoliOS', 'icon' => '▣', 'badge_id' => 'tabOnlyFoliosCount'],
                        'catalog' => ['label' => 'Catálogo', 'icon' => '⌗', 'badge_id' => 'tabCatalogCount'],
                    ]"
                />

                <label class="pending-search"><span class="office-visually-hidden">Buscar</span><input id="pendingSearch" type="search" maxlength="60" placeholder="Buscar pallet, cliente, marca, variedad, calibre, embalaje, CSG, proceso o lote"></label>

                <section class="panel pending-panel" id="pending-validation-panel-pending" data-office-panel-group="pending-validation" data-office-panel-id="pending" role="tabpanel" aria-labelledby="pending-validation-tab-pending">
                    <div class="pending-panel__heading"><div><p class="eyebrow">SIN VALIDAR</p><h2>En bodega y sin ningún intento de validación</h2></div><small>Los más antiguos primero.</small></div>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° pallet</th><th>En bodega desde</th><th>Cajas</th><th>Artículo</th><th>CSG</th><th>Proceso · lote</th></tr></thead><tbody id="pendingBody"></tbody></table></div>
                </section>
                <section class="panel pending-panel" id="pending-validation-panel-unresolved" data-office-panel-group="pending-validation" data-office-panel-id="unresolved" role="tabpanel" aria-labelledby="pending-validation-tab-unresolved" hidden>
                    <div class="pending-panel__heading"><div><p class="eyebrow">VALIDACIÓN SIN FOLIO</p><h2>Se intentó validar, pero no quedó folio vigente</h2></div><small>El último intento manda.</small></div>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° pallet</th><th>Situación</th><th>Motivo u observación</th><th>Último intento</th><th>Cajas</th><th>Artículo</th></tr></thead><tbody id="unresolvedBody"></tbody></table></div>
                </section>
                <section class="panel pending-panel" id="pending-validation-panel-differences" data-office-panel-group="pending-validation" data-office-panel-id="differences" role="tabpanel" aria-labelledby="pending-validation-tab-differences" hidden>
                    <div class="pending-panel__heading"><div><p class="eyebrow">CUADRATURA CON PLANTA</p><h2>Diferencias de validación con planta</h2></div><small>Compara cajas, marca, calibre y cajas por CSG con el respaldo de la validación física. La cuadratura se confirma con una lectura posterior vigente.</small></div>
                    <p>La copia inicial conserva lo disponible al registrar o al incorporar el caso. Muestra la lectura disponible en FoliOS y la fecha en que se obtuvo.</p>
                    <form id="reconciliationFilters" class="reconciliation-filters">
                        <label>Folio<input name="folio" maxlength="100" autocomplete="off"></label>
                        <label>Estado<select name="estado"><option value="">Todos</option><option value="pendiente">Pendiente de cuadratura</option><option value="en_revision">En revisión</option><option value="cuadrado">Cuadrado</option><option value="reabierto">Reabierto</option><option value="por_revisar">Por revisar</option><option value="sin_diferencias">Sin diferencias</option></select></label>
                        <label>Diferencia en<select name="campo"><option value="">Cualquier campo</option><option value="cajas">Cajas</option><option value="marca">Marca</option><option value="calibre">Calibre</option><option value="csg">CSG</option></select></label>
                        <label>Validado desde<input name="desde" type="date"></label><label>Hasta<input name="hasta" type="date"></label>
                        <label><input name="otras_fuentes" type="checkbox" value="1"> Incluir fuentes anteriores</label>
                        <label><input name="incluir_anuladas" type="checkbox" value="1"> Incluir validaciones anuladas</label>
                        <button class="secondary-button">Consultar cuadraturas</button>
                    </form>
                    <div class="reconciliation-actions"><button type="button" class="secondary-button" id="reconciliationCheck" hidden>Registrar / recomprobar conciliaciones</button><button type="button" class="secondary-button" id="reconciliationExcel">Excel de cuadraturas</button></div>
                    <p id="reconciliationStatus" role="status"></p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>Folio y validador</th><th>Cuadratura</th><th>Planta ↔ validación física</th><th>Seguimiento</th></tr></thead><tbody id="reconciliationBody"></tbody></table></div>
                    <div class="reconciliation-actions" id="reconciliationPages"></div>
                    <details class="reconciliation-stock"><summary>Comparación adicional con el stock actual de FoliOS</summary>
                    <p>Incluye cambios posteriores de estado, repaletizaje y otros datos del artículo. Es independiente de la cuadratura de la validación.</p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° pallet</th><th>Estado en FoliOS</th><th>Diferencias</th><th>Artículo</th></tr></thead><tbody id="differencesBody"></tbody></table></div>
                    </details>
                </section>
                <section class="panel pending-panel" id="pending-validation-panel-only-folios" data-office-panel-group="pending-validation" data-office-panel-id="only-folios" role="tabpanel" aria-labelledby="pending-validation-tab-only-folios" hidden>
                    <div class="pending-panel__heading"><div><p class="eyebrow">SIN STOCK EN ORIGEN</p><h2>Validados en FoliOS, en planta, que el origen no tiene en bodega</h2></div><small>Puede ser un número mal digitado o un traspaso aún no registrado en el origen.</small></div>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° folio</th><th>Estado en FoliOS</th><th>Validado</th><th>Cajas</th><th>Artículo</th><th>CSG</th></tr></thead><tbody id="onlyFoliosBody"></tbody></table></div>
                </section>
                <section class="panel pending-panel" id="pending-validation-panel-catalog" data-office-panel-group="pending-validation" data-office-panel-id="catalog" role="tabpanel" aria-labelledby="pending-validation-tab-catalog" hidden>
                    <div class="pending-panel__heading"><div><p class="eyebrow">CATÁLOGO DE LA TEMPORADA</p><h2>Datos del origen que el catálogo todavía no reconoce</h2></div><small>El cliente se reconoce por cód. empresa y el embalaje por cód. artículo. Marca, variedad y calibre, tal cual. La categoría solo se sugiere.</small></div>
                    <p class="pending-catalog-help" id="catalogHelp"></p>
                    <div class="pending-table-scroll"><table class="pending-table pending-table--catalog"><thead><tr><th>Dato</th><th>Valor en origen</th><th>Contexto</th><th>Pallets</th><th>Qué hacer</th></tr></thead><tbody id="catalogBody"></tbody></table></div>
                </section>
            </section>
        </main>

        <dialog id="reconciliationDialog" aria-labelledby="reconciliationTitle"><div class="reconciliation-dialog"><div class="pending-panel__heading"><h2 id="reconciliationTitle">Conciliación</h2><button type="button" class="secondary-button" id="reconciliationClose">Cerrar</button></div><p id="reconciliationDetailNotice" role="alert"></p><div id="reconciliationDetail"></div>
            <div id="reconciliationPending" role="status" hidden><p>Hay una gestión sin respuesta confirmada. Reintenta el mismo envío.</p><button type="button" class="primary-button" id="reconciliationRetry">Reintentar gestión</button></div>
            <form id="reconciliationManagement"><label>Gestión<select name="accion"><option value="revisar">Tomar en revisión</option><option value="gestionada">Registrar corrección realizada en planta</option></select></label><label>Comentario<textarea name="comentario" minlength="5" maxlength="2000" required rows="3"></textarea></label><label>Referencia de la gestión<input name="referencia" maxlength="250"></label><p>La gestión conserva el caso en revisión. Una lectura posterior comprueba la cuadratura.</p><button class="primary-button">Guardar gestión</button></form>
            <h3>Historial</h3><div id="reconciliationHistory"></div><div class="reconciliation-actions" id="reconciliationHistoryPages"></div>
        </div></dialog>
        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
