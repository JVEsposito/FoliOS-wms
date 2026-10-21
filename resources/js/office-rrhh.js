import { initNomina } from './office-rrhh-nomina';
import { attendanceIssueText, importRows, newOperationId, sourceConfiguration } from './shared/rrhh-logic';

const $ = (id) => document.getElementById(id);
const escape = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const clock = document.body.dataset.rrhhClock === '1';
const zone = document.body.dataset.rrhhZone;
const tokenKey = 'estiba_wms_office_token'; const identityKey = 'estiba_wms_office_identity';
let identity; try { identity = JSON.parse(localStorage.getItem(identityKey) || 'null'); } catch { identity = null; }
let token = localStorage.getItem(tokenKey);
const state = { people: [], sources: [], clients: [], assignments: [], manage: false, person: null, source: null, operation: null, page: 1, assignmentPage: 1, generation: 0 };
const capability = (name) => identity?.[name] === true || identity?.capacidades?.[name] === true;
const time = (s) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: zone }).format(new Date(s));
const message = (s) => { $('rhMessage').textContent = s; };

function clearSession() { localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey); location.reload(); }
async function api(path, method = 'GET', body) {
    let response;
    try { response = await fetch(path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, ...(body ? { body: JSON.stringify(body) } : {}) }); }
    catch { throw new Error('No fue posible conectar. Puede reintentar la misma solicitud.'); }
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) clearSession();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la solicitud.');
    return data;
}
function showApp() {
    if (!capability(clock ? 'puede_marcar_rrhh' : 'puede_consultar_rrhh')) return false;
    $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden');
    $('officeUserName').textContent = identity?.nombre || 'Usuario'; $('officeUserRole').textContent = identity?.rol || '';
    $('officeInitials').textContent = (identity?.nombre || 'U').split(/\s+/).slice(0, 2).map((s) => s[0]).join('').toUpperCase();
    return true;
}
$('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault(); const button = event.submitter; if (button.disabled) return; button.disabled = true;
    try {
        const data = await api('/api/acceso-oficina', 'POST', Object.fromEntries(new FormData(event.target)));
        token = data.token; identity = data.usuario; localStorage.setItem(tokenKey, token); localStorage.setItem(identityKey, JSON.stringify(identity));
        if (!showApp()) throw new Error('Su perfil no tiene acceso a esta sección de RRHH.');
        if (!clock) await loadPeople();
    } catch (e) { $('officeLoginError').textContent = e.message; } finally { button.disabled = false; }
});
$('officeLogoutButton')?.addEventListener('click', clearSession);

if (clock) {
    let operation = null;
    $('rhClockForm').addEventListener('change', (e) => { if (['codigo', 'tipo'].includes(e.target.name)) operation = null; });
    $('rhClockForm').addEventListener('submit', async (event) => {
        event.preventDefault(); const button = event.submitter; if (button.disabled) return; button.disabled = true;
        operation ??= newOperationId(); $('rhReceipt').textContent = '';
        try {
            const data = await api('/api/rrhh/marcajes', 'POST', { ...Object.fromEntries(new FormData(event.target)), operacion_id: operation });
            $('rhReceipt').textContent = `${data.data.anulado ? 'Comprobante original anulado' : data.data.tipo === 'entrada' ? 'Entrada registrada' : 'Salida registrada'} · ${time(data.data.instante_at)} · ${data.data.id.slice(0, 8)}`;
            event.target.elements.pin.value = ''; operation = null; message('Marcaje recibido.'); event.target.elements.codigo.focus();
        } catch (e) { message(e.message); } finally { button.disabled = false; }
    });
} else {
    initNomina({ api, capability, escape, table, choices, open, pages, message });
    const dates = $('rhDatesForm');
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    dates.elements.desde.value = today; dates.elements.hasta.value = today;
    $('rhDeviceZone').textContent = `Horario del equipo: ${Intl.DateTimeFormat().resolvedOptions().timeZone}. Duración máxima: 24 horas.`;
    for (const form of document.querySelectorAll('.rh-dialog form:not([data-nomina-form])')) form.addEventListener('submit', submitDialog);
    document.addEventListener('click', async (event) => {
        const close = event.target.closest('[data-rh-close]'); if (close) return close.closest('dialog').close();
        const tab = event.target.closest('[data-rh-tab]');
        if (tab) {
            for (const b of document.querySelectorAll('[data-rh-tab]')) b.setAttribute('aria-pressed', String(b === tab));
            for (const p of document.querySelectorAll('[data-rh-panel]')) p.hidden = p.dataset.rhPanel !== tab.dataset.rhTab;
            if (tab.dataset.rhTab === 'dotacion') await safely(loadAssignments);
        }
        const personAction = event.target.closest('[data-rh-person]');
        if (personAction) await safely(() => personActionClick(personAction));
        const assignmentAction = event.target.closest('[data-rh-assignment]');
        if (assignmentAction) await safely(() => assignmentActionClick(assignmentAction));
        const sourceAction = event.target.closest('[data-rh-source]');
        if (sourceAction) await safely(() => sourceActionClick(sourceAction));
    });
    $('rhNewPerson').addEventListener('click', () => openPerson());
    $('rhNewSource').addEventListener('click', () => openSource());
    $('rhSearchForm').addEventListener('submit', (e) => { e.preventDefault(); state.page = 1; safely(loadPeople); });
    dates.addEventListener('submit', (e) => { e.preventDefault(); state.assignmentPage = 1; safely(loadAssignments); });
}

