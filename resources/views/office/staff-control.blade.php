<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><title>FoliOS · Control de personal</title>
    @vite(['resources/css/office.css', 'resources/css/estiba-ui.css', 'resources/css/office-staff-control.css', 'resources/js/office-staff-control.js'])
    @include('office.partials.arranque')
</head>
<body>
<main class="office-app is-hidden" id="staffApp">
    <x-office.navigation domain="frigorifico" office="control-personal" context="FRIGORÍFICO · SUPERVISIÓN" icon="◷" />
    <div class="staff-shell estiba-ui">
        <x-estiba.heading eyebrow="Supervisión · Control interno" title="Control de personal" description="Registro propio del supervisor: ingresos, salidas, horas extra y ausencias para contrastar a fin de mes con lo que informa RRHH.">
            <button class="eui-button eui-button--secondary" type="button" id="staffPeopleOpen">Personal</button>
            <button class="eui-button eui-button--secondary" type="button" id="staffAbsenceNew">Registrar ausencia</button>
            <button class="eui-button" type="button" id="staffNew">Registrar jornada</button>
        </x-estiba.heading>
        <p id="staffStatus" role="status" aria-live="polite">Consultando control de personal…</p>
        <p id="staffError" class="form-error" role="alert"></p>
        <form id="staffFilters" class="eui-panel staff-panel staff-toolbar">
            <label>Mes<input class="eui-input" type="month" id="staffMonth" required></label>
            <label>Persona<select class="eui-input" id="staffPersonFilter"><option value="">Todas las personas</option></select></label>
            <label>Sector<select class="eui-input" id="staffSectorFilter"><option value="">Todos los sectores</option></select></label>
            <button class="eui-button eui-button--secondary" type="submit">Consultar</button>
            <button class="eui-button eui-button--secondary" type="button" id="staffExportSummary">Excel resumen</button>
            <button class="eui-button eui-button--secondary" type="button" id="staffExportDetail">Excel detalle</button>
            <button class="eui-button eui-button--secondary" type="button" id="staffExportAbsences">Excel ausencias</button>
            <button class="eui-button eui-button--secondary" type="button" id="staffSheetAll">Fichas PDF</button>
        </form>
        <div class="staff-totals" id="staffTotals" aria-live="polite"></div>
        <p class="staff-help" id="staffCriterion"></p>
        <section class="eui-panel staff-panel" aria-labelledby="staffSummaryTitle">
            <h2 id="staffSummaryTitle">Resumen mensual por persona</h2>
            <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Persona</th><th>Cerradas</th><th>Pendientes</th><th>Trabajado</th><th>Horas extra</th><th>Ausencias</th><th>Ficha mensual</th></tr></thead><tbody id="staffSummary"></tbody></table></div>
        </section>
        <section class="eui-panel staff-panel" aria-labelledby="staffRecordsTitle">
            <div class="staff-toolbar"><h2 id="staffRecordsTitle">Jornadas del mes</h2><label>Estado<select class="eui-input" id="staffState"><option value="">Todas las jornadas</option><option value="pendiente">Sin salida</option><option value="cerrada">Cerradas</option><option value="anulada">Anuladas</option></select></label></div>
            <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Persona / sector</th><th>Ingreso</th><th>Salida</th><th>Base / colación</th><th>Trabajado</th><th>Extra</th><th>Acciones</th></tr></thead><tbody id="staffRecords"></tbody></table></div>
        </section>
        <section class="eui-panel staff-panel" aria-labelledby="staffAbsencesTitle">
            <div class="staff-toolbar"><h2 id="staffAbsencesTitle">Ausencias del mes</h2><label>Estado<select class="eui-input" id="staffAbsenceState"><option value="">Todas las ausencias</option><option value="vigente">Vigentes</option><option value="anulada">Anuladas</option></select></label></div>
            <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Persona</th><th>Tipo</th><th>Periodo</th><th>Días en el mes</th><th>Observación</th><th>Acciones</th></tr></thead><tbody id="staffAbsences"></tbody></table></div>
        </section>
        <p class="staff-help">Extra interna = tiempo de permanencia − colación − jornada base, con mínimo cero. Define la base correspondiente a cada turno; puedes usar 00:00 para una jornada enteramente extraordinaria. Este control es interno del supervisor: no envía ni recibe datos de RRHH y no liquida remuneraciones. La ficha mensual PDF sirve para revisar día a día con el trabajador lo que tenemos frente a lo que registró RRHH.</p>
    </div>
