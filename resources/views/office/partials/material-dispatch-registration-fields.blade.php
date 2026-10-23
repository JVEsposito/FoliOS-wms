<fieldset class="materials-wide" data-dispatch-registration>
    <legend>Fecha física y respaldo de esta entrega</legend>
    <p>Completa solo si declaras otra fecha o un documento. Horas en {{ config('app.operational_timezone') }}. El registro del sistema conserva su hora real.</p>
    <label><span>Entrega física</span><input name="registro_fecha_despacho" type="datetime-local"></label>
    <label><span>Número de documento o vale</span><input name="registro_numero_documento" maxlength="50"></label>
    <label><span>Fecha de emisión del documento</span><input name="registro_fecha_documento" type="date"></label>
    <div data-dispatch-authorization>
        <label><input name="registro_provisional" type="checkbox"> Entrega provisional · respaldo pendiente, cinco días corridos</label>
        <label><span>Motivo del registro retroactivo o provisional</span><textarea name="registro_motivo" maxlength="1000" rows="2"></textarea></label>
        <label><span>Motivo de la autorización</span><textarea name="registro_motivo_autorizacion" maxlength="1000" rows="2"></textarea></label>
        <label><input name="registro_aceptar_fuera_plazo" type="checkbox"> Autorizo expresamente fuera de cinco días corridos; quedará auditado</label>
    </div>
</fieldset>
