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

        <main class="office-app is-hidden" id="officeApp" data-folios-workspace="operacion">
            <x-office.navigation domain="frigorifico" office="pendientes-validacion" context="FRIGORÍFICO · PT" icon="◷" />

            <section class="pending-workspace">
                <x-folios.cabecera-etiqueta area="frigorifico" seccion="Frigorífico · Validación PT"
                    titulo="Pendientes por validar" codigo="PT · STOCK DE ORIGEN" class="pending-heading"
                    descripcion="Compara el stock del sistema de origen con las validaciones registradas en FoliOS.">
                    <div class="pending-heading__actions">
                        <button class="secondary-button" id="pendingExcelButton" type="button">↓ Excel</button>
                        <button class="primary-button" id="pendingReadButton" type="button">↻ Leer ahora</button>
                    </div>
                </x-folios.cabecera-etiqueta>

                <div class="pending-source" id="pendingSyncStatus" role="status" aria-live="polite"></div>
                <div class="pending-source" id="pendingSource" role="status">Cargando estado de la lectura…</div>
                <div class="pending-catalog-note" id="pendingCatalogNote" role="status" hidden></div>

                <div class="pending-kpis folios-indicadores" aria-label="Resumen de validación PT">
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="En stock de origen" valor-id="kpiOrigin" valor="—" detalle="Pallets y saldos en bodega" />
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="Validados" valor-id="kpiValidated" valor="—" />
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="Sin intento" valor-id="kpiPending" valor="—"><small id="kpiPendingBoxes">— cajas</small></x-folios.ticket-indicador>
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="Por resolver" valor-id="kpiUnresolved" valor="—" detalle="Observados, rechazados o anulados" />
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="Diferencias de stock" valor-id="kpiDifferences" valor="—" />
                    <x-folios.ticket-indicador area="frigorifico" clave="PT" rotulo="Solo en FoliOS" valor-id="kpiOnlyFolios" valor="—" detalle="En planta, sin stock en origen" />
                </div>

                <x-office.panel-switcher
                    id="pending-validation"
                    label="Vistas de pendientes por validar"
                    default="pending"
                    :panels="[
                        'pending' => ['label' => 'Sin aprobar', 'icon' => '◷', 'badge_id' => 'tabPendingCount'],
                        'unresolved' => ['label' => 'Por resolver', 'icon' => '!', 'badge_id' => 'tabUnresolvedCount'],
                        'differences' => ['label' => 'Cuadraturas', 'icon' => '≠', 'badge_id' => 'tabReconciliationCount'],
                        'only-folios' => ['label' => 'Solo en FoliOS', 'icon' => '▣', 'badge_id' => 'tabOnlyFoliosCount'],
                        'catalog' => ['label' => 'Catálogo', 'icon' => '⌗', 'badge_id' => 'tabCatalogCount'],
                    ]"
                />

                <label class="pending-search"><span class="office-visually-hidden">Buscar</span><input id="pendingSearch" type="search" maxlength="60" placeholder="Buscar pallet, cliente, marca, variedad, calibre, embalaje, CSG, proceso o lote"></label>

                <x-folios.bandeja area="frigorifico" titulo="Pallets y saldos sin aprobación vigente" id="pending-pending-title"
                    panel-id="pending-validation-panel-pending" data-office-panel-group="pending-validation" data-office-panel-id="pending" role="tabpanel">
                    <p class="pending-panel-note">Incluye turnos anteriores en el stock de origen. Los más antiguos primero.</p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>Folio</th><th>En bodega desde</th><th>Estado</th><th>Cajas</th><th>Artículo</th><th>Composición <small>CSG · lote · proceso · cajas</small></th></tr></thead><tbody id="pendingBody"><tr><td class="pending-empty-cell" colspan="6">Cargando bandeja…</td></tr></tbody></table></div>
                </x-folios.bandeja>
                <x-folios.bandeja area="frigorifico" titulo="Validaciones sin folio vigente" id="pending-unresolved-title"
                    panel-id="pending-validation-panel-unresolved" data-office-panel-group="pending-validation" data-office-panel-id="unresolved" role="tabpanel" hidden>
                    <p class="pending-panel-note">Se muestra el último intento.</p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° pallet</th><th>Situación</th><th>Motivo u observación</th><th>Último intento</th><th>Cajas</th><th>Artículo</th></tr></thead><tbody id="unresolvedBody"><tr><td class="pending-empty-cell" colspan="6">Cargando bandeja…</td></tr></tbody></table></div>
                </x-folios.bandeja>
                <x-folios.bandeja area="frigorifico" titulo="Diferencias de validación con planta" id="pending-differences-title"
                    panel-id="pending-validation-panel-differences" data-office-panel-group="pending-validation" data-office-panel-id="differences" role="tabpanel" hidden>
                    <p class="pending-panel-note">Una lectura posterior confirma la cuadratura.</p>
                    <p>La copia inicial conserva lo disponible al registrar o al incorporar el caso. Muestra la lectura disponible en FoliOS y la fecha en que se obtuvo.</p>
                    <form id="reconciliationFilters" class="reconciliation-filters" data-folios-toolbar>
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
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>Folio y validador</th><th>Cuadratura</th><th>Planta ↔ validación física</th><th>Seguimiento</th></tr></thead><tbody id="reconciliationBody"><tr><td class="pending-empty-cell" colspan="4">Cargando bandeja…</td></tr></tbody></table></div>
                    <div class="reconciliation-actions" id="reconciliationPages"></div>
                    <details class="reconciliation-stock"><summary>Comparación adicional con el stock actual de FoliOS</summary>
                    <p>Incluye cambios posteriores de estado, repaletizaje y otros datos del artículo. Es independiente de la cuadratura de la validación.</p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° pallet</th><th>Estado en FoliOS</th><th>Diferencias</th><th>Artículo</th></tr></thead><tbody id="differencesBody"><tr><td class="pending-empty-cell" colspan="4">Cargando bandeja…</td></tr></tbody></table></div>
                    </details>
                </x-folios.bandeja>
                <x-folios.bandeja area="frigorifico" titulo="Validados sin stock en origen" id="pending-only-folios-title"
                    panel-id="pending-validation-panel-only-folios" data-office-panel-group="pending-validation" data-office-panel-id="only-folios" role="tabpanel" hidden>
                    <p class="pending-panel-note">Puede ser un número mal digitado o un traspaso aún no registrado.</p>
                    <div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>N° folio</th><th>Estado en FoliOS</th><th>Validado</th><th>Cajas</th><th>Artículo</th><th>Composición validada</th></tr></thead><tbody id="onlyFoliosBody"><tr><td class="pending-empty-cell" colspan="6">Cargando bandeja…</td></tr></tbody></table></div>
                </x-folios.bandeja>
                <x-folios.bandeja area="frigorifico" titulo="Datos sin equivalencia en el catálogo" id="pending-catalog-title"
                    panel-id="pending-validation-panel-catalog" data-office-panel-group="pending-validation" data-office-panel-id="catalog" role="tabpanel" hidden>
                    <p class="pending-panel-note">Clientes y embalajes se identifican por código.</p>
                    <p class="pending-catalog-help" id="catalogHelp"></p>
                    <div class="pending-table-scroll"><table class="pending-table pending-table--catalog"><thead><tr><th>Dato</th><th>Valor en origen</th><th>Contexto</th><th>Pallets</th><th>Qué hacer</th></tr></thead><tbody id="catalogBody"><tr><td class="pending-empty-cell" colspan="5">Cargando bandeja…</td></tr></tbody></table></div>
                </x-folios.bandeja>
            </section>
        </main>

        <dialog id="reconciliationDialog" aria-labelledby="reconciliationTitle"><div class="reconciliation-dialog folios-ticket" data-ticket-area="frigorifico"><header class="pending-panel__heading"><div><h2 id="reconciliationTitle">Conciliación</h2><div id="reconciliationRecordState"></div></div><button type="button" class="secondary-button" id="reconciliationClose">Cerrar</button></header><div class="folios-perforacion" aria-hidden="true"></div><p id="reconciliationDetailNotice" role="alert"></p><div id="reconciliationDetail"></div>
            <div id="reconciliationPending" role="status" hidden><p>Hay una gestión sin respuesta confirmada. Reintenta el mismo envío.</p><button type="button" class="primary-button" id="reconciliationRetry">Reintentar gestión</button></div>
            <form id="reconciliationManagement"><label>Gestión<select name="accion"><option value="revisar">Tomar en revisión</option><option value="gestionada">Registrar corrección realizada en planta</option></select></label><label>Comentario<textarea name="comentario" minlength="5" maxlength="2000" required rows="3"></textarea></label><label>Referencia de la gestión<input name="referencia" maxlength="250"></label><p>La gestión conserva el caso en revisión. Una lectura posterior comprueba la cuadratura.</p><button class="primary-button">Guardar gestión</button></form>
            <h3>Historial</h3><div id="reconciliationHistory"></div><div class="reconciliation-actions" id="reconciliationHistoryPages"></div>
        </div></dialog>
        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
