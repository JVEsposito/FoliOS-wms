import { api, choices, escape } from './comex-commercial-ui';

// Cada búsqueda conserva la selección expresa, aunque quede fuera de las primeras 25 filas.
export function pickerHtml(key, label, multiple = false) {
    return `<div class="cx-picker" data-picker="${escape(key)}"><label><span>Buscar ${escape(label)}</span><input type="search" maxlength="100" data-search></label><button type="button" data-find class="secondary-button">Buscar</button><label><span>${escape(label)}</span><select data-choice ${multiple ? 'multiple size="4"' : 'required'}></select></label></div>`;
}
export async function preparePicker(root, endpoint, selected = [], label = (r) => r.razon_social || r.nombre) {
    const select = root.querySelector('[data-choice]');
    select.innerHTML = choices(selected.map((r) => [r.id, label(r)]), select.multiple ? '' : 'Seleccione expresamente');
    for (const option of select.options) option.selected = selected.some((r) => r.id === option.value);
    let generation = 0;
    const find = async () => {
        const request = ++generation;
        const response = await api(`${endpoint}${endpoint.includes('?') ? '&' : '?'}buscar=${encodeURIComponent(root.querySelector('[data-search]').value)}`);
        if (request !== generation) return;
        const keep = [...select.selectedOptions].filter((o) => o.value).map((o) => [o.value, o.textContent]);
        const options = response.data.data.map((r) => [r.id, label(r)]);
        for (const item of keep) if (!options.some(([id]) => id === item[0])) options.push(item);
        select.innerHTML = choices(options, select.multiple ? '' : 'Seleccione expresamente');
        for (const o of select.options) o.selected = keep.some(([id]) => id === o.value);
    };
    root.querySelector('[data-find]').onclick = async (e) => {
        const button = e.currentTarget; button.disabled = true;
        try { await find(); } catch (error) { root.closest('form').querySelector('[data-cc-error]').textContent = error.message; } finally { button.disabled = false; }
    };
    if (!selected.length) await find();
}
export const picked = (root) => [...root.querySelector('[data-choice]').selectedOptions].map((o) => o.value).filter(Boolean);
