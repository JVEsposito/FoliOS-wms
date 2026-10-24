<dialog id="rcCompanyDialog" class="rh-dialog">
    <div class="rh-heading"><h2 id="rcCompanyTitle">Cumplimiento del contratista</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <p>Expedientes por empresa, faena y mes. La revisión es interna: registrar una referencia documental no certifica su autenticidad ni acredita por sí solo el cumplimiento.</p>
    <form id="rcSearch" class="rh-toolbar" data-contratistas-form><label><span>Mes (vacío muestra todos)</span><input name="mes" type="month"></label><button type="submit" class="secondary-button">Consultar</button></form>
    <button type="button" data-rc-action="newFile" data-rn-manage class="primary-button">Abrir expediente</button>
    <div id="rcFiles" class="rh-table-scroll"></div><div id="rcFilePages" class="rh-toolbar"></div>
</dialog>
<dialog id="rcPersonalDialog" class="rh-dialog"><form id="rcPersonalForm" class="rh-form" data-contratistas-form>
    <div class="rh-heading"><h2 id="rcPersonalTitle">Abrir expediente</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Código de faena</span><input name="faena" maxlength="40" pattern="[A-Z0-9_\-]+" required></label>
    <label><span>Mes</span><input name="mes" type="month" required></label>
    <label><span>Referencia del contrato comercial</span><input name="referencia_contrato" maxlength="200" required></label>
    <label id="rcPersonalReason" hidden><span>Motivo del cambio de personal</span><textarea name="motivo" minlength="10" maxlength="1000" disabled></textarea></label>
    <p>Seleccione expresamente el personal de esta faena. Solo se ofrecen personas con contrato laboral en esta empresa durante el mes elegido.</p>
    <label><span>Buscar persona por nombre o código</span><input id="rcPersonSearch" type="search" maxlength="100"></label><button type="button" data-rc-action="findPeople" class="secondary-button">Buscar personal</button>
    <div id="rcPeople" class="rh-table-scroll"></div><div id="rcPeoplePages" class="rh-toolbar"></div><div id="rcSelected"></div>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar personal y expediente</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rcFileDialog" class="rh-dialog">
    <div class="rh-heading"><h2 id="rcFileTitle">Expediente</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <div id="rcSummary"></div><div id="rcMissing" role="status"></div><details><summary>Personal esperado en la faena</summary><div id="rcExpected"></div></details>
    <div class="rh-toolbar"><button type="button" data-rc-action="editPersonal" data-rn-manage>Cambiar personal</button><button type="button" data-rc-action="reviewExp" data-rn-manage>Revisar expediente</button><button type="button" data-rc-action="history">Historial del expediente</button></div>
    <h3>Antecedentes y cobertura</h3><p>F30 informa antecedentes generales. Para este control mensual, F30-1 se revisa junto con remuneraciones, comprobantes de pago y cotizaciones del personal esperado. Las EST requieren también su registro.</p>
    <button type="button" data-rc-action="evidence" data-rn-manage>Registrar antecedente</button><div id="rcEvidence" class="rh-table-scroll"></div><div id="rcEvidencePages" class="rh-toolbar"></div>
    <h3>Retenciones comerciales</h3><p>Auxiliar de anotaciones respaldadas. Registre retenciones, liberaciones y pagos por subrogación ya documentados; este registro no ejecuta transferencias ni contabiliza gastos.</p>
    <p id="rcBalance"></p><button type="button" data-rc-action="retention" data-rn-manage>Anotar movimiento</button><div id="rcRetentions" class="rh-table-scroll"></div><div id="rcRetentionPages" class="rh-toolbar"></div>
</dialog>
<dialog id="rcEvidenceDialog" class="rh-dialog"><form id="rcEvidenceForm" class="rh-form" data-contratistas-form>
    <div class="rh-heading"><h2>Registrar antecedente</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Tipo</span><select name="tipo"><option value="f301">F30-1</option><option value="f30">F30</option><option value="remuneraciones">Remuneraciones</option><option value="pago_remuneraciones">Pago de remuneraciones</option><option value="cotizaciones">Cotizaciones</option><option value="contratos">Contratos laborales</option><option value="registro_est">Registro de EST</option><option value="otro">Otro antecedente</option></select></label>
    <label><span>Cubre desde</span><input name="desde" type="date" required></label><label><span>Cubre hasta</span><input name="hasta" type="date" required></label>
    <label><span>Fecha del documento</span><input name="fecha_documento" type="date" required></label><label><span>Folio y ubicación privada del respaldo</span><textarea name="referencia" minlength="5" maxlength="500" required></textarea></label>
    <label><span>Cobertura declarada al registrar</span><select name="cubre_todos"><option value="0">Seleccionar personas cubiertas</option><option value="1">Todo el personal actual del expediente</option></select></label>
    <label id="rcCoverage"><span>Personas cubiertas (selección múltiple)</span><select name="personas" multiple size="6"></select></label>
    <p>Conserve el archivo en su repositorio documental privado e indique aquí cómo encontrarlo. El antecedente se guarda pendiente de revisión.</p>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Registrar pendiente</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rcReviewDialog" class="rh-dialog"><form id="rcReviewForm" class="rh-form" data-contratistas-form>
    <div class="rh-heading"><h2 id="rcReviewTitle">Revisión interna</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <div id="rcReviewContext"></div>
    <label><span>Resultado de la revisión</span><select name="estado"></select></label><label><span>Observación y respaldo de la revisión</span><textarea name="observacion" minlength="10" maxlength="1000" required></textarea></label>
    <p>Confirme cobertura, período, contenido y autenticidad en la fuente correspondiente. Un antecedente descartado conserva su historial y no acredita cobertura.</p>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar revisión</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
<dialog id="rcRetentionDialog" class="rh-dialog"><form id="rcRetentionForm" class="rh-form" data-contratistas-form>
    <div class="rh-heading"><h2>Anotación de retención comercial</h2><button type="button" class="secondary-button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Movimiento documentado</span><select name="tipo"><option value="retencion">Retención</option><option value="liberacion">Liberación</option><option value="pago_subrogacion">Pago por subrogación</option></select></label>
    <label><span>Monto CLP</span><input name="monto" inputmode="numeric" pattern="[0-9]{1,15}" required></label><label><span>Fecha del movimiento</span><input name="fecha" type="date" required></label>
    <label><span>Referencia del documento o comprobante</span><input name="referencia" minlength="5" maxlength="200" required></label><label><span>Fundamento y ubicación del respaldo</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <div id="rcDestination" class="rh-form" hidden><label><span>Buscar institución destinataria</span><input id="rcInstitutionSearch" type="search" maxlength="100"></label><button type="button" data-rc-action="findInstitution">Buscar institución</button>
        <label><span>Institución (elija institución o trabajador)</span><select name="institucion_id"></select></label><label><span>Trabajador destinatario</span><select name="persona_destino_id"></select></label>
    </div>
    <p>La anotación requiere respaldo de la operación y no envía instrucciones de pago.</p>
    <div class="rh-actions"><button type="button" class="secondary-button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar anotación</button></div><p class="form-error" role="alert" data-rh-error></p>
</form></dialog>
