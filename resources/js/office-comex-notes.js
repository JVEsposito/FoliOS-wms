import { newOperationId } from './shared/rrhh-logic';
import { $, api, choices, escape as esc, message, open, pages, roleLabels, safe, startCommercial, table, values } from './shared/comex-commercial-ui';
import { pickerHtml, preparePicker, picked } from './shared/comex-picker';

const base = '/api/comex/comercial';
const state = { context: null, catalog: null, detail: null, revisions: [], edit: null, operation: null, decision: null, generation: 0 };
const actions = { nueva_revision: 'Preparar nueva revisión', enviar_revision: 'Enviar a revisión', retirar_revision: 'Retirar a borrador', aprobar: 'Aprobar propuesta', rechazar: 'Rechazar propuesta', cerrar: 'Cerrar acuerdo', anular: 'Anular acuerdo' };
startCommercial(async () => { state.context = await api(`${base}/contexto`); $('cnNew').hidden = !state.context.permisos.gestionar; await load(); });
$('cnSearch').onsubmit = (e) => { e.preventDefault(); safe(load); };
$('cnNew').onclick = () => safe(() => edit());
$('cnRows').onclick = (e) => { const b = e.target.closest('[data-note]'); if (b) safe(() => show(b.dataset.note)); };
$('cnAddLine').onclick = () => { addLine(); state.operation = null; };
$('cnRevision').onchange = renderRevision;
$('cnPrint').onclick = () => { $('cnPrintSheet').innerHTML = $('cnDocument').innerHTML; window.print(); };
$('cnActions').onclick = (e) => {
    const b = e.target.closest('[data-action]'); if (!b) return;
    if (b.dataset.action === 'edit') return safe(() => edit(state.detail));
    state.decision = b.dataset.action; state.operation = null; $('cnDecisionForm').reset(); $('cnDecisionTitle').textContent = actions[state.decision]; open('cnDecision');
};
for (const id of ['cnForm', 'cnDecisionForm']) $(id).addEventListener('input', () => { state.operation = null; });
$('cnDecisionForm').onsubmit = async (e) => {
    e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
    try {
        state.operation ??= newOperationId();
        await api(`${base}/notas/${state.detail.nota.id}/transiciones`, 'POST', { ...values(e.target), accion: state.decision, version: state.detail.nota.version, operacion_id: state.operation });
        $('cnDecision').close(); await show(state.detail.nota.id); await load();
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { b.disabled = false; }
};
$('cnForm').onsubmit = async (e) => {
    e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
    try {
        state.operation ??= newOperationId(); const d = values(e.target); const old = state.edit;
        d.operacion_id = state.operation; d.version = old?.nota.version || 0;
        d.cliente_operacion_id = old?.nota.cliente_operacion_id || picked($('cnClient'))[0];
        if (old) { d.folio = old.nota.folio; d.gestion = old.nota.gestion; }
        d.partes = Object.fromEntries(state.catalog.roles.map((r) => [r, picked($(`cnRole-${r}`))[0]]));
        if (old) d.partes.vendedor = old.nota.vendedor_id;
        d.partes.notify = picked($('cnNotify')); d.contrata_flete_id = picked($('cnFreight'))[0];
        d.lineas = [...$('cnLines').children].map((row) => ({ ...Object.fromEntries([...row.querySelectorAll('[data-field]')].filter((f) => f.value !== '').map((f) => [f.dataset.field, f.value])), ...(row.dataset.id ? { id: row.dataset.id } : {}) }));
        const r = await api(`${base}/notas${old ? `/${old.nota.id}/borrador` : ''}`, old ? 'PUT' : 'POST', d);
        $('cnEdit').close(); await show(r.data.nota.id); await load(); message('Borrador guardado. Envíelo a revisión cuando esté listo.');
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { b.disabled = false; }
};
async function load(page = 1) {
    const gen = ++state.generation; const r = await api(`${base}/notas?${new URLSearchParams({ ...values($('cnSearch')), page })}`); if (gen !== state.generation) return;
    state.catalog = r;
    $('cnRows').innerHTML = table(['Nota / cliente', 'Gestión', 'Acuerdo', 'Detalle'], r.data.data.map((n) => `<tr><td>${esc(n.folio)}<small>${esc(n.cliente.nombre)}</small></td><td>${n.gestion === 'propia' ? 'Exportación propia' : 'Servicio'}</td><td>${esc(n.estado)}<small>${n.revision_activa_id ? 'Con versión aprobada' : 'Sin aprobación'}</small></td><td><button type="button" data-note="${esc(n.id)}">Abrir acuerdo</button></td></tr>`).join(''));
    pages('cnPages', r.data, load); message('Acuerdos comerciales disponibles.');
}
async function show(id) {
    const r = await api(`${base}/notas/${id}`); state.detail = r.data; const n = r.data.nota; const p = r.data.propuesta;
    $('cnTitle').textContent = `${n.folio} · ${n.cliente.nombre}`;
    $('cnState').textContent = `${n.estado} · Propuesta ${p.numero}: ${p.estado}. ${r.data.aprobada ? `Acuerdo vigente: revisión ${r.data.aprobada.numero}.` : 'Sin acuerdo aprobado.'}`;
    const enabled = [];
    if (n.estado === 'abierta' && state.context.permisos.gestionar) {
        if (p.estado === 'borrador') enabled.push('edit', 'enviar_revision');
        else if (p.estado === 'en_revision') { enabled.push('retirar_revision'); if (r.puede_decidir) enabled.push('aprobar', 'rechazar'); }
        else enabled.push('nueva_revision');
        if (state.context.permisos.aprobar) { enabled.push('anular'); if (r.data.aprobada && !['borrador', 'en_revision'].includes(p.estado)) enabled.push('cerrar'); }
    }
    $('cnActions').innerHTML = enabled.map((a) => `<button type="button" data-action="${a}" class="secondary-button">${actions[a] || 'Editar borrador'}</button>`).join('');
    await revisions(1, r.data.aprobada?.id || p.id); await history(); open('cnDetail');
}
async function revisions(page = 1, selected = null) {
    const r = await api(`${base}/notas/${state.detail.nota.id}/revisiones?page=${page}`); state.revisions = r.data.data;
    const preferred = [state.detail.propuesta, state.detail.aprobada].filter(Boolean);
    for (const item of preferred) if (!state.revisions.some((v) => v.id === item.id)) state.revisions.push(item);
    $('cnRevision').innerHTML = choices(state.revisions.map((v) => [v.id, `Revisión ${v.numero} · ${v.estado} · ${v.preparado_por}`]));
    if (selected) $('cnRevision').value = selected;
    pages('cnRevisionPages', r.data, revisions); renderRevision();
}
async function history(page = 1) {
    const r = await api(`${base}/notas/${state.detail.nota.id}/historial?page=${page}`);
    $('cnHistory').innerHTML = table(['Fecha / responsable', 'Decisión', 'Motivo'], r.data.data.map((e) => `<tr><td>${esc(e.created_at)}<small>${esc(e.user_nombre)}</small></td><td>${esc(actions[e.accion] || e.accion)}</td><td>${esc(e.datos.motivo)}</td></tr>`).join('')); pages('cnHistoryPages', r.data, history);
}
function renderRevision() {
    const r = state.revisions.find((v) => v.id === $('cnRevision').value); if (!r) return;
    const d = r.datos; const n = state.detail.nota; const party = (p) => `${esc(p.razon_social)}<small>${esc(p.pais_iso2)} · ${esc(p.tax_id)} · ${esc(p.direccion)} · ${esc(p.ciudad)}</small>`;
    $('cnDocument').innerHTML = `<article class="cx-commercial-document"><h2>Nota de venta ${esc(n.folio)}</h2><p><strong>Documento comercial interno · No tributario</strong></p><p>Acuerdo ${esc(n.estado)} · Revisión ${r.numero}: ${esc(r.estado)}${n.gestion === 'servicio' ? ' · Referencia comercial del cliente de servicios' : ' · Exportación propia'}</p><p>${esc(n.cliente.nombre)} · ${esc(d.temporada_referencia)}<br>Fecha ${esc(d.fecha)} · Vigencia ${esc(d.vigente_hasta)} · Pedido ${esc(d.referencia_pedido)}</p>${table(['Función', 'Identidad acordada'], Object.entries(d.partes).filter(([k]) => k !== 'notify').map(([k, p]) => `<tr><td>${esc(roleLabels[k])}</td><td>${party(p)}</td></tr>`).join(''))}<p>Notify: ${(d.partes.notify || []).map(party).join('; ') || 'Sin designación'}<br>Contrata flete: ${party(d.contrata_flete)}</p><p>${esc(d.negocio)} · ${esc(d.moneda)} · ${esc(d.incoterm)} ${esc(d.lugar_incoterm)} · Incoterms ${esc(d.incoterm_version)} · ${esc(d.modalidad)}<br>Pago: ${esc(d.condicion_pago)}</p>${table(['Producto', 'Cantidad', 'Kilos', 'Precio unitario', 'Importe'], d.lineas.map((l) => `<tr><td>${esc(l.sku)} · ${esc(l.descripcion)}<small>${esc(l.especie)} / ${esc(l.variedad)} / ${esc(l.calibre)} / ${esc(l.categoria)} / ${esc(l.embalaje)}</small></td><td>${esc(l.cantidad)} ${esc(l.unidad)}</td><td>${esc(l.kilos || 'Sin definir')}</td><td>${esc(l.precio ?? 'Pendiente')}</td><td>${esc(l.importe ?? 'Pendiente')}</td></tr>`).join(''))}<p><strong>${d.valorizacion.total === null ? `Total pendiente. Subtotal conocido: ${esc(d.valorizacion.subtotal_conocido)}` : `Total: ${esc(d.valorizacion.total)}`} ${esc(d.moneda)}</strong><br>Redondeo por línea a ${d.valorizacion.decimales} decimales.</p><p>Respaldo: ${esc(d.respaldo)}<br>${esc(d.observaciones)}</p><p>Preparó: ${esc(r.preparado_por)} · Decidió: ${esc(r.aprobado_por || 'Pendiente')} · ${esc(r.decidido_at)}<br>${esc(r.observacion)}</p></article>`;
}
async function edit(detail = null) {
    state.edit = detail; state.operation = null; const f = $('cnForm'); f.reset();
    f.elements.moneda.innerHTML = choices(Object.keys(state.catalog.monedas).map((m) => [m, m]), 'Seleccione moneda');
    f.elements.incoterm.innerHTML = choices(state.catalog.incoterms.map((m) => [m, m]), 'Seleccione Incoterm');
    const d = detail?.propuesta.datos;
    for (const [k, v] of Object.entries(d || {})) if (f.elements[k] && typeof v !== 'object') f.elements[k].value = v ?? '';
    f.elements.folio.value = detail?.nota.folio || ''; f.elements.gestion.value = detail?.nota.gestion || 'servicio';
    f.elements.folio.readOnly = !!detail; f.elements.gestion.disabled = !!detail;
    $('cnReason').hidden = !detail; f.elements.motivo.required = !!detail;
    $('cnClient').innerHTML = pickerHtml('client', 'Cliente de servicios de la planta');
    $('cnParties').innerHTML = state.catalog.roles.map((r) => `<div id="cnRole-${r}">${pickerHtml(r, roleLabels[r])}</div>`).join('');
    $('cnFreight').innerHTML = pickerHtml('freight', 'Parte que contrata el flete'); $('cnNotify').innerHTML = pickerHtml('notify', 'Notify parties (opcional, hasta 10)', true);
    await Promise.all([
        preparePicker($('cnClient'), `${base}/clientes-operacion`, detail ? [detail.nota.cliente] : [], (r) => r.nombre),
        ...state.catalog.roles.map((r) => preparePicker($(`cnRole-${r}`), `${base}/partes?activo=1&rol=${r}`, d ? [d.partes[r]] : [])),
        preparePicker($('cnFreight'), `${base}/partes?activo=1`, d ? [d.contrata_flete] : []),
        preparePicker($('cnNotify'), `${base}/partes?activo=1&rol=notify`, d?.partes.notify || [])
    ]);
    for (const root of [$('cnClient'), $('cnRole-vendedor')]) { root.querySelector('[data-choice]').disabled = !!detail; root.querySelector('[data-find]').disabled = !!detail; }
    $('cnLines').replaceChildren(); for (const l of d?.lineas || [{}]) addLine(l); open('cnEdit');
}
function addLine(line = {}) {
    const row = document.createElement('fieldset'); row.className = 'cx-form cx-commercial-line'; if (line.id) row.dataset.id = line.id;
    row.innerHTML = `<legend>Producto acordado</legend>${Object.entries({ sku: ['SKU / código', 60], descripcion: ['Descripción', 200], especie: ['Especie', 80], variedad: ['Variedad', 80], calibre: ['Calibre', 40], categoria: ['Categoría', 40], embalaje: ['Embalaje', 100], cantidad: ['Cantidad', 19], kilos: ['Kilos netos (opcional)', 19], precio: ['Precio unitario (opcional según negocio)', 19] }).map(([k, [label, length]]) => `<label><span>${label}</span><input data-field="${k}" maxlength="${length}" value="${esc(line[k])}" ${['cantidad', 'kilos', 'precio'].includes(k) ? 'inputmode="decimal" pattern="[0-9]{1,12}(\\.[0-9]{1,6})?"' : ''} ${['kilos', 'precio'].includes(k) ? '' : 'required'}></label>`).join('')}<label><span>Unidad contractual</span><select data-field="unidad">${choices(['caja', 'kg', 'pallet', 'unidad'].map((s) => [s, s]))}</select></label><button type="button" class="secondary-button">Quitar producto</button>`;
    row.querySelector('select').value = line.unidad || 'caja'; row.querySelector('button').onclick = () => { row.remove(); state.operation = null; }; $('cnLines').append(row);
}
