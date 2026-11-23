import { byId, escapeHtml as esc, message, officeSession } from './shared/packed-fruit-office-session.js';
import { officeCode, officeStatus } from './shared/folios-display.js';
import { createUuid } from './shared/uuid.js';

const api = '/api/despacho-nacional-pt';
const format = new Intl.NumberFormat('es-CL');
const dateTime = new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' });
const search = byId('nationalSearch');
const separation = byId('nationalSeparationForm');
const dispatch = byId('nationalDispatchForm');
const view = byId('nationalView');
const rows = byId('nationalRows');
const lookupSubmit = search.querySelector('button');
const separateSubmit = separation.querySelector('button');
const dispatchSubmit = dispatch.querySelector('button');
const selected = new Map();
let folio = null;
let listed = [];
let page = 1;
let permissions = {};
let listVersion = 0;
let sessionVersion = 0;
let writing = false;

const notify = (text, error = false) => message(text, error, 'nationalNotice');
const nationalError = (id, text = '') => { byId(id).textContent = text; };

function reset() {
    sessionVersion += 1;
    listVersion += 1;
    folio = null;
    listed = [];
    page = 1;
    permissions = {};
    writing = false;
    selected.clear();
    for (const form of [search, separation, dispatch]) {
        form.reset();
        delete form.dataset.operation;
        delete form.dataset.payload;
    }
    lookupSubmit.disabled = false;
    separateSubmit.disabled = false;
    view.value = 'pendiente';
    rows.replaceChildren();
    byId('nationalComponents').replaceChildren();
    byId('nationalSeparation').classList.add('is-hidden');
    separation.classList.add('is-hidden');
    dispatch.classList.add('is-hidden');
    nationalError('nationalListError');
    nationalError('nationalSearchError');
}

const session = officeSession('puede_consultar_catalogo_cargas', () => loadList(), reset, { messageId: 'nationalNotice' });

function selectionSummary() {
    const boxes = [...selected.values()].reduce((total, row) => total + row.cajas, 0);
    byId('nationalSelection').textContent = `${format.format(selected.size)} partidas seleccionadas · ${format.format(boxes)} cajas`;
    dispatchSubmit.disabled = writing || !selected.size || selected.size > 100;
}

async function loadList() {
    const version = ++listVersion;
    const pending = view.value === 'pendiente';
    nationalError('nationalListError');
    byId('nationalList').setAttribute('aria-busy', 'true');
    byId('nationalCount').textContent = 'Consultando partidas…';
    byId('nationalPrevious').disabled = true;
    byId('nationalNext').disabled = true;
    try {
        const data = await session.request(`${api}?estado=${view.value}&page=${page}`);
        if (version !== listVersion) return;
        if (page > data.meta.paginas) {
            page = data.meta.paginas;
            return await loadList();
        }
        permissions = data.permisos;
        listed = data.data;
        byId('nationalSeparation').classList.toggle('is-hidden', !permissions.separar);
        dispatch.classList.toggle('is-hidden', !pending || !permissions.despachar);
        byId('nationalPending').textContent = format.format(data.resumen.partidas_pendientes);
        byId('nationalBoxes').textContent = format.format(data.resumen.cajas_pendientes);
        byId('nationalCount').textContent = `${format.format(data.meta.total)} partidas`;
        byId('nationalPage').textContent = `Página ${data.meta.pagina} de ${data.meta.paginas}`;
        byId('nationalPrevious').disabled = page <= 1;
        byId('nationalNext').disabled = page >= data.meta.paginas;
        renderRows(pending);
        selectionSummary();
    } catch (error) {
        if (version !== listVersion) return;
        rows.replaceChildren();
        selected.clear();
        selectionSummary();
        byId('nationalCount').textContent = 'Lectura no disponible';
        nationalError('nationalListError', error.message);
    } finally {
        if (version === listVersion) byId('nationalList').setAttribute('aria-busy', 'false');
    }
}

