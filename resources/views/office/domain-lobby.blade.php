<!DOCTYPE html>
<html lang="es">
    @php
        $lobbies = [
            'materia-prima' => [
                'label' => 'Materia Prima',
                'context' => 'MATERIA PRIMA',
                'icon' => 'MP',
                'eyebrow' => 'RECEPCIÓN Y ABASTECIMIENTO',
                'title' => 'Resumen de Materia Prima',
                'description' => 'Accede al flujo desde la recepción del camión hasta la entrega de fruta a proceso.',
                'login_title' => 'Recepción, frío inicial y continuidad de proceso.',
                'login_description' => 'Consulta los procesos habilitados para tu perfil dentro del macromódulo de Materia Prima.',
                'metrics' => [
                    'modules' => 'MÓDULOS DISPONIBLES',
                    'season' => 'TEMPORADA ACTIVA',
                    'segments' => 'SEGMENTOS POR LOTIZAR',
                    'hydrocooler' => 'LOTES EN HIDROCOOLER',
                    'process' => 'DISPONIBLES PARA PROCESO',
                    'cameras' => 'LOTES ASIGNADOS A CÁMARA',
                ],
                'cards' => [
                    ['module' => 'materia-prima.romana', 'permissions' => ['puede_consultar_romana'], 'href' => '/oficina/romana', 'icon' => '⚖', 'eyebrow' => 'RECEPCIÓN', 'title' => 'Romana', 'description' => 'Ingreso, pesaje, destare y cierre documental de camiones.'],
                    ['module' => '', 'permissions' => ['puede_auditar_defectos_recepcion_mp'], 'href' => '/oficina/materia-prima/defectos-recepcion', 'icon' => '!', 'eyebrow' => 'CONTROL DE CALIDAD', 'title' => 'Defectos de recepción', 'description' => 'Audita daños registrados por Validación MP, consulta fotos y descarga respaldos.'],
                    ['module' => 'materia-prima.digitacion', 'permissions' => ['puede_consultar_materia_prima'], 'href' => '/oficina/materia-prima/lotes', 'icon' => '◫', 'eyebrow' => 'LOTIZACIÓN', 'title' => 'Digitación de Lotes', 'description' => 'Convierte segmentos validados en lotes trazables de materia prima.'],
                    ['module' => 'materia-prima.hidrocooler', 'permissions' => ['puede_consultar_hidrocooler_materia_prima'], 'href' => '/oficina/materia-prima/hidrocooler', 'icon' => '❄', 'eyebrow' => 'ENFRIAMIENTO', 'title' => 'Hidrocooler', 'description' => 'Controla lotes pendientes, ciclos y liberaciones del enfriamiento inicial.'],
                    ['module' => 'materia-prima.fruta-proceso', 'permissions' => ['puede_consultar_fruta_proceso'], 'href' => '/oficina/materia-prima/fruta-a-proceso', 'icon' => '→', 'eyebrow' => 'CONTINUIDAD', 'title' => 'Fruta a Proceso', 'description' => 'Registra entregas a Packing, retornos y ubicación de sublotes.'],
                    ['module' => 'materia-prima.despacho-comercial', 'permissions' => ['puede_consultar_despacho_comercial'], 'href' => '/oficina/materia-prima/despacho-comercial', 'icon' => '⇥', 'eyebrow' => 'SALIDA DE RETORNOS', 'title' => 'Despacho Comercial', 'description' => 'Reserva bins retornados, registra el número de guía SII y confirma su salida.'],
                    ['module' => 'materia-prima.digitacion', 'permissions' => ['puede_consultar_materia_prima'], 'href' => '/oficina/materia-prima/existencias', 'icon' => '⌁', 'eyebrow' => 'INVENTARIO', 'title' => 'Existencias MP', 'description' => 'Consulta lotes vigentes, condición térmica, cámara y continuidad.'],
                    ['module' => 'materia-prima.cuenta-envases', 'permissions' => ['puede_consultar_cuenta_envases'], 'href' => '/oficina/envases/cuenta-corriente', 'icon' => '▣', 'eyebrow' => 'CUSTODIA', 'title' => 'Cuenta Envases', 'description' => 'Revisa movimientos, saldos y observaciones de envases retornables.'],
                    ['module' => 'materia-prima.despacho-envases', 'permissions' => ['puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases'], 'href' => '/oficina/envases/despachos', 'icon' => '↗', 'eyebrow' => 'SALIDA', 'title' => 'Despacho Envases', 'description' => 'Prepara guías, confirma entregas y conserva sus respaldos trazables.'],
                ],
            ],
            'frigorifico' => [
                'label' => 'Frigorífico (PT)',
                'context' => 'FRIGORÍFICO · PT',
                'icon' => 'PT',
                'eyebrow' => 'CADENA DE FRÍO Y DESPACHO',
                'title' => 'Resumen de Frigorífico',
                'description' => 'Accede a validación, prefrío, cámaras, inspecciones y preparación de cargas.',
                'login_title' => 'Producto terminado bajo control operacional.',
                'login_description' => 'Consulta los procesos habilitados para tu perfil dentro del macromódulo Frigorífico.',
                'metrics' => [
                    'modules' => 'MÓDULOS DISPONIBLES',
                    'prefrio' => 'PROCESOS EN PREFRÍO',
                    'prefrio_folios' => 'FOLIOS ACTIVOS EN PREFRÍO',
                    'sag' => 'LOTES SAG ACTIVOS',
                    'sag_folios' => 'PALLETS EN INSPECCIÓN',
                    'cameras' => 'CÁMARAS PT ACTIVAS',
                ],
                'cards' => [
                    ['module' => 'frigorifico.validacion', 'permissions' => ['puede_consultar_validaciones_pallet'], 'href' => '/oficina/validacion', 'icon' => '✓', 'eyebrow' => 'INGRESO PT', 'title' => 'Validación', 'description' => 'Consulta decisiones, correcciones y trazabilidad de pallets ingresados.'],
                    ['module' => 'frigorifico.validacion', 'permissions' => ['puede_consultar_validaciones_pallet'], 'href' => '/oficina/validacion/repaletizajes', 'icon' => '⇄', 'eyebrow' => 'ACONDICIONAMIENTO', 'title' => 'Repaletizajes', 'description' => 'Crea composiciones, controla saldos y registra cambios de folio.'],
                    ['module' => 'frigorifico.validacion', 'permissions' => ['puede_consultar_validaciones_pallet'], 'href' => '/oficina/validacion/anulaciones', 'icon' => '⊘', 'eyebrow' => 'CORRECCIONES', 'title' => 'Anulaciones', 'description' => 'Revisa y administra anulaciones trazables de validación.'],
                    ['module' => 'frigorifico.inspeccion-sag', 'permissions' => ['puede_consultar_inspeccion_sag'], 'href' => '/oficina/frigorifico/inspeccion-sag', 'icon' => 'SAG', 'eyebrow' => 'INSPECCIÓN', 'title' => 'Inspección SAG', 'description' => 'Agrupa pallets, destinos y resultados de inspecciones oficiales.'],
                    ['module' => 'frigorifico.prefrio', 'permissions' => ['puede_consultar_prefrio'], 'href' => '/oficina/prefrio', 'icon' => '❄', 'eyebrow' => 'CADENA DE FRÍO', 'title' => 'Prefrío', 'description' => 'Opera túneles, controla tiempos, eventos y aprobación térmica.'],
                    ['module' => 'frigorifico.camaras', 'permissions' => ['ambito_camaras_productos'], 'href' => '/oficina/frigorifico/camaras', 'icon' => '▦', 'eyebrow' => 'ALMACENAMIENTO', 'title' => 'Cámaras', 'description' => 'Consulta capacidad, ocupación y disponibilidad de cámaras PT.'],
                    ['module' => 'frigorifico.cargas', 'permissions' => ['puede_consultar_catalogo_cargas'], 'href' => '/oficina/frigorifico/despacho/cargas', 'icon' => '▤', 'eyebrow' => 'DESPACHO · PREPARACIÓN', 'title' => 'Cargas', 'description' => 'Crea órdenes CAR, incorpora folios y publica el trabajo para cámaras.'],
                    ['module' => 'frigorifico.cargas', 'permissions' => ['puede_consultar_cargas'], 'href' => '/oficina/frigorifico/despacho/estatus', 'icon' => '⇥', 'eyebrow' => 'DESPACHO · EN VIVO', 'title' => 'Estatus operativo', 'description' => 'Supervisa concentración, separación, andenes e incidencias de cargas activas.'],
                    ['module' => 'frigorifico.cargas', 'permissions' => ['puede_consultar_catalogo_cargas'], 'href' => '/oficina/frigorifico/despacho/calendario', 'icon' => '◷', 'eyebrow' => 'DESPACHO · PROGRAMACIÓN', 'title' => 'Calendario de embarques', 'description' => 'Visualiza compromisos, ventanas y prioridades de salida.'],
                    ['module' => 'frigorifico.cargas', 'permissions' => ['puede_consultar_cargas'], 'href' => '/oficina/frigorifico/existencias', 'icon' => '◇', 'eyebrow' => 'INVENTARIO', 'title' => 'Existencias PT', 'description' => 'Consulta folios activos desde validación hasta despacho.'],
                ],
            ],
            'administracion' => [
                'label' => 'Gerencia & Administración',
                'context' => 'ADMINISTRACIÓN & GERENCIA',
                'icon' => 'GA',
                'eyebrow' => 'GOBERNANZA Y CONTROL',
                'title' => 'Resumen de Gerencia y Administración',
                'description' => 'Accede a indicadores, perfiles, maestros, infraestructura y salud del sistema.',
                'login_title' => 'Visibilidad gerencial y gobierno del WMS.',
                'login_description' => 'Consulta los procesos habilitados para tu perfil de gerencia o administración.',
                'metrics' => [
                    'modules' => 'MÓDULOS DISPONIBLES',
                    'alerts' => 'ALERTAS OPERACIONALES',
                    'folios' => 'FOLIOS PT ACTIVOS',
                    'loads' => 'CARGAS ACTIVAS',
                    'occupancy' => 'OCUPACIÓN GLOBAL CÁMARAS',
                    'prefrio_pending' => 'FOLIOS PENDIENTES PREFRÍO',
                ],
                'cards' => [
                    ['module' => 'gerencia.panel', 'permissions' => ['puede_consultar_panel_gerencial'], 'href' => '/oficina/operacion-ahora', 'icon' => 'OA', 'eyebrow' => 'TIEMPO REAL', 'title' => 'Operación ahora', 'description' => 'Supervisa cámaras, ambiente, camareros, prefrío e incidencias en una sola lectura.'],
                    ['module' => 'gerencia.panel', 'permissions' => ['puede_consultar_panel_gerencial'], 'href' => '/oficina/gerencia', 'icon' => '◆', 'eyebrow' => 'INDICADORES', 'title' => 'Panel Gerencial', 'description' => 'Consolida capacidad, flujo, alertas y desempeño operacional.'],
                    ['module' => 'administracion.accesos', 'permissions' => ['puede_consultar_accesos'], 'href' => '/oficina/accesos', 'icon' => '⚙', 'eyebrow' => 'GOBERNANZA', 'title' => 'Accesos & Temporadas', 'description' => 'Administra usuarios, perfiles, tablets y ciclos operacionales.'],
                    ['module' => 'administracion.maestros-temporada', 'permissions' => ['puede_administrar_catalogos_validacion'], 'href' => '/oficina/administracion/maestros-temporada', 'icon' => '≡', 'eyebrow' => 'CATÁLOGOS', 'title' => 'Maestros de temporada', 'description' => 'Mantiene clientes, especies, variedades, calibres y combinaciones.'],
                    ['module' => 'administracion.camaras', 'permissions' => ['puede_consultar_configuracion_camaras'], 'href' => '/oficina/administracion/camaras', 'icon' => '▦', 'eyebrow' => 'INFRAESTRUCTURA', 'title' => 'Configuración de cámaras', 'description' => 'Define dimensiones, contenido, posiciones y estado administrativo.'],
                    ['module' => 'administracion.integridad-operacional', 'permissions' => ['puede_consultar_integridad_operacional'], 'href' => '/oficina/administracion/integridad-operacional', 'icon' => '⌁', 'eyebrow' => 'CONTROL', 'title' => 'Salud operacional', 'description' => 'Detecta discrepancias de estados, trazabilidad y proyecciones.'],
                    ['module' => '', 'permissions' => ['puede_habilitar_demo'], 'href' => '/oficina/demo', 'icon' => '▶', 'eyebrow' => 'PRESENTACIÓN', 'title' => 'Demo comercial', 'description' => 'Habilita un escenario ficticio y aislado para gerencia, compradores e inversionistas.'],
                ],
            ],
            'consultas' => [
                'label' => 'Consultas',
                'context' => 'CONSULTAS Y TRAZABILIDAD',
                'icon' => 'CO',
                'eyebrow' => 'TRAZABILIDAD TRANSVERSAL',
                'title' => 'Resumen de Consultas',
                'description' => 'Centraliza búsquedas de folios, lotes, recepciones y antecedentes SAG/CSG.',
                'login_title' => 'Información operacional en un solo punto.',
                'login_description' => 'Consulta los expedientes y catálogos habilitados para tu perfil.',
                'metrics' => [
                    'modules' => 'MÓDULOS DISPONIBLES',
                    'producers' => 'PRODUCTORES REGISTRADOS',
                    'pending' => 'PENDIENTES DE CLIENTE',
                    'associated' => 'PRODUCTORES ASOCIADOS',
                    'lots' => 'LOTES TRAZABLES',
                    'sag_today' => 'CONSULTAS SAG HOY',
                ],
                'cards' => [
                    ['module' => 'consultas.busqueda', 'permissions' => ['puede_consultar_oficina_consultas'], 'href' => '/oficina/consultas/busqueda', 'icon' => '⌕', 'eyebrow' => 'TRAZABILIDAD', 'title' => 'Búsqueda Operacional', 'description' => 'Encuentra folios, lotes, recepciones y productores desde una sola consulta.'],
                    ['module' => 'consultas.sag', 'permissions' => ['puede_consultar_sag'], 'href' => '/oficina/consultas/sag', 'icon' => 'SAG', 'eyebrow' => 'VERIFICACIÓN', 'title' => 'Productores SAG / CSG', 'description' => 'Consulta antecedentes oficiales y conserva cada verificación realizada.'],
                    ['module' => 'consultas.productores', 'permissions' => ['puede_consultar_oficina_consultas'], 'href' => '/oficina/consultas/productores', 'icon' => 'CSG', 'eyebrow' => 'EXPEDIENTES', 'title' => 'Productores Verificados', 'description' => 'Revisa asociaciones por cliente, predio, catálogos y lotes relacionados.'],
                ],
            ],
        ];
        $lobby = $lobbies[$lobbyDomain] ?? $lobbies['materia-prima'];
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · {{ $lobby['label'] }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-domain-lobby.css', 'resources/js/office-domain-lobby.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand domain-lobby-access-brand">
                <div class="office-logo" aria-hidden="true">{{ $lobby['icon'] }}</div>
                <p class="eyebrow">FoliOS · {{ $lobby['context'] }}</p>
                <h1 id="officeAccessTitle">{{ $lobby['login_title'] }}</h1>
                <p>{{ $lobby['login_description'] }}</p>
                <div class="feature-row"><span>Accesos por perfil</span><span>Resumen vigente</span><span>Navegación directa</span></div>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar al macromódulo</h2><p>Utiliza tus credenciales habituales del WMS.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Ingresar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp" data-lobby-domain="{{ $lobbyDomain }}" @if ($officeHomeRedirect ?? false) data-office-home-redirect @endif>
            <x-office.navigation :domain="$lobbyDomain" office="resumen" :context="$lobby['context']" :icon="$lobby['icon']" />

            <section class="domain-lobby-workspace">
                <header class="domain-lobby-heading panel">
                    <div><p class="eyebrow">{{ $lobby['eyebrow'] }}</p><h1>{{ $lobby['title'] }}</h1><p>{{ $lobby['description'] }}</p></div>
                    <button class="secondary-button" id="reloadLobbyButton" type="button">↻ Actualizar</button>
                </header>

                <div class="domain-lobby-metrics" aria-label="Indicadores del macromódulo">
                    @foreach ($lobby['metrics'] as $key => $label)
                        <article><span>{{ $label }}</span><strong data-lobby-metric="{{ $key }}">—</strong></article>
                    @endforeach
                </div>

                <section class="domain-lobby-modules" aria-labelledby="domainLobbyModulesTitle">
                    <header>
                        <div><p class="eyebrow">ACCESOS DIRECTOS</p><h2 id="domainLobbyModulesTitle">Procesos del macromódulo</h2></div>
                        <p id="lobbySummaryStatus">Mostrando solo los módulos habilitados para tu perfil.</p>
                    </header>
                    <div class="domain-lobby-grid" id="domainLobbyGrid">
                        @foreach ($lobby['cards'] as $card)
                            <a
                                class="domain-lobby-card"
                                data-navigation-module="{{ $card['module'] }}"
                                data-navigation-permissions="{{ implode(',', $card['permissions']) }}"
                                href="{{ $card['href'] }}"
                            >
                                <span aria-hidden="true">{{ $card['icon'] }}</span>
                                <div><p class="eyebrow">{{ $card['eyebrow'] }}</p><h3>{{ $card['title'] }}</h3><p>{{ $card['description'] }}</p></div>
                                <strong aria-hidden="true">→</strong>
                            </a>
                        @endforeach
                    </div>
                    <div class="domain-lobby-empty is-hidden" id="domainLobbyEmpty">
                        <strong>No tienes procesos habilitados en este macromódulo.</strong>
                        <span>Solicita la revisión de tu perfil de acceso si necesitas ingresar a esta área.</span>
                    </div>
                </section>
            </section>
        </main>

        <div class="loading-overlay is-hidden" id="officeLoading" aria-hidden="true"><div class="spinner"></div><p id="officeLoadingText">Actualizando resumen…</p></div>
        <div class="toast-stack" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
