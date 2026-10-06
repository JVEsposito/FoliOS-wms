import { createLabelOperation } from './shared/pt-label-operation.js';
import { installOfficeUnexpectedErrorNotice } from './shared/office-unexpected-error.js';

const byId = (id) => document.getElementById(id);
const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const state = { token: localStorage.getItem(tokenKey), identity: null, rows: [], selected: new Set(), season: null, page: 1, lastPage: 1, busy: false, pdf: null, generation: 0 };
try { state.identity = JSON.parse(localStorage.getItem(identityKey)); } catch { /* La sesión se volverá a solicitar. */ }

function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
const operation = createLabelOperation(uuid);
const message = (text) => { byId('ptMessage').textContent = text; };
installOfficeUnexpectedErrorNotice();

function clearPdf() {
    byId('ptPdfPreview').removeAttribute('src');
    byId('ptPdfOpen').removeAttribute('href');
    byId('ptPdfDownload').removeAttribute('href');
    byId('ptPdfResult').classList.add('is-hidden');
    if (state.pdf) URL.revokeObjectURL(state.pdf);
    state.pdf = null;
}
function clearSession() {
    state.generation++;
    state.token = null; state.identity = null; state.rows = []; state.selected.clear(); operation.clear(); clearPdf();
    localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey);
    byId('officeApp').classList.add('is-hidden'); byId('officeAccess').classList.remove('is-hidden');
    window.dispatchEvent(new CustomEvent('estiba:office-session'));
}
function showApp() {
    byId('officeAccess').classList.add('is-hidden'); byId('officeApp').classList.remove('is-hidden');
    byId('officeUserName').textContent = state.identity?.nombre || 'Oficina';
    byId('officeUserRole').textContent = state.identity?.rol || '';
    byId('officeInitials').textContent = (state.identity?.nombre || 'OF').slice(0, 2).toUpperCase();
    window.dispatchEvent(new CustomEvent('estiba:office-session'));
}
function refreshControls() {
    const missing = state.rows.filter((row) => state.selected.has(row.folio_id))
        .flatMap((row) => row.envases_sin_kilos || []).map((envase) => envase.nombre);
    const warning = byId('ptMissingWeights');
    warning.textContent = missing.length && byId('ptPrintForm').elements.tipo.value === 'planta'
        ? `Falta configurar los kilos por caja de: ${[...new Set(missing)].join(', ')}. La etiqueta imprimirá “—” en kilos netos.` : '';
    warning.classList.toggle('is-hidden', !warning.textContent);
    byId('ptGenerate').disabled = state.busy || state.selected.size === 0;
    byId('ptPrevious').disabled = state.busy || state.page <= 1;
    byId('ptNext').disabled = state.busy || state.page >= state.lastPage;
    byId('ptSelectAll').disabled = state.busy || state.rows.length === 0;
    byId('ptSelectAll').checked = state.rows.length > 0 && state.selected.size === state.rows.length;
    byId('ptSelectAll').indeterminate = state.selected.size > 0 && state.selected.size < state.rows.length;
    byId('ptSelection').textContent = `${state.selected.size} folios seleccionados`;
}
function busy(value) {
    state.busy = value;
    for (const id of ['ptFilters', 'ptPrintForm', 'officeLoginForm']) {
        for (const element of byId(id).elements) element.disabled = value;
    }
    byId('ptRows').querySelectorAll('input, button').forEach((element) => { element.disabled = value; });
    refreshControls();
}
async function request(path, options = {}) {
    const headers = new Headers({ Accept: 'application/json', ...options.headers });
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(path, { ...options, headers });
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) clearSession();
        const error = new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'No fue posible completar la solicitud.');
        error.status = response.status;
        throw error;
    }
    return response;
}
function cell(row, text) {
    const td = document.createElement('td'); td.textContent = text ?? '—'; row.append(td); return td;
}
function date(value) { return value ? new Date(value).toLocaleString('es-CL') : '—'; }
function renderRows() {
    const body = byId('ptRows'); body.replaceChildren();
    for (const data of state.rows) {
        const row = document.createElement('tr');
        const check = document.createElement('input'); check.type = 'checkbox'; check.checked = state.selected.has(data.folio_id); check.setAttribute('aria-label', `Seleccionar folio ${data.numero_folio}`);
        check.disabled = state.busy;
        check.addEventListener('change', () => {
            if (state.busy) return;
            check.checked ? state.selected.add(data.folio_id) : state.selected.delete(data.folio_id);
            clearPdf(); refreshControls();
        });
        cell(row, '').append(check);
        cell(row, `${data.numero_folio} · ${data.tipo_bulto} · ${data.cantidad_cajas} cajas`);
        cell(row, `${data.especie} · ${data.variedad} · ${data.calibre} · ${data.envase} · ${data.categoria}`);
        cell(row, `${data.cliente} · ${data.marca} · CSG ${data.csg || 'Ver composición'}`);
        cell(row, data.origen === 'repaletizaje' ? `REPALETIZADO · F. proceso ${data.fecha_proceso || '—'}` : `PROCESO · ${data.validador || '—'} · ${date(data.validado_at)}`);
        const review = document.createElement('button'); review.type = 'button'; review.className = 'secondary-button'; review.textContent = 'Ver datos'; review.addEventListener('click', () => showReview(data)); cell(row, '').append(review);
        body.append(row);
    }
    if (!state.rows.length) { const row = document.createElement('tr'); cell(row, 'No hay folios vigentes con estos filtros.').colSpan = 6; body.append(row); }
    byId('ptPage').textContent = `Página ${state.page} de ${state.lastPage}`;
    refreshControls();
}
function showReview(data) {
    byId('ptReviewTitle').textContent = `Folio ${data.numero_folio}`;
    const details = byId('ptDetails'); details.replaceChildren();
    for (const [label, value] of [
        ['Origen', data.origen === 'repaletizaje' ? 'REPALETIZADO' : 'PROCESO'], ['F. proceso', data.fecha_proceso], ['Kilos netos', data.kilos_netos?.replace('.', ',')], ['Código envase', data.envase_codigo], ['Temporada', data.temporada], ['Bulto / cajas', `${data.tipo_bulto} / ${data.cantidad_cajas}`],
        ['Especie', data.especie], ['Variedad', data.variedad], ['Calibre', data.calibre], ['Envase', data.envase], ['Categoría', data.categoria],
        ['Cliente', data.cliente], ['Marca', data.marca], ['CSG', data.csg], ['Predio', data.predio], ['Embalaje', data.fecha_embalaje],
        ['Estado', data.estado_operacional.replaceAll('_', ' ')], ['Validador', data.validador], ['Validado', date(data.validado_at)], ['Jornada', `Línea ${data.linea_proceso} / Turno ${data.turno}`],
    ]) {
        const dt = document.createElement('dt'); dt.textContent = label;
        const dd = document.createElement('dd'); dd.textContent = value || '—'; details.append(dt, dd);
    }
    byId('ptComposition').replaceChildren();
    for (const segment of data.composicion) {
        const p = document.createElement('p'); p.textContent = `CSG ${segment.csg} · ${segment.predio || '—'} · ${segment.cantidad_cajas} cajas · Emb. ${segment.fecha_embalaje || '—'}`; byId('ptComposition').append(p);
    }
    byId('ptReview').showModal();
}
async function loadRows(page = 1) {
    const generation = state.generation;
    state.selected.clear(); clearPdf();
    const params = new URLSearchParams();
    for (const element of byId('ptFilters').elements) if (element.name && element.value) params.set(element.name, element.value);
    params.set('page', page); params.set('per_page', '25');
    const data = await (await request(`/api/validacion/etiquetas?${params}`)).json();
    if (generation !== state.generation) return;
    state.rows = data.data; state.season = data.temporada; state.page = data.meta.current_page; state.lastPage = data.meta.last_page;
    byId('ptSeason').textContent = state.season ? `${state.season.codigo} · ${state.season.nombre} · activa` : 'No hay temporada activa';
    renderRows();
}
async function loadHistory() {
    const generation = state.generation;
    const data = await (await request('/api/validacion/etiquetas/historial')).json();
    if (generation !== state.generation) return;
    const body = byId('ptHistory'); body.replaceChildren();
    for (const item of data.data) {
        const row = document.createElement('tr'); cell(row, `${date(item.fecha)} · ${item.usuario}`); cell(row, `${item.tipo} · ${item.copias} copias por folio`); cell(row, item.folios.join(', ')); cell(row, item.motivo || 'Primera generación'); body.append(row);
    }
    if (!data.data.length) { const row = document.createElement('tr'); cell(row, 'Sin generaciones registradas.').colSpan = 4; body.append(row); }
}
async function run(action) {
    if (state.busy) return;
    busy(true); message('Procesando…');
    try { await action(); } catch (error) { message(error.message || 'No se pudo conectar. Revisa la conexión y reintenta.'); } finally { busy(false); }
}
async function initialize() {
    showApp();
    const initial = new URLSearchParams(window.location.search);
    if (initial.get('folio')) byId('ptFilters').elements.folio.value = initial.get('folio');
    await loadRows();
    if (initial.get('folio')) {
        state.selected = new Set(state.rows.filter((row) => row.numero_folio === initial.get('folio')).map((row) => row.folio_id));
        renderRows();
    }
    await loadHistory(); message('Selecciona los folios que vas a etiquetar.');
}
byId('officeLoginForm').addEventListener('submit', (event) => {
    event.preventDefault();
    // FormData se obtiene antes de deshabilitar los campos del formulario.
    const credentials = Object.fromEntries(new FormData(event.currentTarget));
    void run(async () => {
        byId('officeLoginError').textContent = '';
        try {
            const data = await (await request('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(credentials) })).json();
            if (!data.usuario.puede_imprimir_etiquetas_pt) throw new Error('Tu cuenta no tiene acceso a Validación PT.');
            state.token = data.token; state.identity = data.usuario;
            localStorage.setItem(tokenKey, state.token); localStorage.setItem(identityKey, JSON.stringify(state.identity));
            await initialize();
        } catch (error) { byId('officeLoginError').textContent = error.message; throw error; }
    });
});
byId('officeLogoutButton').addEventListener('click', () => {
    void request('/api/acceso-oficina', { method: 'DELETE' }).catch(() => {});
    clearSession();
});
byId('ptFilters').addEventListener('submit', (event) => { event.preventDefault(); void run(async () => { await loadRows(); await loadHistory(); message('Listado actualizado.'); }); });
byId('ptPrevious').addEventListener('click', () => { void run(async () => { await loadRows(state.page - 1); message('Listado actualizado.'); }); });
byId('ptNext').addEventListener('click', () => { void run(async () => { await loadRows(state.page + 1); message('Listado actualizado.'); }); });
byId('ptSelectAll').addEventListener('change', (event) => { state.selected = new Set(event.target.checked ? state.rows.map((row) => row.folio_id) : []); clearPdf(); renderRows(); });
byId('ptReviewClose').addEventListener('click', () => byId('ptReview').close());
byId('ptPrintForm').addEventListener('input', () => { clearPdf(); refreshControls(); });
byId('ptPrintForm').elements.tipo.addEventListener('change', (event) => {
    byId('ptPrintForm').elements.copias.value = event.target.value === 'planta' ? 4 : 1;
    refreshControls();
});
byId('ptPrintForm').addEventListener('submit', (event) => {
    event.preventDefault();
    if (state.busy || !state.selected.size || !state.season) return;
    const values = Object.fromEntries(new FormData(event.currentTarget));
    const payload = operation.prepare({
        temporada_id: state.season.id, tipo: values.tipo, copias: Number(values.copias), motivo_reimpresion: values.motivo_reimpresion.trim() || null,
        folios: state.rows.filter((row) => state.selected.has(row.folio_id)).map((row) => ({ id: row.folio_id, version: row.version })),
    });
    const generation = state.generation;
    void run(async () => {
        let response;
        try { response = await request('/api/validacion/etiquetas', { method: 'POST', headers: { Accept: 'application/pdf' }, body: JSON.stringify(payload) }); }
        catch (error) {
            if (error.status === 409) { state.selected.clear(); renderRows(); }
            throw error;
        }
        const blob = await response.blob();
        if (generation !== state.generation) return;
        clearPdf(); state.pdf = URL.createObjectURL(blob);
        byId('ptPdfPreview').src = state.pdf; byId('ptPdfOpen').href = state.pdf; byId('ptPdfDownload').href = state.pdf;
        byId('ptPdfResult').classList.remove('is-hidden');
        state.selected.clear(); operation.clear(); renderRows();
        message('PDF generado. Ábrelo e imprime a tamaño real.');
        try { await loadHistory(); } catch { message('PDF generado. No se pudo actualizar el historial; usa Buscar / actualizar.'); }
    });
});
window.addEventListener('storage', (event) => { if (event.key === tokenKey && event.newValue !== state.token) clearSession(); });
window.addEventListener('beforeunload', clearPdf);
if (state.token && (state.identity?.puede_imprimir_etiquetas_pt || state.identity?.puede_consultar_validaciones_pallet)) void run(initialize);
