<dialog class="review-dialog reconciliation-dialog" id="duplicateReconciliationDialog" aria-labelledby="duplicateReconciliationTitle">
    <div class="reconciliation-shell">
        <header class="review-dialog__header">
            <p class="eyebrow">CUENTA Y EXISTENCIA · ADMINISTRACIÓN</p>
            <h2 id="duplicateReconciliationTitle">Conciliar duplicados</h2>
            <p>Revisa una salida registrada en Romana y Despacho. La previsualización no modifica saldos.</p>
            <button class="secondary-button" data-reconciliation-guide type="button">Consultar guía de uso</button>
        </header>
        <section id="duplicateSearchSection" aria-label="Buscar posibles duplicados">
            <form id="duplicateSearchForm" class="reconciliation-search">
                <label><span>Cliente</span><select name="cliente_id"><option value="">Todos los clientes</option></select></label>
                <label><span>Guía de ingreso, salida o recepción</span><input name="buscar" maxlength="100" placeholder="Ej.: 11893 o 2236"></label>
                <button class="primary-button" type="submit">Buscar coincidencias</button>
            </form>
            <p class="stock-note" id="duplicateCandidatesNote" aria-live="polite"></p>
            <div class="reconciliation-candidates" id="duplicateCandidates"></div>
            <nav class="reconciliation-pagination" aria-label="Páginas de posibles duplicados">
                <button class="secondary-button" id="duplicatePrevious" type="button" disabled>Anterior</button>
                <span id="duplicatePage"></span>
                <button class="secondary-button" id="duplicateNext" type="button" disabled>Siguiente</button>
            </nav>
        </section>
        <section id="duplicateDetail" hidden aria-labelledby="duplicatePairTitle">
            <button class="secondary-button" id="duplicateChooseAnother" type="button">Elegir otros documentos</button>
            <div class="property-dialog__context"><strong id="duplicatePairTitle" tabindex="-1"></strong><p id="duplicatePairDocuments"></p></div>
            <form id="duplicatePreviewForm">
                <label><span>¿Qué cantidades comprobaste con el respaldo?</span><select name="conservar" required><option value="">Seleccionar origen de las cantidades</option><option value="romana">Conservar las cantidades de Romana</option><option value="despacho">Conservar las cantidades de Despacho</option></select></label>
                <button class="secondary-button" type="submit">Previsualizar conciliación</button>
            </form>
            <div id="duplicateComparison" hidden>
                <p class="stock-note">Diferencia = Despacho − Romana. La reposición elimina el efecto duplicado tanto en la cuenta como en la existencia. Los envases presentes en un solo registro se conservan.</p>
                <h3 id="duplicateComparisonTitle">Comparación de cantidades y salida que quedará registrada</h3>
                <p class="stock-note reconciliation-scroll-hint">Desliza la tabla hacia los lados para consultar todas las columnas, incluida la salida final.</p>
                <div class="reconciliation-table-scroll"><table aria-labelledby="duplicateComparisonTitle"><thead><tr><th>Envase</th><th>Propiedad</th><th>Romana</th><th>Despacho</th><th>Diferencia</th><th>Salida final</th><th>Reposición en cuenta y planta</th></tr></thead><tbody id="duplicateComparisonBody"></tbody></table></div>
            </div>
            <form id="duplicateApplyForm" hidden>
                <label><span>Motivo y respaldo revisado</span><textarea name="motivo" minlength="10" maxlength="2000" rows="3" required placeholder="Indica la guía o respaldo y por qué estas cantidades son las correctas"></textarea></label>
                <label class="reconciliation-confirmation"><input name="cantidades_verificadas" type="checkbox" required> Revisé los documentos y confirmo las cantidades de la salida final.</label>
                <button class="primary-button" type="submit" disabled>Confirmar conciliación</button>
            </form>
        </section>
        <div class="property-dialog__context" id="duplicateResult" role="status" hidden></div>
        <p class="form-error" id="duplicateError" role="alert"></p>
        <p class="stock-note" id="duplicateStatus" role="status"></p>
        <footer class="dialog-actions"><button class="secondary-button" id="duplicateClose" type="button">Volver a la cuenta</button></footer>
    </div>
</dialog>

<dialog class="review-dialog reconciliation-dialog" id="reconciliationGuideDialog" aria-labelledby="reconciliationGuideTitle">
    <div class="reconciliation-shell reconciliation-guide">
        <header class="review-dialog__header"><p class="eyebrow">GUÍA DE USO · ENVASES</p><h2 id="reconciliationGuideTitle">Conciliar una salida duplicada</h2><p>Para una misma salida física descontada en Romana y también en Despacho de envases.</p></header>
        <ol>
            <li><strong>Abre Materia prima → Cuenta Envases.</strong> Selecciona la temporada activa y, si corresponde, el cliente. El botón «Conciliar duplicados» está disponible para administradores autorizados.</li>
            <li><strong>Busca los documentos.</strong> Ingresa el número de guía de entrada, de salida o el correlativo de recepción. Las coincidencias corresponden al mismo cliente, temporada y guía de salida; selecciona la pareja exacta.</li>
            <li><strong>Comprueba las cantidades reales.</strong> Revisa el respaldo de la salida y elige si conservarás las cantidades de Romana o de Despacho. No se selecciona un origen automáticamente.</li>
            <li><strong>Previsualiza.</strong> Compara ambos registros, sus diferencias, la salida final y la reposición del efecto duplicado. Este paso no modifica cuenta ni existencia.</li>
            <li><strong>Indica el motivo y confirma.</strong> Describe el respaldo revisado, marca la confirmación de cantidades y pulsa «Confirmar conciliación». Solo entonces se registra la corrección.</li>
            <li><strong>Revisa el resultado.</strong> Se actualizan cuenta y existencia; el estado de cuenta muestra la salida neta. El kardex conserva los asientos originales, las compensaciones, el responsable y el motivo. Un reintento no genera otro abono.</li>
        </ol>
        <h3>Ejemplo: ingreso 11893 y salida 2236</h3>
        <p>Los 42 bins figuran en ambos registros. Romana tiene 1.002 totes y Despacho 1.009: verifica cuál cantidad salió realmente antes de elegir. Las 14 esponjas presentes solo en Despacho permanecen registradas con cualquiera de las opciones.</p>
        <h3>Si el caso no aparece o la previsualización lo bloquea</h3>
        <p>Comprueba cliente, temporada activa y guía de salida. La recepción debe estar cerrada y el despacho confirmado. Si existen correcciones previas, falta el origen físico o los datos son incompatibles, solicita revisión individual; no cambies el número de guía para forzar la conciliación.</p>
        <p>Si los documentos cambian después de previsualizar, vuelve a revisar la comparación. «Registrar apertura o ajuste» y «Corregir saldo anterior» modifican solo la cuenta: no sustituyen esta conciliación de cuenta y existencia.</p>
        <footer class="dialog-actions"><button class="secondary-button" id="reconciliationGuideClose" type="button">Cerrar guía</button></footer>
    </div>
</dialog>
