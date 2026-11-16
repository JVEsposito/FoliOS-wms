import './office-navigation';

// Estándares de consumo: cuánto material lleva cada caja de un embalaje PT de un cliente.
const $ = (id) => document.getElementById(id);
const escape = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => new Intl.NumberFormat('es-CL', { maximumFractionDigits: 6 }).format(Number(v || 0));
const dmy = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('-') : '—');
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago' }).format(new Date());
const token = localStorage.getItem('estiba_wms_office_token');
const state = { page: 1, lastPage: 1, busy: false, canAdmin: false, options: null, detail: null, version: null, editor: null, editorOptions: null };

async function api(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la operación.');
    return data;
}
function filters() { return new URLSearchParams([...new FormData($('standardsFilters'))].filter(([, v]) => v !== '')); }
async function run(work, notice = $('standardsNotice')) {
    if (state.busy) return;
    state.busy = true; notice.textContent = '';
    try { await work(); } catch (error) { notice.textContent = error.message; }
    finally { state.busy = false; $('standardsPrevious').disabled = state.page <= 1; $('standardsNext').disabled = state.page >= state.lastPage; }
}
function fillSelect(select, rows, blank, label = (r) => r.nombre) {
    const selected = select.value;
    select.innerHTML = (blank === null ? '' : `<option value="">${escape(blank)}</option>`) + rows.map((r) => `<option value="${escape(r.id)}">${escape(label(r))}</option>`).join('');
    if ([...select.options].some((o) => o.value === selected)) select.value = selected;
}
function originBadge(row) {
    return `<span class="standards-badge standards-badge--${escape(row.origen)}">${escape(row.origen_nombre)}</span>${row.activo ? '' : ' <span class="standards-badge standards-badge--inactivo">Inactivo</span>'}`;
}

async function loadOptions() {
    const params = new URLSearchParams(); const season = $('standardsFilters').elements.temporada_id.value;
    if (season) params.set('temporada_id', season);
    state.options = await api(`/api/materiales/estandares/opciones?${params}`);
    fillSelect($('standardsFilters').elements.temporada_id, state.options.temporadas, null, (t) => `${t.codigo} · ${t.nombre}${t.activa ? ' (activa)' : ''}`);
    if (!season && state.options.temporada_id) $('standardsFilters').elements.temporada_id.value = state.options.temporada_id;
    fillSelect($('standardsFilters').elements.cliente_id, state.options.clientes, 'Todos');
}
async function load() {
    const params = filters(); params.set('page', state.page);
    const data = await api(`/api/materiales/estandares?${params}`);
    state.page = data.meta.page; state.lastPage = data.meta.last_page; state.canAdmin = data.puede_administrar === true;
    $('standardNew').hidden = !state.canAdmin;
    $('standardsTotal').textContent = `${data.meta.total} estándares. La versión vigente es la que rige hoy.`;
    $('standardsPage').textContent = `${state.page} / ${state.lastPage}`;
    $('standardsRows').innerHTML = data.data.map((row) => `<tr>
        <td><strong>${escape(row.codigo)}</strong><small>${escape(row.descripcion)}</small></td>
        <td>${escape(row.cliente?.nombre)}</td>
        <td>${escape(row.envase?.nombre)}<small>${escape([row.codigo_articulo, row.envase?.especie].filter(Boolean).join(' · '))}</small></td>
        <td>${originBadge(row)}</td>
        <td>${row.version_vigente ? `v${row.version_vigente}` : '<span class="standards-warning">Sin versión vigente hoy</span>'}${row.version_futura ? '<small>Hay una versión que empieza más adelante</small>' : ''}</td>
        <td>${row.insumos_sin_item ? `<span class="standards-warning">${row.insumos_sin_item} sin homologar</span>` : 'Homologados'}</td>
        <td>${row.activo ? 'Activo' : 'Inactivo'}</td>
        <td><button type="button" class="secondary-button" data-open="${escape(row.id)}">Ver</button></td></tr>`).join('') || '<tr><td colspan="8">No hay estándares para los filtros elegidos.</td></tr>';
}

