const labelElements = {
    workspace: document.getElementById('materialLabelWorkspace'),
    form: document.getElementById('materialLabelForm'),
    error: document.getElementById('materialLabelError'),
    summary: document.getElementById('materialLabelSummary'),
    source: document.getElementById('materialLabelSource'),
    reception: document.getElementById('materialLabelReception'),
    profile: document.getElementById('materialLabelProfile'),
    design: document.getElementById('materialLabelDesign'),
    designHelp: document.getElementById('materialLabelDesignHelp'),
    folios: document.getElementById('materialLabelFolios'),
    selectAll: document.getElementById('selectAllMaterialLabels'),
    history: document.getElementById('materialLabelHistory'),
    reload: document.getElementById('reloadMaterialLabels'),
    status: document.getElementById('materialLabelStatus'),
};
const labelState = {
    receptions: [],
    orders: [],
    profiles: [],
    selected: null,
    source: 'recepcion',
    history: [],
    loading: false,
    selectionRequest: 0,
    operation: null,
    submitting: false,
};

function labelToken() {
    return localStorage.getItem('estiba_wms_office_token');
}

function labelIdentity() {
    try {
        return JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
    } catch {
        return null;
    }
}

function labelSectionIsActive() {
    return document.getElementById('officeApp')?.dataset.materialsSection === 'recepcion';
}

function labelCanReadTransformations() {
    const identity = labelIdentity();
    return (identity?.puede_consultar_transformaciones_materiales
        ?? identity?.capacidades?.puede_consultar_transformaciones_materiales) === true;
}

function labelEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function labelUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function labelApi(path) {
    const response = await fetch(path, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${labelToken()}`,
        },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(data?.errors || {}).flat()[0] || data?.message || 'No fue posible consultar las etiquetas.');
    }
    return data;
}

function receiptFolios(receipt) {
    return (receipt?.detalles || []).flatMap((detail) => (detail.bultos || [])
        .filter((packageItem) => packageItem.folio)
        .map((packageItem) => ({
            ...packageItem.folio,
            cantidad: packageItem.cantidad,
            lote_proveedor: packageItem.lote_proveedor,
            item: detail.item,
            unidad_medida: detail.unidad_medida,
            bloqueado: packageItem.bloqueado,
        })));
}

function transformationFolios(order) {
    // Folio reservado del que proviene cada salida: cuando la cantidad real difiere de la
    // teórica ya impresa, la etiqueta se debe reimprimir.
    const reservations = order?.folios_salida || [];
    const reservationByFolio = new Map(reservations.filter((reservation) => reservation.folio_id)
        .map((reservation) => [reservation.folio_id, reservation]));
    const outputItem = order?.version_receta?.receta?.item_salida || null;
    const produced = (order?.lotes || []).filter((lot) => lot.estado === 'cerrado')
        .flatMap((lot) => (lot.salidas || []).map((output) => {
            const reservation = reservationByFolio.get(output.folio_id);
            return {
                kind: 'folio',
                id: output.folio_id,
                numero_folio: output.numero_folio,
                cantidad: output.cantidad_producida,
                lote_proveedor: String(reservation?.secuencia ?? lot.numero_lote),
                item: output.item,
                unidad_medida: output.item?.unidad_medida,
                bloqueado: false,
                requiere_reimpresion: reservation?.requiere_reimpresion === true,
                impreso_anticipado: (reservation?.impresiones || 0) > 0,
            };
        }));
    const reserved = reservations.filter((reservation) => reservation.estado === 'reservado')
        .map((reservation) => ({
            kind: 'reserva',
            id: reservation.id,
            numero_folio: reservation.numero_folio,
            cantidad: reservation.cantidad_teorica,
            lote_proveedor: String(reservation.secuencia),
            item: outputItem,
            unidad_medida: outputItem?.unidad_medida,
            bloqueado: false,
            impresiones: reservation.impresiones || 0,
        }));

    return [...reserved, ...produced];
}

function selectedFolios() {
    return labelState.source === 'transformacion'
        ? transformationFolios(labelState.selected)
        : receiptFolios(labelState.selected);
}

function renderSourceOptions() {
    updateLabelDesign();
    const placeholder = labelState.source === 'transformacion'
        ? 'Seleccionar orden de transformación'
        : 'Seleccionar recepción';
    const records = labelState.source === 'transformacion'
        ? labelState.orders.map((order) => ({
            id: order.id,
            label: `OT-${order.id.slice(0, 8).toUpperCase()} · ${order.cliente?.codigo || '—'} · ${order.version_receta?.receta?.nombre || 'Transformación'}`,
        }))
        : labelState.receptions.map((receipt) => ({
            id: receipt.id,
            label: `${receipt.numero_guia_despacho} · ${receipt.cliente?.codigo || '—'} · ${new Date(receipt.confirmado_at).toLocaleDateString('es-CL')}`,
        }));
    labelElements.reception.innerHTML = `<option value="">${placeholder}</option>`
        + records.map((record) => `
            <option value="${record.id}">${labelEscape(record.label)}</option>
        `).join('');
}

function renderProfileOptions() {
    labelElements.profile.innerHTML = labelState.profiles.map((profile) => `
        <option value="${profile.id}"${profile.predeterminado ? ' selected' : ''}>${labelEscape(profile.nombre)} · ${labelEscape(profile.fabricante)} · ${labelEscape(profile.ancho_mm)}×${labelEscape(profile.alto_mm)} mm</option>
    `).join('') || '<option value="">No existen perfiles activos</option>';
    updateLabelDesign();
}

function updateLabelDesign() {
    if (!labelElements.design) return;
    const profile = labelState.profiles.find(item => item.id === labelElements.profile.value);
    const dimensions = [Number(profile?.ancho_mm), Number(profile?.alto_mm)].sort((a, b) => a - b);
    const compatible = labelState.source === 'recepcion'
        && profile?.disenos_disponibles?.includes('clasica_bodega') === true
        && Math.abs(dimensions[0] - 100) < 0.01 && Math.abs(dimensions[1] - 200) < 0.01;
    labelElements.design.querySelector('[value="clasica_bodega"]').disabled = !compatible;
    if (!compatible) labelElements.design.value = 'actual';
    const symbology = labelElements.form.elements.simbologia;
    const classic = labelElements.design.value === 'clasica_bodega';
    const requiresCode128 = classic || labelElements.form.elements.formato.value === 'pdf';
    symbology.querySelector('[value="qr"]').disabled = requiresCode128;
    if (requiresCode128) symbology.value = 'code128';
    labelElements.designHelp.textContent = labelElements.design.value === 'clasica_bodega'
        ? '200 × 100 mm · cliente dueño, fecha real de recepción y cantidad recibida por bulto. Conserva el folio escaneable. PDF y NiceLabel muestran la cara apaisada; revisa la orientación en las propiedades de impresión.'
        : compatible ? 'Puedes elegir Clásica bodega para destacar la cantidad recibida de cada bulto.'
            : 'Clásica bodega está disponible para recepciones con un perfil de 200 × 100 mm. El diseño Actual conserva el modelo de cada origen.';
}

function labelFolioStatus(folio, generated) {
    if (folio.kind === 'reserva') {
        return folio.impresiones > 0 ? ' · reservado · reimpresión' : ' · reservado';
    }
    if (folio.requiere_reimpresion) return ' · reimprimir: cambió la cantidad';
    return generated.has(folio.id) || folio.impreso_anticipado ? ' · reimpresión' : '';
}

function renderFolios() {
    const folios = selectedFolios();
    const generated = new Set(labelState.history.flatMap((job) => job.folios.map((folio) => folio.id).filter(Boolean)));
    const reference = labelState.source === 'transformacion'
        ? `OT-${labelState.selected?.id?.slice(0, 8).toUpperCase() || ''}`
        : labelState.selected?.numero_guia_despacho;
    const reservedCount = folios.filter((folio) => folio.kind === 'reserva').length;
    labelElements.summary.textContent = labelState.selected
        ? `${reference} · ${folios.length} folios disponibles${reservedCount ? ` · ${reservedCount} reservados con cantidad teórica` : ''}`
        : 'Selecciona una recepción confirmada u orden con folios reservados o lotes cerrados';
    labelElements.folios.innerHTML = folios.map((folio) => `
        <label class="material-label-folio${folio.kind === 'reserva' ? ' is-reserved' : ''}${folio.requiere_reimpresion ? ' needs-reprint' : ''}">
            <input name="folio_ids[]" type="checkbox" value="${labelEscape(folio.id)}" data-label-kind="${folio.kind || 'folio'}">
            <span><strong>${labelEscape(folio.numero_folio)}${labelEscape(labelFolioStatus(folio, generated))}</strong><small>${labelEscape(folio.item?.codigo)} · ${labelEscape(folio.item?.nombre)}</small><small>${labelEscape(folio.cantidad)} ${labelEscape(folio.unidad_medida)}${folio.kind === 'reserva' ? ' teórica' : ''} · lote ${labelEscape(folio.lote_proveedor || '—')}${folio.bloqueado ? ' · BLOQUEADO' : ''}</small></span>
        </label>
    `).join('') || '<p class="empty-state">El origen seleccionado no posee folios etiquetables.</p>';
    labelElements.selectAll.checked = false;
    labelElements.form.querySelector('button[type="submit"]').disabled = !labelState.selected || folios.length === 0;
}

function renderHistory() {
    labelElements.history.innerHTML = labelState.history.map((job) => `
        <article class="material-row">
            <div><strong>${labelEscape(job.formato.toUpperCase())} · ${labelEscape((job.simbologia || 'code128').toUpperCase())} · ${job.folios.length} folios × ${job.copias} copias</strong><small>${new Date(job.solicitado_at).toLocaleString('es-CL')} · ${labelEscape(job.solicitado_por)} · ${labelEscape(job.perfil?.nombre)} · ${job.diseno === 'clasica_bodega' ? 'Clásica bodega' : 'Actual'}</small>${job.motivo_reimpresion ? `<small>Motivo: ${labelEscape(job.motivo_reimpresion)}</small>` : ''}</div>
            <span class="material-import-action">${labelEscape(job.estado)}</span>
        </article>
    `).join('') || '<p class="empty-state">Aún no se han generado etiquetas para este origen.</p>';
}

async function selectSourceRecord(id) {
    const request = ++labelState.selectionRequest;
    const source = labelState.source;
    const token = labelToken();
    labelState.selected = null;
    labelState.history = [];
    renderFolios();
    renderHistory();
    if (!id) return;
    if (source === 'transformacion' && !labelCanReadTransformations()) {
        throw new Error('Tu perfil no permite consultar órdenes de transformación.');
    }
    const prefix = source === 'transformacion'
        ? '/api/materiales/transformaciones/ordenes'
        : '/api/materiales/recepciones';
    const [record, history] = await Promise.all([
        labelApi(`${prefix}/${encodeURIComponent(id)}`),
        labelApi(`${prefix}/${encodeURIComponent(id)}/impresiones`),
    ]);
    if (request !== labelState.selectionRequest || source !== labelState.source || token !== labelToken()) return;
    if (source === 'recepcion' && record.data?.estado !== 'confirmada') {
        throw new Error('Solo se pueden imprimir folios de una recepción confirmada.');
    }
    labelState.selected = record.data;
    labelState.history = history.data || [];
    // El acceso desde Recepciones puede apuntar a un documento fuera de la primera página.
    const records = source === 'transformacion' ? labelState.orders : labelState.receptions;
    if (!records.some(item => item.id === record.data.id)) {
        records.unshift(record.data);
        renderSourceOptions();
    }
    labelElements.reception.value = id;
    renderFolios();
    renderHistory();
}

async function loadLabels() {
    if (labelState.submitting) return;
    const identity = labelIdentity();
    const enabled = labelSectionIsActive() && (identity?.puede_imprimir_etiquetas_materiales
        ?? identity?.capacidades?.puede_imprimir_etiquetas_materiales) === true;
    labelElements.workspace.classList.toggle('is-hidden', !enabled);
    if (!enabled || !labelToken() || labelState.loading) return;
    labelState.selectionRequest++;
    labelState.loading = true;
    labelElements.error.textContent = '';
    try {
        const canReadTransformations = labelCanReadTransformations();
        const [receptions, orders, profiles] = await Promise.all([
            labelApi('/api/materiales/recepciones?estado=confirmada&per_page=100'),
            canReadTransformations ? labelApi('/api/materiales/transformaciones/ordenes?per_page=100') : Promise.resolve({ data: [] }),
            labelApi('/api/materiales/recepciones/perfiles-impresion'),
        ]);
        labelState.receptions = receptions.data || [];
        labelState.orders = (orders.data || []).filter((order) => order.tiene_salidas === true
            || (order.folios_salida_resumen?.reservados || 0) > 0);
        labelState.profiles = profiles.data || [];
        labelElements.source.querySelector('option[value="transformacion"]').disabled = !canReadTransformations;
        if (!canReadTransformations) {
            labelState.source = 'recepcion';
            labelElements.source.value = 'recepcion';
        }
        renderSourceOptions();
        renderProfileOptions();
        labelState.selected = null;
        labelState.history = [];
        renderFolios();
        renderHistory();

        const parameters = new URLSearchParams(window.location.search);
        const requestedSource = parameters.get('origen');
        const requestedId = parameters.get('id');
        if (['recepcion', 'transformacion'].includes(requestedSource) && requestedId) {
            if (requestedSource === 'transformacion' && !canReadTransformations) {
                throw new Error('Tu perfil no permite consultar órdenes de transformación.');
            }
            labelState.source = requestedSource;
            labelElements.source.value = requestedSource;
            renderSourceOptions();
            await selectSourceRecord(requestedId);
        }
    } catch (error) {
        labelElements.error.textContent = error.message;
    } finally {
        labelState.loading = false;
    }
}

labelElements.reception?.addEventListener('change', async (event) => {
    labelElements.error.textContent = '';
    try {
        await selectSourceRecord(event.target.value);
    } catch (error) {
        labelElements.error.textContent = error.message;
    }
});

labelElements.source?.addEventListener('change', (event) => {
    labelState.selectionRequest++;
    labelState.source = event.target.value;
    updateLabelDesign();
    labelState.selected = null;
    labelState.history = [];
    renderSourceOptions();
    renderFolios();
    renderHistory();
});

labelElements.profile?.addEventListener('change', updateLabelDesign);
labelElements.design?.addEventListener('change', updateLabelDesign);

labelElements.selectAll?.addEventListener('change', (event) => {
    labelElements.folios.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
        checkbox.checked = event.target.checked;
    });
});

labelElements.form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (labelState.submitting) return;
    labelElements.error.textContent = '';
    labelElements.status.textContent = '';
    const form = new FormData(labelElements.form);
    const checked = [...labelElements.folios.querySelectorAll('input[type="checkbox"]:checked')];
    const folioIds = checked.filter((checkbox) => checkbox.dataset.labelKind !== 'reserva')
        .map((checkbox) => checkbox.value);
    const reservationIds = checked.filter((checkbox) => checkbox.dataset.labelKind === 'reserva')
        .map((checkbox) => checkbox.value);
    if (!labelState.selected || checked.length === 0) {
        labelElements.error.textContent = 'Selecciona un origen y al menos un folio.';
        return;
    }
    if (!labelElements.form.reportValidity()) return;
    const payload = {
        perfil_id: form.get('perfil_id'),
        formato: form.get('formato'),
        simbologia: form.get('simbologia'),
        canal: 'oficina_descarga',
        ...(folioIds.length ? { folio_ids: folioIds.sort() } : {}),
        ...(reservationIds.length ? { reserva_ids: reservationIds.sort() } : {}),
        copias: Number(form.get('copias')),
        motivo_reimpresion: String(form.get('motivo_reimpresion') || '').trim() || null,
        ...(form.get('diseno') === 'clasica_bodega' ? { diseno: 'clasica_bodega' } : {}),
    };
    const source = labelState.source;
    const id = labelState.selected.id;
    const token = labelToken();
    const fingerprint = JSON.stringify({ source, id, ...payload });
    if (labelState.operation?.fingerprint !== fingerprint) {
        labelState.operation = { fingerprint, id: labelUuid() };
    }
    payload.operacion_id = labelState.operation.id;
    labelState.submitting = true;
    const controls = [...labelElements.form.elements, labelElements.reload].map(input => [input, input.disabled]);
    controls.forEach(([input]) => { input.disabled = true; });
    labelElements.form.setAttribute('aria-busy', 'true');
    try {
        const prefix = source === 'transformacion'
            ? '/api/materiales/transformaciones/ordenes'
            : '/api/materiales/recepciones';
        const response = await fetch(`${prefix}/${encodeURIComponent(id)}/etiquetas`, {
            method: 'POST',
            headers: {
                Accept: payload.formato === 'pdf'
                    ? 'application/pdf'
                    : 'application/octet-stream',
                Authorization: `Bearer ${labelToken()}`,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(Object.values(data?.errors || {}).flat()[0] || data?.message || 'No fue posible generar las etiquetas.');
        }
        const blob = await response.blob();
        if (token !== labelToken()) return;
        const disposition = response.headers.get('Content-Disposition') || '';
        const filename = disposition.match(/filename="([^"]+)"/)?.[1] || `etiquetas.${payload.formato}`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        labelState.operation = null;
        labelElements.status.textContent = `Archivo generado: ${checked.length} folios × ${payload.copias} copias. La impresión física se realiza desde el archivo descargado.`;
        labelElements.form.elements.motivo_reimpresion.value = '';
        try {
            await selectSourceRecord(id);
        } catch {
            labelElements.error.textContent = 'El archivo fue generado. No se pudo actualizar el historial; pulsa Actualizar recepciones para consultarlo.';
        }
    } catch (error) {
        labelElements.error.textContent = error.message;
    } finally {
        labelState.submitting = false;
        controls.forEach(([input, disabled]) => { input.disabled = disabled; });
        labelElements.form.querySelector('button[type="submit"]').disabled = !labelState.selected || selectedFolios().length === 0;
        labelElements.form.removeAttribute('aria-busy');
    }
});

labelElements.reload?.addEventListener('click', () => void loadLabels());
labelElements.form?.elements.formato?.addEventListener('change', updateLabelDesign);
window.addEventListener('estiba:office-session', (event) => {
    labelState.selectionRequest++;
    labelState.operation = null;
    if (event.detail?.authenticated) void loadLabels();
    else labelElements.workspace.classList.add('is-hidden');
});

if (labelElements.form) void loadLabels();
