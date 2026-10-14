<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Repaletizajes</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-repalletizing.css', 'resources/js/office-repalletizing.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand repa-access-brand">
                <div class="office-logo" aria-hidden="true">⇄</div>
                <p class="eyebrow">FoliOS · REPALETIZAJES</p>
                <h1 id="officeAccessTitle">Consolida saldos sin perder su composición.</h1>
                <p>El resultado puede ser pallet o saldo, conservar un folio o recibir otro número escrito o escaneado.</p>
                <div class="feature-row"><span>Genealogía</span><span>MIX visible</span><span>Estado térmico protegido</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a repaletizajes</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="frigorifico" office="repaletizajes" context="FRIGORÍFICO · PT" icon="⇄" />

            <section class="repa-workspace">
                <header class="repa-heading">
                    <div>
                        <p class="eyebrow">CONSOLIDACIÓN DE SALDOS Y TRANSFORMACIONES</p>
                        <h1>Repaletizajes</h1>
                        <p>Cliente, especie, marca y estado térmico nunca se mezclan. Las demás diferencias se informan y quedan trazadas como MIX.</p>
                    </div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <div class="repa-layout">
                    <section class="panel repa-builder">
                        <div class="repa-panel-heading">
                            <div><p class="eyebrow">NUEVA REPA</p><h2>Configurar resultado</h2></div>
                            <span id="sourceCount">0 saldos</span>
                        </div>
                        <form id="repaForm" novalidate>
                            <div class="repa-grid">
                                <label>
                                    <span>Modalidad *</span>
                                    <select name="modalidad">
                                        <option value="consolidacion">Consolidar saldos (N → 1)</option>
                                        <option value="cambio_folio">Cambiar folio (1 → 1)</option>
                                        <option value="division">Dividir bulto (1 → 2)</option>
                                    </select>
                                    <small>El cambio y la división consumen completamente el folio original.</small>
                                </label>
                                <label>
                                    <span>Turno *</span>
                                    <select name="turno">
                                        <option value="A">Turno A</option>
                                        <option value="B">Turno B</option>
                                    </select>
                                    <small>Agrupa la repa en su registro RRPL-01.</small>
                                </label>
                                <label class="is-hidden" id="operationalDateField">
                                    <span>Fecha RRPL-01 *</span>
                                    <select name="fecha_operacional">
                                        <option value="hoy">Hoy</option>
                                        <option value="ayer">Ayer (turno B)</option>
                                    </select>
                                    <small>Si el turno B cruzó la medianoche, selecciona ayer.</small>
                                </label>
                            </div>
                            <div class="repa-grid" id="consolidationFields">
                                <label>
                                    <span>Tipo de resultado *</span>
                                    <select name="tipo_resultado">
                                        <option value="pallet">Pallet completo</option>
                                        <option value="saldo">Saldo consolidado</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Identificación *</span>
                                    <select name="estrategia_folio">
                                        <option value="conservar">Conservar un folio participante</option>
                                        <option value="nuevo">Escribir o escanear otro folio</option>
                                    </select>
                                </label>
                                <label id="keptFolioField">
                                    <span>Folio que se conserva *</span>
                                    <select name="folio_conservado_id"><option value="">Agrega primero los saldos</option></select>
                                </label>
                                <label class="is-hidden" id="newFolioField">
                                    <span>Folio resultante *</span>
                                    <input name="numero_folio_resultante" maxlength="80" autocomplete="off" placeholder="Escanear o escribir">
                                </label>
                                <label>
                                    <span>Capacidad del pallet</span>
                                    <input name="cantidad_objetivo" type="number" min="2" max="100000" value="120">
                                    <small>Obligatoria para pallet; en saldo evita que se confirme como saldo una cantidad completa.</small>
                                </label>
                            </div>
                            <div class="repa-grid is-hidden" id="transformFields">
                                <label>
                                    <span>Nuevo folio 1 *</span>
                                    <input name="resultado_1_numero" maxlength="80" autocomplete="off" placeholder="Escanear o escribir">
                                </label>
                                <label>
                                    <span>Tipo resultado 1 *</span>
                                    <select name="resultado_1_tipo"><option value="pallet">Pallet</option><option value="saldo">Saldo</option></select>
                                </label>
                                <label>
                                    <span>Capacidad resultado 1</span>
                                    <input name="resultado_1_objetivo" type="number" min="2" max="100000" value="120">
                                </label>
                                <label class="is-hidden" id="secondResultNumberField">
                                    <span>Nuevo folio 2 *</span>
                                    <input name="resultado_2_numero" maxlength="80" autocomplete="off" placeholder="Escanear o escribir">
                                </label>
                                <label class="is-hidden" id="secondResultTypeField">
                                    <span>Tipo resultado 2 *</span>
                                    <select name="resultado_2_tipo"><option value="saldo">Saldo</option><option value="pallet">Pallet</option></select>
                                </label>
                                <label class="is-hidden" id="secondResultTargetField">
                                    <span>Capacidad resultado 2</span>
                                    <input name="resultado_2_objetivo" type="number" min="2" max="100000" value="120">
                                </label>
                            </div>
                            <div class="repa-grid">
                                <label>
                                    <span>Observación</span>
                                    <textarea name="observacion" maxlength="2000" rows="3"></textarea>
                                </label>
                            </div>

                            <div class="source-entry">
                                <label>
                                    <span id="sourceInputLabel">Agregar saldo por folio</span>
                                    <input id="sourceFolioInput" maxlength="80" autocomplete="off" placeholder="Escanear o escribir folio">
                                </label>
                                <button class="secondary-button" id="addSourceButton" type="button">+ Agregar folio</button>
                            </div>
                            <p class="form-error" id="sourceError" role="alert"></p>

                            <div class="hard-rule" id="hardRule">
                                <strong>Compatibilidad obligatoria</strong>
                                <span>Cliente · especie · marca · estado térmico</span>
                            </div>
                            <div class="mix-warnings is-hidden" id="mixWarnings"></div>
                            <div class="source-overview is-hidden" id="sourceOverview" aria-live="polite"></div>
                            <div class="source-list" id="sourceList"><p class="empty-copy">Agrega al menos dos folios tipo saldo.</p></div>

                            <div class="source-list is-hidden" id="divisionEditor"></div>

                            <section class="result-preview">
                                <div><span>RESULTADO</span><strong id="previewFolio">Sin definir</strong></div>
                                <div><span>TIPO</span><strong id="previewType">Pallet completo</strong></div>
                                <div><span>CAJAS</span><strong id="previewQuantity">0 / 120</strong></div>
                                <div><span>ESTADO TÉRMICO</span><strong id="previewThermal">—</strong></div>
                            </section>
                            <div class="spec-preview" id="specPreview"></div>
                            <p class="form-error" id="repaError" role="alert"></p>
                            <div class="repa-actions">
                                <button class="secondary-button" id="clearButton" type="button">Limpiar</button>
                                <button class="primary-button" type="submit">Confirmar repaletizaje</button>
                            </div>
                        </form>
                    </section>

                    <div class="repa-side">
                        <section class="panel repa-register" aria-labelledby="registerTitle">
                            <div class="repa-panel-heading">
                                <div><p class="eyebrow">DOCUMENTO TRAZABLE</p><h2 id="registerTitle">Registro RRPL-01</h2></div>
                                <input id="registerDate" type="date" aria-label="Fecha del registro">
                            </div>
                            <p class="repa-register__hint">Se llena solo con cada repa, por turno y tarjador. Las anuladas quedan marcadas; nunca se pierde un número REPA.</p>
                            <div class="repa-register-list" id="registerList"></div>
                            <div class="repa-register-actions">
                                <button class="secondary-button" id="registerBlank" type="button">Formulario en blanco</button>
                                <button class="primary-button" id="registerDay" type="button" disabled>Descargar el día</button>
                            </div>
                        </section>

                        <section class="panel repa-history">
                            <div class="repa-panel-heading">
                                <div><p class="eyebrow">TRAZABILIDAD</p><h2>Repas recientes</h2></div>
                                <input id="historyFilter" maxlength="80" placeholder="Buscar folio">
                            </div>
                            <div class="repa-history-list" id="historyList"></div>
                        </section>
                    </div>
                </div>
            </section>
        </main>

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
