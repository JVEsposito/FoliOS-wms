<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">

        <title>FoliOS · Administración de accesos</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-admin.css', 'resources/js/office-admin.js', 'resources/js/office-access-profiles.js', 'resources/js/office-user-management.js', 'resources/js/office-label-profiles.js', 'resources/js/office-record-formats.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand admin-access-brand">
                <div class="office-logo" aria-hidden="true">⚿</div>
                <p class="eyebrow">FoliOS · ADMINISTRACIÓN</p>
                <h1 id="officeAccessTitle">Administra los accesos y la configuración transversal de la operación.</h1>
                <p>Los accesos, la temporada y los clientes se administran una sola vez y se aplican transversalmente a todas las oficinas.</p>
                <div class="feature-row">
                    <span>Contraseñas cifradas</span>
                    <span>Roles operacionales</span>
                    <span>Tablets identificadas</span>
                    <span>Temporada global</span>
                    <span>Clientes globales</span>
                </div>
            </div>

            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div>
                    <p class="eyebrow">ACCESO ADMINISTRATIVO</p>
                    <h2>Ingresar a accesos</h2>
                    <p>Utiliza una cuenta con rol administrador.</p>
                </div>
                <label>
                    <span>Correo electrónico</span>
                    <input name="email" type="email" autocomplete="username" placeholder="administrador@empresa.cl" required>
                </label>
                <label>
                    <span>Contraseña</span>
                    <input name="password" type="password" autocomplete="current-password" placeholder="••••••••" required>
                </label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar a administración <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            
            <x-office.navigation domain="administracion" office="accesos" context="ADMINISTRACIÓN & GERENCIA" icon="⚙" />


            <section class="admin-workspace">
                <header class="admin-heading">
                    <div>
                        <p class="eyebrow">CONFIGURACIÓN TRANSVERSAL</p>
                        <h1>Accesos, temporada y clientes</h1>
                        <p>Administra los maestros transversales que consumen Romana, Validación, Materiales, Envases y las demás oficinas, sin mezclar sus flujos operacionales.</p>
                    </div>
                    <button class="secondary-button admin-reload" id="reloadAccessesButton" type="button">↻ Actualizar listados</button>
                </header>

                <div class="admin-metrics">
                    <article><span>USUARIOS ACTIVOS</span><strong id="activeUsersCount">0</strong></article>
                    <article><span>TABLETS ACTIVAS</span><strong id="activeDevicesCount">0</strong></article>
                    <article><span>ACCESOS VIGENTES</span><strong id="activeAccessSessionsCount">—</strong></article>
                    <article><span>CLIENTES ACTIVOS</span><strong id="activeClientsCount">0</strong></article>
                    <article><span>TEMPORADA ACTIVA</span><strong id="activeSeasonCode">—</strong></article>
                    <article><span>ÚLTIMO ACCESO TABLET</span><strong id="lastDeviceAccess">Sin accesos</strong></article>
                </div>

                <x-office.panel-switcher
                    id="administration"
                    label="Secciones de administración"
                    default="seasons"
                    :panels="[
                        'seasons' => ['label' => 'Temporadas', 'icon' => '◷'],
                        'clients' => ['label' => 'Clientes', 'icon' => '◇'],
                        'labels' => ['label' => 'Etiquetas', 'icon' => '▤'],
                        'formats' => ['label' => 'Formatos de registro', 'icon' => '▤'],
                        'profiles' => ['label' => 'Perfiles', 'icon' => '⚙'],
                        'users' => ['label' => 'Usuarios', 'icon' => '●'],
                        'sessions' => ['label' => 'Sesiones', 'icon' => '◉'],
                        'devices' => ['label' => 'Tablets', 'icon' => '▣'],
                    ]"
                />

                <section class="admin-panel panel" id="administration-panel-formats" data-office-panel-group="administration" data-office-panel-id="formats" role="tabpanel" aria-labelledby="administration-tab-formats" hidden>
                    <div class="admin-panel__heading"><div><p class="eyebrow">CONTROL DE DOCUMENTOS</p><h2>Formatos de registro</h2></div><span id="recordFormatsSummary">Sin cargar</span></div>
                    <p class="admin-form__hint">La versión activa se guarda al cerrar cada recepción. Las reimpresiones conservan su encabezado original. La fecha corresponde a la vigencia documental; la casilla Activo publica el formato para los nuevos cierres.</p>
                    <form class="admin-form" id="recordFormatForm">
                        <input name="id" type="hidden">
                        <input name="actualizado_at_conocido" type="hidden">
                        <div class="admin-form__grid">
                            <label class="field"><span>Código *</span><input name="codigo" maxlength="30" placeholder="RPR-01" required></label>
                            <label class="field"><span>Nombre *</span><input name="nombre" maxlength="150" required></label>
                            <label class="field"><span>Versión *</span><input name="version" maxlength="20" required></label>
                            <label class="field"><span>Fecha de vigencia *</span><input name="fecha_vigencia" type="date" required></label>
                            <label class="field field--wide"><span>Localidad *</span><textarea name="localidad" maxlength="255" rows="2" required></textarea></label>
                        </div>
                        <label class="admin-check"><input name="activo" type="checkbox" checked><span>Activo para nuevos documentos</span></label>
                        <p class="form-error" id="recordFormatError" role="alert"></p>
                        <div class="admin-form__actions"><button class="secondary-button is-hidden" id="cancelRecordFormatEdit" type="button">Nuevo formato</button><button class="primary-button" type="submit">Guardar formato</button></div>
                    </form>
                    <div class="admin-table-scroll"><table class="admin-table"><thead><tr><th>Formato</th><th>Versión y vigencia</th><th>Estado</th><th>Última modificación</th><th>Acciones</th></tr></thead><tbody id="recordFormatsTableBody"></tbody></table></div>
                    <form class="admin-form" id="containerReferencesForm" hidden>
                        <h3>Reparto del neto por especie</h3><p>Las referencias definen la proporción entre envases con fruta; el neto total siempre viene de la báscula.</p>
                        <label class="field"><span>Especie</span><select name="especie_id"></select></label>
                        <label class="field"><span>Envase sugerido para el reparto</span><select name="sugerido"></select></label>
                        <div class="admin-form__grid" id="containerReferenceRows"></div>
                        <p class="form-error" id="containerReferenceError" role="status"></p><button class="primary-button" type="submit">Guardar referencias</button>
                    </form>
                    <div class="is-hidden" id="recordFormatHistory"><h3 id="recordFormatHistoryTitle">Historial del formato</h3><div class="admin-table-scroll"><table class="admin-table"><thead><tr><th>Fecha y usuario</th><th>Anterior</th><th>Nuevo</th></tr></thead><tbody id="recordFormatHistoryBody"></tbody></table></div><button class="secondary-button" id="recordFormatHistoryMore" type="button">Ver más cambios</button></div>
                </section>

                <section class="admin-panel admin-season-panel panel" id="administration-panel-seasons" data-office-panel-group="administration" data-office-panel-id="seasons" role="tabpanel" aria-labelledby="administration-tab-seasons">
                    <div class="admin-panel__heading">
                        <div><p class="eyebrow">TEMPORADA GLOBAL</p><h2 id="seasonsTitle">Ciclo operacional compartido</h2></div>
                        <span id="seasonsSummary">0 registradas</span>
                    </div>

                    <form class="admin-form" id="seasonForm" novalidate>
                        <input name="id" type="hidden">
                        <div class="admin-form__grid admin-form__grid--season">
                            <label class="field"><span>Código *</span><input name="codigo" maxlength="30" placeholder="2026-2027" required></label>
                            <label class="field"><span>Nombre *</span><input name="nombre" maxlength="100" placeholder="Temporada cerezas 2026–2027" required></label>
                            <label class="field"><span>Tipo al crear *</span><select name="tipo" id="seasonType"><option value="productiva">Productiva</option><option value="prueba">Prueba</option></select><small id="seasonTypeHint">Las temporadas de prueba no se activan ni aparecen en reportes operacionales.</small></label>
                            <label class="field"><span id="seasonStartLabel">Inicio *</span><input name="fecha_inicio" type="date"><small>Obligatoria en temporadas productivas.</small></label>
                            <label class="field"><span id="seasonEndLabel">Término *</span><input name="fecha_fin" type="date"><small>No puede cruzarse con otra temporada productiva.</small></label>
                            <label class="field"><span>Prefijo documental</span><input name="prefijo_documental" maxlength="6" placeholder="T27" autocomplete="off"><small>2 a 6 letras o números. Identificará la temporada en sus documentos; se exige para activarla.</small></label>
                            <label class="field"><span>Intervalo global de embarques *</span><input name="intervalo_embarques_minutos" type="number" min="15" max="240" step="5" value="60" list="shipmentIntervals" required><small>Minutos entre inicios de carga; aplica durante las 24 horas.</small></label>
                            <datalist id="shipmentIntervals"><option value="30"></option><option value="45"></option><option value="60"></option></datalist>
                        </div>
                        <label class="admin-check"><input name="activa" type="checkbox"><span>Dejar como temporada activa para todas las oficinas</span></label>
                        <p class="admin-form__hint">La activación es global. Las oficinas operacionales solo consultan esta configuración y mantienen sus flujos separados.</p>
                        <p class="form-error" id="seasonError" role="alert"></p>
                        <div class="admin-form__actions">
                            <button class="secondary-button is-hidden" id="cancelSeasonEdit" type="button">Nueva temporada</button>
                            <button class="primary-button" type="submit">Guardar temporada <span>→</span></button>
                        </div>
                    </form>

                    <div class="admin-table-scroll admin-season-list">
                        <table class="admin-table">
                            <thead><tr><th>Temporada</th><th>Vigencia</th><th>Calendario</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="seasonsTableBody"></tbody>
                        </table>
                    </div>

                    <form class="admin-form admin-migration-form is-hidden" id="seasonClassificationForm" novalidate>
                        <input name="temporada_id" type="hidden">
                        <input name="tipo" type="hidden">
                        <div class="admin-panel__heading admin-migration-heading">
                            <div><p class="eyebrow">TIPO DE TEMPORADA</p><h3 id="seasonClassificationTitle">Declarar temporada de prueba</h3></div>
                            <button class="secondary-button" id="cancelSeasonClassification" type="button">Cerrar</button>
                        </div>
                        <p class="admin-form__hint" id="seasonClassificationHint">Solo cambia el tipo de esta temporada. No mueve ni borra datos de la temporada activa ni de Materiales.</p>
                        <label class="field field--wide"><span>Motivo *</span><textarea name="motivo" maxlength="500" minlength="10" rows="3" required placeholder="Temporada creada para ensayos del sistema."></textarea></label>
                        <p class="form-error" id="seasonClassificationError" role="alert"></p>
                        <div class="admin-form__actions"><button class="primary-button" id="seasonClassificationSubmit" type="submit">Confirmar <span>→</span></button></div>
                    </form>

                    <form class="admin-form admin-migration-form is-hidden" id="seasonMigrationForm" novalidate>
                        <input name="temporada_destino_id" type="hidden">
                        <div class="admin-panel__heading admin-migration-heading">
                            <div><p class="eyebrow">MIGRACIÓN CONTROLADA</p><h3 id="seasonMigrationTitle">Preparar nueva temporada</h3></div>
                            <button class="secondary-button" id="cancelSeasonMigration" type="button">Cerrar</button>
                        </div>
                        <div class="admin-form__grid admin-form__grid--migration">
                            <label class="field field--wide"><span>Temporada de origen *</span><select name="temporada_origen_id" required></select></label>
                            <label class="admin-check"><input name="copiar_catalogo_validacion" type="checkbox" checked><span>Copiar catálogos de Validación</span></label>
                            <label class="admin-check"><input name="copiar_catalogo_materiales" type="checkbox" checked><span>Copiar ítems de Bodega y sus clientes globales</span></label>
                            <label class="admin-check"><input name="migrar_inventario_materiales" type="checkbox"><span>Migrar inventario vivo de Bodega</span></label>
                            <label class="admin-check"><input name="activar_destino" type="checkbox"><span>Activar el destino para todos los procesos</span></label>
                        </div>
                        <p class="admin-form__hint">No se copian recepciones, validaciones, cargas ni procesos históricos. El inventario conserva folio, ubicación, saldos y kardex; requiere no tener despachos ni reservas abiertas.</p>
                        <p class="form-error" id="seasonMigrationError" role="alert"></p>
                        <div class="admin-form__actions"><button class="primary-button" type="submit">Ejecutar migración <span>→</span></button></div>
                    </form>
                </section>

                <section class="admin-panel admin-client-panel panel" id="administration-panel-clients" data-office-panel-group="administration" data-office-panel-id="clients" role="tabpanel" aria-labelledby="administration-tab-clients">
                    <div class="admin-panel__heading">
                        <div><p class="eyebrow">MAESTRO TRANSVERSAL</p><h2 id="clientsTitle">Clientes de servicio</h2></div>
                        <span id="globalClientsSummary">0 registrados</span>
                    </div>

                    <form class="admin-form" id="globalClientForm" novalidate>
                        <input name="id" type="hidden">
                        <div class="admin-form__grid admin-form__grid--season">
                            <label class="field"><span>Código *</span><input name="codigo" maxlength="80" placeholder="AG-001" required></label>
                            <label class="field"><span>Nombre *</span><input name="nombre" maxlength="180" placeholder="LA AGUADA" required></label>
                            <label class="field"><span>Código ERP futuro</span><input name="codigo_externo" maxlength="150"></label>
                            <label class="field"><span>Sigla documental (2 letras)</span><input name="codigo_folio_materiales" minlength="2" maxlength="2" pattern="[A-Za-z]{2}" placeholder="AG"></label>
                            <label class="admin-check"><input name="activo" type="checkbox" checked><span>Cliente activo para todas las oficinas</span></label>
                        </div>
                        <p class="admin-form__hint">Este es el único lugar para crear o modificar clientes. La sigla se reutiliza en folios de materiales (FGE0000001), inspecciones (IGE0000001) y embarques (EGE0000001).</p>
                        <p class="form-error" id="globalClientError" role="alert"></p>
                        <div class="admin-form__actions">
                            <button class="secondary-button is-hidden" id="cancelGlobalClientEdit" type="button">Nuevo cliente</button>
                            <button class="primary-button" type="submit">Guardar cliente <span>→</span></button>
                        </div>
                    </form>

                    <div class="admin-table-scroll">
                        <table class="admin-table">
                            <thead><tr><th>Cliente</th><th>Sigla documental</th><th>Código ERP</th><th>Presencia</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="globalClientsTableBody"></tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel panel" id="administration-panel-labels" data-office-panel-group="administration" data-office-panel-id="labels" role="tabpanel" aria-labelledby="administration-tab-labels">
                    <div class="admin-panel__heading">
                        <div><p class="eyebrow">IMPRESIÓN TRANSVERSAL</p><h2 id="labelProfilesTitle">Perfiles de etiquetas</h2></div>
                        <span id="labelProfilesSummary">0 registrados</span>
                    </div>
                    <form class="admin-form" id="labelProfileForm" novalidate>
                        <input name="id" type="hidden">
                        <div class="admin-form__grid admin-form__grid--label-profile">
                            <label class="field"><span>Código *</span><input name="codigo" maxlength="60" placeholder="ZEBRA-100X50-203" required></label>
                            <label class="field"><span>Nombre *</span><input name="nombre" maxlength="120" placeholder="Zebra 100 × 50 mm" required></label>
                            <label class="field"><span>Fabricante *</span><select name="fabricante" required><option value="Zebra">Zebra</option><option value="Bixolon">Bixolon</option><option value="Genérico">Genérico / compatible</option></select></label>
                            <label class="field"><span>Modelo</span><input name="modelo" maxlength="80" placeholder="ZT231, SLP-TX400…"></label>
                            <label class="field"><span>DPI *</span><select name="dpi" required><option value="203">203 dpi</option><option value="300">300 dpi</option><option value="600">600 dpi</option></select></label>
                            <label class="field"><span>Lenguaje de impresión *</span><select name="lenguaje" required><option value="zpl">ZPL II · Zebra</option><option value="bpl-z">BPL-Z · BIXOLON</option></select></label>
                            <label class="field"><span>Ancho (mm) *</span><input name="ancho_mm" type="number" min="30" max="200" step="0.01" value="100" required></label>
                            <label class="field"><span>Alto (mm) *</span><input name="alto_mm" type="number" min="20" max="200" step="0.01" value="50" required></label>
                            <label class="field"><span>Orientación *</span><select name="orientacion" required><option value="horizontal">Horizontal</option><option value="vertical">Vertical</option></select></label>
                            <label class="admin-check"><input name="activo" type="checkbox" checked><span>Perfil activo</span></label>
                            <label class="admin-check"><input name="predeterminado" type="checkbox"><span>Usar como predeterminado</span></label>
                        </div>
                        <p class="admin-form__hint">El perfil define equipo, tamaño, resolución y orientación. Las descargas editables se generan en .nlbl para ZebraDesigner 3 y NiceLabel; la IP se configura en cada PDA/tablet.</p>
                        <p class="form-error" id="labelProfileError" role="alert"></p>
                        <div class="admin-form__actions">
                            <button class="secondary-button is-hidden" id="cancelLabelProfileEdit" type="button">Nuevo perfil</button>
                            <button class="primary-button" type="submit">Guardar perfil <span>→</span></button>
                        </div>
                    </form>
                    <div class="admin-table-scroll">
                        <table class="admin-table">
                            <thead><tr><th>Perfil</th><th>Equipo</th><th>Formato</th><th>Lenguaje</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="labelProfilesTableBody"></tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel panel access-profiles-panel" id="administration-panel-profiles" data-office-panel-group="administration" data-office-panel-id="profiles" role="tabpanel" aria-labelledby="administration-tab-profiles">
                    <div class="admin-panel__heading">
                        <div><p class="eyebrow">PERFILES Y PERMISOS</p><h2 id="accessProfilesTitle">Perfiles de acceso</h2></div>
                        <span id="accessProfilesSummary">0 configurados</span>
                    </div>

                    <div class="access-profiles-layout">
                        <form class="admin-form access-profile-form" id="accessProfileForm" novalidate>
                            <input name="id" type="hidden">
                            <div class="admin-form__grid admin-form__grid--access-profile">
                                <label class="field"><span>Código *</span><input name="codigo" maxlength="80" placeholder="SUPERVISOR_RECEPCION" required></label>
                                <label class="field"><span>Nombre *</span><input name="nombre" maxlength="150" placeholder="Supervisor de recepción" required></label>
                                <label class="field"><span>Descripción</span><input name="descripcion" maxlength="500" placeholder="Responsabilidades y alcance del perfil"></label>
                                <label class="field"><span>Modo de acceso *</span><select name="solo_consulta" required><option value="0">Operacional</option><option value="1">Solo consulta</option></select></label>
                                <label class="field"><span>Nivel operacional base *</span><select name="rol_base" required></select></label>
                            </div>
                            <p class="admin-form__hint">Configura por separado las oficinas PC y los módulos PDA/tablet. En modo Solo consulta, el usuario puede navegar y exportar información de las oficinas PC seleccionadas, pero no puede crear, editar, eliminar, validar, mover ni cerrar procesos. Los módulos PDA/tablet quedan deshabilitados automáticamente. Para administrar usuarios y permisos, utiliza nivel Administrador y habilita «Accesos y temporadas».</p>
                            <div class="access-permission-heading">
                                <div><p class="eyebrow">OFICINAS PC</p><h3>Oficinas y módulos web</h3></div>
                                <span>Definen la navegación disponible al ingresar desde un computador.</span>
                            </div>
                            <div class="access-module-selector" id="accessModuleSelector"></div>
                            <div class="access-permission-heading">
                                <div><p class="eyebrow">PDA / TABLET</p><h3>Módulos operacionales móviles</h3></div>
                                <span>Selecciona explícitamente qué espacios móviles puede abrir este perfil.</span>
                            </div>
                            <div class="access-module-selector access-module-selector--tablet" id="accessTabletModuleSelector"></div>
                            <p class="admin-form__hint">Solo se muestran módulos móviles implementados. Cada módulo PDA/tablet requiere al menos una de sus oficinas relacionadas.</p>
                            <label class="admin-check"><input name="activo" type="checkbox" checked><span>Perfil activo y disponible para asignar</span></label>
                            <p class="form-error" id="accessProfileError" role="alert"></p>
                            <div class="admin-form__actions">
                                <button class="secondary-button is-hidden" id="cancelAccessProfileEdit" type="button">Nuevo perfil</button>
                                <button class="primary-button" type="submit">Guardar perfil <span>→</span></button>
                            </div>
                        </form>

                        <div class="admin-table-scroll">
                            <table class="admin-table access-profiles-table">
                                <thead><tr><th>Perfil</th><th>Nivel base</th><th>Permisos</th><th>Usuarios</th><th>Estado</th><th>Acciones</th></tr></thead>
                                <tbody id="accessProfilesTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <div class="admin-grid office-panel-workspace">
                    <section class="admin-panel panel" id="administration-panel-users" data-office-panel-group="administration" data-office-panel-id="users" role="tabpanel" aria-labelledby="administration-tab-users">
                        <div class="admin-panel__heading">
                            <div><p class="eyebrow">PERSONAS</p><h2 id="usersTitle">Usuarios</h2></div>
                            <span id="usersSummary">0 registrados</span>
                        </div>

                        <form class="admin-form" id="createUserForm" novalidate>
                            <div class="admin-form__grid">
                                <label class="field field--wide"><span>Nombre completo *</span><input name="nombre" maxlength="255" placeholder="Ej. Camilo González" required></label>
                                <label class="field field--wide"><span>Correo electrónico *</span><input name="email" type="email" maxlength="255" autocomplete="off" placeholder="camilo@empresa.cl" required></label>
                                <label class="field"><span>Perfil de acceso *</span><select name="perfil_acceso_id" required><option value="">Cargando perfiles…</option></select></label>
                                <label class="field"><span>Contraseña temporal *</span><input name="password" type="password" minlength="10" maxlength="255" autocomplete="new-password" placeholder="Mínimo 10 caracteres" required></label>
                                <label class="field"><span>Confirmar contraseña *</span><input name="password_confirmation" type="password" minlength="10" maxlength="255" autocomplete="new-password" required></label>
                            </div>
                            <p class="admin-form__hint">Mínimo 10 caracteres; debe contener al menos una letra y un número. Al editar, déjala vacía para conservar la contraseña actual. Para retirar un acceso, desactiva el usuario: sus sesiones se cierran y su trazabilidad se conserva.</p>
                            <p class="form-error" id="createUserError" role="alert"></p>
                            <div class="admin-form__actions"><button class="primary-button" type="submit">Crear usuario <span>→</span></button></div>
                        </form>

                        <div class="admin-table-scroll">
                            <table class="admin-table">
                                <thead><tr><th>Usuario</th><th>Perfil</th><th>Estado</th></tr></thead>
                                <tbody id="usersTableBody"></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="admin-panel panel" id="administration-panel-devices" data-office-panel-group="administration" data-office-panel-id="devices" role="tabpanel" aria-labelledby="administration-tab-devices">
                        <div class="admin-panel__heading">
                            <div><p class="eyebrow">EQUIPOS</p><h2 id="devicesTitle">Tablets autorizadas</h2></div>
                            <span id="devicesSummary">0 registradas</span>
                        </div>

                        <form class="admin-form" id="createDeviceForm" novalidate>
                            <div class="admin-form__grid admin-form__grid--device">
                                <label class="field"><span>Código de tablet *</span><input name="codigo" maxlength="100" autocomplete="off" placeholder="TABLET-02" required></label>
                                <label class="field"><span>Nombre descriptivo *</span><input name="nombre" maxlength="150" placeholder="Tablet cámara norte" required></label>
                            </div>
                            <p class="admin-form__hint">El código se convierte a mayúsculas y debe coincidir con el utilizado al iniciar turno.</p>
                            <p class="form-error" id="createDeviceError" role="alert"></p>
                            <div class="admin-form__actions"><button class="primary-button" type="submit">Autorizar tablet <span>→</span></button></div>
                        </form>

                        <div class="admin-table-scroll">
                            <table class="admin-table">
                                <thead><tr><th>Tablet</th><th>Último acceso</th><th>Estado</th></tr></thead>
                                <tbody id="devicesTableBody"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section class="admin-panel admin-sessions-panel panel" id="administration-panel-sessions" data-office-panel-group="administration" data-office-panel-id="sessions" role="tabpanel" aria-labelledby="administration-tab-sessions">
                    <div class="admin-panel__heading">
                        <div><p class="eyebrow">CONTROL DE ACCESOS</p><h2>Sesiones con acceso vigente</h2></div>
                        <span id="accessSessionsSummary">0 sesiones</span>
                    </div>
                    <p class="admin-form__hint">Se muestran accesos autorizados en Oficina y tablet, aunque el equipo no esté conectado en este momento. La última actividad solo aparece si está habilitado su registro.</p>
                    <div class="admin-table-scroll admin-sessions-scroll">
                        <table class="admin-table">
                            <thead><tr><th>Usuario</th><th>Acceso</th><th>Inicio</th><th>Última actividad</th><th>Acción</th></tr></thead>
                            <tbody id="accessSessionsTableBody"><tr class="admin-empty"><td colspan="5">Cargando accesos…</td></tr></tbody>
                        </table>
                    </div>
                    <div class="admin-sessions-pagination">
                        <button id="accessSessionsPrevious" type="button">← Anterior</button>
                        <span id="accessSessionsPage">Página 1 de 1</span>
                        <button id="accessSessionsNext" type="button">Siguiente →</button>
                    </div>
                </section>
            </section>
        </main>

        <dialog class="admin-reset-dialog admin-archive-dialog" id="seasonArchiveDialog" aria-labelledby="seasonArchiveTitle">
            <div class="admin-reset-dialog__shell">
                <header>
                    <div>
                        <p class="eyebrow">ARCHIVO DE TEMPORADA</p>
                        <h2 id="seasonArchiveTitle">Archivo completo</h2>
                        <p id="seasonArchiveDescription">Respaldo restaurable de la temporada cerrada: esquema, datos, Excel de líneas limpias y verificación de integridad. Generarlo no borra ningún dato.</p>
                    </div>
                    <button class="admin-reset-dialog__close" id="closeSeasonArchive" type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="admin-archive-dialog__body">
                    <p class="admin-archive-dialog__eligibility" id="seasonArchiveEligibility"></p>
                    <div id="seasonArchiveList"><p>Cargando archivos…</p></div>
                </div>
                <footer>
                    <button class="secondary-button" id="cancelSeasonArchive" type="button">Cerrar</button>
                    <button class="primary-button" id="createSeasonArchive" type="button" disabled>Generar archivo</button>
                </footer>
            </div>
        </dialog>

        <dialog class="admin-reset-dialog admin-archive-dialog admin-closing-dialog" id="seasonClosingDialog" aria-labelledby="seasonClosingTitle">
            <div class="admin-reset-dialog__shell">
                <header>
                    <div>
                        <p class="eyebrow">CIERRE DE TEMPORADA</p>
                        <h2 id="seasonClosingTitle">Registros sin cerrar</h2>
                        <p id="seasonClosingDescription">Lo que la temporada deja abierto. Ciérralo en su módulo o, si ya no corresponde, regularízalo con motivo. Materiales no participa.</p>
                    </div>
                    <button class="admin-reset-dialog__close" id="closeSeasonClosing" type="button" aria-label="Cerrar">×</button>
                </header>
                <div class="admin-archive-dialog__body">
                    <p class="admin-archive-dialog__eligibility" id="seasonClosingStatus" role="status"></p>
                    <div class="admin-closing-summary" id="seasonClosingSummary"><p>Cargando diagnóstico…</p></div>
                    <section class="admin-closing-detail is-hidden" id="seasonClosingDetail" aria-labelledby="seasonClosingDetailTitle">
                        <div class="admin-closing-detail__heading">
                            <div><h3 id="seasonClosingDetailTitle">Categoría</h3><p id="seasonClosingHowTo"></p></div>
                            <label class="admin-closing-select-all"><input id="seasonClosingSelectAll" type="checkbox"><span>Seleccionar visibles</span></label>
                        </div>
                        <p class="admin-closing-detail__block" id="seasonClosingBlock" hidden></p>
                        <div class="admin-table-scroll">
                            <table class="admin-archive-table admin-closing-table">
                                <thead><tr><th><span class="office-visually-hidden">Seleccionar</span></th><th>Registro</th><th>Detalle</th><th>Responsable</th><th>Fecha</th></tr></thead>
                                <tbody id="seasonClosingItems"></tbody>
                            </table>
                        </div>
                        <form class="admin-closing-form" id="seasonClosingForm" novalidate>
                            <p class="admin-form__hint" id="seasonClosingEffect"></p>
                            <label class="field"><span>Categoría del motivo *</span><select name="motivo_categoria" required></select></label>
                            <label class="field field--wide"><span>Motivo *</span><textarea name="motivo" minlength="10" maxlength="500" rows="2" required placeholder="Ej. Pallets despachados en marzo sin cerrar la carga; confirmado con guías físicas."></textarea></label>
                            <p class="form-error" id="seasonClosingError" role="alert"></p>
                            <div class="admin-form__actions"><button class="danger-button" id="seasonClosingSubmit" type="submit" disabled>Regularizar seleccionados</button></div>
                        </form>
                    </section>
                    <details class="admin-closing-history" id="seasonClosingHistory">
                        <summary>Regularizaciones registradas</summary>
                        <div id="seasonClosingHistoryList"><p>Sin regularizaciones.</p></div>
                    </details>
                </div>
                <footer>
                    <button class="secondary-button" id="notifySeasonClosing" type="button">Avisar a responsables</button>
                    <button class="secondary-button" id="refreshSeasonClosing" type="button">Actualizar</button>
                    <button class="secondary-button" id="cancelSeasonClosing" type="button">Cerrar</button>
                </footer>
            </div>
        </dialog>

        <dialog class="admin-reset-dialog" id="operationalResetDialog" aria-labelledby="operationalResetTitle">
            <form class="admin-reset-dialog__shell" id="operationalResetForm" novalidate>
                <header>
                    <div>
                        <p class="eyebrow">REINICIO CONTROLADO</p>
                        <h2 id="operationalResetTitle">Vaciar datos de prueba PT + MP</h2>
                        <p id="operationalResetDescription">La temporada activa se mantiene; solo se elimina su operación de Frigorífico y Materia Prima.</p>
                    </div>
                    <button class="admin-reset-dialog__close" id="closeOperationalReset" type="button" aria-label="Cerrar">×</button>
                </header>

                <div class="admin-reset-scope">
                    <article><strong>Se elimina</strong><p>Romana, folios PT, validaciones, cargas, prefrío, lotes, hidrocooler, asignaciones y movimientos/guías de envases.</p></article>
                    <article><strong>Se conserva</strong><p>Temporada, catálogos, usuarios, configuración física y todos los catálogos y datos operacionales de Bodega.</p></article>
                </div>

                <div class="admin-reset-preview" id="operationalResetPreview">
                    <p>Calculando registros de la temporada activa…</p>
                </div>

                <label class="field"><span>Motivo del reinicio *</span><textarea name="motivo" minlength="10" maxlength="1000" rows="3" placeholder="Ej. Finalizó la etapa de pruebas integrales previa a la puesta en marcha." required></textarea></label>
                <label class="field"><span>Contraseña del administrador *</span><input name="password" type="password" maxlength="255" autocomplete="current-password" required></label>
                <label class="field"><span>Escribe exactamente <code id="operationalResetPhrase">REINICIAR TEMPORADA</code></span><input name="confirmacion" autocomplete="off" required></label>
                <label class="admin-check admin-reset-check"><input name="confirmar_exclusion_bodega" type="checkbox" required><span>Confirmo que Bodega queda excluida y debe conservar todos sus datos.</span></label>
                <label class="admin-check admin-reset-check"><input name="confirmar_preservar_configuracion" type="checkbox" required><span>Confirmo que temporada, catálogos y configuración deben conservarse.</span></label>

                <p class="form-error" id="operationalResetError" role="alert"></p>
                <footer>
                    <button class="secondary-button" id="cancelOperationalReset" type="button">Cancelar</button>
                    <button class="danger-button" id="confirmOperationalReset" type="submit">Reiniciar PT + MP</button>
                </footer>
            </form>
        </dialog>

        <div class="loading is-hidden" id="officeLoading" role="status" aria-live="assertive" aria-hidden="true"><span aria-hidden="true"></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