function renderDetail() {
    const row = state.detail; const box = $('standardDetail');
    if (!row) { box.hidden = true; return; }
    const version = row.versiones.find((v) => v.id === state.version) || row.versiones.find((v) => v.vigente) || row.versiones[0];
    state.version = version?.id || null;
    const actions = state.canAdmin ? [
        row.origen === 'propio' && row.activo ? '<button type="button" class="primary-button" data-action="version">Nueva versión</button>' : '',
        row.origen === 'planta' ? '<button type="button" class="secondary-button" data-action="copy">Copiar como propio</button>' : '',
        `<button type="button" class="secondary-button" data-action="toggle">${row.activo ? 'Desactivar' : 'Activar'}</button>`,
    ].join('') : '';
    box.hidden = false;
    box.innerHTML = `<div class="standards-detail__heading"><div><p class="eyebrow">${escape(row.cliente?.nombre)} · ${escape(row.temporada?.codigo)}</p>
        <h2>${escape(row.codigo)} · ${escape(row.descripcion)}</h2>
        <p>${originBadge(row)} Embalaje ${escape(row.envase?.nombre)}${row.codigo_articulo ? ` (${escape(row.codigo_articulo)})` : ''}${row.estandar_base ? ` · copiado del estándar de planta ${escape(row.estandar_base.codigo)}` : ''}</p></div>
        <div class="standards-detail__actions">${actions}</div></div>
        <div class="standards-versions" role="group" aria-label="Versiones">${row.versiones.map((v) => `<button type="button" data-version="${escape(v.id)}" aria-pressed="${v.id === state.version}">v${v.numero} · desde ${dmy(v.vigente_desde)}${v.vigente ? ' · vigente' : ''}</button>`).join('')}</div>
        ${version ? `<p class="standards-version-note">Versión ${version.numero}, rige para las cajas embaladas desde el ${dmy(version.vigente_desde)}. ${escape(version.motivo || 'Sin motivo')} · ${escape(version.creado_por || '')}</p>
        <div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Insumo</th><th>Dueño</th><th>Cantidad por caja</th><th>Rebaja</th><th>Variación</th></tr></thead><tbody>${version.lineas.map((l) => `<tr>
            <td><strong>${escape(l.codigo_insumo)}</strong><small>${escape(l.descripcion_insumo)}${l.unidad_medida ? ` · ${escape(l.unidad_medida)}` : ''}</small></td>
            <td>${l.item_material_id ? escape(l.dueno) : '<span class="standards-warning">Sin homologar</span>'}</td>
            <td>${qty(l.cantidad_por_caja)}</td><td>${l.redondeo === 'entero' ? 'Entero' : 'Decimal'}</td>
            <td>${l.variacion_tipo ? `${escape(l.variacion_nombre)}: ${escape(l.variacion_valor)}` : 'General'}</td></tr>`).join('')}</tbody></table></div>` : '<p>Sin versiones.</p>'}`;
}
async function openDetail(id) {
    const { data } = await api(`/api/materiales/estandares/${encodeURIComponent(id)}`);
    state.detail = data; state.version = null; renderDetail();
    $('standardDetail').scrollIntoView({ block: 'nearest' });
}

