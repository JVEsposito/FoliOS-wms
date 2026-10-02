import { dispatchInspectionPayload } from './office-container-inspection.js';
import { loadContainerCatalog, containerLabel, calculateNetAllocation } from './office-container-catalog.js';
import { createOperationalPoller } from './shared/operational-poller';

const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'), loginError: byId('officeLoginError'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'), logout: byId('officeLogoutButton'),
    managementNav: byId('officeManagementNav'), rawMaterialNav: byId('officeRawMaterialNav'), containerAccountsNav: byId('officeContainerAccountsNav'), camerasNav: byId('officeCamerasNav'), loadsNav: byId('officeLoadsNav'), materialsNav: byId('officeMaterialsNav'), validationNav: byId('officeValidationNav'), prefrioNav: byId('officePrefrioNav'), accessesNav: byId('officeAccessesNav'),
    rc02Reception: byId('downloadRc02ReceptionButton'), rc02Dispatch: byId('downloadRc02DispatchButton'), inspectionPanel: byId('dispatchInspectionPanel'), inspectionItems: byId('dispatchInspectionItems'), inspectionObservation: byId('dispatchInspectionObservation'),
    reload: byId('reloadButton'), blankWeighingForm: byId('downloadBlankWeighingFormButton'), newReception: byId('newReceptionButton'), filters: byId('receptionFilters'), tableBody: byId('receptionTableBody'),
    entryCount: byId('entryCount'), containerWeighingCount: byId('containerWeighingCount'), exitCount: byId('exitCount'), closedCount: byId('closedCount'), netWeight: byId('netWeight'),
    paginationSummary: byId('paginationSummary'), previousPage: byId('previousPageButton'), nextPage: byId('nextPageButton'),
    detail: byId('receptionDetail'), detailTitle: byId('detailTitle'), detailSubtitle: byId('detailSubtitle'), detailFacts: byId('detailFacts'), detailTimeline: byId('detailTimeline'), weightBalance: byId('weightBalance'),
    editReception: byId('editReceptionButton'), confirmEntry: byId('confirmEntryButton'), addContainerWeighing: byId('addContainerWeighingButton'), closeReception: byId('closeReceptionButton'), correctExit: byId('correctExitButton'), downloadReceipt: byId('downloadReceiptButton'), closeDetail: byId('closeDetailButton'),
    containerWeighingPanel: byId('containerWeighingPanel'), containerWeighingProgress: byId('containerWeighingProgress'), containerWeighingList: byId('containerWeighingList'),
    receptionDialog: byId('receptionDialog'), receptionForm: byId('receptionForm'), receptionDialogTitle: byId('receptionDialogTitle'), receptionDialogDescription: byId('receptionDialogDescription'), receptionFormError: byId('receptionFormError'), saveReception: byId('saveReceptionButton'),
    serviceField: byId('serviceField'), containerEntryDateField: byId('containerEntryDateField'), containerConceptField: byId('containerConceptField'), standardContainerLines: byId('standardContainerLines'), containerWeighingFields: byId('containerWeighingFields'), grossWeightField: byId('grossWeightField'), administrativeCorrectionField: byId('administrativeCorrectionField'), administrativeTareField: byId('administrativeTareField'), administrativeNetContainerField: byId('administrativeNetContainerField'),
    tareDialog: byId('tareDialog'), tareForm: byId('tareForm'), tareDescription: byId('tareDescription'), tareFormError: byId('tareFormError'), outboundContainerTares: byId('outboundContainerTares'), outboundContainerTareList: byId('outboundContainerTareList'), outboundGuideField: byId('outboundGuideField'), outboundExtraField: byId('outboundExtraField'), outboundExtraType: byId('outboundExtraType'), outboundAddType: byId('outboundAddType'), exitCorrectionReasonField: byId('exitCorrectionReasonField'), containerTarePreviewRow: byId('containerTarePreviewRow'), containerTarePreview: byId('containerTarePreview'), netWeightPreview: byId('netWeightPreview'), netPerContainerPreview: byId('netPerContainerPreview'),
    containerWeighingDialog: byId('containerWeighingDialog'), containerWeighingForm: byId('containerWeighingForm'), containerWeighingDescription: byId('containerWeighingDescription'), containerWeighingFormError: byId('containerWeighingFormError'), containerWeighingTarePreview: byId('containerWeighingTarePreview'), containerWeighingNetPreview: byId('containerWeighingNetPreview'),
    loading: byId('officeLoading'), loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
};

const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    catalogs: { temporadas: [], clientes: [], especies: [], tipos_servicio: [], tipos_envase: [], tipos_recepcion: [], conceptos_envases: [] },
    receptions: [],
    selected: null,
    page: 1,
    meta: null,
    poller: null,
    administrativeCorrection: false,
    tareCorrection: false,
};

class ApiError extends Error {
    constructor(message, status, data = {}) { super(message); this.name = 'ApiError'; this.status = status; this.data = data; }
}

function readJson(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function errorMessage(data, fallback) { return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback; }
function operationUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
function formatDate(value, fallback = 'Pendiente') {
    if (!value) return fallback;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function formatWeight(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return `${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(Number(value))} kg`;
}
function label(value) {
    if (containerLabel(value)) return containerLabel(value);
    const labels = {
        mismos: 'Se va con los mismos', diferentes: 'Se va con más o menos', vacio: 'Se va vacío',
        en_bascula_ingreso: 'En báscula ingreso', en_pesaje_envases: 'Pesaje acumulativo', en_bascula_salida: 'Pendiente de destare', cerrado: 'Cerrado',
        ingreso_registrado: 'Ingreso registrado', ingreso_actualizado: 'Antecedentes de ingreso actualizados', correccion_administrativa: 'Corrección administrativa', ingreso_confirmado: 'Ingreso confirmado', pesaje_envases_registrado: 'Tanda de envases pesada', pesaje_envases_anulado: 'Tanda de pesaje anulada', recepcion_cerrada: 'Recepción cerrada', salida_envases_corregida: 'Salida de envases corregida',
        almacenaje: 'Almacenaje', proceso: 'Proceso', prefrio: 'Pre-frío', fruta_con_envases: 'Fruta con envases', fruta_pesaje_envases: 'Fruta con pesaje acumulativo', solo_envases: 'Solo envases', termo: 'Camión termo', plano: 'Camión plano', compra: 'Compra', arriendo: 'Arriendo', pendiente: 'Pendiente', en_curso: 'En curso', validada: 'Validada',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());
}
function stateBadge(status) {
    const style = status === 'cerrado' ? 'closed' : ['en_bascula_salida', 'en_pesaje_envases'].includes(status) ? 'exit' : 'entry';
    return `<span class="state-badge state-badge--${style}">${escapeHtml(label(status))}</span>`;
}
function receptionStateLabel(reception, status) {
    if (reception.tipo_recepcion !== 'solo_envases') return label(status);
    if (status === 'en_bascula_ingreso') return 'Ingreso documental';
    if (status === 'en_bascula_salida') return 'Listo para cerrar';
    return label(status);
}
function receptionStateBadge(reception) {
    if (reception.tipo_recepcion !== 'solo_envases') return stateBadge(reception.estado);
    const contextualLabel = receptionStateLabel(reception, reception.estado);
    const style = reception.estado === 'cerrado' ? 'closed' : reception.estado === 'en_bascula_salida' ? 'exit' : 'entry';
    return `<span class="state-badge state-badge--${style}">${escapeHtml(contextualLabel)}</span>`;
}
function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const node = document.createElement('div'); node.className = `toast${error ? ' toast--error' : ''}`; node.textContent = message; elements.toasts.append(node); window.setTimeout(() => node.remove(), 4500);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    state.token = null; state.identity = null; state.receptions = []; state.selected = null;
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); state.poller?.stop(); state.poller = null;
    elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden');
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {}); headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.', 0); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data);
    }
    return data;
}

