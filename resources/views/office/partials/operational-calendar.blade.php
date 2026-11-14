<section class="admin-panel panel" id="administration-panel-operational-calendar" data-office-panel-group="administration" data-office-panel-id="operational-calendar" role="tabpanel" aria-labelledby="administration-tab-operational-calendar" hidden>
    <div class="admin-panel__heading">
        <div><h2>Turnos operativos</h2><p>Configura la jornada de planta para los filtros y registros operativos.</p></div>
        <span id="operationalCalendarCurrent" role="status"></span>
    </div>
    <form class="admin-form" id="operationalCalendarForm" hidden>
        <div class="admin-form__grid">
            <label class="field"><span>Primera jornada con este horario</span><input name="vigente_desde" type="date" required></label>
            <label class="field"><span>Inicio de jornada y del turno A</span><input name="inicio_jornada" type="time" required></label>
            <label class="field"><span>Inicio del turno B</span><input name="inicio_turno_b" type="time" required></label>
        </div>
        <p class="admin-form__hint" id="operationalCalendarPreview"></p>
        <p class="form-error" id="operationalCalendarError" role="alert"></p>
        <div class="admin-form__actions"><button class="primary-button" type="submit" disabled>Guardar horario</button></div>
    </form>
    <div class="office-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Vigente desde</th><th>Turno A</th><th>Turno B</th><th>Versión</th></tr></thead>
            <tbody id="operationalCalendarHistory"></tbody>
        </table>
    </div>
</section>
