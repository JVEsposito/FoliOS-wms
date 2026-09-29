import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'),
    app: byId('officeApp'),
    login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'),
    userName: byId('officeUserName'),
    userRole: byId('officeUserRole'),
    initials: byId('officeInitials'),
    logout: byId('officeLogoutButton'),
    camerasNav: byId('officeCamerasNav'),
    managementNav: byId('officeManagementNav'),
    reload: byId('reloadButton'),
    seasonDescription: byId('seasonDescription'),
    pendingSegmentsCount: byId('pendingSegmentsCount'),
    draftLotsCount: byId('draftLotsCount'),
    hydrocoolerLotsCount: byId('hydrocoolerLotsCount'),
    cameraPendingCount: byId('cameraPendingCount'),
    segmentList: byId('segmentList'),
    filters: byId('lotFilters'),
    lotTableBody: byId('lotTableBody'),
    lotDialog: byId('lotDialog'),
    lotForm: byId('lotForm'),
    lotDialogTitle: byId('lotDialogTitle'),
    lotDialogDescription: byId('lotDialogDescription'),
    lotSourceSummary: byId('lotSourceSummary'),
    lotFormError: byId('lotFormError'),
    saveAndConfirm: byId('saveAndConfirmButton'),
    operationDialog: byId('operationDialog'),
    operationForm: byId('operationForm'),
    operationEyebrow: byId('operationEyebrow'),
    operationTitle: byId('operationTitle'),
    operationDescription: byId('operationDescription'),
    operationFields: byId('operationFields'),
    operationFormError: byId('operationFormError'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};

const keys = {
    token: 'estiba_wms_office_token',
    identity: 'estiba_wms_office_identity',
};

const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    summary: null,
    catalogs: null,
    catalogEtag: null,
    segments: [],
    lots: [],
    editingLot: null,
    selectedSegment: null,
    poller: null,
};

class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

function readJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function label(value) {
    const labels = {
        digitador_materia_prima: 'Digitador de materia prima',
        supervisor_frio: 'Supervisor de frío',
        administrador: 'Administrador',
        consulta: 'Solo consulta',
        borrador: 'Borrador',
        pendiente_hidrocooler: 'Pendiente hidrocooler',
        hidrocooler_en_curso: 'Hidrocooler en curso',
        hidrocooler_retenido: 'Retenido por Hidrocooler',
        pendiente_asignacion: 'Pendiente de cámara',
        disponible_proceso: 'Disponible directo a proceso',
        asignado_camara: 'Asignado a cámara',
        entrega_parcial_proceso: 'Entrega parcial a Packing',
        entregado_proceso: 'Entregado a Packing',
        anulado: 'Anulado',
        materia_prima: 'Materia prima',
        comercial: 'Comercial',
        precalibre: 'Precalibre',
        descarte: 'Descarte',
        bins: 'Bins',
        totes: 'Totes',
        esponjas: 'Esponjas',
    };

    return labels[value] || String(value || '')
        .replaceAll('_', ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

function formatWeight(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return `${new Intl.NumberFormat('es-CL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 3,
    }).format(Number(value))} kg`;
}

function formatDate(value, fallback = 'Pendiente') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function localDateTimeValue() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
}

function localDateValue() {
    return localDateTimeValue().slice(0, 10);
}

function stateBadge(status) {
    const style = status === 'borrador'
        ? 'draft'
        : ['pendiente_hidrocooler', 'hidrocooler_en_curso', 'hidrocooler_retenido'].includes(status)
            ? 'hydro'
            : status === 'pendiente_asignacion'
                ? 'camera'
                : ['disponible_proceso', 'asignado_camara', 'entrega_parcial_proceso', 'entregado_proceso'].includes(status)
                    ? 'assigned'
                    : 'void';
    return `<span class="raw-state raw-state--${style}">${escapeHtml(label(status))}</span>`;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 5000);
}

function persist(payload) {
    state.token = payload.token;
    state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}

function clearSession() {
    state.token = null;
    state.identity = null;
    state.summary = null;
    state.catalogs = null;
    state.catalogEtag = null;
    state.segments = [];
    state.lots = [];
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    state.poller?.stop();
    state.poller = null;
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }
    const data = response.status === 204
        ? null
        : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(
            errorMessage(data, 'No fue posible completar la operación.'),
            response.status,
            data,
        );
    }
    return data;
}

