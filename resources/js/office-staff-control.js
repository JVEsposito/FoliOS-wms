const $ = (id) => document.getElementById(id);
const base = '/api/frigorifico/control-personal';
const state = { response: null, person: null, entry: null, absence: null, cancel: null, busy: false, dirty: false, filters: null };
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
const duration = (value) => value == null ? '—' : `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
const minutes = (value) => { const m = /^(\d{1,2}):([0-5]\d)$/.exec(value); if (!m || Number(m[1]) > 24 || (Number(m[1]) === 24 && Number(m[2]))) throw new Error('La jornada base debe tener formato HH:MM, hasta 24:00.'); return Number(m[1]) * 60 + Number(m[2]); };
let token = localStorage.getItem('estiba_wms_office_token');
function busy(value) { state.busy = value; document.querySelectorAll('.staff-shell button, .staff-dialog button, .staff-shell input, .staff-shell select, .staff-dialog input, .staff-dialog select, .staff-dialog textarea').forEach((el) => { if (value) { el.dataset.staffWasDisabled = String(el.disabled); el.disabled = true; } else { el.disabled = el.dataset.staffWasDisabled === 'true'; delete el.dataset.staffWasDisabled; } }); }
async function api(path, options = {}) {
    let response;
    try { response = await fetch(path, { ...options, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) } }); }
    catch { throw new Error('Sin conexión. El borrador se conserva; vuelve a intentar.'); }
    if (response.status === 401) { window.location.replace('/oficina/accesos'); throw new Error('Sesión vencida.'); }
    if (response.status === 403) { $('staffApp').classList.add('is-hidden'); document.querySelectorAll('dialog[open]').forEach((d) => d.close()); window.location.replace('/oficina/accesos'); throw new Error('No tienes acceso al control de personal.'); }
    if (!response.ok) { const data = await response.json(); const error = new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || 'No se pudo completar la operación.'); error.status = response.status; throw error; }
    return options.download ? response.blob() : response.json();
}
function localNow() { const timeZone = state.response?.data.meta.zona_horaria || 'America/Santiago'; return new Intl.DateTimeFormat('sv-SE', { timeZone, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hourCycle:'h23' }).format(new Date()).replace(' ', 'T'); }
function display(value) { if (!value) return 'Sin salida'; const [date,time] = value.split('T'); return `${date.split('-').reverse().join('-')} ${time}`; }
function options(items, selected, placeholder) { return `<option value="">${placeholder}</option>` + items.map((p) => `<option value="${escape(p.id)}" ${p.id === selected ? 'selected' : ''}>${escape(p.nombre)}${p.activo === false ? ' · Inactiva' : ''}${p.identificador ? ` · ${escape(p.identificador)}` : ''}</option>`).join(''); }
function filterValues() { return { mes:$('staffMonth').value, persona_id:$('staffPersonFilter').value, sector:$('staffSectorFilter').value }; }
function params(filters) { return new URLSearchParams(Object.entries(filters).filter(([,v]) => v !== '')); }
async function load() {
    const filters = filterValues();
    const response = await api(`${base}?${params(filters)}`);
    state.response = response; state.filters = filters;
    $('staffPersonFilter').innerHTML = options(response.personas, filters.persona_id, 'Todas las personas');
    $('staffSectorFilter').innerHTML = '<option value="">Todos los sectores</option>' + response.sectores.map((s) => `<option ${s === filters.sector ? 'selected' : ''}>${escape(s)}</option>`).join('');
    render(); $('staffStatus').textContent = `Control actualizado · ${filters.mes}`;
}
function render() {
    const rows = state.response.data.resumen;
    const sum = (key) => rows.reduce((total,r) => total + r[key], 0);
    $('staffTotals').innerHTML = [['Personas registradas',rows.length],['Trabajado · HH:MM',duration(sum('minutos_trabajados'))],['Horas extra · HH:MM',duration(sum('minutos_extra'))],['Salidas pendientes',sum('pendientes')],['Días de ausencia',sum('dias_ausencia')]].map(([label,value]) => `<div class="staff-total"><span>${label}</span><strong>${value}</strong></div>`).join('');
    $('staffCriterion').textContent = state.response.data.meta.criterio;
    $('staffSummary').innerHTML = rows.map((r) => `<tr><td><strong>${escape(r.nombre)}</strong><small>${escape(r.identificador)}</small></td><td>${r.jornadas_cerradas}</td><td>${r.pendientes}</td><td>${duration(r.minutos_trabajados)}</td><td class="staff-extra">${duration(r.minutos_extra)}</td><td>${absenceSummary(r)}</td><td><button class="eui-button eui-button--secondary" type="button" data-sheet="${escape(r.persona_id)}" aria-label="Ficha PDF de ${escape(r.nombre)}">Ficha PDF</button></td></tr>`).join('') || '<tr><td colspan="7">Sin jornadas ni ausencias para este mes y filtros.</td></tr>';
    renderRecords(); renderAbsences();
}
const absenceTypes = () => state.response?.data.meta.tipos_ausencia || {};
const day = (value) => value ? value.split('-').reverse().join('-') : '';
const period = (a) => a.desde === a.hasta ? day(a.desde) : `${day(a.desde)} al ${day(a.hasta)}`;
function absenceSummary(r) {
    const parts = Object.entries(absenceTypes()).filter(([key]) => (r.ausencias?.[key] || 0) > 0).map(([key,label]) => `<span class="staff-absence">${escape(label)} ${r.ausencias[key]}</span>`);
    return parts.join('') || '<span class="staff-muted">—</span>';
}
function historyValues(event, kind) {
    const fields = kind === 'ausencia'
        ? [['tipo','Tipo'],['desde','Desde'],['hasta','Hasta'],['observacion','Observación'],['anulado_at','Anulada el']]
        : [['ingreso_at','Ingreso'],['salida_at','Salida'],['jornada_base_minutos','Jornada base'],['colacion_minutos','Colación'],['sector','Sector'],['observacion','Observación'],['anulado_at','Anulada el']];
    const value = (data, key) => {
        const v = data?.[key]; if (v == null) return '—';
        if (key === 'tipo') return absenceTypes()[v] || v;
        if (key === 'desde' || key === 'hasta') return day(String(v).slice(0, 10));
        if (key.endsWith('_minutos')) return duration(v);
        if (key.endsWith('_at')) return new Intl.DateTimeFormat('es-CL', {timeZone:state.response.data.meta.zona_horaria,dateStyle:'short',timeStyle:'short'}).format(new Date(v));
        return v;
    };
    return `<div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Dato</th><th>Anterior</th><th>Guardado</th></tr></thead><tbody>${fields.map(([key,label]) => `<tr><th>${label}</th><td>${escape(value(event.anterior,key))}</td><td>${escape(value(event.nuevo,key))}</td></tr>`).join('')}</tbody></table></div>`;
}
function renderRecords() {
    const rows = state.response.data.jornadas.filter((j) => !$('staffState').value || $('staffState').value === j.estado);
    $('staffRecords').innerHTML = rows.map((j) => `<tr><td><strong>${escape(j.persona.nombre)}</strong><small>${escape(j.sector)} · ${escape(j.persona.identificador)}</small>${j.observacion ? `<small>${escape(j.observacion)}</small>` : ''}</td><td>${display(j.ingreso)}</td><td class="${j.estado === 'pendiente' ? 'staff-pending' : ''}">${j.estado === 'anulada' ? 'Anulada' : display(j.salida)}</td><td>${duration(j.jornada_base_minutos)} / ${duration(j.colacion_minutos)}</td><td>${duration(j.estado === 'anulada' ? null : j.minutos_trabajados)}</td><td class="staff-extra">${duration(j.estado === 'anulada' ? null : j.minutos_extra)}</td><td><div class="staff-actions">${j.estado !== 'anulada' ? `<button class="eui-button eui-button--secondary" type="button" data-edit="${j.id}">${j.estado === 'pendiente' ? 'Registrar salida' : 'Corregir'}</button><button class="eui-button eui-button--secondary" type="button" data-cancel="${j.id}">Anular</button>` : ''}<button class="eui-button eui-button--secondary" type="button" data-history="${j.id}">Historial</button></div></td></tr>`).join('') || '<tr><td colspan="7">Sin jornadas con este estado.</td></tr>';
}
function renderAbsences() {
    const filter = $('staffAbsenceState').value;
    const rows = (state.response.data.ausencias || []).filter((a) => !filter || filter === a.estado);
    $('staffAbsences').innerHTML = rows.map((a) => `<tr${a.estado === 'anulada' ? ' class="staff-muted"' : ''}><td><strong>${escape(a.persona.nombre)}</strong><small>${escape(a.persona.identificador)}</small></td><td><strong>${escape(a.tipo_nombre)}</strong>${a.estado === 'anulada' ? '<small>Anulada</small>' : ''}</td><td>${period(a)}${a.dias_total !== a.dias_mes ? `<small>${a.dias_total} días en total</small>` : ''}</td><td>${a.estado === 'anulada' ? '—' : a.dias_mes}</td><td>${escape(a.observacion || '')}</td><td><div class="staff-actions">${a.estado !== 'anulada' ? `<button class="eui-button eui-button--secondary" type="button" data-absence-edit="${a.id}">Corregir</button><button class="eui-button eui-button--secondary" type="button" data-absence-cancel="${a.id}">Anular</button>` : ''}<button class="eui-button eui-button--secondary" type="button" data-absence-history="${a.id}">Historial</button></div></td></tr>`).join('') || '<tr><td colspan="6">Sin ausencias con este estado.</td></tr>';
}
function personForm() {
    const p = state.person;
    for (const [id,key] of [['staffName','nombre'],['staffIdentifier','identificador'],['staffSector','sector'],['staffFunction','funcion']]) $(id).value = p?.[key] || '';
    $('staffBase').value = p ? duration(p.jornada_base_minutos) : ''; $('staffActive').checked = p?.activo ?? true; $('staffPeopleError').textContent = ''; state.dirty = false;
}
function openPeople() {
    if (!state.response || state.busy) return;
    state.person = null; $('staffPeopleSelect').innerHTML = options(state.response.personas, '', 'Nueva persona'); personForm(); $('staffPeopleDialog').showModal();
}
function fillPersonDefaults() {
    const p = state.response.personas.find((p) => p.id === $('staffEntryPerson').value);
    $('staffEntryBase').value = p ? duration(p.jornada_base_minutos) : ''; $('staffEntrySector').value = p?.sector || '';
}
function openEntry(j = null) {
    if (!state.response || state.busy) return;
    state.entry = j;
    $('staffEntryTitle').textContent = j ? (j.estado === 'pendiente' ? 'Registrar salida' : 'Corregir jornada') : 'Registrar jornada';
    $('staffEntryPerson').innerHTML = options(state.response.personas.filter((p) => p.activo || p.id === j?.persona_id), j?.persona_id || '', 'Selecciona una persona');
    $('staffEntryPerson').disabled = !!j;
    $('staffIn').value = j?.ingreso || localNow(); $('staffOut').value = j?.salida || '';
    $('staffBreak').value = j?.colacion_minutos || 0; $('staffNote').value = j?.observacion || '';
    $('staffReasonLabel').hidden = !j; $('staffReason').required = !!j; $('staffReason').value = '';
    $('staffEntryBase').value = j ? duration(j.jornada_base_minutos) : ''; $('staffEntrySector').value = j?.sector || '';
    $('staffEntryHelp').textContent = `Horario de planta: ${state.response.data.meta.zona_horaria}. Si termina al día siguiente, selecciona esa fecha en la salida. Las horas pendientes no se suman.`;
    $('staffPreview').textContent = 'El cálculo definitivo se realiza al guardar la salida.';
    $('staffEntryError').textContent = ''; state.dirty = false; $('staffEntryDialog').showModal();
}
function openAbsence(a = null) {
    if (!state.response || state.busy) return;
    state.absence = a;
    $('staffAbsenceTitle').textContent = a ? 'Corregir ausencia' : 'Registrar ausencia';
    $('staffAbsencePerson').innerHTML = options(state.response.personas.filter((p) => p.activo || p.id === a?.persona_id), a?.persona_id || $('staffPersonFilter').value || '', 'Selecciona una persona');
    $('staffAbsencePerson').disabled = !!a;
    $('staffAbsenceType').innerHTML = Object.entries(absenceTypes()).map(([key,label]) => `<option value="${escape(key)}" ${key === (a?.tipo || 'libre') ? 'selected' : ''}>${escape(label)}</option>`).join('');
    const today = localNow().slice(0, 10);
    $('staffAbsenceFrom').value = a?.desde || today; $('staffAbsenceTo').value = a?.hasta || today;
    $('staffAbsenceNote').value = a?.observacion || '';
    $('staffAbsenceReasonLabel').hidden = !a; $('staffAbsenceReason').required = !!a; $('staffAbsenceReason').value = '';
    $('staffAbsenceError').textContent = ''; state.dirty = false; $('staffAbsenceDialog').showModal();
}
async function download(url, name) {
    const blob = await api(url, {download:true}); const link = document.createElement('a'); const href = URL.createObjectURL(blob);
    link.href = href; link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(href), 1000);
}
async function guarded(task) {
    if (state.busy || !state.response) return; busy(true); $('staffError').textContent = '';
    try { await task(); } catch (error) { $('staffError').textContent = error.message; } finally { busy(false); }
}
async function showHistory(kind, id) {
    busy(true); $('staffHistoryTitle').textContent = kind === 'ausencia' ? 'Historial de la ausencia' : 'Historial de la jornada'; $('staffHistory').textContent = 'Consultando historial…'; $('staffHistoryDialog').showModal();
    try {
        const response = await api(`${base}/${kind === 'ausencia' ? 'ausencias' : 'jornadas'}/${id}/historial`);
        const label = (e) => e.accion.endsWith('_anulada') ? 'Anulación' : e.anterior ? (kind === 'ausencia' ? 'Corrección' : 'Cierre / corrección') : 'Registro';
        $('staffHistory').innerHTML = response.data.map((e) => `<article class="staff-history-event"><strong>${escape(e.responsable)} · ${label(e)}</strong><p>${escape(new Intl.DateTimeFormat('es-CL', {timeZone:state.response.data.meta.zona_horaria,dateStyle:'short',timeStyle:'short'}).format(new Date(e.created_at.replace(' ', 'T') + 'Z')))}</p><p>${escape(e.motivo || 'Registro inicial')}</p><details><summary>Ver valores anteriores y guardados</summary>${historyValues(e, kind)}</details></article>`).join('');
    } catch (error) { $('staffHistory').textContent = error.message; } finally { busy(false); }
}
function openCancel(kind, row) {
    state.cancel = {kind, row};
    $('staffCancelTitle').textContent = kind === 'ausencia' ? 'Anular ausencia' : 'Anular jornada';
    $('staffCancelDescription').textContent = kind === 'ausencia'
        ? `${row.persona.nombre} · ${row.tipo_nombre} · ${period(row)}. Se conservará el historial y dejará de contar días.`
        : `${row.persona.nombre} · ${display(row.ingreso)}. Se conservará el historial y dejará de sumar horas.`;
    $('staffCancelReason').value = ''; $('staffCancelError').textContent = ''; state.dirty = false; $('staffCancelDialog').showModal();
}
async function write(dialog, errorId, task) {
    if (state.busy) return;
    busy(true); $(errorId).textContent = '';
    try {
        await task(); state.dirty = false; $(dialog).close();
        try { await load(); $('staffStatus').textContent = 'Registro guardado. Control mensual actualizado.'; }
        catch (error) { $('staffError').textContent = `El registro se guardó, pero no se pudo actualizar la consulta. ${error.message}`; }
    } catch (error) { $(errorId).textContent = error.message + (error.status === 409 ? ' El borrador se conserva; cierra y vuelve a consultar para revisar la versión vigente.' : ''); }
    finally { busy(false); }
}
document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => { if (!state.busy && (!state.dirty || window.confirm('¿Descartar los cambios sin guardar?'))) { state.dirty = false; button.closest('dialog').close(); } }));
document.querySelectorAll('dialog').forEach((dialog) => { dialog.addEventListener('cancel', (event) => { if (state.busy || (state.dirty && !window.confirm('¿Descartar los cambios sin guardar?'))) event.preventDefault(); else state.dirty = false; }); dialog.addEventListener('input', (event) => { if (event.target.id !== 'staffPeopleSelect') state.dirty = true; }); });
window.addEventListener('beforeunload', (event) => { if (state.dirty) event.preventDefault(); });
$('staffPeopleOpen').addEventListener('click', openPeople);
$('staffPeopleSelect').addEventListener('change', () => { if (state.dirty && !window.confirm('¿Descartar los cambios de esta persona?')) { $('staffPeopleSelect').value = state.person?.id || ''; return; } state.person = state.response.personas.find((p) => p.id === $('staffPeopleSelect').value) || null; personForm(); });
$('staffNew').addEventListener('click', () => openEntry());
$('staffAbsenceNew').addEventListener('click', () => openAbsence());
$('staffAbsenceState').addEventListener('change', () => { if (state.response) renderAbsences(); });
$('staffAbsenceFrom').addEventListener('change', () => { if ($('staffAbsenceTo').value < $('staffAbsenceFrom').value) $('staffAbsenceTo').value = $('staffAbsenceFrom').value; });
$('staffEntryPerson').addEventListener('change', fillPersonDefaults);
$('staffState').addEventListener('change', () => { if (state.response) renderRecords(); });
$('staffFilters').addEventListener('submit', async (event) => { event.preventDefault(); if (state.busy) return; busy(true); $('staffError').textContent = ''; try { await load(); } catch (error) { $('staffError').textContent = error.message; } finally { busy(false); } });
$('staffPeopleForm').addEventListener('submit', (event) => { event.preventDefault(); void write('staffPeopleDialog', 'staffPeopleError', async () => {
    const payload = { nombre:$('staffName').value, identificador:$('staffIdentifier').value || null, sector:$('staffSector').value, funcion:$('staffFunction').value || null, jornada_base_minutos:minutes($('staffBase').value), activo:$('staffActive').checked, ...(state.person ? {version:state.person.version} : {}) };
    await api(`${base}/personas${state.person ? `/${state.person.id}` : ''}`, { method:state.person ? 'PUT' : 'POST', body:JSON.stringify(payload) });
}); });
$('staffEntryForm').addEventListener('submit', (event) => { event.preventDefault(); void write('staffEntryDialog', 'staffEntryError', async () => {
    const j = state.entry; const payload = { persona_id:$('staffEntryPerson').value, ingreso:$('staffIn').value, salida:$('staffOut').value || null, jornada_base_minutos:minutes($('staffEntryBase').value), colacion_minutos:Number($('staffBreak').value), sector:$('staffEntrySector').value, observacion:$('staffNote').value || null, ...(j ? {version:j.version,motivo:$('staffReason').value} : {}) };
    await api(`${base}/jornadas${j ? `/${j.id}` : ''}`, { method:j ? 'PUT' : 'POST', body:JSON.stringify(payload) });
}); });
$('staffRecords').addEventListener('click', async (event) => {
    const button = event.target.closest('button'); if (!button || state.busy) return;
    const id = button.dataset.edit || button.dataset.cancel || button.dataset.history;
    const row = state.response.data.jornadas.find((j) => j.id === id); if (!row) return;
    if (button.dataset.edit) return openEntry(row);
    if (button.dataset.cancel) return openCancel('jornada', row);
    await showHistory('jornada', id);
});
$('staffAbsences').addEventListener('click', async (event) => {
    const button = event.target.closest('button'); if (!button || state.busy) return;
    const id = button.dataset.absenceEdit || button.dataset.absenceCancel || button.dataset.absenceHistory;
    const row = (state.response.data.ausencias || []).find((a) => a.id === id); if (!row) return;
    if (button.dataset.absenceEdit) return openAbsence(row);
    if (button.dataset.absenceCancel) return openCancel('ausencia', row);
    await showHistory('ausencia', id);
});
$('staffSummary').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-sheet]'); if (!button) return;
    const row = state.response?.data.resumen.find((r) => r.persona_id === button.dataset.sheet); if (!row) return;
    void guarded(() => download(`${base}/ficha?${params({mes:state.filters.mes,persona_id:row.persona_id})}`, `ficha-personal-${state.filters.mes}.pdf`));
});
$('staffAbsenceForm').addEventListener('submit', (event) => { event.preventDefault(); void write('staffAbsenceDialog', 'staffAbsenceError', async () => {
    const a = state.absence; const payload = { persona_id:$('staffAbsencePerson').value, tipo:$('staffAbsenceType').value, desde:$('staffAbsenceFrom').value, hasta:$('staffAbsenceTo').value, observacion:$('staffAbsenceNote').value || null, ...(a ? {version:a.version,motivo:$('staffAbsenceReason').value} : {}) };
    await api(`${base}/ausencias${a ? `/${a.id}` : ''}`, { method:a ? 'PUT' : 'POST', body:JSON.stringify(payload) });
}); });
$('staffCancelForm').addEventListener('submit', (event) => { event.preventDefault(); const {kind,row} = state.cancel; void write('staffCancelDialog', 'staffCancelError', () => api(`${base}/${kind === 'ausencia' ? 'ausencias' : 'jornadas'}/${row.id}/anular`, {method:'POST',body:JSON.stringify({version:row.version,motivo:$('staffCancelReason').value})})); });
for (const [id,vista] of [['staffExportSummary','resumen'],['staffExportDetail','detalle'],['staffExportAbsences','ausencias']]) $(id).addEventListener('click', () => guarded(() => download(`${base}/exportar?${params({...state.filters,vista})}`, `control-personal-${state.filters.mes}-${vista}.xlsx`)));
$('staffSheetAll').addEventListener('click', () => guarded(() => download(`${base}/ficha?${params({mes:state.filters.mes,sector:state.filters.sector,persona_id:state.filters.persona_id})}`, `ficha-personal-${state.filters.mes}.pdf`)));
async function boot() {
    if (!token) { window.location.replace('/oficina/accesos'); return; }
    busy(true);
    try {
        const response = await api('/api/acceso-oficina');
        const identity = response.usuario;
        if (identity.puede_controlar_personal_frigorifico !== true && identity.capacidades?.puede_controlar_personal_frigorifico !== true) { window.location.replace('/oficina/accesos'); return; }
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity)); window.dispatchEvent(new Event('estiba:office-session'));
        $('staffMonth').value = localNow().slice(0,7); $('staffApp').classList.remove('is-hidden'); await load();
    } catch (error) { $('staffApp').classList.remove('is-hidden'); $('staffError').textContent = error.message; }
    finally { busy(false); }
}
void boot();
