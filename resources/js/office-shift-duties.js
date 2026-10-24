const $ = (id) => document.getElementById(id);
const token = localStorage.getItem('estiba_wms_office_token');
let identity; try { identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null'); } catch { identity = null; }
const state = { response: null, draft: null, dirty: false, busy: false, conflict: false };
const html = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
function message(text, error = false) { $(error ? 'shiftError' : 'shiftStatus').textContent = text; }
function dirty() { state.dirty = true; $('shiftDraft').textContent = 'Cambios sin guardar'; }
function busy(value) { state.busy = value; for (const el of document.querySelectorAll('#shiftForm button, #shiftReload, #shiftNew, #shiftCopy, #shiftSelect, #shiftForm input, #shiftForm select')) el.disabled = value || (!state.response && el.id !== 'shiftReload'); }
async function api(path, options = {}) {
    let response; try { response = await fetch(path, { ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) } }); }
    catch { throw new Error('Sin conexión. Se conservan tus cambios; vuelve a intentar.'); }
    if (response.status === 401) { window.location.replace('/oficina/accesos'); throw new Error('Sesión vencida.'); }
    const data = await response.json();
    if (!response.ok) { const error = new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || 'No se pudo completar la operación.'); error.status = response.status; throw error; }
    return data;
}
function localTime(value) {
    const parts = new Intl.DateTimeFormat('sv-SE', { timeZone: state.response.meta.zona_horaria, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value));
    return parts.replace(' ', 'T');
}
function displayTime(value) { return new Intl.DateTimeFormat('es-CL', { timeZone: state.response.meta.zona_horaria, dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)); }
function selectDraft(id) {
    const source = state.response.data.find((t) => t.id === id);
    state.draft = source ? { id: source.id, version: source.version, nombre: source.nombre, inicio: localTime(source.inicio_at), fin: localTime(source.fin_at), asignaciones: structuredClone(source.asignaciones) }
        : { nombre: '', inicio: localTime(state.response.meta.ahora), fin: localTime(new Date(new Date(state.response.meta.ahora).getTime() + 8 * 3600000)), asignaciones: [] };
    state.dirty = false; state.conflict = false; $('shiftDraft').textContent = ''; $('shiftError').textContent = '';
    $('shiftSelect').value = source?.id || ''; renderForm(); renderJobs(source?.labores || []);
}
function options(items, valueKey, labelKey, selected) { return items.map((i) => `<option value="${html(i[valueKey])}" ${i[valueKey] === selected ? 'selected' : ''}>${html(i[labelKey])}</option>`).join(''); }
function renderForm() {
    const d = state.draft; $('shiftName').value = d.nombre; $('shiftStart').value = d.inicio; $('shiftEnd').value = d.fin;
    $('shiftTimezone').textContent = `Horario de planta: ${state.response.meta.zona_horaria}. Usa la fecha siguiente para los turnos que cruzan medianoche.`;
    $('shiftCount').textContent = `${d.asignaciones.filter((a) => a.disponible).length} disponibles de ${d.asignaciones.length}`;
    $('shiftAssignments').innerHTML = d.asignaciones.length ? d.asignaciones.map((a, index) => {
        const functions = state.response.catalogo.funciones;
        const preferenceType = { recepcion_tuneles: 'tunel', separacion_cargas: 'carga', preparacion_sag: 'sag' }[a.funcion_principal];
        const preferenceItems = preferenceType === 'tunel' ? state.response.catalogo.tuneles.map((x) => ({ id: x.id, nombre: `${x.codigo} · ${x.nombre}` })) : preferenceType === 'carga' ? state.response.catalogo.cargas.map((x) => ({ id: x.id, nombre: x.codigo })) : state.response.catalogo.sag.map((x) => ({ id: x.id, nombre: x.titulo }));
        if (a.preferencia && !preferenceItems.some((x) => x.id === a.preferencia.id)) preferenceItems.push({ id: a.preferencia.id, nombre: 'Preferencia anterior · revisa su vigencia' });
        return `<article class="shift-person" data-row="${index}"><div class="shift-person-head"><h3>${html(a.nombre)}</h3><label><input type="checkbox" data-field="disponible" ${a.disponible ? 'checked' : ''}> Disponible</label></div>
            <div class="shift-person-fields"><div><label>Función principal<select class="eui-input" data-field="funcion_principal">${options(functions, 'codigo', 'nombre', a.funcion_principal)}</select></label>
            ${preferenceType ? `<label>Objetivo preferido (opcional)<select class="eui-input" data-field="preferencia" data-type="${preferenceType}"><option value="">Cualquier objetivo de esta función</option>${options(preferenceItems, 'id', 'nombre', a.preferencia?.id)}</select></label>` : ''}</div>
            <div><strong>Apoyos por orden de prioridad</strong><ol class="shift-supports">${a.funciones_apoyo.map((code, i) => `<li><span>${i + 1}. ${html(functions.find((f) => f.codigo === code)?.nombre || code)}</span><button type="button" class="eui-button eui-button--secondary" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Subir prioridad de ${html(code)}">↑</button><button type="button" class="eui-button eui-button--secondary" data-remove="${i}" aria-label="Quitar apoyo ${html(code)}">×</button></li>`).join('')}</ol>
            <div class="shift-support-add"><label>Agregar apoyo<select class="eui-input" data-support-select><option value="">Seleccionar función</option>${options(functions.filter((f) => f.codigo !== a.funcion_principal && !a.funciones_apoyo.includes(f.codigo)), 'codigo', 'nombre', '')}</select></label><button type="button" class="eui-button eui-button--secondary" data-add-support>Agregar apoyo</button></div></div></div></article>`;
    }).join('') : '<p>Agrega al menos un camarero para guardar este turno.</p>';
    const unused = state.response.catalogo.usuarios.filter((u) => !d.asignaciones.some((a) => a.user_id === u.id));
    $('shiftAddUser').innerHTML = '<option value="">Seleccionar camarero</option>' + options(unused, 'id', 'nombre', '');
}
const labels = { en_curso: 'En curso', esperando_dependencias: 'En espera · puede tomar otra labor', completada: 'Completada', liberada: 'Liberada' };
function renderJobs(jobs) {
    $('shiftJobs').innerHTML = jobs.length ? jobs.map((j) => `<article class="shift-job"><span class="shift-badge">${html(labels[j.estado] || j.estado)}</span><p><strong>${html(j.titulo)}</strong></p><p>${html(j.camarero)} · ${html(j.tablet)}</p><p>${html(j.motivo || 'Mantiene los siguientes movimientos de esta labor.')}</p>${j.espera?.porcentaje_fisico !== undefined ? `<p>${html(j.espera.porcentaje_fisico)}% concentrado físicamente</p>` : ''}${j.estado === 'en_curso' ? `<button type="button" class="eui-button eui-button--secondary" data-release="${html(j.id)}" data-version="${j.version}">Liberar labor sin pallet comprometido</button>` : ''}</article>`).join('') : '<p>Aún no hay labores tomadas en este turno.</p>';
}
async function load(preserve = true) {
    busy(true); $('shiftError').textContent = '';
    try {
        const response = await api('/api/labores-turno'); state.response = response;
        $('shiftSelect').innerHTML = '<option value="">Nuevo turno</option>' + response.data.map((t) => `<option value="${html(t.id)}">${html(t.nombre)} · ${html(displayTime(t.inicio_at))}${t.vigente ? ' · VIGENTE' : ''}</option>`).join('');
        if (preserve && state.dirty) {
            $('shiftSelect').value = state.draft.id || ''; const remote = response.data.find((t) => t.id === state.draft.id); renderJobs(remote?.labores || []);
            message(state.conflict ? 'Tu borrador sigue aquí. Para editar la versión nueva, selecciónala en «Turnos guardados».' : 'Estado actualizado. Tus cambios sin guardar se conservan.');
        } else { selectDraft(state.draft?.id || response.meta.vigente_id || response.data[0]?.id); message('Turnos actualizados.'); }
    } catch (error) { message(error.message, true); } finally { busy(false); }
}
$('shiftForm').addEventListener('input', (event) => { if (['shiftName','shiftStart','shiftEnd'].includes(event.target.id)) { state.draft[{ shiftName:'nombre', shiftStart:'inicio', shiftEnd:'fin' }[event.target.id]] = event.target.value; dirty(); } });
$('shiftAssignments').addEventListener('change', (event) => {
    const row = event.target.closest('[data-row]'); if (!row || !event.target.dataset.field) return;
    const a = state.draft.asignaciones[Number(row.dataset.row)]; const field = event.target.dataset.field;
    if (field === 'disponible') a.disponible = event.target.checked;
    if (field === 'funcion_principal') { a.funcion_principal = event.target.value; a.funciones_apoyo = a.funciones_apoyo.filter((v) => v !== a.funcion_principal); a.preferencia = null; }
    if (field === 'preferencia') a.preferencia = event.target.value ? { tipo: event.target.dataset.type, id: event.target.value } : null;
    dirty(); renderForm();
});
$('shiftAssignments').addEventListener('click', (event) => {
    const button = event.target.closest('button'); const row = button?.closest('[data-row]'); if (!row) return;
    const a = state.draft.asignaciones[Number(row.dataset.row)];
    if (button.hasAttribute('data-add-support')) { const code = row.querySelector('[data-support-select]').value; if (!code) return; a.funciones_apoyo.push(code); }
    else if (button.hasAttribute('data-remove')) a.funciones_apoyo.splice(Number(button.dataset.remove), 1);
    else if (button.hasAttribute('data-up')) { const i = Number(button.dataset.up); if (i > 0) [a.funciones_apoyo[i-1], a.funciones_apoyo[i]] = [a.funciones_apoyo[i], a.funciones_apoyo[i-1]]; }
    else return;
    dirty(); renderForm();
});
$('shiftAdd').addEventListener('click', () => { const user = state.response?.catalogo.usuarios.find((u) => u.id === Number($('shiftAddUser').value)); if (!user) return; state.draft.asignaciones.push({ user_id: user.id, nombre: user.nombre, funcion_principal: 'apoyo_camaras', funciones_apoyo: [], preferencia: null, disponible: true }); dirty(); renderForm(); });
$('shiftSelect').addEventListener('change', () => selectDraft($('shiftSelect').value));
$('shiftNew').addEventListener('click', () => { if (state.response) selectDraft(''); });
$('shiftCopy').addEventListener('click', () => { const assignments = structuredClone(state.draft.asignaciones); const previousEnd = state.draft.fin; selectDraft(''); state.draft.asignaciones = assignments; state.draft.inicio = previousEnd; state.draft.fin = ''; dirty(); renderForm(); $('shiftName').focus(); });
$('shiftReload').addEventListener('click', () => void load());
$('shiftForm').addEventListener('submit', async (event) => {
    event.preventDefault(); if (state.busy || !state.response) return;
    if (!state.draft.asignaciones.length) { message('Agrega al menos un camarero.', true); return; }
    busy(true); $('shiftError').textContent = '';
    try {
        const payload = { ...state.draft, asignaciones: state.draft.asignaciones.map(({ user_id, funcion_principal, funciones_apoyo, preferencia, disponible }) => ({ user_id, funcion_principal, funciones_apoyo, preferencia, disponible })) };
        const response = await api('/api/labores-turno', { method: 'POST', body: JSON.stringify(payload) }); state.dirty = false; state.draft.id = response.data.id;
        await load(false); message('Turno guardado. Las tablets recibirán la configuración al actualizar sus labores; una maniobra ya tomada conserva su continuidad.');
    } catch (error) { state.conflict = error.status === 409; message(error.message + (state.conflict ? ' Tu borrador se conserva. Actualiza y selecciona la versión guardada para revisarla.' : ''), true); }
    finally { busy(false); }
});
$('shiftJobs').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-release]'); if (!button || state.busy) return;
    busy(true); try { await api(`/api/labores-turno/labores/${encodeURIComponent(button.dataset.release)}/liberar`, { method: 'POST', body: JSON.stringify({ version: Number(button.dataset.version) }) }); await load(); message('Labor liberada.'); }
    catch (error) { message(error.message, true); } finally { busy(false); }
});
window.addEventListener('beforeunload', (event) => { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });
async function boot() {
    const caps = { ...identity?.capacidades, ...identity }; const modules = caps.modulos_acceso;
    if (!token || !identity || !(identity.rol === 'administrador' || caps.puede_supervisar_camaras_productos) || (Array.isArray(modules) && !modules.includes('frigorifico.camaras'))) { window.location.replace('/oficina/accesos'); return; }
    const name = identity.nombre || identity.name || 'Supervisión'; $('officeUserName').textContent = name; $('officeUserRole').textContent = 'Supervisión de turno'; $('officeInitials').textContent = name.split(/\s+/).slice(0,2).map((x) => x[0]).join('');
    $('officeLogoutButton').addEventListener('click', () => { localStorage.removeItem('estiba_wms_office_token'); localStorage.removeItem('estiba_wms_office_identity'); window.location.replace('/oficina/accesos'); });
    $('shiftDutiesApp').classList.remove('is-hidden'); await load(false);
}
void boot();