function showApp() {
    if (state.identity?.puede_consultar_romana !== true) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario de romana';
    elements.userName.textContent = name; elements.userRole.textContent = label(state.identity?.rol || 'consulta');
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    elements.newReception.classList.toggle('is-hidden', state.identity?.puede_operar_romana !== true);
    elements.rawMaterialNav.classList.toggle('is-hidden', state.identity?.puede_consultar_materia_prima !== true);
    elements.containerAccountsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cuenta_envases !== true);
    elements.managementNav.classList.toggle('is-hidden', state.identity?.puede_consultar_panel_gerencial !== true);
    elements.camerasNav.classList.toggle('is-hidden', state.identity?.ambito_camaras === 'ninguno');
    elements.loadsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_cargas !== true);
    elements.materialsNav.classList.toggle('is-hidden', state.identity?.puede_consultar_despachos_materiales !== true);
    elements.validationNav.classList.toggle('is-hidden', state.identity?.puede_consultar_validaciones_pallet !== true);
    elements.prefrioNav.classList.toggle('is-hidden', state.identity?.puede_consultar_prefrio !== true);
    elements.accessesNav.classList.toggle('is-hidden', state.identity?.puede_administrar_accesos !== true);
    return true;
}

function fillCatalogs() {
    const form = elements.receptionForm.elements;
    const quantities = Object.fromEntries(state.catalogs.tipos_envase.map((t) => [t.codigo, form[`cantidad_${t.codigo}`]?.value || '0']));
    byId('standardContainerLines').innerHTML = '<legend>Envases declarados en la guía *</legend>' + state.catalogs.tipos_envase.map((t) => `<label><span>${escapeHtml(t.nombre)}</span><input name="cantidad_${t.codigo}" type="number" min="0" max="100000" value="${quantities[t.codigo]}"></label>`).join('');
    form.tipo_envase_pesaje.innerHTML = state.catalogs.tipos_envase.filter((t) => t.contiene_fruta).map((t) => `<option value="${t.codigo}">${escapeHtml(t.nombre)}</option>`).join('');
    const activeSeasons = state.catalogs.temporadas.filter((season) => season.activa);
    form.temporada_id.innerHTML = '<option value="">Seleccionar temporada activa</option>' + activeSeasons.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(season.nombre)} · ${escapeHtml(season.codigo)}</option>`).join('');
    elements.filters.elements.temporada_id.innerHTML = '<option value="">Temporada activa</option>' + state.catalogs.temporadas.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(season.codigo)}${season.activa ? ' (activa)' : ''}</option>`).join('');
    form.cliente_id.innerHTML = '<option value="">Seleccionar cliente activo</option>' + state.catalogs.clientes.map((client) => `<option value="${escapeHtml(client.id)}">${escapeHtml(client.nombre)}${client.codigo ? ` · ${escapeHtml(client.codigo)}` : ''}</option>`).join('');
    form.tipo_servicio.innerHTML = state.catalogs.tipos_servicio.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
    form.tipo_recepcion.innerHTML = state.catalogs.tipos_recepcion.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
    form.especie_validacion_id.innerHTML = '<option value="">Seleccionar especie</option>' + state.catalogs.especies.map((species) => `<option value="${escapeHtml(species.id)}">${escapeHtml(species.nombre)}</option>`).join('');
    form.concepto_envases.innerHTML = state.catalogs.conceptos_envases.map((type) => `<option value="${escapeHtml(type.codigo)}">${escapeHtml(type.nombre)}</option>`).join('');
}

function filterQuery() {
    const values = Object.fromEntries(new FormData(elements.filters));
    const query = new URLSearchParams({ por_pagina: '30', pagina: String(state.page) });
    Object.entries(values).forEach(([key, value]) => { if (value) query.set(key, value); });
    query.set('page', String(state.page));
    query.delete('pagina');
    return query.toString();
}

function renderList(payload) {
    state.receptions = payload.data; state.meta = payload.meta;
    elements.entryCount.textContent = String(payload.resumen.en_bascula_ingreso);
    elements.containerWeighingCount.textContent = String(payload.resumen.en_pesaje_envases);
    elements.exitCount.textContent = String(payload.resumen.en_bascula_salida);
    elements.closedCount.textContent = String(payload.resumen.cerradas);
    elements.netWeight.textContent = formatWeight(payload.resumen.peso_neto);
    elements.paginationSummary.textContent = `${payload.meta.total} ${payload.meta.total === 1 ? 'recepción' : 'recepciones'} · página ${payload.meta.pagina_actual} de ${payload.meta.ultima_pagina}`;
    elements.previousPage.disabled = payload.meta.pagina_actual <= 1;
    elements.nextPage.disabled = payload.meta.pagina_actual >= payload.meta.ultima_pagina;

    if (!state.receptions.length) {
        elements.tableBody.innerHTML = '<tr class="weighbridge-empty"><td colspan="6">No existen recepciones para los filtros seleccionados.</td></tr>';
        return;
    }

    elements.tableBody.innerHTML = state.receptions.map((reception) => `
        <tr data-reception-id="${escapeHtml(reception.id)}" tabindex="0">
            <td><strong>${escapeHtml(reception.numero_recepcion || 'Ingreso abierto')}</strong><small>${escapeHtml(formatDate(reception.ingreso_at))}</small></td>
            <td><strong>${escapeHtml(reception.cliente.nombre)}</strong><small>Guía ${escapeHtml(reception.numero_guia_despacho)}</small></td>
            <td><strong>${escapeHtml(reception.patente_camion)}</strong><small>${escapeHtml(reception.nombre_conductor)}</small></td>
            <td><strong>${escapeHtml(envasesLabel(reception))}</strong><small>${escapeHtml(label(reception.tipo_recepcion))}</small></td>
            <td class="weight-cell"><strong>${escapeHtml(reception.tipo_recepcion === 'solo_envases' ? 'Sin pesaje' : reception.pesaje_envases ? formatWeight(reception.peso_neto) : formatWeight(reception.peso_neto, formatWeight(reception.peso_bruto)))}</strong><small>${reception.tipo_recepcion === 'solo_envases' ? 'Recepción documental' : reception.pesaje_envases ? `${reception.pesaje_envases.cantidad_pesada}/${reception.pesaje_envases.cantidad_declarada} pesados` : reception.peso_neto === null ? 'Peso bruto' : 'Peso neto'}</small></td>
            <td>${receptionStateBadge(reception)}</td>
        </tr>`).join('');
}

