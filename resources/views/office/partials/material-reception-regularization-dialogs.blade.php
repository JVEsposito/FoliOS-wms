<dialog class="materials-import material-reception-dialog" id="materialReceptionAuthorizationDialog">
    <form class="material-reception-form" id="materialReceptionAuthorizationForm">
        <header class="materials-import__header"><div><p class="eyebrow">CONTROL DE INGRESO</p><h2>Autorizar recepción</h2></div></header>
        <div class="material-reception-form__body materials-form">
            <p id="materialReceptionAuthorizationSummary"></p>
            <label><span>Motivo de la autorización *</span><textarea name="motivo_autorizacion" required minlength="5" maxlength="1000" rows="3"></textarea></label>
            <label class="materials-check" data-late-ack><input name="aceptar_fuera_plazo" type="checkbox"><span>Autorizo expresamente fuera de los cinco días corridos. Quedará auditado.</span></label>
        </div>
        <footer class="material-reception-form__footer"><button type="button" class="secondary-button" data-close-registration>Cancelar</button><button type="submit" class="primary-button">Autorizar y confirmar</button></footer>
    </form>
</dialog>
<dialog class="materials-import material-reception-dialog" id="materialReceptionRegularizationDialog">
    <form class="material-reception-form" id="materialReceptionRegularizationForm">
        <header class="materials-import__header"><div><p class="eyebrow">RESPALDO DOCUMENTAL</p><h2>Regularizar recepción provisional</h2></div></header>
        <div class="material-reception-form__body materials-form">
            <p id="materialReceptionRegularizationInfo"></p>
            <p>Completa el respaldo en las unidades base indicadas. Las cantidades contadas, aceptadas y rechazadas, los bultos y los folios se conservan.</p>
            <div class="materials-form__grid">
                <label><span>Número de guía *</span><input name="numero_guia_despacho" required maxlength="50"></label>
                <label><span>Fecha de emisión *</span><input name="fecha_documento" type="date" required></label>
            </div>
            <div id="materialReceptionRegularizationLines" class="materials-form__grid"></div>
            <label><span>Motivo y antecedentes de regularización *</span><textarea name="motivo" required minlength="5" maxlength="1000" rows="3"></textarea></label>
            <label class="materials-check" data-late-ack><input name="aceptar_fuera_plazo" type="checkbox"><span>Regularizo expresamente fuera de los cinco días corridos. Quedará auditado.</span></label>
            <p id="materialReceptionRegularizationError" class="form-error" role="alert"></p>
        </div>
        <footer class="material-reception-form__footer"><button type="button" class="secondary-button" data-close-registration>Cancelar</button><button type="submit" class="primary-button">Guardar regularización</button></footer>
    </form>
</dialog>
