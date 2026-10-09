const receptionImportState = {
    preview: null,
    previewFingerprint: null,
    requestSequence: 0,
};

function receptionImportEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function receptionImportToken() {
    return localStorage.getItem('estiba_wms_office_token');
}

function receptionImportSelectedFile(form) {
    return form.elements.archivo.files?.[0] || null;
}

function receptionImportFileFingerprint(file) {
    return file ? `${file.name}:${file.size}:${file.lastModified}` : null;
}

function receptionImportInvalidate(elements) {
    receptionImportState.preview = null;
    receptionImportState.previewFingerprint = null;
    receptionImportState.requestSequence += 1;
    elements.preview.classList.add('is-hidden');
    elements.apply.disabled = true;
}

function receptionImportError(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function receptionImportQuantity(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 })
        .format(Number(value || 0));
}

function receptionImportDownloadTemplate() {
    const content = [
        'codigo_item;cantidad_documental;cantidad_contada;cantidad_aceptada;cantidad_rechazada;unidades_por_bulto;lote_proveedor;fecha_fabricacion;fecha_vencimiento;bloqueado;motivo_bloqueo;observacion',
        'FILM-REC;500;500;500;0;60;LOTE-001;2026-08-01;2027-08-01;no;;Ejemplo de recepción',
    ].join('\r\n');
    const blob = new Blob([`\uFEFF${content}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'plantilla-productos-recepcion-materiales.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function receptionImportMarkup() {
    return `
        <dialog class="materials-import" id="materialReceptionImportDialog">
            <div class="materials-import__header">
                <div>
                    <p class="eyebrow">CARGA MASIVA</p>
                    <h2>Importar productos de la recepción</h2>
                    <p>La planilla se previsualiza y luego carga sus productos en el formulario abierto. Guarda el borrador, agrega la foto de la guía o factura y luego confirma.</p>
                </div>
                <button id="closeMaterialReceptionImport" type="button" aria-label="Cerrar">×</button>
            </div>
            <form class="materials-import__form" id="materialReceptionImportForm">
                <label><span>Planilla CSV o XLSX *</span><input name="archivo" type="file" accept=".csv,.txt,.xlsx" required></label>
                <label class="materials-check"><input name="reemplazar" type="checkbox" checked><span>Reemplazar los productos actualmente cargados en el formulario</span></label>
                <div class="materials-import__actions">
                    <button class="secondary-button" id="downloadMaterialReceptionImportTemplate" type="button">Descargar plantilla CSV</button>
                    <button class="primary-button" type="submit">Previsualizar productos</button>
                </div>
                <p class="materials-import__help">Obligatorio: código del ítem, cantidad aceptada y unidades por bulto. Si omites cantidad contada se calcula como aceptada + rechazada; si omites cantidad documental se usa la cantidad contada. Máximo 100 productos y 500 bultos por producto.</p>
                <p class="form-error" id="materialReceptionImportError" role="alert"></p>
            </form>
            <section class="materials-import__preview is-hidden" id="materialReceptionImportPreview">
                <div class="materials-import__metrics">
                    <article><span>FILAS LEÍDAS</span><strong id="materialReceptionImportRead">0</strong></article>
                    <article><span>VÁLIDAS</span><strong id="materialReceptionImportValid">0</strong></article>
                    <article><span>CON ERROR</span><strong id="materialReceptionImportInvalid">0</strong></article>
                    <article><span>FOLIOS ESTIMADOS</span><strong id="materialReceptionImportFolios">0</strong></article>
                </div>
                <div class="materials-import__errors is-hidden" id="materialReceptionImportErrors"></div>
                <div class="materials-table-scroll">
                    <table class="materials-table">
                        <thead><tr><th>Fila</th><th>Ítem</th><th>Documental</th><th>Aceptada</th><th>Bultos</th><th>Lote</th></tr></thead>
                        <tbody id="materialReceptionImportRows"></tbody>
                    </table>
                </div>
                <div class="materials-import__confirm">
                    <p id="materialReceptionImportStatus">Revisa la previsualización antes de cargar.</p>
                    <button class="primary-button" id="applyMaterialReceptionImport" type="button" disabled>Cargar productos al formulario</button>
                </div>
            </section>
        </dialog>
    `;
}

function receptionImportRender(elements, data) {
    receptionImportState.preview = data;
    const summary = data?.resumen || {};
    const rows = data?.filas || [];
    const errors = data?.errores || [];

    elements.preview.classList.remove('is-hidden');
    elements.read.textContent = summary.filas_leidas || 0;
    elements.valid.textContent = summary.filas_validas || 0;
    elements.invalid.textContent = summary.filas_con_error || 0;
    elements.folios.textContent = summary.folios_estimados || 0;
    elements.errors.classList.toggle('is-hidden', errors.length === 0);
    elements.errors.innerHTML = errors.map((error) =>
        `<p><strong>Fila ${receptionImportEscape(error.fila)}${error.codigo_item ? ` · ${receptionImportEscape(error.codigo_item)}` : ''}:</strong> ${receptionImportEscape(error.mensaje)}</p>`).join('');
    elements.rows.innerHTML = rows.map((row) => `
        <tr>
            <td>${receptionImportEscape(row.fila)}</td>
            <td><strong>${receptionImportEscape(row.item?.codigo)}</strong><small>${receptionImportEscape(row.item?.nombre)}</small></td>
            <td>${receptionImportEscape(receptionImportQuantity(row.cantidad_documental))}</td>
            <td>${receptionImportEscape(receptionImportQuantity(row.cantidad_aceptada))}</td>
            <td>${receptionImportEscape(row.bultos?.length || 0)}</td>
            <td>${receptionImportEscape(row.bultos?.[0]?.lote_proveedor || 'Sin lote')}</td>
        </tr>
    `).join('') || '<tr><td colspan="6"><p class="empty-state">No existen productos válidos para cargar.</p></td></tr>';
    elements.apply.disabled = errors.length > 0 || rows.length === 0;
    elements.status.textContent = errors.length > 0
        ? 'Corrige todas las filas con error y vuelve a previsualizar. No se permite una carga parcial.'
        : `${rows.length} productos listos para cargar en el formulario.`;
}

async function receptionImportPreview(elements) {
    const contextApi = window.estibaMaterialReceptionImportContext;
    const context = contextApi?.context();
    if (!context?.clienteId || !context?.proveedorId) {
        throw new Error('Selecciona primero el cliente y el proveedor de la recepción.');
    }

    const file = receptionImportSelectedFile(elements.form);
    if (!file) {
        throw new Error('Selecciona una planilla CSV o XLSX.');
    }
    const fingerprint = receptionImportFileFingerprint(file);
    receptionImportInvalidate(elements);
    const requestSequence = ++receptionImportState.requestSequence;
    const formData = new FormData(elements.form);
    formData.set('cliente_id', context.clienteId);
    formData.set('proveedor_material_id', context.proveedorId);
    const response = await fetch('/api/materiales/recepciones/importaciones/previsualizar', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${receptionImportToken()}`,
        },
        body: formData,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(receptionImportError(payload, 'No fue posible leer la planilla.'));
    }
    if (requestSequence !== receptionImportState.requestSequence
        || fingerprint !== receptionImportFileFingerprint(receptionImportSelectedFile(elements.form))) {
        return;
    }

    receptionImportState.previewFingerprint = fingerprint;
    receptionImportRender(elements, payload.data || {});
}