async function safely(action) { try { await action(); } catch (e) { message(e.message); } }
function choices(items, empty = '') { return (empty ? `<option value="">${escape(empty)}</option>` : '') + items.map(([id, label]) => `<option value="${escape(id)}">${escape(label)}</option>`).join(''); }
function sourceChoices() { return state.sources.map((s) => [s.id, s.nombre]); }
function open(id) { const dialog = $(id); dialog.querySelector('[data-rh-error]')?.replaceChildren(); dialog.showModal(); }
function pages(id, pagination, action) {
    const container = $(id); container.replaceChildren();
    for (const [label, value] of [['Anterior', pagination.current_page - 1], ['Siguiente', pagination.current_page + 1]]) {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'secondary-button'; b.textContent = label;
        b.disabled = value < 1 || value > pagination.last_page; b.addEventListener('click', () => safely(() => action(value))); container.append(b);
    }
    container.append(document.createTextNode(`Página ${pagination.current_page} de ${pagination.last_page}`));
}
function table(headers, rows) { return `<table><thead><tr>${headers.map((s) => `<th>${escape(s)}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td colspan="${headers.length}">Sin registros para esta consulta.</td></tr>`}</tbody></table>`; }

async function loadPeople() {
    const generation = ++state.generation;
    const data = await api(`/api/rrhh/personas?${new URLSearchParams({ buscar: $('rhSearchForm').elements.buscar.value, page: state.page })}`);
    if (generation !== state.generation) return;
    state.people = data.data.data; state.clients = data.clientes; state.sources = data.fuentes; state.manage = data.permisos.gestionar;
    for (const n of document.querySelectorAll('[data-rh-manage]')) n.hidden = !state.manage;
    $('rhPeople').innerHTML = table(['Persona', 'Cargo / área', 'Marcaje', 'Acciones'], state.people.map((p) => `<tr><td><strong>${escape(p.nombre)}</strong><small>${escape(p.codigo)} · ${p.activa ? 'Activa' : 'Inactiva'}</small></td><td>${escape(p.cargo)}<small>${escape(p.area)} · ${escape(p.ceco)}</small></td><td>${escape(p.fuente_marcaje)}</td><td>`
        + (state.manage ? ['editar', 'programar', 'vincular'].map((a) => `<button type="button" data-rh-person="${p.id}" data-action="${a}">${{ editar: 'Editar', programar: 'Programar', vincular: 'Vincular fuente' }[a]}</button>`).join('') : '')
        + `<button type="button" data-rh-person="${p.id}" data-action="marcajes">Marcajes</button><button type="button" data-rh-person="${p.id}" data-action="historial">Historial</button></td></tr>`).join(''));
    pages('rhPeoplePages', data.data, async (page) => { state.page = page; await loadPeople(); });
    $('rhSources').innerHTML = state.sources.map((s) => `<div class="rh-source"><strong>${escape(s.nombre)}</strong><small>${escape(s.tipo)} · ${s.activa ? 'Activa' : 'Inactiva'} · ${s.configurada ? 'Credenciales guardadas' : 'Sin credenciales'}</small>`
        + `<button type="button" data-rh-source="${s.id}" data-action="consultar">Vínculos e importaciones</button>`
        + (state.manage ? `<button type="button" data-rh-source="${s.id}" data-action="configurar">Configurar</button><button type="button" data-rh-source="${s.id}" data-action="importar">Recibir lote</button>` : '') + '</div>').join('') || '<p>Agregue una fuente para recibir marcajes externos.</p>';
}
async function loadAssignments() {
    const data = await api(`/api/rrhh/asignaciones?${new URLSearchParams({ ...Object.fromEntries(new FormData($('rhDatesForm'))), page: state.assignmentPage })}`);
    state.assignments = data.data.data;
    $('rhAssignments').innerHTML = table(['Turno / persona', 'Destino', 'Programación', 'Asistencia', 'Acciones'], state.assignments.map((a) => `<tr><td><strong>${escape(a.persona_nombre)}</strong><small>${time(a.desde_at)} → ${time(a.hasta_at)}</small><small>${escape(a.estado)}</small></td><td>${escape(a.cliente_nombre || 'Uso interno')}<small>${escape(a.cargo)} · ${escape(a.area)} · ${escape(a.ceco)}</small></td><td>${escape(a.horas_programadas)} h<small>${a.costo_programado === null ? '' : `Costo: ${escape(a.costo_programado)} ${escape(a.moneda_costo)}`}</small></td><td>${escape(a.horas_asistidas)} h${a.asistencia_valida ? '' : `<small>Revisar: ${escape(attendanceIssueText(a.incidencias))}</small>`}</td><td>`
        + (state.manage && a.estado !== 'cancelada' ? `<button type="button" data-rh-assignment="${a.id}" data-action="completada">Completar</button><button type="button" data-rh-assignment="${a.id}" data-action="cancelada">Cancelar</button>` : '')
        + `<button type="button" data-rh-assignment="${a.id}" data-action="historial">Historial</button></td></tr>`).join(''));
    pages('rhAssignmentPages', data.data, async (page) => { state.assignmentPage = page; await loadAssignments(); });
}
function openPerson(person = null) {
    state.person = person; const form = $('rhPersonForm'); form.reset();
    form.elements.fuente_marcaje.innerHTML = choices([['folios', 'FoliOS · código y PIN'], ...state.sources.map((s) => [s.codigo, s.nombre])]);
    for (const [k, v] of Object.entries(person || {})) if (form.elements[k]) form.elements[k].value = k === 'activa' ? (v ? '1' : '0') : v ?? '';
    form.elements.codigo.readOnly = !!person; $('rhPersonTitle').textContent = person ? 'Editar persona' : 'Agregar persona'; open('rhPersonDialog');
}
function openSource(source = null) {
    state.source = source; const form = $('rhSourceForm'); form.reset();
    for (const k of ['codigo', 'nombre', 'tipo', 'activa']) if (source) form.elements[k].value = k === 'activa' ? (source[k] ? '1' : '0') : source[k];
    for (const k of ['codigo', 'nombre', 'tipo']) form.elements[k].disabled = !!source;
    open('rhSourceDialog');
}
async function personActionClick(button) {
    const person = state.people.find((p) => p.id === button.dataset.rhPerson); if (!person) return;
    state.person = person;
    if (button.dataset.action === 'editar') return openPerson(person);
    if (button.dataset.action === 'programar') {
        const form = $('rhAssignmentForm'); form.reset(); state.operation = newOperationId();
        form.elements.cliente_id.innerHTML = choices(state.clients.map((c) => [c.id, c.nombre]), 'Sin cliente (uso interno)');
        for (const k of ['cargo', 'area', 'ceco']) form.elements[k].value = person[k] || '';
        $('rhAssignmentTitle').textContent = `Programar: ${person.nombre}`; return open('rhAssignmentDialog');
    }
    if (button.dataset.action === 'vincular') { $('rhLinkForm').reset(); $('rhLinkForm').elements.fuente_id.innerHTML = choices(sourceChoices()); $('rhLinkPerson').textContent = `${person.codigo} · ${person.nombre}`; return open('rhLinkDialog'); }
    if (button.dataset.action === 'historial') return history('persona', person.id);
    await showMarks(person.id);
}
async function showMarks(personId, page = 1) {
    const data = await api(`/api/rrhh/personas/${personId}/marcajes?page=${page}`);
    $('rhDetailTitle').textContent = 'Marcajes y correcciones';
    $('rhDetail').innerHTML = table(['Hora', 'Tipo / fuente', 'Estado'], data.data.data.map((m) => `<tr><td>${time(m.instante_at)}</td><td>${escape(m.tipo)}<small>${escape(m.fuente_codigo)} · ${escape(m.canal)}</small></td><td>${m.anulado_at ? 'Anulado' : 'Vigente'}`
        + (state.manage && !m.anulado_at ? `<button type="button" data-mark-correct="${m.id}">Corregir / anular</button>` : '')
        + `<button type="button" data-mark-history="${m.id}">Historial</button></td></tr>`).join('')) + '<div id="rhMarkPages" class="rh-toolbar"></div>';
    pages('rhMarkPages', data.data, (n) => showMarks(personId, n));
    for (const b of $('rhDetail').querySelectorAll('[data-mark-history]')) b.addEventListener('click', () => safely(() => history('marcaje', b.dataset.markHistory)));
    for (const b of $('rhDetail').querySelectorAll('[data-mark-correct]')) b.addEventListener('click', () => safely(async () => {
        const motivo = prompt('Motivo de la corrección o anulación (mínimo 5 caracteres):'); if (!motivo) return;
        const instante = prompt('Nueva fecha ISO 8601 con zona (ej. 2026-11-01T08:00:00-03:00). Vacío anula sin reemplazar:', ''); if (instante === null) return;
        const tipo = instante ? prompt('Tipo: entrada o salida', data.data.data.find((m) => m.id === b.dataset.markCorrect).tipo) : null; if (instante && !tipo) return;
        await api(`/api/rrhh/marcajes/${b.dataset.markCorrect}/corregir`, 'POST', { motivo, ...(instante ? { instante, tipo } : {}) });
        await showMarks(personId, page); message('Corrección guardada con historial.');
    }));
    if (!$('rhDetailDialog').open) $('rhDetailDialog').showModal();
}
async function assignmentActionClick(button) {
    const a = state.assignments.find((row) => row.id === button.dataset.rhAssignment); if (!a) return;
    if (button.dataset.action === 'historial') return history('asignacion', a.id);
    const motivo = prompt('Motivo del cambio de estado (no modifica cargos comerciales):'); if (!motivo) return;
    await api(`/api/rrhh/asignaciones/${a.id}/estado`, 'PATCH', { version: a.version, estado: button.dataset.action, motivo }); await loadAssignments();
}
async function sourceActionClick(button) {
    const source = state.sources.find((s) => s.id === button.dataset.rhSource); if (!source) return;
    if (button.dataset.action === 'configurar') return openSource(source);
    if (button.dataset.action === 'importar') { const form = $('rhImportForm'); form.reset(); form.elements.fuente_id.innerHTML = choices(sourceChoices()); form.elements.fuente_id.value = source.id; return open('rhImportDialog'); }
    $('rhIntegrationResult').textContent = JSON.stringify((await api(`/api/rrhh/fuentes/${source.id}`)).data, null, 2);
}
async function history(entidad, registro_id) {
    const data = await api(`/api/rrhh/historial?${new URLSearchParams({ entidad, registro_id })}`);
    $('rhDetailTitle').textContent = 'Historial'; $('rhDetail').replaceChildren(); const pre = document.createElement('pre'); pre.className = 'rh-history'; pre.textContent = JSON.stringify(data.data.data, null, 2); $('rhDetail').append(pre);
    if (!$('rhDetailDialog').open) $('rhDetailDialog').showModal();
}

