import { newOperationId } from './shared/rrhh-logic';

export function initContratistas({ api, capability, escape, table, choices, open, pages, message }) {
    const $ = (id) => document.getElementById(id);
    const path = '/api/rrhh/contratistas';
    const state = { company: null, file: null, docs: [], entries: [], people: [], selected: new Map(), editing: false, review: null, operation: null, generation: 0 };
    const labels = { pendiente: 'Pendiente', conforme: 'Conforme', observado: 'Observado', descartado: 'Descartado', f30: 'F30', f301: 'F30-1', remuneraciones: 'Remuneraciones', pago_remuneraciones: 'Pago de remuneraciones', cotizaciones: 'Cotizaciones', contratos: 'Contratos laborales', registro_est: 'Registro de EST', otro: 'Otro', retencion: 'Retención', liberacion: 'Liberación', pago_subrogacion: 'Pago por subrogación' };
    const canWrite = () => capability('puede_gestionar_remuneraciones_rrhh');
    const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: document.body.dataset.rrhhZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const money = (v) => `${new Intl.NumberFormat('es-CL').format(v)} CLP`;
    const safe = async (fn) => { try { await fn(); } catch (e) { message(e.message); } };
    const values = (form) => Object.fromEntries([...new FormData(form)].filter(([, v]) => v !== ''));
    const show = (id) => { if (!$(id).open) open(id); };
    const button = (action, text, id = '') => `<button type="button" data-rc-action="${action}" data-id="${escape(id)}">${text}</button>`;
    document.addEventListener('click', (e) => {
        const company = e.target.closest('[data-rc-company]');
        if (company) safe(async () => { state.company = company.dataset.rcCompany; $('rcSearch').reset(); await files(); show('rcCompanyDialog'); });
        const b = e.target.closest('[data-rc-action]'); if (b) safe(() => action(b));
    });
    $('rcSearch').addEventListener('submit', (e) => { e.preventDefault(); safe(() => files()); });
    for (const form of document.querySelectorAll('[data-contratistas-form]:not(#rcSearch)')) form.addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try { await save(form); form.closest('dialog').close(); message('Registro guardado con historial.'); }
        catch (error) { form.querySelector('[data-rh-error]').textContent = error.message; } finally { b.disabled = false; }
    });
    $('rcPersonalForm').elements.mes.addEventListener('change', () => { state.selected.clear(); selected(); safe(() => people()); });
    $('rcPeople').addEventListener('change', (e) => { const p = state.people.find((p) => p.id === e.target.dataset.rcPerson); if (!p) return; if (e.target.checked) state.selected.set(p.id, p); else state.selected.delete(p.id); selected(); });
    $('rcEvidenceForm').elements.cubre_todos.addEventListener('change', coverage);
    $('rcRetentionForm').elements.tipo.addEventListener('change', destination);
    $('rcRetentionForm').addEventListener('input', () => { state.operation = null; });
    async function files(page = 1) {
        const company = state.company; const data = await api(`${path}/expedientes?${new URLSearchParams({ empleador_id: company, mes: $('rcSearch').elements.mes.value, page })}`);
        if (company !== state.company) return;
        $('rcCompanyTitle').textContent = `Cumplimiento · ${data.empleador.razon_social}`;
        $('rcFiles').innerHTML = table(['Faena / mes', 'Revisión interna', 'Acciones'], data.data.data.map((r) => `<tr><td>${escape(r.faena)} · ${escape(r.mes)}<small>${r.personas_count} personas · ${escape(r.referencia_contrato)}</small></td><td>${escape(labels[r.estado])}<small>${escape(r.observacion)}</small></td><td>${button('detail', 'Ver expediente', r.id)}</td></tr>`).join(''));
        pages('rcFilePages', data.data, files);
    }
    async function detail(id = state.file.id) {
        const data = await api(`${path}/expedientes/${id}`); state.file = data.data;
        $('rcFileTitle').textContent = `${state.file.faena} · ${state.file.mes}`;
        $('rcSummary').textContent = `${labels[state.file.estado]} · ${state.file.personas_count} personas · ${state.file.observacion || 'Sin revisión final'}${state.file.revisado_por ? ` · Revisó ${state.file.revisado_por}` : ''}`;
        $('rcMissing').textContent = data.faltantes.length ? `Por resolver: ${data.faltantes.map((v) => v.replace(/^[a-z_0-9]+(?=:)/, (m) => labels[m] || m)).join('; ')}` : 'Cobertura documental revisada completa; la conformidad del expediente requiere revisión expresa y mes concluido.';
        $('rcExpected').innerHTML = table(['Código', 'Persona'], state.file.personal.map((p) => `<tr><td>${escape(p.codigo)}</td><td>${escape(p.nombre)}</td></tr>`).join(''));
        await Promise.all([evidence(), retentions()]); show('rcFileDialog');
    }
    async function evidence(page = 1) {
        const data = await api(`${path}/expedientes/${state.file.id}/antecedentes?page=${page}`); state.docs = data.data.data;
        $('rcEvidence').innerHTML = table(['Antecedente / período', 'Cobertura y revisión', 'Acciones'], state.docs.map((r) => `<tr><td>${escape(labels[r.tipo])}<small>${escape(r.desde)} → ${escape(r.hasta)}</small><small>${escape(r.referencia)}</small></td><td>${r.personas.length} personas · ${escape(labels[r.estado])}<small>${escape(r.observacion)} ${escape(r.revisado_por)}</small></td><td>${canWrite() ? button('reviewDoc', 'Revisar', r.id) : ''}${button('docHistory', 'Historial', r.id)}</td></tr>`).join(''));
        pages('rcEvidencePages', data.data, evidence);
    }
    async function retentions(page = 1) {
        const data = await api(`${path}/expedientes/${state.file.id}/retenciones?page=${page}`); state.entries = data.data.data;
        $('rcBalance').textContent = `Saldo retenido anotado: ${money(data.saldo_retenido)}`;
        $('rcRetentions').innerHTML = table(['Movimiento', 'Monto / destino', 'Acciones'], state.entries.map((r) => `<tr><td>${escape(labels[r.tipo])} · ${escape(r.fecha)}${r.anulado_at ? '<small>Anulado</small>' : ''}<small>${escape(r.referencia)}</small></td><td>${money(r.monto)}<small>${escape(r.destinatario?.razon_social || r.destinatario?.nombre)}</small></td><td>${canWrite() && !r.anulado_at ? button('void', 'Anular anotación', r.id) : ''}${button('entryHistory', 'Historial', r.id)}</td></tr>`).join(''));
        pages('rcRetentionPages', data.data, retentions);
    }
    async function personal(editing = false) {
        state.editing = editing; state.selected = new Map(editing ? state.file.personal.map((p) => [p.id, p]) : []);
        const f = $('rcPersonalForm'); f.reset(); $('rcPersonSearch').value = '';
        $('rcPersonalTitle').textContent = editing ? 'Cambiar personal esperado' : 'Abrir expediente';
        for (const k of ['faena', 'mes', 'referencia_contrato']) { f.elements[k].readOnly = editing; f.elements[k].value = editing ? state.file[k] : k === 'mes' ? today().slice(0, 7) : ''; }
        $('rcPersonalReason').hidden = !editing; f.elements.motivo.disabled = !editing; f.elements.motivo.required = editing;
        selected(); await people(); show('rcPersonalDialog');
    }
    async function people(page = 1) {
        const generation = ++state.generation; const mes = $('rcPersonalForm').elements.mes.value; if (!mes) return;
        const data = await api(`${path}/dotacion?${new URLSearchParams({ empleador_id: state.company, mes, buscar: $('rcPersonSearch').value, page })}`);
        if (generation !== state.generation) return;
        state.people = data.data.data;
        $('rcPeople').innerHTML = table(['Personal con contrato en el mes'], state.people.map((p) => `<tr><td><label><input type="checkbox" data-rc-person="${p.id}" ${state.selected.has(p.id) ? 'checked' : ''}> ${escape(p.codigo)} · ${escape(p.nombre)}</label></td></tr>`).join(''));
        pages('rcPeoplePages', data.data, people);
    }
    function selected() {
        $('rcSelected').innerHTML = `<p>${state.selected.size} personas seleccionadas</p>` + [...state.selected.values()].map((p) => `<p>${escape(p.codigo)} · ${escape(p.nombre)} ${button('removePerson', 'Quitar', p.id)}</p>`).join('');
    }
    function coverage() { const f = $('rcEvidenceForm'); const all = f.elements.cubre_todos.value === '1'; $('rcCoverage').hidden = all; f.elements.personas.disabled = all; }
    function destination() { const f = $('rcRetentionForm'); const show = f.elements.tipo.value === 'pago_subrogacion'; $('rcDestination').hidden = !show; for (const k of ['institucion_id', 'persona_destino_id']) f.elements[k].disabled = !show; }
    async function institutions() {
        const data = await api(`/api/rrhh/prevision/directorio/institucion?${new URLSearchParams({ buscar: $('rcInstitutionSearch').value })}`);
        $('rcRetentionForm').elements.institucion_id.innerHTML = choices(data.data.data.map((r) => [r.id, `${r.rut} · ${r.razon_social}`]), 'Sin institución / elegir trabajador');
    }
    async function save(form) {
        const d = values(form);
        if (form.id === 'rcPersonalForm') {
            if (!state.selected.size) throw new Error('Seleccione al menos una persona de la faena.');
            const data = state.editing ? await api(`${path}/expedientes/${state.file.id}/personal`, 'PATCH', { personal: [...state.selected.keys()], version: state.file.version, motivo: d.motivo }) : await api(`${path}/expedientes`, 'POST', { ...d, empleador_id: state.company, personal: [...state.selected.keys()] });
            await files(); return detail(data.data.id);
        }
        if (form.id === 'rcEvidenceForm') await api(`${path}/expedientes/${state.file.id}/antecedentes`, 'POST', { ...d, personas: [...form.elements.personas.selectedOptions].map((o) => o.value), cubre_todos: d.cubre_todos === '1', version_expediente: state.file.version });
        if (form.id === 'rcReviewForm') await api(state.review.kind === 'exp' ? `${path}/expedientes/${state.file.id}/revision` : `${path}/antecedentes/${state.review.row.id}/revision`, 'PATCH', { ...d, version: state.review.row.version });
        if (form.id === 'rcRetentionForm') { state.operation ??= newOperationId(); await api(`${path}/expedientes/${state.file.id}/retenciones`, 'POST', { ...d, operacion_id: state.operation }); }
        await detail(); await files();
    }
    async function action(b) {
        const { rcAction: a, id } = b.dataset;
        if (a === 'detail') return detail(id);
        if (a === 'newFile' || a === 'editPersonal') return personal(a === 'editPersonal');
        if (a === 'findPeople') return people();
        if (a === 'removePerson') { state.selected.delete(id); selected(); return people(); }
        if (a === 'evidence') {
            const f = $('rcEvidenceForm'); f.reset(); f.elements.desde.value = `${state.file.mes}-01`;
            const [year, month] = state.file.mes.split('-').map(Number); f.elements.hasta.value = `${state.file.mes}-${new Date(year, month, 0).getDate()}`;
            f.elements.fecha_documento.value = today(); f.elements.personas.innerHTML = choices(state.file.personal.map((p) => [p.id, `${p.codigo} · ${p.nombre}`])); coverage(); return show('rcEvidenceDialog');
        }
        if (a === 'reviewExp' || a === 'reviewDoc') {
            state.review = { kind: a === 'reviewExp' ? 'exp' : 'doc', row: a === 'reviewExp' ? state.file : state.docs.find((r) => r.id === id) };
            const f = $('rcReviewForm'); f.reset(); $('rcReviewTitle').textContent = `Revisión interna · ${a === 'reviewExp' ? 'expediente' : labels[state.review.row.tipo]}`;
            const row = state.review.row;
            $('rcReviewContext').textContent = a === 'reviewExp' ? `${state.file.faena} · ${state.file.mes} · ${state.file.personas_count} personas` : `${row.referencia} · Emitido ${row.fecha_documento} · Cubre ${row.desde} → ${row.hasta} · Personal: ${row.personas.map((id) => { const p = state.file.personal.find((p) => p.id === id); return p ? `${p.codigo} ${p.nombre}` : `${id} (fuera de la dotación actual)`; }).join(', ') || 'Antecedente de la empresa, sin nómina individual'}`;
            f.elements.estado.innerHTML = choices((a === 'reviewExp' ? ['pendiente', 'conforme', 'observado'] : ['pendiente', 'conforme', 'observado', 'descartado']).map((s) => [s, labels[s]])); f.elements.estado.value = state.review.row.estado; return show('rcReviewDialog');
        }
        if (a === 'retention') {
            const f = $('rcRetentionForm'); f.reset(); state.operation = null; f.elements.fecha.value = today(); $('rcInstitutionSearch').value = '';
            f.elements.persona_destino_id.innerHTML = choices(state.file.personal.map((p) => [p.id, `${p.codigo} · ${p.nombre}`]), 'Sin trabajador / elegir institución'); await institutions(); destination(); return show('rcRetentionDialog');
        }
        if (a === 'findInstitution') return institutions();
        if (a === 'void') {
            const motivo = prompt('Motivo y respaldo de la anulación (mínimo 10 caracteres):'); if (!motivo) return;
            await api(`${path}/retenciones/${id}/anular`, 'PATCH', { motivo }); return retentions();
        }
        const entity = { history: 'expediente_contratista', docHistory: 'antecedente_contratista', entryHistory: 'retencion_contratista' }[a];
        if (entity) {
            const data = await api(`${path}/historial?${new URLSearchParams({ entidad: entity, registro_id: id || state.file.id })}`);
            $('rhDetailTitle').textContent = 'Historial del control de contratistas';
            $('rhDetail').innerHTML = table(['Fecha / acción', 'Responsable', 'Respaldo'], data.data.data.map((r) => `<tr><td>${escape(r.created_at)}<small>${escape(r.accion)}</small></td><td>${escape(r.user_nombre)}</td><td>${escape(r.datos?.motivo || r.datos?.observacion || r.datos?.actual?.observacion || r.datos?.respaldo)}</td></tr>`).join('')); show('rhDetailDialog');
        }
    }
}
