<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Hidrocooler</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-hydrocooler.css', 'resources/js/office-hydrocooler.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand hydrocooler-access-brand">
                <div class="office-logo" aria-hidden="true">❄</div>
                <p class="eyebrow">FoliOS · MATERIA PRIMA</p>
                <h1 id="officeAccessTitle">Un ciclo de Hidrocooler por cada lote.</h1>
                <p>Registra tiempos, operador, equipo, temperaturas, kilos y el destino físico de la fruta sin perder trazabilidad.</p>
                <div class="feature-row"><span>1 lote por ciclo</span><span>Tiempo real</span><span>Temperaturas</span><span>Salida controlada</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a Hidrocooler</h2><p>Disponible para operación, supervisión, administración y consulta autorizada.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar al módulo <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="materia-prima" office="hidrocooler" context="MATERIA PRIMA" icon="❄" />

            <section class="hydrocooler-workspace">
                <header class="hydrocooler-heading">
                    <div><p class="eyebrow">CONTROL DE ENFRIAMIENTO MP</p><h1>Hidrocooler</h1><p id="seasonDescription">Cargando ciclos de la temporada…</p></div>
                    <div class="hydrocooler-heading__actions">
                        <button class="secondary-button" data-register="blank-xlsx" type="button">↓ Blanco Excel</button>
                        <button class="secondary-button" data-register="blank-pdf" type="button">↓ Blanco PDF</button>
                        <button class="secondary-button" data-register="filled-xlsx" type="button">↓ Registro Excel</button>
                        <button class="secondary-button" data-register="filled-pdf" type="button">↓ Registro PDF</button>
                        <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                    </div>
                </header>

                <div class="hydrocooler-kpis">
                    <article><span>LOTES EN ESPERA</span><strong id="pendingCount">0</strong><small>Confirmados por Digitación</small></article>
                    <article><span>CICLOS EN CURSO</span><strong id="activeCount">0</strong><small>Un lote por equipo</small></article>
                    <article><span>KILOS EN CURSO</span><strong id="activeKilos">0</strong><small>Peso congelado al iniciar</small></article>
                    <article><span>COMPLETADOS HOY</span><strong id="completedToday">0</strong><small>Con destino definido</small></article>
                    <article><span>RETENIDOS</span><strong id="retainedCount">0</strong><small>Esperan evaluación y liberación</small></article>
                    <article><span>TIEMPO PROMEDIO HOY</span><strong id="averageDuration">0 min</strong><small>Calculado por el servidor</small></article>
                </div>

                <section class="panel hydrocooler-panel">
                    <div class="hydrocooler-toolbar">
                        <div class="hydrocooler-tabs" role="tablist" aria-label="Bandejas Hidrocooler">
                            <button class="is-active" data-tray="pendientes" type="button">Pendientes</button>
                            <button data-tray="en_curso" type="button">En curso</button>
                            <button data-tray="retenidos" type="button">Retenidos</button>
                            <button data-tray="historial" type="button">Historial</button>
                        </div>
                        <form id="hydrocoolerFilters">
                            <input name="buscar" maxlength="100" placeholder="Lote, recepción, cliente, ciclo o equipo">
                            <select name="equipo" id="equipmentFilter"><option value="">Todos los equipos</option></select>
                            <select name="turno"><option value="">Todos los turnos</option><option value="A">Turno A</option><option value="B">Turno B</option></select>
                            <select name="destino"><option value="">Todos los destinos</option><option value="camara">Cámara MP</option><option value="proceso">Directo a proceso</option></select>
                            <input name="desde" type="date" aria-label="Desde">
                            <input name="hasta" type="date" aria-label="Hasta">
                            <button class="secondary-button" type="submit">Filtrar</button>
                        </form>
                    </div>
                    <div class="hydrocooler-list" id="hydrocoolerList"></div>
                </section>
            </section>
        </main>

        <dialog class="hydrocooler-dialog" id="startDialog">
            <form method="dialog" id="startForm" novalidate>
                <div class="hydrocooler-dialog__heading"><div><p class="eyebrow">INICIO TRAZABLE</p><h2 id="startTitle">Iniciar ciclo</h2><p id="startDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="lote_id" type="hidden">
                <input name="operacion_id" type="hidden">
                <div class="cycle-summary" id="startSummary"></div>
                <div class="hydrocooler-fields">
                    <label><span>Equipo / Hidrocooler *</span><input name="equipo" list="equipmentOptions" maxlength="100" autocomplete="off" required></label>
                    <label><span>Turno *</span><select name="turno" required><option value="">Seleccionar</option><option value="A">Turno A</option><option value="B">Turno B</option></select></label>
                    <label><span>Cantidad de bombas funcionando *</span><input name="cantidad_bombas_funcionando" type="number" min="1" max="20" step="1" required></label>
                    <label><span>Operador responsable</span><input name="operador" readonly></label>
                    <label><span>Fecha y hora de inicio *</span><input name="inicio_at" type="datetime-local" required></label>
                    <label><span>Temperatura inicial fruta °C *</span><input name="temperatura_inicial_c" type="number" min="-20" max="50" step="0.01" required></label>
                    <label><span>Temperatura objetivo fruta °C *</span><input name="temperatura_objetivo_c" type="number" min="-20" max="50" step="0.01" required></label>
                    <label><span>Temperatura inicial agua °C</span><input name="temperatura_agua_inicial_c" type="number" min="-20" max="50" step="0.01"></label>
                    <label><span>T° ambiente °C *</span><input name="temperatura_ambiente_c" type="number" min="-50" max="70" step="0.01" required></label>
                    <label><span>Humedad relativa % *</span><input name="humedad_relativa_pct" type="number" min="0" max="100" step="0.01" required></label>
                    <label><span>Pozo Accutab mV *</span><input name="pozo_accutab_mv" type="number" min="-9999999" max="9999999" step="0.01" required></label>
                    <label><span>Recarga de pastilla *</span><select name="recarga_pastilla" required><option value="">Seleccionar</option><option value="1">Sí</option><option value="0">No</option></select></label>
                    <label><span>Corrección de cloro (lectura después de corregir, ppm)</span><input name="correccion_cloro_ppm" type="number" min="0" max="500" step="0.01"><small id="chlorineRangeHint"></small></label>
                    <label><span>Aplicación de producto *</span><select name="aplicacion_producto" required><option value="">Seleccionar</option><option value="1">Sí</option><option value="0">No</option></select></label>
                    <label><span>Producto aplicado</span><select name="producto_hidrocooler_id" disabled><option value="">Seleccionar del catálogo</option></select></label>
                    <label><span>Dosis aplicada</span><input name="producto_dosis" type="number" min="0.0001" max="99999999" step="0.0001" disabled></label>
                    <label><span>Unidad de dosis</span><input name="producto_unidad_dosis" maxlength="30" placeholder="ml/L, g/L, ppm" disabled></label>
                    <label><span>Cloro libre ppm *</span><input name="cloro_libre_ppm" type="number" min="0" max="500" step="0.01" required></label>
                    <label><span>pH del agua *</span><input name="ph_agua" type="number" min="0" max="14" step="0.01" required></label>
                    <label><span>Cloro después de corregir y pH conformes al procedimiento de planta *</span><select name="control_inicial_conforme" required><option value="">Seleccionar</option><option value="1">Sí, conformes</option><option value="0">No, hay desviación</option></select></label>
                    <label><span>Condición visual del agua *</span><select name="condicion_visual_agua" required><option value="">Seleccionar</option><option value="conforme">Conforme</option><option value="no_conforme">No conforme</option></select></label>
                    <label><span>Dosificador *</span><select name="dosificador_operativo" required><option value="">Seleccionar</option><option value="1">Operativo</option><option value="0">No operativo</option></select></label>
                    <label><span>Control del agua *</span><select name="manejo_agua" required><option value="">Seleccionar</option><option value="sin_novedad">Sin novedad</option><option value="filtrado">Filtrado</option><option value="recambio">Recambio</option></select></label>
                    <label class="field-wide"><span>Observación de inicio</span><textarea name="observacion_inicio" maxlength="2000"></textarea></label>
                </div>
                <datalist id="equipmentOptions"></datalist>
                <p class="form-error" id="startError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Iniciar ciclo</button></div>
            </form>
        </dialog>

        <dialog class="hydrocooler-dialog" id="finishDialog">
            <form method="dialog" id="finishForm" novalidate>
                <div class="hydrocooler-dialog__heading"><div><p class="eyebrow">CIERRE Y DESTINO</p><h2 id="finishTitle">Finalizar ciclo</h2><p id="finishDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="lote_id" type="hidden">
                <input name="operacion_id" type="hidden">
                <div class="cycle-summary" id="finishSummary"></div>
                <div class="hydrocooler-fields">
                    <label><span>Fecha y hora de término *</span><input name="termino_at" type="datetime-local" required></label>
                    <label><span>Temperatura final fruta °C *</span><input name="temperatura_c" type="number" min="-20" max="50" step="0.01" required></label>
                    <label><span>Temperatura final agua °C</span><input name="temperatura_agua_final_c" type="number" min="-20" max="50" step="0.01"></label>
                    <label><span>Cloro libre final ppm *</span><input name="cloro_libre_final_ppm" type="number" min="0" max="500" step="0.01" required></label>
                    <label><span>pH final del agua *</span><input name="ph_agua_final" type="number" min="0" max="14" step="0.01" required></label>
                    <label><span>Cloro y pH finales conformes al procedimiento *</span><select name="control_final_conforme" required><option value="">Seleccionar</option><option value="1">Sí, conformes</option><option value="0">No, hay desviación</option></select></label>
                    <label><span>Condición visual final del agua *</span><select name="condicion_visual_agua_final" required><option value="">Seleccionar</option><option value="conforme">Conforme</option><option value="no_conforme">No conforme</option></select></label>
                    <label><span>Dosificador al término *</span><select name="dosificador_operativo_final" required><option value="">Seleccionar</option><option value="1">Operativo</option><option value="0">No operativo</option></select></label>
                    <fieldset class="destination-choice"><legend>Destino después del Hidrocooler *</legend><label><input name="destino_salida" type="radio" value="camara" checked required><span><strong>Cámara MP</strong><small>Queda pendiente de asignación a cámara.</small></span></label><label><input name="destino_salida" type="radio" value="proceso" required><span><strong>Directo a Fruta a proceso</strong><small>Disponible para viajes a Packing sin ubicación ficticia.</small></span></label></fieldset>
                    <label class="field-wide"><span>Observación de término</span><textarea name="observacion" maxlength="2000"></textarea></label>
                    <label class="field-wide"><span>Acción correctiva aplicada</span><textarea name="accion_correctiva" maxlength="2000" placeholder="Completar cuando exista una desviación de temperatura, agua, cloro, pH, dosificador o bombas."></textarea></label>
                </div>
                <p class="form-error" id="finishError" role="alert"></p>
                <p>Si algún control no es conforme o la fruta supera el objetivo, el lote quedará retenido para evaluación de supervisión.</p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Finalizar ciclo</button></div>
            </form>
        </dialog>

        <dialog class="hydrocooler-dialog" id="releaseDialog">
            <form method="dialog" id="releaseForm" novalidate>
                <div class="hydrocooler-dialog__heading"><div><p class="eyebrow">DISPOSICIÓN DE PRODUCTO</p><h2 id="releaseTitle">Evaluar lote retenido</h2><p id="releaseDescription"></p></div><button value="cancel" type="submit" aria-label="Cerrar">×</button></div>
                <input name="lote_id" type="hidden"><input name="operacion_id" type="hidden">
                <div class="hydrocooler-fields">
                    <label><span>Temperatura de fruta verificada °C *</span><input name="temperatura_verificacion_c" type="number" min="-20" max="50" step="0.01" required></label>
                    <label><span>Cloro libre verificado ppm *</span><input name="cloro_libre_verificacion_ppm" type="number" min="0" max="500" step="0.01" required></label>
                    <label><span>pH verificado *</span><input name="ph_agua_verificacion" type="number" min="0" max="14" step="0.01" required></label>
                    <label><span>Control de agua conforme al procedimiento vigente *</span><select name="control_verificacion_conforme" required><option value="">Seleccionar</option><option value="1">Sí, verificado conforme</option></select></label>
                    <label class="field-wide"><span>Evaluación del producto afectado *</span><textarea name="evaluacion_producto" minlength="10" maxlength="2000" required></textarea></label>
                    <label class="field-wide"><span>Acción correctiva y verificación para liberar *</span><textarea name="verificacion_liberacion" minlength="10" maxlength="2000" required></textarea></label>
                </div>
                <p class="form-error" id="releaseError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel" type="submit">Cancelar</button><button class="primary-button" value="default" type="submit">Liberar bajo responsabilidad de supervisión</button></div>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
