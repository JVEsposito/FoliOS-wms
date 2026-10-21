/** Constructor de expresiones con medidas públicas: nunca solicita código, tablas o SQL. */
export function mountRuleEditor({ root, processes, escapeHtml: esc, api }) {
    let sequence = 0;
    const rows = root.querySelector('[data-rule-terms]');
    const groups = root.querySelector('[data-rule-groups]');
    const sources = () => (processes() || []).filter((source) => !source.intervalo && !['calculado', 'declarado'].includes(source.clave));
    const sourceFor = (key) => sources().find((source) => source.clave === key);
    const terms = () => [...rows.querySelectorAll('[data-rule-term]')];
    const options = (items) => items.map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`).join('');
    let filterSequence = 0;

    function addFilter(row) {
        const list = row.querySelector('[data-rule-filters]');
        if (list.children.length >= 8) return;
        const source = sourceFor(row.querySelector('[data-rule-source]').value);
        const id = `ruleFilter${++filterSequence}`;
        list.insertAdjacentHTML('beforeend', `<div data-rule-filter-row><label><span>Filtrar por</span><select data-rule-filter><option value="">Sin filtro</option>${options(Object.entries(source?.dimensiones || {}))}</select></label><label><span>Valor exacto (puede elegir del catálogo)</span><input data-rule-filter-value maxlength="180" list="${id}"><datalist id="${id}"></datalist></label><button type="button" class="fin-boton" data-rule-filter-remove>Quitar filtro</button></div>`);
    }

    function dimensions() {
        const selected = new Set([...groups.querySelectorAll(':checked')].map((input) => input.value));
        const chosen = terms().map((row) => row.querySelector('[data-rule-source]').value);
        const common = sources().length && chosen.length
            ? Object.entries(sourceFor(chosen[0])?.dimensiones || {}).filter(([key]) => chosen.every((source) => Object.hasOwn(sourceFor(source)?.dimensiones || {}, key))) : [];
        const available = [...(new Set(chosen).size === 1 ? [['origen_id', 'Cada registro de origen']] : []), ['fecha_operacional', 'Día operacional'], ...common];
        groups.innerHTML = available.map(([key, label]) => `<label class="fin-form__check"><input type="checkbox" data-rule-group value="${esc(key)}" ${selected.has(key) ? 'checked' : ''}><span>${esc(label)}</span></label>`).join('');
        if (terms().length === 1 && !selected.size) groups.querySelector('[value="origen_id"]')?.setAttribute('checked', '');
    }

    function sync(row) {
        const source = sourceFor(row.querySelector('[data-rule-source]').value);
        row.querySelector('[data-rule-measure]').innerHTML = options(Object.entries(source?.medidas || {}).map(([key, measure]) => [key, `${measure.nombre} (${measure.unidad})`]));
        row.querySelector('[data-rule-filters]').innerHTML = '';
        addFilter(row);
        dimensions();
    }

    function add() {
        if (terms().length >= 8) return;
        const id = ++sequence;
        rows.insertAdjacentHTML('beforeend', `<fieldset data-rule-term class="fin-service-days"><legend>Término ${id}</legend>
            <label><span>Proceso</span><select data-rule-source>${options(sources().map((source) => [source.clave, `${source.area || 'general'} · ${source.nombre}`]))}</select></label>
            <label><span>Medida</span><select data-rule-measure></select></label>
            <label><span>Coeficiente (+ suma, − resta)</span><input data-rule-factor inputmode="decimal" value="1" aria-label="Coeficiente del término ${id}"></label>
            <div data-rule-filters></div><button type="button" class="fin-boton" data-rule-filter-add>+ Agregar filtro</button>
            <button type="button" class="fin-boton" data-rule-remove>Quitar término</button></fieldset>`);
        sync(terms().at(-1));
    }

    root.querySelector('[data-rule-add]').addEventListener('click', add);
    rows.addEventListener('change', async (event) => {
        if (event.target.matches('[data-rule-source]')) sync(event.target.closest('[data-rule-term]'));
        if (event.target.matches('[data-rule-filter]')) {
            const field = event.target.value;
            const filter = event.target.closest('[data-rule-filter-row]');
            const row = event.target.closest('[data-rule-term]');
            const source = row.querySelector('[data-rule-source]').value;
            const list = filter.querySelector('datalist');
            list.innerHTML = '';
            filter.querySelector('input').value = '';
            if (!field || !api) return;
            try {
                const response = await api(`/api/finanzas/servicios/referencias?fuente=${encodeURIComponent(source)}&campo=${encodeURIComponent(field)}`);
                if (response.ok && event.target.value === field && row.querySelector('[data-rule-source]').value === source && list.isConnected) {
                    list.innerHTML = (response.data.data || []).map((item) => `<option value="${esc(item.valor)}">${esc(item.nombre)}</option>`).join('');
                }
            } catch { /* El valor exacto puede ingresarse aunque falle la lectura del catálogo. */ }
        }
    });
    rows.addEventListener('click', (event) => {
        if (event.target.closest('[data-rule-filter-add]')) { addFilter(event.target.closest('[data-rule-term]')); return; }
        if (event.target.closest('[data-rule-filter-remove]')) { event.target.closest('[data-rule-filter-row]').remove(); return; }
        if (!event.target.closest('[data-rule-remove]') || terms().length <= 1) return;
        event.target.closest('[data-rule-term]').remove();
        dimensions();
    });

    return {
        reset() { rows.innerHTML = ''; groups.innerHTML = ''; sequence = 0; add(); root.querySelector('[data-rule-preview]').textContent = ''; },
        read() {
            return {
                agrupar: [...groups.querySelectorAll(':checked')].map((input) => input.value),
                exigir_todos: root.querySelector('[data-rule-complete]').checked,
                terminos: terms().map((row) => {
                    const filters = {};
                    for (const filter of row.querySelectorAll('[data-rule-filter-row]')) {
                        const field = filter.querySelector('[data-rule-filter]').value;
                        if (!field) continue;
                        if (Object.hasOwn(filters, field)) throw new Error('Cada dimensión puede filtrarse una sola vez en cada término.');
                        filters[field] = filter.querySelector('[data-rule-filter-value]').value.trim();
                    }
                    return { fuente: row.querySelector('[data-rule-source]').value, medida: row.querySelector('[data-rule-measure]').value,
                        factor: row.querySelector('[data-rule-factor]').value.trim().replace(',', '.'),
                        filtros: filters };
                }),
            };
        },
    };
}
