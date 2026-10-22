export function createOriginExplorer({ api, authorized, session, escapeHtml: esc }) {
    const $ = (id) => document.getElementById(id);
    const root = $('originExplorer');
    let connection = null;
    let objects = [];
    let columns = [];
    let generation = 0;
    let selected = '';
    const valid = (version, token) => generation === version && session() === token && authorized();
    function clear() {
        generation++; connection = null; objects = []; columns = []; selected = '';
        root.hidden = true;
        for (const id of ['originObjects', 'originColumns', 'originFilters', 'originSample', 'originStatus']) $(id).replaceChildren();
        $('originSearch').value = '';
        root.dispatchEvent(new CustomEvent('origin:selection', { detail: null }));
    }
    async function request(action, data = {}) {
        return api(`/${encodeURIComponent(connection.id)}/${action}`, { method: 'POST', body: JSON.stringify({ version_conocida: connection.version, ...data }) });
    }
    function choices() { return columns.map((c) => `<option value="${esc(c.nombre)}">${esc(c.nombre)}</option>`).join(''); }
    function filters() { return [...$('originFilters').children].map((row) => ({ columna: row.querySelector('select').value, valor: row.querySelector('input').value })); }
    function source() { return connection && selected ? { conexion_id: connection.id, version_conexion: connection.version, tabla: selected, columnas: columns, filtros: filters() } : null; }
    function notify() { root.dispatchEvent(new CustomEvent('origin:selection', { detail: source() })); }
    function renderObjects() {
        const search = $('originSearch').value.trim().toLocaleLowerCase();
        $('originObjects').innerHTML = ['VIEW', 'BASE TABLE'].map((type) => `<optgroup label="${type === 'VIEW' ? 'Vistas' : 'Tablas'}">${objects.filter((o) => o.tipo === type && o.nombre.toLocaleLowerCase().includes(search)).map((o) => `<option value="${esc(o.nombre)}" ${selected === o.nombre ? 'selected' : ''}>${esc(o.nombre)}</option>`).join('')}</optgroup>`).join('');
    }
    async function open(item) {
        clear(); connection = item; root.hidden = false;
        $('originTitle').textContent = `Explorar ${item.nombre}`;
        $('originStatus').textContent = 'Consultando las tablas y vistas disponibles…';
        const version = ++generation; const token = session();
        try {
            const response = await request('estructura');
            if (!valid(version, token)) return;
            objects = response.data; renderObjects();
            $('originStatus').textContent = `${objects.length} fuentes disponibles. Seleccione una para ver sus columnas.`;
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) { if (valid(version, token)) $('originStatus').textContent = error.message; }
    }
    $('originSearch').addEventListener('input', renderObjects);
    $('originObjects').addEventListener('change', async () => {
        selected = $('originObjects').value; columns = [];
        for (const id of ['originColumns', 'originFilters', 'originSample']) $(id).replaceChildren();
        notify();
        const version = ++generation; const token = session();
        $('originStatus').textContent = 'Consultando columnas…';
        try {
            const response = await request('columnas', { tabla: selected });
            if (!valid(version, token)) return;
            columns = response.data;
            $('originColumns').innerHTML = columns.map((c, i) => `<label class="admin-check"><input type="checkbox" value="${esc(c.nombre)}" ${i < 12 ? 'checked' : ''}><span>${esc(c.nombre)} <small>${esc(c.tipo)}${c.clave === 'PRI' ? ' · clave' : ''}</small></span></label>`).join('');
            $('originStatus').textContent = `${columns.length} columnas. Puede probar la lectura sin filtros y después acotarla.`;
            notify();
        } catch (error) { if (valid(version, token)) $('originStatus').textContent = error.message; }
    });
    $('originFilterAdd').addEventListener('click', () => {
        if (!columns.length || $('originFilters').children.length >= 8) return;
        $('originFilters').insertAdjacentHTML('beforeend', `<div class="origin-filter"><select aria-label="Columna del filtro">${choices()}</select><span>=</span><input aria-label="Valor exacto del filtro" maxlength="180"><button type="button" data-origin-remove>Quitar</button></div>`);
        generation++; $('originSample').replaceChildren(); notify();
    });
    $('originFilters').addEventListener('click', (event) => {
        if (event.target.closest('[data-origin-remove]')) { event.target.closest('.origin-filter').remove(); generation++; $('originSample').replaceChildren(); notify(); }
    });
    for (const id of ['originFilters', 'originColumns']) $(id).addEventListener('input', () => { generation++; $('originSample').replaceChildren(); notify(); });
    $('originPreview').addEventListener('click', async () => {
        if (!connection || !selected || !columns.length) return;
        const chosen = [...$('originColumns').querySelectorAll('input:checked')].map((input) => input.value);
        if (!chosen.length || chosen.length > 40) { $('originStatus').textContent = 'Seleccione entre 1 y 40 columnas.'; return; }
        const version = ++generation; const token = session();
        $('originSample').replaceChildren(); $('originStatus').textContent = 'Leyendo una muestra de hasta 10 filas…';
        try {
            const response = await request('muestra', { tabla: selected, columnas: chosen, filtros: filters(), limite: 10 });
            if (!valid(version, token)) return;
            const data = response.data;
            $('originSample').innerHTML = `<table class="admin-table"><thead><tr>${data.columnas.map((c) => `<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>${data.filas.map((row) => `<tr>${data.columnas.map((c) => `<td>${row[c] === null ? '—' : esc(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
            $('originStatus').textContent = data.filas.length ? `Lectura correcta: ${data.filas.length} filas de muestra${data.valores_recortados ? ' · valores largos recortados' : ''}.` : 'La consulta respondió sin filas con esta selección. Pruebe quitando los filtros.';
        } catch (error) { if (valid(version, token)) $('originStatus').textContent = error.message; }
    });
    return { open, clear, source };
}
