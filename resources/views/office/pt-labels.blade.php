<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Etiquetas PT</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-pt-labels.css', 'resources/js/office-pt-labels.js'])
    @endif
</head>
<body>
    <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
        <div class="office-access__brand">
            <div class="office-logo" aria-hidden="true">▥</div>
            <p class="eyebrow">FRIGORÍFICO · PRODUCTO TERMINADO</p>
            <h1 id="officeAccessTitle">Cada pallet con su etiqueta.</h1>
            <p>Imprime etiquetas de planta, folios y ventanas de pallets y saldos vigentes.</p>
        </div>
        <form class="office-access__form" id="officeLoginForm">
            <h2>Ingresar a Etiquetas PT</h2>
            <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
            <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
            <p class="form-error" id="officeLoginError" role="alert"></p>
            <button class="primary-button" type="submit">Ingresar</button>
        </form>
    </section>
    <main class="office-app is-hidden" id="officeApp">
        <x-office.navigation domain="frigorifico" office="etiquetas-pt" context="FRIGORÍFICO · PT" icon="▥" />
        <div class="pt-labels-workspace">
            <header class="panel pt-labels-heading">
                <div><p class="eyebrow">IMPRESIÓN DE PALLETS</p><h1>Etiquetas PT</h1><p id="ptSeason">Cargando temporada…</p></div>
                <a class="secondary-button" href="/oficina/validacion">Revisar validaciones</a>
            </header>
            <p id="ptMessage" role="status" aria-live="polite"></p>
            <section class="panel pt-labels-panel" aria-labelledby="ptListTitle">
                <h2 id="ptListTitle">Pallets y saldos vigentes</h2>
                <p>Se muestran folios activos de la temporada activa. Revisa sus datos antes de seleccionar. Los datos corresponden al inventario actual.</p>
                <form id="ptFilters" class="pt-labels-filters">
                    <label>Folio exacto<input name="folio" maxlength="50" placeholder="Incluye los ceros iniciales"></label>
                    <label>Origen<select name="origen"><option value="">Todos</option><option value="validacion">Validación</option><option value="repaletizaje">Repaletizaje</option></select></label>
                    <label>Fecha de validación / repa<input name="fecha" type="date"></label>
                    <label>Línea<select name="linea_proceso"><option value="">Todas</option><option>1</option><option>2</option><option>3</option></select></label>
                    <label>Turno<select name="turno"><option value="">Todos</option><option>A</option><option>B</option></select></label>
                    <button class="secondary-button" type="submit">Buscar / actualizar</button>
                </form>
                <div class="pt-labels-scroll"><table class="pt-labels-table">
                    <thead><tr><th><input id="ptSelectAll" type="checkbox" aria-label="Seleccionar todos los folios de esta página"></th><th>Folio / cajas</th><th>Artículo</th><th>Cliente / CSG</th><th>Origen</th><th>Revisar</th></tr></thead>
                    <tbody id="ptRows"></tbody>
                </table></div>
                <div class="pt-labels-actions"><button class="secondary-button" id="ptPrevious" type="button">Anterior</button><span id="ptPage"></span><button class="secondary-button" id="ptNext" type="button">Siguiente</button></div>
            </section>
            <section class="panel pt-labels-panel" aria-labelledby="ptPrintTitle">
                <h2 id="ptPrintTitle">Preparar etiquetas</h2>
                <p id="ptSelection">0 folios seleccionados</p>
                <p id="ptMissingWeights" class="form-error is-hidden" role="alert"></p>
                <form id="ptPrintForm" class="pt-labels-filters">
                    <label>Formato<select name="tipo"><option value="planta">Planta · 107 × 74 mm</option><option value="ventana">Ventana · 100 × 200 mm</option><option value="folio">Folio · 100 × 50 mm</option></select></label>
                    <label>Copias por folio<input name="copias" type="number" min="1" max="10" value="4" required></label>
                    <label class="pt-labels-reason">Motivo de reimpresión<input name="motivo_reimpresion" minlength="5" maxlength="1000" placeholder="Obligatorio si ya generaste este formato"></label>
                    <button class="primary-button" id="ptGenerate" type="submit" disabled>Generar PDF</button>
                </form>
                <p>Imprime el PDF al 100 % / tamaño real, sin ajustar a página. La ventana incluye composición y trazabilidad; el folio muestra un resumen. La generación queda registrada y no confirma que la impresora haya terminado.</p>
                <div id="ptPdfResult" class="is-hidden">
                    <a id="ptPdfOpen" class="primary-button" target="_blank" rel="noopener">Abrir PDF para imprimir</a>
                    <a id="ptPdfDownload" class="secondary-button" download="etiquetas-pt.pdf">Descargar PDF</a>
                    <iframe id="ptPdfPreview" title="PDF de etiquetas PT" class="pt-labels-pdf"></iframe>
                </div>
            </section>
            <section class="panel pt-labels-panel" aria-labelledby="ptHistoryTitle">
                <h2 id="ptHistoryTitle">Historial de generación</h2>
                <p>Últimas 25 generaciones de esta temporada.</p>
                <div class="pt-labels-scroll"><table class="pt-labels-table"><thead><tr><th>Fecha / usuario</th><th>Formato / copias</th><th>Folios</th><th>Motivo</th></tr></thead><tbody id="ptHistory"></tbody></table></div>
            </section>
        </div>
        <dialog id="ptReview" class="pt-labels-dialog">
            <h2 id="ptReviewTitle">Revisar pallet</h2>
            <dl id="ptDetails"></dl>
            <div id="ptComposition"></div>
            <p>Para corregir datos, vuelve a Validación con una cuenta autorizada y después actualiza este listado.</p>
            <button class="secondary-button" id="ptReviewClose" type="button">Cerrar</button>
        </dialog>
    </main>
</body>
</html>
