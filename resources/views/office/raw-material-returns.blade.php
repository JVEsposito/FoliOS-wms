<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Retornos de Packing</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/office-raw-material-returns.css',
                'resources/js/office-raw-material-returns.js',
            ])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand return-access-brand">
                <div class="office-logo" aria-hidden="true">↩</div>
                <p class="eyebrow">FoliOS · MATERIA PRIMA</p>
                <h1 id="officeAccessTitle">Registra lo que realmente vuelve desde Packing.</h1>
                <p>Cada bin nace con un folio provisional y kilos verdes. Cuadraturas confirma después su peso definitivo por proceso.</p>
                <div class="feature-row"><span>Bin individual</span><span>Multiorigen real</span><span>Cuadratura definitiva</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Retornos de Packing</h2><p>Disponible para operación, supervisión y administración autorizada.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar al módulo <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="materia-prima" office="fruta-proceso" context="MATERIA PRIMA" icon="↩" />

            <section class="return-workspace">
                <header class="return-heading">
                    <div>
                        <p class="eyebrow">PACKING → CÁMARA MP</p>
                        <h1>Retornos de Packing</h1>
                        <p>El bin es la unidad física. Los procesos solo explican de dónde provienen sus kilos.</p>
                    </div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <nav class="return-flow-nav" aria-label="Fruta a proceso">
                    <a href="/oficina/materia-prima/fruta-a-proceso">1. Entregas a Packing</a>
                    <a class="is-active" href="/oficina/materia-prima/retornos-packing">2. Retornos de Packing</a>
                </nav>

                <div class="return-kpis">
                    <article><span>BINS REGISTRADOS</span><strong id="registeredBins">0</strong><small>Modelo individual</small></article>
                    <article><span>KILOS VERDES REGISTRADOS</span><strong id="registeredKilos">0</strong><small>Peso provisorio informado al retornar</small></article>
                    <article><span>PENDIENTES DE REGULARIZAR</span><strong id="pendingBins">0</strong><small>Folio provisional vigente</small></article>
                    <article><span>RETORNOS ANTERIORES</span><strong id="legacyReturns">0</strong><small>Pendientes de migrar o descartar</small></article>
                </div>

                <nav class="return-section-tabs" aria-label="Etapas de retorno">
                    <button class="is-active" data-return-section="recepcion" type="button">Recepción</button>
                    <button data-return-section="pendientes" type="button">Pendientes de regularizar</button>
                    <button data-return-section="anteriores" type="button">Registros anteriores</button>
                </nav>

                <section class="return-panel" data-return-panel="recepcion">
                    <div class="return-panel__heading">
                        <div><p class="eyebrow">NUEVO RETORNO FÍSICO</p><h2>Registrar un bin</h2><p>El WMS asignará un folio provisional PR-* al confirmar.</p></div>
                    </div>

                    <form id="binReturnForm" class="bin-return-form" novalidate>
                        <div class="return-form-grid">
                            <label><span>Kilos verdes totales del bin *</span><input name="kilos_totales" id="binTotalKilos" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" required></label>
                            <label class="field-wide"><span>Observación</span><textarea name="observacion" maxlength="2000" placeholder="Opcional"></textarea></label>
                        </div>

                        <div class="origin-builder">
                            <div class="origin-builder__heading">
                                <div><strong>Composición verde por proceso</strong><small>Agrega cada proceso y los kilos provisorios que aportó al bin.</small></div>
                                <div class="origin-add">
                                    <select id="processSelect" aria-label="Proceso de origen"></select>
                                    <button class="secondary-button" id="addOriginButton" type="button">+ Agregar proceso</button>
                                </div>
                            </div>
                            <div id="originRows" class="origin-rows"></div>
                            <div class="origin-balance" id="originBalance">
                                <span>Distribuido</span><strong>0,000 / 0,000 kg</strong><small>Faltan 0,000 kg</small>
                            </div>
                        </div>

                        <p class="form-error" id="binReturnError" role="alert"></p>
                        <div class="return-actions"><button class="primary-button" type="submit">Registrar bin y generar folio provisional</button></div>
                    </form>

                    <div class="recent-return-heading">
                        <div><strong>Todos los bins registrados</strong><small id="binListCount">Cargando retornos de la temporada…</small></div>
                        <div class="return-list-filters">
                            <label><span>Buscar</span><input id="binListSearch" type="search" autocomplete="off" placeholder="Folio, observación, lote u orden"></label>
                            <label><span>Estado</span><select id="binListState"><option value="">Todos</option><option value="pendiente_regularizacion">Pendientes</option><option value="regularizado">Regularizados</option><option value="anulado">Anulados</option></select></label>
                        </div>
                    </div>
                    <div class="bin-list" id="recentBins"></div>
                </section>

                <section class="return-panel is-hidden" data-return-panel="pendientes">
                    <div class="return-panel__heading">
                        <div><p class="eyebrow">REGULARIZACIÓN OBLIGATORIA</p><h2>Folios provisionales pendientes</h2><p>Cuadraturas debe confirmar folio, clasificación, kilos totales y kilos definitivos por proceso. El PR-* siempre conserva su identidad.</p></div>
                    </div>
                    <div class="bin-list" id="pendingBinList"></div>
                </section>

                <section class="return-panel is-hidden" data-return-panel="anteriores">
                    <div class="return-panel__heading">
                        <div><p class="eyebrow">TRANSICIÓN DE MODELO</p><h2>Retornos registrados anteriormente</h2><p>Migra los que representan exactamente un bin o descártalos de forma auditable para reingresarlos correctamente.</p></div>
                    </div>
                    <div class="legacy-notice">Los registros anteriores no se eliminan físicamente. Al descartar se anulan y quedan fuera de operación, conservando la auditoría.</div>
                    <div class="legacy-list" id="legacyList"></div>
                </section>
            </section>
        </main>

        <dialog class="return-dialog return-dialog--wide" id="editBinDialog">
            <form method="dialog" id="editBinForm" novalidate>
                <div class="return-dialog__heading"><div><p class="eyebrow">CORRECCIÓN AUDITADA</p><h2 id="editBinTitle">Modificar retorno</h2><p id="editBinDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="bin_id" type="hidden">
                <div class="return-form-grid">
                    <label><span>Kilos verdes totales *</span><input name="kilos_totales" id="editGreenTotalKilos" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" required></label>
                    <label class="field-wide"><span>Observación</span><textarea name="observacion" id="editBinObservation" maxlength="2000" placeholder="Opcional"></textarea></label>
                </div>
                <div>
                    <p class="eyebrow">PROCESOS DE ORIGEN Y KILOS VERDES</p>
                    <div class="origin-add edit-origin-add">
                        <select id="editProcessSelect" aria-label="Proceso de origen para agregar">
                            <option value="">Seleccionar proceso para agregar</option>
                        </select>
                        <button class="secondary-button" id="editAddOriginButton" type="button">Agregar proceso</button>
                    </div>
                    <div class="edit-origins" id="editBinOrigins"></div>
                    <div class="origin-balance" id="editGreenBalance"><span>Distribuido</span><strong>0,000 / 0,000 kg</strong><small>Completa la cuadratura</small></div>
                </div>
                <section class="edit-definitive is-hidden" id="editDefinitiveSection">
                    <div class="return-form-grid">
                        <label><span>Folio definitivo *</span><input name="folio_definitivo" maxlength="80" autocomplete="off"></label>
                        <label><span>Clasificación *</span><select name="tipo_resultado_packing_id"></select></label>
                        <label class="field-wide"><span>Nombre / detalle</span><input name="nombre_resultado" maxlength="100" autocomplete="off" placeholder="Opcional; usa el nombre del catálogo si queda vacío"></label>
                    </div>
                    <label class="migration-total"><span>Kilos totales definitivos *</span><input name="kilos_totales_definitivos" id="editDefinitiveTotalKilos" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal"></label>
                    <div class="origin-balance" id="editDefinitiveBalance"><span>Distribuido</span><strong>0,000 / 0,000 kg</strong><small>Completa la cuadratura</small></div>
                </section>
                <label class="migration-reason"><span>Motivo de la corrección *</span><textarea name="motivo" maxlength="2000" minlength="5" required placeholder="Explica brevemente qué dato estaba incorrecto"></textarea></label>
                <div class="edit-scope-notice">El folio provisional no cambia. Puedes agregar, quitar o reemplazar procesos de origen; el estado anterior y el nuevo quedarán guardados en el historial de la corrección.</div>
                <p class="form-error" id="editBinError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Guardar modificación</button></div>
            </form>
        </dialog>

        <dialog class="return-dialog return-dialog--wide" id="regularizeDialog">
            <form method="dialog" id="regularizeForm" novalidate>
                <div class="return-dialog__heading"><div><p class="eyebrow">FOLIO PROVISIONAL</p><h2 id="regularizeTitle">Regularizar bin</h2><p id="regularizeDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <section class="return-source-observation is-empty" aria-labelledby="regularizeObservationLabel">
                    <span id="regularizeObservationLabel">Observación del retorno físico</span>
                    <p id="regularizeObservation">Sin observación registrada.</p>
                </section>
                <input name="bin_id" type="hidden">
                <div class="return-form-grid">
                    <label><span>Folio definitivo *</span><input name="folio_definitivo" maxlength="80" autocomplete="off" required></label>
                    <label><span>Clasificación *</span><select name="tipo_resultado_packing_id" required></select></label>
                    <label class="field-wide"><span>Nombre / detalle</span><input name="nombre_resultado" maxlength="100" autocomplete="off" placeholder="Opcional; usa el nombre del catálogo si queda vacío"></label>
                </div>
                <label class="migration-total"><span>Kilos totales definitivos *</span><input name="kilos_totales_definitivos" id="regularizeTotalKilos" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" required></label>
                <div>
                    <p class="eyebrow">DISTRIBUCIÓN DEFINITIVA POR PROCESO</p>
                    <div class="migration-origins" id="regularizeOrigins"></div>
                    <div class="origin-balance" id="regularizeBalance"><span>Distribuido</span><strong>0,000 / 0,000 kg</strong><small>Completa la cuadratura</small></div>
                </div>
                <p class="form-error" id="regularizeError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Confirmar folio y kilos definitivos</button></div>
            </form>
        </dialog>

        <dialog class="return-dialog return-dialog--wide" id="legacyMigrationDialog">
            <form method="dialog" id="legacyMigrationForm" novalidate>
                <div class="return-dialog__heading"><div><p class="eyebrow">MIGRAR RETORNO ANTERIOR</p><h2 id="legacyMigrationTitle">Convertir a bin provisional</h2><p id="legacyMigrationDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="retorno_id" type="hidden">
                <label class="migration-total"><span>Kilos totales del bin *</span><input name="kilos_totales" id="migrationTotalKilos" type="number" min="0.001" step="0.001" required></label>
                <div class="migration-origins" id="migrationOrigins"></div>
                <div class="origin-balance" id="migrationBalance"><span>Distribuido</span><strong>0,000 / 0,000 kg</strong><small>Completa la distribución</small></div>
                <label class="migration-reason"><span>Observación de migración</span><textarea name="motivo" maxlength="2000"></textarea></label>
                <p class="form-error" id="legacyMigrationError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Migrar a folio provisional</button></div>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
