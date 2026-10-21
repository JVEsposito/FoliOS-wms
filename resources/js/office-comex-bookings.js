import { newOperationId } from './shared/rrhh-logic';
import { $, api, choices, escape as esc, message, open, pages, safe, startCommercial, table, values } from './shared/comex-commercial-ui';
import { pickerHtml, preparePicker, picked } from './shared/comex-picker';

const base = '/api/comex/comercial';
const state = { context: null, detail: null, edit: null, operation: null, action: null, extra: {}, notes: [], generation: 0 };
const labels = { asignar: 'Asignar cantidad de una nota', retirar_asignacion: 'Retirar asignación', confirmar: 'Confirmar booking', volver_borrador: 'Volver a borrador', vincular: 'Vincular despacho', retirar_vinculo: 'Retirar vínculo', cerrar: 'Cerrar booking', anular: 'Anular booking' };
startCommercial(async () => { state.context = await api(`${base}/contexto`); $('cbNew').hidden = !state.context.permisos.gestionar; await load(); });
$('cbSearch').onsubmit = (e) => { e.preventDefault(); safe(load); };
$('cbNew').onclick = () => safe(() => edit());
$('cbRows').onclick = (e) => { const b = e.target.closest('[data-booking]'); if (b) safe(() => show(b.dataset.booking)); };
$('cbRefresh').onclick = () => safe(() => show(state.detail.booking.id));
$('cbAddContainer').onclick = () => { addContainer(); state.operation = null; };
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cb-action]'); if (!b) return;
    if (b.dataset.cbAction === 'edit') return safe(() => edit(state.detail));
    safe(() => action(b.dataset.cbAction, b.dataset.id));
});
for (const id of ['cbForm', 'cbActionForm']) $(id).addEventListener('input', () => { state.operation = null; });
$('cbForm').onsubmit = async (e) => {
    e.preventDefault(); const button = e.submitter; if (button.disabled) return; button.disabled = true;
    try {
        const d = values(e.target); const old = state.edit?.booking; state.operation ??= newOperationId(); d.operacion_id = state.operation; d.version = old?.version || 0;
        for (const [key, id] of Object.entries({ cliente_operacion_id: 'cbClient', naviera_id: 'cbNaviera', forwarder_id: 'cbForwarder', origen_id: 'cbOrigen', destino_id: 'cbDestino' })) d[key] = picked($(id))[0];
        if (old) { d.codigo = old.codigo; d.gestion = old.gestion; d.cliente_operacion_id = old.cliente_operacion_id; d.naviera_id = old.naviera_id; }
        d.contenedores = [...$('cbContainers').children].map((row) => ({ ...Object.fromEntries([...row.querySelectorAll('[data-field]')].map((f) => [f.dataset.field, f.value || null])), ...(row.dataset.id ? { id: row.dataset.id } : {}) }));
        const r = await api(`${base}/bookings${old ? `/${old.id}/borrador` : ''}`, old ? 'PUT' : 'POST', d); $('cbEdit').close(); await show(r.data.booking.id); await load();
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { button.disabled = false; }
};
$('cbActionForm').onsubmit = async (e) => {
    e.preventDefault(); const button = e.submitter; if (button.disabled) return; button.disabled = true;
    try {
        state.operation ??= newOperationId();
        await api(`${base}/bookings/${state.detail.booking.id}/transiciones`, 'POST', { ...values(e.target), ...state.extra, accion: state.action, version: state.detail.booking.version, operacion_id: state.operation });
        $('cbAction').close(); await show(state.detail.booking.id); await load();
    } catch (error) { e.target.querySelector('[data-cc-error]').textContent = error.message; } finally { button.disabled = false; }
};
async function load(page = 1) {
    const gen = ++state.generation; const r = await api(`${base}/bookings?${new URLSearchParams({ ...values($('cbSearch')), page })}`); if (gen !== state.generation) return;
    $('cbRows').innerHTML = table(['Booking / cliente', 'Viaje', 'Estado', 'Detalle'], r.data.data.map((b) => `<tr><td>${esc(b.codigo)}<small>${esc(b.cliente.nombre)} · ${esc(b.gestion)}</small></td><td>${esc(b.datos.nave)} / ${esc(b.datos.viaje)}<small>${esc(b.datos.fecha_salida)} → ${esc(b.datos.fecha_llegada)}</small></td><td>${esc(b.estado)}</td><td><button type="button" data-booking="${esc(b.id)}">Abrir booking</button></td></tr>`).join('')); pages('cbPages', r.data, load); message('Reservas comerciales disponibles.');
}
function actionButton(a, id = '') { return `<button type="button" class="secondary-button" data-cb-action="${a}" data-id="${esc(id)}">${esc(labels[a] || 'Editar borrador')}</button>`; }
async function show(id) {
    state.detail = (await api(`${base}/bookings/${id}`)).data; const { booking: b, contenedores: cs, asignaciones: assignments, vinculos: links } = state.detail; const d = b.datos; const manage = state.context.permisos.gestionar;
    $('cbTitle').textContent = `${b.codigo} · ${b.cliente.nombre}`; $('cbState').textContent = `${b.estado} · ${b.gestion} · versión ${b.version}`;
    const acts = [];
    if (manage && b.estado === 'borrador') { acts.push('edit', 'asignar'); if (state.context.permisos.aprobar) acts.push('confirmar', 'anular'); }
    if (manage && b.estado === 'confirmado') { acts.push('vincular'); if (!links.some((l) => l.activo)) acts.push('volver_borrador'); if (state.context.permisos.aprobar) acts.push('cerrar', 'anular'); }
    $('cbActions').innerHTML = acts.map((a) => actionButton(a)).join('');
    $('cbHeader').innerHTML = `<p>${esc(d.naviera.razon_social)} · Forwarder ${esc(d.forwarder.razon_social)}<br>${esc(d.nave)} / ${esc(d.viaje)}<br>${esc(d.origen.nombre)} → ${esc(d.destino.nombre)}<br>Salida ${esc(d.fecha_salida)} · Llegada ${esc(d.fecha_llegada)}<br>Cutoff físico ${esc(d.cutoff_fisico)} · Documental ${esc(d.cutoff_documental)} · ${esc(d.zona_horaria)}</p>`;
    $('cbAssignments').innerHTML = table(['Contenedor', 'Acuerdo / producto', 'Cantidad', 'Estado'], cs.map((c) => `<tr><td>${esc(c.referencia)}<small>${esc(c.numero || 'Número pendiente')} · ${esc(c.tipo)}</small></td><td colspan="3">${esc(c.sello || 'Sello pendiente')}</td></tr>${assignments.filter((a) => a.contenedor_id === c.id).map((a) => `<tr><td>${esc(c.referencia)}</td><td>${esc(a.acuerdo.nota.folio)} · revisión ${a.acuerdo.revision.numero}<small>${esc(a.acuerdo.linea.sku)} · ${esc(a.acuerdo.linea.descripcion)}</small></td><td>${esc(a.cantidad)} ${esc(a.acuerdo.linea.unidad)}</td><td>${a.activa ? 'Reservada' : 'Retirada'} ${manage && b.estado === 'borrador' && a.activa ? actionButton('retirar_asignacion', a.id) : ''}</td></tr>`).join('')}`).join(''));
    $('cbLinks').innerHTML = table(['Contenedor / embarque', 'Evidencia capturada', 'Estado operativo actual', 'Vínculo'], links.map((l) => `<tr><td>${esc(cs.find((c) => c.id === l.contenedor_id)?.referencia)}<small>${esc(l.evidencia.codigo)}</small></td><td>${esc(l.evidencia.carga?.estado || 'Sin carga')}<small>${esc(l.captured_at)}</small>${l.al_cierre ? `<small>Al cierre comercial: ${esc(l.al_cierre.evidencia.carga?.estado)}</small>` : ''}</td><td>${esc(l.actual?.carga?.estado || (l.actual ? 'Sin carga' : 'No disponible'))}<small>${l.cambio_operacion ? 'Cambió desde la captura' : 'Coincide con la captura'} · ${l.actual?.carga?.folios ?? 0} pallets</small></td><td>${l.activo ? 'Vigente' : 'Retirado'} ${manage && b.estado === 'confirmado' && l.activo && l.actual && !l.actual.carga?.salio ? actionButton('retirar_vinculo', l.id) : ''}</td></tr>`).join(''));
    await history(); open('cbDetail');
}
async function history(page = 1) {
    const r = await api(`${base}/bookings/${state.detail.booking.id}/historial?page=${page}`); $('cbHistory').innerHTML = table(['Fecha / responsable', 'Acción', 'Motivo'], r.data.data.map((e) => `<tr><td>${esc(e.created_at)}<small>${esc(e.user_nombre)}</small></td><td>${esc(labels[e.accion] || e.accion)}</td><td>${esc(e.datos.solicitud?.motivo || e.datos.motivo)}</td></tr>`).join('')); pages('cbHistoryPages', r.data, history);
}
async function edit(detail = null) {
    state.edit = detail; state.operation = null; const b = detail?.booking; const f = $('cbForm'); f.reset(); f.elements.zona_horaria.value = 'America/Santiago';
    for (const [k, v] of Object.entries(b?.datos || {})) if (f.elements[k] && typeof v !== 'object') f.elements[k].value = v || '';
    f.elements.codigo.value = b?.codigo || ''; f.elements.gestion.value = b?.gestion || 'servicio'; f.elements.codigo.readOnly = !!b; f.elements.gestion.disabled = !!b; f.elements.motivo.required = !!b; $('cbReason').hidden = !b;
    const pickers = [['cbClient', 'Cliente de servicios', `${base}/clientes-operacion`, b?.cliente], ['cbNaviera', 'Naviera', `${base}/partes?activo=1&rol=naviera`, b?.datos.naviera], ['cbForwarder', 'Forwarder', `${base}/partes?activo=1&rol=forwarder`, b?.datos.forwarder], ['cbOrigen', 'Puerto de origen', `${base}/lugares?activo=1&tipo=puerto`, b?.datos.origen], ['cbDestino', 'Puerto de destino', `${base}/lugares?activo=1&tipo=puerto`, b?.datos.destino]];
    await Promise.all(pickers.map(async ([id, label, endpoint, selected]) => { $(id).innerHTML = pickerHtml(id, label); await preparePicker($(id), endpoint, selected ? [selected] : []); }));
    for (const id of ['cbClient', 'cbNaviera']) { $(id).querySelector('[data-choice]').disabled = !!b; $(id).querySelector('[data-find]').disabled = !!b; }
    $('cbContainers').replaceChildren(); for (const c of detail?.contenedores || [{}]) addContainer(c); open('cbEdit');
}
function addContainer(c = {}) {
    const row = document.createElement('fieldset'); row.className = 'cx-form'; if (c.id) row.dataset.id = c.id;
    row.innerHTML = `<legend>Contenedor previsto</legend><label><span>Referencia interna permanente</span><input data-field="referencia" maxlength="80" value="${esc(c.referencia)}" ${c.id ? 'readonly' : ''} required></label><label><span>Número (opcional al planificar)</span><input data-field="numero" pattern="[A-Z]{3}U[0-9]{7}" maxlength="11" value="${esc(c.numero)}"></label><label><span>Tipo</span><select data-field="tipo">${choices(['20GP', '40GP', '40HC', '20RF', '40RF', '40RH'].map((s) => [s, s]))}</select></label><label><span>Sello (opcional)</span><input data-field="sello" maxlength="80" value="${esc(c.sello)}"></label><button type="button" class="secondary-button">Quitar contenedor</button>`;
    row.querySelector('select').value = c.tipo || '40RH'; row.querySelector('button').onclick = () => { row.remove(); state.operation = null; }; $('cbContainers').append(row);
}
async function action(a, id = '') {
    state.action = a; state.operation = null; state.extra = a === 'retirar_asignacion' ? { asignacion_id: id } : a === 'retirar_vinculo' ? { vinculo_id: id } : {};
    $('cbActionTitle').textContent = labels[a]; $('cbActionForm').reset(); $('cbActionFields').replaceChildren();
    const containerSelect = `<label><span>Contenedor</span><select name="contenedor_id" required>${choices(state.detail.contenedores.map((c) => [c.id, `${c.referencia} · ${c.numero || 'Número pendiente'}`]), 'Seleccione contenedor')}</select></label>`;
    if (a === 'asignar') {
        $('cbActionFields').innerHTML = `${containerSelect}<label><span>Buscar nota aprobada por folio</span><input id="cbNoteSearch" type="search" maxlength="100"></label><button id="cbFindNotes" type="button" class="secondary-button">Buscar notas</button><label><span>Nota y línea con saldo comercial</span><select name="linea_id" id="cbLine" required></select></label><input type="hidden" name="nota_id" id="cbNote"><div id="cbNotePages"></div><label><span>Cantidad en la unidad contractual</span><input name="cantidad" inputmode="decimal" pattern="[0-9]{1,12}(\\.[0-9]{1,6})?" required></label>`;
        $('cbFindNotes').onclick = () => safe(() => notes()); $('cbLine').onchange = () => { $('cbNote').value = state.notes.find((n) => n.lineas.some((l) => l.id === $('cbLine').value))?.id || ''; }; await notes();
    }
    if (a === 'vincular') {
        const date = state.detail.booking.datos.fecha_salida;
        $('cbActionFields').innerHTML = `${containerSelect}<label><span>Fecha inicial de búsqueda</span><input id="cbFrom" type="date" value="${esc(date)}" required></label><label><span>Fecha final (hasta 31 días)</span><input id="cbTo" type="date" value="${esc(date)}" required></label><button type="button" id="cbFindShipments" class="secondary-button">Consultar embarques del cliente</button><label><span>Embarque operativo existente</span><select name="embarque_id" id="cbShipment" required></select></label>`;
        $('cbFindShipments').onclick = () => safe(shipments); await shipments();
    }
    open('cbAction');
}
async function notes(page = 1) {
    const r = await api(`${base}/bookings/${state.detail.booking.id}/notas?${new URLSearchParams({ buscar: $('cbNoteSearch').value, page })}`); state.notes = r.data.data;
    $('cbLine').innerHTML = choices(state.notes.flatMap((n) => n.lineas.map((l) => [l.id, `${n.folio} · ${l.sku} · ${l.descripcion} · saldo ${l.saldo} ${l.unidad}`])), 'Seleccione una línea'); $('cbNote').value = ''; pages('cbNotePages', r.data, notes);
}
async function shipments() {
    const r = await api(`${base}/bookings/${state.detail.booking.id}/embarques?${new URLSearchParams({ desde: $('cbFrom').value, hasta: $('cbTo').value })}`);
    $('cbShipment').innerHTML = choices(r.data.map((e) => [e.id, `${e.codigo} · ${e.fecha_programada} · ${e.contenedor || 'Sin contenedor'} · ${e.carga?.estado || 'Sin carga'}`]), 'Seleccione embarque');
}
