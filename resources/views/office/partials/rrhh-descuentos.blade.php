<dialog id="rdMandateDialog" class="rh-dialog"><form id="rdMandateForm" class="rh-form" data-descuentos-form>
    <div class="rh-heading"><h2>Mandato de descuento por institución</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Buscar concepto de descuento</span><input id="rdConceptSearch" type="search" maxlength="100"></label><label><span>Concepto y régimen legal</span><select name="concepto_id" required></select></label>
    <label><span>Empleador propio con contrato vigente en el mes consultado</span><select name="empleador_id" required></select></label>
    <label><span>Buscar institución</span><input type="search" data-rd-institution-search maxlength="100"></label><label><span>Institución destinataria</span><select name="institucion_id" required></select></label>
    <label><span>Referencia del crédito, autorización o causa judicial</span><input name="referencia" maxlength="100" required></label>
    <label><span>Desde</span><input name="desde" type="date" required></label><label><span>Hasta inclusive</span><input name="hasta" type="date" required></label>
    <label><span id="rdQuotaLabel">Cuota en CLP o porcentaje</span><input name="cuota" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" required></label>
    <label><span>Base del porcentaje</span><select name="base_porcentaje"><option value="remuneracion_bruta">Remuneración bruta</option><option value="liquido_legal">Remuneración líquida legal documentada</option><option value="base_imponible">Base imponible documentada</option></select></label>
    <label><span>Deuda total en CLP (opcional; requiere número de cuotas)</span><input name="deuda_total" inputmode="numeric" pattern="[0-9]+"></label><label><span>Número máximo de cuotas</span><input name="cuotas" type="number" min="1" max="1200"></label>
    <label><span>Pago directo a la institución</span><select name="pago_directo_institucion"><option value="0">No aplica</option><option value="1">Sí, respaldado según artículo 58</option></select></label>
    <label><span>Excepción especial CCAF</span><select name="excepcion_ccaf"><option value="">Sin excepción</option><option value="vivienda_emergencia">Emergencia de vivienda: catástrofe, FIBE y validación de la caja</option><option value="salud">Salud: indicación escrita, presupuesto y validación de la caja</option></select></label>
    <label><span>Autorización, norma u orden judicial y documentos revisados</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <p>El mandato no descuenta ni paga por sí solo. Prepare la cuota del mes y revise su novedad antes de aprobarla. El saldo por descontar se actualiza con cuotas aprobadas y sus anulaciones.</p>
    <div class="rh-actions"><button type="button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar mandato</button></div><p role="alert" class="form-error" data-rh-error></p>
</form></dialog>
<dialog id="rdQuotaDialog" class="rh-dialog"><form id="rdQuotaForm" class="rh-form" data-descuentos-form>
    <div class="rh-heading"><h2 id="rdQuotaTitle">Preparar cuota</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Fecha de devengo</span><input name="fecha" type="date" required></label><label><span>Remuneración bruta documentada del mes (para límites facultativos)</span><input name="remuneracion_bruta" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?"></label>
    <label><span>Remuneración líquida legal documentada (CCAF o base porcentual)</span><input name="liquido_legal" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?"></label><label><span>Base imponible (si la usa el porcentaje)</span><input name="base_imponible" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?"></label>
    <label><span>Respaldo del mes y de las bases informadas</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <button type="button" id="rdSimulateQuota">Calcular cuota</button><p id="rdQuotaPreview" role="status"></p>
    <div class="rh-actions"><button type="button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar borrador</button></div><p role="alert" class="form-error" data-rh-error></p>
</form></dialog>
<dialog id="rdAccountDialog" class="rh-dialog"><div class="rh-heading"><h2>Movimientos por institución</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div><form id="rdAccountForm" class="rh-toolbar"><label><span>Mes</span><input name="mes" type="month" required></label><button type="submit">Consultar</button></form><p id="rdAccountTotals"></p><p>Los importes aprobados son descuentos o aportes registrados. Su registro y el acuse del sistema receptor no acreditan pago a la institución.</p><div id="rdAccountRows" class="rh-table-scroll"></div><div id="rdAccountPages" class="rh-toolbar"></div></dialog>