// ── Editor ────────────────────────────────────────────────────────────────
function variationValues(type) {
    const values = state.editorOptions?.valores?.[type] || [];
    $('standardVariationValues').innerHTML = values.map((v) => `<option value="${escape(v)}"></option>`).join('');
}
function lineTemplate(line = {}) {
    const node = document.createElement('div'); node.className = 'standards-line';
    node.dataset.item = line.item_material_id || '';
    const variations = (state.options?.variaciones || []).map((v) => `<option value="${escape(v.clave)}"${line.variacion_tipo === v.clave ? ' selected' : ''}>${escape(v.nombre)}</option>`).join('');
    node.innerHTML = `<div class="standards-item-picker"><label>Ítem de Materiales<input data-item-search autocomplete="off" placeholder="Código o nombre" value="${escape(line.item_material_id ? `${line.codigo_insumo} · ${line.descripcion_insumo}` : '')}"></label>
        <small data-item-hint>${escape(line.item_material_id ? [line.dueno, line.unidad_medida].filter(Boolean).join(' · ') : '')}</small><div class="standards-item-results" data-item-results hidden></div></div>
        <label>Cantidad por caja<input data-quantity inputmode="decimal" value="${escape(line.cantidad_por_caja ?? '')}" placeholder="0,0062"></label>
        <label>Rebaja<select data-rounding><option value="entero"${line.redondeo !== 'decimal' ? ' selected' : ''}>Entero</option><option value="decimal"${line.redondeo === 'decimal' ? ' selected' : ''}>Decimal</option></select></label>
        <label>Variación<select data-variation-type><option value="">General</option>${variations}</select></label>
        <label>Valor<input data-variation-value list="standardVariationValues" maxlength="120" value="${escape(line.variacion_valor ?? '')}"${line.variacion_tipo ? '' : ' disabled'}></label>
        <button type="button" data-remove aria-label="Quitar insumo">×</button>`;
    return node;
}
function addLine(line) { $('standardLines').append(lineTemplate(line)); }
async function editorOptions(clientId) {
    const params = new URLSearchParams({ cliente_id: clientId });
    if (state.options?.temporada_id) params.set('temporada_id', state.options.temporada_id);
    state.editorOptions = await api(`/api/materiales/estandares/opciones?${params}`);
    const envelope = $('standardForm').elements.envase_validacion_id;
    envelope.innerHTML = '<option value="">Elegir embalaje</option>' + state.editorOptions.envases.map((e) => `<option value="${escape(e.id)}">${escape(e.nombre)}${e.codigo_externo ? ` · ${escape(e.codigo_externo)}` : ''}${e.especie ? ` · ${escape(e.especie)}` : ''}</option>`).join('');
}
async function openEditor(mode) {
    const form = $('standardForm'); const detail = state.detail;
    form.reset(); $('standardLines').innerHTML = ''; $('standardFormError').textContent = '';
    state.editor = { mode, id: detail?.id, version: detail?.version };
    const fresh = mode === 'nuevo';
    $('standardIdentity').hidden = !fresh; $('standardIdentity').disabled = !fresh;
    $('standardDialogEyebrow').textContent = fresh ? 'NUEVO ESTÁNDAR PROPIO' : `${detail.cliente?.nombre} · ${detail.envase?.nombre}`;
    $('standardDialogTitle').textContent = fresh ? 'Nuevo estándar' : `Nueva versión de ${detail.codigo}`;
    $('standardDialogHelp').textContent = fresh ? 'Elige el cliente y el embalaje PT. Cada insumo indica cuánto lleva una caja.' : 'La versión actual no se modifica: la nueva rige desde la fecha que indiques.';
    form.elements.vigente_desde.value = today();
    if (fresh) {
        fillSelect(form.elements.cliente_id, state.options.clientes, 'Elegir cliente', (c) => `${c.nombre}${c.codigo ? ` · ${c.codigo}` : ''}`);
        form.elements.envase_validacion_id.innerHTML = '<option value="">Elige primero el cliente</option>';
        state.editorOptions = null; addLine();
    } else {
        form.elements.codigo.value = detail.codigo; form.elements.descripcion.value = detail.descripcion;
        await editorOptions(detail.cliente.id);
        const current = detail.versiones.find((v) => v.vigente) || detail.versiones[0];
        (current?.lineas || []).forEach(addLine);
        if (!current?.lineas?.length) addLine();
    }
    $('standardDialog').showModal();
}
let searchTimer = null; let searchController = null;
function searchItems(input) {
    clearTimeout(searchTimer);
    const picker = input.closest('.standards-item-picker'); const results = picker.querySelector('[data-item-results]');
    input.closest('.standards-line').dataset.item = '';
    picker.querySelector('[data-item-hint]').textContent = '';
    if (input.value.trim().length < 2) { results.hidden = true; return; }
    searchTimer = setTimeout(async () => {
        searchController?.abort(); searchController = new AbortController();
        const client = state.editor?.mode === 'nuevo' ? $('standardForm').elements.cliente_id.value : state.detail?.cliente?.id;
        const params = new URLSearchParams({ q: input.value.trim() }); if (client) params.set('cliente_id', client);
        try {
            const { data } = await api(`/api/materiales/estandares/items?${params}`, { signal: searchController.signal });
            results.innerHTML = data.map((item) => `<button type="button" data-pick="${escape(item.id)}" data-label="${escape(`${item.codigo} · ${item.nombre}`)}" data-hint="${escape([item.dueno, item.unidad_medida].filter(Boolean).join(' · '))}"><strong>${escape(item.codigo)} · ${escape(item.nombre)}</strong><small>${escape(item.dueno)}${item.unidad_medida ? ` · ${escape(item.unidad_medida)}` : ''}${item.del_cliente ? '' : ' · otro dueño'}</small></button>`).join('') || '<p>Sin ítems activos con ese texto en la temporada.</p>';
            results.hidden = false;
        } catch (error) { if (error.name !== 'AbortError') { results.innerHTML = `<p>${escape(error.message)}</p>`; results.hidden = false; } }
    }, 250);
}
function editorPayload() {
    const form = $('standardForm');
    const lines = [...$('standardLines').querySelectorAll('.standards-line')].map((node) => ({
        item_material_id: node.dataset.item,
        cantidad_por_caja: node.querySelector('[data-quantity]').value.trim().replace(',', '.'),
        redondeo: node.querySelector('[data-rounding]').value,
        variacion_tipo: node.querySelector('[data-variation-type]').value || null,
        variacion_valor: node.querySelector('[data-variation-value]').value.trim() || null,
    }));
    const missing = lines.findIndex((l) => !l.item_material_id);
    if (missing >= 0) throw new Error(`Elige el ítem del insumo ${missing + 1} desde la lista de resultados.`);
    const payload = { codigo: form.elements.codigo.value.trim() || null, descripcion: form.elements.descripcion.value.trim() || null, vigente_desde: form.elements.vigente_desde.value, motivo: form.elements.motivo.value.trim() || null, lineas: lines };
    if (state.editor.mode === 'nuevo') return { ...payload, cliente_id: form.elements.cliente_id.value, envase_validacion_id: form.elements.envase_validacion_id.value };
    return { ...payload, version: state.editor.version };
}