function componentName(line) {
    return [line.csg, line.lote_materia_prima ? `Lote ${line.lote_materia_prima}` : null,
        line.fecha_embalaje, line.variedad, line.calibre, line.envase, line.marca].filter(Boolean).join(' · ');
}

function renderRows(pending) {
    const canSelect = pending && permissions.despachar;
    const columns = [
        ...(canSelect ? ['Seleccionar'] : []),
        'Folio original', 'Folio nacional', 'Cajas', 'Motivo', pending ? 'Separación' : 'Salida',
    ];
    byId('nationalColumns').innerHTML = `<tr>${columns.map((column) => `<th scope="col">${column}</th>`).join('')}</tr>`;
    rows.innerHTML = listed.map((partida) => {
        const { snapshot, despacho, usuario, created_at: created } = partida;
        const time = despacho?.despachado_at ?? created;
        return `<tr>
            ${canSelect ? `<td><input type="checkbox" value="${esc(partida.id)}" aria-label="Seleccionar partida ${esc(snapshot.folio_nacional)}"
                ${selected.has(partida.id) ? 'checked' : ''} ${writing ? 'disabled' : ''}></td>` : ''}
            <td><span class="folios-code">${officeCode(snapshot.folio_origen, esc)}</span>
                <small>${esc(snapshot.cliente ?? '—')} · ${esc(partida.temporada?.codigo ?? '—')}</small></td>
            <td><span class="folios-code">${officeCode(snapshot.folio_nacional, esc)}</span>
                <details>
                    <summary>Ver composición</summary>
                    <ul>${(snapshot.composicion ?? []).map((line) => `<li>${esc(componentName(line))} · ${format.format(line.cantidad_cajas)} cajas</li>`).join('')}</ul>
                </details>
            </td>
            <td><strong>${format.format(partida.cajas)}</strong></td>
            <td>${esc(partida.motivo)}</td>
            <td>${pending ? officeStatus('Pendiente', 'pendiente', esc) : officeStatus('Despachada', 'listo', esc)}
                <small><time datetime="${esc(time)}">${dateTime.format(new Date(time))}</time> · ${esc(despacho?.usuario?.name ?? usuario?.name ?? '—')}</small>
                ${despacho ? `<small>${esc(despacho.destinatario)} · Guía ${esc(despacho.guia)}${despacho.patente ? ` · ${esc(despacho.patente)}` : ''}</small>` : ''}</td>
        </tr>`;
    }).join('') || `<tr><td colspan="${columns.length}"><span class="folios-bandeja__estado">${pending
        ? 'Sin partidas pendientes de despacho.' : 'Todavía no hay salidas registradas.'}</span></td></tr>`;
}

function quantities() {
    return [...separation.querySelectorAll('[data-component]')].map((input) => Number(input.value) || 0);
}

function showFolio() {
    const location = folio.ubicacion ? [folio.ubicacion.camara, folio.ubicacion.posicion].filter(Boolean).join(' · ') : 'Sin posición registrada';
    byId('nationalOrigin').textContent = [folio.numero_folio, folio.cliente, location].filter(Boolean).join(' · ');
    byId('nationalComponents').innerHTML = folio.composicion.map((line, index) => {
        const label = esc(componentName(line));
        return `<label><strong>${label}</strong><span>${format.format(line.cantidad_cajas)} cajas en este componente</span>
            <input type="number" min="0" max="${line.cantidad_cajas}" step="1" value="0" data-component="${index}" aria-label="Cajas a separar de ${label}"></label>`;
    }).join('');
    separation.reset();
    separation.classList.remove('is-hidden');
    updateBalance();
}

function updateBalance() {
    const count = quantities().reduce((total, value) => total + value, 0);
    byId('nationalBalance').textContent = `${format.format(count)} cajas a Nacional · ${format.format(folio.cajas - count)} quedan en el folio original`;
    separateSubmit.disabled = writing || count < 1;
}

