<section class="panel sc-panel is-hidden" data-section="herramientas">
    <header class="sc-heading">
        <h2>Preparación de inspecciones y consultas</h2>
        <a class="secondary-button" href="/oficina/frigorifico/diagramas-sag">Muestreo y adhesivos</a>
    </header>
    <nav class="sc-actions" aria-label="Herramientas SAG">
        <button class="secondary-button" type="button" data-sag-tool="lote" aria-controls="scToolLote" aria-pressed="true">Descripción del lote</button>
        <button class="secondary-button" type="button" data-sag-tool="mexico" aria-controls="scToolMexico" aria-pressed="false">México · SdP</button>
        <button class="secondary-button" type="button" data-sag-tool="china" aria-controls="scToolChina" aria-pressed="false">China · Cerezas</button>
    </nav>
    <article class="sc-tool" id="scToolLote" data-sag-tool-panel="lote">
        <form id="scLotUpload" class="sc-actions">
            <label>Archivo plano SAG<input name="archivo" type="file" accept=".ins" required></label>
            <button class="primary-button" type="submit">Leer .INS</button>
        </form>
        <p id="scLotStatus" role="status"></p>
        <form id="scLotPdf" class="sc-tool" hidden>
            <div class="sc-grid">
                <label>Número de lote<input name="lote" maxlength="60" required></label>
                <label>Especie<input name="especie" maxlength="60" required></label>
                <label>Muestreo<select name="muestreo" required><option value="">Selecciona</option><option value="N">Normal</option><option value="PE">Por etapa</option></select></label>
                <label>Selección<select name="seleccion" required><option value="">Selecciona</option><option value="T">Tradicional</option><option value="M">Manual</option><option value="A">Automática</option></select></label>
                <label>Despacho<select name="despacho" required><option value="">Selecciona</option><option value="I">Inmediato</option><option value="P">Pendiente</option><option value="D">Distancia</option></select></label>
            </div>
            <p>Completa los certificados y sus cajas para los pallets que correspondan.</p>
            <div class="sc-scroll sc-tool-table" tabindex="0" aria-label="Pallets y certificados">
                <table><thead><tr><th>Folio</th><th>Cajas</th><th>Acumulado</th><th>Certificado de premuestreo</th><th>Cajas</th><th>Certificado de inspección</th><th>Cajas</th></tr></thead><tbody id="scLotPallets"></tbody></table>
            </div>
            <div class="sc-actions"><button class="primary-button" type="submit">Descargar planilla PDF</button></div>
            <p id="scLotPdfStatus" role="status"></p>
        </form>
    </article>
    <article class="sc-tool" id="scToolMexico" data-sag-tool-panel="mexico" hidden>
        <p>Sube un XLSX o CSV con las columnas SdP, Fecha de embalaje y Cajas. Agrega Especie y Variedad para cruzarlas con el registro.</p>
        <form id="scMexicoForm" class="sc-actions">
            <label>Packing list<input name="archivo" type="file" accept=".xlsx,.csv,.txt" required></label>
            <button class="primary-button" type="submit">Validar SdP</button>
            <a href="https://sispmex.sag.gob.cl/pubsber/reporteSDP.asp" target="_blank" rel="noopener noreferrer">Registro SAG de México</a>
        </form>
        <div class="sc-actions"><p id="scMexicoStatus" role="status"></p><button class="secondary-button" type="button" id="scMexicoResume" hidden>Reanudar consulta</button></div>
        <section class="sc-tool" id="scMexicoResult" hidden>
            <div class="sc-heading"><h3>Cajas por fecha de embalaje</h3><button class="secondary-button" type="button" id="scMexicoPdf">Descargar reporte PDF</button></div>
            <div class="sc-scroll sc-tool-table" tabindex="0" aria-label="Totales por fecha"><table><thead><tr><th>Fecha</th><th>Cajas</th></tr></thead><tbody id="scMexicoDates"></tbody></table></div>
            <div class="sc-heading"><h3>Revisión por fila</h3><label><input type="checkbox" id="scMexicoOnlyIssues"> Solo con observaciones</label></div>
            <div class="sc-scroll sc-tool-table" tabindex="0" aria-label="Resultados SdP"><table><thead><tr><th>Fila</th><th>Folio</th><th>SdP</th><th>Fecha</th><th>Cajas</th><th>Resultado</th><th>Clase SAG</th><th>Observaciones</th></tr></thead><tbody id="scMexicoRows"></tbody></table></div>
        </section>
    </article>
    <article class="sc-tool" id="scToolChina" data-sag-tool-panel="china" hidden>
        <p>Pega los códigos de huertos y de establecimientos para consultar el listado de cerezas publicado por el SAG.</p>
        <form id="scChinaForm" class="sc-tool">
            <div class="sc-grid">
                <label>CSG · Huertos<textarea name="csg" rows="4" maxlength="3500" placeholder="Un código por línea, o separados por comas"></textarea></label>
                <label>CSP · Establecimientos<textarea name="csp" rows="4" maxlength="3500" placeholder="Un código por línea, o separados por comas"></textarea></label>
            </div>
            <div class="sc-actions">
                <button class="primary-button" type="submit">Consultar registros</button>
                <a href="https://www.sag.gob.cl/content/listado-de-participantes-inscritos-para-exportacion-de-cerezas-china" target="_blank" rel="noopener noreferrer">Registro SAG de cerezas</a>
                <a href="https://www.sag.gob.cl/content/listados-de-participantes-para-exportacion-de-cerezas-china" target="_blank" rel="noopener noreferrer">Ver plantas autorizadas</a>
            </div>
        </form>
        <p id="scChinaStatus" role="status"></p>
        <div class="sc-scroll sc-tool-table" id="scChinaResult" tabindex="0" aria-label="Inscripción de huertos y establecimientos" hidden>
            <table><thead><tr><th>Tipo</th><th>Código</th><th>Inscripción SAG</th><th>Región y comuna</th></tr></thead><tbody id="scChinaRows"></tbody></table>
        </div>
    </article>
</section>
