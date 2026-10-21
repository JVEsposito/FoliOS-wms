import { newOperationId } from './shared/rrhh-logic';

export function initNomina({ api, capability, escape, table, choices, open, pages, message }) {
    const $ = (id) => document.getElementById(id);
    const canRead = () => capability('puede_consultar_remuneraciones_rrhh');
    const canWrite = () => capability('puede_gestionar_remuneraciones_rrhh');
    const state = { cargos: [], concepts: [], person: null, people: [], cargo: null, movements: [], pact: null, operation: null, page: 1, movementPage: 1 };
    const safe = async (fn) => { try { await fn(); } catch (e) { message(e.message); } };
    const labels = { haber: 'Haber', descuento: 'Descuento', aporte_empleador: 'Aporte empleador', monto: 'Monto', porcentaje: 'Porcentaje', horas_extra: 'Horas extra', voluntario: 'Voluntario 15%', vivienda: 'Vivienda / educación 30%', cooperativa: 'Cooperativa', obligatorio: 'Obligatorio', judicial: 'Judicial', ccaf: 'Crédito CCAF', sindical: 'Cuota sindical', apv: 'APV' };
    const money = (v) => `${new Intl.NumberFormat('es-CL').format(v)} CLP`;
    const values = (form) => Object.fromEntries(new FormData(form));
    function permissions() {
        $('rnTab').hidden = !canRead();
        for (const el of document.querySelectorAll('[data-rn-manage]')) el.hidden = !canWrite();
    }
    // Identity may arrive after the login form completes.
    new MutationObserver(permissions).observe($('officeApp'), { attributes: true, attributeFilter: ['class'] }); permissions();
    $('rnMonth').value = new Intl.DateTimeFormat('en-CA', { timeZone: document.body.dataset.rrhhZone, year: 'numeric', month: '2-digit' }).format(new Date()).slice(0, 7);
    document.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-rh-tab]');
        if (tab?.dataset.rhTab === 'cargos') safe(() => loadCargos());
        if (tab?.dataset.rhTab === 'nomina' && canRead()) safe(() => loadConcepts());
        const action = e.target.closest('[data-rn-action]'); if (action) safe(() => rowAction(action));
    });
    $('rnCargoSearch').addEventListener('submit', (e) => { e.preventDefault(); safe(() => loadCargos()); });
    $('rnPersonSearch').addEventListener('submit', (e) => { e.preventDefault(); safe(searchPeople); });
    $('rnLoad').addEventListener('click', () => safe(() => loadFicha()));
    $('rnPerson').addEventListener('change', () => { $('rnFicha').hidden = true; state.person = null; });
    $('rnNewCargo').addEventListener('click', () => openCargo());
    $('rnNewConcept').addEventListener('click', () => { $('rnConceptForm').reset(); open('rnConceptDialog'); });
    $('rnNewParameter').addEventListener('click', () => { $('rnParameterForm').reset(); open('rnParameterDialog'); });
    $('rnNewCondition').addEventListener('click', () => safe(async () => { $('rnConditionForm').reset(); await cargoPicker(''); open('rnConditionDialog'); }));
    $('rnConditionCargoSearch').addEventListener('input', () => safe(() => cargoPicker($('rnConditionCargoSearch').value)));
    $('rnNewPact').addEventListener('click', () => safe(async () => { $('rnPactForm').reset(); await conceptPicker($('rnPactForm')); open('rnPactDialog'); }));
    $('rnNewMovement').addEventListener('click', () => safe(() => openMovement()));
    $('rnSimulate').addEventListener('click', () => safe(async () => { const data = await api('/api/rrhh/nomina/simular', 'POST', movementData()); showCalculation(data.data); }));
    $('rnRefreshOutbox').addEventListener('click', () => safe(() => loadOutbox()));
    async function loadOutbox(page = 1) {
        const data = await api(`/api/rrhh/nomina/entregas?page=${page}`);
        $('rnOutbox').innerHTML = table(['Evento / secuencia', 'Acción y movimiento', 'Contenido'], data.data.data.map((e) => `<tr><td>${escape(e.id)}<small>Secuencia ${e.secuencia}</small></td><td>${escape(e.contenido.accion)}<small>${escape(e.movimiento_id)}</small></td><td><details><summary>Ver datos y huella</summary><pre class="rh-history">${escape(JSON.stringify({ contenido: e.contenido, huella: e.huella },null,2))}</pre></details></td></tr>`).join(''));
        pages('rnOutboxPages',data.data,loadOutbox);
    }
    for (const form of document.querySelectorAll('[data-nomina-form]')) form.addEventListener('submit', async (e) => {
        e.preventDefault(); if (e.submitter.disabled) return; e.submitter.disabled = true; form.querySelector('[data-rh-error]').textContent = '';
        try { await save(form); form.closest('dialog').close(); message('Registro guardado con historial.'); }
        catch (error) { form.querySelector('[data-rh-error]').textContent = error.message; } finally { e.submitter.disabled = false; }
    });
    for (const search of document.querySelectorAll('[data-rn-concept-search]')) search.addEventListener('input', () => safe(() => conceptPicker(search.form, search.value)));
    $('rnMovementForm').elements.concepto_id.addEventListener('change', movementFields);
    async function loadCargos(page = 1) {
        const data = await api(`/api/rrhh/cargos?${new URLSearchParams({ page, buscar: $('rnCargoSearch').elements.buscar.value })}`); state.cargos = data.data.data;
        $('rnCargos').innerHTML = table(['Cargo', 'Estado', 'Acciones'], state.cargos.map((c) => `<tr><td><strong>${escape(c.nombre)}</strong><small>${escape(c.codigo)} · ${escape(c.descripcion)}</small></td><td>${c.activo ? 'Activo' : 'Inactivo'}</td><td>${capability('puede_gestionar_rrhh') ? `<button type="button" data-rn-action="cargo" data-id="${c.id}">Editar</button>` : ''}</td></tr>`).join(''));
        pages('rnCargoPages', data.data, loadCargos);
    }
    async function loadConcepts(page = 1) {
        const data = await api(`/api/rrhh/nomina/conceptos?page=${page}`); state.concepts = data.data.data;
        $('rnConcepts').innerHTML = table(['Concepto', 'Cálculo y tratamiento', 'Acciones'], state.concepts.map((c) => `<tr><td><strong>${escape(c.nombre)}</strong><small>${escape(c.codigo)} · ${labels[c.tipo]} · ${c.activo ? 'Activo' : 'Inactivo'}</small></td><td>${labels[c.calculo]}<small>${c.remuneracion ? 'Remuneracional' : 'No remuneracional'} · ${c.imponible ? 'Imponible' : 'No imponible'} · ${c.tributable ? 'Tributable' : 'No tributable'}${c.base_he ? ' · Integra base HE' : ''}</small><small>${escape(labels[c.grupo_descuento] || '')}</small></td><td><button type="button" data-rn-action="history" data-entity="concepto_nomina" data-id="${c.id}">Historial</button>${canWrite() ? `<button type="button" data-rn-action="concept-state" data-id="${c.id}">${c.activo ? 'Desactivar' : 'Activar'}</button>` : ''}</td></tr>`).join(''));
        pages('rnConceptPages', data.data, loadConcepts);
        $('rnParameters').innerHTML = table(['Vigencia', 'General / otros tramos', 'Respaldo'], data.parametros.map((p) => `<tr><td>${escape(p.desde)} → ${escape(p.hasta)}</td><td>${money(p.imm_general)} / ${money(p.imm_menor_mayor)}</td><td>${escape(p.respaldo)}</td></tr>`).join(''));
        if (!state.people.length) await searchPeople(); permissions();
    }
    async function searchPeople() {
        const data = await api(`/api/rrhh/personas?${new URLSearchParams({ buscar: $('rnPersonSearch').elements.buscar.value })}`); state.people = data.data.data;
        $('rnPerson').innerHTML = choices(state.people.map((p) => [p.id, `${p.codigo} · ${p.nombre}`]), 'Seleccione trabajador'); $('rnFicha').hidden = true; state.person = null;
    }
    async function loadFicha(conditionPage = 1, pactPage = 1, movementPage = 1) {
        const id = $('rnPerson').value; if (!id) throw new Error('Seleccione un trabajador.');
        const data = await api(`/api/rrhh/nomina/personas/${id}?condiciones_page=${conditionPage}&pactos_page=${pactPage}`);
        state.person = data.data.persona; $('rnPersonTitle').textContent = `${state.person.codigo} · ${state.person.nombre}`;
        $('rnConditions').innerHTML = table(['Cargo / régimen', 'Vigencia', 'Jornada y sueldo', 'Acciones'], data.data.condiciones.data.map((c) => `<tr><td>${escape(c.cargo_nombre)}<small>${escape(c.regimen)}</small></td><td>${c.desde} → ${c.hasta}</td><td>${c.horas_semanales} h/semana<small>${money(c.sueldo_mensual)} mensual</small></td><td>${canWrite() ? `<button type="button" data-rn-action="end-condition" data-id="${c.id}">Acortar vigencia</button>` : ''}<button type="button" data-rn-action="history" data-entity="condicion" data-id="${c.id}">Historial</button></td></tr>`).join(''));
        $('rnPacts').innerHTML = table(['Asignación mensual', 'Vigencia', 'Cantidad y valor', 'Acciones'], data.data.pactos.data.map((p) => `<tr><td>${escape(p.concepto.nombre)}<small>${escape(labels[p.concepto.calculo])}</small></td><td>${p.desde} → ${p.hasta}</td><td>${escape(p.cantidad)} × ${escape(p.valor)}</td><td>${canWrite() ? `<button type="button" data-rn-action="from-pact" data-id="${p.id}">Preparar novedad del mes</button><button type="button" data-rn-action="end-pact" data-id="${p.id}">Acortar vigencia</button>` : ''}<button type="button" data-rn-action="history" data-entity="pacto_concepto" data-id="${p.id}">Historial</button></td></tr>`).join(''));
        state.pacts = data.data.pactos.data; state.conditions = data.data.condiciones.data;
        pages('rnConditionPages', data.data.condiciones, (n) => loadFicha(n, pactPage, movementPage)); pages('rnPactPages', data.data.pactos, (n) => loadFicha(conditionPage, n, movementPage));
        const movements = await api(`/api/rrhh/nomina/movimientos?${new URLSearchParams({ persona_id: id, mes: $('rnMonth').value, page: movementPage })}`); state.movements = movements.data.data;
        $('rnMovements').innerHTML = table(['Fecha y concepto', 'Monto', 'Estado / incidencias', 'Acciones'], state.movements.map((m) => `<tr><td>${m.fecha}<small>${escape(m.calculo.concepto.nombre)}</small></td><td>${money(m.total)}</td><td>${escape(m.estado)}<small>${escape([...(m.calculo.incidencias || []), ...(m.calculo.incidencias_aprobacion || [])].join(' '))}</small></td><td><button type="button" data-rn-action="calculation" data-id="${m.id}">Ver cálculo</button><button type="button" data-rn-action="history" data-entity="movimiento_nomina" data-id="${m.id}">Historial</button>${canWrite() ? `${m.estado === 'borrador' ? `<button type="button" data-rn-action="approve" data-id="${m.id}">Aprobar</button>` : ''}${m.estado !== 'anulado' ? `<button type="button" data-rn-action="annul" data-id="${m.id}">Anular</button>` : ''}` : ''}</td></tr>`).join(''));
        pages('rnMovementPages', movements.data, (n) => loadFicha(conditionPage, pactPage, n)); $('rnFicha').hidden = false;
        document.dispatchEvent(new CustomEvent('rrhh:ficha', { detail: state.person }));
    }
    let cargoGeneration = 0;
    async function cargoPicker(buscar) { const generation = ++cargoGeneration; const data = await api(`/api/rrhh/cargos?${new URLSearchParams({ buscar })}`); if (generation !== cargoGeneration) return; $('rnConditionForm').elements.cargo_id.innerHTML = choices(data.data.data.filter((c) => c.activo).map((c) => [c.id, `${c.codigo} · ${c.nombre}`]), 'Seleccione cargo'); }
    const pickerGenerations = new WeakMap();
    async function conceptPicker(form, buscar = '') {
        const generation = (pickerGenerations.get(form) || 0) + 1; pickerGenerations.set(form, generation);
        const data = await api(`/api/rrhh/nomina/conceptos?${new URLSearchParams({ buscar })}`); if (pickerGenerations.get(form) !== generation) return;
        const concepts = data.data.data.filter((c) => c.activo && (form.id !== 'rnPactForm' || c.calculo !== 'horas_extra')); form._concepts = concepts;
        form.elements.concepto_id.innerHTML = choices(concepts.map((c) => [c.id, `${c.codigo} · ${c.nombre}`]), 'Seleccione concepto'); if (form.id === 'rnMovementForm') movementFields();
    }
    function openCargo(cargo = null) { state.cargo = cargo; const form = $('rnCargoForm'); form.reset(); if (cargo) for (const k of ['codigo', 'nombre', 'descripcion', 'activo']) form.elements[k].value = k === 'activo' ? (cargo[k] ? '1' : '0') : cargo[k] || ''; form.elements.codigo.readOnly = !!cargo; open('rnCargoDialog'); }
    async function openMovement(pact = null) {
        const form = $('rnMovementForm'); form.reset(); state.operation = newOperationId(); state.pact = pact;
        form.elements.fecha.value = `${$('rnMonth').value}-01`; $('rnSimulation').textContent = ''; await conceptPicker(form);
        if (pact) { form._concepts = [pact.concepto]; form.elements.concepto_id.innerHTML = choices([[pact.concepto_id, pact.concepto.nombre]]); form.elements.cantidad.value = pact.cantidad; form.elements.valor.value = pact.valor; form.elements.respaldo.value = pact.respaldo; }
        form.elements.concepto_id.disabled = !!pact; form.querySelector('[data-rn-concept-search]').disabled = !!pact;
        for (const key of ['cantidad', 'valor']) form.elements[key].readOnly = !!pact; movementFields(); open('rnMovementDialog');
    }
    function movementFields() {
        const form = $('rnMovementForm'); const concept = form._concepts?.find((c) => c.id === form.elements.concepto_id.value); const extra = concept?.calculo === 'horas_extra'; const deduction = concept?.tipo === 'descuento';
        for (const [selector, show] of [['[data-rn-amount]', !extra], ['[data-rn-extra]', extra], ['[data-rn-deduction]', deduction]]) { const block = form.querySelector(selector); block.hidden = !show; for (const input of block.querySelectorAll('input,select')) { input.disabled = !show; if (input.name === 'cantidad' || input.name === 'valor' || input.name === 'minutos') input.required = show; } }
        const apv = deduction && concept?.grupo_descuento === 'apv'; form.querySelector('[data-rn-apv]').hidden = !apv; form.elements.regimen_apv.disabled = !apv; form.elements.regimen_apv.required = apv;
        form.elements.remuneracion_bruta.required = deduction && ['voluntario', 'vivienda', 'cooperativa'].includes(concept?.grupo_descuento);
    }
    function movementData() { const form = $('rnMovementForm'); const d = { ...values(form), persona_id: state.person.id, operacion_id: state.operation }; if (state.pact) { d.pacto_id = state.pact.id; d.concepto_id = state.pact.concepto_id; } if (d.minutos) d.minutos = Number(d.minutos); if (d.pago_directo_institucion !== undefined) d.pago_directo_institucion = d.pago_directo_institucion === '1'; for (const k of Object.keys(d)) if (d[k] === '') delete d[k]; return d; }
    function showCalculation(c) { $('rnSimulation').textContent = `Total: ${money(c.total)}${c.base_he ? `\nBase mensual HE: ${money(c.base_he)}\nHora ordinaria: ${c.valor_hora_ordinaria}\nHora extra: ${c.valor_hora_extra}\nMinutos: ${c.minutos}` : ''}\n${c.incidencias?.join('\n') || 'Sin incidencias detectadas en este cálculo.'}`; }
    async function save(form) {
        const d = values(form); const path = '/api/rrhh/nomina';
        if (form.id === 'rnCargoForm') { await api(`/api/rrhh/cargos${state.cargo ? `/${state.cargo.id}` : ''}`, state.cargo ? 'PUT' : 'POST', { ...d, activo: d.activo === '1', ...(state.cargo ? { version: state.cargo.version } : {}) }); return loadCargos(); }
        if (form.id === 'rnConceptForm') { for (const k of ['remuneracion', 'imponible', 'tributable', 'base_he']) d[k] = d[k] === '1'; for (const k of ['grupo_descuento', 'codigo_lre', 'codigo_externo', 'institucion_id']) d[k] ||= null; await api(`${path}/conceptos`, 'POST', { ...d, activo: true }); return loadConcepts(); }
        if (form.id === 'rnConditionForm') await api(`${path}/condiciones`, 'POST', { ...d, persona_id: state.person.id });
        if (form.id === 'rnPactForm') await api(`${path}/asignaciones`, 'POST', { ...d, persona_id: state.person.id });
        if (form.id === 'rnParameterForm') { await api(`${path}/parametros`, 'POST', d); return loadConcepts(); }
        if (form.id === 'rnMovementForm') await api(`${path}/movimientos`, 'POST', movementData());
        return loadFicha();
    }
    async function rowAction(button) {
        const id = button.dataset.id; const action = button.dataset.rnAction;
        if (action === 'end-condition' || action === 'end-pact') {
            const condition = action === 'end-condition'; const row = (condition ? state.conditions : state.pacts).find((p) => p.id === id);
            const hasta = prompt('Último día de vigencia inclusive (AAAA-MM-DD). Las novedades aprobadas posteriores deben rectificarse antes:', row.hasta); if (!hasta || hasta === row.hasta) return;
            const motivo = prompt('Motivo del cambio de vigencia (mínimo 10 caracteres):'); if (!motivo) return;
            await api(`/api/rrhh/nomina/vigencias/${condition ? 'condicion' : 'pacto_concepto'}/${id}`, 'PATCH', { hasta, hasta_actual: row.hasta, motivo }); return loadFicha();
        }
        if (action === 'cargo') return openCargo(state.cargos.find((c) => c.id === id));
        if (action === 'from-pact') return openMovement(state.pacts.find((p) => p.id === id));
        if (action === 'history' || action === 'calculation') {
            const data = action === 'history' ? (await api(`/api/rrhh/nomina/historial?${new URLSearchParams({ entidad: button.dataset.entity, registro_id: id })}`)).data.data : state.movements.find((m) => m.id === id).calculo;
            $('rhDetailTitle').textContent = action === 'history' ? 'Historial de remuneraciones' : 'Cálculo y antecedentes'; $('rhDetail').replaceChildren(); const pre = document.createElement('pre'); pre.className = 'rh-history'; pre.textContent = JSON.stringify(data, null, 2); $('rhDetail').append(pre); open('rhDetailDialog'); return;
        }
        if (action === 'concept-state') { const c = state.concepts.find((c) => c.id === id); await api(`/api/rrhh/nomina/conceptos/${id}`, 'PATCH', { activo: !c.activo, version: c.version }); return loadConcepts(); }
        const motivo = prompt(action === 'approve' ? 'Motivo y respaldo de la aprobación (mínimo 10 caracteres). Confirme que revisó el cálculo y las incidencias:' : 'Motivo de anulación (mínimo 10 caracteres):'); if (!motivo) return;
        await api(`/api/rrhh/nomina/movimientos/${id}`, 'PATCH', { estado: action === 'approve' ? 'aprobado' : 'anulado', motivo, acepta_incidencias: true }); await loadFicha();
    }
}
