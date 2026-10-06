import { byId, escapeHtml as e, message, officeSession } from './shared/packed-fruit-office-session.js';
import { indexValidationCatalog, createOriginArticleSelector } from '../../mobile/src/domain/validationCatalogIndex.ts';
function uuid() {
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((v) => v.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
const root = '/api/recepciones-fruta-embalada';
const form = byId('receptionForm');
const filter = byId('filterForm');
let options = null, catalog = null, etag = null, index = null;
let draft = null, pallets = [], pending = null, page = 1, lastPage = 1, busy = false, dirty = false;
let selectArticles = () => [];
const session = officeSession('puede_consultar_recepciones_fruta_embalada', async (isCurrent) => {
    const [o, c] = await Promise.all([session.request(`${root}/opciones`), session.request(`${root}/catalogo-pt`, { headers: etag ? { 'If-None-Match': etag } : {} })]);
    if (!isCurrent()) return;
    options = o;
    if (!c.notModified) { catalog = c; etag = c.etag; index = indexValidationCatalog(c); selectArticles = createOriginArticleSelector(index); }
    for (const target of [filter, form]) {
        fillSelect(target.elements.cliente_id, options.clientes, target === filter ? 'Todos' : 'Seleccionar cliente');
        fillSelect(target.elements.planta_origen_id, options.plantas_origen, target === filter ? 'Todas' : 'Seleccionar planta');
    }
    fillSelect(form.elements.validador_id, options.validadores.map((v) => ({ ...v, nombre: v.name })), 'Seleccionar validador');
    fillSelect(form.elements.condicion_sag_id, options.condiciones_sag, 'Sin condición SAG');
    byId('seasonLabel').textContent = options.temporada ? `Temporada: ${options.temporada.nombre}` : 'Sin temporada activa';
    byId('newReception').disabled = !options.temporada || !session.can('puede_gestionar_recepciones_fruta_embalada');
    if (draft || pallets.length) renderPallets();
    await loadList();
}, () => { options = catalog = index = etag = draft = pending = null; pallets = []; dirty = false; byId('receptionEditor').classList.add('is-hidden'); byId('receptionList').replaceChildren(); form.reset(); });
function fillSelect(control, rows, placeholder) {
    const value = control.value;
    control.innerHTML = `<option value="">${e(placeholder)}</option>${rows.map((r) => `<option value="${e(r.id)}">${e(r.nombre)}</option>`).join('')}`;
    control.value = value;
}
const optionHtml = (rows, value, placeholder = 'Seleccionar') => `<option value="">${e(placeholder)}</option>${rows.map(([id, label]) => `<option value="${e(id)}"${String(id) === String(value) ? ' selected' : ''}>${e(label)}</option>`).join('')}`;
const values = (rows, key) => [...new Set(rows.map((r) => r[key]))].sort().map((v) => [v, v]);
const localDateTime = (iso) => { if (!iso) return ''; const date = new Date(iso); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
function origins() {
    const ids = options?.clientes.find((c) => c.id === form.elements.cliente_id.value)?.catalogo_validacion_ids ?? [];
    return catalog?.origenes.filter((o) => o.activo && ids.includes(o.cliente_validacion_id)) ?? [];
}
function field(label, key, type, value, extra = '') { return `<label><span>${e(label)}</span><input data-field="${key}" type="${type}" value="${e(value)}" ${extra}></label>`; }
function select(label, key, rows, value, placeholder) { return `<label><span>${e(label)}</span><select data-field="${key}">${optionHtml(rows, value, placeholder)}</select></label>`; }
function renderPallets() {
    const availableOrigins = origins();
    byId('palletList').innerHTML = pallets.map((p, i) => {
        const available = selectArticles([p.origen_validacion_id].filter(Boolean));
        const species = available.filter((a) => !p.especie || a.especie === p.especie);
        const varieties = species.filter((a) => !p.variedad || a.variedad === p.variedad);
        const packages = varieties.filter((a) => !p.embalaje || a.envase === p.embalaje);
        const matching = packages.filter((a) => !p.calibre || a.calibre === p.calibre);
        if (p.especie && p.variedad && p.embalaje && p.calibre) p.articulo_validacion_id = matching[0]?.id ?? '';
        return `<fieldset class="rfe-pallet" data-row="${i}"${editable() && !pending ? '' : ' disabled'}><legend>Pallet ${i + 1}</legend><div class="rfe-grid">
        ${field('Folio de origen *', 'folio_origen', 'text', p.folio_origen, 'required maxlength="50"')}
        ${select('CSG *', 'origen_validacion_id', availableOrigins.map((o) => [o.id, `${o.csg} · ${o.predio ?? ''} · ${o.marca}`]), p.origen_validacion_id)}
        ${select('Especie *', 'especie', values(available, 'especie'), p.especie)}
        ${select('Variedad *', 'variedad', values(species, 'variedad'), p.variedad)}
        ${select('Embalaje *', 'embalaje', values(varieties, 'envase'), p.embalaje)}
        ${select('Calibre *', 'calibre', values(packages, 'calibre'), p.calibre)}
        ${field('Cajas *', 'cantidad_cajas', 'number', p.cantidad_cajas, 'required min="1" max="100000" step="1"')}
        ${select('Tipo de bulto', 'tipo_bulto', [['pallet', 'Pallet'], ['saldo', 'Saldo']], p.tipo_bulto)}
        ${field('Fecha de proceso de origen *', 'fecha_proceso_origen', 'date', p.fecha_proceso_origen, 'required')}
        ${field('T° de pulpa (°C) *', 'temperatura_pulpa_c', 'number', p.temperatura_pulpa_c, 'required min="-50" max="80" step="0.01"')}
        ${field('CSP', 'csp', 'text', p.csp, 'maxlength="50"')}
        ${select('Condición SAG', 'sag', [['heredar', 'Aplicar condición del encabezado'], ['sin', 'Sin condición SAG'], ...options.condiciones_sag.map((s) => [s.id, s.nombre])], p.condicion_sag_personalizada ? p.condicion_sag_id ?? 'sin' : 'heredar')}
        </div>${p.folio_repetido ? '<p class="rfe-warning" data-folio-warning>Se asignará folio interno al aceptar</p>' : ''}<button class="secondary-button" type="button" data-remove="${i}">Quitar pallet</button></fieldset>`;
    }).join('');
    byId('totals').textContent = `${pallets.length} bultos · ${pallets.reduce((sum, p) => sum + Number(p.cantidad_cajas || 0), 0)} cajas`;
    refreshLocks();
}
function editable() { return session.can('puede_gestionar_recepciones_fruta_embalada') && (!draft || draft.editable); }
function refreshLocks() {
    const locked = !editable() || busy || !!pending;
    byId('headerFields').disabled = locked; byId('addPallet').disabled = locked;
    byId('saveReception').disabled = locked; byId('newReception').disabled = busy || !!pending || !options?.temporada || !session.can('puede_gestionar_recepciones_fruta_embalada');
    byId('retrySave').classList.toggle('is-hidden', !pending || busy);
    byId('reloadButton').disabled = busy || !!pending;
    byId('palletList').querySelectorAll('fieldset').forEach((f) => { f.disabled = locked; });
}
async function loadList() {
    const params = new URLSearchParams([...new FormData(filter)].filter(([, value]) => value)); params.set('page', page);
    const data = await session.request(`${root}?${params}`);
    lastPage = data.last_page;
    byId('receptionList').innerHTML = data.data.map((r) => `<tr><td>${e(new Date(r.recepcion_at).toLocaleString('es-CL'))}</td><td>${e(r.cliente?.nombre)}</td><td>${e(r.planta_origen?.nombre)}</td><td>${e(r.numero_guia)}</td><td>${e(r.estado)}</td><td>${r.pallets_count}</td><td><button class="secondary-button" data-open="${e(r.id)}">Ver detalle</button></td></tr>`).join('') || '<tr><td colspan="7">No hay recepciones para estos filtros.</td></tr>';
    byId('pageLabel').textContent = `Página ${data.current_page} de ${lastPage}`;
    byId('previousPage').disabled = page <= 1; byId('nextPage').disabled = page >= lastPage;
}
function canLeave() { return !pending && !busy && (!dirty || window.confirm('Hay cambios sin guardar. ¿Descartarlos?')); }
function openDraft(data = null) {
    draft = data; pallets = data?.pallets.map((p) => ({ ...p })) ?? []; form.reset(); dirty = false;
    for (const key of Object.keys(data ?? {})) if (form.elements[key]?.tagName) form.elements[key].value = String(data[key] ?? '');
    form.elements.recepcion_at.value = localDateTime(data?.recepcion_at ?? new Date().toISOString());
    form.elements.salida_at.value = localDateTime(data?.salida_at);
    if (!data) form.elements.turno.value = 'A';
    byId('editorTitle').textContent = data ? `Guía ${data.numero_guia} · ${data.estado}` : 'Nueva recepción';
    byId('receptionEditor').classList.remove('is-hidden'); byId('guideWarning').classList.add('is-hidden');
    renderPallets(); byId('receptionEditor').scrollIntoView({ behavior: 'smooth' });
}
async function checkGuide() {
    const fields = Object.fromEntries(['cliente_id', 'planta_origen_id', 'numero_guia'].map((key) => [key, form.elements[key].value]));
    if (Object.values(fields).some((v) => !v)) return { duplicada: false };
    if (draft) fields.excluir_id = draft.id;
    const data = await session.request(`${root}/revisar-guia?${new URLSearchParams(fields)}`);
    byId('guideWarning').textContent = data.mensaje ?? ''; byId('guideWarning').classList.toggle('is-hidden', !data.duplicada);
    return data;
}
form.addEventListener('input', () => { dirty = true; });
form.addEventListener('change', (event) => {
    if (event.target === form.elements.cliente_id) { for (const p of pallets) { p.origen_validacion_id = ''; p.articulo_validacion_id = ''; p.especie = p.variedad = p.embalaje = p.calibre = ''; } renderPallets(); }
    if (['cliente_id', 'planta_origen_id', 'numero_guia'].includes(event.target.name)) void checkGuide().catch((error) => message(error.message, true));
});
byId('palletList').addEventListener('input', (event) => {
    const control = event.target; const i = Number(control.closest('[data-row]')?.dataset.row); if (!control.dataset.field || !pallets[i]) return;
    const key = control.dataset.field; const p = pallets[i];
    if (key === 'sag') { p.condicion_sag_personalizada = control.value !== 'heredar'; p.condicion_sag_id = ['sin', 'heredar'].includes(control.value) ? null : control.value; }
    else p[key] = control.value;
    if (['origen_validacion_id', 'especie', 'variedad', 'embalaje', 'calibre'].includes(key)) {
        const order = ['origen_validacion_id', 'especie', 'variedad', 'embalaje', 'calibre'];
        for (const next of order.slice(order.indexOf(key) + 1)) p[next] = ''; p.articulo_validacion_id = ''; renderPallets();
    }
    dirty = true;
});
byId('palletList').addEventListener('focusout', async (event) => {
    if (event.target.dataset.field !== 'folio_origen') return;
    const i = Number(event.target.closest('[data-row]').dataset.row); const p = pallets[i]; const value = p.folio_origen.trim().toUpperCase(); if (!value) return;
    try { const data = await session.request(`${root}/revisar-folio?${new URLSearchParams({ folio_origen: value })}`); if (p !== pallets[i]) return; p.folio_origen = value; p.folio_repetido = data.repetido || pallets.some((other) => other !== p && other.folio_origen.trim().toUpperCase() === value); if (p.folio_repetido) message('Se asignará folio interno al aceptar'); const fieldset = byId('palletList').querySelector(`[data-row="${i}"]`); fieldset?.querySelector('[data-folio-warning]')?.remove(); if (p.folio_repetido && fieldset) { const note = document.createElement('p'); note.className = 'rfe-warning'; note.dataset.folioWarning = ''; note.textContent = 'Se asignará folio interno al aceptar'; fieldset.append(note); } }
    catch (error) { message(error.message, true); }
});
byId('palletList').addEventListener('click', (event) => { const control = event.target.closest('[data-remove]'); if (!control) return; pallets.splice(Number(control.dataset.remove), 1); dirty = true; renderPallets(); });
byId('addPallet').onclick = () => { pallets.push({ folio_origen: '', tipo_bulto: 'pallet', origen_validacion_id: '', articulo_validacion_id: '', especie: '', variedad: '', embalaje: '', calibre: '', csp: '', cantidad_cajas: '', fecha_proceso_origen: new Date().toLocaleDateString('en-CA'), temperatura_pulpa_c: '', condicion_sag_personalizada: false, condicion_sag_id: null }); dirty = true; renderPallets(); };
async function submitPending() {
    busy = true; refreshLocks(); message('Guardando…');
    try {
        const result = await session.request(`${root}${pending.id ? `/${pending.id}` : ''}`, { method: pending.id ? 'PUT' : 'POST', body: JSON.stringify(pending.payload) });
        pending = null; openDraft(result.data); message('Borrador guardado.'); await loadList();
    } catch (error) {
        // Una respuesta HTTP rechaza el intento; un corte de red puede ocurrir tras el commit.
        if (error.status >= 400 && error.status < 500) pending = null;
        message(error.status === 409 ? `${error.message} Vuelve a abrir el detalle para recuperar la versión actual.` : error.message, true);
    } finally { busy = false; refreshLocks(); }
}
form.addEventListener('submit', async (event) => {
    event.preventDefault(); if (busy || pending || !editable()) return;
    busy = true; refreshLocks();
    try {
        const review = await checkGuide();
        if (review.duplicada && !window.confirm(`${review.mensaje}\n¿Guardar de todas formas?`)) return;
        const payload = Object.fromEntries(new FormData(form));
        // El fieldset se bloqueó durante el aviso; toma la cabecera directamente de sus controles.
        for (const control of byId('headerFields').querySelectorAll('[name]')) payload[control.name] = control.value || null;
        payload.temporada_id = draft?.temporada_id ?? options.temporada.id;
        payload.validador_id = Number(payload.validador_id); payload.llega_con_prefrio = payload.llega_con_prefrio === 'true';
        payload.recepcion_at = new Date(payload.recepcion_at).toISOString(); payload.salida_at = payload.salida_at ? new Date(payload.salida_at).toISOString() : null;
        payload.operacion_id = uuid(); payload.confirmar_guia_duplicada = review.duplicada;
        if (draft) payload.version_conocida = draft.version;
        payload.pallets = pallets.map((p) => {
            if (!p.articulo_validacion_id) throw new Error('Completa CSG, especie, variedad, embalaje y calibre de cada pallet.');
            return { ...(p.id ? { id: p.id } : {}), folio_origen: p.folio_origen, tipo_bulto: p.tipo_bulto, articulo_validacion_id: p.articulo_validacion_id, origen_validacion_id: p.origen_validacion_id, csp: p.csp || null, cantidad_cajas: Number(p.cantidad_cajas), fecha_proceso_origen: p.fecha_proceso_origen, temperatura_pulpa_c: p.temperatura_pulpa_c === '' ? null : Number(p.temperatura_pulpa_c), condicion_sag_personalizada: p.condicion_sag_personalizada, condicion_sag_id: p.condicion_sag_id || null };
        });
        pending = { id: draft?.id, payload }; await submitPending();
    } catch (error) { message(error.message, true); } finally { busy = false; refreshLocks(); }
});
byId('retrySave').onclick = () => pending && void submitPending();
byId('newReception').onclick = () => { if (canLeave()) openDraft(); };
byId('receptionList').onclick = async (event) => { const id = event.target.closest('[data-open]')?.dataset.open; if (!id || !canLeave()) return; try { openDraft((await session.request(`${root}/${id}`)).data); } catch (error) { message(error.message, true); } };
filter.onsubmit = (event) => { event.preventDefault(); page = 1; void loadList().catch((error) => message(error.message, true)); };
byId('previousPage').onclick = () => { if (page > 1) { page--; void loadList().catch((error) => message(error.message, true)); } };
byId('nextPage').onclick = () => { if (page < lastPage) { page++; void loadList().catch((error) => message(error.message, true)); } };
window.addEventListener('beforeunload', (event) => { if (dirty || pending) { event.preventDefault(); event.returnValue = ''; } });
session.start();
