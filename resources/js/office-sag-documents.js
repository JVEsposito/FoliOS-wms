import { sagApi, sagEscape as esc, sagNotice, sagState } from './office-sag-counterpart.js';

import { dispatchFields, dispatchRows } from './office-sag-dispatch.js';

let dispatchHeader = {};
let dispatchInstruction = '';
const $ = (id) => document.getElementById(id);
let initialized = false;
let draft = null;
let source = null;
let selected = [];
let selectedDocument = null;
let pendingOperation = null;
const labels = { repaletizaje: 'Repaletizaje', anulacion: 'Anulación / devolución', cambio_mercado: 'Cambio de mercado', despacho: 'Despacho / Multipuerto' };
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
async function run(action) { try { await action(); } catch (error) { sagNotice(error.message, true); } }

function people() {
    const select = $('scDocumentForm').elements.responsable_id;
    const value = select.value;
    select.innerHTML = '<option value="">Seleccionar</option>' + (sagState().configuration?.responsables || []).filter((r) => r.activo).map((r) => `<option value="${esc(r.id)}">${esc(r.nombre)}</option>`).join('');
    select.value = value;
}

async function list(page = 1) {
    const params = new URLSearchParams(new FormData($('scDocumentFilters'))); params.set('page', page);
    const data = await sagApi(`/documentos?${params}`);
    $('scDocuments').innerHTML = data.data.map((d) => `<article><button type="button" class="secondary-button" data-document="${esc(d.id)}">${esc(labels[d.tipo] || d.tipo)} ${esc(d.numero ?? 'Borrador')} · ${esc(d.fecha_actividad.slice(0, 10))} · ${esc(d.estado)}</button></article>`).join('') || '<p>No hay documentos para estos filtros.</p>';
    $('scDocumentPages').innerHTML = `<button class="secondary-button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button><span>${page} / ${data.last_page}</span><button class="secondary-button" data-page="${page + 1}" ${page >= data.last_page ? 'disabled' : ''}>Siguiente</button>`;
}

function lines() {
    const type = $('scDocumentForm').elements.tipo.value;
    const isRepa = type === 'repaletizaje'; const isMarket = type === 'cambio_mercado'; const isDispatch = type === 'despacho';
    $('scRepaSource').classList.toggle('is-hidden', !isRepa && !isMarket && !isDispatch);
    $('scFolioSource').classList.toggle('is-hidden', isRepa || isMarket || isDispatch);
    $('scSourceLabel').textContent = isDispatch ? 'Código de embarque' : isMarket ? 'Número de lote de cambio de mercado' : 'Número REPA';
    $('scLoadRepa').textContent = isDispatch ? 'Cargar embarque' : isMarket ? 'Cargar lote' : 'Cargar repa';
    document.querySelectorAll('[data-market]').forEach((el) => { el.hidden = !isMarket; });
    $('scDispatchFields').classList.toggle('is-hidden', !isDispatch);
    if (isDispatch) { $('scDocumentLines').innerHTML = dispatchRows(selected); return; }
    const rows = isRepa ? source?.originales || [] : selected;
    const input = (l, i, field, label, extra = '') => `<input aria-label="${label} ${esc(l.folio)}" data-line="${i}" data-field="${field}" value="${esc(l[field] ?? '')}" ${extra}>`;
    $('scDocumentLines').innerHTML = `<table><thead><tr><th>Folio</th><th>Especie</th><th>Cajas</th><th>Última actividad</th><th>N° actividad</th>${isMarket ? '<th>Kilos</th><th>Destino aprobado</th><th>Nuevo destino</th>' : isRepa ? '' : '<th>Causal</th><th></th>'}</tr></thead><tbody>${rows.map((l, i) => `<tr><td>${esc(l.folio)}</td><td>${esc(l.especie)}</td><td>${isRepa || isMarket ? esc(l.cantidad) : input(l, i, 'cantidad', 'Cajas', `type="number" min="1" max="${esc(l.maximo || l.cantidad)}"`)}</td><td>${input(l, i, 'tipo_actividad', 'Actividad', 'maxlength="100"')}</td><td>${input(l, i, 'numero_actividad', 'Número actividad', 'maxlength="100"')}</td>${isMarket ? `<td>${input(l, i, 'kilos', 'Kilos', 'type="number" min="0.0001" step="0.0001"')}</td><td>${input(l, i, 'mercados_anteriores', 'Destino aprobado', 'maxlength="1000"')}</td><td>${esc(l.nuevo_destino)}</td>` : isRepa ? '' : `<td>${input(l, i, 'causal', 'Causal', 'maxlength="250" required')}</td><td><button type="button" data-remove="${i}">Quitar</button></td>`}</tr>`).join('')}</tbody></table>${isRepa && source ? `<p>Resultado: ${source.resultados.map((r) => `${esc(r.folio)} (${esc(r.cantidad)} cajas)`).join(', ')}</p>` : ''}`;
}

