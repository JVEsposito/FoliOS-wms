@props([
    'domain',
    'office',
    'context' => 'OPERACIÓN DE OFICINA',
    'icon' => '◆',
])

@php
    $offices = [
        'materia-prima' => [
            ['key' => 'resumen', 'module' => '', 'label' => 'Resumen', 'href' => '/oficina/materia-prima', 'permissions' => ['puede_consultar_romana', 'puede_consultar_materia_prima', 'puede_consultar_hidrocooler_materia_prima', 'puede_consultar_fruta_proceso', 'puede_consultar_despacho_comercial', 'puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
            ['key' => 'porteria', 'module' => '', 'label' => 'Ingresos Portería', 'href' => '/oficina/porteria', 'permissions' => ['puede_consultar_porteria']],
            ['key' => 'romana', 'module' => 'materia-prima.romana', 'label' => 'Romana', 'href' => '/oficina/romana', 'permissions' => ['puede_consultar_romana']],
            ['key' => 'defectos-recepcion', 'module' => '', 'label' => 'Defectos de recepción', 'href' => '/oficina/materia-prima/defectos-recepcion', 'permissions' => ['puede_auditar_defectos_recepcion_mp']],
            ['key' => 'digitacion', 'module' => 'materia-prima.digitacion', 'label' => 'Digitación de Lotes', 'href' => '/oficina/materia-prima/lotes', 'permissions' => ['puede_consultar_materia_prima']],
            ['key' => 'hidrocooler', 'module' => 'materia-prima.hidrocooler', 'label' => 'Hidrocooler', 'href' => '/oficina/materia-prima/hidrocooler', 'permissions' => ['puede_consultar_hidrocooler_materia_prima']],
            ['key' => 'fruta-proceso', 'module' => 'materia-prima.fruta-proceso', 'label' => 'Fruta a Proceso', 'href' => '/oficina/materia-prima/fruta-a-proceso', 'permissions' => ['puede_consultar_fruta_proceso']],
            ['key' => 'despacho-comercial', 'module' => 'materia-prima.despacho-comercial', 'label' => 'Despacho Comercial', 'href' => '/oficina/materia-prima/despacho-comercial', 'permissions' => ['puede_consultar_despacho_comercial']],
            ['key' => 'existencias-mp', 'module' => 'materia-prima.digitacion', 'label' => 'Existencias MP', 'href' => '/oficina/materia-prima/existencias', 'permissions' => ['puede_consultar_materia_prima']],
            ['key' => 'envases', 'module' => 'materia-prima.cuenta-envases', 'label' => 'Cuenta Envases', 'href' => '/oficina/envases/cuenta-corriente', 'permissions' => ['puede_consultar_cuenta_envases']],
            ['key' => 'despacho-envases', 'module' => 'materia-prima.despacho-envases', 'label' => 'Despacho Envases', 'href' => '/oficina/envases/despachos', 'permissions' => ['puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
        ],
        'frigorifico' => [
            ['key' => 'resumen', 'module' => '', 'label' => 'Resumen', 'href' => '/oficina/frigorifico', 'permissions' => ['puede_consultar_validaciones_pallet', 'puede_consultar_inspeccion_sag', 'puede_consultar_prefrio', 'ambito_camaras_productos', 'puede_consultar_catalogo_cargas', 'puede_consultar_cargas']],
            ['key' => 'validacion', 'module' => 'frigorifico.validacion', 'label' => 'Validación', 'href' => '/oficina/validacion', 'permissions' => ['puede_consultar_validaciones_pallet']],
            ['key' => 'pendientes-validacion', 'module' => 'frigorifico.validacion', 'label' => 'Pendientes por validar', 'href' => '/oficina/validacion/pendientes', 'permissions' => ['puede_consultar_validaciones_pallet']],
            ['key' => 'etiquetas-pt', 'module' => 'frigorifico.validacion', 'label' => 'Etiquetas PT', 'href' => '/oficina/validacion/etiquetas', 'permissions' => ['puede_imprimir_etiquetas_pt']],
            ['key' => 'recepcion-fruta-embalada', 'module' => 'frigorifico.recepcion-fruta-embalada', 'label' => 'Recepción de fruta embalada', 'href' => '/oficina/recepcion-fruta-embalada', 'permissions' => ['puede_consultar_recepciones_fruta_embalada']],
            ['key' => 'repaletizajes', 'module' => '', 'label' => 'Repaletizajes', 'href' => '/oficina/validacion/repaletizajes', 'permissions' => ['puede_consultar_repaletizajes']],
            ['key' => 'anulaciones-validacion', 'module' => 'frigorifico.validacion', 'label' => 'Anulaciones', 'href' => '/oficina/validacion/anulaciones', 'permissions' => ['puede_consultar_validaciones_pallet']],
            ['key' => 'contraparte-sag', 'module' => 'frigorifico.contraparte-sag', 'label' => 'Contraparte SAG', 'href' => '/oficina/frigorifico/contraparte-sag', 'permissions' => ['puede_consultar_contraparte_sag']],
            ['key' => 'inspeccion-sag', 'module' => 'frigorifico.inspeccion-sag', 'label' => 'Inspección SAG', 'href' => '/oficina/frigorifico/inspeccion-sag', 'permissions' => ['puede_consultar_inspeccion_sag']],
            ['key' => 'prefrio', 'module' => 'frigorifico.prefrio', 'label' => 'Prefrío', 'href' => '/oficina/prefrio', 'permissions' => ['puede_consultar_prefrio']],
            ['key' => 'camaras', 'module' => 'frigorifico.camaras', 'label' => 'Cámaras', 'href' => '/oficina/frigorifico/camaras', 'permissions' => ['ambito_camaras_productos']],
            ['key' => 'discrepancias', 'module' => 'frigorifico.camaras', 'label' => 'Discrepancias', 'href' => '/oficina/frigorifico/discrepancias', 'permissions' => ['puede_supervisar']],
            ['key' => 'cargas', 'module' => 'frigorifico.cargas', 'section' => 'Despacho', 'label' => 'Cargas', 'href' => '/oficina/frigorifico/despacho/cargas', 'permissions' => ['puede_consultar_catalogo_cargas']],
            ['key' => 'despacho-estatus', 'module' => 'frigorifico.cargas', 'section' => 'Despacho', 'label' => 'Estatus operativo', 'href' => '/oficina/frigorifico/despacho/estatus', 'permissions' => ['puede_consultar_cargas']],
            ['key' => 'embarques', 'module' => 'frigorifico.cargas', 'section' => 'Despacho', 'label' => 'Calendario de embarques', 'href' => '/oficina/frigorifico/despacho/calendario', 'permissions' => ['puede_consultar_catalogo_cargas']],
            ['key' => 'existencias-pt', 'module' => 'frigorifico.cargas', 'label' => 'Existencias PT', 'href' => '/oficina/frigorifico/existencias', 'permissions' => ['puede_consultar_cargas']],
        ],
        'materiales' => [
            ['key' => 'capacidad', 'module' => 'materiales.inventario', 'label' => 'Capacidad de bodegas', 'href' => '/oficina/materiales/capacidad', 'permissions' => ['puede_consultar_kardex_materiales']],
            ['key' => 'supervision', 'module' => 'materiales.inventario', 'label' => 'Supervisión', 'href' => '/oficina/materiales/supervision', 'permissions' => ['puede_consultar_kardex_materiales']],
            ['key' => 'resumen', 'module' => 'materiales.resumen', 'label' => 'Resumen', 'href' => '/oficina/materiales', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'catalogos', 'module' => 'materiales.catalogos', 'label' => 'Catálogos', 'href' => '/oficina/materiales/catalogos', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'niveles-stock', 'module' => 'materiales.catalogos', 'label' => 'Ítems · niveles de stock', 'href' => '/oficina/materiales/items/niveles', 'permissions' => ['puede_editar_niveles_stock_materiales']],
            ['key' => 'items', 'module' => '', 'label' => 'Ítems', 'href' => '/oficina/materiales/items', 'permissions' => ['puede_consultar_despachos_materiales', 'puede_administrar_catalogos_materiales', 'puede_consultar_recepciones_materiales']],
            ['key' => 'recepciones', 'module' => 'materiales.etiquetas', 'label' => 'Recepciones', 'href' => '/oficina/materiales/recepciones', 'permissions' => ['puede_consultar_recepciones_materiales']],
            ['key' => 'recepcion', 'module' => 'materiales.etiquetas', 'label' => 'Etiquetas', 'href' => '/oficina/materiales/recepcion', 'permissions' => ['puede_consultar_recepciones_materiales', 'puede_imprimir_etiquetas_materiales']],
            ['key' => 'inventario', 'module' => 'materiales.inventario', 'label' => 'Inventario BC', 'href' => '/oficina/materiales/inventario', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'vencimientos', 'module' => 'materiales.inventario', 'label' => 'Vencimientos', 'href' => '/oficina/materiales/vencimientos', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'reposicion', 'module' => 'materiales.inventario', 'label' => 'Reposición', 'href' => '/oficina/materiales/reposicion', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'tomas', 'module' => 'materiales.inventario', 'label' => 'Tomas de inventario', 'href' => '/oficina/materiales/tomas', 'permissions' => ['puede_gestionar_bloqueos_materiales']],
            ['key' => 'bodegas', 'module' => 'materiales.inventario', 'label' => 'Bodegas físicas', 'href' => '/oficina/materiales/bodegas', 'permissions' => ['puede_consultar_kardex_materiales']],
            ['key' => 'custodia', 'module' => 'materiales.inventario', 'label' => 'Inventario CC', 'href' => '/oficina/materiales/almacenes', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'despachos', 'module' => 'materiales.despachos', 'label' => 'Despachos', 'href' => '/oficina/materiales/despachos', 'permissions' => ['puede_consultar_despachos_materiales']],
            ['key' => 'recetas', 'module' => 'materiales.recetas', 'label' => 'Recetas', 'href' => '/oficina/materiales/recetas', 'permissions' => ['puede_consultar_transformaciones_materiales']],
            ['key' => 'ordenes', 'module' => 'materiales.ordenes', 'label' => 'Órdenes', 'href' => '/oficina/materiales/ordenes', 'permissions' => ['puede_consultar_transformaciones_materiales']],
            ['key' => 'exportaciones', 'module' => 'materiales.exportaciones', 'label' => 'Existencias', 'href' => '/oficina/materiales/exportaciones', 'permissions' => ['puede_consultar_despachos_materiales']],
        ],
        'gerencia' => [
            ['key' => 'panel', 'module' => 'gerencia.panel', 'label' => 'Panel gerencial', 'href' => '/oficina/gerencia', 'permissions' => ['puede_consultar_panel_gerencial']],
            ['key' => 'operacion-ahora', 'module' => 'gerencia.panel', 'label' => 'Operación ahora', 'href' => '/oficina/operacion-ahora', 'permissions' => ['puede_consultar_panel_gerencial']],
        ],
        'administracion' => [
            ['key' => 'resumen', 'module' => '', 'label' => 'Resumen', 'href' => '/oficina/administracion', 'permissions' => ['puede_consultar_panel_gerencial', 'puede_consultar_accesos', 'puede_administrar_catalogos_validacion', 'puede_consultar_configuracion_camaras', 'puede_consultar_integridad_operacional', 'puede_consultar_finanzas', 'puede_consultar_proveedores_finanzas', 'puede_consultar_comex', 'puede_consultar_comercial_comex', 'puede_consultar_rrhh', 'puede_marcar_rrhh']],
            ['key' => 'accesos', 'module' => 'administracion.accesos', 'label' => 'Accesos & Temporadas', 'href' => '/oficina/accesos', 'permissions' => ['puede_consultar_accesos']],
            ['key' => 'fruta-embalada', 'module' => 'administracion.accesos', 'label' => 'Plantas y umbrales de prefrío', 'href' => '/oficina/administracion/fruta-embalada', 'permissions' => ['puede_administrar_accesos']],
            ['key' => 'maestros-temporada', 'module' => 'administracion.maestros-temporada', 'label' => 'Maestros de temporada', 'href' => '/oficina/administracion/maestros-temporada', 'permissions' => ['puede_administrar_catalogos_validacion']],
            ['key' => 'configuracion-camaras', 'module' => 'administracion.camaras', 'label' => 'Configuración de cámaras', 'href' => '/oficina/administracion/camaras', 'permissions' => ['puede_consultar_configuracion_camaras']],
            ['key' => 'integridad-operacional', 'module' => 'administracion.integridad-operacional', 'label' => 'Salud operacional', 'href' => '/oficina/administracion/integridad-operacional', 'permissions' => ['puede_consultar_integridad_operacional']],
            ['key' => 'demo', 'module' => '', 'label' => 'Demo comercial', 'href' => '/oficina/demo', 'permissions' => ['puede_habilitar_demo']],
            ['key' => 'finanzas-hechos', 'module' => 'finanzas.prefactura', 'section' => 'Finanzas', 'label' => 'Hechos valorizables', 'href' => '/oficina/finanzas/hechos', 'permissions' => ['puede_consultar_finanzas']],
            ['key' => 'finanzas-tarifario', 'module' => 'finanzas.prefactura', 'section' => 'Finanzas', 'label' => 'Tarifario', 'href' => '/oficina/finanzas/tarifario', 'permissions' => ['puede_consultar_finanzas']],
            ['key' => 'finanzas-proveedores', 'module' => 'finanzas.proveedores', 'section' => 'Finanzas', 'label' => 'Proveedores', 'href' => '/oficina/finanzas/proveedores', 'permissions' => ['puede_consultar_proveedores_finanzas']],
            ['key' => 'finanzas-costos-comex', 'module' => 'finanzas.proveedores', 'section' => 'Finanzas', 'label' => 'Costos COMEX', 'href' => '/oficina/finanzas/costos-comex', 'permissions' => ['puede_consultar_costos_comex']],
            ['key' => 'finanzas-prefactura', 'module' => 'finanzas.prefactura', 'section' => 'Finanzas', 'label' => 'Prefactura', 'href' => '/oficina/finanzas/prefactura', 'permissions' => ['puede_consultar_finanzas']],
            ['key' => 'rrhh', 'module' => 'rrhh.personal', 'section' => 'Recursos humanos', 'label' => 'Personal y dotación', 'href' => '/oficina/rrhh', 'permissions' => ['puede_consultar_rrhh']],
            ['key' => 'rrhh-marcaje', 'module' => 'rrhh.marcaje', 'section' => 'Recursos humanos', 'label' => 'Puesto de marcaje', 'href' => '/oficina/rrhh/marcaje', 'permissions' => ['puede_marcar_rrhh']],
            ['key' => 'comex-expedientes', 'module' => 'comex.expedientes', 'section' => 'Comercio exterior', 'label' => 'Expedientes de embarque', 'href' => '/oficina/comex/expedientes', 'permissions' => ['puede_consultar_comex']],
            ['key' => 'comex-configuracion', 'module' => 'comex.configuracion', 'section' => 'Comercio exterior', 'label' => 'Configuración Comex', 'href' => '/oficina/comex/configuracion', 'permissions' => ['puede_consultar_comex']],
            ['key' => 'comex-comercial', 'module' => 'comex.comercial', 'section' => 'Comercio exterior', 'label' => 'Actores y lugares', 'href' => '/oficina/comex/comercial', 'permissions' => ['puede_consultar_comercial_comex']],
            ['key' => 'comex-notas', 'module' => 'comex.comercial', 'section' => 'Comercio exterior', 'label' => 'Notas de venta', 'href' => '/oficina/comex/notas', 'permissions' => ['puede_consultar_comercial_comex']],
            ['key' => 'comex-bookings', 'module' => 'comex.comercial', 'section' => 'Comercio exterior', 'label' => 'Bookings y contenedores', 'href' => '/oficina/comex/bookings', 'permissions' => ['puede_consultar_comercial_comex']],
        ],
        'consultas' => [
            ['key' => 'resumen', 'module' => '', 'label' => 'Resumen', 'href' => '/oficina/consultas', 'permissions' => ['puede_consultar_oficina_consultas', 'puede_consultar_sag']],
            ['key' => 'busqueda', 'module' => 'consultas.busqueda', 'label' => 'Búsqueda Operacional', 'href' => '/oficina/consultas/busqueda', 'permissions' => ['puede_consultar_oficina_consultas']],
            ['key' => 'trazabilidad', 'module' => 'consultas.busqueda', 'label' => 'Trazabilidad de lotes', 'href' => '/oficina/consultas/trazabilidad', 'permissions' => ['puede_consultar_oficina_consultas']],
            ['key' => 'sag', 'module' => 'consultas.sag', 'label' => 'Productores SAG / CSG', 'href' => '/oficina/consultas/sag', 'permissions' => ['puede_consultar_sag']],
            ['key' => 'productores', 'module' => 'consultas.productores', 'label' => 'Productores Verificados', 'href' => '/oficina/consultas/productores', 'permissions' => ['puede_consultar_oficina_consultas']],
        ],
    ];

    // Las seis áreas del gajo, en el orden de sus hojas (horario desde las 12). El centro
    // del gajo es Operación ahora. Ver docs/diseno/folios-v2/README.md § Áreas.
    $domains = [
        'materia-prima' => ['label' => 'Materia Prima'],
        'frigorifico' => ['label' => 'Frigorífico'],
        'materiales' => ['label' => 'Materiales'],
        'consultas' => ['label' => 'Consultas'],
        'gerencia' => ['label' => 'Gerencia'],
        'administracion' => ['label' => 'Administración'],
    ];

    foreach ($domains as $domainKey => &$definition) {
        $definition['targets'] = array_map(
            fn (array $officeDefinition): array => [
                'href' => $officeDefinition['href'],
                'module' => $officeDefinition['module'],
                'permissions' => $officeDefinition['permissions'],
            ],
            $offices[$domainKey] ?? [],
        );
        $definition['href'] = $definition['targets'][0]['href'] ?? '/';
    }
    unset($definition);

    // Compatibilidad: una vista que aún declare domain="administracion" para el panel
    // gerencial u Operación ahora se muestra en Gerencia.
    if ($domain === 'administracion' && in_array($office, ['panel', 'operacion-ahora'], true)) {
        $domain = 'gerencia';
    }
    $activeDomain = $domains[$domain] ?? $domains['materia-prima'];
    $activeOffices = $offices[$domain] ?? [];
    $area = $office === 'operacion-ahora' ? 'operacion' : (array_key_exists($domain, $domains) ? $domain : 'materia-prima');
    $officeLabel = collect($activeOffices)->firstWhere('key', $office)['label'] ?? 'Oficina';
    $serverLabel = config('app.server_label');
@endphp

<div class="estiba-ui estiba-office-shell" data-office-shell data-folios-area="{{ $area }}">
    <a class="estiba-office-skip" href="#officeContent" data-office-skip>Saltar al contenido</a>
    <header class="estiba-office-header" data-office-shell-header data-active-domain="{{ $domain }}" data-active-office="{{ $office }}">
        <div class="estiba-office-brand">
            <a class="estiba-office-home" href="/oficina" data-office-home title="Ir a mi inicio"><x-folios-logo surface="dark" class="folios-logo--office" /></a>
            <button type="button" class="estiba-office-menu" data-office-menu aria-controls="officeSidebar" aria-expanded="true" aria-label="Ocultar menú lateral" title="Ocultar menú lateral">
                <span class="estiba-office-menu-icon" aria-hidden="true"></span>
                <span class="estiba-office-menu-text" data-office-menu-label>Menú</span>
            </button>
        </div>
        <div class="estiba-office-system estiba-office-sr">
            <span>SISTEMA DE GESTIÓN</span>
            <small>{{ $context }} · {{ $officeLabel }}</small>
        </div>
        <div class="estiba-office-context">
            <span>Temporada<strong data-office-season>Sin consultar</strong></span>
            <span>Planta<strong data-office-plant>Sin consultar</strong></span>
        </div>
        <button class="estiba-office-status" type="button" data-office-context-refresh aria-label="Actualizar temporada y planta">
            <i aria-hidden="true"></i>
            <span><small class="estiba-office-status__caption">Datos</small><strong data-office-context-label>Verificando contexto</strong><small data-office-context-status>Temporada y planta</small></span>
            <x-estiba.icon name="refresh" />
        </button>
        <div class="estiba-office-tools">
            @if (filled($serverLabel))
                <span class="estiba-office-server" data-office-server>{{ $serverLabel }}</span>
            @endif
            <button type="button" class="estiba-office-theme-toggle" data-office-theme-toggle aria-pressed="false" aria-label="Activar modo oscuro" title="Activar modo oscuro">
                <span class="estiba-office-theme-icon" aria-hidden="true"></span>
                <span data-office-theme-label>Modo oscuro</span>
            </button>
        </div>
        @if ($domain === 'materiales')
            <a href="/oficina/materiales/reposicion" class="estiba-office-replenishment" data-material-replenishment-indicator hidden>Reposición</a>
        @endif
    </header>
    {{-- Avisos de cierre de temporada dirigidos al usuario (office-shell.js). --}}
    <div class="estiba-office-notices" data-office-notices role="status" aria-live="polite" hidden></div>

    <aside class="estiba-office-sidebar" id="officeSidebar" aria-label="Navegación de Oficina">
        {{-- Búsqueda global: folio, lote, recepción, CSG o guía desde cualquier oficina. --}}
        <form class="estiba-office-search" action="/oficina/consultas/busqueda" method="get" role="search"
            data-navigation-permissions="puede_consultar_oficina_consultas"
            data-navigation-module="consultas.busqueda">
            <label class="estiba-office-nav-label" for="officeGlobalSearch">Buscar folio, lote o guía</label>
            <div>
                <input id="officeGlobalSearch" name="q" type="search" minlength="2" maxlength="100" placeholder="Folio, lote, REC, CSG…" autocomplete="off" required>
                <button type="submit" aria-label="Buscar"><x-estiba.icon name="search" /></button>
            </div>
        </form>
        @php($operationNowDefinition = collect($offices['gerencia'])->firstWhere('key', 'operacion-ahora'))
        <nav class="estiba-office-primary" aria-label="Operación en tiempo real">
            <a @class(['estiba-office-area', 'is-active' => $office === 'operacion-ahora'])
                data-office-key="operacion-ahora"
                data-office-domain="gerencia"
                data-navigation-permissions="{{ implode(',', $operationNowDefinition['permissions']) }}"
                data-navigation-module="{{ $operationNowDefinition['module'] }}"
                href="{{ $operationNowDefinition['href'] }}"
                @if ($office === 'operacion-ahora') aria-current="page" @endif
            ><x-folios.gajo area="operacion" /><strong>Operación ahora</strong></a>
        </nav>
        <nav class="estiba-office-areas" aria-label="Áreas del sistema">
            @foreach ($domains as $domainKey => $definition)
                @php($isActiveDomain = $domain === $domainKey && $office !== 'operacion-ahora')
                <a @class(['estiba-office-domain', 'estiba-office-area', 'is-active' => $isActiveDomain])
                    data-domain-key="{{ $domainKey }}"
                    data-navigation-targets="{{ json_encode($definition['targets'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                    href="{{ $definition['href'] }}"
                    @if ($isActiveDomain) aria-current="true" @endif
                ><x-folios.gajo :area="$domainKey" /><strong>{{ $definition['label'] }}</strong></a>
                @if ($isActiveDomain)
                    <nav class="estiba-office-offices" aria-label="Oficinas de {{ $activeDomain['label'] }}">
                        @php($currentSection = null)
                        @foreach ($activeOffices as $officeDefinition)
                            @continue($officeDefinition['key'] === 'operacion-ahora')
                            @if (($officeDefinition['section'] ?? null) !== $currentSection)
                                @php($currentSection = $officeDefinition['section'] ?? null)
                                @if ($currentSection)
                                    {{-- El título del grupo se oculta con sus oficinas cuando el perfil no habilita ninguna. --}}
                                    @php($sectionOffices = collect($activeOffices)->where('section', $currentSection))
                                    @php($sectionModules = $sectionOffices->pluck('module')->unique())
                                    <p data-navigation-permissions="{{ $sectionOffices->flatMap(fn (array $item): array => $item['permissions'])->unique()->implode(',') }}"
                                        data-navigation-module="{{ $sectionModules->count() === 1 ? $sectionModules->first() : '' }}"
                                        class="estiba-office-subgroup">{{ $currentSection }}</p>
                                @endif
                            @endif
                            <a @class([
                                    'is-active' => $office === $officeDefinition['key'],
                                    'is-subgroup-item' => isset($officeDefinition['section']),
                                ])
                                data-office-key="{{ $officeDefinition['key'] }}"
                                data-office-domain="{{ $domain }}"
                                data-navigation-permissions="{{ implode(',', $officeDefinition['permissions']) }}"
                                data-navigation-module="{{ $officeDefinition['module'] }}"
                                href="{{ $officeDefinition['href'] }}"
                                @if ($office === $officeDefinition['key']) aria-current="page" @endif
                            >{{ $officeDefinition['label'] }}</a>
                        @endforeach
                    </nav>
                @endif
            @endforeach
        </nav>
        <div class="estiba-office-identity">
            <span class="estiba-office-avatar" id="officeInitials" aria-hidden="true">OF</span>
            <span class="estiba-office-user"><strong id="officeUserName">Usuario</strong><small id="officeUserRole">Oficina</small><small data-office-readonly hidden>Solo consulta</small></span>
            <button id="officeLogoutButton" type="button">Cerrar sesión</button>
        </div>
        {{-- Nombre e iniciales desde la sesión del equipo, antes de pintar; cada oficina los confirma al cargar. --}}
        <script>
            (function () {
                try {
                    var identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
                    var name = identity && typeof identity.nombre === 'string' ? identity.nombre.trim() : '';
                    if (!name) return;
                    document.getElementById('officeUserName').textContent = name;
                    document.getElementById('officeInitials').textContent = name.split(/\s+/).slice(0, 2)
                        .map(function (part) { return part.charAt(0); }).join('').toUpperCase();
                } catch (error) {
                    // Sin almacenamiento, la oficina los completa al cargar.
                }
            }());
        </script>
        <div class="estiba-office-signature">
            <strong>FRÍO QUE<br>MANTIENE VALOR</strong><small>Versión {{ config('app.product_version') }}</small>
        </div>
    </aside>

    <div class="office-navigation-legacy" hidden aria-hidden="true">
        <a id="officeManagementNav" href="/oficina/gerencia" tabindex="-1"></a>
        <a id="officeRomanaNav" href="/oficina/romana" tabindex="-1"></a>
        <a id="officeRawMaterialNav" href="/oficina/materia-prima" tabindex="-1"></a>
        <a id="officeContainerAccountsNav" href="/oficina/envases/cuenta-corriente" tabindex="-1"></a>
        <a id="officeValidationNav" href="/oficina/validacion" tabindex="-1"></a>
        <a id="officeCamerasNav" href="/oficina/frigorifico/camaras" tabindex="-1"></a>
        <a id="officeLoadsNav" href="/oficina/frigorifico/despacho/cargas" tabindex="-1"></a>
        <a id="officeMaterialsNav" href="/oficina/materiales" tabindex="-1"></a>
        <a id="officePrefrioNav" href="/oficina/prefrio" tabindex="-1"></a>
        <a id="officeAccessesNav" href="/oficina/accesos" tabindex="-1"></a>
    </div>
</div>

{{-- Estilos y script del menú: van en <head> (office/partials/arranque) para no pintar el menú sin estilo. --}}