async function loadCatalogs() { const [catalogs, containers] = await Promise.all([api('/api/romana/catalogos'), loadContainerCatalog(api)]); state.catalogs = { ...catalogs, tipos_envase: containers }; fillCatalogs(); }
async function loadReceptions({ silent = false } = {}) {
    const payload = await api(`/api/romana/recepciones?${filterQuery()}`); renderList(payload);
    if (state.selected) {
        const exists = state.receptions.some((item) => item.id === state.selected.id);
        if (exists) await selectReception(state.selected.id, { silent: true });
        else closeDetail();
    }
    if (!silent) toast('Recepciones actualizadas.');
}

function fact(title, value, weight = false) { return `<article class="detail-fact${weight ? ' detail-fact--weight' : ''}"><span>${escapeHtml(title)}</span><strong>${escapeHtml(value ?? '—')}</strong></article>`; }
function envasesLabel(reception) { return (reception.envases || []).map((item) => `${item.cantidad_declarada} ${label(item.tipo_envase)}`).join(' · ') || 'Sin envases'; }
function renderContainerWeighings(reception, canOperate) {
    const weighing = reception.pesaje_envases;
    elements.containerWeighingPanel.classList.toggle('is-hidden', !weighing);
    if (!weighing) {
        elements.containerWeighingList.innerHTML = '';
        return;
    }
    elements.containerWeighingProgress.textContent = `${weighing.cantidad_pesada} / ${weighing.cantidad_declarada} ${label(weighing.tipo_envase)} · faltan ${weighing.cantidad_pendiente}`;
    const readings = reception.lecturas_pesaje_envases || [];
    elements.containerWeighingList.innerHTML = readings.length ? readings.map((reading) => `
        <article class="container-weighing-row${reading.anulado ? ' container-weighing-row--annulled' : ''}">
            <div><span>TANDA</span><strong>#${reading.secuencia}</strong></div>
            <div><span>ENVASES</span><strong>${reading.cantidad_envases} ${escapeHtml(label(reading.tipo_envase))}</strong></div>
            <div><span>BRUTO</span><strong>${escapeHtml(formatWeight(reading.peso_bruto))}</strong></div>
            <div><span>TARA</span><strong>${escapeHtml(formatWeight(reading.peso_tara))}</strong></div>
            <div><span>NETO</span><strong>${escapeHtml(formatWeight(reading.peso_neto))}</strong></div>
            <div><span>${reading.anulado ? 'ANULADO' : 'REGISTRADO'}</span><strong>${escapeHtml(formatDate(reading.anulado_at || reading.pesado_at))}</strong>${reading.motivo_anulacion ? `<small>${escapeHtml(reading.motivo_anulacion)}</small>` : ''}</div>
            ${canOperate && !reading.anulado && reception.puede_registrar_pesaje ? `<button type="button" data-annul-weighing-id="${escapeHtml(reading.id)}">Anular</button>` : '<span></span>'}
        </article>`).join('') : '<p class="weighbridge-empty">Aún no se registran tandas. Puedes comenzar con 1, 3 o cualquier cantidad pendiente.</p>';
}
function renderDetail(reception) {
    state.selected = reception;
    elements.detail.classList.remove('is-hidden');
    elements.detailTitle.textContent = reception.numero_recepcion || `Ingreso · ${reception.patente_camion}`;
    elements.detailSubtitle.innerHTML = `${receptionStateBadge(reception)} · Guía ${escapeHtml(reception.numero_guia_despacho)} · ${escapeHtml(reception.cliente.nombre)}`;
    const weighingFacts = reception.pesaje_envases ? [
        fact('ENVASE PESADO', label(reception.pesaje_envases.tipo_envase)),
        fact('TARA UNITARIA', formatWeight(reception.pesaje_envases.tara_unitaria), true),
        fact('AVANCE', `${reception.pesaje_envases.cantidad_pesada} / ${reception.pesaje_envases.cantidad_declarada}`),
        fact('PENDIENTES', String(reception.pesaje_envases.cantidad_pendiente)),
    ] : [];
    const standardWeightFacts = reception.tipo_recepcion === 'solo_envases' ? [
        fact('PESAJE', 'No aplica · recepción exclusiva de envases'),
    ] : [
        fact(reception.pesaje_envases ? 'BRUTO ACUMULADO' : 'PESO BRUTO', formatWeight(reception.peso_bruto), true),
        fact(reception.pesaje_envases ? 'TARA ACUMULADA' : 'TARA CAMIÓN', formatWeight(reception.peso_tara), true),
        ...(reception.modo_salida_envases && reception.peso_tara_envases !== 0 ? [
            fact('DIFERENCIA DE TARA DE ENVASES', formatWeight(reception.peso_tara_envases), true),
        ] : []),
        fact('PESO NETO', formatWeight(reception.peso_neto), true),
    ];
    const outboundTareFacts = reception.modo_salida_envases ? [
        fact(
            'TARAS UNITARIAS',
            reception.envases
                .map((item) => `${label(item.tipo_envase)} ${formatWeight(item.tara_unitaria_salida)}/u`)
                .join(' · '),
        ),
    ] : [];
    elements.detailFacts.innerHTML = [
        fact('INGRESO', formatDate(reception.ingreso_at)), fact(reception.tipo_recepcion === 'solo_envases' ? 'CIERRE DOCUMENTAL' : reception.pesaje_envases ? 'CIERRE DE PESAJE' : 'SALIDA / DESTARE', formatDate(reception.salida_at)), fact('TEMPORADA GLOBAL', `${reception.temporada.nombre} · ${reception.temporada.codigo}`), fact('CLIENTE', reception.cliente.nombre), fact('TIPO RECEPCIÓN', label(reception.tipo_recepcion)), fact('SERVICIO / CONCEPTO', reception.tipo_recepcion === 'solo_envases' ? label(reception.concepto_envases) : label(reception.tipo_servicio)), fact('GUÍA', reception.numero_guia_despacho),
        ...(reception.destare_pendiente_validacion ? [fact('DESTARE', 'Espera la Validación MP completa en la PDA')] : []),
        ...(reception.modo_salida_envases ? [fact('SALIDA ENVASES', label(reception.modo_salida_envases)), fact('GUÍA DE SALIDA', reception.numero_guia_salida || 'Sin salida'), fact('ENVASES RETIRADOS', (reception.salida_envases || []).filter((x) => x.cantidad > 0).map((x) => `${x.cantidad} ${label(x.tipo_envase)}`).join(' · ') || 'Ninguno')] : []),
        fact('CAMIÓN', reception.patente_camion), fact('TIPO DE CAMIÓN', reception.tipo_camion ? label(reception.tipo_camion) : 'No informado'), fact('CARRO', reception.patente_carro || 'No informado'), fact('CONDUCTOR', reception.nombre_conductor), fact('RUT', reception.rut_conductor), fact('ENVASES DECLARADOS', envasesLabel(reception)), fact('VALIDACIÓN MP', label(reception.estado_validacion_mp)),
        ...weighingFacts,
        ...outboundTareFacts,
        ...standardWeightFacts,
        fact('VERSIÓN', String(reception.version)), fact('OBS. INGRESO', reception.observacion || 'Sin observaciones'), fact('OBS. CIERRE', reception.observacion_cierre || 'Sin observaciones'),
    ].join('');
    elements.detailTimeline.innerHTML = (reception.eventos || []).map((event) => `<article class="timeline-item"><i></i><div><strong>${escapeHtml(label(event.tipo))}</strong><small>${escapeHtml(event.usuario?.nombre || 'Sistema')} · ${escapeHtml(event.estado_anterior ? `${receptionStateLabel(reception, event.estado_anterior)} → ${receptionStateLabel(reception, event.estado_nuevo)}` : receptionStateLabel(reception, event.estado_nuevo))}</small>${event.datos?.motivo ? `<small>Motivo: ${escapeHtml(event.datos.motivo)}</small>` : ''}</div><time>${escapeHtml(formatDate(event.ocurrido_at))}</time></article>`).join('');
    elements.weightBalance.innerHTML = reception.tipo_recepcion === 'solo_envases'
        ? '<div class="net-row"><span>Recepción documental</span><strong>Sin registro de kilos</strong></div>'
        : `<div><span>Bruto${reception.pesaje_envases ? ' acumulado' : ''}</span><strong>${escapeHtml(formatWeight(reception.peso_bruto))}</strong></div><div><span>Tara ${reception.pesaje_envases ? 'acumulada' : 'camión'}</span><strong>${escapeHtml(formatWeight(reception.peso_tara))}</strong></div>${reception.modo_salida_envases && reception.peso_tara_envases !== 0 ? `<div><span>Diferencia tara envases</span><strong>${escapeHtml(formatWeight(reception.peso_tara_envases))}</strong></div>` : ''}${reception.pesaje_envases ? `<div><span>Neto promedio por envase</span><strong>${escapeHtml(formatWeight(reception.peso_neto_por_envase))}</strong></div>` : ''}<div class="net-row"><span>Neto legal</span><strong>${escapeHtml(formatWeight(reception.peso_neto))}</strong></div>`;
    const canOperate = state.identity?.puede_operar_romana === true;
    const canCorrect = state.identity?.puede_corregir_recepciones_romana === true
        && reception.correccion_administrativa_disponible
        && !reception.puede_editar;
    elements.editReception.textContent = canCorrect ? 'Corregir recepción' : 'Editar ingreso';
    elements.editReception.classList.toggle('is-hidden', (!canOperate || !reception.puede_editar) && !canCorrect);
    elements.confirmEntry.classList.toggle('is-hidden', !canOperate || !reception.puede_confirmar_ingreso);
    elements.addContainerWeighing.classList.toggle('is-hidden', !canOperate || !reception.puede_registrar_pesaje);
    elements.closeReception.textContent = reception.tipo_recepcion === 'solo_envases'
        ? 'Cerrar recepción de envases'
        : reception.pesaje_envases ? 'Cerrar pesaje acumulativo' : 'Registrar destare y cerrar';
    elements.closeReception.classList.toggle('is-hidden', !canOperate || !reception.puede_cerrar);
    elements.correctExit.classList.toggle('is-hidden', !state.identity?.puede_corregir_recepciones_romana || !reception.puede_corregir_salida);
    elements.downloadReceipt.classList.toggle('is-hidden', !reception.aviso_recibo_disponible);
    elements.rc02Reception.classList.toggle('is-hidden', !reception.rc02_recepcion_disponible);
    elements.rc02Dispatch.classList.toggle('is-hidden', !reception.rc02_despacho_disponible);
    renderContainerWeighings(reception, canOperate);
    elements.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function selectReception(id, { silent = false } = {}) {
    if (!silent) setBusy(true, 'Abriendo expediente de recepción…');
    try { const payload = await api(`/api/romana/recepciones/${id}`); renderDetail(payload.data); }
    catch (error) { toast(error.message, true); }
    finally { if (!silent) setBusy(false); }
}
function closeDetail() { state.selected = null; elements.detail.classList.add('is-hidden'); }

function configureAdministrativeCorrection(enabled) {
    const closedCorrection = enabled
        && state.selected?.estado === 'cerrado'
        && state.selected?.tipo_recepcion !== 'solo_envases';
    state.administrativeCorrection = enabled;
    elements.administrativeCorrectionField.classList.toggle('is-hidden', !enabled);
    elements.administrativeTareField.classList.toggle('is-hidden', !closedCorrection);
    elements.administrativeNetContainerField.classList.toggle('is-hidden', !closedCorrection);
    elements.receptionForm.elements.motivo_correccion.required = enabled;
    elements.receptionForm.elements.peso_tara.required = closedCorrection;
    elements.receptionForm.elements.peso_tara.disabled = !closedCorrection;
    elements.receptionForm.elements.tipo_envase_calculo_neto.required = closedCorrection;
    elements.receptionForm.elements.tipo_envase_calculo_neto.disabled = !closedCorrection;
    elements.saveReception.textContent = enabled ? 'Guardar corrección' : 'Guardar ingreso';
    if (!enabled) elements.receptionForm.elements.motivo_correccion.value = '';
}

function syncAdministrativeNetContainerOptions(preferredType = null) {
    if (!state.administrativeCorrection || state.selected?.estado !== 'cerrado') return;
    const form = elements.receptionForm.elements;
    const select = form.tipo_envase_calculo_neto;
    const selected = preferredType || select.value || state.selected.tipo_envase_calculo_neto;
    const available = state.catalogs.tipos_envase.filter((t) => t.contiene_fruta).map((t) => t.codigo)
        .map((type) => ({ type, quantity: Number(form[`cantidad_${type}`].value || 0) }))
        .filter((item) => item.quantity > 0);
    select.innerHTML = available.map((item) => `<option value="${escapeHtml(item.type)}">${escapeHtml(label(item.type))} · ${item.quantity} declarados</option>`).join('');
    if (available.some((item) => item.type === selected)) select.value = selected;
}

function openNewReception() {
    elements.receptionForm.reset(); elements.receptionForm.elements.recepcion_id.value = ''; elements.receptionFormError.textContent = '';
    configureAdministrativeCorrection(false);
    elements.receptionDialogTitle.textContent = 'Registrar ingreso';
    const activeSeasons = state.catalogs.temporadas.filter((season) => season.activa);
    if (!activeSeasons.length) {
        toast('No existe una temporada global activa para recibir.', true); return;
    }
    if (!state.catalogs.clientes.length) {
        toast('No existen clientes operacionales activos para recibir.', true); return;
    }
    if (activeSeasons.length === 1) elements.receptionForm.elements.temporada_id.value = activeSeasons[0].id;
    toggleReceptionType();
    elements.receptionDialog.showModal();
}
function openEditReception() {
    const canCorrect = state.identity?.puede_corregir_recepciones_romana === true
        && state.selected?.correccion_administrativa_disponible
        && !state.selected?.puede_editar;
    if (!state.selected?.puede_editar && !canCorrect) return;
    const reception = state.selected; const form = elements.receptionForm.elements;
    elements.receptionForm.reset(); elements.receptionFormError.textContent = '';
    configureAdministrativeCorrection(canCorrect);
    elements.receptionDialogTitle.textContent = canCorrect ? 'Corregir recepción' : 'Editar pesaje de ingreso';
    form.recepcion_id.value = reception.id; form.temporada_id.value = reception.temporada.id; form.cliente_id.value = reception.cliente.id; form.tipo_recepcion.value = reception.tipo_recepcion; form.especie_validacion_id.value = reception.especie_validacion_id || ''; form.fecha_ingreso.value = reception.fecha_ingreso || ''; form.concepto_envases.value = reception.concepto_envases || ''; form.tipo_servicio.value = reception.tipo_servicio;
    form.numero_guia_despacho.value = reception.numero_guia_despacho;
    state.catalogs.tipos_envase.map((t) => t.codigo).forEach((tipo) => { const item = reception.envases.find((envase) => envase.tipo_envase === tipo); form[`cantidad_${tipo}`].value = item?.cantidad_declarada || 0; });
    if (reception.pesaje_envases) {
        form.tipo_envase_pesaje.value = reception.pesaje_envases.tipo_envase;
        form.cantidad_envases_pesaje.value = reception.pesaje_envases.cantidad_declarada;
        form.tara_unitaria_envase.value = reception.pesaje_envases.tara_unitaria;
    }
    form.patente_camion.value = reception.patente_camion; form.tipo_camion.value = reception.tipo_camion || ''; form.patente_carro.value = reception.patente_carro || ''; form.rut_conductor.value = reception.rut_conductor; form.nombre_conductor.value = reception.nombre_conductor;
    form.peso_bruto.value = reception.peso_bruto ?? ''; form.observacion.value = reception.observacion || '';
    if (canCorrect && reception.estado === 'cerrado') {
        form.peso_tara.value = reception.peso_tara;
        syncAdministrativeNetContainerOptions(reception.tipo_envase_calculo_neto);
        form.tipo_envase_calculo_neto.value = reception.tipo_envase_calculo_neto;
    }
    toggleReceptionType();
    elements.receptionDialog.showModal();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso a Romana…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
        state.token = payload.token;
        if (payload.usuario.puede_consultar_romana !== true) {
            await api('/api/acceso-oficina', { method: 'DELETE' }).catch(() => {}); state.token = null;
            throw new ApiError('Tu perfil no posee acceso al módulo de Romana.', 403);
        }
        persist(payload); showApp(); setBusy(true, 'Cargando operación de Romana…'); await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); startRefresh();
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});

