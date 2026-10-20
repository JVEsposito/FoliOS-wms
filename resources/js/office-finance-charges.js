export function mountCharges({ api, data, canManage, escapeHtml: esc, toast }) {
    const $ = (id) => document.getElementById(id);
    const form = $('chargeForm');
    const dialog = $('chargeDialog');
    let page = 1;
    let generation = 0;
    let operation = null;
    const names = { ejecucion: 'Prestación realizada', acuerdo: 'Acuerdo comercial', compromiso: 'Compromiso', disponibilidad: 'Disponibilidad', realizado: 'Realizado', programado: 'Programado', no_realizado: 'No realizado', cancelado: 'Cancelado', no_aplica: 'No aplica' };
    const message = (r) => Object.values(r.data?.errors || {}).flat()[0] || r.data?.message || 'No fue posible guardar el cargo.';
    const uuid = () => {
        if (crypto.randomUUID) return crypto.randomUUID();
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
    };
    const service = (key) => data()?.servicios.find((item) => item.clave === key);
    function syncUnit() { $('chargeUnit').textContent = service(form.elements.servicio.value)?.unidades[0]?.nombre || ''; }

    async function load() {
        if (!data()) return;
        const request = ++generation;
        const response = await api(`/api/finanzas/cargos?mes=${encodeURIComponent($('chargeMonth').value)}&page=${page}`);
        if (request !== generation) return;
        if (!response.ok) { $('chargeStatus').textContent = message(response); return; }
        const result = response.data.data;
        if (!result || !Array.isArray(result.data)) { $('chargeStatus').textContent = 'No fue posible leer los cargos.'; return; }
        $('chargeStatus').textContent = 'Los borradores no se cobran. Los aprobados se valorizan según el tarifario; el estado operativo no los anula.';
        $('chargePage').textContent = `${result.current_page} / ${result.last_page}`;
        $('chargePrev').disabled = !result.prev_page_url;
        $('chargeNext').disabled = !result.next_page_url;
        $('chargeRows').innerHTML = result.data.map((cargo) => {
            const state = cargo.anulado_at ? 'Anulado' : cargo.aprobado_at ? 'Aprobado' : 'Borrador';
            const actions = `<button class="fin-boton" data-charge-history="${esc(cargo.id)}" type="button">Historial</button>`
                + (canManage() && !cargo.anulado_at ? `${cargo.aprobado_at ? '' : `<button class="fin-boton" data-charge-action="aprobar" data-charge-id="${esc(cargo.id)}" type="button">Aprobar cargo</button>`}<button class="fin-boton" data-charge-action="anular" data-charge-id="${esc(cargo.id)}" type="button">Anular</button>` : '');
            return `<tr><td>${esc(cargo.cliente_nombre)}<small>${esc(service(cargo.servicio)?.nombre || cargo.servicio)}</small></td><td>${esc(cargo.cantidad)} ${esc(service(cargo.servicio)?.unidad_libre)}<small>${esc(cargo.fecha.slice(0, 10))}</small></td><td>${esc(names[cargo.base_cobro])}<small>Ejecución: ${esc(names[cargo.estado_ejecucion])}</small><small>${esc(cargo.respaldo)}</small></td><td>${state}<div class="fin-acciones">${actions}</div></td></tr>`;
        }).join('') || '<tr><td colspan="4">No hay cargos en este mes.</td></tr>';
    }
    function render() {
        $('chargeNew').hidden = !canManage();
        if (!$('chargeMonth').value) $('chargeMonth').value = data().hoy.slice(0, 7);
        load();
    }
    $('chargeNew').addEventListener('click', () => {
        const services = (data()?.servicios || []).filter((item) => item.fuente === 'declarado');
        if (!services.length) { toast('Primero cree un concepto en modalidad acordada/manual.', true); $('finance-tariffs-tab-services').click(); return; }
        form.reset(); operation = uuid(); $('chargeError').textContent = '';
        form.elements.servicio.innerHTML = services.map((item) => `<option value="${esc(item.clave)}">${esc(item.nombre)}</option>`).join('');
        form.elements.cliente_id.innerHTML = data().clientes.filter((item) => item.activo).map((item) => `<option value="${esc(item.id)}">${esc(item.nombre)}</option>`).join('');
        form.elements.fecha.value = data().hoy; syncUnit(); dialog.showModal();
    });
    form.elements.servicio.addEventListener('change', syncUnit);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            const body = { ...Object.fromEntries(new FormData(form)), operacion_id: operation };
            body.cantidad = body.cantidad.trim().replace(',', '.');
            const response = await api('/api/finanzas/cargos', { method: 'POST', body: JSON.stringify(body) });
            if (!response.ok) { $('chargeError').textContent = message(response); return; }
            dialog.close(); toast('Borrador guardado. Revíselo y apruébelo para valorizarlo.'); await load();
        } finally { button.disabled = false; }
    });
    $('chargeRows').addEventListener('click', async (event) => {
        const action = event.target.closest('[data-charge-action]');
        const history = event.target.closest('[data-charge-history]');
        if (history) {
            $('chargeHistoryBody').textContent = 'Cargando…'; $('chargeHistory').showModal();
            const response = await api(`/api/finanzas/cargos/${encodeURIComponent(history.dataset.chargeHistory)}/historial`);
            $('chargeHistoryBody').innerHTML = response.ok ? response.data.data.map((item) => `<p class="fin-form__wide"><strong>${esc(item.accion)}</strong> · ${esc(item.user_nombre)}<small>${esc(item.created_at)} ${esc(item.datos?.motivo || '')}</small></p>`).join('') : esc(message(response));
        }
        if (!action || !canManage()) return;
        const motive = action.dataset.chargeAction === 'anular' ? window.prompt('Motivo de la anulación económica (al menos 5 caracteres):') : null;
        if (action.dataset.chargeAction === 'anular' && !motive) return;
        action.disabled = true;
        try {
            const response = await api(`/api/finanzas/cargos/${encodeURIComponent(action.dataset.chargeId)}/${action.dataset.chargeAction}`, { method: 'POST', body: JSON.stringify(motive ? { motivo: motive } : {}) });
            if (!response.ok) { toast(message(response), true); return; }
            toast('Estado económico actualizado.'); await load();
        } finally { action.disabled = false; }
    });
    $('chargeMonth').addEventListener('change', () => { page = 1; load(); });
    $('chargePrev').addEventListener('click', () => { page--; load(); });
    $('chargeNext').addEventListener('click', () => { page++; load(); });
    for (const modal of [dialog, $('chargeHistory')]) modal.addEventListener('click', (event) => { if (event.target.closest('[data-charge-close]')) modal.close(); });
    return { render };
}