</main>
<dialog id="staffPeopleDialog" class="staff-dialog estiba-ui" aria-labelledby="staffPeopleTitle">
    <div class="staff-toolbar"><h2 id="staffPeopleTitle">Personal de Frigorífico</h2><button class="eui-button eui-button--secondary" type="button" data-close>Cerrar</button></div>
    <label>Persona a editar<select class="eui-input" id="staffPeopleSelect"><option value="">Nueva persona</option></select></label>
    <form id="staffPeopleForm" class="staff-fields">
        <label>Nombre completo<input class="eui-input" id="staffName" maxlength="120" required></label>
        <label>Identificador o RUT (opcional)<input class="eui-input" id="staffIdentifier" maxlength="40"></label>
        <label>Sector<input class="eui-input" id="staffSector" maxlength="80" placeholder="Cámaras, prefrío, repaletizaje…" required></label>
        <label>Función (opcional)<input class="eui-input" id="staffFunction" maxlength="80"></label>
        <label>Jornada base neta (HH:MM)<input class="eui-input" id="staffBase" pattern="[0-9]{1,2}:[0-5][0-9]" placeholder="08:00" required></label>
        <label class="staff-check"><input type="checkbox" id="staffActive" checked> Persona activa</label>
        <p class="staff-help staff-full">La base no incluye colación. Su actualización se aplica a jornadas nuevas; las ya registradas conservan su base. Una persona inactiva conserva todo su historial.</p>
        <p id="staffPeopleError" class="form-error staff-full" role="alert"></p>
        <button class="eui-button staff-full" type="submit">Guardar persona</button>
    </form>
</dialog>
<dialog id="staffEntryDialog" class="staff-dialog estiba-ui" aria-labelledby="staffEntryTitle">
    <div class="staff-toolbar"><h2 id="staffEntryTitle">Registrar jornada</h2><button class="eui-button eui-button--secondary" type="button" data-close>Cerrar</button></div>
    <form id="staffEntryForm" class="staff-fields">
        <label class="staff-full">Persona<select class="eui-input" id="staffEntryPerson" required></select></label>
        <label>Ingreso<input class="eui-input" type="datetime-local" id="staffIn" required></label>
        <label>Salida (opcional al ingresar)<input class="eui-input" type="datetime-local" id="staffOut"></label>
        <label>Jornada base neta (HH:MM)<input class="eui-input" id="staffEntryBase" pattern="[0-9]{1,2}:[0-5][0-9]" required></label>
        <label>Colación descontada (min)<input class="eui-input" type="number" id="staffBreak" min="0" max="240" value="0" required></label>
        <label class="staff-full">Sector<input class="eui-input" id="staffEntrySector" maxlength="80" required></label>
        <label class="staff-full">Observación<textarea class="eui-input" id="staffNote" maxlength="1000" rows="2"></textarea></label>
        <label class="staff-full" id="staffReasonLabel" hidden>Motivo del cierre o corrección<input class="eui-input" id="staffReason" maxlength="500"></label>
        <p class="staff-help staff-full" id="staffEntryHelp"></p>
        <output class="staff-preview staff-full" id="staffPreview" aria-live="polite">El cálculo definitivo se realiza al guardar la salida.</output>
        <p id="staffEntryError" class="form-error staff-full" role="alert"></p>
        <button class="eui-button staff-full" type="submit" id="staffEntrySave">Guardar jornada</button>
    </form>
</dialog>
<dialog id="staffAbsenceDialog" class="staff-dialog estiba-ui" aria-labelledby="staffAbsenceTitle">
    <div class="staff-toolbar"><h2 id="staffAbsenceTitle">Registrar ausencia</h2><button class="eui-button eui-button--secondary" type="button" data-close>Cerrar</button></div>
    <form id="staffAbsenceForm" class="staff-fields">
        <label class="staff-full">Persona<select class="eui-input" id="staffAbsencePerson" required></select></label>
        <label class="staff-full">Tipo<select class="eui-input" id="staffAbsenceType" required></select></label>
        <label>Desde<input class="eui-input" type="date" id="staffAbsenceFrom" required></label>
        <label>Hasta<input class="eui-input" type="date" id="staffAbsenceTo" required></label>
        <label class="staff-full">Observación<textarea class="eui-input" id="staffAbsenceNote" maxlength="1000" rows="2"></textarea></label>
        <label class="staff-full" id="staffAbsenceReasonLabel" hidden>Motivo de la corrección<input class="eui-input" id="staffAbsenceReason" maxlength="500"></label>
        <p class="staff-help staff-full">Días completos sin jornada. No se puede marcar un día que ya tiene una jornada vigente ni superponer dos ausencias. Una falta no se registra por anticipado.</p>
        <p id="staffAbsenceError" class="form-error staff-full" role="alert"></p>
        <button class="eui-button staff-full" type="submit" id="staffAbsenceSave">Guardar ausencia</button>
    </form>
</dialog>
<dialog id="staffCancelDialog" class="staff-dialog estiba-ui" aria-labelledby="staffCancelTitle">
    <h2 id="staffCancelTitle">Anular jornada</h2><p id="staffCancelDescription"></p>
    <form id="staffCancelForm" class="staff-fields"><label class="staff-full">Motivo<textarea class="eui-input" id="staffCancelReason" maxlength="500" required></textarea></label>
        <p id="staffCancelError" class="form-error staff-full" role="alert"></p>
        <button class="eui-button" type="submit">Confirmar anulación</button><button class="eui-button eui-button--secondary" type="button" data-close>Volver</button></form>
</dialog>
<dialog id="staffHistoryDialog" class="staff-dialog estiba-ui" aria-labelledby="staffHistoryTitle">
    <div class="staff-toolbar"><h2 id="staffHistoryTitle">Historial</h2><button class="eui-button eui-button--secondary" type="button" data-close>Cerrar</button></div>
    <div id="staffHistory"></div>
</dialog>
</body>
</html>
