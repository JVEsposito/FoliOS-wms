// Reasignar un movimiento de envases (una guía de ingreso o de salida) a otro productor CSG de la misma cuenta,
// completo o por cantidades. No crea asientos: solo cambia a qué productor se atribuye en el estado de cuenta y el Excel.

const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const number = (value) => new Intl.NumberFormat('es-CL').format(value);
const WITHOUT_PRODUCER = 'sin_csg';

function producerKey(producer) {
    return producer.codigo ? `csg:${producer.codigo}` : WITHOUT_PRODUCER;
}

function producerText(producer) {
    return producer.codigo ? `CSG ${producer.codigo}${producer.nombre ? ` · ${producer.nombre}` : ''}` : 'Sin productor declarado';
}

function quantitiesText(lines) {
    return lines.map((line) => `${number(line.cantidad)} ${line.nombre.toLowerCase()}`).join(', ');
}

function dateText(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

export function createContainerProducerReassignment({ api, operationId, toast, afterSaved }) {
    const el = (id) => document.getElementById(id);
    const dialog = el('producerReassignDialog');
    const form = el('producerReassignForm');
    const fields = form.elements;
    const state = { documents: [], selected: null, producers: [], attempt: null, busy: false, movement: null };

    const documentSelected = () => state.documents.find((item) => item.clave === state.selected) || state.documents[0] || null;
    const origin = () => documentSelected()?.productores.find((producer) => producerKey(producer) === fields.productor_origen.value) || null;
    const destination = () => state.producers.find((producer) => producer.id === fields.productor_csg_id.value) || null;
    const partial = () => fields.modalidad.value === 'parcial';

    /** Envases que se reasignan: todo lo del productor actual o lo indicado en cada cantidad. */
    function selection() {
        const current = origin();
        if (!current) return [];
        if (!partial()) return current.envases;
        return current.envases.map((line) => ({ ...line, cantidad: Number(form.querySelector(`[data-quantity="${line.tipo_envase}"]`)?.value || 0) }))
            .filter((line) => line.cantidad > 0);
    }

    function renderChoices() {
        const box = el('producerReassignChoices');
        box.hidden = state.documents.length < 2;
        box.innerHTML = state.documents.length < 2 ? '' : '<legend>Guía del movimiento</legend>' + state.documents.map((item) => `
            <label class="reassign-dialog__choice"><input type="radio" name="documento_clave" value="${escapeHtml(item.clave)}"${item.clave === state.selected ? ' checked' : ''}>
            <span><b>${escapeHtml(item.titulo)}</b><small>${item.productores.map((producer) => `${escapeHtml(producerText(producer))}: ${escapeHtml(quantitiesText(producer.envases))}`).join(' · ')}</small></span></label>`).join('');
    }

    function renderDocument() {
        const item = documentSelected();
        if (!item) return;
        el('producerReassignSummary').innerHTML = `<strong>${escapeHtml(item.titulo)}</strong>
            <span>${escapeHtml(item.movimiento)}${item.fecha ? ` · ${escapeHtml(dateText(item.fecha))}` : ''}${item.referencia ? ` · ${escapeHtml(item.referencia)}` : ''}</span>`;
        const previous = fields.productor_origen.value;
        fields.productor_origen.innerHTML = item.productores.map((producer) => `<option value="${escapeHtml(producerKey(producer))}">${escapeHtml(producerText(producer))}</option>`).join('');
        fields.productor_origen.value = item.productores.some((producer) => producerKey(producer) === previous) ? previous : producerKey(item.productores[0]);
        el('producerReassignBlocked').hidden = item.disponible;
        el('producerReassignBlocked').textContent = item.motivo_no_disponible || '';
        el('producerReassignHistory').hidden = !item.historial.length;
        el('producerReassignHistoryList').innerHTML = item.historial.map((entry) => `<li><b>${entry.modalidad === 'completa' ? 'Completa' : 'Parcial'} · ${escapeHtml(quantitiesText(entry.envases))}</b>
            <span>${escapeHtml(producerText(entry.origen))} → ${escapeHtml(producerText(entry.destino))}</span>
            <small>${escapeHtml(dateText(entry.registrado_at))}${entry.usuario ? ` · ${escapeHtml(entry.usuario)}` : ''} · ${escapeHtml(entry.motivo)}</small></li>`).join('');
        renderOrigin();
    }

    function renderOrigin() {
        const current = origin();
        el('producerReassignLines').innerHTML = current
            ? current.envases.map((line) => `<li><b>${number(line.cantidad)}</b> ${escapeHtml(line.nombre)} <small>${documentSelected()?.lado === 'entregados' ? 'entregados' : 'recibidos'}</small></li>`).join('')
            : '';
        el('producerReassignQuantities').innerHTML = current ? current.envases.map((line) => `
            <label><span>${escapeHtml(line.nombre)} <small>de ${number(line.cantidad)}</small></span>
            <input type="number" inputmode="numeric" min="0" max="${line.cantidad}" step="1" value="0" data-quantity="${escapeHtml(line.tipo_envase)}"></label>`).join('') : '';
        renderDestinations();
    }

    function renderDestinations() {
        const previous = fields.productor_csg_id.value;
        const current = origin();
        fields.productor_csg_id.innerHTML = '<option value="">Seleccionar CSG</option>' + state.producers.map((producer) => {
            const same = current?.codigo && String(current.codigo) === String(producer.codigo);
            return `<option value="${escapeHtml(producer.id)}"${same ? ' disabled' : ''}>CSG ${escapeHtml(producer.codigo)}${same ? ' (productor actual)' : ''}</option>`;
        }).join('');
        fields.productor_csg_id.value = state.producers.some((producer) => producer.id === previous && !(current?.codigo && String(current.codigo) === String(producer.codigo))) ? previous : '';
        updateControls();
    }

    function updateControls() {
        const item = documentSelected();
        const blocked = !item?.disponible;
        const target = destination();
        fields.razon_social.value = target?.nombre || '';
        el('producerReassignQuantities').hidden = !partial();
        const moving = selection();
        const current = origin();
        const quantitiesOk = !partial() || (moving.length > 0 && current?.envases.every((line) => {
            const input = form.querySelector(`[data-quantity="${line.tipo_envase}"]`);
            const value = Number(input?.value || 0);
            return Number.isInteger(value) && value >= 0 && value <= line.cantidad;
        }));
        const reasonOk = fields.motivo.value.trim().length >= 10;
        [fields.productor_origen, fields.productor_csg_id, ...form.querySelectorAll('[name="modalidad"], [data-quantity]')].forEach((control) => { control.disabled = state.busy || blocked; });
        renderDestinationsDisabled(current);
        el('producerReassignSubmit').disabled = state.busy || blocked || !target || !current || !quantitiesOk || !reasonOk;
        // Lo que no se reasigna sigue con el productor actual.
        const remains = partial() && current?.envases.some((line) => (moving.find((item) => item.tipo_envase === line.tipo_envase)?.cantidad ?? 0) < line.cantidad);
        el('producerReassignPreview').textContent = item && current && target && moving.length && quantitiesOk
            ? `${item.titulo}: ${quantitiesText(moving)} pasan de ${producerText(current)} a CSG ${target.codigo} · ${target.nombre}.`
                + (remains ? ` El resto sigue en ${producerText(current)}.` : '') + ' El saldo de la cuenta no cambia.'
            : (partial() && !quantitiesOk ? 'Indica cuántos envases pasan al otro productor, sin superar lo que tiene el productor actual.' : 'Elige el CSG del productor correcto.');
    }

    function renderDestinationsDisabled(current) {
        [...fields.productor_csg_id.options].forEach((option) => {
            const producer = state.producers.find((candidate) => candidate.id === option.value);
            if (producer) option.disabled = Boolean(current?.codigo && String(current.codigo) === String(producer.codigo));
        });
    }

    async function loadProducers(clientId) {
        state.producers = [];
        fields.productor_csg_id.innerHTML = '<option value="">Cargando productores…</option>';
        try {
            state.producers = (await api(`/api/envases/devoluciones/productores/${encodeURIComponent(clientId)}`)).data;
            if (!state.producers.length) el('producerReassignError').textContent = 'La cuenta no tiene productores CSG declarados. Asócialos antes de reasignar.';
        } catch (error) {
            el('producerReassignError').textContent = error.message;
        }
        renderDestinations();
    }

    form.addEventListener('change', (event) => {
        if (event.target.name === 'documento_clave') {
            state.selected = event.target.value;
            renderDocument();
        } else if (event.target === fields.productor_origen) {
            renderOrigin();
        } else {
            updateControls();
        }
    });
    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-quantity], textarea')) updateControls();
    });
    el('producerReassignCancel').addEventListener('click', () => { if (!state.busy) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (state.busy) event.preventDefault(); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const item = documentSelected();
        const current = origin();
        const target = destination();
        if (!item || !current || !target || state.busy || el('producerReassignSubmit').disabled) return;
        const body = {
            documento_clave: item.clave, productor_origen_codigo: current.codigo || null, productor_csg_id: target.id,
            razon_social: target.nombre, modalidad: partial() ? 'parcial' : 'completa',
            envases: partial() ? selection().map((line) => ({ tipo_envase: line.tipo_envase, cantidad: line.cantidad })) : [],
            motivo: fields.motivo.value.trim(),
        };
        // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
        const payload = JSON.stringify(body);
        state.attempt = state.attempt?.payload === payload ? state.attempt : { payload, operacion_id: operationId() };
        state.busy = true;
        el('producerReassignError').textContent = '';
        updateControls();
        try {
            await api('/api/envases/cuenta-corriente/reasignaciones-productor', { method: 'POST', body: JSON.stringify({ ...body, operacion_id: state.attempt.operacion_id }) });
            state.attempt = null;
            dialog.close();
            toast(`${item.titulo}: ${quantitiesText(selection())} reasignados a CSG ${target.codigo}. El saldo de la cuenta no cambia.`);
            await afterSaved();
        } catch (error) {
            el('producerReassignError').textContent = error.message;
        } finally {
            state.busy = false;
            updateControls();
        }
    });

    return {
        async open(movement) {
            form.reset();
            state.documents = [];
            state.selected = null;
            state.attempt = null;
            state.movement = movement;
            el('producerReassignError').textContent = '';
            el('producerReassignSummary').innerHTML = `<strong>${escapeHtml(movement.numero_documento)}</strong><span>Cargando el movimiento…</span>`;
            el('producerReassignLines').innerHTML = '';
            el('producerReassignQuantities').innerHTML = '';
            el('producerReassignChoices').hidden = true;
            el('producerReassignBlocked').hidden = true;
            el('producerReassignHistory').hidden = true;
            fields.productor_origen.innerHTML = '';
            fields.productor_csg_id.innerHTML = '<option value="">Seleccionar CSG</option>';
            el('producerReassignSubmit').disabled = true;
            el('producerReassignPreview').textContent = '';
            dialog.showModal();
            try {
                [state.documents] = await Promise.all([
                    api(`/api/envases/cuenta-corriente/movimientos/${encodeURIComponent(movement.id)}/reasignacion-productor`).then((response) => response.data),
                    loadProducers(movement.cliente.id),
                ]);
            } catch (error) {
                el('producerReassignSummary').innerHTML = `<strong>${escapeHtml(movement.numero_documento)}</strong>`;
                el('producerReassignError').textContent = error.message;
                return;
            }
            // En un camión con varias guías, parte en la del movimiento si se reconoce por su número.
            state.selected = (state.documents.find((item) => item.numero === movement.numero_documento) || state.documents[0]).clave;
            renderChoices();
            renderDocument();
        },
    };
}
