import { newOperationId } from './shared/rrhh-logic';

export function initDescuentos({ api, capability, escape, table, choices, open, pages, message }) {
    const $ = (id) => document.getElementById(id);
    const state = { person: null, mandates: [], contracts: [], quota: null, operation: null, institution: null };
    const canWrite = () => capability('puede_gestionar_remuneraciones_rrhh');
    const safe = async (fn) => { try { await fn(); } catch (e) { message(e.message); } };
    const values = (form) => Object.fromEntries([...new FormData(form)].filter(([, v]) => v !== ''));
    const money = (v) => `${new Intl.NumberFormat('es-CL').format(v ?? 0)} CLP`;
    document.addEventListener('rrhh:ficha', (e) => { state.person = e.detail; safe(async () => { await Promise.all([mandates(), contracts()]); }); });
    $('rdNewMandate').addEventListener('click', () => safe(async () => { const f = $('rdMandateForm'); f.reset(); await contracts(); f.elements.empleador_id.innerHTML = choices([...new Map(state.contracts.filter((c) => c.empleador.tipo === 'propio' && c.tipo !== 'honorarios').map((c) => [c.empleador_id, [c.empleador_id, c.empleador.razon_social]])).values()], 'Seleccione empleador propio'); await conceptPicker(); await institutionPicker(f); open('rdMandateDialog'); }));
    $('rdConceptSearch').addEventListener('input', () => safe(() => conceptPicker($('rdConceptSearch').value)));
    for (const input of document.querySelectorAll('[data-rd-institution-search]')) input.addEventListener('input', () => safe(() => institutionPicker(input.form, input.value)));
    for (const id of ['rnConceptDialog', 'rnMovementDialog']) new MutationObserver(() => { if ($(id).open) safe(() => institutionPicker($(id).querySelector('form'))); }).observe($(id), { attributes: true, attributeFilter: ['open'] });
    $('rdMandateForm').elements.concepto_id.addEventListener('change', mandateFields);
    for (const form of document.querySelectorAll('[data-descuentos-form]')) form.addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try {
            const d = values(form);
            if (form.id === 'rdMandateForm') { d.pago_directo_institucion = d.pago_directo_institucion === '1'; if (d.cuotas) d.cuotas = Number(d.cuotas); await api('/api/rrhh/nomina/mandatos', 'POST', { ...d, persona_id: state.person.id }); await mandates(); }
            else { await api('/api/rrhh/nomina/movimientos', 'POST', quotaData()); $('rnLoad').click(); }
            form.closest('dialog').close(); message('Registro guardado. Las cuotas se descuentan al aprobar su novedad.');
        } catch (error) { form.querySelector('[data-rh-error]').textContent = error.message; } finally { b.disabled = false; }
    });
    $('rdSimulateQuota').addEventListener('click', () => safe(async () => { const d = await api('/api/rrhh/nomina/simular', 'POST', quotaData()); $('rdQuotaPreview').textContent = `Cuota: ${money(d.data.total)} · ${d.data.institucion.razon_social}. Se guardará como borrador.`; }));
    $('rdAccountForm').addEventListener('submit', (e) => { e.preventDefault(); safe(() => account()); });
    document.addEventListener('click', (e) => { const b = e.target.closest('[data-rd-action]'); if (b) safe(() => action(b)); });
    async function mandates(page = 1) {
        const id = state.person.id; const d = await api(`/api/rrhh/nomina/mandatos?persona_id=${id}&page=${page}`); if (id !== state.person.id) return;
        state.mandates = d.data?.data || [];
        $('rdMandates').innerHTML = table(['Concepto e institución', 'Cuota y vigencia', 'Descontado / saldo', 'Acciones'], state.mandates.map((m) => `<tr><td>${escape(m.concepto.nombre)}<small>${escape(m.institucion.razon_social)} · ${escape(m.referencia)}</small></td><td>${m.concepto.calculo === 'porcentaje' ? `${escape(m.cuota)}%` : money(m.cuota)}<small>${m.desde} → ${m.hasta}</small></td><td>${money(m.descontado)}<small>${m.saldo_por_descontar === null ? 'Sin deuda total pactada' : `Por descontar: ${money(m.saldo_por_descontar)}`} · ${m.cuotas_descontadas}${m.cuotas ? ` / ${m.cuotas}` : ''} cuotas</small></td><td>${canWrite() ? `<button type="button" data-rd-action="quota" data-id="${m.id}">Preparar cuota</button><button type="button" data-rd-action="end" data-id="${m.id}">Acortar vigencia</button>` : ''}<button type="button" data-rd-action="history" data-id="${m.id}">Historial</button></td></tr>`).join(''));
        if (d.data) pages('rdMandatePages', d.data, mandates);
    }
    let generation = 0;
    async function conceptPicker(buscar = '') {
        const request = ++generation; const d = await api(`/api/rrhh/nomina/conceptos?${new URLSearchParams({ buscar })}`); if (request !== generation) return;
        const f = $('rdMandateForm'); f._concepts = d.data.data.filter((c) => c.activo && c.tipo === 'descuento'); f.elements.concepto_id.innerHTML = choices(f._concepts.map((c) => [c.id, `${c.codigo} · ${c.nombre}`]), 'Seleccione descuento'); mandateFields();
    }
    function mandateFields() {
        const f = $('rdMandateForm'); const percent = f._concepts?.find((c) => c.id === f.elements.concepto_id.value)?.calculo === 'porcentaje';
        for (const k of ['deuda_total', 'cuotas']) { f.elements[k].disabled = percent; f.elements[k].required = false; }
        f.elements.base_porcentaje.disabled = !percent; f.elements.base_porcentaje.required = percent;
        $('rdQuotaLabel').textContent = percent ? 'Porcentaje autorizado' : 'Monto mensual de la cuota en CLP';
        const apv = f._concepts?.find((c) => c.id === f.elements.concepto_id.value)?.grupo_descuento === 'apv'; $('rdApv').hidden = !apv; f.elements.regimen_apv.disabled = !apv; f.elements.regimen_apv.required = apv;
    }
    const requests = new WeakMap();
    async function contracts() {
        const id = state.person.id; const d = await api(`/api/rrhh/prevision/contratos?${new URLSearchParams({ persona_id: id, vigente_en: `${$('rnMonth').value}-01` })}`); if (id !== state.person.id) return;
        state.contracts = d.data?.data || []; $('rnMovementForm').elements.contrato_id.innerHTML = choices(state.contracts.map((c) => [c.id, `${c.empleador.razon_social} · ${c.referencia}`]), 'Usar contrato propio único vigente');
    }
    async function institutionPicker(form, buscar = '') {
        const request = (requests.get(form) || 0) + 1; requests.set(form, request);
        const select = form.elements.institucion_id; const selected = select.value;
        const d = await api(`/api/rrhh/prevision/directorio/institucion?${new URLSearchParams({ buscar })}`); if (requests.get(form) !== request) return;
        const rows = d.data?.data || [];
        select.innerHTML = choices(rows.filter((i) => i.activo).map((i) => [i.id, `${i.rut} · ${i.razon_social}`]), form.id === 'rdMandateForm' ? 'Seleccione institución; busque si no aparece' : 'Sin cambio / usar destino del concepto');
        if ([...select.options].some((o) => o.value === selected)) select.value = selected;
    }
    function quotaData() { return { ...values($('rdQuotaForm')), operacion_id: state.operation, persona_id: state.person.id, concepto_id: state.quota.concepto_id, mandato_id: state.quota.id }; }
    async function account(page = 1) {
        const d = await api(`/api/rrhh/nomina/por-institucion?${new URLSearchParams({ institucion_id: state.institution, mes: $('rdAccountForm').elements.mes.value, page })}`);
        $('rdAccountTotals').textContent = `Descuentos aprobados: ${money(d.totales_aprobados.descuento)} · Aportes del empleador: ${money(d.totales_aprobados.aporte_empleador)}`;
        $('rdAccountRows').innerHTML = table(['Fecha y persona', 'Concepto', 'Importe / estado'], d.data.data.map((m) => `<tr><td>${m.fecha}<small>${escape(m.persona_nombre || m.persona_id)}</small></td><td>${escape(m.calculo.concepto.nombre)}</td><td>${money(m.total)}<small>${escape(m.estado)}</small></td></tr>`).join('')); pages('rdAccountPages', d.data, account);
    }
    async function action(b) {
        const { rdAction: action, id } = b.dataset;
        if (action === 'account') { state.institution = id; $('rdAccountForm').elements.mes.value = $('rnMonth').value; await account(); return open('rdAccountDialog'); }
        const m = state.mandates.find((m) => m.id === id);
        if (action === 'quota') { state.quota = m; state.operation = newOperationId(); $('rdQuotaForm').reset(); $('rdQuotaForm').elements.fecha.value = `${$('rnMonth').value}-01`; $('rdQuotaForm').elements.respaldo.value = m.respaldo; $('rdQuotaTitle').textContent = `${m.concepto.nombre} · ${m.institucion.razon_social}`; $('rdQuotaPreview').textContent = ''; return open('rdQuotaDialog'); }
        if (action === 'history') { const d = await api(`/api/rrhh/nomina/historial?entidad=mandato_descuento&registro_id=${id}`); $('rhDetailTitle').textContent = 'Historial del mandato'; $('rhDetail').textContent = JSON.stringify(d.data.data, null, 2); return open('rhDetailDialog'); }
        const hasta = prompt('Último día de vigencia inclusive (AAAA-MM-DD):', m.hasta); if (!hasta || hasta === m.hasta) return;
        const motivo = prompt('Motivo y respaldo (mínimo 10 caracteres):'); if (!motivo) return;
        await api(`/api/rrhh/nomina/mandatos/${id}`, 'PATCH', { hasta, hasta_actual: m.hasta, motivo }); await mandates();
    }
}