async function loadCatalogs() {
    const headers = new Headers({ Accept: 'application/json' });
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (state.catalogEtag) headers.set('If-None-Match', state.catalogEtag);

    let response;
    try {
        response = await fetch('/api/materia-prima/catalogos', { headers });
    } catch {
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }

    if (response.status === 304 && state.catalogs) {
        return state.catalogs;
    }

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) clearSession();
        throw new ApiError(
            errorMessage(data, 'No fue posible actualizar los catálogos de materia prima.'),
            response.status,
            data,
        );
    }

    state.catalogEtag = response.headers.get('ETag');

    return data;
}

function showApp() {
    if (state.identity?.puede_consultar_materia_prima !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario de materia prima';
    elements.userName.textContent = name;
    elements.userRole.textContent = label(state.identity?.rol || 'consulta');
    elements.initials.textContent = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    elements.camerasNav.classList.toggle(
        'is-hidden',
        state.identity?.ambito_camaras === 'ninguno',
    );
    elements.managementNav.classList.toggle(
        'is-hidden',
        state.identity?.puede_consultar_panel_gerencial !== true,
    );
    return true;
}

function renderSummary() {
    const summary = state.summary;
    elements.pendingSegmentsCount.textContent = String(summary?.segmentos_pendientes || 0);
    elements.draftLotsCount.textContent = String(summary?.lotes?.borradores || 0);
    elements.hydrocoolerLotsCount.textContent = String((summary?.lotes?.pendientes_hidrocooler || 0) + (summary?.lotes?.retenidos_hidrocooler || 0));
    elements.cameraPendingCount.textContent = String(summary?.lotes?.pendientes_asignacion || 0);
    elements.seasonDescription.textContent = summary?.temporada
        ? `${summary.temporada.nombre} · ${summary.temporada.codigo}. Lotización y destino de materia prima validada.`
        : 'No existe una temporada global activa.';
}

function renderSegments() {
    if (!state.segments.length) {
        elements.segmentList.innerHTML = '<div class="raw-material-empty">No hay segmentos pendientes de lotización en la temporada activa.</div>';
        return;
    }

    const canManage = state.identity?.puede_gestionar_lotes_materia_prima === true;
    elements.segmentList.innerHTML = state.segments.map((segment) => {
        const base = segment.envases.find((item) => item.tipo_envase === segment.recepcion.tipo_envase_calculo_neto);
        const canCreate = canManage && segment.estado === 'pendiente_lote'
            && Number(base?.cantidad_disponible || 0) > 0
            && Number(segment.recepcion.peso_neto_por_envase || 0) > 0;
        const origin = [
            segment.csg?.codigo,
            segment.variedad?.nombre,
            segment.cuartel,
        ].filter(Boolean).join(' · ') || 'Sin segregación adicional';
        return `
            <article class="segment-card">
                <div class="segment-card__top">
                    <strong>${escapeHtml(segment.recepcion.numero_recepcion)} · S${escapeHtml(segment.secuencia)}</strong>
                    <span>${escapeHtml(label(segment.estado))}</span>
                </div>
                <p>${escapeHtml(segment.recepcion.cliente.nombre)} · Guía ${escapeHtml(segment.recepcion.numero_guia_despacho)}</p>
                <p>${escapeHtml(origin)}</p>
                <div class="segment-card__containers">
                    ${segment.envases.map((container) => `<span>${escapeHtml(label(container.tipo_envase))}: ${escapeHtml(container.cantidad_disponible)} disponibles de ${escapeHtml(container.cantidad)}</span>`).join('')}
                </div>
                <div class="segment-card__net">
                    <div><span>NETO ESTIMADO SEGMENTO</span><strong>${escapeHtml(formatWeight(segment.neto_estimado))}</strong></div>
                    <div><span>NETO / ${escapeHtml(label(segment.recepcion.tipo_envase_calculo_neto))}</span><strong>${escapeHtml(formatWeight(segment.recepcion.peso_neto_por_envase))}</strong></div>
                </div>
                <div class="segment-card__actions">
                    <button class="primary-button" data-create-segment="${escapeHtml(segment.id)}" type="button" ${canCreate ? '' : 'disabled'}>+ Crear lote</button>
                </div>
            </article>`;
    }).join('');
}

function lotActions(lot) {
    const actions = [];
    const canManage = state.identity?.puede_gestionar_lotes_materia_prima === true;
    const canSupervise = state.identity?.puede_supervisar_lotes_materia_prima === true;
    if (canManage && lot.estado === 'borrador') {
        actions.push(`<button data-action="edit" data-lot-id="${escapeHtml(lot.id)}" type="button">Editar</button>`);
        actions.push(`<button class="is-primary" data-action="confirm" data-lot-id="${escapeHtml(lot.id)}" type="button">Confirmar</button>`);
    }
    if (canManage && !['borrador', 'anulado'].includes(lot.estado)) {
        actions.push(`<button data-action="correct-origin" data-lot-id="${escapeHtml(lot.id)}" type="button">Corregir origen</button>`);
    }
    if (canManage && lot.estado === 'pendiente_asignacion') {
        actions.push(`<button class="is-primary" data-action="assign" data-lot-id="${escapeHtml(lot.id)}" type="button">Asignar cámara</button>`);
    }
    if (canSupervise && ['borrador', 'pendiente_hidrocooler', 'pendiente_asignacion'].includes(lot.estado)) {
        actions.push(`<button class="is-danger" data-action="void" data-lot-id="${escapeHtml(lot.id)}" type="button">Anular</button>`);
    }
    return actions.join('') || '<small>Sin acciones pendientes</small>';
}

function renderLots() {
    if (!state.lots.length) {
        elements.lotTableBody.innerHTML = '<tr><td class="raw-material-empty" colspan="6">No existen lotes para los filtros seleccionados.</td></tr>';
        return;
    }

    elements.lotTableBody.innerHTML = state.lots.map((lot) => `
        <tr>
            <td><strong>${escapeHtml(lot.numero_lote)}</strong><small>${escapeHtml(lot.recepcion?.numero_recepcion)} · guía ${escapeHtml(lot.recepcion?.numero_guia_despacho)}</small><small>${escapeHtml(lot.cliente?.nombre)}</small></td>
            <td><strong>CSG ${escapeHtml(lot.trazabilidad.csg)}</strong><small>SdP ${escapeHtml(lot.trazabilidad.sdp)} · GGN ${escapeHtml(lot.trazabilidad.ggn)}</small><small>${escapeHtml(lot.trazabilidad.predio)}${lot.trazabilidad.cuartel ? ` · ${escapeHtml(lot.trazabilidad.cuartel)}` : ' · Sin cuartel'}</small></td>
            <td><strong>${escapeHtml(lot.trazabilidad.especie)} · ${escapeHtml(lot.trazabilidad.variedad)}</strong><small>${escapeHtml(label(lot.trazabilidad.tipo_producto))}${lot.trazabilidad.calibre ? ` · Calibre histórico: ${escapeHtml(lot.trazabilidad.calibre)}` : ''}</small><small>Cosecha ${escapeHtml(lot.trazabilidad.fecha_cosecha)}</small></td>
            <td><strong>${(lot.envases.detalle || []).map((item) => `${escapeHtml(item.cantidad)} ${escapeHtml(label(item.tipo_envase))}`).join(' · ')}</strong><small class="weight-value">${escapeHtml(formatWeight(lot.pesos.kilos_netos_confirmados))} netos</small><small>${lot.pesos.corregido_por_digitador ? `Calculado: ${escapeHtml(formatWeight(lot.pesos.kilos_netos_calculados))}` : 'Neto calculado confirmado'}</small></td>
            <td>${stateBadge(lot.estado)}<small>${lot.requiere_hidrocooler ? 'Con hidrocooler' : 'Sin hidrocooler'}</small>${lot.asignacion_camara?.camara ? `<small>Cámara ${escapeHtml(lot.asignacion_camara.camara.codigo)}</small>` : ''}</td>
            <td><div class="lot-actions">${lotActions(lot)}</div></td>
        </tr>`).join('');
}

function fillBaseCatalogs() {
    const form = elements.lotForm.elements;
    const clientId = state.selectedSegment?.recepcion?.cliente?.id;
    const availableCsg = state.catalogs.csg.filter((item) => (
        item.disponible_todos_clientes
        || item.cliente_ids?.includes(clientId)
    ));
    form.csg_validacion_id.innerHTML = '<option value="">Seleccionar CSG</option>'
        + availableCsg.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.codigo)} · ${escapeHtml(item.predio)}</option>`).join('');
    form.especie_validacion_id.innerHTML = '<option value="">Seleccionar especie</option>'
        + state.catalogs.especies.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.nombre)}</option>`).join('');
    form.tipo_producto.innerHTML = state.catalogs.tipos_producto
        .map((type) => `<option value="${escapeHtml(type)}">${escapeHtml(label(type))}</option>`)
        .join('');
    updateSpeciesDependants();
}

