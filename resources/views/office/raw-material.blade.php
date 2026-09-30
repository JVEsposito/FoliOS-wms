<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Materia prima</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-raw-material.css', 'resources/js/office-raw-material.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand raw-material-access-brand">
                <div class="office-logo" aria-hidden="true">◫</div>
                <p class="eyebrow">FoliOS · MATERIA PRIMA</p>
                <h1 id="officeAccessTitle">Convierte cada recepción validada en lotes trazables.</h1>
                <p>Digitación transforma cada segmento validado en un lote trazable y lo deriva al proceso operacional correspondiente.</p>
                <div class="feature-row"><span>Lotes manuales</span><span>Neto confirmado</span><span>GGN y SdP</span><span>Destino controlado</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Materia prima</h2><p>Disponible para Digitación, supervisión, administración y consulta autorizada.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar al módulo <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="materia-prima" office="digitacion" context="MATERIA PRIMA" icon="◫" />

            <section class="raw-material-workspace">
                <header class="raw-material-heading">
                    <div><p class="eyebrow">OFICINA MADRE</p><h1>Materia prima</h1><p id="seasonDescription">Lotización de recepciones validadas y despacho operacional hacia cámara.</p></div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <nav class="raw-material-module-links" aria-label="Procesos del módulo">
                    <a href="/oficina/materia-prima/romana"><span>01</span><strong>Romana</strong><small>Ingreso, destare y neto por envase</small></a>
                    <a class="is-active" href="/oficina/materia-prima/lotes"><span>02</span><strong>Digitación de lotes</strong><small>Origen, envases, kilos y confirmación</small></a>
                    <a href="/oficina/materia-prima/hidrocooler"><span>03</span><strong>Hidrocooler</strong><small>Ciclos individuales y temperaturas</small></a>
                    <a href="/oficina/materia-prima/fruta-a-proceso"><span>04</span><strong>Fruta a proceso</strong><small>Entregas parciales hacia Packing</small></a>
                    <a href="/oficina/materia-prima/envases"><span>05</span><strong>Envases</strong><small>Cuenta corriente y trazabilidad</small></a>
                </nav>

                <div class="raw-material-kpis">
                    <article><span>SEGMENTOS POR LOTIZAR</span><strong id="pendingSegmentsCount">0</strong><small>Validados por la APK</small></article>
                    <article><span>BORRADORES</span><strong id="draftLotsCount">0</strong><small>Pendientes de confirmación</small></article>
                    <article><span>HIDROCOOLER</span><strong id="hydrocoolerLotsCount">0</strong><small>Pendientes, en curso o retenidos</small></article>
                    <article><span>PENDIENTES DE CÁMARA</span><strong id="cameraPendingCount">0</strong><small>Listos para asignar</small></article>
                </div>

                <div class="raw-material-grid">
                    <section class="panel raw-material-pending">
                        <div class="raw-material-panel-heading"><div><p class="eyebrow">DESDE VALIDACIÓN MP</p><h2>Segmentos disponibles</h2><p>Cada segmento validado genera un lote con todos sus envases.</p></div></div>
                        <div class="segment-list" id="segmentList"></div>
                    </section>

                    <section class="panel raw-material-lots">
                        <div class="raw-material-panel-heading">
                            <div><p class="eyebrow">TRAZABILIDAD OPERACIONAL</p><h2>Lotes de la temporada</h2></div>
                            <form class="lot-filters" id="lotFilters">
                                <input name="buscar" maxlength="100" placeholder="Lote, recepción, GGN o SdP">
                                <select name="estado"><option value="">Todos los estados</option><option value="borrador">Borrador</option><option value="pendiente_hidrocooler">Pendiente hidrocooler</option><option value="hidrocooler_en_curso">Hidrocooler en curso</option><option value="hidrocooler_retenido">Retenido por Hidrocooler</option><option value="pendiente_asignacion">Pendiente cámara</option><option value="disponible_proceso">Disponible directo a proceso</option><option value="asignado_camara">Asignado</option><option value="entrega_parcial_proceso">Entrega parcial a Packing</option><option value="entregado_proceso">Entregado a Packing</option><option value="anulado">Anulado</option></select>
                                <button class="secondary-button" type="submit">Filtrar</button>
                            </form>
                        </div>
                        <div class="raw-material-table-scroll">
                            <table class="raw-material-table">
                                <thead><tr><th>Lote / recepción</th><th>Origen</th><th>Producto</th><th>Envases y kilos</th><th>Estado</th><th>Acciones</th></tr></thead>
                                <tbody id="lotTableBody"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </section>
        </main>

        <dialog class="raw-material-dialog" id="lotDialog">
            <form method="dialog" class="raw-material-dialog__shell" id="lotForm" novalidate>
                <div class="raw-material-dialog__heading"><div><p class="eyebrow">DIGITACIÓN TRAZABLE</p><h2 id="lotDialogTitle">Crear lote</h2><p id="lotDialogDescription">Completa y confirma los antecedentes informados por la exportadora.</p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="lote_id" type="hidden">
                <input name="version_conocida" type="hidden">
                <input name="segmento_validacion_mp_id" type="hidden">
                <input name="operacion_id" type="hidden">
                <input name="confirmacion_operacion_id" type="hidden">
                <div class="lot-source-summary" id="lotSourceSummary"></div>
                <div class="raw-material-form-grid">
                    <label class="field"><span>Número de lote *</span><input name="numero_lote" maxlength="80" autocomplete="off" required></label>
                    <label class="field"><span>Fecha cosecha *</span><input name="fecha_cosecha" type="date" required></label>
                    <label class="field"><span>CSG *</span><select name="csg_validacion_id" required></select></label>
                    <label class="field"><span>Predio *</span><input name="predio" maxlength="150" required></label>
                    <label class="field"><span>SdP *</span><input name="sdp" inputmode="numeric" pattern="[0-9]+" maxlength="30" required></label>
                    <label class="field"><span>GGN · 13 dígitos (opcional)</span><input name="ggn" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13"></label>
                    <label class="field"><span>Especie *</span><select name="especie_validacion_id" required></select></label>
                    <label class="field"><span>Variedad *</span><select name="variedad_validacion_id" required></select></label>
                    <label class="field"><span>Cuartel</span><input name="cuartel" maxlength="100"><small>Opcional; si Validación MP ya lo informó, se conservará automáticamente.</small></label>
                    <label class="field"><span>Producto *</span><select name="tipo_producto" required></select></label>
                    <label class="field"><span>Kilos brutos calculados</span><input name="kilos_brutos" type="number" step="0.001" readonly><small>Neto del segmento más la tara de todos sus envases; el servidor concilia el último lote.</small></label>
                    <label class="field"><span>Neto calculado</span><input name="kilos_netos_calculados" type="number" step="0.001" readonly></label>
                    <fieldset class="hydrocooler-choice"><legend>¿El lote necesita hidrocooler? *</legend><label><input name="requiere_hidrocooler" type="radio" value="1" required><span>Sí, dejar pendiente de hidrocooler</span></label><label><input name="requiere_hidrocooler" type="radio" value="0" required><span>No, dejar pendiente de cámara</span></label></fieldset>
                    <label class="field field--span-2"><span>Observación del lote</span><textarea name="observacion" maxlength="2000"></textarea></label>
                </div>
                <p class="form-error" id="lotFormError" role="alert"></p>
                <div class="dialog-actions">
                    <button class="secondary-button" value="cancel" type="submit">Cancelar</button>
                    <button class="secondary-button" id="saveDraftButton" value="draft" type="submit">Guardar borrador</button>
                    <button class="primary-button" id="saveAndConfirmButton" value="confirm" type="submit">Guardar y confirmar</button>
                </div>
            </form>
        </dialog>

        <dialog class="raw-material-dialog raw-material-dialog--compact" id="operationDialog">
            <form method="dialog" class="raw-material-dialog__shell" id="operationForm" novalidate>
                <div class="raw-material-dialog__heading"><div><p class="eyebrow" id="operationEyebrow">OPERACIÓN</p><h2 id="operationTitle">Completar operación</h2><p id="operationDescription"></p></div><button class="dialog-close" value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="operation_type" type="hidden">
                <input name="lote_id" type="hidden">
                <input name="operacion_id" type="hidden">
                <div class="operation-fields" id="operationFields"></div>
                <p class="form-error" id="operationFormError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Confirmar operación</button></div>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
