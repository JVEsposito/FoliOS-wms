const $ = (id) => document.getElementById(id);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function operationId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}
const states = { con_observaciones: 'Con observaciones', retenido: 'Retenido', liberado: 'Liberado' };
const state = { token: localStorage.getItem('estiba_wms_office_token'), catalog: null, detail: null, targets: new Map(), pending: null, busy: false, listRevision: 0, detailRevision: 0 };
const form = $('slForm');
const field = (name) => form.elements.namedItem(name);
const url = '/api/sag/lotes-retenidos';
const date = (value) => value ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' }).format(new Date(value)) : '—';
const labelDestinations = (r) => r.todos_destinos ? 'Todos los destinos' : r.destinos.map((d) => d.nombre).join(' · ') || 'Sin restricciones de destino';

async function api(path = '', options = {}) {
    const response = await fetch(url + path, { ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${state.token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) } });
    if (response.status === 401) {
        localStorage.removeItem('estiba_wms_office_token');
        $('officeAccess').classList.remove('is-hidden'); $('officeApp').classList.add('is-hidden');
    }
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'No se pudo completar la operación.');
    return data;
}
async function run(action) {
    try { await action(); } catch (e) { $('slError').textContent = e.message; $('slMessage').textContent = e.message; }
}
async function list(page = 1) {
    const revision = ++state.listRevision;
    const query = new URLSearchParams(new FormData($('slFilters'))); query.set('page', page);
    const data = await api(`?${query}`);
    if (revision !== state.listRevision) return;
    $('slRows').innerHTML = data.data.map((r) => `<tr><td><strong>${esc(r.numero_lote)}</strong><small>${esc(r.cliente?.nombre)}</small></td><td>${esc(states[r.estado])}<small>${r.definitivo ? 'Resultado definitivo' : 'En seguimiento'}</small></td><td>${esc(labelDestinations(r))}</td><td>${esc(date(r.updated_at))}<small>Versión ${r.version}</small></td><td><button type="button" class="secondary-button" data-open="${esc(r.id)}">Abrir / resolver</button></td></tr>`).join('') || '<tr><td colspan="5">Sin lotes registrados para estos filtros.</td></tr>';
    $('slPages').replaceChildren();
    for (const [text, target] of [['Anterior', page - 1], ['Siguiente', page + 1]]) {
        const button = document.createElement('button'); button.className = 'secondary-button'; button.textContent = text;
        button.disabled = target < 1 || target > data.last_page; button.onclick = () => run(() => list(target)); $('slPages').append(button);
    }
}
function destinations() {
    const all = field('todos_destinos').checked;
    const released = field('estado').value === 'liberado';
    const search = $('slDestinationSearch').value.trim().toLocaleLowerCase('es');
    const choices = [
        ...state.catalog.bloques.map((d) => ({ tipo: 'bloque', id: d.id, label: `Bloque ${d.codigo} · ${d.nombre}` })),
        ...state.catalog.paises.map((d) => ({ tipo: 'pais', id: d.id, label: `${d.iso_alpha2} · ${d.nombre_es}` })),
    ].filter((d) => !search || d.label.toLocaleLowerCase('es').includes(search));
    $('slDestinations').innerHTML = choices.map((d) => {
        const key = `${d.tipo}|${d.id}`;
        return `<label class="sl-check"><input type="checkbox" data-destination="${esc(key)}"${state.targets.has(key) ? ' checked' : ''}${all || released ? ' disabled' : ''}>${esc(d.label)}</label>`;
    }).join('');
    field('todos_destinos').disabled = released;
    $('slSelected').textContent = released ? 'La liberación elimina las restricciones de este lote.' : all ? 'Despacho restringido a todos los destinos.' : `${state.targets.size} países / bloques seleccionados.`;
}
function editor(detail = null) {
    state.detail = detail; state.pending = null; state.targets = new Map();
    form.reset(); $('slError').textContent = ''; $('slEditor').classList.remove('is-hidden');
    $('slEvidence').classList.toggle('is-hidden', !detail);
    const r = detail?.registro;
    field('cliente_id').disabled = Boolean(r); field('numero_lote').readOnly = Boolean(r);
    field('resolucion').required = Boolean(r);
    field('estado').querySelector('option[value="liberado"]').disabled = !r;
    field('fecha_deteccion').value = state.catalog.fecha_planta; field('fecha_deteccion').max = state.catalog.fecha_planta;
    $('slTitle').textContent = r ? `${r.cliente.nombre} · Lote ${r.numero_lote}` : 'Registrar lote de recepción';
    $('slVersion').textContent = r ? `VERSIÓN ${r.version} · ${r.definitivo ? 'RESULTADO DEFINITIVO' : 'EN SEGUIMIENTO'}` : state.catalog.temporada.nombre;
    $('slSave').textContent = r ? 'Guardar resolución / seguimiento' : 'Registrar seguimiento';
    if (r) {
        for (const name of ['cliente_id', 'numero_lote', 'estado', 'observacion', 'referencia_informe', 'instruccion_segregacion']) field(name).value = r[name] || '';
        field('fecha_deteccion').value = r.fecha_deteccion.slice(0, 10);
        field('todos_destinos').checked = r.todos_destinos; field('definitivo').checked = r.definitivo;
        state.targets = new Map(r.destinos.map((d) => [`${d.tipo}|${d.id}`, { tipo: d.tipo, id: d.id }]));
        const active = detail.folios.filter((f) => f.activo);
        $('slTotals').textContent = `${active.length} folios activos · ${active.reduce((n, f) => n + f.cajas_lote, 0)} cajas del lote.`;
        $('slFolios').innerHTML = detail.folios.map((f) => `<tr><td>${esc(f.folio)}</td><td>${f.cajas_lote} / ${f.cajas_pallet}</td><td>${esc(f.csg)}</td><td>${esc(f.ubicacion || f.estado)}<small>${esc(f.estado)}</small></td><td>${esc(f.carga || 'Sin carga')}</td></tr>`).join('') || '<tr><td colspan="5">Aún no hay folios con cajas de este cliente y lote. Los que ingresen después heredarán el control.</td></tr>';
        $('slHistory').innerHTML = detail.historial.map((e) => `<article class="sl-history"><strong>Versión ${e.version} · ${esc(e.usuario)} · ${esc(date(e.fecha.replace(' ', 'T') + 'Z'))}</strong><p>${esc(states[e.despues.estado])} · ${esc(labelDestinations(e.despues))}</p><p>${esc(e.despues.resolucion || e.despues.observacion)}</p><small>${e.folios_afectados.length} folios identificados al registrar. ${esc(e.despues.instruccion_segregacion || '')}</small></article>`).join('');
    }
    $('slDestinationSearch').value = ''; destinations();
}
async function enter() {
    state.catalog = await api('/catalogo');
    document.querySelectorAll('[data-clients]').forEach((select) => {
        const placeholder = select.options[0].textContent;
        select.innerHTML = `<option value="">${esc(placeholder)}</option>` + state.catalog.clientes.map((c) => `<option value="${esc(c.id)}">${esc(c.codigo)} · ${esc(c.nombre)}</option>`).join('');
    });
    $('slSeason').textContent = `Temporada activa · ${state.catalog.temporada.nombre}`;
    $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden'); await list();
}
$('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const response = await fetch('/api/acceso-oficina', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(event.target))) });
        const data = await response.json(); if (!response.ok) throw new Error(data.message);
        state.token = data.token; localStorage.setItem('estiba_wms_office_token', state.token); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(data.usuario)); await enter();
    } catch (e) { $('officeLoginError').textContent = e.message; }
});
$('slNew').onclick = () => { if (!state.busy) { state.detailRevision++; editor(); } };
$('slClose').onclick = () => { if (!state.busy) { state.detailRevision++; $('slEditor').classList.add('is-hidden'); } };
$('slFilters').onsubmit = (e) => { e.preventDefault(); run(() => list()); };
$('slRows').onclick = (e) => {
    const id = e.target.closest('[data-open]')?.dataset.open; if (!id || state.busy) return;
    const revision = ++state.detailRevision;
    run(async () => { const detail = await api(`/${id}`); if (revision === state.detailRevision) editor(detail); });
};
$('slDestinationSearch').oninput = destinations;
field('definitivo').onchange = () => { field('resolucion').required = Boolean(state.detail) || field('definitivo').checked; };
field('todos_destinos').onchange = destinations; field('estado').onchange = destinations;
$('slDestinations').onchange = (e) => {
    const key = e.target.dataset.destination; if (!key) return;
    const [tipo, id] = key.split('|'); if (e.target.checked) state.targets.set(key, { tipo, id }); else state.targets.delete(key); destinations();
};
$('slExpand').onclick = () => {
    if (!state.detail) { $('slError').textContent = 'Guarda primero el seguimiento para desglosar sus restricciones por país.'; return; }
    field('todos_destinos').checked = false;
    state.targets = new Map(state.detail.paises_restringidos.map((id) => [`pais|${id}`, { tipo: 'pais', id }]));
    destinations();
};
form.onsubmit = (e) => {
    e.preventDefault(); if (state.busy) return;
    run(async () => {
        state.busy = true; $('slSave').disabled = true; $('slError').textContent = '';
        try {
            const r = state.detail?.registro; const released = field('estado').value === 'liberado';
            const data = Object.fromEntries(new FormData(form)); delete data.cliente_id; delete data.numero_lote;
            data.todos_destinos = !released && field('todos_destinos').checked;
            data.destinos = released || data.todos_destinos ? [] : [...state.targets.values()];
            data.definitivo = field('definitivo').checked;
            data.instruccion_segregacion = released ? null : field('instruccion_segregacion').value.trim() || null;
            if (r) data.version = r.version; else { data.cliente_id = field('cliente_id').value; data.numero_lote = field('numero_lote').value.trim(); }
            const fingerprint = JSON.stringify(data);
            if (state.pending?.fingerprint !== fingerprint) state.pending = { fingerprint, operacion_id: operationId() };
            const detail = await api(r ? `/${r.id}` : '', { method: r ? 'PUT' : 'POST', body: JSON.stringify({ ...data, operacion_id: state.pending.operacion_id }) });
            editor(detail); await list(); $('slMessage').textContent = 'Seguimiento SAG guardado. Las alertas se actualizan en la operación.';
        } finally { state.busy = false; $('slSave').disabled = false; }
    });
};
document.querySelectorAll('[data-report]').forEach((button) => { button.onclick = () => run(async () => {
    const r = state.detail?.registro; if (!r) return;
    const response = await fetch(`${url}/${r.id}/archivo/${button.dataset.report}`, { headers: { Authorization: `Bearer ${state.token}` } });
    if (!response.ok) throw new Error('No se pudo descargar el antecedente SAG.');
    const object = URL.createObjectURL(await response.blob()); const a = document.createElement('a');
    a.href = object; a.download = `SAG-lote-${r.numero_lote.replace(/[^A-Za-z0-9_-]/g, '-')}-v${r.version}.${button.dataset.report}`; a.click(); setTimeout(() => URL.revokeObjectURL(object), 1000);
}); });
if (state.token) run(enter);
