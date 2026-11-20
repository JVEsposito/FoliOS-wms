<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark">
    <title>FoliOS · Diagramas SAG/USDA</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-sag-pallet-diagrams.css', 'resources/js/office-sag-pallet-diagrams.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <section class="office-access" id="officeAccess">
        <div class="office-access__brand"><p class="eyebrow">FRIGORÍFICO · SAG/USDA</p><h1>Diagramas de pallets</h1><p>Presentación de pallets con numeración continua de cajas.</p></div>
        <form class="office-access__form" id="officeLoginForm"><h2>Ingresar</h2>
            <label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label>
            <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="primary-button">Ingresar</button><p id="officeLoginError" role="alert"></p>
        </form>
    </section>
    <main class="office-app is-hidden" id="officeApp">
        <x-office.navigation domain="frigorifico" office="diagramas-sag" context="FRIGORÍFICO · SAG" icon="SAG" />
        <section class="pd-workspace">
            <header class="pd-heading">
                <div><p class="eyebrow">PRESENTACIÓN DE PALLETS · INSPECCIÓN SAG/USDA</p><h1 id="pdTitle">DIAGRAMA USDA</h1><p class="pd-help" id="pdSeason"></p></div>
                <div class="pd-actions">
                    <button class="secondary-button" type="button" id="pdNew">Nuevo diagrama</button>
                    <button class="secondary-button" type="button" id="pdOpenSaved" aria-expanded="false" aria-controls="pdSaved">Diagramas guardados</button>
                    <button class="primary-button" type="button" id="pdSave">Guardar diagrama</button>
                </div>
            </header>
            <p class="pd-help">Diagrama de presentación interno. No constituye aprobación del SAG ni del USDA.</p>
            <p class="pd-message" id="pdMessage" role="status"></p>
            <div class="pd-warning is-hidden" id="pdWarning" role="alert"><span id="pdWarningText"></span><div class="pd-warning__actions"><button class="secondary-button" type="button" id="pdDropMissing">Quitar esas marcas</button><button class="secondary-button" type="button" id="pdWarningOk">Entendido</button></div></div>

            <section class="panel is-hidden" id="pdSaved">
                <div class="pd-panel-heading"><h2>Diagramas guardados</h2><p class="pd-help" id="pdSavedSeason"></p></div>
                <form class="pd-saved-filters" id="pdSavedFilters">
                    <label>Buscar<input name="texto" maxlength="100" placeholder="N.º, lote, folio o exportadora"></label>
                    <button class="secondary-button">Buscar / actualizar</button>
                </form>
                <div class="pd-scroll"><table><thead><tr><th>Diagrama</th><th>Fecha / lote</th><th>Exportadora</th><th>Pallets / cajas</th><th>Actualización</th><th></th></tr></thead><tbody id="pdSavedRows"></tbody></table></div>
                <div class="pd-actions" id="pdSavedPages"></div>
            </section>

            <section class="panel">
                <div class="pd-panel-heading"><h2>Datos del diagrama</h2><p class="pd-help" id="pdVersion">Diagrama nuevo, sin guardar.</p></div>
                <form class="pd-grid" id="pdForm">
                    <label>Inspección<select name="tipo"><option value="usda">USDA</option><option value="sag">SAG</option></select></label>
                    <label>N.º de diagrama<input name="numero" maxlength="30" autocomplete="off" placeholder="Ej.: 8"></label>
                    <label>CSP<input name="csp" maxlength="50" autocomplete="off"></label>
                    <label>Folio<input name="folio" maxlength="60" autocomplete="off"></label>
                    <label>Fecha<input name="fecha" type="date"></label>
                    <label>Lote<input name="lote" maxlength="100" autocomplete="off"></label>
                    <label>Especie<input name="especie" maxlength="80" list="pdSpecies" autocomplete="off"></label>
                    <label>Exportadora<input name="exportadora" maxlength="150" list="pdExporters" autocomplete="off"></label>
                </form>
                <datalist id="pdSpecies"></datalist><datalist id="pdExporters"></datalist>
            </section>

            <section class="panel">
                <div class="pd-panel-heading"><h2>Pallets</h2><p class="pd-totals" id="pdTotals"></p></div>
                <form class="pd-quick" id="pdQuick">
                    <label>Cajas por base<input name="base" type="number" min="1" max="50" step="1" inputmode="numeric"></label>
                    <label>Corridas de altura<input name="corridas" type="number" min="1" max="50" step="1" inputmode="numeric"></label>
                    <label>Última corrida incompleta<input name="ultima" type="number" min="1" max="49" step="1" inputmode="numeric" placeholder="Completa"></label>
                    <div class="pd-quick__buttons"><button class="secondary-button" type="button" data-apply="todos">Aplicar a todos</button><button class="secondary-button" type="button" data-apply="vacios">Aplicar a los sin configurar</button></div>
                </form>
                <div class="pd-scroll">
                    <table class="pd-table"><thead><tr><th>Pallet</th><th>Folio</th><th>Cajas por base</th><th>Corridas</th><th>Última corrida incompleta</th><th>Cajas declaradas</th><th>Total</th><th>Numeración</th><th>Marcadas</th><th></th></tr></thead><tbody id="pdPallets"></tbody></table>
                </div>
                <p class="pd-help">«Última corrida incompleta» va vacía si todas las corridas están completas. «Cajas declaradas» es opcional: si la escribes, se compara con la configuración.</p>
                <div class="pd-actions"><button class="secondary-button" type="button" id="pdAddPallet">Agregar pallet</button></div>
            </section>

            <section class="panel">
                <div class="pd-panel-heading"><h2>Marcar cajas</h2></div>
                <form class="pd-mark-form" id="pdMarkForm">
                    <label>Números de caja<input id="pdMarkInput" autocomplete="off" placeholder="Ej.: 5, 82, 145, 206 y 411"></label>
                    <button class="primary-button">Marcar</button>
                    <button class="secondary-button" type="button" id="pdClearMarks">Limpiar marcaciones</button>
                </form>
                <p class="pd-help">Separa los números con comas, espacios o «y»; un rango se escribe 10-15. También puedes marcar o desmarcar con un clic sobre la caja.</p>
                <p id="pdMarkResult" role="status"></p>
            </section>

            <section class="panel">
                <div class="pd-panel-heading">
                    <h2>Diagrama</h2>
                    <div class="pd-actions">
                        <label class="pd-check"><input type="checkbox" id="pdShowNumbers" checked> Mostrar números</label>
                        <label>Papel<select id="pdPaper"><option value="a4">A4 horizontal</option><option value="a3">A3 horizontal</option></select></label>
                        <button class="secondary-button" type="button" data-export="pdf">Descargar PDF</button>
                        <button class="secondary-button" type="button" data-export="png">Descargar PNG</button>
                        <button class="secondary-button" type="button" data-export="print">Imprimir</button>
                    </div>
                </div>
                <p class="pd-legend"><span class="pd-swatch" aria-hidden="true"></span>Caja marcada. Cada corrida se numera de izquierda a derecha y de abajo hacia arriba; la numeración sigue de un pallet al siguiente.</p>
                <div class="pd-diagram" id="pdDiagram"></div>
            </section>

            <section class="panel">
                <div class="pd-panel-heading"><h2>Resumen de pallets y cajas marcadas</h2><p class="pd-help">c = corrida contada desde abajo · p = posición contada desde la izquierda</p></div>
                <div class="pd-scroll"><table class="pd-summary"><thead><tr><th>Pallet</th><th>Folio</th><th>Base × corridas</th><th>Cajas</th><th>Numeración</th><th>Cajas marcadas</th></tr></thead><tbody id="pdSummary"></tbody><tfoot id="pdSummaryTotal"></tfoot></table></div>
            </section>
        </section>
        <dialog class="pd-zoom" id="pdZoom" aria-labelledby="pdZoomTitle">
            <header><h2 id="pdZoomTitle">Pallet</h2><div class="pd-actions"><button class="secondary-button" type="button" id="pdZoomPrev">Anterior</button><button class="secondary-button" type="button" id="pdZoomNext">Siguiente</button><button class="primary-button" type="button" id="pdZoomClose">Cerrar</button></div></header>
            <div class="pd-zoom__body" id="pdZoomBody"></div>
        </dialog>
    </main>
</body>
</html>