elements.receptionForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault(); elements.receptionFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.receptionForm)); const id = data.recepcion_id; delete data.recepcion_id; data.operacion_id = operationUuid();
    if (!data.tipo_camion) {
        elements.receptionFormError.textContent = 'Selecciona el tipo de camión.';
        elements.receptionForm.elements.tipo_camion.focus();
        return;
    }
    const soloEnvases = data.tipo_recepcion === 'solo_envases';
    const cumulativeWeighing = data.tipo_recepcion === 'fruta_pesaje_envases';
    data.envases = cumulativeWeighing
        ? [{ tipo_envase: data.tipo_envase_pesaje, cantidad: Number(data.cantidad_envases_pesaje || 0) }]
        : state.catalogs.tipos_envase.map((t) => t.codigo).map((tipo) => ({ tipo_envase: tipo, cantidad: Number(data[`cantidad_${tipo}`] || 0) })).filter((item) => item.cantidad > 0);
    state.catalogs.tipos_envase.map((t) => t.codigo).forEach((tipo) => delete data[`cantidad_${tipo}`]);
    delete data.cantidad_envases_pesaje;
    if (cumulativeWeighing || soloEnvases) delete data.peso_bruto;
    else {
        delete data.tipo_envase_pesaje;
        delete data.tara_unitaria_envase;
    }
    const administrativeCorrection = Boolean(id && state.administrativeCorrection);
    if (administrativeCorrection) data.version_conocida = state.selected?.version;
    else delete data.motivo_correccion;
    setBusy(true, administrativeCorrection ? 'Aplicando corrección administrativa…' : id ? 'Actualizando ingreso…' : soloEnvases ? 'Registrando recepción documental…' : 'Registrando pesaje de ingreso…');
    try {
        const path = administrativeCorrection
            ? `/api/romana/recepciones/${id}/corregir`
            : id ? `/api/romana/recepciones/${id}` : '/api/romana/recepciones';
        const payload = await api(path, { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
        elements.receptionDialog.close(); await loadReceptions({ silent: true }); await selectReception(payload.data.id, { silent: true });
        toast(administrativeCorrection ? 'Recepción corregida con trazabilidad.' : id ? 'Ingreso actualizado.' : soloEnvases ? 'Recepción de envases registrada sin kilos.' : 'Pesaje de ingreso registrado.');
    } catch (error) { elements.receptionFormError.textContent = error.message; }
    finally { setBusy(false); }
});