function edit(document = null, type = 'repaletizaje') {
    draft = document; source = ['repaletizaje', 'cambio_mercado', 'despacho'].includes(document?.tipo) ? structuredClone(document.detalle) : null;
    selected = ['anulacion', 'cambio_mercado', 'despacho'].includes(document?.tipo) ? structuredClone(document.detalle.lineas) : [];
    dispatchHeader = structuredClone(document?.detalle?.transporte || {}); dispatchInstruction = document?.detalle?.instructivo_id || '';
    if (document?.tipo === 'despacho') selected.forEach((l) => { l.incluido = true; });
    $('scDispatchFields').innerHTML = dispatchFields(source, dispatchHeader, dispatchInstruction);
    const form = $('scDocumentForm'); form.reset(); people();
    form.elements.tipo.value = document?.tipo || type; form.elements.tipo.disabled = Boolean(document);
    form.elements.fecha_actividad.value = document?.fecha_actividad.slice(0, 10) || today();
    form.elements.responsable_id.value = document?.responsable_id || '';
    for (const [key, value] of Object.entries(document?.encabezado || {})) if (form.elements[key]) form.elements[key].value = value;
    $('scDocumentTitle').textContent = document ? `Editar ${labels[document.tipo]}` : 'Nuevo documento';
    form.classList.remove('is-hidden'); $('scDocumentDetail').classList.add('is-hidden'); pendingOperation = null; lines();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function save() {
    const form = $('scDocumentForm'); const type = form.elements.tipo.value;
    if (['repaletizaje', 'cambio_mercado', 'despacho'].includes(type) && !source) throw new Error('Carga primero la repa.');
    const data = { tipo: type, version: draft?.version || 0, responsable_id: form.elements.responsable_id.value, fecha_actividad: form.elements.fecha_actividad.value,
        encabezado: { observaciones: form.elements.observaciones.value, programa: form.elements.programa.value, motivo: form.elements.motivo.value, certificados: Number(form.elements.certificados.value), certificados_devueltos: Number(form.elements.certificados_devueltos.value), certificados_asignados: Number(form.elements.certificados_asignados.value) } };
    if (type === 'repaletizaje') { data.repaletizaje_id = source.repaletizaje_id; data.actividades = Object.fromEntries(source.originales.map((l) => [l.folio_id, { tipo: l.tipo_actividad, numero: l.numero_actividad }])); }
    else if (type === 'cambio_mercado') { data.lote_inspeccion_sag_id = source.lote_inspeccion_sag_id; data.kilos = Object.fromEntries(selected.filter((l) => l.kilos !== null && l.kilos !== '').map((l) => [l.folio_id, Number(l.kilos)])); data.mercados_anteriores = Object.fromEntries(selected.map((l) => [l.folio_id, l.mercados_anteriores])); data.actividades = Object.fromEntries(selected.map((l) => [l.folio_id, { tipo: l.tipo_actividad, numero: l.numero_actividad }])); }
    else if (type === 'despacho') { const included = selected.filter((l) => l.incluido); if (!included.length) throw new Error('Selecciona los pallets del documento.'); data.embarque_id = source.embarque_id; data.instructivo_id = dispatchInstruction || null; data.seleccion_folios = [...new Set(included.map((l) => l.folio_id))]; data.encabezado.despacho = dispatchHeader; data.kilos = Object.fromEntries(included.filter((l) => l.kilos !== null && l.kilos !== '').map((l) => [l.linea_id, Number(l.kilos)])); data.origenes = Object.fromEntries(included.map((l) => [l.linea_id, Object.fromEntries(['provincia', 'comuna', 'csg', 'csp'].map((k) => [k, l[k]]))])); }
    else data.folios = selected.map((l) => ({ folio_id: l.folio_id, cantidad: Number(l.cantidad), causal: l.causal, tipo_actividad: l.tipo_actividad, numero_actividad: l.numero_actividad }));
    const signature = JSON.stringify(data); if (!pendingOperation || pendingOperation.signature !== signature) pendingOperation = { signature, id: crypto.randomUUID() };
    data.operacion_id = pendingOperation.id;
    const saved = await sagApi(`/documentos${draft ? `/${draft.id}` : ''}`, { method: draft ? 'PUT' : 'POST', body: JSON.stringify(data) });
    pendingOperation = null; $('scDocumentForm').classList.add('is-hidden'); await open(saved.id); await list(); sagNotice('Borrador guardado.');
}

async function open(id) {
    selectedDocument = await sagApi(`/documentos/${id}`);
    const d = selectedDocument;
    $('scDocumentForm').classList.add('is-hidden');
    const manage = sagState().configuration.puede_gestionar;
    const actions = d.estado === 'borrador' ? ['emitir', 'cancelar'] : ['emitido', 'enviado'].includes(d.estado) ? [...(d.estado === 'emitido' ? ['enviar'] : []), 'aprobar', 'rechazar'] : [];
    $('scDocumentDetail').innerHTML = `<h3>${esc(labels[d.tipo] || d.tipo)} ${esc(d.numero ?? 'Borrador')} · ${esc(d.estado)}</h3><p>Fecha actividad: ${esc(d.fecha_actividad.slice(0, 10))} · Versión ${esc(d.version)}</p><div class="sc-actions">${manage && d.estado === 'borrador' ? '<button class="secondary-button" data-edit>Editar borrador</button>' : ''}<button class="secondary-button" data-download="pdf">PDF</button><button class="secondary-button" data-download="xlsx">Excel</button></div><p>${esc(d.encabezado.observaciones || '')}</p><div class="sc-scroll"><table><thead><tr><th>Folio</th><th>Cajas</th><th>Última actividad</th><th>N° actividad</th></tr></thead><tbody>${(d.detalle.originales || d.detalle.lineas || []).map((l) => `<tr><td>${esc(l.folio)}</td><td>${esc(l.cantidad)}</td><td>${esc(l.tipo_actividad)}</td><td>${esc(l.numero_actividad)}</td></tr>`).join('')}</tbody></table></div>${manage && actions.length ? `<form id="scDocumentAction" class="sc-grid"><label>Acción<select name="accion">${actions.map((a) => `<option value="${a}">${({ emitir: 'Emitir documento', enviar: 'Registrar envío', aprobar: 'Registrar aprobación recibida', rechazar: 'Registrar rechazo recibido', cancelar: 'Cancelar borrador' })[a]}</option>`).join('')}</select></label><label>Referencia de aviso, recepción o resolución<input name="referencia" maxlength="1000"></label><label>Nombre y cargo de quien resolvió<input name="resuelto_por" maxlength="250"></label><button class="primary-button">Registrar</button></form>` : ''}<h4>Respaldos</h4>${manage ? '<form id="scDocumentUpload"><label>Agregar respaldo<input type="file" name="archivo" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><button class="secondary-button">Guardar respaldo</button></form>' : ''}<div>${d.archivos.map((a) => `<button class="secondary-button" data-attachment="${esc(a.id)}">${esc(a.nombre)}</button>`).join('') || 'Sin respaldos.'}</div><h4>Historial</h4>${d.historial.map((e) => `<p>${esc(e.created_at)} · ${esc(e.tipo)} · ${esc(e.datos.referencia || '')} ${esc(e.datos.resuelto_por || '')}</p>`).join('')}`;
    $('scDocumentDetail').classList.remove('is-hidden');
}

async function download(path, name) {
    const response = await fetch(`/api/contraparte-sag${path}`, { headers: { Authorization: `Bearer ${sagState().token}`, Accept: '*/*' } });
    if (!response.ok) { const d = await response.json(); throw new Error(d.message || 'No se pudo descargar.'); }
    const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 10000);
}

