{{-- Corrección administrativa de una validación aprobada. Mismo orden que la PDA:
     bulto → origen comercial y composición por CSG → producto → categoría → motivo. --}}
<dialog class="vc-dialog" id="validationCorrectionDialog" aria-labelledby="validationCorrectionTitle">
    <form class="vc-form" id="validationCorrectionForm" novalidate>
        <header class="vc-heading">
            <div>
                <p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p>
                <h2 id="validationCorrectionTitle">Corregir validación</h2>
                <p class="vc-state" id="validationCorrectionState"></p>
            </div>
            <button class="vc-close" data-vc-close type="button" aria-label="Cerrar">×</button>
        </header>

        <div class="vc-body">
            <section class="vc-section" aria-labelledby="vcBulkTitle">
                <h3 id="vcBulkTitle"><span aria-hidden="true">1</span>Bulto</h3>
                <div class="vc-grid vc-grid--3">
                    <label class="vc-field"><span>Tipo de bulto</span><select name="tipo_bulto"><option value="pallet">Pallet completo</option><option value="saldo">Saldo</option></select></label>
                    <label class="vc-field"><span>Línea</span><select name="linea_proceso"><option value="1">Línea 1</option><option value="2">Línea 2</option><option value="3">Línea 3</option></select></label>
                    <label class="vc-field"><span>Turno</span><select name="turno"><option value="A">Turno A</option><option value="B">Turno B</option></select></label>
                </div>
            </section>

            <section class="vc-section" aria-labelledby="vcOriginTitle">
                <h3 id="vcOriginTitle"><span aria-hidden="true">2</span>Origen comercial</h3>
                <div class="vc-grid vc-grid--2">
                    <div class="vc-field"><span id="vcClientLabel">Cliente</span><div data-vc-picker="cliente"></div></div>
                    <div class="vc-field"><span id="vcBrandLabel">Marca</span><div data-vc-picker="marca"></div></div>
                </div>
                <div class="vc-lines-heading">
                    <div><strong>Composición por CSG</strong><small>Una línea por cada CSG, lote o proceso distinto. Las cajas del bulto son la suma.</small></div>
                    <output class="vc-total" id="vcTotal" aria-live="polite">0 cajas</output>
                </div>
                <div class="vc-lines" id="vcLines"></div>
                <button class="vc-add-line" id="vcAddLine" type="button">+ Agregar línea (otro CSG, lote o proceso)</button>
            </section>

            <section class="vc-section" aria-labelledby="vcProductTitle">
                <h3 id="vcProductTitle"><span aria-hidden="true">3</span>Producto</h3>
                <p class="vc-hint" id="vcProductHint"></p>
                <div class="vc-grid vc-grid--3">
                    <div class="vc-field"><span>Especie</span><div data-vc-picker="especie"></div></div>
                    <div class="vc-field"><span>Variedad</span><div data-vc-picker="variedad"></div></div>
                    <div class="vc-field"><span>Calibre</span><div data-vc-picker="calibre"></div></div>
                </div>
                <div class="vc-grid vc-grid--2">
                    <div class="vc-field"><span>Embalaje</span><div data-vc-picker="envase"></div></div>
                    <div class="vc-field"><span>Categoría</span><div data-vc-picker="categoria"></div></div>
                </div>
            </section>

            <section class="vc-section" aria-labelledby="vcReasonTitle">
                <h3 id="vcReasonTitle"><span aria-hidden="true">4</span>Motivo</h3>
                <label class="vc-field"><span class="office-visually-hidden">Motivo de la corrección</span><textarea name="motivo_correccion" rows="3" maxlength="1000" placeholder="Ej.: el pallet trae dos CSG; se ingresaron 129 cajas y son 120"></textarea></label>
            </section>

            <section class="vc-review" id="vcReview" aria-live="polite"></section>
            <p class="form-error" id="validationCorrectionError" role="alert"></p>
        </div>

        <footer class="vc-actions">
            <button class="secondary-button" data-vc-close type="button">Cancelar</button>
            <button class="primary-button" id="vcSubmit" type="submit">Guardar corrección</button>
        </footer>
    </form>
</dialog>
