import { officeCode, officeStatus } from './shared/folios-display.js';
import { SAG_LOT_STATES as states } from './shared/sag-lot-alerts.js';

const $ = (id) => document.getElementById(id);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function operationId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}
const state = { token: localStorage.getItem('estiba_wms_office_token'), catalog: null, detail: null, targets: new Map(), pending: null, busy: false, listRevision: 0, detailRevision: 0 };
const form = $('slForm');
const field = (name) => form.elements.namedItem(name);
const url = '/api/sag/lotes-retenidos';
const date = (value) => value ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' }).format(new Date(value)) : '—';
const labelDestinations = (r) => r.todos_destinos ? 'Todos los destinos' : r.destinos.map((d) => d.nombre).join(' · ') || 'Sin restricciones de destino';
const partial = 'liberado_con_restricciones';
const lotGlyphs = { con_observaciones: 'atencion', retenido: 'bloqueado', [partial]: 'atencion', liberado: 'listo' };
const lotStatus = (record) => officeStatus(states[record.estado] || 'Estado sin informar', lotGlyphs[record.estado], esc);
const plantDay = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'America/Santiago' });

function daysSince(value, today) {
    const detected = String(value ?? '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(detected)) return null;
    const elapsed = (Date.parse(today) - Date.parse(detected)) / 86_400_000;
    return Number.isFinite(elapsed) ? Math.max(0, elapsed) : null;
}

function message(text = '') {
    $('slMessage').textContent = text;
    $('slMessage').hidden = !text;
}

function listState(title, detail = '') {
    return `<tr><td colspan="5"><div class="folios-bandeja__estado"><strong>${title}</strong>${detail ? `<span>${detail}</span>` : ''}</div></td></tr>`;
}

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
    try { await action(); } catch (e) { $('slError').textContent = e.message; message(e.message); }
}
async function list(page = 1) {
    const revision = ++state.listRevision;
    const query = new URLSearchParams(new FormData($('slFilters'))); query.set('page', page);
    const rows = $('slRows');
    rows.setAttribute('aria-busy', 'true');
    rows.innerHTML = listState('Consultando lotes…');
    $('slResults').textContent = '';
    $('slPages').replaceChildren();
    let data;
    try {
        data = await api(`?${query}`);
    } catch (error) {
        if (revision !== state.listRevision) return;
        rows.removeAttribute('aria-busy');
        rows.innerHTML = listState('Bandeja sin lectura', 'Usa Buscar / actualizar para volver a consultar.');
        throw error;
    }
    if (revision !== state.listRevision) return;
    rows.removeAttribute('aria-busy');
    const today = plantDay.format(new Date());
    const ages = data.data.map((record) => daysSince(record.fecha_deteccion, today));
    const maxAge = Math.max(1, ...ages);
    rows.innerHTML = data.data.map((record, index) => {
        const age = ages[index];
        const ageDetail = age === null ? '' : `<small>Detectado ${age === 0 ? 'hoy' : `hace ${age} ${age === 1 ? 'día' : 'días'}`}</small>
            <span class="sl-age" aria-hidden="true"><i style="inline-size:${age / maxAge * 100}%"></i></span>`;
        return `<tr>
            <td><span class="folios-code">${officeCode(record.numero_lote, esc, 5)}</span><small>${esc(record.cliente?.nombre)}</small></td>
            <td>${lotStatus(record)}<small>${record.definitivo ? 'Resultado definitivo' : 'En seguimiento'}</small></td>
            <td>${esc(labelDestinations(record))}</td>
            <td>${ageDetail}<small>Actualizado ${esc(date(record.updated_at))} · v${esc(record.version)}</small></td>
            <td><button type="button" class="secondary-button" data-open="${esc(record.id)}" aria-label="Abrir lote ${esc(record.numero_lote)}">Abrir lote</button></td>
        </tr>`;
    }).join('') || listState('Sin lotes para esta búsqueda', 'Los controles registrados aparecerán aquí. Cambia los filtros para ampliar la búsqueda.');
    const total = data.total ?? data.data.length;
    $('slResults').textContent = `${total} ${total === 1 ? 'lote' : 'lotes'}`;
    for (const [text, target] of [['Anterior', page - 1], ['Siguiente', page + 1]]) {
        const button = document.createElement('button'); button.className = 'secondary-button'; button.textContent = text;
        button.disabled = target < 1 || target > data.last_page; button.onclick = () => run(() => list(target)); $('slPages').append(button);
    }
}
function destinationChoices() {
    return [
        ...state.catalog.bloques.map((d) => ({ tipo: 'bloque', id: d.id, label: `Bloque ${d.codigo} · ${d.nombre}` })),
        ...state.catalog.paises.map((d) => ({ tipo: 'pais', id: d.id, label: `${d.iso_alpha2} · ${d.nombre_es}` })),
    ];
}
// Con pocos destinos marcados se nombran: en una lista de ~250 cuesta comprobar cuáles quedaron.
function selectedSummary() {
    const count = state.targets.size;
    const text = `${count} ${count === 1 ? 'país / bloque seleccionado' : 'países / bloques seleccionados'}`;
    if (count === 0 || count > 8) return `${text}.`;
    const names = destinationChoices().filter((d) => state.targets.has(`${d.tipo}|${d.id}`)).map((d) => d.label);
    return `${text}: ${names.join(', ')}.`;
}
function destinations() {
    const all = field('todos_destinos').checked;
    const released = field('estado').value === 'liberado';
    const search = $('slDestinationSearch').value.trim().toLocaleLowerCase('es');
    const choices = destinationChoices().filter((d) => !search || d.label.toLocaleLowerCase('es').includes(search));
    $('slDestinations').innerHTML = choices.map((d) => {
        const key = `${d.tipo}|${d.id}`;
        return `<label class="sl-check"><input type="checkbox" data-destination="${esc(key)}"${state.targets.has(key) ? ' checked' : ''}${all || released ? ' disabled' : ''}>${esc(d.label)}</label>`;
    }).join('');
    field('todos_destinos').disabled = released;
    $('slClear').disabled = released || (!all && state.targets.size === 0);
    $('slExpand').disabled = released;
    $('slSelected').textContent = released ? 'La liberación elimina las restricciones de este lote.' : all ? 'Despacho restringido a todos los destinos.' : selectedSummary();
}
function selectRestrictionScope() {
    if (field('estado').value === 'retenido' || field('estado').value === partial) {
        field('estado').value = field('todos_destinos').checked ? 'retenido' : partial;
    }
    destinations();
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
    $('slTitle').innerHTML = r ? `Lote <span class="folios-code">${officeCode(r.numero_lote, esc, 5)}</span>` : 'Registrar lote de recepción';
    $('slClient').textContent = r?.cliente?.nombre || '';
    $('slVersion').textContent = r ? `VERSIÓN ${r.version} · ${r.definitivo ? 'RESULTADO DEFINITIVO' : 'EN SEGUIMIENTO'}` : '';
    $('slRecordState').innerHTML = r ? lotStatus(r) : '';
    $('slSave').textContent = r ? 'Guardar resolución / seguimiento' : 'Registrar seguimiento';
    if (r) {
        for (const name of ['cliente_id', 'numero_lote', 'estado', 'observacion', 'referencia_informe', 'instruccion_segregacion']) field(name).value = r[name] || '';
        field('fecha_deteccion').value = r.fecha_deteccion.slice(0, 10);
        field('todos_destinos').checked = r.todos_destinos; field('definitivo').checked = r.definitivo;
        state.targets = new Map(r.destinos.map((d) => [`${d.tipo}|${d.id}`, { tipo: d.tipo, id: d.id }]));
        const active = detail.folios.filter((f) => f.activo);
        $('slTotals').textContent = active.length
            ? `${active.length} folios activos · ${active.reduce((n, f) => n + f.cajas_lote, 0)} cajas del lote en planta.`
            : 'Sin cajas del lote en planta.';
        $('slFolios').innerHTML = detail.folios.map((f) => `<tr>
            <td><span class="folios-code">${officeCode(f.folio, esc)}</span></td>
            <td>${esc(f.cajas_lote)} / ${esc(f.cajas_pallet)}</td>
            <td><span class="folios-code">${esc(f.csg)}</span></td>
            <td>${esc(f.ubicacion || f.estado)}<small>${esc(f.estado)}</small></td>
            <td><span class="folios-code">${f.carga ? officeCode(f.carga, esc, 3) : 'Sin carga'}</span></td>
        </tr>`).join('') || '<tr><td colspan="5">Aún no hay folios con cajas de este cliente y lote. Los que ingresen después heredarán el control.</td></tr>';
        $('slHistory').innerHTML = detail.historial.map((event) => `<li class="folios-ticket sl-history">
            <div class="folios-ticket__stub"><span>v${esc(event.version)}</span></div>
            <div class="sl-history__body">
                <small>${esc(date(event.fecha.replace(' ', 'T') + 'Z'))} · ${esc(event.usuario)}</small>
                <p>${lotStatus(event.despues)} · ${esc(labelDestinations(event.despues))}</p>
                <p>${esc(event.despues.resolucion || event.despues.observacion)}</p>
                <small>${esc(event.folios_afectados.length)} folios identificados al registrar. ${esc(event.despues.instruccion_segregacion || '')}</small>
            </div>
        </li>`).join('') || '<li>Aún no hay resoluciones registradas.</li>';
    }
    $('slDestinationSearch').value = ''; destinations();
}
async function enter() {
    state.catalog = await api('/catalogo');
    document.querySelectorAll('[data-clients]').forEach((select) => {
        const placeholder = select.options[0].textContent;
        select.innerHTML = `<option value="">${esc(placeholder)}</option>` + state.catalog.clientes.map((c) => `<option value="${esc(c.id)}">${esc(c.codigo)} · ${esc(c.nombre)}</option>`).join('');
    });
    $('slNew').disabled = false;
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
let detailTrigger = null;
$('slNew').onclick = () => {
    if (state.busy) return;
    state.detailRevision++;
    detailTrigger = $('slNew');
    editor();
    $('slTitle').focus();
};
$('slClose').onclick = () => {
    if (state.busy) return;
    state.detailRevision++;
    $('slEditor').classList.add('is-hidden');
    (detailTrigger?.isConnected ? detailTrigger : $('slNew')).focus();
};
$('slFilters').onsubmit = (e) => { e.preventDefault(); message(); run(() => state.catalog ? list() : enter()); };
$('slRows').onclick = (e) => {
    const trigger = e.target.closest('[data-open]');
    if (!trigger || state.busy) return;
    const revision = ++state.detailRevision;
    run(async () => {
        const detail = await api(`/${encodeURIComponent(trigger.dataset.open)}`);
        if (revision !== state.detailRevision) return;
        detailTrigger = trigger;
        editor(detail);
        $('slTitle').focus();
    });
};
$('slDestinationSearch').oninput = destinations;
field('definitivo').onchange = () => { field('resolucion').required = Boolean(state.detail) || field('definitivo').checked; };
field('todos_destinos').onchange = selectRestrictionScope;
field('estado').onchange = () => {
    if (field('estado').value === 'retenido' || field('estado').value === partial) {
        field('todos_destinos').checked = field('estado').value === 'retenido';
    }
    destinations();
};
$('slDestinations').onchange = (e) => {
    const key = e.target.dataset.destination; if (!key) return;
    const [tipo, id] = key.split('|'); if (e.target.checked) state.targets.set(key, { tipo, id }); else state.targets.delete(key); destinations();
};
$('slExpand').onclick = () => {
    if (!state.detail) { $('slError').textContent = 'Guarda primero el seguimiento para desglosar sus restricciones por país.'; return; }
    field('todos_destinos').checked = false;
    state.targets = new Map(state.detail.paises_restringidos.map((id) => [`pais|${id}`, { tipo: 'pais', id }]));
    selectRestrictionScope();
};
// Para dejar solo unos pocos destinos: se quitan todos y se buscan y marcan los que siguen restringidos.
$('slClear').onclick = () => {
    field('todos_destinos').checked = false;
    state.targets = new Map();
    $('slDestinationSearch').value = '';
    selectRestrictionScope();
    $('slDestinationSearch').focus();
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
            editor(detail);
            $('slTitle').focus();
            let feedback = 'Seguimiento SAG guardado. Las alertas se actualizan en la operación.';
            try {
                await list();
            } catch {
                feedback += ' La bandeja está sin lectura. Puedes actualizarla.';
            }
            message(feedback);
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
if (state.token) {
    run(async () => {
        $('officeAccess').classList.add('is-hidden');
        $('officeApp').classList.remove('is-hidden');
        try {
            await enter();
        } catch (error) {
            if (!state.catalog) $('slRows').innerHTML = listState('No fue posible cargar el catálogo SAG');
            throw error;
        }
    });
}
