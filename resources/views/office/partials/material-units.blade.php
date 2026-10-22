<section class="panel materials-panel" id="materials-catalog-panel-units" data-office-panel-group="materials-catalog" data-office-panel-id="units" role="tabpanel" aria-labelledby="materials-catalog-tab-units" hidden>
    <div class="materials-panel__heading"><h2>Unidades de medida</h2><button id="reloadMaterialUnits" class="secondary-button" type="button">Actualizar</button></div>
    <p class="materials-help">Los sinónimos identifican la misma unidad. Una equivalencia, en cambio, convierte cantidades de un ítem concreto. El inventario conserva su unidad base.</p>
    <form id="materialUnitForm" class="materials-form">
        <p class="materials-help">Los sinónimos existentes se conservan para reconocer documentos y cargas anteriores; puede agregar otros.</p>
        <input name="id" type="hidden"><input name="version_conocida" type="hidden" value="0">
        <div class="materials-form__grid">
            <label><span>Código permanente *</span><input name="codigo" maxlength="40" required></label>
            <label><span>Nombre *</span><input name="nombre" maxlength="100" required></label>
            <label><span>Sinónimos, separados por coma</span><input name="alias" placeholder="unidad, un, u"></label>
            <label><span>Motivo *</span><input name="motivo" minlength="5" maxlength="500" required></label>
            <label class="materials-check"><input name="activa" type="checkbox" checked><span>Disponible para nuevos ítems</span></label>
        </div>
        <div class="materials-actions"><button id="newMaterialUnit" class="secondary-button" type="button">Nueva unidad</button><button class="primary-button" type="submit">Guardar unidad</button></div>
    </form>
    <div id="materialUnitsList" class="materials-list"></div>
    <h3>Equivalencias por ítem</h3>
    <p class="materials-help">Indique cuántas unidades base contiene una unidad de ingreso. Por ejemplo: 1 caja = 100 unidades. Se usará al registrar recepciones; las recetas conservan sus propios factores y versiones.</p>
    <form id="materialUnitConversionForm" class="materials-form">
        <div class="materials-form__grid">
            <label><span>Ítem *</span><select id="materialConversionItem" required></select></label>
            <label><span>Unidad de ingreso *</span><select id="materialConversionUnit" required></select></label>
            <label><span id="materialConversionFactorLabel">Cantidad base por unidad de ingreso *</span><input name="factor" type="number" min="0.000001" max="99999999.999999" step="0.000001" required></label>
            <label><span>Motivo *</span><input name="motivo" minlength="5" maxlength="500" required></label>
            <label class="materials-check"><input name="activa" type="checkbox" checked><span>Equivalencia activa</span></label>
        </div>
        <button class="primary-button" type="submit">Guardar equivalencia</button>
    </form>
    <div id="materialConversionsList" class="materials-list"></div>
    <p id="materialUnitsStatus" class="form-error" role="status" aria-live="polite"></p>
    <details><summary>Historial de unidades y equivalencias</summary><div id="materialUnitsHistory" class="materials-list"></div></details>
</section>