async function register(form, path, payload, onSuccess, errorId) {
    if (writing) return;
    const version = sessionVersion;
    writing = true;
    const fingerprint = JSON.stringify(payload);
    let result;
    nationalError(errorId);
    const controls = [...form.elements].filter((control) => !control.disabled);
    controls.forEach((control) => { control.disabled = true; });
    lookupSubmit.disabled = true;
    rows.querySelectorAll('input').forEach((input) => { input.disabled = true; });
    try {
        if (form.dataset.payload !== fingerprint) {
            form.dataset.operation = createUuid();
            form.dataset.payload = fingerprint;
        }
        result = await session.request(path, { method: 'POST', body: JSON.stringify({ ...payload, operacion_id: form.dataset.operation }) });
    } catch (error) {
        if (version === sessionVersion) nationalError(errorId, error.message);
    } finally {
        controls.forEach((control) => { control.disabled = false; });
        if (version === sessionVersion) {
            writing = false;
            lookupSubmit.disabled = false;
            rows.querySelectorAll('input').forEach((input) => { input.disabled = false; });
            if (folio) updateBalance();
            selectionSummary();
        }
    }
    if (!result || version !== sessionVersion) return;
    form.reset();
    delete form.dataset.operation;
    delete form.dataset.payload;
    onSuccess(result.data);
    page = 1;
    await loadList();
}

search.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (writing || lookupSubmit.disabled) return;
    const version = sessionVersion;
    lookupSubmit.disabled = true;
    separation.classList.add('is-hidden');
    folio = null;
    nationalError('nationalSearchError');
    try {
        const params = new URLSearchParams(new FormData(search));
        const result = await session.request(`${api}/folio?${params}`);
        if (version !== sessionVersion) return;
        folio = result.data;
        showFolio();
        separation.querySelector('[data-component]').focus();
    } catch (error) {
        if (version === sessionVersion) nationalError('nationalSearchError', error.status === 404 ? 'No se encontró ese folio.' : error.message);
    } finally {
        if (version === sessionVersion) lookupSubmit.disabled = false;
    }
});

separation.addEventListener('input', updateBalance);
separation.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!folio) return;
    const values = new FormData(separation);
    const composicion = quantities().map((cajas, index) => ({ clave: folio.composicion[index].clave, cajas })).filter((line) => line.cajas > 0);
    if (!composicion.length) return;
    void register(separation, `${api}/partidas`, {
        folio_id: folio.id, cajas_esperadas: folio.cajas, composicion,
        motivo: values.get('motivo').trim(), confirmado: values.has('confirmado'),
    }, (partida) => {
        folio = null;
        separation.classList.add('is-hidden');
        search.reset();
        search.elements.numero.focus();
        notify(`${format.format(partida.cajas)} cajas separadas en el folio ${partida.snapshot.folio_nacional}.`);
    }, 'nationalSearchError');
});

rows.addEventListener('change', (event) => {
    const input = event.target;
    if (input.type !== 'checkbox') return;
    if (input.checked) selected.set(input.value, listed.find((row) => row.id === input.value));
    else selected.delete(input.value);
    dispatch.elements.confirmado.checked = false;
    selectionSummary();
});

dispatch.addEventListener('submit', (event) => {
    event.preventDefault();
    if (view.value !== 'pendiente' || !selected.size || selected.size > 100) return;
    const values = new FormData(dispatch);
    void register(dispatch, `${api}/despachos`, {
        partidas: [...selected.keys()].sort(),
        destinatario: values.get('destinatario').trim(),
        guia: values.get('guia').trim(),
        patente: values.get('patente').trim(),
        confirmado: values.has('confirmado'),
    }, (departure) => {
        selected.clear();
        selectionSummary();
        notify(`Salida confirmada. Guía ${departure.guia} · ${departure.destinatario}.`);
    }, 'nationalListError');
});

view.addEventListener('change', () => {
    page = 1;
    selected.clear();
    dispatch.elements.confirmado.checked = false;
    selectionSummary();
    void loadList();
});
byId('nationalPrevious').addEventListener('click', () => { page -= 1; void loadList(); });
byId('nationalNext').addEventListener('click', () => { page += 1; void loadList(); });
session.start();
