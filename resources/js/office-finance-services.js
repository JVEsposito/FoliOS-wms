import { serviceEventText, serviceRules, serviceValidity } from './shared/finance-services-logic';
import { mountRuleEditor } from './shared/finance-rule-editor';

/** Catálogo dentro del tarifario; comparte sesión y permisos con la página. */
export function mountServiceCatalog({ api, data, canManage, reload, openPrice, escapeHtml: esc, toast, formatDateTime }) {
    const byId = (id) => document.getElementById(id);
    const rows = byId('serviceRows');
    const dialog = byId('serviceDialog');
    const form = byId('serviceForm');
    const error = byId('serviceFormError');
    const endDialog = byId('serviceEndDialog');
    const endForm = byId('serviceEndForm');
    const endError = byId('serviceEndError');
    const history = byId('serviceHistory');
    const ruleRoot = byId('serviceRule');
    const ruleEditor = mountRuleEditor({ root: ruleRoot, processes: () => data()?.procesos, escapeHtml: esc, api });
    let ending = null;
    const getService = (key) => data()?.servicios.find((service) => service.clave === key);
    const message = (response) => Object.values(response.data?.errors || {}).flat()[0] || response.data?.message || 'No fue posible guardar el concepto.';

    function render() {
        const services = data()?.servicios || [];
        byId('tariffServicesCount').textContent = String(services.length);
        byId('serviceNewButton').hidden = !canManage();
        rows.innerHTML = services.map((service) => {
            const expired = service.vigente_hasta && service.vigente_hasta < data().hoy;
            const actions = (canManage() ? `<button class="fin-boton" type="button" data-service-price="${esc(service.clave)}">${expired ? 'Asignar precio histórico' : 'Asignar precio'}</button>` : '')
                + (!service.base ? `<button class="fin-boton" type="button" data-service-history="${esc(service.clave)}">Historial</button>` : '')
                + (!service.base && canManage() && (!service.vigente_hasta || service.vigente_hasta > data().hoy)
                    ? `<button class="fin-boton" type="button" data-service-end="${esc(service.clave)}">Finalizar</button>` : '');
            return `<tr class="${expired ? 'is-annulled' : ''}">`
                + `<td><strong>${esc(service.nombre)}</strong>${service.area ? `<small>Área: ${esc(service.area)}</small>` : ''}${service.descripcion ? `<small>${esc(service.descripcion)}</small>` : ''}</td>`
                + `<td>${esc(service.fuente_nombre)}<small>${esc(serviceRules(service))}</small></td>`
                + `<td>${esc(serviceValidity(service))}${expired ? '<small>Finalizado</small>' : ''}</td>`
                + `<td><div class="fin-acciones">${actions || '—'}</div></td></tr>`;
        }).join('');
    }

    function syncProcess() {
        const process = data()?.procesos.find((item) => item.clave === form.elements.fuente.value);
        const declared = process?.clave === 'declarado';
        const calculated = process?.clave === 'calculado';
        byId('serviceManualFields').hidden = !(declared || calculated);
        ruleRoot.hidden = !calculated;
        form.elements.unidad_libre.disabled = !(declared || calculated);
        form.elements.unidad_libre.required = declared || calculated;
        form.elements.proceso_referencia.disabled = !declared;
        form.elements.aplicacion.innerHTML = calculated ? '<option value="calculado">Un cobro con la cantidad calculada</option>' : declared ? '<option value="declarado">Cargo por acuerdo o declaración</option>' : '<option value="">Seleccione</option><option value="reemplaza_base">Reemplaza al concepto base</option><option value="adicional">Suma un cobro adicional</option>';
        byId('serviceDays').hidden = !process?.intervalo;
        for (const field of ['dia_desde', 'dia_hasta']) form.elements[field].disabled = !process?.intervalo;
        if (!process?.intervalo) {
            form.elements.dia_desde.value = '1';
            form.elements.dia_hasta.value = '';
        }
        byId('serviceProcessHelp').textContent = process
            ? `${process.descripcion} Cantidades disponibles: ${Object.values(process.medidas).map((measure) => measure.nombre).join(', ')}.` : '';
    }

    function open() {
        if (!canManage()) return;
        form.reset();
        error.textContent = '';
        form.elements.fuente.innerHTML = (data()?.procesos || []).map((process) => `<option value="${esc(process.clave)}">${esc(process.nombre)}</option>`).join('');
        form.elements.vigente_desde.value = data().hoy;
        ruleEditor.reset();
        syncProcess();
        dialog.showModal();
        form.elements.nombre.focus();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        const body = Object.fromEntries(new FormData(form));
        Object.assign(body, {
            dia_desde: Number(body.dia_desde || 1), dia_hasta: body.dia_hasta ? Number(body.dia_hasta) : null,
            vigente_hasta: body.vigente_hasta || null, descripcion: body.descripcion?.trim() || null,
        });
        try { if (body.fuente === 'calculado') body.regla = ruleEditor.read(); }
        catch (problem) { error.textContent = problem.message; return; }
        button.disabled = true;
        error.textContent = '';
        try {
            const response = await api('/api/finanzas/servicios', { method: 'POST', body: JSON.stringify(body) });
            if (!response.ok) { error.textContent = message(response); return; }
            dialog.close();
            toast('Concepto creado. Ahora puede asignarle un precio.');
            await reload({ quiet: true });
            openPrice(null, response.data.data.clave);
        } finally { button.disabled = false; }
    });

    byId('serviceRulePreviewButton').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const output = ruleRoot.querySelector('[data-rule-preview]');
        button.disabled = true;
        output.textContent = 'Calculando con los hechos capturados…';
        try {
            const response = await api('/api/finanzas/servicios/simular', { method: 'POST', body: JSON.stringify({ regla: ruleEditor.read(), vigente_desde: form.elements.vigente_desde.value, vigente_hasta: form.elements.vigente_hasta.value || null }) });
            if (!response.ok) { output.textContent = message(response); return; }
            const result = response.data.data;
            output.innerHTML = `<p>${esc(result.grupos)} grupos · ${esc(result.incompletos)} incompletos. ${esc(result.aviso)}</p>`
                + result.muestra.map((group) => `<details><summary>${esc(group.cliente_nombre || group.cliente_id || 'Cliente sin vincular')} · ${esc(group.fecha)} · ${esc(group.cantidad)} ${esc(result.unidad)} · ${group.completo ? 'Completo' : 'Pendiente'}</summary><p>${esc(Object.entries(group.dimensiones).map(([key, value]) => `${key}: ${value}`).join(' · '))}</p><ul>${group.aportes.map((input) => `<li>${esc(input.fuente)} · ${esc(input.valor)} × ${esc(input.factor)} = ${esc(input.cantidad)} · hecho ${esc(input.hecho_id)}, versión ${esc(input.version)}</li>`).join('')}</ul></details>`).join('');
        } catch (problem) { output.textContent = problem.message; }
        finally { button.disabled = false; }
    });

    endForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!ending) return;
        const button = endForm.querySelector('[type="submit"]');
        button.disabled = true;
        endError.textContent = '';
        try {
            const response = await api(`/api/finanzas/servicios/${encodeURIComponent(ending.clave)}/finalizar`, {
                method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(endForm))),
            });
            if (!response.ok) { endError.textContent = message(response); return; }
            endDialog.close();
            toast('Término del concepto registrado.');
            await reload({ quiet: true });
        } finally { button.disabled = false; }
    });

    async function showHistory(service) {
        byId('serviceHistoryTitle').textContent = service.nombre;
        const list = byId('serviceHistoryList');
        list.textContent = 'Cargando historial…';
        history.showModal();
        const response = await api(`/api/finanzas/servicios/${encodeURIComponent(service.clave)}/historial`);
        if (!response.ok) { list.textContent = message(response); return; }
        list.innerHTML = response.data.data.map((event) => `<li><strong>${esc(event.accion === 'creado' ? 'Creado' : 'Finalizado')}</strong>`
            + `<p>${esc(serviceEventText({ ...event, datos: { ...service, ...event.datos } }))}</p>`
            + (event.motivo ? `<p>${esc(event.motivo)}</p>` : '')
            + `<small>${esc(event.usuario || 'Sistema')} · ${esc(formatDateTime(event.fecha))}</small></li>`).join('');
    }

    rows.addEventListener('click', (event) => {
        const price = event.target.closest('[data-service-price]');
        const end = event.target.closest('[data-service-end]');
        const view = event.target.closest('[data-service-history]');
        if (price && canManage()) openPrice(null, price.dataset.servicePrice);
        if (view && getService(view.dataset.serviceHistory)) showHistory(getService(view.dataset.serviceHistory));
        if (end && canManage() && getService(end.dataset.serviceEnd)) {
            ending = getService(end.dataset.serviceEnd);
            endForm.reset();
            endError.textContent = '';
            byId('serviceEndTitle').textContent = ending.nombre;
            const min = [data().hoy, ending.vigente_desde].sort().at(-1);
            endForm.elements.vigente_hasta.min = min;
            endForm.elements.vigente_hasta.value = min;
            endForm.elements.vigente_hasta.removeAttribute('max');
            endDialog.showModal();
        }
    });
    byId('serviceNewButton').addEventListener('click', open);
    byId('tariffFormCreateService').addEventListener('click', () => { byId('tariffDialog').close(); open(); });
    form.elements.fuente.addEventListener('change', syncProcess);
    for (const modal of [dialog, endDialog, history]) {
        modal.addEventListener('click', (event) => {
            if (event.target.closest('[data-service-close]')) modal.close();
        });
    }

    return { render };
}
