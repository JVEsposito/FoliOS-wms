<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Anulaciones de pallets</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-validation-annulments.css', 'resources/css/office-validation-correction.css', 'resources/js/office-validation-annulments.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand annulment-access-brand">
                <div class="office-logo" aria-hidden="true">⊘</div>
                <p class="eyebrow">FoliOS · AUDITORÍA</p>
                <h1 id="officeAccessTitle">Corrige o anula sin borrar la historia.</h1>
                <p>La corrección actualiza los datos con auditoría. La anulación invalida el intento, conserva su historia y libera el número para ingresarlo nuevamente.</p>
                <div class="feature-row"><span>Sin eliminación</span><span>Folio reutilizable</span><span>Error medible</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a anulaciones</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="anulaciones-validacion" context="FRIGORÍFICO · PT" icon="⊘" />

            <section class="annulment-workspace">
                <header class="annulment-heading panel">
                    <div>
                        <p class="eyebrow">CONTROL DE ERRORES DE VALIDACIÓN</p>
                        <h1>Anulaciones de pallets</h1>
                        <p>Corrige los datos de un pallet aprobado o anula su validación mientras continúa pendiente de prefrío y sin actividad posterior. El folio anulado podrá validarse nuevamente.</p>
                    </div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <div class="annulment-metrics">
                    <article><span>TOTAL ANULADOS</span><strong id="totalAnnulled">0</strong></article>
                    <article><span>ANULADOS HOY</span><strong id="todayAnnulled">0</strong></article>
                    <article><span>AÚN ANULABLES</span><strong id="candidateCount">0</strong></article>
                    <article><span>MOTIVO MÁS FRECUENTE</span><strong id="topReason">—</strong></article>
                </div>

                <section class="panel annulment-panel">
                    <div class="annulment-panel-heading">
                        <div><p class="eyebrow">ANTES DE PRE-FRÍO</p><h2>Pallets aún anulables</h2></div>
                        <form class="annulment-filter" id="annulmentFilter">
                            <input name="folio" maxlength="80" placeholder="Buscar folio">
                            <button class="secondary-button" type="submit">Buscar</button>
                        </form>
                    </div>
                    <p class="annulment-help" id="permissionNotice"></p>
                    <div class="annulment-list" id="candidateList"></div>
                </section>

                <section class="panel annulment-panel">
                    <div class="annulment-panel-heading">
                        <div><p class="eyebrow">AUDITORÍA</p><h2>Registro de anulaciones</h2></div>
                        <select id="historyCategoryFilter" aria-label="Filtrar motivo">
                            <option value="">Todos los motivos</option>
                            <option value="folio_incorrecto">Folio incorrecto</option>
                            <option value="cantidad_cajas_incorrecta">Cantidad de cajas incorrecta</option>
                            <option value="articulo_incorrecto">Artículo incorrecto</option>
                            <option value="cliente_origen_incorrecto">Cliente / origen incorrecto</option>
                            <option value="pallet_duplicado">Pallet duplicado</option>
                            <option value="error_etiqueta">Error de etiqueta</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                    <div class="annulment-history" id="historyList"></div>
                </section>
            </section>
        </main>

        <dialog class="annulment-dialog" id="annulmentDialog">
            <form id="annulmentForm" novalidate>
                <div class="annulment-dialog-heading">
                    <div><p class="eyebrow">ANULACIÓN IRREVERSIBLE</p><h2 id="annulmentDialogTitle">Anular pallet</h2></div>
                    <button class="annulment-close" id="cancelAnnulment" type="button" aria-label="Cerrar">×</button>
                </div>
                <p class="annulment-warning">Este intento quedará anulado. El número de folio podrá ingresarse nuevamente en Validación, pero no avanzará a otras operaciones hasta ser aprobado otra vez.</p>
                <label><span>Tipo de error *</span>
                    <select name="motivo_categoria" required>
                        <option value="">Selecciona un motivo</option>
                        <option value="folio_incorrecto">Folio incorrecto</option>
                        <option value="cantidad_cajas_incorrecta">Cantidad de cajas incorrecta</option>
                        <option value="articulo_incorrecto">Artículo incorrecto</option>
                        <option value="cliente_origen_incorrecto">Cliente / origen incorrecto</option>
                        <option value="pallet_duplicado">Pallet duplicado</option>
                        <option value="error_etiqueta">Error de etiqueta</option>
                        <option value="otro">Otro</option>
                    </select>
                </label>
                <label><span>Detalle del error *</span><textarea name="motivo" rows="4" maxlength="2000" placeholder="Explica qué ocurrió y por qué debe anularse" required></textarea></label>
                <p class="form-error" id="annulmentError" role="alert"></p>
                <div class="annulment-actions">
                    <button class="secondary-button" id="cancelAnnulmentBottom" type="button">Cancelar</button>
                    <button class="danger-button" type="submit">Confirmar anulación</button>
                </div>
            </form>
        </dialog>

        @include('office.partials.validation-correction-dialog')

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