$('standardsFilters').addEventListener('submit', (e) => { e.preventDefault(); void run(async () => { state.page = 1; await load(); }); });
$('standardsFilters').elements.temporada_id.addEventListener('change', () => void run(async () => { await loadOptions(); state.page = 1; await load(); }));
$('standardsReload').addEventListener('click', () => void run(load));
$('standardsPrevious').addEventListener('click', () => void run(async () => { state.page -= 1; await load(); }));
$('standardsNext').addEventListener('click', () => void run(async () => { state.page += 1; await load(); }));
$('standardsRows').addEventListener('click', (e) => { const button = e.target.closest('[data-open]'); if (button) void run(() => openDetail(button.dataset.open)); });
$('standardNew').addEventListener('click', () => void run(() => openEditor('nuevo')));
$('standardDetail').addEventListener('click', (e) => {
    const version = e.target.closest('[data-version]');
    if (version) { state.version = version.dataset.version; renderDetail(); return; }
    const action = e.target.closest('[data-action]')?.dataset.action; const row = state.detail;
    if (action === 'version') void run(() => openEditor('version'));
    if (action === 'copy') void run(async () => {
        const { data } = await api(`/api/materiales/estandares/${encodeURIComponent(row.id)}/copiar`, { method: 'POST', body: JSON.stringify({ vigente_desde: today() }) });
        state.detail = data; state.version = null; renderDetail(); await load();
        $('standardsNotice').textContent = `Estándar propio ${data.codigo} creado. Desde ahora la rebaja de este embalaje usa el propio.`;
    });
    if (action === 'toggle') void run(async () => {
        const { data } = await api(`/api/materiales/estandares/${encodeURIComponent(row.id)}/estado`, { method: 'PUT', body: JSON.stringify({ activo: !row.activo, version: row.version }) });
        state.detail = data; renderDetail(); await load();
        $('standardsNotice').textContent = `Estándar ${data.codigo} ${data.activo ? 'activado' : 'desactivado'}.`;
    });
});
$('standardsExport').addEventListener('click', () => void run(async () => {
    const response = await fetch(`/api/materiales/estandares/excel?${filters()}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    if (!response.ok) { const data = await response.json().catch(() => ({})); throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible exportar.'); }
    const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = 'estandares-consumo.xlsx'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}));

$('standardForm').elements.cliente_id.addEventListener('change', (e) => {
    if (!e.target.value) return;
    void run(() => editorOptions(e.target.value), $('standardFormError'));
});
$('standardAddLine').addEventListener('click', () => addLine());
$('standardLines').addEventListener('input', (e) => { if (e.target.matches('[data-item-search]')) searchItems(e.target); });
$('standardLines').addEventListener('focusin', (e) => { if (e.target.matches('[data-variation-value]')) variationValues(e.target.closest('.standards-line').querySelector('[data-variation-type]').value); });
$('standardLines').addEventListener('change', (e) => {
    if (!e.target.matches('[data-variation-type]')) return;
    const value = e.target.closest('.standards-line').querySelector('[data-variation-value]');
    value.disabled = !e.target.value; if (!e.target.value) value.value = '';
    variationValues(e.target.value);
});
$('standardLines').addEventListener('click', (e) => {
    const pick = e.target.closest('[data-pick]');
    if (pick) {
        const line = pick.closest('.standards-line');
        line.dataset.item = pick.dataset.pick;
        line.querySelector('[data-item-search]').value = pick.dataset.label;
        line.querySelector('[data-item-hint]').textContent = pick.dataset.hint;
        pick.closest('[data-item-results]').hidden = true;
        $('standardFormError').textContent = '';
        line.querySelector('[data-quantity]').focus();
        return;
    }
    const remove = e.target.closest('[data-remove]');
    if (remove && $('standardLines').children.length > 1) remove.closest('.standards-line').remove();
});
$('standardDialog').addEventListener('click', (e) => { if (e.target.closest('[data-close]') && !state.busy) $('standardDialog').close(); });
$('standardForm').addEventListener('submit', (e) => {
    e.preventDefault();
    void run(async () => {
        const payload = editorPayload(); const fresh = state.editor.mode === 'nuevo';
        if (fresh && (!payload.cliente_id || !payload.envase_validacion_id)) throw new Error('Elige el cliente y el embalaje PT.');
        const url = fresh ? '/api/materiales/estandares' : `/api/materiales/estandares/${encodeURIComponent(state.editor.id)}/versiones`;
        const { data } = await api(url, { method: 'POST', body: JSON.stringify(payload) });
        $('standardDialog').close();
        state.detail = data; state.version = null; renderDetail(); await load();
        $('standardsNotice').textContent = fresh ? `Estándar ${data.codigo} creado.` : `Versión ${data.versiones[0].numero} de ${data.codigo} guardada.`;
    }, $('standardFormError'));
});

if (!token) location.replace('/oficina/materiales');
else void run(async () => { await loadOptions(); await load(); });