async function submitDialog(event) {
    event.preventDefault(); const form = event.target; const button = event.submitter; if (button.disabled) return; button.disabled = true;
    const values = Object.fromEntries(new FormData(form)); const error = form.querySelector('[data-rh-error]'); error.textContent = '';
    try {
        if (form.id === 'rhPersonForm') {
            await api(state.person ? `/api/rrhh/personas/${state.person.id}` : '/api/rrhh/personas', state.person ? 'PUT' : 'POST', { ...values, activa: values.activa === '1', pin: values.pin || null, ...(state.person ? { version: state.person.version } : {}) });
            form.elements.pin.value = ''; await loadPeople();
        } else if (form.id === 'rhAssignmentForm') {
            await api('/api/rrhh/asignaciones', 'POST', { ...values, persona_id: state.person.id, operacion_id: state.operation, cliente_id: values.cliente_id || null,
                costo_hora: values.costo_hora ? values.costo_hora.replace(',', '.') : null, moneda_costo: values.moneda_costo.toUpperCase() || null,
                desde: new Date(values.desde).toISOString(), hasta: new Date(values.hasta).toISOString(), descanso_programado_minutos: Number(values.descanso_programado_minutos) });
            await loadAssignments();
        } else if (form.id === 'rhSourceForm') {
            const config = sourceConfiguration(values);
            await api(state.source ? `/api/rrhh/fuentes/${state.source.id}` : '/api/rrhh/fuentes', state.source ? 'PUT' : 'POST', {
                ...(state.source ? {} : { codigo: values.codigo, nombre: values.nombre, tipo: values.tipo }), activa: values.activa === '1', ...(Object.keys(config).length ? { configuracion: config } : {}) });
            form.reset(); await loadPeople();
        } else if (form.id === 'rhLinkForm') {
            await api(`/api/rrhh/fuentes/${values.fuente_id}/vinculos`, 'POST', { persona_id: state.person.id, identificador_externo: values.identificador_externo });
        } else if (form.id === 'rhImportForm') {
            const file = form.elements.archivo.files[0]; if (!file || file.size > 5_000_000) throw new Error('Seleccione un archivo JSON de hasta 5 MB.');
            const data = await api(`/api/rrhh/fuentes/${values.fuente_id}/importar`, 'POST', { marcajes: importRows(JSON.parse(await file.text())) });
            $('rhIntegrationResult').textContent = JSON.stringify(data.data, null, 2); form.reset();
        }
        form.closest('dialog').close(); message('Registro guardado.');
    } catch (e) { error.textContent = e.message; } finally { button.disabled = false; }
}
if (token && showApp() && !clock) safely(loadPeople);
