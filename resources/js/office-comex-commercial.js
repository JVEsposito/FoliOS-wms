import { newOperationId } from './shared/rrhh-logic';
import { $, api, choices, escape, message, open, pages, roleLabels, safe, startCommercial, table, values } from './shared/comex-commercial-ui';

const path = '/api/comex/comercial';
const state = { context: null, rows: [], kind: 'partes', edit: null, operation: null, generation: 0 };
const placeLabels = { puerto: 'Puerto', terminal: 'Terminal', deposito: 'Depósito', entrega: 'Lugar de entrega' };
startCommercial(async () => {
    state.context = await api(`${path}/contexto`); $('ccNew').hidden = !state.context.permisos.gestionar;
    for (const f of [$('ccPartyForm'), $('ccPlaceForm')]) f.elements.pais_iso2.innerHTML = choices(state.context.paises.map((p) => [p.iso2, `${p.iso2} · ${p.nombre}`]), 'Seleccione país');
    $('ccPartyRoles').innerHTML = state.context.roles.map((r) => `<label><input type="checkbox" name="roles" value="${r}"><span>${escape(roleLabels[r])}</span></label>`).join('');
    $('ccPlaceForm').elements.tipo.innerHTML = choices(state.context.tipos_lugar.map((t) => [t, placeLabels[t]]));
    await load();
});
$('ccSearch').addEventListener('submit', (e) => { e.preventDefault(); safe(() => load()); });
$('ccNew').onclick = () => safe(() => edit());
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cc-action]'); if (!b) return;
    safe(async () => {
        if (b.dataset.ccAction === 'edit') return edit(state.rows.find((r) => r.id === b.dataset.id));
        if (b.dataset.ccAction === 'clients') return picker('cliente');
        if (b.dataset.ccAction === 'ports') return picker('puerto');
        const data = await api(`${path}/historial?${new URLSearchParams({ entidad: state.kind === 'partes' ? 'parte' : 'lugar', entidad_id: b.dataset.id })}`);
        $('ccHistory').innerHTML = table(['Fecha / responsable', 'Cambio', 'Motivo / respaldo'], data.data.data.map((r) => `<tr><td>${escape(r.created_at)}<small>${escape(r.user_nombre)}</small></td><td>${escape(r.accion)}<small>Versión ${r.datos.despues.version} · ${escape(r.datos.despues.razon_social || r.datos.despues.nombre)} · ${r.datos.despues.activo ? 'Activo' : 'Inactivo'}</small></td><td>${escape(r.datos.motivo || r.datos.despues.respaldo)}</td></tr>`).join('')); open('ccHistoryDialog');
    });
});
for (const form of [$('ccPartyForm'), $('ccPlaceForm')]) {
    form.addEventListener('input', () => { state.operation = null; });
    form.addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try {
            const d = values(form); d.activo = d.activo === '1'; d.version = state.edit?.version || 0; state.operation ??= newOperationId(); d.operacion_id = state.operation;
            if (form.id === 'ccPartyForm') d.roles = new FormData(form).getAll('roles');
            if (state.edit) { d.pais_iso2 = state.edit.pais_iso2; if (form.id === 'ccPlaceForm') d.tipo = state.edit.tipo; }
            await api(`${path}/${state.kind}${state.edit ? `/${state.edit.id}` : ''}`, state.edit ? 'PUT' : 'POST', d);
            form.closest('dialog').close(); await load(); message('Ficha guardada con historial.');
        } catch (error) { form.querySelector('[data-cc-error]').textContent = error.message; } finally { b.disabled = false; }
    });
}
async function load(page = 1) {
    const generation = ++state.generation; const f = $('ccSearch'); state.kind = f.elements.catalogo.value;
    const data = await api(`${path}/${state.kind}?${new URLSearchParams({ buscar: f.elements.buscar.value, page })}`); if (generation !== state.generation) return;
    state.rows = data.data.data;
    $('ccRows').innerHTML = table(['Identidad / lugar', 'Roles / ubicación', 'Estado', 'Acciones'], state.rows.map((r) => `<tr><td><strong>${escape(r.razon_social || r.nombre)}</strong><small>${escape(r.codigo)} · ${escape(r.pais_iso2)} · ${escape(r.tax_id || r.codigo_externo)}</small></td><td>${escape(r.roles ? r.roles.map((s) => roleLabels[s]).join(', ') : placeLabels[r.tipo])}<small>${escape(r.ciudad || r.localidad)}</small></td><td>${r.activo ? 'Activo' : 'Inactivo'} · v${r.version}</td><td>${state.context.permisos.gestionar ? `<button type="button" data-cc-action="edit" data-id="${r.id}">Editar</button>` : ''}<button type="button" data-cc-action="history" data-id="${r.id}">Historial</button></td></tr>`).join(''));
    pages('ccPages', data.data, load);
}
async function edit(row = null) {
    state.edit = row; state.operation = null; const party = state.kind === 'partes'; const f = party ? $('ccPartyForm') : $('ccPlaceForm'); f.reset();
    for (const [k, v] of Object.entries(row || {})) if (f.elements[k] && k !== 'roles') f.elements[k].value = k === 'activo' ? (v ? '1' : '0') : v ?? '';
    for (const k of party ? ['codigo', 'pais_iso2', 'tax_id'] : ['codigo', 'pais_iso2', 'tipo']) { f.elements[k].disabled = !!row && ['pais_iso2', 'tipo'].includes(k); f.elements[k].readOnly = !!row; }
    f.elements.motivo.required = !!row; f.elements.motivo.closest('label').hidden = !row; f.elements.motivo.disabled = !row;
    if (party) { for (const c of f.querySelectorAll('[name=roles]')) c.checked = !!row?.roles.includes(c.value); $('ccClientSearch').value = ''; await picker('cliente'); }
    else { $('ccPortSearch').value = ''; await picker('puerto'); }
    open(party ? 'ccPartyDialog' : 'ccPlaceDialog');
}
async function picker(kind) {
    const client = kind === 'cliente'; const data = await api(`${path}/${client ? 'clientes-operacion' : 'lugares'}?${new URLSearchParams({ buscar: $(client ? 'ccClientSearch' : 'ccPortSearch').value, ...(client ? {} : { tipo: 'puerto', activo: '1' }) })}`);
    const rows = data.data.data; const select = $(client ? 'ccPartyForm' : 'ccPlaceForm').elements[client ? 'cliente_operacion_id' : 'puerto_id'];
    const current = select.value || state.edit?.[client ? 'cliente_operacion_id' : 'puerto_id'];
    const options = rows.map((r) => [r.id, `${r.codigo} · ${r.nombre}`]);
    if (current && !options.some(([id]) => id === current)) options.push([current, client ? state.edit?.cliente_operacion?.nombre || 'Vínculo actual' : 'Puerto vinculado actual']);
    select.innerHTML = choices(options, client ? 'Sin vínculo con cliente de la planta' : 'Sin puerto asociado'); select.value = current || '';
}
