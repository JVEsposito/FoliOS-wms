<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Configuración del asistente</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/office-assistant-settings.css',
                'resources/js/office-assistant-settings.js',
            ])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <main class="office-app assistant-settings is-hidden" id="assistantSettingsApp" data-folios-workspace="analisis">
            <x-office.navigation
                domain="administracion"
                office="asistente"
                context="ADMINISTRACIÓN & GERENCIA"
                icon="?"
            />

            <div class="assistant-settings__shell">
                <header class="assistant-settings__heading" data-folios-masthead>
                    <div>
                        <p class="eyebrow">ASISTENTE DE OFICINA</p>
                        <h1>Configuración del asistente</h1>
                        <p>Modo, topes de Haiku, piloto, compuerta, rangos de tara y registro de preguntas. El asistente es de solo lectura.</p>
                    </div>
                    <div class="assistant-settings__actions">
                        <button class="secondary-button" id="assistantReload" type="button">↻ Actualizar</button>
                        <button class="primary-button" id="assistantSave" type="button">Guardar cambios</button>
                    </div>
                </header>

                <p class="assistant-settings__error is-hidden" id="assistantError" role="alert"></p>

                <section class="assistant-settings__metrics" aria-label="Consumo de Haiku" data-folios-metrics>
                    <article>
                        <span>MODO</span>
                        <strong id="assistantModeLabel">Sin IA</strong>
                        <small id="assistantModelLabel">—</small>
                    </article>
                    <article>
                        <span>GASTO DEL MES</span>
                        <strong id="assistantSpend">US$ 0,00</strong>
                        <small id="assistantSpendDetail">de US$ 20,00</small>
                        <div class="assistant-settings__bar" aria-hidden="true"><i id="assistantSpendBar"></i></div>
                    </article>
                    <article>
                        <span>LLAMADAS DEL MES</span>
                        <strong id="assistantCalls">0</strong>
                        <small id="assistantCallsDetail">0 con error</small>
                    </article>
                    <article>
                        <span>COMPUERTA</span>
                        <strong id="assistantGate">Sin medir</strong>
                        <small id="assistantGateDetail">Mide los últimos 7 días del registro.</small>
                    </article>
                </section>

                <div class="assistant-settings__grid">
                    <section class="panel assistant-settings__panel">
                        <p class="eyebrow">MODO</p>
                        <h2>Quién interpreta las preguntas</h2>
                        <fieldset class="assistant-settings__modes" id="assistantModes">
                            <label><input type="radio" name="modo" value="sin_ia"> <span><strong>Sin IA</strong><small>Solo reglas. Nada sale de la planta.</small></span></label>
                            <label><input type="radio" name="modo" value="mixto"> <span><strong>Mixto con Haiku</strong><small>Las reglas responden lo que entienden con certeza; el resto lo interpreta Haiku y FoliOS calcula las cifras.</small></span></label>
                        </fieldset>
                        <p class="assistant-settings__hint is-hidden" id="assistantNoKey">Falta <code>ANTHROPIC_API_KEY</code> en el <code>.env</code> del servidor: el modo mixto no se puede activar.</p>
                    </section>

                    <section class="panel assistant-settings__panel">
                        <p class="eyebrow">TOPES</p>
                        <h2>Gasto y límite diario</h2>
                        <div class="assistant-settings__fields">
                            <label class="field">Tope mensual (US$)
                                <input id="assistantBudget" type="number" min="0" max="200" step="0.5" inputmode="decimal">
                            </label>
                            <label class="field">Preguntas a Haiku por usuario y jornada
                                <input id="assistantDaily" type="number" min="1" max="200" step="1" inputmode="numeric">
                            </label>
                        </div>
                        <p class="assistant-settings__hint">Al llegar a un tope, el asistente responde sin IA y dice por qué. Vacío, rige el valor del servidor.</p>
                    </section>

                    <section class="panel assistant-settings__panel assistant-settings__panel--wide">
                        <p class="eyebrow">PILOTO</p>
                        <h2>Usuarios con Haiku</h2>
                        <p class="assistant-settings__hint">Marca de 3 a 5 usuarios para el piloto de dos semanas. Sin marcas, Haiku queda para todos los que usan el asistente.</p>
                        <div class="assistant-settings__pilot" id="assistantPilot" role="group" aria-label="Usuarios del piloto"></div>
                    </section>

                    <section class="panel assistant-settings__panel assistant-settings__panel--wide">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">COMPUERTA</p>
                                <h2>¿Se justifica Haiku?</h2>
                            </div>
                            <button class="secondary-button" id="assistantMeasure" type="button">Medir 7 días</button>
                        </div>
                        <div id="assistantGateReport" class="assistant-settings__report">
                            <p class="assistant-settings__hint">Compara el registro real con el umbral de 15 % de preguntas sin consulta y revisa que el banco no tenga respuestas falsas.</p>
                        </div>
                    </section>

                    <section class="panel assistant-settings__panel">
                        <p class="eyebrow">CONSUMO</p>
                        <h2>Por usuario este mes</h2>
                        <table class="assistant-settings__table">
                            <thead><tr><th>Usuario</th><th>Llamadas</th><th>US$</th></tr></thead>
                            <tbody id="assistantUsageUsers"><tr><td colspan="3">Sin llamadas este mes.</td></tr></tbody>
                        </table>
                    </section>

                    <section class="panel assistant-settings__panel">
                        <p class="eyebrow">REVISIÓN DE TARAS</p>
                        <h2>Margen y rangos de neto por envase</h2>
                        <label class="field">Margen de revisión (desviaciones)
                            <input id="assistantMargin" type="number" min="0.5" max="5" step="0.1" inputmode="decimal">
                        </label>
                        <table class="assistant-settings__table">
                            <thead><tr><th>Envase</th><th>Especie</th><th>Mínimo (kg)</th><th>Máximo (kg)</th><th>Activo</th></tr></thead>
                            <tbody id="assistantRanges"></tbody>
                        </table>
                    </section>

                    <section class="panel assistant-settings__panel assistant-settings__panel--wide" data-folios-register>
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">REGISTRO</p>
                                <h2>Preguntas recientes</h2>
                            </div>
                            <label class="field assistant-settings__filter">Estado
                                <select id="assistantQuestionsFilter">
                                    <option value="">Todas</option>
                                    <option value="sugerencias">Sin consulta (sugerencias)</option>
                                    <option value="sin_datos">Sin datos</option>
                                    <option value="respondida">Respondidas</option>
                                </select>
                            </label>
                        </div>
                        <table class="assistant-settings__table">
                            <thead><tr><th>Fecha</th><th>Usuario</th><th>Pregunta</th><th>Consulta</th><th>Estado</th></tr></thead>
                            <tbody id="assistantQuestions"></tbody>
                        </table>
                    </section>
                </div>
            </div>

            <div class="toast-region" id="assistantToasts" aria-live="polite"></div>
        </main>
    </body>
</html>
