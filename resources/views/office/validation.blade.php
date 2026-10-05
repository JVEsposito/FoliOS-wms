<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Validación de pallets</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-validation.css', 'resources/js/office-validation.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand validation-access-brand">
                <div class="office-logo" aria-hidden="true">✓</div>
                <p class="eyebrow">FoliOS · VALIDACIÓN</p>
                <h1 id="officeAccessTitle">El punto de nacimiento trazable de cada pallet.</h1>
                <p>Consulta el maestro de temporada y revisa aprobaciones, observaciones y conflictos enviados desde terreno.</p>
                <div class="feature-row"><span>Maestro de temporada</span><span>Trazabilidad operacional</span><span>Intentos inmutables</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a validación</h2><p>Disponible para administración y supervisión de frío.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a validación <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            
            <x-office.navigation domain="frigorifico" office="validacion" context="FRIGORÍFICO · PT" icon="✓" />


            <section class="validation-workspace">
                <header class="validation-heading panel">
                    <div><p class="eyebrow">CONTROL DE INGRESO</p><h1>Validación de pallets</h1><p>La aprobación crea el folio pendiente de prefrío; observar conserva la posibilidad de corregir y volver a validar.</p></div>
                    <div class="validation-heading__actions">
                        <label><span>Temporada visible</span><select id="seasonSelector"></select></label>
                        <button class="secondary-button" id="reloadValidationButton" type="button">↻ Actualizar</button>
                        <a class="secondary-button" href="/oficina/validacion/etiquetas">Etiquetas PT</a>
                    </div>
                </header>

                <div class="validation-metrics">
                    <article><span>VERSIÓN CATÁLOGO</span><strong id="catalogVersion">—</strong></article>
                    <article><span>ARTÍCULOS ACTIVOS</span><strong id="activeArticleCount">0</strong></article>
                    <article><span>ORÍGENES ACTIVOS</span><strong id="activeOriginCount">0</strong></article>
                    <article><span>COMBINACIONES ACTIVAS</span><strong id="activeCombinationCount">0</strong></article>
                    <article><span>OBSERVADOS RECIENTES</span><strong id="observedCount">0</strong></article>
                </div>

                <section class="panel validation-history-panel">
                    <div class="validation-panel__heading">
                        <div><p class="eyebrow">TRAZABILIDAD</p><h2>Validaciones recientes</h2></div>
                        <form class="validation-filters" id="validationFilters">
                            <input name="folio" maxlength="50" placeholder="Buscar folio">
                            <input name="fecha" type="date" value="{{ now(config('app.operational_timezone'))->format('Y-m-d') }}" aria-label="Fecha de validación">
                            <select name="linea_proceso"><option value="">Todas las líneas</option><option value="1">Línea 1</option><option value="2">Línea 2</option><option value="3">Línea 3</option></select>
                            <select name="turno"><option value="">Todos los turnos</option><option value="A">Turno A</option><option value="B">Turno B</option></select>
                            <select id="validationUserFilter" name="user_id"><option value="">Todos los encargados</option></select>
                            <select name="resultado"><option value="">Todos los resultados</option><option value="aprobado">Aprobado</option><option value="observado">Observado</option><option value="rechazado">Rechazado</option></select>
                            <select name="estado"><option value="">Todos los estados</option><option value="aceptada">Aceptada</option><option value="conflicto">Conflicto</option></select>
                            <button class="secondary-button" type="submit">Filtrar</button>
                            <button class="secondary-button" id="downloadBlankValidationRegisterButton" type="button">Descargar en blanco</button>
                            <button class="primary-button" id="exportValidationRegisterButton" type="button">Descargar RRPP-01</button>
                        </form>
                    </div>
                    <p class="validation-help">El registro RRPP-01 utiliza la fecha real de terreno y agrupa automáticamente cada hoja por encargado, línea y turno. La versión en blanco conserva el formato oficial para contingencia, trazabilidad y auditoría.</p>
                    <div class="validation-table-scroll"><table class="validation-table"><thead><tr><th>Folio</th><th>Artículo</th><th>Origen</th><th>Resultado</th><th>Validador</th><th>Fecha y jornada</th><th>Administración</th></tr></thead><tbody id="validationHistoryBody"></tbody></table></div>
                </section>

                <dialog class="validation-correction-dialog" id="validationCorrectionDialog">
                    <form class="validation-form validation-correction-form" id="validationCorrectionForm" novalidate>
                        <div class="validation-correction-heading">
                            <div>
                                <p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p>
                                <h2 id="validationCorrectionTitle">Corregir validación</h2>
                                <p>Disponible en cualquier etapa del folio. Corrige sus datos sin cambiar el proceso, túnel, cámara, reserva, carga ni estado operativo.</p>
                            </div>
                            <button aria-label="Cerrar" class="validation-dialog-close" id="cancelValidationCorrection" type="button">×</button>
                        </div>
                        <p class="validation-help" id="validationCorrectionState"></p>
                        <div class="validation-form__grid">
                            <label><span>Tipo de bulto *</span><select name="tipo_bulto" required><option value="pallet">Pallet completo</option><option value="saldo">Saldo</option></select></label>
                            <label><span>Cantidad de cajas *</span><input name="cantidad_cajas" type="number" min="1" required></label>
                            <label><span>Línea *</span><select name="linea_proceso" required><option value="1">Línea 1</option><option value="2">Línea 2</option><option value="3">Línea 3</option></select></label>
                            <label><span>Turno *</span><select name="turno" required><option value="A">Turno A</option><option value="B">Turno B</option></select></label>
                        </div>
                        <label><span>Artículo / embalaje *</span><select name="articulo_validacion_id" required></select></label>
                        <label><span>Origen autorizado *</span><select name="origen_validacion_id" required></select></label>
                        <label><span>Categoría *</span><select name="categoria_validacion_id" required></select></label>
                        <label><span>Motivo de la corrección *</span><textarea name="motivo_correccion" rows="3" maxlength="1000" placeholder="Explica el error y el dato corregido" required></textarea></label>
                        <p class="form-error" id="validationCorrectionError" role="alert"></p>
                        <div class="validation-actions">
                            <button class="secondary-button" onclick="document.getElementById('cancelValidationCorrection').click()" type="button">Cancelar</button>
                            <button class="primary-button" type="submit">Guardar corrección</button>
                        </div>
                    </form>
                </dialog>

            </section>
        </main>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
