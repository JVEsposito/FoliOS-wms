<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Defectos de recepción MP</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-raw-material-defects.css', 'resources/js/office-raw-material-defects.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
        <div class="office-access__brand"><div class="office-logo" aria-hidden="true">MP</div><p class="eyebrow">FoliOS · MATERIA PRIMA</p>
            <h1 id="officeAccessTitle">Auditoría de defectos de recepción</h1><p>Consulta registros y fotografías capturados por Validación MP, con respaldo descargable.</p></div>
        <form class="office-access__form" id="officeLoginForm" novalidate>
            <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a auditoría</h2><p>Disponible para supervisión autorizada y administración.</p></div>
            <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
            <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
            <p class="form-error" id="officeLoginError" role="alert"></p><button class="primary-button" type="submit">Ingresar <span>→</span></button>
        </form>
    </section>
    <main class="office-app is-hidden" id="officeApp">
        <x-office.navigation domain="materia-prima" office="defectos-recepcion" context="MATERIA PRIMA" icon="!" />
        <section class="defect-workspace" id="officeContent">
            <header class="defect-heading"><div><p class="eyebrow">CONTROL DE CALIDAD · MATERIA PRIMA</p><h1>Defectos de recepción</h1><p>Registro fotográfico vinculado a la recepción y a la temporada original.</p></div><button class="secondary-button" id="refreshDefects" type="button">↻ Actualizar</button></header>
            <section class="defect-panel" aria-label="Filtros de auditoría">
                <form id="defectFilters" class="defect-filters">
                    <label>Temporada<select name="temporada_id" id="defectSeason"><option value="">Cargando temporadas…</option></select></label>
                    <label>Desde<input name="desde" type="date"></label><label>Hasta<input name="hasta" type="date"></label>
                    <label>Categoría<select name="categoria"><option value="">Todas las categorías</option><option value="envase_danado">Envase dañado</option><option value="envase_sucio">Envase sucio</option><option value="producto_danado">Producto dañado</option><option value="otro">Otro</option></select></label>
                    <label class="defect-filters__search">Guía, recepción o cliente<input name="buscar" type="search" maxlength="80" placeholder="Buscar…"></label>
                    <button class="primary-button" type="submit">Aplicar filtros</button>
                </form>
                <div class="defect-export"><p>Descarga los registros filtrados. El ZIP incluye planilla, PDF y fotos originales.</p><div><button class="secondary-button" data-defect-export="xlsx" type="button">Excel</button><button class="secondary-button" data-defect-export="pdf" type="button">PDF</button><button class="secondary-button" data-defect-export="zip" type="button">ZIP con fotos</button></div></div>
            </section>
            <section class="defect-panel" aria-labelledby="defectResultsTitle"><div class="defect-results-heading"><h2 id="defectResultsTitle">Registros</h2><span id="defectCount">—</span></div>
                <div class="defect-table-wrap"><table class="defect-table"><thead><tr><th>Fecha</th><th>Recepción / guía</th><th>Cliente</th><th>Defecto</th><th>Validador</th><th>Evidencias</th></tr></thead><tbody id="defectRows"><tr><td colspan="6">Consultando registros…</td></tr></tbody></table></div>
                <nav class="defect-pages" aria-label="Páginas de resultados"><button id="defectPrevious" class="secondary-button" type="button">← Anterior</button><span id="defectPage">Página 1</span><button id="defectNext" class="secondary-button" type="button">Siguiente →</button></nav>
            </section>
        </section>
    </main>
    <dialog id="defectDialog" class="defect-dialog" aria-labelledby="defectDialogTitle"><div class="defect-dialog__head"><div><p class="eyebrow">EVIDENCIA DE RECEPCIÓN</p><h2 id="defectDialogTitle">Detalle del defecto</h2></div><button type="button" id="closeDefect" aria-label="Cerrar detalle">×</button></div><div id="defectDetail"></div></dialog>
    <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div><div class="toast-region" id="officeToasts" aria-live="polite"></div>
</body>
</html>
