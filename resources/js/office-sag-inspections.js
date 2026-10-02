import { showOfficeToast } from './office-toast.js';

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    summary: {},
    catalogs: { bloques: [], paises: [], tipos_aprobacion: [], tipos_lote: [] },
    options: {},
    lots: [],
    eligibleFolios: [],
    selectedFolioIds: new Set(),
    selectedDestinationValues: new Set(),
    selectedLot: null,
};

const elements = {
    access: document.getElementById('officeAccess'),
    app: document.getElementById('officeApp'),
    login: document.getElementById('officeLoginForm'),
    loginError: document.getElementById('officeLoginError'),
    builder: document.getElementById('sagBuilderForm'),
    builderError: document.getElementById('builderError'),
    eligibleBody: document.getElementById('eligibleFoliosBody'),
    destinationOptions: document.getElementById('destinationOptions'),
    selectedDestinationPills: document.getElementById('selectedDestinationPills'),
    destinationSearch: document.getElementById('destinationSearch'),
    singleFolioSearch: document.getElementById('singleFolioSearch'),
    searchSingleFolioButton: document.getElementById('searchSingleFolioButton'),
    detail: document.getElementById('sagLotDetail'),
    detailError: document.getElementById('detailError'),
    detailActions: document.getElementById('detailActionSelect'),
    toasts: document.getElementById('officeToasts'),
};

function toast(message, error = false) { showOfficeToast(elements.toasts, message, error); }

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[character]));
}

