import { newOperationId } from './shared/rrhh-logic';
import { $, api, choices, escape as esc, message, open, pages, safe, startCommercial, table, values } from './shared/comex-commercial-ui';
import { pickerHtml, preparePicker, picked } from './shared/comex-picker';

const base = '/api/finanzas/costos-comex';
const state = { manage: false, currencies: {}, detail: null, edit: null, operation: null, action: null, extra: {}, documents: [], generation: 0 };
const labels = { imputar: 'Distribuir documento registrado', retirar_imputacion: 'Retirar distribución', cerrar: 'Cerrar control de costo', reabrir: 'Reabrir control', anular: 'Anular control' };
const treatments = { por_definir: 'Por definir', costo_propio: 'Costo propio', reembolsable: 'Reembolsable por tercero' };
const decimal = (v) => v === null || v === undefined ? 'Pendiente' : String(v).includes('.') ? String(v).replace(/\.?0+$/, '') : String(v);
startCommercial(load, 'puede_consultar_costos_comex');
$('fcSearch').onsubmit = (e) => { e.preventDefault(); safe(load); };
$('fcNew').onclick = () => safe(() => edit());
$('fcRows').onclick = (e) => { const b = e.target.closest('[data-cost]'); if (b) safe(() => show(b.dataset.cost)); };
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fc-action]'); if (!b) return;
    if (b.dataset.fcAction === 'edit') return safe(() => edit(state.detail.costo));
    if (b.dataset.fcAction === 'refresh') return safe(() => show(state.detail.costo.id));
    safe(() => action(b.dataset.fcAction, b.dataset.id));
});
for (const id of ['fcForm', 'fcActionForm']) $(id).addEventListener('input', () => { state.operation = null; });
$('fcForm').elements.moneda.onchange = toggleFx;
$('fcForm').onsubmit = async (e) => {
    e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
    try {
        state.operation ??= newOperationId(); const d = values(e.target); d.version = state.edit?.version || 0; d.operacion_id = state.operation;
        d.booking_id = state.edit?.booking_id || picked($('fcBooking'))[0]; d.contenedor_id = state.edit?.contenedor_id || e.target.elements.contenedor_id.value || null;
        const r = await api(`${base}${state.edit ? `/${state.edit.id}` : ''}`, state.edit ? 'PUT' : 'POST', d); $('fcEdit').close(); await show(r.data.costo.id); await load();
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { b.disabled = false; }
};
$('fcActionForm').onsubmit = async (e) => {
    e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
    try {
        state.operation ??= newOperationId(); const d = { ...values(e.target), ...state.extra, accion: state.action, version: state.detail.costo.version, operacion_id: state.operation }; if (d.linea !== undefined) d.linea = Number(d.linea);
        await api(`${base}/${state.detail.costo.id}/transiciones`, 'POST', d); $('fcAction').close(); await show(state.detail.costo.id); await load();
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { b.disabled = false; }
};
async function load(page = 1) {
    const gen = ++state.generation; const r = await api(`${base}?${new URLSearchParams({ ...values($('fcSearch')), page })}`); if (gen !== state.generation) return;
    state.manage = r.puede_gestionar; state.currencies = r.monedas; $('fcNew').hidden = !state.manage;
    $('fcRows').innerHTML = table(['Costo / booking', 'Previsto / comprometido', 'Real al cierre', 'Estado'], r.data.data.map((c) => `<tr><td><button type="button" data-cost="${esc(c.id)}">${esc(c.concepto)}</button><small>${esc(c.fuente.booking.codigo)} · ${esc(c.fuente.booking.cliente.nombre)}</small></td><td>${esc(decimal(c.previsto))} / ${esc(decimal(c.comprometido))} ${esc(c.moneda)}</td><td>${c.cierre ? `${esc(c.cierre.real_clp)} CLP` : 'Consulte el real vigente en detalle'}</td><td>${esc(c.estado)}</td></tr>`).join('')); pages('fcPages', r.data, load); message('Previsión y distribución auxiliar de costos disponibles.');
}
function button(a, id = '') { return `<button type="button" class="secondary-button" data-fc-action="${a}" data-id="${esc(id)}">${esc(labels[a] || (a === 'edit' ? 'Editar previsión / actualizar fuente' : 'Actualizar diferencias'))}</button>`; }
async function show(id) {
    const d = (await api(`${base}/${id}`)).data; state.detail = d; const c = d.costo; $('fcTitle').textContent = `${c.concepto} · ${c.fuente.booking.codigo}`;
    $('fcAlerts').textContent = d.alertas.length ? d.alertas.join(' ') : 'Las fuentes consultadas coinciden con la captura.'; $('fcAlerts').classList.toggle('cx-status--alerta', d.alertas.length > 0);
    const acts = ['refresh']; if (state.manage && c.estado === 'abierto') acts.push('edit', 'imputar', 'cerrar', 'anular'); if (state.manage && c.estado === 'cerrado') acts.push('reabrir'); $('fcActions').innerHTML = acts.map((a) => button(a)).join('');
    $('fcSummary').innerHTML = table(['Medida', 'Importe / referencia'], `<tr><td>Estado y tratamiento</td><td>${esc(c.estado)} · ${esc(treatments[c.tratamiento])}</td></tr><tr><td>Booking / alcance</td><td>${esc(c.fuente.booking.codigo)} · ${esc(c.fuente.contenedores.find((x) => x.id === c.contenedor_id)?.referencia || 'Booking completo')}</td></tr><tr><td>Previsto</td><td>${esc(decimal(c.previsto))} ${esc(c.moneda)} · Referencia ${esc(c.conversion.previsto_clp)} CLP</td></tr><tr><td>Comprometido</td><td>${esc(decimal(c.comprometido))} ${esc(c.moneda)} · Referencia ${esc(c.conversion.comprometido_clp ?? 'Pendiente')} CLP</td></tr><tr><td>Real vigente distribuido</td><td>${esc(d.real_vigente_clp)} CLP</td></tr><tr><td>Real conservado al cierre</td><td>${esc(d.real_cierre_clp ?? 'Sin cierre')} ${d.real_cierre_clp === null ? '' : 'CLP'}</td></tr><tr><td>Conversión presupuestaria conservada</td><td>${esc(c.conversion.tasa_clp)} CLP/${esc(c.moneda)} · ${esc(c.conversion.fecha)} · ${esc(c.conversion.fuente)}<small>${esc(c.conversion.politica)}</small></td></tr><tr><td>Respaldo</td><td>${esc(c.respaldo)}</td></tr>`);
    $('fcAllocations').innerHTML = table(['Documento / línea', 'Distribución CLP', 'Estado', 'Aplicado al documento CLP'], d.imputaciones.map((i) => `<tr><td>${esc(i.documento.tipo)} · ${esc(i.documento.folio)}<small>${esc(i.documento.proveedor_snapshot.razon_social)} · ${esc(i.documento.linea.descripcion)}</small></td><td>${i.signo === -1 ? 'Abono −' : ''}${esc(i.monto)}</td><td>${i.activa ? 'Vigente en control' : 'Retirada'}<small>Documento ${esc(i.estado_documento)} · ${i.fuente_vigente ? 'Coincide' : 'Requiere revisión'}</small>${state.manage && c.estado === 'abierto' && i.activa ? button('retirar_imputacion', i.id) : ''}</td><td>${esc(i.aplicado_documento_clp)}</td></tr>`).join('')); await history(); open('fcDetail');
}
async function history(page = 1) {
    const r = await api(`${base}/${state.detail.costo.id}/historial?page=${page}`); $('fcHistory').innerHTML = table(['Fecha / responsable', 'Acción', 'Motivo'], r.data.data.map((e) => `<tr><td>${esc(e.created_at)}<small>${esc(e.user_nombre)}</small></td><td>${esc(labels[e.accion] || e.accion)}</td><td>${esc(e.datos.solicitud?.motivo || e.datos.motivo)}</td></tr>`).join('')); pages('fcHistoryPages', r.data, history);
}
async function edit(c = null) {
    state.edit = c; state.operation = null; const f = $('fcForm'); f.reset(); f.elements.moneda.innerHTML = choices(Object.keys(state.currencies).map((s) => [s, s]));
    for (const [k, v] of Object.entries(c || {})) if (f.elements[k] && v !== null && typeof v !== 'object') f.elements[k].value = v;
    if (c) { f.elements.tasa_clp.value = c.conversion.tasa_clp; f.elements.fecha_cambio.value = c.conversion.fecha; f.elements.fuente_cambio.value = c.conversion.fuente; }
    $('fcBooking').innerHTML = pickerHtml('booking', 'Booking confirmado o cerrado');
    await preparePicker($('fcBooking'), `${base}/bookings`, c ? [c.fuente.booking] : [], (b) => `${b.codigo} · ${b.cliente.nombre}`);
    const select = $('fcBooking').querySelector('[data-choice]'); select.disabled = !!c; $('fcBooking').querySelector('[data-find]').disabled = !!c;
    select.onchange = () => safe(containers); await containers(); f.elements.contenedor_id.disabled = !!c;
    $('fcReason').hidden = !c; f.elements.motivo.required = !!c; toggleFx(); open('fcEdit');
}
async function containers() {
    const id = picked($('fcBooking'))[0]; const c = state.edit; const data = c?.fuente || (id ? (await api(`${base}/bookings/${id}`)).data : null);
    $('fcForm').elements.contenedor_id.innerHTML = choices((data?.contenedores || []).map((v) => [v.id, `${v.referencia} · ${v.numero || 'Número pendiente'}`]), 'Booking completo'); $('fcForm').elements.contenedor_id.value = c?.contenedor_id || '';
}
function toggleFx() {
    const foreign = $('fcForm').elements.moneda.value !== 'CLP'; $('fcFx').hidden = !foreign; for (const f of $('fcFx').querySelectorAll('input,textarea')) { f.disabled = !foreign; f.required = foreign; }
}
async function action(a, id = '') {
    state.action = a; state.operation = null; state.extra = a === 'retirar_imputacion' ? { imputacion_id: id } : {}; $('fcActionForm').reset(); $('fcActionTitle').textContent = labels[a]; $('fcActionFields').replaceChildren();
    if (a === 'imputar') {
        $('fcActionFields').innerHTML = `<label><span>Buscar folio de compra</span><input id="fcDocSearch" type="search" maxlength="100"></label><button type="button" id="fcFindDocuments" class="secondary-button">Consultar Compras</button><label><span>Documento registrado vigente</span><select name="documento_id" id="fcDocument" required></select></label><div id="fcDocPages"></div><label><span>Línea de gasto sin IVA</span><select name="linea" id="fcLine" required></select></label><label><span>Parte a distribuir en CLP</span><input name="monto" inputmode="numeric" pattern="[0-9]{1,15}" required></label>`;
        $('fcFindDocuments').onclick = () => safe(() => documents()); $('fcDocument').onchange = documentLines; await documents();
    }
    open('fcAction');
}
async function documents(page = 1) {
    const r = await api(`${base}/documentos?${new URLSearchParams({ buscar: $('fcDocSearch').value, page })}`); state.documents = r.data.data;
    $('fcDocument').innerHTML = choices(state.documents.map((d) => [d.id, `${d.tipo === '61' ? 'Nota de crédito' : `Documento ${d.tipo}`} ${d.folio} · ${d.proveedor_snapshot.razon_social}`]), 'Seleccione documento'); documentLines(); pages('fcDocPages', r.data, documents);
}
function documentLines() {
    const d = state.documents.find((v) => v.id === $('fcDocument').value);
    $('fcLine').innerHTML = choices((d?.lineas || []).map((l, i) => [i, l]).filter(([, l]) => l.cuenta_tipo === 'gasto').map(([i, l]) => [i, `${l.descripcion} · ${l.monto} CLP · ${l.cuenta_nombre}`]), 'Seleccione línea de gasto');
}
