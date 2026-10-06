<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Maestros de temporada</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-validation.css', 'resources/css/office-validation-catalog.css', 'resources/js/office-validation-catalog.js'])
        @endif
    </head>
    <body>
        <main class="office-app" id="catalogApp">
            
            <x-office.navigation domain="administracion" office="maestros-temporada" context="ADMINISTRACIÓN · MAESTROS" icon="MT" />


            <section class="catalog-workspace">
                <header class="validation-heading panel">
                    <div><p class="eyebrow">CONFIGURACIÓN MAESTRA</p><h1>Maestros de temporada</h1><p>Base transversal consumida por Validación, Materia Prima, Inspección SAG y los demás módulos operacionales.</p></div>
                    <div class="validation-heading__actions">
                        <label><span>Temporada</span><select id="catalogSeasonSelector"></select></label>
                        <button class="secondary-button" id="catalogToggleInactive" type="button">Mostrar eliminados</button>
                        <button class="secondary-button" id="catalogReload" type="button">↻ Actualizar</button>
                    </div>
                </header>

                <section class="panel catalog-season-panel">
                    <div class="validation-panel__heading"><div><p class="eyebrow">TEMPORADA TRANSVERSAL</p><h2>Datos maestros de la temporada seleccionada</h2></div><span>Administración TI</span></div>
                    <p class="validation-help">Las temporadas se crean y activan en Accesos. Aquí TI administra sus datos maestros e importaciones; al eliminar un elemento se retira de la operación, pero se conserva su trazabilidad histórica.</p>
                </section>

                <section aria-labelledby="catalogProjectionTitle">
                    <div class="validation-panel__heading catalog-projection-heading">
                        <div><p class="eyebrow">PROYECCIÓN PDA</p><h2 id="catalogProjectionTitle">Registros activos generados</h2></div>
                        <span>Se actualizan automáticamente con la jerarquía</span>
                    </div>
                    <div class="validation-metrics catalog-projection-metrics">
                        <article><span>ARTÍCULOS</span><strong id="projectionArticleCount">0</strong></article>
                        <article><span>ORÍGENES</span><strong id="projectionOriginCount">0</strong></article>
                        <article><span>COMBINACIONES</span><strong id="projectionCombinationCount">0</strong></article>
                    </div>
                </section>

                <x-office.panel-switcher
                    id="validation-catalog"
                    label="Datos maestros de la temporada"
                    default="clients"
                    :panels="[
                        'clients' => ['label' => 'Clientes', 'icon' => '◇'],
                        'brands' => ['label' => 'Marcas', 'icon' => '◆'],
                        'categories' => ['label' => 'Categorías', 'icon' => '≡'],
                        'species' => ['label' => 'Especies', 'icon' => '✦'],
                        'varieties' => ['label' => 'Variedades', 'icon' => '⌁'],
                        'calibers' => ['label' => 'Calibres', 'icon' => '↔'],
                        'packages' => ['label' => 'Envases', 'icon' => '▣'],
                        'csg' => ['label' => 'CSG', 'icon' => '⌖'],
                        'imports' => ['label' => 'Importación', 'icon' => '⇧'],
                    ]"
                />

                <section class="panel validation-panel validation-import-panel office-panel-workspace" id="validation-catalog-panel-imports" data-office-panel-group="validation-catalog" data-office-panel-id="imports" role="tabpanel" aria-labelledby="validation-catalog-tab-imports">
                    <div class="validation-panel__heading"><div><p class="eyebrow">CARGA MASIVA</p><h2>Importar planilla de temporada</h2></div><span>CSV o XLSX · 10 MB</span></div>
                    <p class="validation-help">Columnas obligatorias: especie, variedad, calibre, envase, cliente, marca y CSG. Categoría, predio y códigos externos son opcionales; la previsualización no publica cambios hasta confirmar.</p>
                    <form class="validation-form" id="importForm" enctype="multipart/form-data">
                        <label class="validation-file"><span>Archivo de temporada *</span><input name="archivo" type="file" accept=".csv,.txt,.xlsx" required></label>
                        <p class="form-error" id="importError" role="alert"></p>
                        <div class="validation-actions"><button class="primary-button" type="submit">Previsualizar importación</button></div>
                    </form>
                    <div class="import-preview is-hidden" id="importPreview"></div>
                    <div class="validation-list" id="importList"></div>
                </section>

                <div class="catalog-columns catalog-columns--three office-panel-workspace">
                    <section class="panel catalog-card" id="validation-catalog-panel-clients" data-office-panel-group="validation-catalog" data-office-panel-id="clients" role="tabpanel" aria-labelledby="validation-catalog-tab-clients">
                        <div class="validation-panel__heading"><div><p class="eyebrow">MAESTRO TRANSVERSAL</p><h2>Clientes</h2></div><span id="clientCount">0</span></div>
                        <p class="validation-help">Los clientes se crean y modifican exclusivamente en Accesos. Los módulos operacionales los consumen para asociar sus marcas dentro de la temporada.</p>
                        <div class="validation-list" id="clientList"></div>
                    </section>

                    <section class="panel catalog-card" id="validation-catalog-panel-brands" data-office-panel-group="validation-catalog" data-office-panel-id="brands" role="tabpanel" aria-labelledby="validation-catalog-tab-brands">
                        <div class="validation-panel__heading"><div><p class="eyebrow">CLIENTE → MARCA</p><h2>Marcas</h2></div><span id="brandCount">0</span></div>
                        <form class="catalog-form" id="brandForm">
                            <input name="id" type="hidden">
                            <label><span>Cliente *</span><select name="cliente_validacion_id" required></select></label>
                            <label><span>Nombre *</span><input name="nombre" maxlength="150" required></label>
                            <label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label>
                            <label class="validation-check"><input name="activo" type="checkbox" checked><span>Activa</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="brandForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar marca</button></div>
                        </form>
                        <p class="form-error" id="brandError"></p><div class="validation-list" id="brandList"></div>
                    </section>

                    <section class="panel catalog-card" id="validation-catalog-panel-categories" data-office-panel-group="validation-catalog" data-office-panel-id="categories" role="tabpanel" aria-labelledby="validation-catalog-tab-categories">
                        <div class="validation-panel__heading"><div><p class="eyebrow">INDEPENDIENTE</p><h2>Categorías</h2></div><span id="categoryCount">0</span></div>
                        <form class="catalog-form" id="categoryForm">
                            <input name="id" type="hidden">
                            <label><span>Nombre *</span><input name="nombre" maxlength="100" required></label>
                            <label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label>
                            <label class="validation-check"><input name="activo" type="checkbox" checked><span>Activa</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="categoryForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar categoría</button></div>
                        </form>
                        <p class="form-error" id="categoryError"></p><div class="validation-list" id="categoryList"></div>
                    </section>
                </div>

                <div class="catalog-columns office-panel-workspace">
                    <section class="panel catalog-card" id="validation-catalog-panel-species" data-office-panel-group="validation-catalog" data-office-panel-id="species" role="tabpanel" aria-labelledby="validation-catalog-tab-species">
                        <div class="validation-panel__heading"><div><p class="eyebrow">BASE PRODUCTIVA</p><h2>Especies</h2></div><span id="speciesCount">0</span></div>
                        <form class="catalog-form" id="speciesForm">
                            <input name="id" type="hidden">
                            <label><span>Nombre *</span><input name="nombre" maxlength="100" required></label>
                            <label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label>
                            <label class="validation-check"><input name="activo" type="checkbox" checked><span>Activa</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="speciesForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar especie</button></div>
                        </form>
                        <p class="form-error" id="speciesError"></p><div class="validation-list" id="speciesList"></div>
                    </section>

                    <section class="panel catalog-card" id="validation-catalog-panel-varieties" data-office-panel-group="validation-catalog" data-office-panel-id="varieties" role="tabpanel" aria-labelledby="validation-catalog-tab-varieties">
                        <div class="validation-panel__heading"><div><p class="eyebrow">ESPECIE → VARIEDAD</p><h2>Variedades</h2></div><span id="varietyCount">0</span></div>
                        <form class="catalog-form" id="varietyForm">
                            <input name="id" type="hidden">
                            <label><span>Especie *</span><select name="especie_validacion_id" required></select></label>
                            <label><span>Nombre *</span><input name="nombre" maxlength="100" required></label>
                            <label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label>
                            <label class="validation-check"><input name="activo" type="checkbox" checked><span>Activa</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="varietyForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar variedad</button></div>
                        </form>
                        <p class="form-error" id="varietyError"></p><div class="validation-list" id="varietyList"></div>
                    </section>
                </div>

                <div class="catalog-columns catalog-columns--three office-panel-workspace">
                    <section class="panel catalog-card" id="validation-catalog-panel-calibers" data-office-panel-group="validation-catalog" data-office-panel-id="calibers" role="tabpanel" aria-labelledby="validation-catalog-tab-calibers">
                        <div class="validation-panel__heading"><div><p class="eyebrow">POR ESPECIE</p><h2>Calibres</h2></div><span id="caliberCount">0</span></div>
                        <form class="catalog-form" id="caliberForm">
                            <input name="id" type="hidden"><label><span>Especie *</span><select name="especie_validacion_id" required></select></label><label><span>Calibre *</span><input name="nombre" maxlength="50" required></label><label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label><label class="validation-check"><input name="activo" type="checkbox" checked><span>Activo</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="caliberForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar calibre</button></div>
                        </form><p class="form-error" id="caliberError"></p><div class="validation-list" id="caliberList"></div>
                    </section>
                    <section class="panel catalog-card" id="validation-catalog-panel-packages" data-office-panel-group="validation-catalog" data-office-panel-id="packages" role="tabpanel" aria-labelledby="validation-catalog-tab-packages">
                        <div class="validation-panel__heading"><div><p class="eyebrow">POR ESPECIE</p><h2>Envases</h2></div><span id="packageCount">0</span></div>
                        <form class="catalog-form" id="packageForm">
                            <input name="id" type="hidden"><label><span>Especie *</span><select name="especie_validacion_id" required></select></label><label><span>Envase *</span><input name="nombre" maxlength="100" required></label><label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label><label><span>Cliente *</span><select name="cliente_validacion_id" required></select></label><label><span>Kilos netos por caja</span><input name="kilos_netos_por_caja" type="number" step="0.0001" min="0.0001" max="999999" placeholder="Sin configurar"></label><label class="validation-check"><input name="activo" type="checkbox" checked><span>Activo</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="packageForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar envase</button></div>
                        </form><p class="form-error" id="packageError"></p><div class="validation-list" id="packageList"></div>
                    </section>
                    <section class="panel catalog-card" id="validation-catalog-panel-csg" data-office-panel-group="validation-catalog" data-office-panel-id="csg" role="tabpanel" aria-labelledby="validation-catalog-tab-csg">
                        <div class="validation-panel__heading"><div><p class="eyebrow">CSG → VARIEDADES</p><h2>CSG</h2></div><span id="csgCount">0</span></div>
                        <form class="catalog-form" id="csgForm">
                            <input name="id" type="hidden"><label><span>Código CSG *</span><input name="codigo" maxlength="50" required></label><label><span>Predio</span><input name="predio" maxlength="150"></label><label><span>Código externo</span><input name="codigo_externo" maxlength="100"></label>
                            <fieldset class="catalog-varieties"><legend>Variedades autorizadas *</legend><div id="csgVarietyOptions"></div></fieldset>
                            <label class="validation-check"><input name="activo" type="checkbox" checked><span>Activo</span></label>
                            <div class="catalog-actions"><button class="secondary-button" data-reset-form="csgForm" type="button">Limpiar</button><button class="primary-button" type="submit">Guardar CSG</button></div>
                        </form><p class="form-error" id="csgError"></p><div class="validation-list" id="csgList"></div>
                    </section>
                </div>
            </section>
        </main>
        <div class="loading is-hidden" id="catalogLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="catalogLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="catalogToasts" aria-live="polite"></div>
    </body>
</html>
