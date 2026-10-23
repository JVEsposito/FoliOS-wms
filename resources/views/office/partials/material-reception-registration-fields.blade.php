<fieldset class="materials-form__grid" id="materialReceptionRegistrationFields">
    <legend>Ingreso físico y respaldo documental</legend>
    <label><span>Fecha y hora de recepción física ({{ config('app.operational_timezone') }})</span><input name="fecha_recepcion" type="datetime-local"></label>
    <label class="materials-check"><input name="provisional" type="checkbox"><span>Recepción provisional: operar y completar el respaldo después</span></label>
    <label class="materials-wide"><span>Motivo del registro retroactivo o provisional</span><textarea name="motivo_registro_tardio" minlength="5" maxlength="1000" rows="2"></textarea></label>
    <p class="materials-help materials-wide">Cinco días corridos desde el día de recepción física. Un ingreso retroactivo o provisional requiere autorización para confirmar. En una provisional pueden quedar pendientes la guía, la foto y la cantidad documental; el conteo físico y los bultos son obligatorios. La regularización conserva los folios y el inventario.</p>
</fieldset>
