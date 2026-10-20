@php($clock = $clock ?? false)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><meta name="theme-color" content="#07151e">
    <title>FoliOS · {{ $clock ? 'Puesto de marcaje' : 'Recursos humanos' }}</title>
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-rrhh.css', 'resources/js/office-rrhh.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body data-rrhh-clock="{{ $clock ? '1' : '0' }}" data-rrhh-zone="{{ config('app.operational_timezone') }}">
<section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
    <div class="office-access__brand"><div class="office-logo" aria-hidden="true">♙</div><p class="eyebrow">FoliOS · RRHH</p>
        <h1 id="officeAccessTitle">{{ $clock ? 'Entrada y salida de su turno.' : 'El equipo detrás de la operación.' }}</h1>
        <p>{{ $clock ? 'Use su código personal y PIN en un puesto autorizado.' : 'Personal, dotación programada y asistencia por área y cliente.' }}</p></div>
    <form class="office-access__form" id="officeLoginForm">
        <h2>Ingresar a RRHH</h2>
        <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
        <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
        <p class="form-error" id="officeLoginError" role="alert"></p><button class="primary-button" type="submit">Entrar →</button>
    </form>
</section>
<main class="office-app is-hidden" id="officeApp">
    <x-office.navigation domain="administracion" :office="$clock ? 'rrhh-marcaje' : 'rrhh'" context="ADMINISTRACIÓN · RRHH" icon="♙" />
    <section class="rh-workspace">
        <header class="rh-heading"><div><p class="eyebrow">RECURSOS HUMANOS</p><h1>{{ $clock ? 'Puesto de marcaje' : 'Personal y dotación' }}</h1>
            <p>{{ $clock ? 'La hora del comprobante la registra el servidor.' : 'La programación y la asistencia se conservan por separado, incluso si se cancela una asignación.' }}</p></div></header>
        <p id="rhMessage" role="status" aria-live="polite"></p>
        @if($clock)
            <section class="panel rh-clock"><h2>Registrar marcaje</h2>
                <form id="rhClockForm" class="rh-form">
                    <label><span>Código personal</span><input name="codigo" autocomplete="off" maxlength="40" required></label>
                    <label><span>PIN</span><input name="pin" type="password" inputmode="numeric" autocomplete="off" minlength="6" maxlength="12" required></label>
                    <label><span>Movimiento</span><select name="tipo"><option value="entrada">Entrada</option><option value="salida">Salida</option></select></label>
                    <button class="primary-button" type="submit">Registrar</button>
                </form><p id="rhReceipt" class="rh-receipt" role="status" aria-live="polite"></p>
                <small>Marcaje operativo de FoliOS. Para emplearlo como registro laboral oficial se requiere autorización del sistema por la Dirección del Trabajo.</small>
            </section>
        @else
            <nav class="rh-tabs" aria-label="Secciones RRHH"><button type="button" data-rh-tab="personal" aria-pressed="true">Personal</button><button type="button" data-rh-tab="dotacion" aria-pressed="false">Dotación y asistencia</button><button type="button" data-rh-tab="fuentes" aria-pressed="false">Integraciones</button></nav>
            <section class="panel" data-rh-panel="personal"><div class="rh-heading"><h2>Personal</h2><button type="button" class="primary-button" data-rh-manage id="rhNewPerson">Agregar persona</button></div>
                <form id="rhSearchForm" class="rh-toolbar"><label><span>Nombre o código</span><input name="buscar" type="search" maxlength="100"></label><button class="secondary-button" type="submit">Buscar</button></form>
                <div id="rhPeople" class="rh-table-scroll"></div><div id="rhPeoplePages" class="rh-toolbar"></div>
            </section>
            <section class="panel" data-rh-panel="dotacion" hidden><h2>Dotación y asistencia</h2>
                <form id="rhDatesForm" class="rh-toolbar"><label><span>Desde</span><input name="desde" type="date" required></label><label><span>Hasta</span><input name="hasta" type="date" required></label><button type="submit" class="secondary-button">Consultar</button></form>
                <p>Programe desde la ficha de cada persona. Las pausas reales se respaldan con salida y entrada. Las horas con incidencias requieren revisión.</p>
                <div id="rhAssignments" class="rh-table-scroll"></div><div id="rhAssignmentPages" class="rh-toolbar"></div>
            </section>
            <section class="panel" data-rh-panel="fuentes" hidden><div class="rh-heading"><h2>Integraciones de marcaje</h2><button type="button" class="primary-button" id="rhNewSource" data-rh-manage>Agregar fuente</button></div>
                <p>Recepción por lotes con vínculo explícito de personas. GeoVictoria admite registros Punch normalizados; la conexión automática requiere validar el contrato de la cuenta.</p>
                <div id="rhSources"></div><pre id="rhIntegrationResult" class="rh-history" aria-live="polite"></pre>
            </section>
        @endif
    </section>
