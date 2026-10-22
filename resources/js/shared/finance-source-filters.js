/** Criterios de un servicio directo o de permanencia, tomados del catálogo del proceso. */
export function mountSourceFilters({ root, source, api, escapeHtml: esc }) {
    const rows = root.querySelector('[data-source-filter-rows]');
    let sequence = 0;
    function add() {
        if (rows.children.length >= 8) return;
        const id = `sourceFilter${++sequence}`;
        rows.insertAdjacentHTML('beforeend', `<div data-source-filter-row><label><span>Dimensión</span><select data-source-filter-field><option value="">Sin filtro</option>${Object.entries(source()?.dimensiones || {}).map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`).join('')}</select></label><label><span>Valor exacto (puede elegir del catálogo)</span><input data-source-filter-value list="${id}" maxlength="180"><datalist id="${id}"></datalist></label><button type="button" class="fin-boton" data-source-filter-remove>Quitar filtro</button></div>`);
    }
    root.querySelector('[data-source-filter-add]').addEventListener('click', add);
    rows.addEventListener('click', (event) => { if (event.target.closest('[data-source-filter-remove]')) event.target.closest('[data-source-filter-row]').remove(); });
    rows.addEventListener('change', async (event) => {
        if (!event.target.matches('[data-source-filter-field]')) return;
        const row = event.target.closest('[data-source-filter-row]');
        const field = event.target.value;
        const key = source()?.clave;
        const list = row.querySelector('datalist');
        list.innerHTML = ''; row.querySelector('input').value = '';
        if (!field || !key) return;
        try {
            const result = await api(`/api/finanzas/servicios/referencias?fuente=${encodeURIComponent(key)}&campo=${encodeURIComponent(field)}`);
            if (result.ok && event.target.value === field && source()?.clave === key && list.isConnected) list.innerHTML = (result.data.data || []).map((item) => `<option value="${esc(item.valor)}">${esc(item.nombre)}</option>`).join('');
        } catch { /* Se conserva el ingreso del valor exacto. */ }
    });
    return {
        reset() { rows.innerHTML = ''; if (Object.keys(source()?.dimensiones || {}).length) add(); },
        read() {
            const filters = {};
            if (root.hidden) return filters;
            for (const row of rows.children) {
                const field = row.querySelector('select').value;
                if (!field) continue;
                if (Object.hasOwn(filters, field)) throw new Error('Cada dimensión puede filtrarse una sola vez.');
                filters[field] = row.querySelector('input').value.trim();
            }
            return filters;
        },
    };
}
