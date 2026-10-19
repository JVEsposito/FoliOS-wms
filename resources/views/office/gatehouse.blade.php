<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Ingresos Portería</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-gatehouse.css', 'resources/js/office-gatehouse.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
<section class="office-access" id="officeAccess">
    <div class="office-access__brand"><x-folios-logo surface="dark" /><p class="eyebrow">PORTERÍA</p><h1>Registra la llegada de cada camión.</h1><p>Una bitácora para Portería y Romana, desde celular o PDA.</p></div>
    <form class="office-access__form" id="officeLoginForm">
        <h2>Ingresar a Portería</h2>
        <label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label>
        <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
        <p id="officeLoginError" role="alert"></p><button class="primary-button" type="submit">Entrar</button>
    </form>
</section>
<main class="office-app is-hidden" id="officeApp">
    <x-office.navigation domain="materia-prima" office="porteria" context="PORTERÍA · INGRESOS" />
    <section class="gate-workspace" id="officeContent">
        <header><p class="eyebrow">PORTERÍA</p><h1>Ingresos Portería</h1><p>Registra cada llegada, aunque la patente o la guía hayan ingresado antes.</p></header>
        <p id="gateStatus" role="status" aria-live="polite"></p>
        <div class="gate-columns">
            <section class="panel gate-panel" id="gateCapture" hidden>
                <h2 id="gateFormTitle">Nuevo ingreso</h2>
                <p id="gateClock">Fecha y hora automáticas al registrar, en horario de Chile.</p>
                <form id="gateForm">
                    <fieldset id="gateFields" class="gate-fields">
                        <label>Motivo<select name="motivo" required><option value="carga">Con carga</option><option value="vacio">Vacío</option><option value="servicio">Servicio</option></select></label>
                        <label>Cliente / servicio<select name="cliente_id" id="gateClient" required><option value="">Seleccione</option></select></label>
                        <label id="gateOtherRow" hidden>Otro cliente o servicio<input name="cliente_otro" maxlength="180" disabled></label>
                        <label>Patente<input name="patente" maxlength="12" autocomplete="off" autocapitalize="characters" required></label>
                        <label>Tipo de camión<select name="tipo_camion" required><option value="termo">Termo</option><option value="plano">Plano</option><option value="otro">Otro</option></select></label>
                        <label id="gateTruckOtherRow" hidden>Especifique tipo<input name="tipo_camion_otro" maxlength="80" disabled></label>
                        <label>Nombre del conductor<input name="nombre_conductor" maxlength="180" autocomplete="off" required></label>
                        <div class="gate-wide"><span>Guías <small>(opcional para vacío o servicio)</small></span><div id="gateGuideRows"></div><button class="secondary-button" type="button" id="gateAddGuide">+ Agregar guía</button></div>
                        <label>Patente carro <small>(opcional)</small><input name="patente_carro" maxlength="12" autocomplete="off" autocapitalize="characters"></label>
                        <label>Cantidad <small>(opcional)</small><input name="cantidad" type="number" min="0" max="1000000" step="1" inputmode="numeric"></label>
                        <label>Unidad<select name="unidad"><option value="">Sin cantidad</option><option value="bins">Bins</option><option value="bultos">Bultos</option></select></label>
                        <label class="gate-wide">Observación <small>(opcional)</small><textarea name="observacion" maxlength="500" rows="2"></textarea></label>
                        <label class="gate-wide" id="gateCorrectionRow" hidden>Motivo de la corrección<textarea name="motivo_correccion" minlength="5" maxlength="500" rows="2" disabled></textarea></label>
                    </fieldset>
                    <p id="gateFormError" role="alert"></p>
                    <div class="gate-actions"><button class="primary-button" type="submit" id="gateSubmit">Registrar ingreso</button><button class="secondary-button" id="gateCancelEdit" type="button" hidden>Cancelar edición</button></div>
                </form>
            </section>
            <section class="panel gate-panel">
                <h2>Bitácora diaria</h2>
                <form id="gateFilters" class="gate-filters"><label>Fecha<input id="gateDate" name="fecha" type="date" required></label><label>Buscar<input name="buscar" type="search" placeholder="Patente, cliente, conductor o guía" maxlength="100"></label><button class="secondary-button" type="submit">Consultar</button></form>
                <p id="gateCount" aria-live="polite"></p><div id="gateEntries" class="gate-entries"></div>
                <div class="gate-actions"><button class="secondary-button" id="gatePrev" type="button">Anterior</button><span id="gatePage"></span><button class="secondary-button" id="gateNext" type="button">Siguiente</button></div>
            </section>
        </div>
    </section>
</main>
<dialog id="gateHistory"><h2>Historial del ingreso</h2><div id="gateHistoryBody"></div><button class="secondary-button" id="gateHistoryClose" type="button">Cerrar</button></dialog>
</body>
</html>