function receptionImportBoot() {
    const heading = document.querySelector('.material-reception-lines-heading');
    const addButton = document.getElementById('addMaterialReceptionLine');
    if (!heading || !addButton || document.getElementById('openMaterialReceptionImport')) return;

    const openButton = document.createElement('button');
    openButton.className = 'secondary-button';
    openButton.id = 'openMaterialReceptionImport';
    openButton.type = 'button';
    openButton.textContent = '⇩ Importar Excel';
    heading.insertBefore(openButton, addButton);
    document.body.insertAdjacentHTML('beforeend', receptionImportMarkup());

    const elements = {
        open: openButton,
        dialog: document.getElementById('materialReceptionImportDialog'),
        close: document.getElementById('closeMaterialReceptionImport'),
        form: document.getElementById('materialReceptionImportForm'),
        error: document.getElementById('materialReceptionImportError'),
        preview: document.getElementById('materialReceptionImportPreview'),
        errors: document.getElementById('materialReceptionImportErrors'),
        rows: document.getElementById('materialReceptionImportRows'),
        read: document.getElementById('materialReceptionImportRead'),
        valid: document.getElementById('materialReceptionImportValid'),
        invalid: document.getElementById('materialReceptionImportInvalid'),
        folios: document.getElementById('materialReceptionImportFolios'),
        status: document.getElementById('materialReceptionImportStatus'),
        apply: document.getElementById('applyMaterialReceptionImport'),
        template: document.getElementById('downloadMaterialReceptionImportTemplate'),
    };

    window.estibaMaterialReceptionImporter = {
        setVisible(visible) {
            elements.open.classList.toggle('is-hidden', !visible);
        },
    };

    elements.open.addEventListener('click', () => {
        const context = window.estibaMaterialReceptionImportContext?.context();
        elements.error.textContent = '';
        if (!context?.clienteId || !context?.proveedorId) {
            const formError = document.getElementById('materialReceptionFormError');
            if (formError) formError.textContent = 'Selecciona cliente y proveedor antes de importar productos.';
            return;
        }
        elements.form.reset();
        elements.form.elements.reemplazar.checked = true;
        receptionImportInvalidate(elements);
        elements.dialog.showModal();
    });
    elements.close.addEventListener('click', () => elements.dialog.close());
    elements.template.addEventListener('click', receptionImportDownloadTemplate);
    elements.form.elements.archivo.addEventListener('change', () => {
        receptionImportInvalidate(elements);
        elements.error.textContent = '';
    });
    elements.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        elements.error.textContent = '';
        try {
            await receptionImportPreview(elements);
        } catch (error) {
            elements.error.textContent = error.message;
        }
    });
    elements.apply.addEventListener('click', () => {
        elements.error.textContent = '';
        try {
            const fingerprint = receptionImportFileFingerprint(receptionImportSelectedFile(elements.form));
            if (!receptionImportState.preview
                || !fingerprint
                || receptionImportState.previewFingerprint !== fingerprint) {
                throw new Error('La planilla seleccionada cambió; vuelve a previsualizarla antes de cargar.');
            }
            const rows = receptionImportState.preview.filas || [];
            const replace = elements.form.elements.reemplazar.checked;
            const count = window.estibaMaterialReceptionImportContext?.apply(rows, replace) || 0;
            elements.dialog.close();
            const formError = document.getElementById('materialReceptionFormError');
            if (formError) formError.textContent = '';
            document.getElementById('materialReceptionLines')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const help = document.getElementById('materialReceptionDialogHelp');
            if (help) help.textContent = `${count} productos cargados desde la planilla. Revisa los datos antes de guardar.`;
        } catch (error) {
            elements.error.textContent = error.message;
        }
    });
}

queueMicrotask(receptionImportBoot);