elements.confirmEntry.addEventListener('click', async () => {
    if (!state.selected || !window.confirm(`¿Confirmar el ingreso de ${state.selected.patente_camion}? Después de esta acción solo un administrador podrá corregirlo mientras Validación MP no tome la recepción.`)) return;
    const soloEnvases = state.selected.tipo_recepcion === 'solo_envases';
    setBusy(true, soloEnvases ? 'Confirmando recepción documental…' : 'Confirmando ingreso y liberando camión…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/confirmar-ingreso`, { method: 'POST', body: JSON.stringify({ operacion_id: operationUuid() }) });
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast(soloEnvases
            ? 'Ingreso documental confirmado. La recepción está lista para cerrar.'
            : 'Ingreso confirmado. El camión quedó pendiente de destare.');
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.addContainerWeighing.addEventListener('click', () => {
    const weighing = state.selected?.pesaje_envases;
    if (!weighing || !state.selected?.puede_registrar_pesaje || weighing.cantidad_pendiente < 1) return;
    elements.containerWeighingForm.reset();
    elements.containerWeighingFormError.textContent = '';
    elements.containerWeighingTarePreview.textContent = '—';
    elements.containerWeighingNetPreview.textContent = '—';
    const quantity = elements.containerWeighingForm.elements.cantidad_envases;
    quantity.max = String(weighing.cantidad_pendiente);
    quantity.value = String(Math.min(3, weighing.cantidad_pendiente));
    elements.containerWeighingDescription.textContent = `${weighing.cantidad_pesada}/${weighing.cantidad_declarada} ${label(weighing.tipo_envase)} pesados · faltan ${weighing.cantidad_pendiente} · tara ${formatWeight(weighing.tara_unitaria)} por unidad.`;
    elements.containerWeighingDialog.showModal();
    quantity.focus();
    updateContainerWeighingPreviews();
});

function updateContainerWeighingPreviews() {
    const weighing = state.selected?.pesaje_envases;
    const quantity = Number(elements.containerWeighingForm.elements.cantidad_envases.value || 0);
    const gross = Number(elements.containerWeighingForm.elements.peso_bruto.value || 0);
    const tare = Number(weighing?.tara_unitaria || 0) * quantity;
    elements.containerWeighingTarePreview.textContent = quantity > 0 ? formatWeight(tare) : '—';
    elements.containerWeighingNetPreview.textContent = gross > tare ? formatWeight(gross - tare) : '—';
}
elements.containerWeighingForm.elements.cantidad_envases.addEventListener('input', updateContainerWeighingPreviews);
elements.containerWeighingForm.elements.peso_bruto.addEventListener('input', updateContainerWeighingPreviews);
elements.containerWeighingForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault();
    if (!state.selected) return;
    elements.containerWeighingFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.containerWeighingForm));
    data.operacion_id = operationUuid();
    setBusy(true, 'Registrando tanda y recalculando el peso neto…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/pesajes-envases`, { method: 'POST', body: JSON.stringify(data) });
        elements.containerWeighingDialog.close();
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast(`Tanda registrada: ${data.cantidad_envases} envase(s).`);
    } catch (error) { elements.containerWeighingFormError.textContent = error.message; }
    finally { setBusy(false); }
});