</main>
@unless($clock)
<dialog id="rhPersonDialog" class="rh-dialog"><form id="rhPersonForm" class="rh-form"><div class="rh-heading"><h2 id="rhPersonTitle">Agregar persona</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Código permanente</span><input name="codigo" maxlength="40" pattern="[A-Za-z0-9_-]+" required></label>
    <label><span>Nombre</span><input name="nombre" maxlength="180" required></label>
    <label><span>Documento / RUT</span><input name="documento" maxlength="40"></label><label><span>Empleador</span><input name="empleador" maxlength="180"></label>
    <label><span>Cargo</span><input name="cargo" maxlength="120"></label><label><span>Área</span><input name="area" maxlength="120"></label><label><span>CECO</span><input name="ceco" maxlength="60"></label>
    <label><span>Fuente de marcaje</span><select name="fuente_marcaje" required></select></label>
    <label><span>Nuevo PIN (6 a 12 números; vacío conserva el actual)</span><input name="pin" type="password" inputmode="numeric" autocomplete="new-password" pattern="[0-9]{6,12}"></label>
    <label><span>Estado</span><select name="activa"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rhAssignmentDialog" class="rh-dialog"><form id="rhAssignmentForm" class="rh-form"><div class="rh-heading"><h2 id="rhAssignmentTitle">Programar asignación</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div><p id="rhDeviceZone"></p>
    <label><span>Cliente</span><select name="cliente_id"><option value="">Sin cliente (uso interno)</option></select></label>
    <label><span>Cargo</span><input name="cargo" maxlength="120" required></label><label><span>Área</span><input name="area" maxlength="120" required></label><label><span>CECO</span><input name="ceco" maxlength="60"></label>
    <label><span>Inicio</span><input name="desde" type="datetime-local" step="60" required></label><label><span>Término</span><input name="hasta" type="datetime-local" step="60" required></label>
    <label><span>Descanso programado (minutos)</span><input name="descanso_programado_minutos" type="number" min="0" max="1439" step="1" value="0" required></label>
    <label><span>Costo interno por hora (opcional)</span><input name="costo_hora" inputmode="decimal"></label><label><span>Moneda del costo</span><input name="moneda_costo" maxlength="3" placeholder="CLP"></label>
    <label><span>Observación</span><textarea name="observacion" maxlength="500"></textarea></label>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Programar</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rhSourceDialog" class="rh-dialog"><form id="rhSourceForm" class="rh-form"><div class="rh-heading"><h2>Fuente externa</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Código permanente</span><input name="codigo" pattern="[a-z][a-z0-9_-]+" maxlength="60" required></label><label><span>Nombre</span><input name="nombre" maxlength="120" required></label>
    <label><span>Formato</span><select name="tipo"><option value="normalizado">Marcajes normalizados</option><option value="geovictoria">GeoVictoria · Punch</option></select></label>
    <label><span>Estado</span><select name="activa"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
    <p>Credenciales opcionales para preparar la conexión. Se guardan cifradas; no se devuelven al navegador. Vacío conserva las actuales al editar.</p>
    <label><span>Dirección del servicio</span><input name="base_url" type="url" maxlength="500"></label><label><span>Usuario del servicio</span><input name="usuario" autocomplete="off" maxlength="500"></label>
    <label><span>Contraseña</span><input name="password" type="password" autocomplete="new-password" maxlength="500"></label><label><span>API key</span><input name="api_key" type="password" autocomplete="off" maxlength="500"></label><label><span>API secret</span><input name="api_secret" type="password" autocomplete="off" maxlength="500"></label>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar fuente</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rhLinkDialog" class="rh-dialog"><form id="rhLinkForm" class="rh-form"><div class="rh-heading"><h2>Vincular persona con fuente externa</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div><p id="rhLinkPerson"></p>
    <label><span>Fuente</span><select name="fuente_id" required></select></label><label><span>Identificador en el proveedor</span><input name="identificador_externo" maxlength="100" required></label>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Vincular</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rhImportDialog" class="rh-dialog"><form id="rhImportForm" class="rh-form"><div class="rh-heading"><h2>Recibir lote de marcajes</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div><p>Elija un archivo JSON con un arreglo de marcajes o un objeto que contenga «marcajes». Hasta 1.000 registros por lote. Cada instante debe incluir su zona horaria.</p>
    <label><span>Fuente</span><select name="fuente_id" required></select></label><label><span>Archivo JSON</span><input name="archivo" type="file" accept=".json,application/json" required></label>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Importar</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rhDetailDialog" class="rh-dialog"><div class="rh-heading"><h2 id="rhDetailTitle">Detalle</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div><div id="rhDetail"></div><button type="button" class="secondary-button" data-rh-close>Cerrar</button></dialog>
@endunless
</body></html>