function humanize(value) {
    const labels = {
        muestreo_usda: 'Muestreo USDA', inspeccion_origen: 'Inspección Origen',
        inspeccion_linea: 'Inspección en línea',
        fumigacion: 'Fumigación', cambio_mercado: 'Cambio de mercado', preparacion: 'Preparación',
        en_inspeccion: 'En inspección', resultado_parcial: 'Resultado parcial', finalizado: 'Finalizado',
        cancelado: 'Cancelado', sin_resolucion: 'Sin resolución', aprobado: 'Aprobado',
        segregado: 'Segregado', rechazado: 'Rechazado', pendiente: 'Pendiente',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ');
}

function capabilities() {
    return { ...(state.identity?.capacidades || {}), ...(state.identity || {}) };
}

function canManage() {
    return state.identity?.rol === 'administrador' || capabilities().puede_gestionar_inspeccion_sag === true;
}

function persistSession(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}

function clearSession() {
    state.token = null;
    state.identity = null;
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

async function api(path, options = {}) {
    const response = await fetch(`/api${path}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
            ...(options.headers || {}),
        },
    });
    if (response.status === 401) clearSession();
    const payload = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
        const validation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validation || payload?.message || 'No fue posible completar la operación.');
    }
    return payload;
}

function showApp() {
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
}

function fillSelect(select, values, placeholder, mapper = (value) => ({ value, label: value })) {
    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>${(values || []).map((entry) => {
        const option = mapper(entry);
        return `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`;
    }).join('')}`;
}

function setupOptions() {
    fillSelect(
        elements.builder.elements.tipo,
        state.catalogs.tipos_lote,
        'Seleccionar tipo de inspección',
        (entry) => ({ value: entry.value, label: entry.label }),
    );
    fillSelect(
        elements.builder.elements.cliente,
        state.options.clientes,
        'Seleccionar',
        (entry) => ({ value: entry.id, label: `${entry.codigo} · ${entry.nombre}` }),
    );
    fillSelect(
        elements.builder.elements.condicion_termica,
        state.options.condiciones_termicas,
        'Todas',
        (entry) => ({ value: entry.value, label: entry.label }),
    );
    refreshCatalogHierarchy({ resetSpecies: true, resetOptional: true });
    renderDestinationOptions();
}

function refreshCatalogHierarchy({ resetSpecies = false, resetOptional = false } = {}) {
    const clienteSelect = elements.builder.elements.cliente;
    const especieSelect = elements.builder.elements.especie;
    const variedadSelect = elements.builder.elements.variedad;
    const csgSelect = elements.builder.elements.csg;
    const cliente = (state.options.clientes || []).find((entry) => entry.id === clienteSelect.value);
    const especieAnterior = resetSpecies ? '' : especieSelect.value;

    fillSelect(
        especieSelect,
        cliente?.especies || [],
        cliente ? 'Seleccionar' : 'Selecciona primero el cliente',
        (entry) => ({ value: entry.id, label: entry.nombre }),
    );
    if ((cliente?.especies || []).some((entry) => entry.id === especieAnterior)) especieSelect.value = especieAnterior;

    const especie = (cliente?.especies || []).find((entry) => entry.id === especieSelect.value);
    const variedadAnterior = resetOptional ? '' : variedadSelect.value;
    const csgAnterior = resetOptional ? '' : csgSelect.value;
    fillSelect(variedadSelect, especie?.variedades || [], 'Todas', (entry) => ({ value: entry.id, label: entry.nombre }));
    fillSelect(csgSelect, especie?.csg || [], 'Todos', (entry) => ({ value: entry.id, label: entry.codigo }));
    if ((especie?.variedades || []).some((entry) => entry.id === variedadAnterior)) variedadSelect.value = variedadAnterior;
    if ((especie?.csg || []).some((entry) => entry.id === csgAnterior)) csgSelect.value = csgAnterior;

    refreshFilterAvailability();
}

function renderDestinationOptions() {
    const query = elements.destinationSearch.value.trim().toLocaleLowerCase('es');
    const matches = (entry) => !query || `${entry.codigo} ${entry.nombre}`.toLocaleLowerCase('es').includes(query);
    const blocks = state.catalogs.bloques.filter(matches);
    const countries = state.catalogs.paises.filter(matches);
    const option = (value, code, name, detail = '') => `<label class="sag-destination-option">
        <input type="checkbox" data-destination-value="${escapeHtml(value)}" ${state.selectedDestinationValues.has(value) ? 'checked' : ''}>
        <span><strong>${escapeHtml(code)} · ${escapeHtml(name)}</strong>${detail ? `<small>${escapeHtml(detail)}</small>` : ''}</span>
    </label>`;
    elements.destinationOptions.innerHTML = [
        blocks.length ? `<section><h4>Bloques de mercado</h4>${blocks.map((entry) => option(`bloque:${entry.id}`, entry.codigo, entry.nombre, `${entry.paises.length} países`)).join('')}</section>` : '',
        countries.length ? `<section><h4>Países</h4>${countries.map((entry) => option(`pais:${entry.id}`, entry.codigo, entry.nombre)).join('')}</section>` : '',
    ].join('') || '<p class="empty-cell">No hay destinos que coincidan con la búsqueda.</p>';
    elements.selectedDestinationPills.innerHTML = [...state.selectedDestinationValues]
        .map((value) => {
            const [type, id] = value.split(':');
            const entry = type === 'bloque'
                ? state.catalogs.bloques.find((item) => item.id === id)
                : state.catalogs.paises.find((item) => item.id === id);
            return entry ? `<span class="sag-pill sag-pill--active">${escapeHtml(entry.codigo)} · ${escapeHtml(entry.nombre)}</span>` : '';
        }).join('') || '<small>Sin destinos seleccionados.</small>';
}

function refreshFilterAvailability() {
    const enabled = Boolean(elements.builder.elements.cliente.value && elements.builder.elements.especie.value);
    elements.builder.querySelectorAll('[data-optional-filter]').forEach((field) => { field.disabled = !enabled; });
    document.getElementById('searchSagFoliosButton').disabled = !enabled;
}

function metric(id, value) { document.getElementById(id).textContent = Number(value || 0).toLocaleString('es-CL'); }

function renderSummary() {
    metric('activeLotsMetric', state.summary.lotes_activos);
    metric('activePalletsMetric', state.summary.pallets_en_inspeccion);
    metric('finishedTodayMetric', state.summary.finalizados_hoy);
    metric('approvalsTodayMetric', state.summary.autorizaciones_hoy);
}

function destinationPills(destinations) {
    return (destinations || []).map((destination) => `<span class="sag-pill">${escapeHtml(destination.codigo)} · ${escapeHtml(destination.nombre)}</span>`).join('') || '—';
}

function statePill(status) {
    const modifier = ['finalizado'].includes(status) ? 'active' : (status === 'cancelado' ? 'danger' : 'warning');
    return `<span class="sag-pill sag-pill--${modifier}">${escapeHtml(humanize(status))}</span>`;
}

function actionSelect(lot) {
    const options = [`<option value="">Seleccionar acción</option>`, `<option value="view">Ver detalle</option>`];
    if (canManage() && lot.estado === 'preparacion') options.push('<option value="start">Iniciar inspección</option>');
    if (canManage() && ['en_inspeccion', 'resultado_parcial'].includes(lot.estado)) options.push('<option value="finish">Finalizar lote</option>');
    if (canManage() && !['finalizado', 'cancelado'].includes(lot.estado)) options.push('<option value="cancel">Cancelar lote</option>');
    return `<select class="lot-action" data-lot-id="${lot.id}" aria-label="Acciones de ${escapeHtml(lot.codigo)}">${options.join('')}</select>`;
}

function lotRow(lot, dateField = 'creado_at') {
    return `<tr><td><strong>${escapeHtml(lot.codigo)}</strong><br><small>SAG: ${escapeHtml(lot.numero_inspeccion_sag || 'Sin número')}</small><br><small>${escapeHtml(lot.referencia_correo || 'Sin referencia')}</small></td><td>${escapeHtml(humanize(lot.tipo))}</td><td>${statePill(lot.estado)}</td><td>${escapeHtml(lot.cantidad_folios)}</td><td>${destinationPills(lot.destinos)}</td><td>${dateTime(lot[dateField] || lot.creado_at)}${lot.creado_por ? `<br><small>${escapeHtml(lot.creado_por)}</small>` : ''}</td><td>${actionSelect(lot)}</td></tr>`;
}

function renderLots() {
    const active = state.lots.filter((lot) => !['finalizado', 'cancelado'].includes(lot.estado));
    const history = state.lots.filter((lot) => ['finalizado', 'cancelado'].includes(lot.estado));
    const empty = '<tr><td colspan="7" class="empty-cell">No hay registros para mostrar.</td></tr>';
    document.getElementById('recentLotsBody').innerHTML = state.lots.slice(0, 8).map((lot) => lotRow(lot)).join('') || empty;
    document.getElementById('activeLotsBody').innerHTML = active.map((lot) => lotRow(lot)).join('') || empty;
    document.getElementById('historyLotsBody').innerHTML = history.map((lot) => lotRow(lot, 'finalizado_at')).join('') || empty;
}

function dateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

async function loadWorkspace() {
    const [summary, catalogs, options, lots] = await Promise.all([
        api('/inspeccion-sag/resumen'),
        api('/inspeccion-sag/catalogos'),
        api('/inspeccion-sag/folios/opciones'),
        api('/inspeccion-sag/lotes?per_page=100'),
    ]);
    state.summary = summary;
    state.catalogs = catalogs;
    state.options = options;
    state.lots = lots.data || [];
    setupOptions();
    renderSummary();
    renderLots();
}

async function searchFolios() {
    elements.builderError.textContent = '';
    const params = new URLSearchParams();
    ['cliente', 'especie', 'variedad', 'condicion_sag', 'csg', 'fecha_ingreso', 'condicion_termica'].forEach((name) => {
        const value = elements.builder.elements[name].value;
        if (value) params.set(name, value);
    });
    params.set('per_page', '100');
    try {
        const payload = await api(`/inspeccion-sag/folios?${params}`);
        state.eligibleFolios = payload.data || [];
        state.selectedFolioIds.clear();
        renderEligibleFolios();
    } catch (error) { elements.builderError.textContent = error.message; }
}

async function searchSingleFolio() {
    elements.builderError.textContent = '';
    const folioNumber = elements.singleFolioSearch.value.trim();
    if (!folioNumber) {
        elements.builderError.textContent = 'Escribe o escanea el número del pallet que quieres agregar.';
        elements.singleFolioSearch.focus();
        return;
    }

    elements.searchSingleFolioButton.disabled = true;
    try {
        const params = new URLSearchParams({ folio: folioNumber, per_page: '1' });
        const payload = await api(`/inspeccion-sag/folios?${params}`);
        const folio = payload.data?.[0];
        if (!folio) throw new Error('El folio no existe o no está elegible para una inspección SAG.');

        const selectedClientId = state.eligibleFolios
            .find((entry) => state.selectedFolioIds.has(entry.id))?.cliente_id;
        if (selectedClientId && folio.cliente_id !== selectedClientId) {
            throw new Error('El pallet pertenece a otro cliente/exportadora. Cada lote SAG admite un solo cliente.');
        }

        state.eligibleFolios = [folio, ...state.eligibleFolios.filter((entry) => entry.id !== folio.id)];
        state.selectedFolioIds.add(folio.id);
        elements.singleFolioSearch.value = '';
        renderEligibleFolios();
        elements.singleFolioSearch.focus();
    } catch (error) {
        elements.builderError.textContent = error.message;
    } finally {
        elements.searchSingleFolioButton.disabled = false;
    }
}

function renderEligibleFolios() {
    const body = elements.eligibleBody;
    if (!state.eligibleFolios.length) {
        body.innerHTML = '<tr><td colspan="8" class="empty-cell">No se encontraron pallets elegibles con estos filtros.</td></tr>';
    } else {
        body.innerHTML = state.eligibleFolios.map((folio) => `<tr><td><input type="checkbox" data-folio-id="${folio.id}" ${state.selectedFolioIds.has(folio.id) ? 'checked' : ''}></td><td><strong>${escapeHtml(folio.folio)}</strong><br><small>${escapeHtml(folio.cliente)}</small></td><td>${escapeHtml(folio.variedad || '—')}</td><td>${escapeHtml(folio.csg || '—')}</td><td>${escapeHtml(humanize(folio.condicion_termica))}</td><td>${folio.camara ? `${escapeHtml(folio.camara)}<br><small>${escapeHtml(folio.posicion || 'Sin posición')}</small>` : '<span class="sag-pill sag-pill--warning">Sin ubicación</span>'}</td><td>${folio.sag.en_inspeccion ? '<span class="sag-pill sag-pill--warning">En inspección</span>' : ''}<span class="sag-pill sag-pill--active">${escapeHtml(folio.sag.estado)}</span></td><td>${(folio.sag.destinos || []).map((destination) => `<span class="sag-pill">${escapeHtml(destination)}</span>`).join('') || '—'}</td></tr>`).join('');
    }
    document.getElementById('folioSelectionTitle').textContent = `${state.eligibleFolios.length} pallets encontrados`;
    updateBuilderSummary();
}

function updateBuilderSummary() {
    const count = state.selectedFolioIds.size;
    document.getElementById('selectedFoliosCount').textContent = `${count} seleccionados`;
    if (!elements.builder.elements.cantidad_solicitada.value || Number(elements.builder.elements.cantidad_solicitada.value) !== count) {
        elements.builder.elements.cantidad_solicitada.value = count || '';
    }
    document.getElementById('builderSummary').textContent = `${count} pallets · ${state.selectedDestinationValues.size} destinos seleccionados`;
}

function normalizeEuropeanSelection() {
    const ue = state.catalogs.bloques.find((block) => block.codigo === 'UE');
    if (!ue || !state.selectedDestinationValues.has(`bloque:${ue.id}`)) return;
    const memberValues = new Set(ue.paises.map((country) => `pais:${country.id}`));
    state.selectedDestinationValues = new Set([...state.selectedDestinationValues].filter((value) => !memberValues.has(value)));
}

async function createLot(event) {
    event.preventDefault();
    elements.builderError.textContent = '';
    if (!state.selectedFolioIds.size || !state.selectedDestinationValues.size) {
        elements.builderError.textContent = 'Selecciona al menos un pallet y un destino de inspección.';
        return;
    }
    const data = new FormData(elements.builder);
    const body = {
        tipo: data.get('tipo'),
        numero_inspeccion_sag: data.get('numero_inspeccion_sag') || null,
        cantidad_solicitada: Number(data.get('cantidad_solicitada')),
        referencia_correo: data.get('referencia_correo') || null,
        observacion: data.get('observacion') || null,
        folios: [...state.selectedFolioIds],
        destinos: [...state.selectedDestinationValues].map((value) => {
            const [tipo, id] = value.split(':'); return { tipo, id };
        }),
    };
    try {
        const lot = await api('/inspeccion-sag/lotes', { method: 'POST', body: JSON.stringify(body) });
        await loadWorkspace();
        state.selectedFolioIds.clear();
        state.selectedDestinationValues.clear();
        renderDestinationOptions();
        await openLot(lot.id);
        selectPanel(lot.estado === 'finalizado' ? 'historial' : 'activos');
        toast(`${lot.codigo} creado correctamente.`);
    } catch (error) { elements.builderError.textContent = error.message; toast(error.message, true); }
}

function selectPanel(panel) {
    document.querySelectorAll('[data-sag-panel]').forEach((button) => button.classList.toggle('is-active', button.dataset.sagPanel === panel));
    document.querySelectorAll('[data-sag-section]').forEach((section) => section.classList.toggle('is-hidden', section.dataset.sagSection !== panel));
}

async function openLot(id) {
    elements.detailError.textContent = '';
    state.selectedLot = await api(`/inspeccion-sag/lotes/${id}`);
    const lot = state.selectedLot;
    document.getElementById('sagDetailTitle').textContent = `${lot.codigo} · ${humanize(lot.tipo)}`;
    document.getElementById('sagDetailSubtitle').textContent = `${humanize(lot.estado)} · ${lot.cantidad_folios} pallets · SAG ${lot.numero_inspeccion_sag || 'sin número'} · creado ${dateTime(lot.creado_at)}`;
    document.getElementById('sagDetailDestinations').innerHTML = destinationPills(lot.destinos);
    renderDetailActions(lot);
    renderResults(lot);
    elements.detail.classList.remove('is-hidden');
    elements.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderDetailActions(lot) {
    const options = ['<option value="">Seleccionar acción</option>'];
    if (canManage() && lot.estado === 'preparacion') options.push('<option value="start">Iniciar inspección</option>');
    if (canManage() && ['en_inspeccion', 'resultado_parcial'].includes(lot.estado)) options.push('<option value="finish">Finalizar lote</option>');
    if (canManage() && !['finalizado', 'cancelado'].includes(lot.estado)) options.push('<option value="cancel">Cancelar lote</option>');
    elements.detailActions.innerHTML = options.join('');
    elements.detailActions.disabled = options.length === 1;
}

function renderResults(lot) {
    const editable = canManage() && ['en_inspeccion', 'resultado_parcial'].includes(lot.estado);
    const lotType = state.catalogs.tipos_lote.find((type) => type.value === lot.tipo);
    const fixedApproval = lotType?.tipo_aprobacion || '';
    const approvalOptions = state.catalogs.tipos_aprobacion.map((type) => `<option value="${type.value}">${escapeHtml(type.value)} · ${escapeHtml(type.label)}</option>`).join('');
    document.getElementById('sagResultList').innerHTML = lot.folios.map((entry) => `<article class="sag-result-card">
        <div class="sag-result-card__heading">
            <div><strong>${escapeHtml(entry.folio.numero)}</strong><p>${escapeHtml(entry.folio.cliente || '—')} · ${escapeHtml(entry.folio.especie || '—')} · ${escapeHtml(entry.folio.variedad || '—')} · ${escapeHtml(entry.folio.camara || '—')} / ${escapeHtml(entry.folio.posicion || 'Sin posición')}</p></div>
            <span class="sag-pill ${entry.estado === 'resuelto' ? 'sag-pill--active' : 'sag-pill--warning'}">${escapeHtml(humanize(entry.estado))}</span>
        </div>
        <div class="sag-result-grid">${entry.resultados.map((result) => {
            const approvalDisabled = fixedApproval || !editable || result.resultado !== 'aprobado';
            const options = fixedApproval
                ? `<option value="${fixedApproval}">${escapeHtml(fixedApproval)} · Determinado por la inspección</option>`
                : `<option value="">Seleccionar</option>${approvalOptions}`;

            return `<div class="sag-resolution" data-result-id="${result.id}" data-fixed-approval="${escapeHtml(fixedApproval)}">
                <label>Destino<strong>${escapeHtml(result.destino.codigo)} · ${escapeHtml(result.destino.nombre)}</strong></label>
                <label>Resultado<select data-decision ${editable ? '' : 'disabled'}>
                    <option value="pendiente" ${result.resultado === 'pendiente' ? 'selected' : ''}>Pendiente</option>
                    <option value="aprobado" ${result.resultado === 'aprobado' ? 'selected' : ''}>Aprobado</option>
                    <option value="segregado" ${result.resultado === 'segregado' ? 'selected' : ''}>Segregado</option>
                    <option value="sin_resolucion" ${result.resultado === 'sin_resolucion' ? 'selected' : ''}>Sale sin resolución</option>
                    <option value="rechazado" ${result.resultado === 'rechazado' ? 'selected' : ''}>Rechazado</option>
                </select></label>
                <label>Aprobación resultante<select data-approval ${approvalDisabled ? 'disabled' : ''}>${options}</select></label>
                ${editable ? '<button class="secondary-button" data-save-result type="button">Guardar</button>' : ''}
            </div>`;
        }).join('')}</div>
    </article>`).join('') || '<p class="empty-cell">El lote no contiene pallets.</p>';
    document.querySelectorAll('[data-result-id]').forEach((row) => {
        const result = lot.folios.flatMap((entry) => entry.resultados).find((item) => item.id === row.dataset.resultId);
        if (result?.tipo_aprobacion || fixedApproval) row.querySelector('[data-approval]').value = result?.tipo_aprobacion || fixedApproval;
    });
}

async function executeLotAction(id, action) {
    if (!action) return;
    if (action === 'view') { await openLot(id); return; }
    if (action === 'cancel' && !window.confirm('¿Cancelar este lote? Las aprobaciones ya emitidas se conservarán.')) return;
    try {
        await api(`/inspeccion-sag/lotes/${id}/${action === 'start' ? 'iniciar' : action === 'finish' ? 'finalizar' : 'cancelar'}`, { method: 'POST' });
        await loadWorkspace();
        await openLot(id);
        toast(action === 'start' ? 'Inspección iniciada.' : action === 'finish' ? 'Lote finalizado.' : 'Lote cancelado.');
    } catch (error) { elements.detailError.textContent = error.message; toast(error.message, true); }
}

async function saveResult(button) {
    const row = button.closest('[data-result-id]');
    const decision = row.querySelector('[data-decision]').value;
    const approval = row.querySelector('[data-approval]').value;
    if (decision === 'pendiente') { elements.detailError.textContent = 'Selecciona un resultado definitivo o salida sin resolución.'; return; }
    if (decision === 'aprobado' && !approval) { elements.detailError.textContent = 'Selecciona AO, AU o AF para aprobar.'; return; }
    try {
        await api(`/inspeccion-sag/lotes/${state.selectedLot.id}/resultados/${row.dataset.resultId}/resolver`, {
            method: 'POST', body: JSON.stringify({ resultado: decision, tipo_aprobacion: decision === 'aprobado' ? approval : null }),
        });
        await loadWorkspace();
        await openLot(state.selectedLot.id);
        toast('Resultado de inspección guardado.');
    } catch (error) { elements.detailError.textContent = error.message; toast(error.message, true); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = '';
    const data = new FormData(elements.login);
    try {
        const payload = await api('/acceso-oficina', { method: 'POST', body: JSON.stringify({ email: data.get('email'), password: data.get('password') }) });
        persistSession(payload); showApp(); await loadWorkspace();
    } catch (error) { elements.loginError.textContent = error.message; }
});

document.querySelectorAll('[data-sag-panel]').forEach((button) => button.addEventListener('click', () => selectPanel(button.dataset.sagPanel)));
elements.builder.elements.cliente.addEventListener('change', () => refreshCatalogHierarchy({ resetSpecies: true, resetOptional: true }));
elements.builder.elements.especie.addEventListener('change', () => refreshCatalogHierarchy({ resetOptional: true }));
document.getElementById('searchSagFoliosButton').addEventListener('click', searchFolios);
elements.searchSingleFolioButton.addEventListener('click', searchSingleFolio);
elements.singleFolioSearch.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    searchSingleFolio();
});
elements.builder.addEventListener('submit', createLot);
elements.eligibleBody.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-folio-id]'); if (!checkbox) return;
    if (checkbox.checked) state.selectedFolioIds.add(checkbox.dataset.folioId); else state.selectedFolioIds.delete(checkbox.dataset.folioId);
    updateBuilderSummary();
});
elements.destinationSearch.addEventListener('input', renderDestinationOptions);
elements.destinationOptions.addEventListener('change', (event) => {
    const option = event.target.closest('[data-destination-value]');
    if (!option) return;
    if (option.checked) state.selectedDestinationValues.add(option.dataset.destinationValue);
    else state.selectedDestinationValues.delete(option.dataset.destinationValue);
    normalizeEuropeanSelection(); renderDestinationOptions(); updateBuilderSummary();
});
document.addEventListener('change', async (event) => {
    const action = event.target.closest('.lot-action');
    if (action) { const value = action.value; action.value = ''; await executeLotAction(action.dataset.lotId, value); }
    const decision = event.target.closest('[data-decision]');
    if (decision) {
        const result = decision.closest('[data-result-id]');
        result.querySelector('[data-approval]').disabled = Boolean(result.dataset.fixedApproval) || decision.value !== 'aprobado';
    }
});
document.getElementById('sagResultList').addEventListener('click', (event) => { const button = event.target.closest('[data-save-result]'); if (button) saveResult(button); });
elements.detailActions.addEventListener('change', async () => { const action = elements.detailActions.value; elements.detailActions.value = ''; await executeLotAction(state.selectedLot.id, action); });
document.getElementById('closeSagDetailButton').addEventListener('click', () => elements.detail.classList.add('is-hidden'));
document.getElementById('reloadSagButton').addEventListener('click', loadWorkspace);

if (state.token && state.identity) {
    showApp();
    loadWorkspace().catch((error) => { elements.detailError.textContent = error.message; });
}