elements.containerWeighingList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-annul-weighing-id]');
    if (!button || !state.selected) return;
    const reason = window.prompt('Indica el motivo de la anulación de esta tanda:');
    if (reason === null) return;
    if (reason.trim().length < 5) {
        toast('El motivo debe contener al menos 5 caracteres.', true);
        return;
    }
    setBusy(true, 'Anulando lectura y recalculando acumulados…');
    try {
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/pesajes-envases/${button.dataset.annulWeighingId}/anular`, {
            method: 'POST',
            body: JSON.stringify({ operacion_id: operationUuid(), motivo: reason.trim() }),
        });
        await loadReceptions({ silent: true });
        renderDetail(payload.data);
        toast('Lectura anulada con trazabilidad.');
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.closeReception.addEventListener('click', async () => {
    if (!state.selected?.puede_cerrar) return;
    if (state.selected.pesaje_envases) {
        if (!window.confirm(`¿Cerrar el pesaje de ${state.selected.pesaje_envases.cantidad_declarada} ${label(state.selected.pesaje_envases.tipo_envase)}? Después del cierre no se podrán agregar ni anular tandas.`)) return;
        setBusy(true, 'Cerrando pesaje acumulativo…');
        try {
            const payload = await api(`/api/romana/recepciones/${state.selected.id}/cerrar`, {
                method: 'POST',
                body: JSON.stringify({ operacion_id: operationUuid() }),
            });
            await loadReceptions({ silent: true });
            renderDetail(payload.data);
            toast(`${payload.data.numero_recepcion} cerrada con el total pesado.`);
        } catch (error) { toast(error.message, true); }
        finally { setBusy(false); }
        return;
    }
    if (state.selected.tipo_recepcion === 'solo_envases') {
        if (!window.confirm(`¿Cerrar la recepción documental ${state.selected.numero_recepcion}? No se registrarán kilos para este ingreso de envases.`)) return;
        setBusy(true, 'Cerrando recepción documental de envases…');
        try {
            const payload = await api(`/api/romana/recepciones/${state.selected.id}/cerrar`, {
                method: 'POST',
                body: JSON.stringify({ operacion_id: operationUuid(), salida_sin_envases: false }),
            });
            await loadReceptions({ silent: true });
            renderDetail(payload.data);
            toast(`${payload.data.numero_recepcion} cerrada sin registro de kilos.`);
        } catch (error) { toast(error.message, true); }
        finally { setBusy(false); }
        return;
    }
    openTareDialog(false);
});

function openTareDialog(correcting) {
    state.tareCorrection = correcting;
    elements.inspectionItems.replaceChildren();
    elements.inspectionObservation.value = correcting ? (state.selected.inspecciones_envases?.find((i) => i.tipo === 'despacho')?.observacion || '') : '';
    elements.tareForm.reset(); elements.tareFormError.textContent = ''; elements.containerTarePreview.textContent = '—'; elements.netWeightPreview.textContent = '—'; elements.netPerContainerPreview.textContent = '—';
    const containerSelect = elements.tareForm.elements.tipo_envase_calculo_neto;
    containerSelect.innerHTML = state.selected.envases.filter((item) => item.cantidad_validada > 0 && state.catalogs.tipos_envase.find((t) => t.codigo === item.tipo_envase)?.contiene_fruta).map((item) => `<option value="${escapeHtml(item.tipo_envase)}">${escapeHtml(label(item.tipo_envase))} · ${item.cantidad_validada} validados</option>`).join('');
    elements.outboundContainerTareList.innerHTML = state.selected.envases.map((item) => exitRow(item.tipo_envase, item.cantidad_validada, item.tara_unitaria_salida)).join('');
    elements.tareForm.elements.peso_tara.value = correcting ? state.selected.peso_tara : '';
    elements.tareForm.elements.peso_tara.readOnly = correcting;
    elements.tareForm.elements.tipo_envase_calculo_neto.disabled = correcting;
    const suggested = state.selected.configuracion_reparto?.sugerido;
    const selected = correcting ? (state.selected.reparto_neto_envases?.map((r) => r.tipo_envase) || [state.selected.tipo_envase_calculo_neto]) : [suggested || containerSelect.options[0]?.value];
    [...containerSelect.options].forEach((option) => { option.selected = selected.includes(option.value); });
    if (![...containerSelect.options].some((o) => o.selected) && containerSelect.options[0]) containerSelect.options[0].selected = true;
    if (correcting) {
        elements.tareForm.elements.modo_salida_envases.value = state.selected.modo_salida_envases;
        elements.tareForm.elements.numero_guia_salida.value = state.selected.numero_guia_salida || '';
        (state.selected.salida_envases || []).forEach((item) => {
            let row = exitTypes().find((r) => r.dataset.exitRow === item.tipo_envase);
            if (!row) {
                elements.outboundContainerTareList.insertAdjacentHTML('beforeend', exitRow(item.tipo_envase, 0, item.tara_unitaria, true));
                row = exitTypes().find((r) => r.dataset.exitRow === item.tipo_envase);
            }
            row.querySelector('[data-exit-quantity]').value = item.cantidad;
            row.querySelector('[data-container-tare]').value = item.tara_unitaria;
        });
    }
    elements.exitCorrectionReasonField.classList.toggle('is-hidden', !correcting);
    elements.tareForm.elements.motivo_correccion.required = correcting;
    elements.tareDescription.textContent = `${state.selected.patente_camion} · bruto ${formatWeight(state.selected.peso_bruto)}. Completa la guía y el retiro físico de envases.`;
    updateExitMode();
    elements.tareDialog.showModal();
    if (!correcting) elements.tareForm.elements.peso_tara.focus();
}
elements.correctExit.addEventListener('click', () => openTareDialog(true));

function exitRow(type, validated = 0, tare = '', extra = false) {
    return `<div data-exit-row="${escapeHtml(type)}" data-validated="${validated}">
        <label class="field"><span>${escapeHtml(label(type))} · entraron ${validated} validados</span><input data-exit-quantity="${escapeHtml(type)}" type="number" min="0" max="100000" step="1" value="${validated}" required></label>
        <label class="field"><span>Tara unitaria (kg) *</span><input data-container-tare="${escapeHtml(type)}" type="number" min="0.001" max="1000" step="0.001" inputmode="decimal" value="${escapeHtml(tare ?? '')}" required></label>
        ${extra ? '<button type="button" class="secondary-button" data-remove-exit-type>Quitar tipo</button>' : ''}
    </div>`;
}

function exitTypes() {
    return [...elements.outboundContainerTareList.querySelectorAll('[data-exit-row]')];
}

function updateExtraTypes() {
    const used = new Set(exitTypes().map((row) => row.dataset.exitRow));
    const available = state.catalogs.tipos_envase.filter((item) => !used.has(item.codigo));
    elements.outboundExtraType.innerHTML = available.map((item) => `<option value="${escapeHtml(item.codigo)}">${escapeHtml(item.nombre)}</option>`).join('');
    elements.outboundAddType.disabled = !available.length;
}

function updateExitMode() {
    const mode = elements.tareForm.elements.modo_salida_envases.value;
    const editable = mode === 'diferentes';
    elements.outboundGuideField.classList.toggle('is-hidden', mode === 'vacio');
    elements.tareForm.elements.numero_guia_salida.required = mode !== 'vacio';
    elements.outboundExtraField.classList.toggle('is-hidden', !editable);
    exitTypes().forEach((row) => {
        const field = row.querySelector('[data-exit-quantity]');
        if (mode === 'mismos') field.value = row.dataset.validated;
        if (mode === 'vacio') field.value = '0';
        field.readOnly = !editable;
        if (!editable && Number(row.dataset.validated) === 0) row.remove();
    });
    updateExtraTypes();
    updateNetPreviews();
}

function calculatedContainerTare() {
    return exitTypes().reduce((total, row) => {
        const tare = Number(row.querySelector('[data-container-tare]').value || 0);
        const outbound = elements.tareForm.elements.modo_salida_envases.value === 'vacio'
            ? 0 : Number(row.querySelector('[data-exit-quantity]').value || 0);
        return total + (Number(row.dataset.validated) - outbound) * tare;
    }, 0);
}

function updateDispatchInspection() {
    const empty = elements.tareForm.elements.modo_salida_envases.value === 'vacio';
    elements.inspectionPanel.classList.toggle('is-hidden', empty);
    const types = empty ? [] : exitTypes().filter((row) => Number(row.querySelector('[data-exit-quantity]').value) > 0).map((row) => row.dataset.exitRow);
    [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].forEach((row) => { if (!types.includes(row.dataset.inspectionType)) row.remove(); });
    types.forEach((type) => {
        if ([...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].some((row) => row.dataset.inspectionType === type)) return;
        const previous = state.tareCorrection ? state.selected.inspecciones_envases?.find((i) => i.tipo === 'despacho')?.items.find((i) => i.tipo_envase === type) : null;
        elements.inspectionItems.insertAdjacentHTML('beforeend', `<div data-inspection-type="${escapeHtml(type)}"><strong>${escapeHtml(label(type))}</strong><label class="field"><span>Limpieza *</span><select data-clean required><option value="">Seleccionar</option><option value="si">Sí</option><option value="no">No</option></select></label><label class="field"><span>Condición *</span><select data-condition required><option value="">Seleccionar</option><option value="buena">Buena</option><option value="regular">Regular</option><option value="mala">Mala</option></select></label><label class="field"><span>Nota opcional</span><input data-note maxlength="500"></label></div>`);
        const row = [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')].find((r) => r.dataset.inspectionType === type);
        row.querySelector('[data-clean]').value = previous ? (previous.limpieza ? 'si' : 'no') : '';
        row.querySelector('[data-condition]').value = previous?.condicion || '';
        row.querySelector('[data-note]').value = previous?.nota || '';
    });
}
function updateNetPreviews() {
    updateDispatchInspection();
    const tare = Number(elements.tareForm.elements.peso_tara.value);
    const difference = calculatedContainerTare();
    const gross = Number(state.selected?.peso_bruto || 0);
    const net = gross - tare - difference;
    elements.containerTarePreview.textContent = formatWeight(difference);
    elements.netWeightPreview.textContent = tare > 0 && net > 0 ? formatWeight(net) : '—';
    try {
        const selection = [...elements.tareForm.elements.tipo_envase_calculo_neto.selectedOptions].map((o) => o.value);
        const quantities = Object.fromEntries(state.selected.envases.map((i) => [i.tipo_envase, i.cantidad_validada]));
        const references = state.tareCorrection && state.selected.reparto_neto_envases
            ? Object.fromEntries(state.selected.reparto_neto_envases.map((r) => [r.tipo_envase, r.peso_referencia]))
            : state.selected.configuracion_reparto?.referencias || {};
        const rows = calculateNetAllocation(net, quantities, selection, references);
        elements.netPerContainerPreview.textContent = tare > 0 && net > 0 ? rows.map((r) => `${formatWeight(r.neto_unitario)} / ${label(r.codigo)}`).join(' · ') + (rows.length > 1 ? '. Reparto proporcional a referencias; el neto total proviene de la báscula.' : '') : '—';
    } catch (error) { elements.netPerContainerPreview.textContent = error.message; }

}
elements.tareForm.elements.peso_tara.addEventListener('input', updateNetPreviews);
elements.tareForm.elements.tipo_envase_calculo_neto.addEventListener('change', updateNetPreviews);
elements.tareForm.elements.modo_salida_envases.addEventListener('change', updateExitMode);
elements.outboundContainerTareList.addEventListener('input', updateNetPreviews);
elements.outboundContainerTareList.addEventListener('click', (event) => {
    if (!event.target.closest('[data-remove-exit-type]')) return;
    event.target.closest('[data-exit-row]').remove(); updateExtraTypes(); updateNetPreviews();
});
elements.outboundAddType.addEventListener('click', () => {
    const type = elements.outboundExtraType.value;
    if (!type) return;
    elements.outboundContainerTareList.insertAdjacentHTML('beforeend', exitRow(type, 0, '', true));
    updateExtraTypes(); updateNetPreviews();
});
elements.tareForm.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'cancel') return;
    event.preventDefault(); if (!state.selected) return; elements.tareFormError.textContent = '';
    const data = Object.fromEntries(new FormData(elements.tareForm));
    data.operacion_id = operationUuid();
    data.envases_reparto = [...elements.tareForm.elements.tipo_envase_calculo_neto.selectedOptions].map((o) => o.value);
    delete data.tipo_envase_calculo_neto;
    data.salida_envases = data.modo_salida_envases === 'diferentes' ? exitTypes().map((row) => ({
        tipo_envase: row.dataset.exitRow, cantidad: Number(row.querySelector('[data-exit-quantity]').value),
    })) : [];
    data.taras_envases = exitTypes().map((row) => ({
        tipo_envase: row.dataset.exitRow, tara_unitaria: Number(row.querySelector('[data-container-tare]').value),
    }));
    if (data.modo_salida_envases === 'vacio') data.numero_guia_salida = null;
    if (state.tareCorrection) data.version_conocida = state.selected.version;
    if (data.modo_salida_envases !== 'vacio') {
        try {
            const rows = [...elements.inspectionItems.querySelectorAll('[data-inspection-type]')];
            data.inspeccion_envases = dispatchInspectionPayload(rows.map((r) => r.dataset.inspectionType), Object.fromEntries(rows.map((r) => [r.dataset.inspectionType, { limpieza: r.querySelector('[data-clean]').value, condicion: r.querySelector('[data-condition]').value, nota: r.querySelector('[data-note]').value }])), elements.inspectionObservation.value);
        } catch (error) { elements.tareFormError.textContent = error.message; return; }
    }
    setBusy(true, state.tareCorrection ? 'Corrigiendo salida de envases…' : 'Calculando neto y cerrando recepción…');
    try {
        const path = state.tareCorrection ? 'corregir-salida-envases' : 'cerrar';
        const payload = await api(`/api/romana/recepciones/${state.selected.id}/${path}`, { method: 'POST', body: JSON.stringify(data) });
        elements.tareDialog.close(); await loadReceptions({ silent: true }); renderDetail(payload.data);
        toast(`${payload.data.numero_recepcion} ${state.tareCorrection ? 'salida corregida con reversas.' : 'cerrada correctamente.'}`);
    } catch (error) { elements.tareFormError.textContent = error.message; }
    finally { setBusy(false); }
});

async function downloadRc02(type) {
    if (!state.selected) return;
    setBusy(true, 'Generando RC-02…');
    try {
        const response = await fetch(`/api/romana/recepciones/${state.selected.id}/control-envases/${type}`, { headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` } });
        if (!response.ok) { const detail = await response.json().catch(() => ({})); throw new Error(detail.message || 'No fue posible generar RC-02.'); }
        const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `rc02-${type}-${state.selected.numero_recepcion.toLowerCase()}.pdf`; anchor.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, 'error'); }
    finally { setBusy(false); }
}
elements.rc02Reception.addEventListener('click', () => downloadRc02('recepcion'));
elements.rc02Dispatch.addEventListener('click', () => downloadRc02('despacho'));

