<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><title>FoliOS · Labores por turno</title>
    @vite(['resources/css/office.css', 'resources/css/estiba-ui.css', 'resources/css/office-shift-duties.css', 'resources/js/office-shift-duties.js'])
    @include('office.partials.arranque')
</head>
<body>
<main class="office-app is-hidden" id="shiftDutiesApp">
    <x-office.navigation domain="frigorifico" office="labores-turno" context="FRIGORÍFICO · PT" icon="◷" />
    <div class="shift-shell estiba-ui">
        <x-estiba.heading eyebrow="Supervisión de turno" title="Labores por turno" description="Define la función principal y los apoyos de cada camarero. Las labores comparten los destinos de las cargas.">
            <a class="eui-button eui-button--secondary" href="/oficina/operacion-ahora">Operación ahora</a>
            <button class="eui-button eui-button--secondary" type="button" id="shiftReload">Actualizar</button>
        </x-estiba.heading>
        <p id="shiftStatus" role="status" aria-live="polite">Consultando turnos…</p>
        <p class="form-error" id="shiftError" role="alert"></p>
        <section class="eui-panel shift-panel" aria-labelledby="shiftSelectTitle">
            <h2 id="shiftSelectTitle">Turno a configurar</h2>
            <div class="shift-toolbar">
                <label>Turnos guardados<select class="eui-input" id="shiftSelect" aria-label="Turnos guardados"></select></label>
                <button class="eui-button eui-button--secondary" type="button" id="shiftNew">Nuevo turno</button>
                <button class="eui-button eui-button--secondary" type="button" id="shiftCopy">Copiar dotación a otro turno</button>
            </div>
        </section>
        <form class="eui-panel shift-panel" id="shiftForm">
            <div class="shift-fields">
                <label>Nombre del turno<input class="eui-input" id="shiftName" name="nombre" maxlength="80" required placeholder="Ej. Turno noche"></label>
                <label>Inicio<input class="eui-input" id="shiftStart" name="inicio" type="datetime-local" required></label>
                <label>Término<input class="eui-input" id="shiftEnd" name="fin" type="datetime-local" required></label>
            </div>
            <p id="shiftTimezone" class="shift-help"></p>
            <div class="shift-section-heading"><h2>Funciones de los camareros</h2><span id="shiftCount"></span></div>
            <p class="shift-help">Primero se propone la función principal y luego los apoyos, en el orden indicado. Las urgencias físicas mantienen prioridad. Una carga sin movimientos útiles queda en espera y permite continuar otra.</p>
            <div id="shiftAssignments" class="shift-assignments"></div>
            <div class="shift-toolbar">
                <label>Agregar camarero<select class="eui-input" id="shiftAddUser" aria-label="Agregar camarero"></select></label>
                <button class="eui-button eui-button--secondary" type="button" id="shiftAdd">Agregar</button>
            </div>
            <p class="shift-help">Para retirar a un camarero del turno, marca «No disponible». Los cambios se aplican al terminar la maniobra que ya tenga tomada.</p>
            <div class="shift-save"><span id="shiftDraft" aria-live="polite"></span><button class="eui-button" type="submit" id="shiftSave">Guardar turno</button></div>
        </form>
        <section class="eui-panel shift-panel" aria-labelledby="shiftJobsTitle">
            <div class="shift-section-heading"><h2 id="shiftJobsTitle">Labores y esperas del turno</h2></div>
            <p class="shift-help">La tablet conserva la labor mientras tenga movimientos útiles. Una recepción entrega los pallets al acopio de su carga, aunque otro camarero la esté separando.</p>
            <div id="shiftJobs" class="shift-jobs"></div>
        </section>
    </div>
</main>
</body>
</html>
