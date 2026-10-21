export function initPrevision({ api, capability, escape, table, choices, open, pages, message }) {
    const $ = (id) => document.getElementById(id);
    const path = '/api/rrhh/prevision';
    const state = { rows: [], types: [], edit: null, directory: 'institucion', person: null, employer: null, contracts: [], affiliates: [], employerAffiliates: [], owner: null };
    const labels = { afp: 'AFP', fonasa: 'Fonasa', isapre: 'Isapre', ccaf: 'CCAF', mutualidad: 'Mutualidad', isl: 'ISL', afc: 'AFC', fapp: 'FAPP', fisco: 'Fisco', apv: 'APV', sindicato: 'Sindicato', cooperativa: 'Cooperativa', aseguradora: 'Aseguradora', otro: 'Otro destinatario', propio: 'Propio', contratista: 'Contratista', subcontratista: 'Subcontratista', est: 'Servicios transitorios', salud: 'Salud', accidentes: 'Accidentes del trabajo', indefinido: 'Indefinido', plazo_fijo: 'Plazo fijo', obra_faena: 'Obra o faena', honorarios: 'Honorarios' };
    const canWrite = () => capability('puede_gestionar_remuneraciones_rrhh');
    const safe = async (fn) => { try { await fn(); } catch (e) { message(e.message); } };
    const values = (form) => Object.fromEntries([...new FormData(form)].filter(([, v]) => v !== ''));
    const links = (kind, row) => `<button type="button" data-rp-action="history" data-kind="${kind}" data-id="${row.id}">Historial</button>${canWrite() && ['contrato', 'afiliacion'].includes(kind) ? `<button type="button" data-rp-action="end" data-kind="${kind}" data-id="${row.id}">Acortar vigencia</button>` : ''}`;
    function permissions() { $('rpTab').hidden = !capability('puede_consultar_remuneraciones_rrhh'); }
    new MutationObserver(permissions).observe($('officeApp'), { attributes: true, attributeFilter: ['class'] }); permissions();
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-rh-tab]')?.dataset.rhTab === 'prevision') safe(() => directory());
        const b = e.target.closest('[data-rp-action]'); if (b) safe(() => action(b));
    });
    document.addEventListener('rrhh:ficha', (e) => { state.person = e.detail; safe(() => person()); });
    $('rpSearch').addEventListener('submit', (e) => { e.preventDefault(); safe(() => directory()); });
    $('rpNew').addEventListener('click', () => edit());
    $('rpNewContract').addEventListener('click', () => safe(async () => { const f = $('rpContractForm'); f.reset(); f.elements.prorroga_de.innerHTML = choices(state.contracts.map((c) => [c.id, `${c.referencia} · ${c.hasta}`]), 'Contrato inicial / no corresponde'); await picker(f, 'empleador'); open('rpContractDialog'); }));
    $('rpNewAffiliation').addEventListener('click', () => safe(() => affiliation({ persona_id: state.person.id }, state.person.nombre)));
    $('rpNewEmployerAffiliation').addEventListener('click', () => safe(() => affiliation({ empleador_id: state.employer.id }, state.employer.razon_social)));
    $('rpAffiliationForm').elements.regimen.addEventListener('change', () => safe(() => picker($('rpAffiliationForm'), 'institucion')));
    $('rpAffiliationForm').elements.institucion_id.addEventListener('change', plan);
    for (const input of document.querySelectorAll('[data-rp-picker]')) input.addEventListener('input', () => safe(() => picker(input.form, input.dataset.rpPicker, input.value)));
    for (const form of document.querySelectorAll('[data-prevision-form]')) form.addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try { await save(form); form.closest('dialog').close(); message('Antecedente registrado con historial.'); }
        catch (error) { form.querySelector('[data-rh-error]').textContent = error.message; } finally { b.disabled = false; }
    });
    async function directory(page = 1) {
        const f = $('rpSearch'); const kind = f.elements.directorio.value;
        const data = await api(`${path}/directorio/${kind}?${new URLSearchParams({ page, buscar: f.elements.buscar.value })}`);
        if (kind !== f.elements.directorio.value) return;
        state.directory = kind; state.rows = data.data.data; state.types = data.tipos;
        $('rpDirectory').innerHTML = table(['Entidad', 'Vigencia / estado', 'Acciones'], state.rows.map((r) => `<tr><td><strong>${escape(r.razon_social)}</strong><small>${escape(r.rut)} · ${escape(labels[r.tipo])} · ${escape(r.codigo)}</small></td><td>${r.desde} → ${r.hasta}<small>${r.activo ? 'Activo' : 'Inactivo'}</small></td><td>${links(kind, r)}${kind === 'institucion' ? `<button type="button" data-rd-action="account" data-id="${r.id}">Movimientos</button>` : ''}${canWrite() ? `<button type="button" data-rp-action="edit" data-id="${r.id}">Editar</button>` : ''}${kind === 'empleador' ? `<button type="button" data-rp-action="employer" data-id="${r.id}">Afiliaciones</button>` : ''}</td></tr>`).join(''));
        pages('rpPages', data.data, directory);
    }
    function edit(row = null) {
        state.edit = row; const f = $('rpDirectoryForm'); f.reset();
        $('rpDirectoryTitle').textContent = state.directory === 'institucion' ? 'Institución destinataria' : 'Empleador real';
        f.elements.tipo.innerHTML = choices(state.types.map((t) => [t, labels[t]]));
        if (row) for (const [k, v] of Object.entries(row)) if (f.elements[k]) f.elements[k].value = k === 'activo' ? (v ? '1' : '0') : v ?? '';
        for (const k of ['codigo', 'rut', 'desde', 'hasta']) f.elements[k].readOnly = !!row;
        f.elements.tipo.disabled = !!row; open('rpDirectoryDialog');
    }
    async function person(contractPage = 1, affiliatePage = 1) {
        const id = state.person.id;
        const [contracts, affiliates] = await Promise.all([api(`${path}/contratos?persona_id=${id}&page=${contractPage}`), api(`${path}/afiliaciones?persona_id=${id}&page=${affiliatePage}`)]);
        if (id !== state.person.id) return;
        state.contracts = contracts.data?.data || []; state.affiliates = affiliates.data?.data || [];
        $('rpContracts').innerHTML = table(['Empleador / vínculo', 'Contrato y vigencia', 'Acciones'], state.contracts.map((c) => `<tr><td>${escape(c.empleador.razon_social)}<small>${escape(labels[c.empleador.tipo])} · ${escape(labels[c.tipo])}</small></td><td>${escape(c.referencia)}<small>${c.desde} → ${c.hasta}</small>${c.prorroga_de ? '<small>Prórroga registrada</small>' : ''}</td><td>${links('contrato', c)}</td></tr>`).join(''));
        if (contracts.data) pages('rpContractPages', contracts.data, (n) => person(n, affiliatePage));
        showAffiliates('rpAffiliations', state.affiliates);
        if (affiliates.data) pages('rpAffiliationPages', affiliates.data, (n) => person(contractPage, n));
    }
    function showAffiliates(id, rows) {
        $(id).innerHTML = table(['Régimen / institución', 'Vigencia y plan', 'Acciones'], rows.map((a) => `<tr><td>${escape(labels[a.regimen])}<small>${escape(a.institucion.razon_social)} · ${escape(a.referencia)}</small></td><td>${a.desde} → ${a.hasta}${a.precio_plan ? `<small>Plan ${escape(a.precio_plan)} ${escape(a.moneda_plan)}</small>` : ''}</td><td>${links('afiliacion', a)}</td></tr>`).join(''));
    }
    async function employer(page = 1) {
        const data = await api(`${path}/afiliaciones?empleador_id=${state.employer.id}&page=${page}`); state.employerAffiliates = data.data.data;
        $('rpEmployerTitle').textContent = state.employer.razon_social; showAffiliates('rpEmployerAffiliations', state.employerAffiliates); pages('rpEmployerPages', data.data, employer);
    }
    async function affiliation(owner, name) {
        state.owner = owner; const f = $('rpAffiliationForm'); f.reset(); $('rpOwner').textContent = name;
        f.elements.regimen.innerHTML = choices((owner.persona_id ? ['afp', 'salud', 'apv'] : ['ccaf', 'accidentes']).map((t) => [t, labels[t]]));
        await picker(f, 'institucion'); open('rpAffiliationDialog');
    }
    const generations = new WeakMap();
    async function picker(form, kind, buscar = '') {
        const generation = (generations.get(form) || 0) + 1; generations.set(form, generation);
        const query = new URLSearchParams({ buscar }); if (kind === 'institucion') query.set('regimen', form.elements.regimen.value);
        const data = await api(`${path}/directorio/${kind}?${query}`); if (generations.get(form) !== generation) return;
        form._institutions = data.data.data.filter((r) => r.activo);
        form.elements[`${kind}_id`].innerHTML = choices(form._institutions.map((r) => [r.id, `${r.rut} · ${r.razon_social}`]), 'Seleccione; busque si no aparece'); if (kind === 'institucion') plan();
    }
    function plan() {
        const f = $('rpAffiliationForm'); const show = f._institutions?.find((r) => r.id === f.elements.institucion_id.value)?.tipo === 'isapre'; $('rpPlan').hidden = !show;
        for (const k of ['moneda_plan', 'precio_plan']) { f.elements[k].disabled = !show; f.elements[k].required = show; }
        const apv = f.elements.regimen.value === 'apv'; $('rpApv').hidden = !apv; f.elements.regimen_apv.disabled = !apv; f.elements.regimen_apv.required = apv;
    }
    async function save(form) {
        const d = values(form);
        if (form.id === 'rpDirectoryForm') { d.activo = d.activo === '1'; if (state.edit) { d.tipo = state.edit.tipo; d.version = state.edit.version; } await api(`${path}/directorio/${state.directory}${state.edit ? `/${state.edit.id}` : ''}`, state.edit ? 'PUT' : 'POST', d); return directory(); }
        if (form.id === 'rpContractForm') { await api(`${path}/contratos`, 'POST', { ...d, persona_id: state.person.id }); return person(); }
        if (form.id === 'rpAffiliationForm') { await api(`${path}/afiliaciones`, 'POST', { ...d, ...state.owner }); return state.owner.persona_id ? person() : employer(); }
    }
    async function action(b) {
        const { rpAction: a, id, kind } = b.dataset;
        if (a === 'edit') return edit(state.rows.find((r) => r.id === id));
        if (a === 'employer') { state.employer = state.rows.find((r) => r.id === id); await employer(); return open('rpEmployerDialog'); }
        if (a === 'history') { const data = await api(`${path}/historial?${new URLSearchParams({ entidad: kind, registro_id: id })}`); $('rhDetailTitle').textContent = 'Historial de antecedentes'; $('rhDetail').textContent = JSON.stringify(data.data.data, null, 2); return open('rhDetailDialog'); }
        const row = [...state.contracts, ...state.affiliates, ...state.employerAffiliates].find((r) => r.id === id);
        const hasta = prompt('Último día de vigencia inclusive (AAAA-MM-DD):', row.hasta); if (!hasta || hasta === row.hasta) return;
        const motivo = prompt('Motivo y respaldo (mínimo 10 caracteres):'); if (!motivo) return;
        await api(`${path}/vigencias/${kind}/${id}`, 'PATCH', { hasta, hasta_actual: row.hasta, motivo });
        if (state.person) await person(); if (state.employer) await employer();
    }
}
