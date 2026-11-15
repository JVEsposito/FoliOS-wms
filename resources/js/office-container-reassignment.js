// Reasignar una guía completa de la cuenta de envases a otro productor CSG o a otra cuenta corriente.
// Solo mueve la cuenta: kilos, Validación MP, lotes, cantidades y existencia física no cambian.

const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const number = (value) => new Intl.NumberFormat('es-CL').format(value);

function producerText(producers) {
    if (!producers?.length) return 'sin productor';
    return producers.map((producer) => `CSG ${producer.codigo}${producer.nombre ? ` · ${producer.nombre}` : ''}`).join(' / ');
}

function placeText(place) {
    return `${place.cliente?.nombre || 'Cuenta'} · ${producerText(place.productores || (place.productor ? [place.productor] : []))}`;
}

function dateText(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

export function createContainerReassignment({ api, catalogs, operationId, toast, afterSaved }) {
    const el = (id) => document.getElementById(id);
    const dialog = el('reassignDialog');
    const form = el('reassignForm');
    const fields = form.elements;
    const state = { documents: [], selected: null, attempt: null, busy: false, producers: [] };

    function selected() {
        return state.documents.find((item) => item.clave === state.selected) || state.documents[0] || null;
    }

    function renderChoices() {
        const box = el('reassignChoices');
        box.hidden = state.documents.length < 2;
        box.innerHTML = state.documents.length < 2 ? '' : '<legend>Guía que se reasigna</legend>' + state.documents.map((item) => `
            <label class="reassign-dialog__choice"><input type="radio" name="documento_clave" value="${escapeHtml(item.clave)}"${item.clave === state.selected ? ' checked' : ''}>
            <span><b>${escapeHtml(item.titulo)}</b><small>${item.envases.map((line) => `${number(Math.abs(line.cantidad))} ${escapeHtml(line.nombre)}`).join(' · ')} · ${escapeHtml(placeText(item.actual))}</small></span></label>`).join('');
    }

    function renderDocument() {
        const item = selected();
        if (!item) return;
        el('reassignTitle').textContent = `Reasignar ${item.titulo.charAt(0).toLowerCase()}${item.titulo.slice(1)}`;
        const exit = item.lado === 'entregados';
        el('reassignSummary').innerHTML = `<strong>${escapeHtml(item.titulo)}</strong>
            <span>${escapeHtml(item.movimiento)}${item.recepcion ? ` · ${escapeHtml(item.recepcion)}` : ''}${item.despacho ? ` · ${escapeHtml(item.despacho)}` : ''}${item.fecha ? ` · ${escapeHtml(dateText(item.fecha))}` : ''}</span>`;
        el('reassignLines').innerHTML = item.envases.length
            ? item.envases.map((line) => `<li><b>${number(Math.abs(line.cantidad))}</b> ${escapeHtml(line.nombre)} <small>${escapeHtml(line.propiedad_nombre)} · ${exit ? 'entregados' : 'recibidos'}</small></li>`).join('')
            : '<li>Sin envases vigentes en la cuenta.</li>';
        el('reassignNow').textContent = placeText(item.actual);
        el('reassignOrigin').hidden = !item.reasignada;
        el('reassignOriginText').textContent = placeText(item.origen);
        el('reassignBlocked').hidden = item.disponible;
        el('reassignBlocked').textContent = item.motivo_no_disponible || '';
        el('reassignHistory').hidden = !item.historial.length;
        el('reassignHistoryList').innerHTML = item.historial.map((entry) => `<li><b>${entry.deshace ? 'Deshecha' : 'Reasignada'}${entry.vigente ? ' · vigente' : ''}</b>
            <span>${escapeHtml(placeText({ cliente: entry.origen.cliente, productor: entry.origen.productor }))} → ${escapeHtml(placeText({ cliente: entry.destino.cliente, productor: entry.destino.productor }))}</span>
            <small>${escapeHtml(dateText(entry.registrado_at))}${entry.usuario ? ` · ${escapeHtml(entry.usuario)}` : ''} · ${escapeHtml(entry.motivo)}</small></li>`).join('');
        el('reassignUndo').hidden = !item.reasignada;
        if (!fields.cliente_destino_id.value || ![...fields.cliente_destino_id.options].some((option) => option.value === fields.cliente_destino_id.value)) {
            fields.cliente_destino_id.value = item.actual.cliente.id;
        }
        updateControls();
    }

    function updatePreview() {
        const item = selected();
        const client = catalogs()?.clientes?.find((candidate) => candidate.id === fields.cliente_destino_id.value);
        const producer = state.producers.find((candidate) => candidate.id === fields.productor_csg_id.value);
        el('reassignPreview').textContent = item && client && producer
            ? `La guía ${item.numero} pasa de ${placeText(item.actual)} a ${client.nombre} · CSG ${producer.codigo} · ${producer.nombre}. Kilos, validación, lotes y cantidades no cambian.`
            : 'Elige la cuenta corriente y el productor CSG de destino.';
    }

    function updateControls() {
        const item = selected();
        const blocked = !item?.disponible;
        const reasonOk = fields.motivo.value.trim().length >= 10;
        [fields.cliente_destino_id, fields.productor_csg_id].forEach((control) => { control.disabled = state.busy || blocked; });
        el('reassignSubmit').disabled = state.busy || blocked || !fields.productor_csg_id.value || !reasonOk;
        el('reassignUndo').disabled = state.busy || !item?.reasignada || !reasonOk;
        updatePreview();
    }

    async function loadProducers() {
        const clientId = fields.cliente_destino_id.value;
        fields.productor_csg_id.innerHTML = '<option value="">Cargando productores…</option>';
        state.producers = [];
        if (!clientId) return;
        try {
            const producers = (await api(`/api/envases/devoluciones/productores/${encodeURIComponent(clientId)}`)).data;
            if (fields.cliente_destino_id.value !== clientId) return;
            state.producers = producers;
            fields.productor_csg_id.innerHTML = '<option value="">Seleccionar productor</option>' + producers
                .map((producer) => `<option value="${escapeHtml(producer.id)}">CSG ${escapeHtml(producer.codigo)} · ${escapeHtml(producer.nombre)}</option>`).join('');
            el('reassignError').textContent = producers.length ? '' : 'La cuenta no tiene productores CSG activos. Asócialos antes de reasignar.';
        } catch (error) {
            fields.productor_csg_id.innerHTML = '<option value="">Seleccionar productor</option>';
            el('reassignError').textContent = error.message;
        }
        updateControls();
    }

    async function send(path, body, message) {
        if (state.busy) return;
        // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
        const payload = JSON.stringify({ path, body });
        state.attempt = state.attempt?.payload === payload ? state.attempt : { payload, operacion_id: operationId() };
        state.busy = true;
        el('reassignError').textContent = '';
        updateControls();
        try {
            await api(path, { method: 'POST', body: JSON.stringify({ ...body, operacion_id: state.attempt.operacion_id }) });
            state.attempt = null;
            dialog.close();
            toast(message);
            await afterSaved();
        } catch (error) {
            el('reassignError').textContent = error.message;
        } finally {
            state.busy = false;
            updateControls();
        }
    }

    form.addEventListener('change', (event) => {
        if (event.target.name === 'documento_clave') {
            state.selected = event.target.value;
            renderDocument();
        } else if (event.target === fields.cliente_destino_id) {
            void loadProducers();
        } else {
            updateControls();
        }
    });
    fields.motivo.addEventListener('input', updateControls);
    el('reassignCancel').addEventListener('click', () => { if (!state.busy) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (state.busy) event.preventDefault(); });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const item = selected();
        if (!item || !form.reportValidity()) return;
        void send('/api/envases/cuenta-corriente/reasignaciones', {
            documento_clave: item.clave, reasignacion_vigente_id: item.reasignacion_vigente_id,
            cliente_destino_id: fields.cliente_destino_id.value, productor_csg_id: fields.productor_csg_id.value, motivo: fields.motivo.value.trim(),
        }, `Guía ${item.numero} reasignada. Kilos y cantidades sin cambios.`);
    });
    el('reassignUndo').addEventListener('click', () => {
        const item = selected();
        if (!item?.reasignacion_vigente_id || fields.motivo.value.trim().length < 10) return;
        void send(`/api/envases/cuenta-corriente/reasignaciones/${encodeURIComponent(item.reasignacion_vigente_id)}/deshacer`,
            { motivo: fields.motivo.value.trim() }, `Reasignación de la guía ${item.numero} deshecha: vuelve a su cuenta y productor.`);
    });

    return {
        async open(movement) {
            form.reset();
            state.documents = [];
            state.selected = null;
            state.attempt = null;
            el('reassignError').textContent = '';
            el('reassignTitle').textContent = 'Reasignar guía';
            el('reassignSummary').innerHTML = `<strong>${escapeHtml(movement.numero_documento)}</strong><span>Cargando la guía…</span>`;
            el('reassignLines').innerHTML = '';
            el('reassignChoices').hidden = true;
            fields.cliente_destino_id.innerHTML = '<option value="">Seleccionar cuenta corriente</option>' + (catalogs()?.clientes || [])
                .map((client) => `<option value="${escapeHtml(client.id)}">${escapeHtml(client.nombre)} · ${escapeHtml(client.codigo)}</option>`).join('');
            dialog.showModal();
            try {
                state.documents = (await api(`/api/envases/cuenta-corriente/movimientos/${encodeURIComponent(movement.id)}/reasignacion`)).data;
            } catch (error) {
                el('reassignError').textContent = error.message;
                return;
            }
            if (!state.documents.length) {
                el('reassignError').textContent = 'Este movimiento no pertenece a una guía que se pueda reasignar.';
                return;
            }
            // En un camión con varias guías, parte en la del movimiento elegido si se reconoce por su número.
            state.selected = (state.documents.find((item) => item.numero === movement.numero_documento) || state.documents[0]).clave;
            renderChoices();
            fields.cliente_destino_id.value = '';
            renderDocument();
            await loadProducers();
        },
    };
}