function updateSpeciesDependants(selectedVariety = '') {
    const form = elements.lotForm.elements;
    const species = state.catalogs?.especies.find(
        (item) => item.id === form.especie_validacion_id.value,
    );
    form.variedad_validacion_id.innerHTML = '<option value="">Seleccionar variedad</option>'
        + (species?.variedades || []).map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.nombre)}</option>`).join('');
    form.variedad_validacion_id.value = selectedVariety;
}

function renderSourceSummary(segment) {
    const available = segment.envases.filter((item) => Number(item.cantidad_disponible || 0) > 0)
        .map((item) => `${item.cantidad_disponible} ${label(item.tipo_envase)}`).join(' · ');
    elements.lotSourceSummary.innerHTML = `
        <div><span>RECEPCIÓN</span><strong>${escapeHtml(segment.recepcion.numero_recepcion)}</strong></div>
        <div><span>EXPORTADORA / CLIENTE</span><strong>${escapeHtml(segment.recepcion.cliente.nombre)}</strong></div>
        <div><span>ENVASES DISPONIBLES</span><strong>${escapeHtml(available || 'Sin envases')}</strong></div>
        <div><span>NETO UNITARIO ROMANA</span><strong>${escapeHtml(formatWeight(segment.recepcion.peso_neto_por_envase))}</strong></div>`;
}

function updateCalculatedNet() {
    const form = elements.lotForm.elements;
    const segment = state.selectedSegment;
    if (!segment) return;
    const calculated = Number(segment.neto_estimado);
    const tare = (type) => Number(segment.recepcion.envases?.find((item) => item.tipo_envase === type)?.tara_unitaria || 0);
    const gross = calculated + segment.envases.reduce((sum, item) => sum + Number(item.cantidad) * tare(item.tipo_envase), 0);
    form.kilos_netos_calculados.value = calculated.toFixed(3);
    form.kilos_brutos.value = gross.toFixed(3);
}

function openCreateLot(segmentId) {
    const segment = state.segments.find((item) => item.id === segmentId);
    if (!segment || !state.catalogs) return;
    state.editingLot = null;
    state.selectedSegment = segment;
    elements.lotForm.reset();
    elements.lotFormError.textContent = '';
    elements.lotDialogTitle.textContent = 'Crear lote';
    elements.lotDialogDescription.textContent = 'El lote recibirá todos los envases y kilos calculados para este segmento.';
    const form = elements.lotForm.elements;
    form.lote_id.value = '';
    form.version_conocida.value = '';
    form.segmento_validacion_mp_id.value = segment.id;
    form.operacion_id.value = operationUuid();
    form.confirmacion_operacion_id.value = operationUuid();
    fillBaseCatalogs();
    updateCalculatedNet();
    renderSourceSummary(segment);
    form.fecha_cosecha.max = localDateValue();
    form.fecha_cosecha.value = localDateValue();
    form.tipo_producto.value = 'materia_prima';
    form.requiere_hidrocooler.value = '0';
    if (segment.csg) {
        form.csg_validacion_id.value = segment.csg.id;
        form.predio.value = segment.csg.predio || '';
    }
    if (segment.variedad) {
        form.especie_validacion_id.value = segment.variedad.especie_id;
        updateSpeciesDependants(segment.variedad.id);
    }
    form.csg_validacion_id.disabled = Boolean(segment.csg);
    form.especie_validacion_id.disabled = Boolean(segment.variedad);
    form.variedad_validacion_id.disabled = Boolean(segment.variedad);
    form.cuartel.value = segment.cuartel || '';
    elements.lotDialog.showModal();
    form.numero_lote.focus();
}

function openEditLot(lotId) {
    const lot = state.lots.find((item) => item.id === lotId);
    if (!lot || lot.estado !== 'borrador') return;
    const segment = state.segments.find((item) => item.id === lot.segmento.id);
    if (!segment) {
        toast('Actualiza el módulo para recuperar el segmento del borrador.', true);
        return;
    }
    state.editingLot = lot;
    state.selectedSegment = segment;
    elements.lotForm.reset();
    elements.lotFormError.textContent = '';
    elements.lotDialogTitle.textContent = `Editar ${lot.numero_lote}`;
    elements.lotDialogDescription.textContent = 'Solo los borradores pueden editarse. La confirmación cerrará estos antecedentes.';
    const form = elements.lotForm.elements;
    fillBaseCatalogs();
    updateCalculatedNet();
    renderSourceSummary(segment);
    form.lote_id.value = lot.id;
    form.version_conocida.value = lot.version;
    form.segmento_validacion_mp_id.value = lot.segmento.id;
    form.operacion_id.value = operationUuid();
    form.confirmacion_operacion_id.value = operationUuid();
    form.numero_lote.value = lot.numero_lote;
    form.fecha_cosecha.value = lot.trazabilidad.fecha_cosecha;
    form.fecha_cosecha.max = localDateValue();
    form.csg_validacion_id.value = lot.trazabilidad.csg_id;
    form.predio.value = lot.trazabilidad.predio;
    form.sdp.value = lot.trazabilidad.sdp;
    form.ggn.value = lot.trazabilidad.ggn;
    form.especie_validacion_id.value = lot.trazabilidad.especie_id;
    updateSpeciesDependants(lot.trazabilidad.variedad_id);
    form.csg_validacion_id.disabled = Boolean(segment.csg);
    form.especie_validacion_id.disabled = Boolean(segment.variedad);
    form.variedad_validacion_id.disabled = Boolean(segment.variedad);
    form.cuartel.value = lot.trazabilidad.cuartel;
    form.tipo_producto.value = lot.trazabilidad.tipo_producto;
    updateCalculatedNet();
    form.requiere_hidrocooler.value = lot.requiere_hidrocooler ? '1' : '0';
    form.observacion.value = lot.observacion || '';
    elements.lotDialog.showModal();
    form.numero_lote.focus();
}

function lotPayload() {
    const form = elements.lotForm.elements;
    return {
        operacion_id: form.operacion_id.value,
        version_conocida: form.version_conocida.value || undefined,
        segmento_validacion_mp_id: form.segmento_validacion_mp_id.value,
        numero_lote: form.numero_lote.value,
        csg_validacion_id: form.csg_validacion_id.value,
        sdp: form.sdp.value,
        ggn: form.ggn.value,
        fecha_cosecha: form.fecha_cosecha.value,
        predio: form.predio.value,
        especie_validacion_id: form.especie_validacion_id.value,
        variedad_validacion_id: form.variedad_validacion_id.value,
        cuartel: form.cuartel.value,
        tipo_producto: form.tipo_producto.value,
        requiere_hidrocooler: form.requiere_hidrocooler.value === '1',
        observacion: form.observacion.value || null,
    };
}

async function saveLot(confirmAfterSave) {
    const payload = lotPayload();
    const isEditing = Boolean(state.editingLot);
    const path = isEditing
        ? `/api/materia-prima/lotes/${state.editingLot.id}`
        : '/api/materia-prima/lotes';
    const result = await api(path, {
        method: isEditing ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
    });
    let lot = result.data;
    if (confirmAfterSave) {
        const confirmed = await api(`/api/materia-prima/lotes/${lot.id}/confirmar`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: elements.lotForm.elements.confirmacion_operacion_id.value,
                version_conocida: lot.version,
            }),
        });
        lot = confirmed.data;
    }
    return lot;
}

function openOperation(type, lotId) {
    const lot = state.lots.find((item) => item.id === lotId);
    if (!lot) return;
    const form = elements.operationForm.elements;
    elements.operationForm.reset();
    elements.operationFormError.textContent = '';
    form.operation_type.value = type;
    form.lote_id.value = lot.id;
    form.operacion_id.value = operationUuid();
    const definitions = {
        'correct-origin': {
            eyebrow: 'CORRECCIÓN DE ORIGEN',
            title: `Corregir ${lot.numero_lote}`,
            description: 'Esta corrección no modifica kilos, envases, segmento, hidrocooler ni cámara.',
            fields: `
                <label class="field"><span>Cuartel</span><input name="cuartel" maxlength="100" value="${escapeHtml(lot.trazabilidad.cuartel || '')}"><small>Opcional.</small></label>
                ${lot.trazabilidad.calibre
                    ? `<div class="field"><span>Calibre histórico</span><input value="${escapeHtml(lot.trazabilidad.calibre)}" disabled><label><input name="retirar_calibre" type="checkbox" value="1" checked> Retirar este dato del lote</label></div>`
                    : '<input name="retirar_calibre" type="hidden" value="0">'}`,
        },
        assign: {
            eyebrow: 'DESTINO DE MATERIA PRIMA',
            title: `Asignar ${lot.numero_lote} a cámara`,
            description: 'La asignación es a una cámara exclusiva de materia prima; no crea un folio ni ocupa una posición.',
            fields: `
                <label class="field"><span>Cámara *</span><select name="camara_id" required><option value="">Seleccionar cámara</option>${state.catalogs.camaras.map((camera) => `<option value="${escapeHtml(camera.id)}">${escapeHtml(camera.codigo)} · ${escapeHtml(camera.nombre)}</option>`).join('')}</select></label>
                <label class="field"><span>Observación</span><textarea name="observacion" maxlength="2000"></textarea></label>`,
        },
        void: {
            eyebrow: 'CORRECCIÓN SUPERVISADA',
            title: `Anular ${lot.numero_lote}`,
            description: 'La anulación conserva el historial, libera el segmento y permite recrear el número de lote correctamente.',
            fields: '<label class="field"><span>Motivo detallado *</span><textarea name="motivo" minlength="10" maxlength="2000" required></textarea></label>',
        },
    };
    const definition = definitions[type];
    if (!definition) return;
    elements.operationEyebrow.textContent = definition.eyebrow;
    elements.operationTitle.textContent = definition.title;
    elements.operationDescription.textContent = definition.description;
    elements.operationFields.innerHTML = definition.fields;
    elements.operationDialog.showModal();
    elements.operationFields.querySelector('input, select, textarea')?.focus();
}

async function submitOperation() {
    const values = Object.fromEntries(new FormData(elements.operationForm));
    const type = values.operation_type;
    const lotId = values.lote_id;
    const payload = { operacion_id: values.operacion_id };
    let path = '';
    let method = 'POST';
    if (type === 'correct-origin') {
        const lot = state.lots.find((item) => item.id === lotId);
        path = `/api/materia-prima/lotes/${lotId}/corregir-origen`;
        method = 'PUT';
        payload.version_conocida = lot?.version;
        payload.cuartel = values.cuartel || null;
        payload.retirar_calibre = values.retirar_calibre === '1';
    } else if (type === 'assign') {
        path = `/api/materia-prima/lotes/${lotId}/asignar-camara`;
        payload.camara_id = values.camara_id;
        payload.observacion = values.observacion || null;
    } else if (type === 'void') {
        path = `/api/materia-prima/lotes/${lotId}/anular`;
        payload.motivo = values.motivo;
    }
    if (!path) return;
    await api(path, { method, body: JSON.stringify(payload) });
}

function filtersQuery() {
    const query = new URLSearchParams();
    Object.entries(Object.fromEntries(new FormData(elements.filters)))
        .forEach(([key, value]) => {
            if (value) query.set(key, value);
        });
    return query.toString();
}

async function loadLots() {
    const payload = await api(`/api/materia-prima/lotes?${filtersQuery()}`);
    state.lots = payload.data;
    renderLots();
}

async function loadAll({ notify = false } = {}) {
    const [summary, catalogs, segments, lots] = await Promise.all([
        api('/api/materia-prima/resumen'),
        loadCatalogs(),
        api('/api/materia-prima/segmentos-pendientes'),
        api(`/api/materia-prima/lotes?${filtersQuery()}`),
    ]);
    state.summary = summary;
    state.catalogs = catalogs;
    state.segments = segments.data;
    state.lots = lots.data;
    renderSummary();
    renderSegments();
    renderLots();
    if (notify) toast('Materia prima actualizada.');
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    setBusy(true, 'Verificando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', {
            method: 'POST',
            body: JSON.stringify(Object.fromEntries(new FormData(elements.login))),
        });
        if (payload.usuario?.puede_consultar_materia_prima !== true) {
            throw new ApiError('El usuario no posee acceso a Materia prima.', 403);
        }
        persist(payload);
        showApp();
        await loadAll();
        startRefresh();
    } catch (error) {
        elements.loginError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.logout.addEventListener('click', async () => {
    try {
        await api('/api/acceso-oficina', { method: 'DELETE' });
    } finally {
        clearSession();
    }
});

elements.reload.addEventListener('click', async () => {
    setBusy(true, 'Actualizando materia prima…');
    try {
        await loadAll({ notify: true });
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.filters.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(true, 'Aplicando filtros…');
    try {
        await loadLots();
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.segmentList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-create-segment]');
    if (button && !button.disabled) openCreateLot(button.dataset.createSegment);
});

elements.lotForm.elements.especie_validacion_id.addEventListener('change', () => {
    updateSpeciesDependants();
});

elements.lotForm.elements.csg_validacion_id.addEventListener('change', () => {
    const selected = state.catalogs?.csg.find(
        (item) => item.id === elements.lotForm.elements.csg_validacion_id.value,
    );
    if (selected) elements.lotForm.elements.predio.value = selected.predio || '';
});

['sdp', 'ggn'].forEach((name) => {
    elements.lotForm.elements[name].addEventListener('input', (event) => {
        event.target.value = event.target.value.replace(/\D/g, '');
    });
});

elements.lotForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    elements.lotFormError.textContent = '';
    const confirmAfterSave = event.submitter?.value === 'confirm';
    setBusy(
        true,
        confirmAfterSave ? 'Guardando y confirmando lote…' : 'Guardando borrador…',
    );
    try {
        const lot = await saveLot(confirmAfterSave);
        elements.lotDialog.close();
        await loadAll();
        toast(confirmAfterSave
            ? `${lot.numero_lote} confirmado: ${label(lot.estado)}.`
            : `${lot.numero_lote} guardado como borrador.`);
    } catch (error) {
        elements.lotFormError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

elements.lotTableBody.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const { action, lotId } = button.dataset;
    if (action === 'edit') {
        openEditLot(lotId);
        return;
    }
    if (action === 'confirm') {
        const lot = state.lots.find((item) => item.id === lotId);
        if (!lot || !window.confirm(`¿Confirmar definitivamente el lote ${lot.numero_lote}?`)) return;
        setBusy(true, 'Confirmando lote…');
        try {
            button.dataset.operationId ||= operationUuid();
            await api(`/api/materia-prima/lotes/${lot.id}/confirmar`, {
                method: 'POST',
                body: JSON.stringify({
                    operacion_id: button.dataset.operationId,
                    version_conocida: lot.version,
                }),
            });
            await loadAll();
            toast(`${lot.numero_lote} confirmado.`);
        } catch (error) {
            toast(error.message, true);
        } finally {
            setBusy(false);
        }
        return;
    }
    openOperation(action, lotId);
});

elements.operationForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    elements.operationFormError.textContent = '';
    setBusy(true, 'Registrando operación…');
    try {
        await submitOperation();
        elements.operationDialog.close();
        await loadAll();
        toast('Operación registrada correctamente.');
    } catch (error) {
        elements.operationFormError.textContent = error.message;
    } finally {
        setBusy(false);
    }
});

function startRefresh() {
    state.poller?.stop();
    state.poller = createOperationalPoller(
        loadAll,
        {
            intervalMs: 30000,
            canRun: () => Boolean(state.token)
                && !elements.lotDialog.open
                && !elements.operationDialog.open,
        },
    );
    state.poller.start();
}

async function boot() {
    if (!state.token || state.identity?.puede_consultar_materia_prima !== true) {
        if (state.token) clearSession();
        return;
    }
    if (!showApp()) return;
    setBusy(true, 'Cargando materia prima…');
    try {
        await loadAll();
        startRefresh();
    } catch (error) {
        if (error.status !== 401) toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

void boot();
