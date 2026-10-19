import { escapeGatehouse as esc, gatehouseDay, gatehouseDate, gatehouseGuides, gatehouseOperationId, gatehouseStatus, gatehouseEventLines, GATEHOUSE_MOTIVES } from './shared/gatehouse-logic';
const byId = (id) => document.getElementById(id);
const form = byId('gateForm');
const fields = form.elements;
const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
let token = localStorage.getItem(tokenKey);
let identity = null;
let entries = [];
let editing = null;
let pending = null;
let page = 1;
let generation = 0;
let canManage = false;
let canRegister = false;
const draftKey = () => `folios_porteria_intento_${identity?.id}`;
function message(text) { byId('gateStatus').textContent = text; }
function errorText(error) { return Object.values(error.data?.errors || {}).flat()[0] || error.message || 'No fue posible completar la operación.'; }
async function api(path, options = {}) {
    let response;
    try { response = await fetch(path, { ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) }, cache: 'no-store' }); }
    catch { throw new Error('Sin conexión. El ingreso aún no está confirmado; puede reintentar sin duplicarlo.'); }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) { byId('officeAccess').classList.remove('is-hidden'); byId('officeApp').classList.add('is-hidden'); }
        const error = new Error(data.message || 'No fue posible completar la operación.');
        error.status = response.status; error.data = data; throw error;
    }
    return data;
}
function savePending(value) {
    pending = value;
    try { if (value) sessionStorage.setItem(draftKey(), JSON.stringify(value)); else sessionStorage.removeItem(draftKey()); } catch { /* El intento sigue en memoria. */ }
    byId('gateFields').disabled = Boolean(value);
    byId('gateSubmit').textContent = value ? 'Reintentar mismo ingreso' : (editing ? 'Guardar corrección' : 'Registrar ingreso');
}
function addGuide(value = '') {
    const row = document.createElement('div'); row.className = 'gate-guide';
    row.innerHTML = `<input name="guia" aria-label="Número de guía" maxlength="50" value="${esc(value)}"><button class="secondary-button" type="button" aria-label="Quitar guía">Quitar</button>`;
    row.querySelector('button').addEventListener('click', () => row.remove());
    byId('gateGuideRows').append(row);
}
function toggleFields() {
    const other = fields.cliente_id.value === 'otro';
    byId('gateOtherRow').hidden = !other; fields.cliente_otro.disabled = !other; fields.cliente_otro.required = other;
    const otherTruck = fields.tipo_camion.value === 'otro';
    byId('gateTruckOtherRow').hidden = !otherTruck; fields.tipo_camion_otro.disabled = !otherTruck; fields.tipo_camion_otro.required = otherTruck;
    fields.unidad.required = fields.cantidad.value !== '';
}
function payload() {
    const client = fields.cliente_id.value;
    return { cliente_id: client === 'otro' ? null : client, cliente_otro: client === 'otro' ? fields.cliente_otro.value.trim() : null,
        motivo: fields.motivo.value, patente: fields.patente.value, patente_carro: fields.patente_carro.value || null,
        tipo_camion: fields.tipo_camion.value === 'otro' ? fields.tipo_camion_otro.value.trim() : fields.tipo_camion.value,
        nombre_conductor: fields.nombre_conductor.value.trim(), guias: gatehouseGuides([...byId('gateGuideRows').querySelectorAll('input')].map((input) => input.value)),
        cantidad: fields.cantidad.value === '' ? null : Number(fields.cantidad.value), unidad: fields.unidad.value || null, observacion: fields.observacion.value.trim() || null };
}
function fill(data) {
    fields.cliente_id.value = data.cliente_es_otro || data.cliente_otro ? 'otro' : data.cliente_id;
    fields.cliente_otro.value = data.cliente_otro || (data.cliente_es_otro ? data.cliente_nombre : '') || '';
    for (const name of ['motivo', 'patente', 'patente_carro', 'nombre_conductor', 'cantidad', 'unidad', 'observacion']) fields[name].value = data[name] ?? '';
    fields.tipo_camion.value = ['termo', 'plano'].includes(data.tipo_camion) ? data.tipo_camion : 'otro';
    fields.tipo_camion_otro.value = fields.tipo_camion.value === 'otro' ? data.tipo_camion : '';
    byId('gateGuideRows').replaceChildren(); (data.guias.length ? data.guias : ['']).forEach(addGuide); toggleFields();
}
function resetForm() {
    editing = null; form.reset(); savePending(null); byId('gateGuideRows').replaceChildren(); addGuide();
    byId('gateCorrectionRow').hidden = true; fields.motivo_correccion.disabled = true; fields.motivo_correccion.required = false;
    byId('gateFormTitle').textContent = 'Nuevo ingreso'; byId('gateCancelEdit').hidden = true; toggleFields();
    byId('gateCapture').hidden = !canRegister;
}
async function load() {
    const current = ++generation;
    const query = new URLSearchParams(new FormData(byId('gateFilters'))); query.set('page', String(page));
    try {
        const data = await api(`/api/porteria/ingresos?${query}`);
        if (current !== generation) return;
        entries = data.data; canManage = data.puede_administrar; canRegister = data.puede_registrar;
        byId('gateCapture').hidden = !canRegister && !editing;
        byId('gateCount').textContent = `${data.total} ingresos en la fecha consultada`;
        byId('gatePage').textContent = `${data.pagina} / ${data.ultima_pagina}`;
        byId('gatePrev').disabled = data.pagina <= 1; byId('gateNext').disabled = data.pagina >= data.ultima_pagina;
        byId('gateEntries').innerHTML = entries.length ? entries.map((entry) => `<article class="gate-entry"><h3>${esc(entry.patente)} · ${esc(entry.codigo)}</h3><p>${esc(gatehouseStatus(entry))} · ${esc(gatehouseDate(entry.ingreso_at))}</p><dl><dt>Cliente / servicio</dt><dd>${esc(entry.cliente_nombre)}</dd><dt>Conductor</dt><dd>${esc(entry.nombre_conductor)}</dd><dt>Camión</dt><dd>${esc(entry.tipo_camion)}${entry.patente_carro ? ` · carro ${esc(entry.patente_carro)}` : ''}</dd><dt>Motivo</dt><dd>${esc(GATEHOUSE_MOTIVES[entry.motivo])}</dd><dt>Guías</dt><dd>${esc(entry.guias.join(' · ') || 'Sin guía')}</dd><dt>Cantidad</dt><dd>${entry.cantidad === null ? 'No informada' : `${esc(entry.cantidad)} ${esc(entry.unidad)}`}</dd><dt>Registrado por</dt><dd>${esc(entry.creado_por_nombre)}</dd></dl>${entry.observacion ? `<p>${esc(entry.observacion)}</p>` : ''}${entry.motivo_anulacion ? `<p>Anulación: ${esc(entry.motivo_anulacion)}</p>` : ''}<div class="gate-actions"><button class="secondary-button" type="button" data-history="${entry.id}">Historial</button>${canManage && !entry.anulado_at ? `<button class="secondary-button" type="button" data-edit="${entry.id}">Corregir</button><button class="secondary-button" type="button" data-annul="${entry.id}">Anular</button>` : ''}</div></article>`).join('') : '<p>Sin ingresos para esta consulta.</p>';
    } catch (error) { if (current === generation) message(errorText(error)); }
}
async function start(user) {
    identity = user;
    if (!identity?.capacidades?.puede_consultar_porteria) throw new Error('Su perfil no tiene acceso a Portería.');
    byId('officeAccess').classList.add('is-hidden'); byId('officeApp').classList.remove('is-hidden');
    byId('officeUserName').textContent = identity.nombre; byId('officeUserRole').textContent = identity.rol.replaceAll('_', ' ');
    const catalogs = await api('/api/porteria/catalogos');
    fields.cliente_id.innerHTML = '<option value="">Seleccione cliente</option>' + catalogs.clientes.map((client) => `<option value="${esc(client.id)}">${esc(client.nombre)}</option>`).join('') + '<option value="otro">Otro cliente / servicio</option>';
    byId('gateClock').textContent = `Hora del servidor: ${gatehouseDate(catalogs.ahora)} · fecha y hora automáticas al registrar`;
    byId('gateDate').value = gatehouseDay(new Date(catalogs.ahora));
    await load();
    let saved; try { saved = JSON.parse(sessionStorage.getItem(draftKey()) || 'null'); } catch { saved = null; }
    if (saved?.operacion_id) { fill(saved); savePending(saved); message('Hay un intento sin confirmar. Reintente el mismo ingreso para comprobar si ya quedó registrado.'); }
}
byId('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault(); byId('officeLoginError').textContent = '';
    try {
        const data = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
        token = data.token; localStorage.setItem(tokenKey, token); localStorage.setItem(identityKey, JSON.stringify(data.usuario)); await start(data.usuario);
    } catch (error) { byId('officeLoginError').textContent = errorText(error); }
});
byId('officeLogoutButton').addEventListener('click', async () => {
    try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch { /* Se cierra la sesión local. */ }
    localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey); location.reload();
});
form.addEventListener('submit', async (event) => {
    event.preventDefault(); byId('gateFormError').textContent = '';
    const data = pending || { ...payload(), ...(editing ? { version: editing.version, motivo_correccion: fields.motivo_correccion.value.trim() } : { operacion_id: gatehouseOperationId() }) };
    const button = byId('gateSubmit'); button.disabled = true;
    if (!editing) savePending(data);
    try {
        const response = await api(editing ? `/api/porteria/ingresos/${editing.id}` : '/api/porteria/ingresos', { method: editing ? 'PUT' : 'POST', body: JSON.stringify(data) });
        message(`${response.data.codigo} · ${response.data.patente} guardado correctamente.`); resetForm(); page = 1; await load();
    } catch (error) {
        if (error.status === 422 && !editing) savePending(null);
        byId('gateFormError').textContent = errorText(error);
    } finally { button.disabled = false; }
});
fields.cliente_id.addEventListener('change', toggleFields); fields.tipo_camion.addEventListener('change', toggleFields); fields.cantidad.addEventListener('input', toggleFields);
for (const name of ['patente', 'patente_carro']) fields[name].addEventListener('input', () => { fields[name].value = fields[name].value.toUpperCase().replace(/[\s-]+/g, ''); });
byId('gateAddGuide').addEventListener('click', () => { if (byId('gateGuideRows').children.length < 30) addGuide(); });
byId('gateCancelEdit').addEventListener('click', resetForm);
byId('gateFilters').addEventListener('submit', (event) => { event.preventDefault(); page = 1; void load(); });
byId('gatePrev').addEventListener('click', () => { page--; void load(); }); byId('gateNext').addEventListener('click', () => { page++; void load(); });
byId('gateEntries').addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit]'); const annul = event.target.closest('[data-annul]'); const history = event.target.closest('[data-history]');
    if (edit) {
        if (pending) { message('Primero confirme el intento pendiente de registro.'); return; }
        editing = entries.find((entry) => String(entry.id) === edit.dataset.edit); fill(editing); byId('gateCapture').hidden = false;
        byId('gateCorrectionRow').hidden = false; fields.motivo_correccion.disabled = false; fields.motivo_correccion.required = true;
        byId('gateFormTitle').textContent = `Corregir ${editing.codigo}`; byId('gateSubmit').textContent = 'Guardar corrección'; byId('gateCancelEdit').hidden = false; byId('gateCapture').scrollIntoView({ block: 'start' });
    }
    try {
        if (annul) {
            const entry = entries.find((row) => String(row.id) === annul.dataset.annul); const reason = prompt(`Motivo para anular ${entry.codigo} (mínimo 5 caracteres):`);
            if (!reason) return;
            await api(`/api/porteria/ingresos/${entry.id}/anular`, { method: 'POST', body: JSON.stringify({ version: entry.version, motivo: reason.trim() }) }); message('Ingreso anulado con historial.'); await load();
        }
        if (history) {
            const data = await api(`/api/porteria/ingresos/${history.dataset.history}`);
            byId('gateHistoryBody').innerHTML = data.eventos.map((entry) => `<article><h3>${esc(entry.accion)} · ${esc(gatehouseDate(entry.created_at))}</h3><p>${esc(entry.user_nombre)}</p>${entry.datos?.motivo ? `<p>${esc(entry.datos.motivo)}</p>` : ''}<ul>${gatehouseEventLines(entry).map((line) => `<li>${esc(line)}</li>`).join('')}</ul></article>`).join(''); byId('gateHistory').showModal();
        }
    } catch (error) { message(errorText(error)); }
});
byId('gateHistoryClose').addEventListener('click', () => byId('gateHistory').close());
addGuide(); toggleFields();
if (token) api('/api/acceso-oficina').then((data) => start(data.usuario)).catch((error) => { byId('officeLoginError').textContent = errorText(error); });