export async function init() {
    if (!initialized) {
        initialized = true;
        $('scNewDocument').onclick = () => edit(); $('scReloadDocuments').onclick = () => run(list);
        document.querySelectorAll('[data-blank]').forEach((button) => { button.onclick = () => run(() => download(`/en-blanco/${$('scBlankType').value}/${button.dataset.blank}`, `sag-${$('scBlankType').value}-blanco.${button.dataset.blank}`)); });
        $('scDocumentFilters').onsubmit = (e) => { e.preventDefault(); run(list); };
        $('scDocuments').onclick = (e) => { const b = e.target.closest('[data-document]'); if (b) run(() => open(b.dataset.document)); };
        $('scDocumentPages').onclick = (e) => { const b = e.target.closest('[data-page]'); if (b) run(() => list(Number(b.dataset.page))); };
        $('scDocumentForm').elements.tipo.onchange = () => { source = null; selected = []; dispatchHeader = {}; dispatchInstruction = ''; $('scDispatchFields').innerHTML = dispatchFields(null, {}); lines(); };
        $('scLoadRepa').onclick = () => run(async () => { source = await sagApi(`/fuentes/${$('scDocumentForm').elements.tipo.value}/${encodeURIComponent($('scRepaReference').value.trim())}`); selected = source.lineas ? structuredClone(source.lineas) : []; if ($('scDocumentForm').elements.tipo.value === 'despacho') { dispatchHeader = { ...source.transporte, modo: 'multipuerto' }; dispatchInstruction = source.instructivos.length === 1 ? source.instructivos[0].id : ''; selected.forEach((l) => { l.incluido = source.instructivos.length === 1; }); const i = source.instructivos.find((r) => r.id === dispatchInstruction); if (i) dispatchHeader = { ...dispatchHeader, destino_pais: i.destino_pais, destino_ciudad: i.destino_ciudad, consignatario: i.recibidor, referencia_multipuerto: i.planilla_sag || '' }; $('scDispatchFields').innerHTML = dispatchFields(source, dispatchHeader, dispatchInstruction); } $('scDocumentForm').elements.fecha_actividad.value = source.fecha_actividad; lines(); });
        $('scAddFolio').onclick = () => run(async () => { const l = await sagApi(`/folios/${encodeURIComponent($('scAddFolioNumber').value.trim())}`); if (selected.some((r) => r.folio_id === l.folio_id)) throw new Error('El folio ya está agregado.'); selected.push({ ...l, maximo: l.cantidad, causal: '' }); $('scAddFolioNumber').value = ''; lines(); });
        $('scAddFolioNumber').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('scAddFolio').click(); } };
        $('scDispatchFields').onchange = (e) => { const input = e.target.closest('[data-dispatch]'); if (input) { const k = input.dataset.dispatch; dispatchHeader[k] = ['nimf15', 'inspeccionado'].includes(k) ? (input.value === '' ? null : input.value === 'true') : input.value; } if (e.target.id === 'scDispatchInstruction') { dispatchInstruction = e.target.value; const i = source?.instructivos.find((r) => r.id === dispatchInstruction); if (i) { dispatchHeader = { ...dispatchHeader, destino_pais: i.destino_pais, destino_ciudad: i.destino_ciudad, consignatario: i.recibidor, referencia_multipuerto: i.planilla_sag || '' }; $('scDispatchFields').innerHTML = dispatchFields(source, dispatchHeader, dispatchInstruction); } } };
        $('scDocumentLines').oninput = (e) => { const checkbox = e.target.closest('[data-select-folio]'); if (checkbox) { selected.filter((l) => l.folio_id === checkbox.dataset.selectFolio).forEach((l) => { l.incluido = checkbox.checked; }); lines(); return; } const input = e.target.closest('[data-line]'); if (input) { const rows = $('scDocumentForm').elements.tipo.value === 'repaletizaje' ? source.originales : selected; rows[Number(input.dataset.line)][input.dataset.field] = input.value; } };
        $('scDocumentLines').onclick = (e) => { const b = e.target.closest('[data-remove]'); if (b) { selected.splice(Number(b.dataset.remove), 1); lines(); } };
        $('scDocumentForm').onsubmit = (e) => { e.preventDefault(); run(save); };
        $('scDocumentDetail').onclick = (e) => {
            if (e.target.closest('[data-edit]')) edit(selectedDocument);
            const d = e.target.closest('[data-download]'); if (d) run(() => download(`/documentos/${selectedDocument.id}/archivo/${d.dataset.download}`, `sag-${selectedDocument.tipo}.${d.dataset.download}`));
            const a = e.target.closest('[data-attachment]'); if (a) run(() => download(`/documentos/${selectedDocument.id}/respaldos/${a.dataset.attachment}`, selectedDocument.archivos.find((x) => x.id === a.dataset.attachment).nombre));
        };
        $('scDocumentDetail').onsubmit = (e) => { e.preventDefault(); run(async () => {
            if (e.target.id === 'scDocumentAction') { const data = Object.fromEntries(new FormData(e.target)); const action = data.accion; delete data.accion; await sagApi(`/documentos/${selectedDocument.id}/${action}`, { method: 'POST', body: JSON.stringify({ ...data, version: selectedDocument.version, operacion_id: crypto.randomUUID() }) }); }
            if (e.target.id === 'scDocumentUpload') { const response = await fetch(`/api/contraparte-sag/documentos/${selectedDocument.id}/respaldos`, { method: 'POST', headers: { Authorization: `Bearer ${sagState().token}`, Accept: 'application/json' }, body: new FormData(e.target) }); if (!response.ok) throw new Error((await response.json()).message); }
            await open(selectedDocument.id); await list(); sagNotice('Registro guardado.');
        }); };
        document.addEventListener('sag:configuration', people);
    }
    people(); await list();
    const params = new URLSearchParams(location.search);
    if (params.get('documento')) { document.querySelector('[data-panel="documentos"]').click(); await open(params.get('documento')); }
    if (params.get('embarque')) { document.querySelector('[data-panel="documentos"]').click(); edit(null, 'despacho'); $('scRepaReference').value = params.get('embarque'); $('scLoadRepa').click(); }
    if (params.get('lote')) { document.querySelector('[data-panel="documentos"]').click(); edit(null, 'cambio_mercado'); $('scRepaReference').value = params.get('lote'); $('scLoadRepa').click(); }
    if (params.get('repa')) { document.querySelector('[data-panel="documentos"]').click(); edit(); $('scRepaReference').value = params.get('repa'); $('scLoadRepa').click(); }
}
