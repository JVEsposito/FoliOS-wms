<!DOCTYPE html>
<html lang="es">
    @php
        $activeMaterialsSection = $materialsSection ?? 'resumen';
        $materialsSectionMeta = [
            'resumen' => [
                'eyebrow' => 'CONTROL DE MATERIALES',
                'title' => 'Resumen operacional',
                'description' => 'Selecciona el proceso que necesitas sin recorrer una página continua.',
            ],
            'catalogos' => [
                'eyebrow' => 'CONFIGURACIÓN',
                'title' => 'Catálogos de materiales',
                'description' => 'Administra proveedores, ítems y destinos asociados a la temporada global.',
            ],
            'recepcion' => [
                'eyebrow' => 'RECEPCIÓN',
                'title' => 'Etiquetas de materiales',
                'description' => 'Selecciona una recepción u orden y genera sus etiquetas PDF o NiceLabel/ZebraDesigner.',
            ],
            'recepciones' => [
                'eyebrow' => 'RECEPCIÓN',
                'title' => 'Recepciones de materiales',
                'description' => 'Registra, consulta y controla los ingresos de materiales por guía y folio.',
            ],
            'vencimientos' => [
                'eyebrow' => 'CONTROL DE VENCIMIENTO',
                'title' => 'Vencimientos de materiales',
                'description' => 'Revisa fechas, existencias y ubicación en todos los almacenes.',
            ],
            'inventario' => [
                'eyebrow' => 'INVENTARIO BC · BODEGA CENTRAL',
                'title' => 'Inventario físico por folio y cliente',
                'description' => 'Consulta cantidades, ubicación, reservas y bloqueos de la Bodega Central.',
            ],
            'despachos' => [
                'eyebrow' => 'DESPACHOS INTERNOS',
                'title' => 'Solicitudes de materiales',
                'description' => 'Prepara solicitudes, reserva existencia y controla sus estados.',
            ],
            'recetas' => [
                'eyebrow' => 'TRANSFORMACIÓN',
                'title' => 'Recetas de materiales',
                'description' => 'Define los componentes que producen materiales preparados para línea.',
            ],
            'ordenes' => [
                'eyebrow' => 'PROGRAMACIÓN',
                'title' => 'Órdenes de transformación',
                'description' => 'Planifica, reserva y sigue la ejecución de cada orden.',
            ],
        ];
        $activeMaterialsMeta = $materialsSectionMeta[$activeMaterialsSection] ?? $materialsSectionMeta['resumen'];
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Materiales</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-materials.css', 'resources/js/office-materials.js', 'resources/js/office-material-recipes.js', 'resources/js/office-material-orders.js', 'resources/js/office-material-labels.js', 'resources/js/office-material-receptions.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand materials-access-brand">
                <div class="office-logo" aria-hidden="true">▦</div>
                <p class="eyebrow">FoliOS · MATERIALES</p>
                <h1 id="officeAccessTitle">Inventario por folio, cantidades y centro de costo.</h1>
                <p>Administra ítems y destinos, prepara solicitudes y consulta el saldo físico disponible en las cámaras de materiales.</p>
                <div class="feature-row"><span>Catálogo controlado</span><span>Reserva FIFO</span><span>Kardex trazable</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a materiales</h2><p>Disponible para despachadores, supervisores y administradores.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a materiales <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp" data-materials-section="{{ $activeMaterialsSection }}">
            
            <x-office.navigation domain="materiales" :office="$navigationOffice ?? 'recepcion'" context="MATERIALES" icon="▦" />


            <section class="materials-workspace">
                <header class="materials-heading panel">
                    <div><p class="eyebrow">{{ $activeMaterialsMeta['eyebrow'] }}</p><h1>{{ $activeMaterialsMeta['title'] }}</h1><p>{{ $activeMaterialsMeta['description'] }}</p></div>
                    <button class="secondary-button" id="reloadMaterialsButton" type="button">↻ Actualizar</button>
                </header>

                <div class="materials-metrics" data-materials-view="resumen">
                    <article><span>TEMPORADA ACTIVA</span><strong id="materialsSeasonActive">—</strong></article>
                    <article><span>CLIENTES ACTIVOS</span><strong id="materialsClientCount">0</strong></article>
                    <article><span>ÍTEMS ACTIVOS</span><strong id="materialsItemCount">0</strong></article>
                    <article><span>FOLIOS CON SALDO</span><strong id="materialsFolioCount">0</strong></article>
                    <article><span>DESPACHOS ABIERTOS</span><strong id="materialsDispatchCount">0</strong></article>
                    <article><span>DESTINOS ACTIVOS</span><strong id="materialsDestinationCount">0</strong></article>
                </div>

                <section class="materials-module-overview" id="materialsModuleOverview" data-materials-view="resumen">
                    <a class="materials-module-card" href="/oficina/materiales/catalogos" data-navigation-module="materiales.catalogos" data-navigation-permissions="puede_administrar_catalogos_materiales">
                        <span aria-hidden="true">≡</span>
                        <div><p class="eyebrow">CONFIGURACIÓN</p><h2>Catálogos</h2><p>Proveedores, ítems, destinos y asociaciones por cliente.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/recepcion" data-navigation-module="materiales.etiquetas" data-navigation-permissions="puede_imprimir_etiquetas_materiales">
                        <span aria-hidden="true">▣</span>
                        <div><p class="eyebrow">RECEPCIÓN</p><h2>Etiquetas</h2><p>Folios por recepción u orden, disponibles en PDF y .nlbl editable.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/recepciones" data-navigation-module="materiales.etiquetas" data-navigation-permissions="puede_consultar_recepciones_materiales">
                        <span aria-hidden="true">↓</span>
                        <div><p class="eyebrow">INGRESO</p><h2>Recepciones</h2><p>Guías, proveedores, bultos y folios de materiales recibidos.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/inventario" data-navigation-module="materiales.inventario" data-navigation-permissions="puede_consultar_despachos_materiales">
                        <span aria-hidden="true">▦</span>
                        <div><p class="eyebrow">BODEGA CENTRAL</p><h2>Inventario BC</h2><p>Saldo físico por cliente, folio, ítem, estado y ubicación.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/almacenes" data-navigation-module="materiales.inventario" data-navigation-permissions="puede_consultar_despachos_materiales">
                        <span aria-hidden="true">⌖</span>
                        <div><p class="eyebrow">CENTROS DE COSTO</p><h2>Inventario CC</h2><p>Custodia, transferencias, consumos y existencias distribuidas por centro de costo.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/despachos" data-navigation-module="materiales.despachos" data-navigation-permissions="puede_consultar_despachos_materiales">
                        <span aria-hidden="true">↗</span>
                        <div><p class="eyebrow">OPERACIÓN</p><h2>Despachos</h2><p>Solicitudes internas, reservas y seguimiento de entrega.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/recetas" data-navigation-module="materiales.recetas" data-navigation-permissions="puede_consultar_transformaciones_materiales">
                        <span aria-hidden="true">◇</span>
                        <div><p class="eyebrow">TRANSFORMACIÓN</p><h2>Recetas</h2><p>Componentes, factores, merma y versiones activas.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/ordenes" data-navigation-module="materiales.ordenes" data-navigation-permissions="puede_consultar_transformaciones_materiales">
                        <span aria-hidden="true">✓</span>
                        <div><p class="eyebrow">PROGRAMACIÓN</p><h2>Órdenes</h2><p>Planificación, reservas FIFO y ejecución en PDA.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                    <a class="materials-module-card" href="/oficina/materiales/exportaciones" data-navigation-module="materiales.exportaciones" data-navigation-permissions="puede_consultar_despachos_materiales">
                        <span aria-hidden="true">⇩</span>
                        <div><p class="eyebrow">RESPALDOS</p><h2>Exportaciones</h2><p>Cortes XLSX y conexiones autoactualizables para Excel.</p></div>
                        <strong aria-hidden="true">→</strong>
                    </a>
                </section>

                <section class="panel materials-panel" data-materials-view="vencimientos">
                    <div class="materials-panel__heading">
                        <div><h2>Control por folio</h2><p id="materialExpirySummary" aria-live="polite"></p></div>
                        <button class="secondary-button" id="exportMaterialExpiry" type="button">Exportar Excel</button>
                    </div>
                    <div class="materials-actions" role="group" aria-label="Estado del vencimiento">
                        <button class="primary-button" data-expiry-tab="por_vencer" aria-pressed="true" type="button">Por vencer</button>
                        <button class="secondary-button" data-expiry-tab="vencido" aria-pressed="false" type="button">Vencidos</button>
                    </div>
                    <div class="materials-form__grid">
                        <label><span>Cliente</span><select id="materialExpiryClient"><option value="">Todos</option></select></label>
                        <label><span>Categoría</span><select id="materialExpiryCategory"><option value="">Todas</option></select></label>
                        <label><span>Almacén</span><select id="materialExpiryWarehouse"><option value="">Todos</option></select></label>
                    </div>
                    <p class="form-error" id="materialExpiryError" role="alert"></p>
                    <div class="materials-table-scroll"><table class="materials-table"><thead><tr><th>Folio</th><th>Cliente / ítem</th><th>Vencimiento</th><th>Estado</th><th>Cantidad</th><th>Ubicación</th><th>Acciones</th></tr></thead><tbody id="materialExpiryBody"></tbody></table></div>
                    <div class="materials-actions"><button class="secondary-button" id="materialExpiryPrevious" type="button">Anterior</button><span id="materialExpiryPage"></span><button class="secondary-button" id="materialExpiryNext" type="button">Siguiente</button></div>
                </section>
                @if ($activeMaterialsSection === 'catalogos')
                    <x-office.panel-switcher
                        id="materials-catalog"
                        label="Catálogos de materiales"
                        default="season"
                        :panels="[
                            'season' => ['label' => 'Temporada', 'icon' => '◷'],
                            'clients' => ['label' => 'Clientes', 'icon' => '◇'],
                            'providers' => ['label' => 'Proveedores', 'icon' => '⇄'],
                            'items' => ['label' => 'Ítems', 'icon' => '▤'],
                            'destinations' => ['label' => 'Destinos', 'icon' => '⌖'],
                        ]"
                    />
                @endif

                <div class="materials-admin-grid office-panel-workspace" id="materialsAdminCatalogs" data-materials-view="catalogos">
                    <section class="panel materials-panel" id="materials-catalog-panel-season" data-office-panel-group="materials-catalog" data-office-panel-id="season" role="tabpanel" aria-labelledby="materials-catalog-tab-season">
                        <div class="materials-panel__heading"><div><p class="eyebrow">TEMPORADA TRANSVERSAL</p><h2>Ciclo operacional</h2></div><span id="seasonsSummary">0 registradas</span></div>
                        <label class="materials-season-selector"><span>Temporada seleccionada</span><select id="materialSeasonSelector"></select></label>
                        <p class="materials-help">La temporada se crea, edita y activa en la oficina Accesos. Materiales administra ítems, proveedores y destinos dentro del ciclo seleccionado.</p>
                        <div class="materials-list" id="seasonsMaterialList"></div>
                    </section>

                    <section class="panel materials-panel" id="materials-catalog-panel-clients" data-office-panel-group="materials-catalog" data-office-panel-id="clients" role="tabpanel" aria-labelledby="materials-catalog-tab-clients">
                        <div class="materials-panel__heading"><div><p class="eyebrow">MAESTRO TRANSVERSAL</p><h2>Clientes de servicio</h2></div><span id="clientsSummary">0 registrados</span></div>
                        <p class="materials-help">Estos clientes provienen de Accesos y se comparten con Romana, Validación, Envases y los demás procesos. Aquí se usan para asociar ítems, inventario y proveedores.</p>
                        <div class="materials-list" id="clientsMaterialList"></div>
                    </section>

                    <section class="panel materials-panel" id="materials-catalog-panel-providers" data-office-panel-group="materials-catalog" data-office-panel-id="providers" role="tabpanel" aria-labelledby="materials-catalog-tab-providers">
                        <div class="materials-panel__heading"><div><p class="eyebrow">ABASTECIMIENTO</p><h2>Proveedores</h2></div><span id="providersSummary">0 registrados</span></div>
                        <form class="materials-form" id="providerMaterialForm" novalidate>
                            <input name="id" type="hidden">
                            <div class="materials-form__grid">
                                <label><span>Código *</span><input name="codigo" maxlength="80" placeholder="PRV-001" required></label>
                                <label><span>Nombre *</span><input name="nombre" maxlength="180" placeholder="Proveedor de embalajes" required></label>
                                <label><span>Código ERP futuro</span><input name="codigo_externo" maxlength="150"></label>
                                <label class="materials-check"><input name="activo" type="checkbox" checked><span>Proveedor activo</span></label>
                                <fieldset class="materials-provider-clients materials-wide">
                                    <legend>Clientes asociados *</legend>
                                    <div id="providerClientOptions"></div>
                                </fieldset>
                                <fieldset class="materials-provider-clients materials-wide">
                                    <legend>Categorías habilitadas *</legend>
                                    <p class="materials-help">Cada selección habilita automáticamente todos los ítems activos de esa categoría para el cliente indicado.</p>
                                    <div id="providerCategoryOptions"></div>
                                </fieldset>
                            </div>
                            <p class="form-error" id="providerMaterialError" role="alert"></p>
                            <div class="materials-actions"><button class="secondary-button is-hidden" id="cancelProviderEdit" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar proveedor</button></div>
                        </form>
                        <div class="materials-list" id="providersMaterialList"></div>
                    </section>

                    <section class="panel materials-panel" id="materials-catalog-panel-items" data-office-panel-group="materials-catalog" data-office-panel-id="items" role="tabpanel" aria-labelledby="materials-catalog-tab-items">
                        <div class="materials-panel__heading"><div><p class="eyebrow">CATÁLOGO</p><h2>Ítems seleccionables</h2></div><div class="materials-panel__tools"><span id="itemsSummary">0 registrados</span><button class="secondary-button" id="openMaterialImport" type="button">Importar catálogo</button></div></div>
                        <form class="materials-form" id="itemMaterialForm" novalidate>
                            <input name="id" type="hidden">
                            <div class="materials-form__grid">
                                <label><span>Cliente *</span><select name="cliente_material_id" required></select></label>
                                <label><span>Código *</span><input name="codigo" maxlength="80" placeholder="MAT-CAJ-010" required></label>
                                <label><span>Descripción *</span><input name="nombre" maxlength="180" placeholder="Caja cartón 10 kg" required></label>
                                <label><span>Categoría comercial</span><input name="categoria" maxlength="100" placeholder="Cajas"></label>
                                <label><span>Tipo de ítem *</span><select name="categoria_operacional" required><option value="">Selecciona un tipo</option><option value="insumo">Insumo</option><option value="material_mp">Material MP · sin preparar</option><option value="material_pt">Material PT · preparado para línea</option></select></label>
                                <label><span>Unidad *</span><input name="unidad_medida" maxlength="40" placeholder="unidades" required></label>
                                <label><span>Código ERP futuro</span><input name="codigo_externo" maxlength="150"></label>
                                <label><span>Alerta de vencimiento (días)</span><input name="dias_alerta_vencimiento" type="number" min="0" max="3650" placeholder="Predeterminado: {{ config('materiales.dias_alerta_vencimiento', 30) }}"></label>
                                <label class="materials-check"><input name="activo" type="checkbox" checked><span>Ítem activo</span></label>
                                <p class="materials-help materials-wide">El tipo determina si el ítem puede recibirse como insumo o Material MP, o generarse como Material PT mediante una receta. Los ítems sin tipo permanecen fuera de Recepción y Transformación.</p>
                            </div>
                            <p class="form-error" id="itemMaterialError" role="alert"></p>
                            <div class="materials-actions"><button class="secondary-button is-hidden" id="cancelItemEdit" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar ítem</button></div>
                        </form>
                        <div class="materials-list" id="itemsMaterialList"></div>
                    </section>

                    <dialog class="materials-import material-regularization-dialog" id="materialRegularizationDialog">
                        <form id="materialRegularizationForm" novalidate>
                            <header class="materials-import__header">
                                <div><p class="eyebrow">REGULARIZACIÓN TRAZABLE</p><h2>Consolidar Material PT duplicado</h2><p id="materialRegularizationContext"></p></div>
                                <button id="closeMaterialRegularization" type="button" aria-label="Cerrar">×</button>
                            </header>
                            <div class="material-regularization-body">
                                <aside class="material-reception-warning">El PT duplicado quedará inactivo. La historia no se reescribe y las recetas compatibles crearán una nueva versión MP → PT con el código canónico.</aside>
                                <label><span>Material MP canónico *</span><select name="item_canonico_id" required></select></label>
                                <label><span>Motivo de regularización *</span><textarea name="motivo" minlength="10" maxlength="1000" rows="3" placeholder="Ej.: ambos códigos representan físicamente el mismo material." required></textarea></label>
                                <p class="materials-help">La consolidación se bloqueará si el PT aún tiene stock, reservas, recepciones en borrador, despachos u órdenes abiertas.</p>
                                <p class="form-error" id="materialRegularizationError" role="alert"></p>
                            </div>
                            <footer class="material-reception-form__footer">
                                <button class="secondary-button" id="cancelMaterialRegularization" type="button">Cancelar</button>
                                <button class="primary-button" type="submit">Consolidar ítems</button>
                            </footer>
                        </form>
                    </dialog>

                    <section class="panel materials-panel" id="materials-catalog-panel-destinations" data-office-panel-group="materials-catalog" data-office-panel-id="destinations" role="tabpanel" aria-labelledby="materials-catalog-tab-destinations">
                        <div class="materials-panel__heading"><div><p class="eyebrow">DESTINOS</p><h2>Centros de costo</h2></div><span id="destinationsSummary">0 registrados</span></div>
                        <form class="materials-form" id="destinationMaterialForm" novalidate>
                            <input name="id" type="hidden">
                            <div class="materials-form__grid">
                                <label><span>Nombre *</span><input name="nombre" maxlength="180" placeholder="Packing cerezas" required></label>
                                <label><span>Centro de costo *</span><input name="centro_costo" maxlength="100" placeholder="CC-1205" required></label>
                                <label><span>Código ERP futuro</span><input name="codigo_externo" maxlength="150"></label>
                                <label class="materials-check"><input name="activo" type="checkbox" checked><span>Destino activo</span></label>
                                <label class="materials-wide"><span>Descripción</span><textarea name="descripcion" maxlength="1000" rows="2"></textarea></label>
                            </div>
                            <p class="form-error" id="destinationMaterialError" role="alert"></p>
                            <div class="materials-actions"><button class="secondary-button is-hidden" id="cancelDestinationEdit" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar destino</button></div>
                        </form>
                        <div class="materials-list" id="destinationsMaterialList"></div>
                    </section>
                </div>

                <section class="panel materials-panel material-receptions-workspace" id="materialReceptionsWorkspace" data-materials-view="recepciones">
                    <div class="materials-panel__heading">
                        <div>
                            <p class="eyebrow">INGRESO DE MATERIALES</p>
                            <h2>Recepciones y folios</h2>
                            <span id="materialReceptionsSummary">Cargando recepciones…</span>
                        </div>
                        <div class="materials-panel__tools">
                            <button class="secondary-button" id="downloadBlankMaterialSampling" type="button">↓ Muestreo en blanco</button>
                            <button class="secondary-button" id="reloadMaterialReceptions" type="button">↻ Actualizar</button>
                            <button class="primary-button is-hidden" id="newMaterialReception" type="button">+ Nueva recepción</button>
                        </div>
                    </div>

                    <div class="material-receptions-metrics">
                        <article><span>BORRADORES</span><strong id="materialReceptionDraftCount">0</strong></article>
                        <article><span>CONFIRMADAS</span><strong id="materialReceptionConfirmedCount">0</strong></article>
                        <article><span>ANULADAS</span><strong id="materialReceptionCancelledCount">0</strong></article>
                        <article><span>FOLIOS VISIBLES</span><strong id="materialReceptionFolioCount">0</strong></article>
                    </div>

                    <form class="material-receptions-filters" id="materialReceptionsFilters">
                        <label><span>Buscar guía</span><input name="guia" maxlength="50" placeholder="Número de guía"></label>
                        <label><span>Estado</span><select name="estado"><option value="">Todos</option><option value="borrador">Borrador</option><option value="confirmada">Confirmada</option><option value="anulada">Anulada</option></select></label>
                        <button class="secondary-button" type="submit">Buscar</button>
                    </form>

                    <p class="form-error" id="materialReceptionsError" role="alert"></p>
                    <div class="material-receptions-table-wrap">
                        <table class="materials-table material-receptions-table">
                            <thead><tr><th>Guía</th><th>Cliente / proveedor</th><th>Fecha</th><th>Estado</th><th>Ítems / folios</th><th>Acciones</th></tr></thead>
                            <tbody id="materialReceptionsList"></tbody>
                        </table>
                    </div>
                    <div class="materials-pagination">
                        <span id="materialReceptionsPageSummary">Página 1</span>
                        <div><button id="materialReceptionsPrevious" type="button">← Anterior</button><button id="materialReceptionsNext" type="button">Siguiente →</button></div>
                    </div>

                    <dialog class="materials-import material-reception-dialog" id="materialReceptionDialog">
                        <form class="material-reception-form" id="materialReceptionForm" novalidate>
                            <header class="materials-import__header">
                                <div><p class="eyebrow" id="materialReceptionDialogEyebrow">NUEVA RECEPCIÓN</p><h2 id="materialReceptionDialogTitle">Registrar recepción</h2><p id="materialReceptionDialogHelp">Los folios se asignan automáticamente al confirmar.</p></div>
                                <button id="closeMaterialReceptionDialog" type="button" aria-label="Cerrar">×</button>
                            </header>
                            <div class="material-reception-form__body">
                                <div class="materials-form__grid">
                                    <label><span>Cliente *</span><select name="cliente_id" required></select></label>
                                    <label><span>Proveedor *</span><select name="proveedor_material_id" required></select></label>
                                    <label><span>Número guía de despacho *</span><input name="numero_guia_despacho" maxlength="50" required></label>
                                    <label><span>Fecha del documento</span><input name="fecha_documento" type="date"></label>
                                    <label><span>Orden de compra</span><input name="orden_compra" maxlength="80"></label>
                                    <label><span>Patente</span><input name="patente" maxlength="20"></label>
                                    <label><span>Transportista</span><input name="transportista" maxlength="150"></label>
                                    <label class="materials-wide"><span>Observación</span><textarea name="observacion" maxlength="2000" rows="2"></textarea></label>
                                </div>

                                <div class="material-reception-lines-heading">
                                    <div><h3>Productos recibidos</h3><p>Indica la cantidad aceptada y cuántas unidades contiene cada bulto; el último se calcula con el diferencial.</p></div>
                                    <button class="secondary-button" id="addMaterialReceptionLine" type="button">+ Agregar producto</button>
                                </div>
                                <div class="material-reception-lines" id="materialReceptionLines"></div>

                                <label class="material-admin-reason is-hidden" id="materialReceptionCorrectionReason">
                                    <span>Motivo de la corrección administrativa *</span>
                                    <textarea name="motivo_correccion" minlength="5" maxlength="1000" rows="2" placeholder="Explica qué dato se corrige y por qué."></textarea>
                                </label>
                                <aside class="material-reception-warning is-hidden" id="materialReceptionConfirmedWarning">
                                    La recepción está confirmada. Solo se corregirá si sus folios no tienen movimientos posteriores. Las etiquetas impresas anteriormente deben destruirse.
                                </aside>
                                <p class="form-error" id="materialReceptionFormError" role="alert"></p>
                            </div>
                            <footer class="material-reception-form__footer">
                                <button class="secondary-button" id="cancelMaterialReception" type="button">Cancelar</button>
                                <button class="secondary-button" id="saveMaterialReceptionDraft" type="submit" value="draft">Guardar borrador</button>
                                <button class="primary-button" id="saveAndConfirmMaterialReception" type="submit" value="confirm">Guardar y confirmar</button>
                            </footer>
                        </form>
                    </dialog>

                    <dialog class="materials-import material-reception-delete-dialog" id="materialReceptionDeleteDialog">
                        <form id="materialReceptionDeleteForm" novalidate>
                            <header class="materials-import__header">
                                <div><p class="eyebrow">ACCIÓN EXCLUSIVA DEL ADMINISTRADOR</p><h2>Eliminar recepción</h2><p id="materialReceptionDeleteSummary">Esta acción elimina la recepción y libera sus folios.</p></div>
                                <button id="closeMaterialReceptionDeleteDialog" type="button" aria-label="Cerrar">×</button>
                            </header>
                            <div class="material-reception-delete-body">
                                <aside class="material-reception-warning">El historial operativo se eliminará, pero quedará una auditoría independiente. Si existen etiquetas físicas impresas, deben destruirse antes de reutilizar el folio.</aside>
                                <label><span>Motivo de eliminación *</span><textarea name="motivo" minlength="5" maxlength="1000" rows="3" required></textarea></label>
                                <p class="form-error" id="materialReceptionDeleteError" role="alert"></p>
                            </div>
                            <footer class="material-reception-form__footer">
                                <button class="secondary-button" id="cancelMaterialReceptionDelete" type="button">Cancelar</button>
                                <button class="danger-button" type="submit">Eliminar y liberar folios</button>
                            </footer>
                        </form>
                    </dialog>
                </section>

                <section class="panel materials-panel material-label-workspace" id="materialLabelWorkspace" data-materials-view="recepcion">
                    <div class="materials-panel__heading">
                        <div><p class="eyebrow">ETIQUETAS DE RECEPCIÓN</p><h2>Descarga PDF y NiceLabel</h2><span id="materialLabelSummary">Selecciona una recepción confirmada</span></div>
                        <button class="secondary-button" id="reloadMaterialLabels" type="button">↻ Actualizar recepciones</button>
                    </div>
                    <form class="materials-form material-label-form" id="materialLabelForm" novalidate>
                        <div class="materials-form__grid material-label-controls">
                            <label><span>Origen de folios *</span><select id="materialLabelSource"><option value="recepcion">Recepciones confirmadas</option><option value="transformacion">Órdenes de transformación</option></select></label>
                            <label><span>Documento / proceso *</span><select name="origen_id" id="materialLabelReception" required><option value="">Seleccionar origen</option></select></label>
                            <label><span>Perfil de impresora *</span><select name="perfil_id" id="materialLabelProfile" required><option value="">Seleccionar perfil</option></select></label>
                            <label><span>Formato *</span><select name="formato" required><option value="nlbl">NLBL · ZebraDesigner 3 / NiceLabel</option><option value="pdf">PDF · vista previa Code 128</option></select></label>
                            <label><span>Código del folio *</span><select name="simbologia" required><option value="code128">Código de barras Code 128</option><option value="qr">Código QR</option></select></label>
                            <label><span>Copias por folio *</span><input name="copias" type="number" min="1" max="20" value="1" required></label>
                            <label class="materials-wide"><span>Motivo de reimpresión</span><textarea name="motivo_reimpresion" minlength="5" maxlength="1000" rows="2" placeholder="Será obligatorio si uno de los folios ya fue generado anteriormente."></textarea></label>
                        </div>
                        <div class="material-label-selection">
                            <div class="material-label-selection__heading">
                                <strong>Folios de la recepción</strong>
                                <label class="materials-check"><input id="selectAllMaterialLabels" type="checkbox"><span>Seleccionar todos</span></label>
                            </div>
                            <div id="materialLabelFolios" class="material-label-folios"><p class="empty-state">Selecciona una recepción u orden para consultar sus folios.</p></div>
                        </div>
                        <p class="materials-help">La descarga queda auditada como archivo generado. El sistema no la marca como impresión física confirmada.</p>
                        <p class="form-error" id="materialLabelError" role="alert"></p>
                        <div class="materials-actions"><button class="primary-button" type="submit">Generar y descargar etiquetas</button></div>
                    </form>
                    <div class="material-label-history">
                        <div class="materials-panel__heading"><div><p class="eyebrow">TRAZABILIDAD</p><h3>Generaciones anteriores</h3></div></div>
                        <div id="materialLabelHistory" class="materials-list"><p class="empty-state">Sin recepción seleccionada.</p></div>
                    </div>
                </section>

                <div class="materials-operation-grid" id="materialsOperationGrid">
                    <section class="panel materials-panel" id="materialDispatchWorkspace" data-materials-view="despachos">
                        <div class="materials-panel__heading"><div><p class="eyebrow">SOLICITUD</p><h2>Nuevo despacho de materiales</h2></div><span id="materialsStockSync" aria-live="polite">Consultando stock disponible…</span></div>
                        <form class="materials-form" id="dispatchMaterialForm" novalidate>
                            <fieldset class="materials-wide"><legend>Modalidad del despacho</legend>
                                <label><input type="radio" name="modalidad" value="delegado" checked> Delegado a tablet · Bodega reserva FIFO; el camarero retira</label>
                                <label><input type="radio" name="modalidad" value="directo"> Directo desde bodega · seleccionar folios y entregar ahora</label>
                            </fieldset>
                            <label><span>Destino *</span><select name="destino_material_id" id="dispatchDestination" required></select></label>
                            <label id="dispatchAssigneeField"><span>Camarero asignado</span><select name="asignado_a_user_id" id="dispatchAssignee"><option value="">Cualquier camarero autorizado</option></select></label>
                            <label class="materials-wide"><span>Observación</span><textarea name="observacion" maxlength="1000" rows="2"></textarea></label>
                            <div class="dispatch-lines" id="dispatchMaterialLines"></div>
                            <button class="secondary-button" id="addDispatchLine" type="button">+ Agregar ítem</button>
                            <div id="dispatchFolioSelection" class="materials-wide is-hidden" aria-live="polite"></div>
                            <label id="dispatchFifoReasonField" class="materials-wide is-hidden"><span>Motivo de excepción FIFO *</span><textarea name="motivo_excepcion_fifo" minlength="5" maxlength="1000" rows="2" placeholder="Explica por qué se omite el folio más antiguo"></textarea></label>
                            <p class="form-error" id="dispatchMaterialError" role="alert"></p>
                            <div class="materials-actions"><button class="primary-button" id="dispatchSubmit" type="submit">Crear despacho y reservar</button></div>
                        </form>
                        <div class="dispatch-list" id="dispatchMaterialList"></div>
                        <dialog class="materials-import" id="materialDispatchTraceDialog">
                            <div class="materials-import__header"><div><p class="eyebrow">TRAZABILIDAD DEL DESPACHO</p><h2 id="dispatchTraceTitle">Cargando…</h2></div><button id="closeMaterialDispatchTrace" type="button" aria-label="Cerrar">×</button></div>
                            <div id="dispatchTraceBody" class="materials-import__form"></div>
                            <form id="dispatchReassignForm" class="materials-import__form is-hidden">
                                <label><span>Reasignar camarero *</span><select name="asignado_a_user_id" required></select></label>
                                <label><span>Motivo de reasignación *</span><textarea name="motivo" minlength="5" maxlength="1000" required></textarea></label>
                                <p id="dispatchReassignError" class="form-error" role="alert"></p>
                                <div class="materials-import__actions"><button type="submit" class="primary-button">Guardar reasignación</button></div>
                            </form>
                        </dialog>
                    </section>

                    <section class="panel materials-panel materials-inventory-panel" id="materialInventoryWorkspace" data-materials-view="inventario">
                        <div class="materials-panel__heading"><div><p class="eyebrow">EXISTENCIA POR CLIENTE</p><h2>Folios en cámaras</h2><span id="materialsInventorySummary">Sin existencias</span></div><div class="materials-panel__tools"><select id="materialsInventoryClient" aria-label="Filtrar inventario por cliente"><option value="">Todos los clientes</option></select><input id="materialsInventorySearch" type="search" placeholder="Buscar folio o ítem"></div></div>
                        <div class="materials-table-scroll"><table class="materials-table"><thead><tr><th>Folio</th><th>Cliente</th><th>Ítem</th><th>Actual</th><th>Reservada</th><th>Disponible</th><th>Estado</th><th>Ubicación</th><th>Acciones</th></tr></thead><tbody id="materialsInventoryBody"></tbody></table></div>
                        <div class="materials-pagination" aria-label="Paginación del inventario">
                            <label>Mostrar <select id="materialsInventoryPageSize"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
                            <div><button id="materialsInventoryPrevious" type="button">← Anterior</button><span id="materialsInventoryPage">Página 1 de 1</span><button id="materialsInventoryNext" type="button">Siguiente →</button></div>
                        </div>
                    </section>
                </div>
            </section>
        </main>
        <dialog class="materials-import" id="materialImportDialog">
            <div class="materials-import__header">
                <div><p class="eyebrow">CARGA MASIVA</p><h2>Importar catálogo de materiales</h2><p>Previsualiza los cambios antes de incorporarlos. Esta operación no crea folios ni existencias.</p></div>
                <button id="closeMaterialImport" type="button" aria-label="Cerrar">×</button>
            </div>
            <form class="materials-import__form" id="materialImportForm">
                <label><span>Planilla CSV o XLSX *</span><input name="archivo" type="file" accept=".csv,.txt,.xlsx" required></label>
                <div class="materials-import__actions"><button class="secondary-button" id="downloadMaterialTemplate" type="button">Descargar plantilla CSV</button><button class="primary-button" type="submit">Previsualizar</button></div>
                <p class="materials-import__help">Columnas: temporada_codigo, cliente_codigo, código, nombre, categoría, tipo_item, unidad_medida, código_externo y activo. La temporada debe existir y el cliente debe estar creado previamente en Accesos. Máximo 5.000 filas.</p>
                <p class="form-error" id="materialImportError" role="alert"></p>
            </form>
            <section class="materials-import__preview is-hidden" id="materialImportPreview">
                <div class="materials-import__metrics" id="materialImportMetrics"></div>
                <div class="materials-import__errors is-hidden" id="materialImportErrors"></div>
                <div class="materials-table-scroll"><table class="materials-table"><thead><tr><th>Fila</th><th>Temporada</th><th>Cliente</th><th>Código</th><th>Nombre</th><th>Tipo</th><th>Unidad</th><th>Acción</th></tr></thead><tbody id="materialImportRows"></tbody></table></div>
                <div class="materials-import__confirm"><p id="materialImportConfirmationHelp"></p><button class="primary-button" id="confirmMaterialImport" type="button">Confirmar importación</button></div>
            </section>
            <section class="materials-import__history"><div class="materials-panel__heading"><div><p class="eyebrow">AUDITORÍA</p><h3>Importaciones recientes</h3></div></div><div id="materialImportHistory"></div></section>
        </dialog>
        <dialog class="materials-import" id="materialDirectDispatchDialog">
            <div class="materials-import__header">
                <div>
                    <p class="eyebrow">DESPACHO DIRECTO · OFICINA</p>
                    <h2>Entregar material a centro de costo</h2>
                    <p id="materialDirectDispatchContext">Selecciona un folio ubicado.</p>
                </div>
                <button id="closeMaterialDirectDispatch" type="button" aria-label="Cerrar">×</button>
            </div>
            <form class="materials-import__form" id="materialDirectDispatchForm">
                <input name="folio_id" type="hidden">
                <label><span>Destino y centro de costo *</span><select name="destino_material_id" required></select></label>
                <label><span>Cantidad a entregar *</span><input name="cantidad" type="number" min="0.001" step="0.001" required></label>
                <label><span>Observación</span><textarea name="observacion" maxlength="1000" rows="2" placeholder="Referencia, turno o motivo de la entrega"></textarea></label>
                <p class="materials-import__help">Solo se admiten folios con ubicación válida en una cámara de Materiales. La entrega traslada la custodia al centro de costo y no disminuye la existencia total de la empresa.</p>
                <p class="form-error" id="materialDirectDispatchError" role="alert"></p>
                <div class="materials-import__actions">
                    <button class="secondary-button" id="cancelMaterialDirectDispatch" type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Confirmar despacho directo</button>
                </div>
            </form>
        </dialog>
        <dialog class="materials-import" id="materialDirectConsumptionDialog">
            <div class="materials-import__header">
                <div>
                    <p class="eyebrow">CONSUMO DIRECTO · BODEGA CENTRAL</p>
                    <h2>Consumir insumo</h2>
                    <p id="materialDirectConsumptionContext">Selecciona un insumo disponible.</p>
                </div>
                <button id="closeMaterialDirectConsumption" type="button" aria-label="Cerrar">×</button>
            </div>
            <form class="materials-import__form" id="materialDirectConsumptionForm">
                <input name="tipo" type="hidden" value="consumo">
                <input name="folio_id" type="hidden">
                <input name="almacen_origen_id" type="hidden">
                <label><span>Centro de costo que consume *</span><select name="centro_costo_id" required></select></label>
                <label><span>Cantidad a consumir *</span><input name="cantidad" type="number" min="0.001" step="0.001" required></label>
                <label><span>Documento o referencia</span><input name="documento_relacionado" maxlength="150" placeholder="Orden, turno o vale de consumo"></label>
                <label><span>Motivo *</span><textarea name="motivo" minlength="3" maxlength="1000" rows="2" placeholder="Consumo turno día, mantención preventiva…" required></textarea></label>
                <label><span>Justificación de excepción FIFO</span><textarea name="motivo_excepcion_fifo" minlength="5" maxlength="1000" rows="2" placeholder="Completar solo si no se consume el lote más antiguo"></textarea></label>
                <p class="materials-import__help">Este movimiento descuenta la existencia de Bodega Central e imputa el consumo al centro de costo. No crea existencia temporal en el centro de costo y no puede deshacerse editando el Kardex.</p>
                <p class="form-error" id="materialDirectConsumptionError" role="alert"></p>
                <div class="materials-import__actions">
                    <button class="secondary-button" id="cancelMaterialDirectConsumption" type="button">Cancelar</button>
                    <button class="primary-button" type="submit">Confirmar consumo</button>
                </div>
            </form>
        </dialog>
        <dialog class="materials-import" id="materialCorrectionDialog">
            <div class="materials-import__header">
                <div><p class="eyebrow">CORRECCIÓN SUPERVISADA</p><h2>Corregir código del ítem</h2><p id="materialCorrectionContext"></p></div>
                <button id="closeMaterialCorrection" type="button" aria-label="Cerrar">×</button>
            </div>
            <form class="materials-import__form" id="materialCorrectionForm">
                <input name="folio_id" type="hidden">
                <label><span>Ítem correcto *</span><select name="item_material_id" required></select></label>
                <label><span>Motivo de la corrección *</span><textarea name="motivo" minlength="5" maxlength="1000" rows="3" required></textarea></label>
                <p class="materials-import__help">Solo se muestran ítems activos del mismo cliente y con la misma unidad. La corrección quedará registrada en el kardex.</p>
                <p class="form-error" id="materialCorrectionError" role="alert"></p>
                <div class="materials-import__actions"><button class="secondary-button" id="cancelMaterialCorrection" type="button">Cancelar</button><button class="primary-button" type="submit">Confirmar corrección</button></div>
            </form>
        </dialog>
        <dialog class="materials-import" id="materialExpiryCorrectionDialog">
            <div class="materials-import__header"><div><p class="eyebrow">CORRECCIÓN SUPERVISADA</p><h2>Corregir vencimiento</h2><p id="materialExpiryCorrectionContext"></p></div><button id="closeMaterialExpiryCorrection" type="button" aria-label="Cerrar">×</button></div>
            <form class="materials-import__form" id="materialExpiryCorrectionForm">
                <input name="folio_id" type="hidden">
                <label><span>Nueva fecha de vencimiento *</span><input name="fecha_vencimiento" type="date" required></label>
                <label><span>Motivo (reanálisis o extensión) *</span><textarea name="motivo" minlength="5" maxlength="2000" required></textarea></label>
                <p>Una fecha vigente libera el bloqueo por vencimiento. Los otros bloqueos se conservan.</p>
                <p class="form-error" id="materialExpiryCorrectionError" role="alert"></p>
                <div class="materials-import__actions"><button class="secondary-button" id="cancelMaterialExpiryCorrection" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar corrección</button></div>
            </form>
        </dialog>
        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
