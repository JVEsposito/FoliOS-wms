const escape = v => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

export function setupConsumptionAllocation(form, api, canManage) {
    const panel = form.querySelector('[data-consumption-allocation]');
    const toggle = panel.querySelector('[data-allocation-toggle]');
    const tools = panel.querySelector('[data-allocation-tools]');
    const rows = panel.querySelector('[data-allocation-rows]');
    const search = panel.querySelector('[data-allocation-search]');
    const notice = panel.querySelector('[data-allocation-notice]');
    const select = panel.querySelector('[data-allocation-select]');
    let generation = 0;
    function changed() { form.dispatchEvent(new Event('input', { bubbles: true })); }
    async function load() {
        const current = ++generation; notice.textContent = 'Buscando órdenes en ejecución…';
        try {
            const data = await api(`/api/materiales/transformaciones/consumos/ordenes?${new URLSearchParams({ folio_id: form.elements.folio_id.value, q: search.value })}`);
            if (current !== generation) return;
            select.innerHTML = '<option value="">Selecciona una orden</option>' + data.data.map(o => `<option value="${escape(o.id)}">${escape(o.referencia_ot || `OT-${o.id.slice(0, 8).toUpperCase()}`)} · ${escape(o.proceso || 'Sin proceso')} · ${escape(o.turno || 'Sin turno')}</option>`).join('');
            notice.textContent = data.mas_resultados ? 'Hay más de 50 coincidencias. Busca por referencia para acotar.' : `${data.data.length} órdenes en ejecución del mismo cliente.`;
        } catch (e) { if (current === generation) { select.innerHTML = ''; notice.textContent = e.message; } }
    }
    toggle.addEventListener('change', () => {
        tools.hidden = !toggle.checked;
        rows.querySelectorAll('input, button').forEach(el => { el.disabled = !toggle.checked; });
        if (toggle.checked) void load(); else generation++;
        changed();
    });
    panel.querySelector('[data-allocation-find]').addEventListener('click', () => void load());
    panel.querySelector('[data-allocation-add]').addEventListener('click', () => {
        const id = select.value;
        if (!id || [...rows.children].some(r => r.dataset.order === id)) { notice.textContent = 'Selecciona una orden que todavía no esté agregada.'; return; }
        if (rows.children.length >= 50) { notice.textContent = 'Distribuye entre un máximo de 50 órdenes.'; return; }
        const row = document.createElement('div'); row.className = 'materials-allocation-row'; row.dataset.order = id;
        row.innerHTML = `<strong>${escape(select.selectedOptions[0].textContent)}</strong><label>Cantidad<input data-allocation-quantity type="number" min="0.001" max="99999999999.999" step="0.001" required></label><label>Merma incluida<input data-allocation-waste type="number" min="0" max="99999999999.999" step="0.001" value="0" required></label><button class="secondary-button" type="button" data-allocation-remove>Quitar OT</button>`;
        rows.append(row); changed();
    });
    rows.addEventListener('click', e => { if (e.target.closest('[data-allocation-remove]')) { e.target.closest('[data-order]').remove(); changed(); } });
    return {
        reset() { generation++; panel.hidden = !canManage(); toggle.checked = false; tools.hidden = true; rows.replaceChildren(); select.innerHTML = ''; search.value = ''; notice.textContent = ''; },
        payload() {
            if (!toggle.checked) return null;
            const lines = [...rows.children].map(row => ({ orden_id: row.dataset.order, cantidad: row.querySelector('[data-allocation-quantity]').value, cantidad_merma: row.querySelector('[data-allocation-waste]').value }));
            const total = lines.reduce((sum, l) => sum + Math.round(Number(l.cantidad) * 1000), 0);
            if (!lines.length || total !== Math.round(Number(form.elements.cantidad.value) * 1000)) throw new Error('La distribución entre órdenes debe sumar exactamente la cantidad consumida.');
            if (lines.some(l => Number(l.cantidad_merma) > Number(l.cantidad))) throw new Error('La merma de una orden no puede superar su consumo.');
            return lines;
        },
    };
}
