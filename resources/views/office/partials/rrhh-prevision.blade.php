<section class="panel" data-rh-panel="prevision" hidden>
    <div class="rh-heading"><h2>Instituciones y empleadores</h2><button type="button" id="rpNew" class="primary-button" data-rn-manage>Crear registro</button></div>
    <p>Identifique las entidades existentes y sus códigos oficiales. Las afiliaciones y contratos conservan sus antecedentes históricos.</p>
    <form id="rpSearch" class="rh-toolbar"><label><span>Directorio</span><select name="directorio"><option value="institucion">Instituciones</option><option value="empleador">Empleadores propios, contratistas y EST</option></select></label><label><span>Nombre, RUT o código</span><input name="buscar" type="search" maxlength="100"></label><button type="submit">Buscar</button></form>
    <div id="rpDirectory" class="rh-table-scroll"></div><div id="rpPages" class="rh-toolbar"></div>
</section>
<dialog id="rpDirectoryDialog" class="rh-dialog"><form id="rpDirectoryForm" class="rh-form" data-prevision-form>
    <div class="rh-heading"><h2 id="rpDirectoryTitle">Registro institucional</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Código permanente</span><input name="codigo" pattern="[A-Z0-9_-]+" maxlength="40" required></label>
    <label><span>RUT</span><input name="rut" maxlength="15" required></label><label><span>Razón social</span><input name="razon_social" maxlength="160" required></label>
    <label><span>Tipo</span><select name="tipo" required></select></label>
    <label><span>Código oficial (opcional)</span><input name="codigo_oficial" maxlength="40"></label><label><span>Código de integración (opcional)</span><input name="codigo_externo" maxlength="100"></label>
    <label><span>Desde</span><input name="desde" type="date" required></label><label><span>Hasta inclusive</span><input name="hasta" type="date" value="9999-12-31" required></label>
    <label><span>Estado</span><select name="activo"><option value="1">Activo</option><option value="0">Inactivo para nuevos registros</option></select></label>
    <label><span>Fecha de verificación</span><input name="verificado_en" type="date" required></label><label><span>Fuente y respaldo de la verificación</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <p>RUT, código, tipo y vigencia son permanentes. La desactivación conserva la historia. Los códigos y datos reales se completan aquí.</p>
    <div class="rh-actions"><button type="button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar</button></div><p role="alert" class="form-error" data-rh-error></p>
</form></dialog>
<dialog id="rpContractDialog" class="rh-dialog"><form id="rpContractForm" class="rh-form" data-prevision-form>
    <div class="rh-heading"><h2>Contrato o vínculo de la persona</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div>
    <label><span>Buscar empleador por nombre o RUT</span><input type="search" data-rp-picker="empleador" maxlength="100"></label><label><span>Empleador real</span><select name="empleador_id" required></select></label>
    <label><span>Tipo</span><select name="tipo"><option value="indefinido">Indefinido</option><option value="plazo_fijo">Plazo fijo</option><option value="obra_faena">Obra o faena</option><option value="honorarios">Prestación a honorarios</option></select></label>
    <label><span>Referencia única del contrato o anexo</span><input name="referencia" maxlength="100" required></label>
    <label><span>Desde</span><input name="desde" type="date" required></label><label><span>Hasta inclusive (indefinido: 9999-12-31)</span><input name="hasta" type="date" value="9999-12-31" required></label>
    <label><span>Prórroga del contrato (opcional)</span><select name="prorroga_de"></select></label>
    <label><span>Referencia al contrato firmado y respaldo</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <p>Las condiciones salariales se registran en la ficha de remuneraciones. El personal de contratistas conserva su empleador y no se incorpora automáticamente a la nómina propia.</p>
    <div class="rh-actions"><button type="button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar contrato</button></div><p role="alert" class="form-error" data-rh-error></p>
</form></dialog>
<dialog id="rpAffiliationDialog" class="rh-dialog"><form id="rpAffiliationForm" class="rh-form" data-prevision-form>
    <div class="rh-heading"><h2>Afiliación con vigencia</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div>
    <p id="rpOwner"></p><label><span>Régimen</span><select name="regimen" required></select></label>
    <label><span>Buscar institución por nombre o RUT</span><input type="search" data-rp-picker="institucion" maxlength="100"></label><label><span>Institución</span><select name="institucion_id" required></select></label>
    <label><span>Desde</span><input name="desde" type="date" required></label><label><span>Hasta inclusive</span><input name="hasta" type="date" value="9999-12-31" required></label>
    <label><span>Referencia de afiliación, FUN o contrato</span><input name="referencia" maxlength="100" required></label>
    <fieldset id="rpPlan" hidden><legend>Plan Isapre según FUN</legend><label><span>Moneda</span><select name="moneda_plan" disabled><option value="UF">UF</option><option value="CLP">CLP</option></select></label><label><span>Precio mensual pactado</span><input name="precio_plan" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" disabled></label></fieldset>
    <label><span>Documento y respaldo</span><textarea name="respaldo" minlength="10" maxlength="1000" required></textarea></label>
    <div class="rh-actions"><button type="button" data-rh-close>Cancelar</button><button type="submit" class="primary-button">Guardar afiliación</button></div><p role="alert" class="form-error" data-rh-error></p>
</form></dialog>
<dialog id="rpEmployerDialog" class="rh-dialog"><div class="rh-heading"><h2 id="rpEmployerTitle">Afiliaciones del empleador</h2><button type="button" data-rh-close aria-label="Cerrar">×</button></div><button type="button" id="rpNewEmployerAffiliation" data-rn-manage>Registrar CCAF o seguro de accidentes</button><div id="rpEmployerAffiliations" class="rh-table-scroll"></div><div id="rpEmployerPages" class="rh-toolbar"></div></dialog>
