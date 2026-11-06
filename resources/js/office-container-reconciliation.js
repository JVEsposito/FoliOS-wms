const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const number = (value) => new Intl.NumberFormat('es-CL').format(value);
const base = '/api/envases/cuenta-corriente/conciliaciones-salida';
const normalizeGuide = (value) => String(value ?? '').trim().replace(/[ .]/g, '').replace(/^0+(?=\d)/, '').toUpperCase();

export function createContainerReconciliation({ api, catalogs, scope, afterApplied, toast }) {
    const el = (id) => document.getElementById(id);
    const dialog = el('duplicateReconciliationDialog');
    const guide = el('reconciliationGuideDialog');
    const search = el('duplicateSearchForm');
    const previewForm = el('duplicatePreviewForm');
    const applyForm = el('duplicateApplyForm');
    const submit = applyForm.querySelector('[type="submit"]');
    const state = { items: [], selected: null, plan: null, season: null, page: 1, lastPage: 1, busy: false, revision: 0 };
    let guideOpener;

    function describePair(item) {
        if (!item) return;
        el('duplicatePairTitle').textContent = `${item.cliente} · Despacho ${item.despacho}`;
        el('duplicatePairDocuments').textContent = `${item.recepcion} · Guía de ingreso ${item.guia_ingreso} · Salida Romana ${item.guia_salida_romana ?? item.guia_salida} · Salida Despacho ${item.guia_salida}`;
        const different = normalizeGuide(item.guia_salida_romana ?? item.guia_salida) !== normalizeGuide(item.guia_salida);
        el('duplicateDifferentGuides').hidden = !different;
        previewForm.elements.guias_distintas_verificadas.required = different;
    }

    function updateSearchRequirements() {
        const manual = search.elements.coincidencia.value === 'manual';
        search.elements.cliente_id.required = manual;
        search.elements.buscar.required = manual;
        search.elements.buscar.minLength = manual ? 3 : 0;
        el('duplicateManualNotice').hidden = !manual;
    }

    function updateSubmit() {
        submit.disabled = state.busy || !state.plan?.hash || state.plan.aplicada
            || applyForm.elements.motivo.value.trim().length < 10 || !applyForm.elements.cantidades_verificadas.checked;
    }

    function invalidate() {
        state.revision++;
        state.plan = null;
        applyForm.elements.cantidades_verificadas.checked = false;
        el('duplicateComparison').hidden = true;
        applyForm.hidden = true;
        updateSubmit();
    }

    async function run(message, action) {
        if (state.busy) return;
        state.busy = true;
        el('duplicateError').textContent = '';
        el('duplicateStatus').textContent = message;
        const controls = [...dialog.querySelectorAll('button, input, select, textarea')];
        const disabled = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        try { await action(); }
        catch (error) { el('duplicateError').textContent = error.message; }
        finally {
            state.busy = false;
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
            el('duplicatePrevious').disabled = state.page <= 1;
            el('duplicateNext').disabled = state.page >= state.lastPage;
            el('duplicateStatus').textContent = '';
            updateSubmit();
        }
    }

    async function loadCandidates(page = 1) {
        invalidate();
        state.selected = null;
        state.items = [];
        el('duplicateCandidates').innerHTML = '';
        el('duplicateDetail').hidden = true;
        el('duplicateResult').hidden = true;
        el('duplicateSearchSection').hidden = false;
        const parameters = new URLSearchParams({ cliente_id: search.elements.cliente_id.value, buscar: search.elements.buscar.value.trim(), coincidencia: search.elements.coincidencia.value });
        if (!parameters.get('cliente_id')) parameters.delete('cliente_id');
        parameters.set('temporada_id', state.season);
        parameters.set('page', page);
        const response = await api(`${base}?${parameters}`);
        state.items = response.data;
        state.page = response.meta.page;
        state.lastPage = response.meta.last_page;
        el('duplicateCandidatesNote').textContent = `${response.meta.total} posibles dobles salidas pendientes. Revisa cada coincidencia antes de aplicar.`;
        el('duplicatePage').textContent = `Página ${state.page} de ${state.lastPage}`;
        el('duplicateCandidates').innerHTML = state.items.length ? state.items.map((item, index) => `<article><div><strong>${escapeHtml(item.cliente)} · Salida Despacho ${escapeHtml(item.guia_salida)}</strong><p>${escapeHtml(item.despacho)} · ${escapeHtml(item.recepcion)} · Guía ingreso ${escapeHtml(item.guia_ingreso)} · Salida Romana ${escapeHtml(item.guia_salida_romana ?? item.guia_salida)}</p></div><button class="secondary-button" type="button" data-duplicate-pair="${index}">Comparar documentos</button></article>`).join('')
            : '<p>No hay coincidencias pendientes con estos filtros. Si hay un error de numeración, usa «Comparación manual» con el cliente y una referencia documental.</p>';
    }

    function showApplied(plan) {
        invalidate();
        describePair(plan.documentos);
        previewForm.hidden = true;
        el('duplicateResult').hidden = false;
        el('duplicateResult').innerHTML = `<strong>Esta pareja ya está conciliada. No se crean abonos adicionales.</strong><p>${number(plan.compensaciones)} compensaciones registradas.</p>${plan.auditoria.map((item) => `<p>Se conservaron las cantidades de ${item.conservar === 'romana' ? 'Romana' : 'Despacho'}. Responsable: ${escapeHtml(item.responsable)}.<br>Motivo: ${escapeHtml(item.motivo)}</p>`).join('')}`;
    }

    el('duplicateReconciliationOpen').addEventListener('click', () => {
        if (catalogs()?.puede_ajustar !== true) return;
        const active = catalogs().temporadas.find((season) => season.activa);
        const selected = scope();
        if (!active || (selected.temporada_id && selected.temporada_id !== active.id)) {
            toast('Selecciona la temporada activa para conciliar duplicados.', true);
            return;
        }
        state.season = active.id;
        search.reset();
        updateSearchRequirements();
        search.elements.cliente_id.innerHTML = '<option value="">Todos los clientes</option>' + catalogs().clientes.map((client) => `<option value="${escapeHtml(client.id)}">${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</option>`).join('');
        search.elements.cliente_id.value = selected.cliente_id || '';
        dialog.showModal();
        run('Buscando coincidencias…', () => loadCandidates());
    });

    search.addEventListener('submit', (event) => {
        event.preventDefault();
        run('Buscando coincidencias…', () => loadCandidates());
    });
    search.addEventListener('input', () => {
        updateSearchRequirements();
        state.items = [];
        el('duplicateCandidates').innerHTML = '';
        el('duplicateCandidatesNote').textContent = 'Filtros modificados. Pulsa Buscar coincidencias para actualizar la lista.';
        el('duplicatePrevious').disabled = true;
        el('duplicateNext').disabled = true;
    });
    el('duplicatePrevious').addEventListener('click', () => run('Buscando coincidencias…', () => loadCandidates(state.page - 1)));
    el('duplicateNext').addEventListener('click', () => run('Buscando coincidencias…', () => loadCandidates(state.page + 1)));
    el('duplicateChooseAnother').addEventListener('click', () => run('Buscando coincidencias…', () => loadCandidates()));
    el('duplicateCandidates').addEventListener('click', (event) => {
        const button = event.target.closest('[data-duplicate-pair]');
        if (!button || state.busy) return;
        invalidate();
        state.selected = state.items[Number(button.dataset.duplicatePair)];
        previewForm.reset(); applyForm.reset(); previewForm.hidden = false;
        describePair(state.selected);
        el('duplicateSearchSection').hidden = true;
        el('duplicateDetail').hidden = false;
        el('duplicateResult').hidden = true;
        el('duplicateError').textContent = '';
        el('duplicatePairTitle').focus();
    });

    previewForm.addEventListener('change', invalidate);
    previewForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (state.busy || !state.selected) return;
        const body = { recepcion_id: state.selected.recepcion_id, guia_id: state.selected.guia_id, conservar: previewForm.elements.conservar.value, guias_distintas_verificadas: previewForm.elements.guias_distintas_verificadas.checked };
        invalidate();
        const revision = state.revision;
        run('Comparando cantidades…', async () => {
            const { data: plan } = await api(`${base}/previsualizar`, { method: 'POST', body: JSON.stringify(body) });
            if (revision !== state.revision) return;
            describePair(plan.documentos);
            if (plan.aplicada) { showApplied(plan); await afterApplied(); return; }
            state.plan = { ...plan, body };
            el('duplicateComparisonBody').innerHTML = plan.filas.map((row) => `<tr><th scope="row">${escapeHtml(row.envase)}</th><td>${escapeHtml(row.propiedad)}</td>${['romana', 'despacho', 'diferencia', 'conservar', 'compensacion'].map((key) => `<td>${number(row[key])}</td>`).join('')}</tr>`).join('');
            el('duplicateComparison').hidden = false;
            applyForm.hidden = false;
        });
    });
    applyForm.addEventListener('input', updateSubmit);
    applyForm.addEventListener('submit', (event) => {
        event.preventDefault();
        updateSubmit();
        if (submit.disabled) return;
        const body = { ...state.plan.body, hash: state.plan.hash, motivo: applyForm.elements.motivo.value.trim(), cantidades_verificadas: applyForm.elements.cantidades_verificadas.checked };
        run('Registrando conciliación…', async () => {
            try {
                const { data: result } = await api(`${base}/aplicar`, { method: 'POST', body: JSON.stringify(body) });
                showApplied(result);
                toast('Conciliación registrada. Cuenta y existencia actualizadas.');
                await afterApplied();
            } catch (error) {
                // Incluso si se perdió la respuesta, una nueva vista previa comprueba si ya se aplicó.
                invalidate();
                throw new Error(`${error.message} Vuelve a previsualizar antes de confirmar.`);
            }
        });
    });
    el('duplicateClose').addEventListener('click', () => { if (!state.busy) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (state.busy) event.preventDefault(); });
    dialog.addEventListener('close', invalidate);
    document.querySelectorAll('[data-reconciliation-guide]').forEach((button) => button.addEventListener('click', () => {
        guideOpener = button;
        guide.showModal();
    }));
    el('reconciliationGuideClose').addEventListener('click', () => guide.close());
    guide.addEventListener('close', () => guideOpener?.focus());

    return { syncAccess: () => el('duplicateReconciliationOpen').classList.toggle('is-hidden', catalogs()?.puede_ajustar !== true) };
}