elements.downloadReceipt.addEventListener('click', async () => {
    if (!state.selected?.aviso_recibo_disponible) return; setBusy(true, 'Generando registro de pesaje (RPR-01)…');
    try {
        const response = await fetch(`/api/romana/recepciones/${state.selected.id}/aviso-recibo`, { headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` } });
        if (!response.ok) { const data = await response.json().catch(() => ({})); throw new ApiError(errorMessage(data, 'No fue posible generar el PDF.'), response.status); }
        const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `aviso-recibo-${state.selected.numero_recepcion.toLowerCase()}.pdf`; anchor.click(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});

elements.blankWeighingForm.addEventListener('click', async () => {
    setBusy(true, 'Generando planilla de pesaje en blanco…');
    try {
        const response = await fetch('/api/romana/registro-pesaje/en-blanco', {
            headers: { Accept: 'application/pdf', Authorization: `Bearer ${state.token}` },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new ApiError(errorMessage(data, 'No fue posible generar la planilla en blanco.'), response.status);
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'registro-pesaje-romana-en-blanco.pdf';
        anchor.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
});

elements.tableBody.addEventListener('click', (event) => { const row = event.target.closest('[data-reception-id]'); if (row) void selectReception(row.dataset.receptionId); });
elements.tableBody.addEventListener('keydown', (event) => { const row = event.target.closest('[data-reception-id]'); if (row && ['Enter', ' '].includes(event.key)) { event.preventDefault(); void selectReception(row.dataset.receptionId); } });
elements.filters.addEventListener('submit', async (event) => { event.preventDefault(); state.page = 1; setBusy(true, 'Aplicando filtros…'); try { await loadReceptions({ silent: true }); } catch (error) { toast(error.message, true); } finally { setBusy(false); } });
elements.previousPage.addEventListener('click', async () => { if (state.page <= 1) return; state.page--; await loadReceptions({ silent: true }); });
elements.nextPage.addEventListener('click', async () => { if (state.meta && state.page >= state.meta.ultima_pagina) return; state.page++; await loadReceptions({ silent: true }); });
elements.reload.addEventListener('click', async () => { setBusy(true, 'Actualizando Romana…'); try { await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); toast('Romana actualizada.'); } catch (error) { toast(error.message, true); } finally { setBusy(false); } });
elements.newReception.addEventListener('click', openNewReception); elements.editReception.addEventListener('click', openEditReception); elements.closeDetail.addEventListener('click', closeDetail);
function toggleReceptionType() {
    const form = elements.receptionForm.elements;
    const soloEnvases = form.tipo_recepcion.value === 'solo_envases';
    const cumulativeWeighing = form.tipo_recepcion.value === 'fruta_pesaje_envases';
    elements.serviceField.classList.toggle('is-hidden', soloEnvases);
    document.getElementById('receptionSpeciesField').classList.toggle('is-hidden', soloEnvases);
    form.especie_validacion_id.required = !soloEnvases;
    elements.containerEntryDateField.classList.toggle('is-hidden', !soloEnvases);
    elements.containerConceptField.classList.toggle('is-hidden', !soloEnvases);
    elements.standardContainerLines.classList.toggle('is-hidden', cumulativeWeighing);
    elements.containerWeighingFields.classList.toggle('is-hidden', !cumulativeWeighing);
    elements.grossWeightField.classList.toggle('is-hidden', cumulativeWeighing || soloEnvases);
    form.tipo_servicio.required = !soloEnvases;
    form.fecha_ingreso.disabled = !soloEnvases;
    form.fecha_ingreso.required = soloEnvases;
    if (soloEnvases && !form.fecha_ingreso.value) form.fecha_ingreso.value = form.fecha_ingreso.max;
    form.concepto_envases.required = soloEnvases;
    form.peso_bruto.required = !cumulativeWeighing && !soloEnvases;
    form.tipo_envase_pesaje.required = cumulativeWeighing;
    form.cantidad_envases_pesaje.required = cumulativeWeighing;
    form.tara_unitaria_envase.required = cumulativeWeighing;
    elements.receptionDialogDescription.textContent = soloEnvases
        ? 'Selecciona la fecha efectiva y registra guía, cliente, transporte y cantidades. Esta recepción no utiliza kilos.'
        : cumulativeWeighing
            ? 'Configura el envase y registra después sus pesajes por tandas.'
            : 'Captura los antecedentes documentales y el peso del camión cargado.';
    if (!state.administrativeCorrection) {
        elements.saveReception.textContent = form.recepcion_id.value
            ? 'Guardar cambios'
            : soloEnvases ? 'Guardar recepción de envases' : 'Guardar pesaje de ingreso';
    }
}
elements.receptionForm.elements.tipo_recepcion.addEventListener('change', toggleReceptionType);
byId('standardContainerLines').addEventListener('input', () => syncAdministrativeNetContainerOptions());
['patente_camion', 'patente_carro'].forEach((name) => elements.receptionForm.elements[name].addEventListener('input', (event) => { event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); }));
elements.logout.addEventListener('click', async () => { try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); } });

function startRefresh() {
    state.poller?.stop();
    state.poller = createOperationalPoller(
        () => loadReceptions({ silent: true }),
        {
            intervalMs: 30000,
            canRun: () => Boolean(state.token)
                && !elements.receptionDialog.open
                && !elements.tareDialog.open
                && !elements.containerWeighingDialog.open,
        },
    );
    state.poller.start();
}

async function boot() {
    if (!state.token || state.identity?.puede_consultar_romana !== true) { if (state.token) clearSession(); return; }
    if (!showApp()) return; setBusy(true, 'Cargando operación de Romana…');
    try { await Promise.all([loadCatalogs(), loadReceptions({ silent: true })]); startRefresh(); }
    catch (error) { if (error.status !== 401) toast(error.message, true); }
    finally { setBusy(false); }
}

void boot();
