import {
    addDays,
    alertInfo,
    clientsOf,
    countText,
    documentDetail,
    documentFormProblem,
    documentStateLabel,
    filterRows,
    formatBytes,
    formatDay,
    formatMoment,
    groupDocuments,
    historyText,
    managementLabel,
    missingText,
    progressPercent,
    progressText,
    relativeDay,
    requirementNote,
    transportLabel,
} from './shared/comex-logic';

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
    range: byId('filesRange'),
    from: byId('filesFrom'),
    to: byId('filesTo'),
    status: byId('filesStatus'),
    setup: byId('filesSetup'),
    setupText: byId('filesSetupText'),
    setupActions: byId('filesSetupActions'),
    kpis: byId('filesKpis'),
    filters: byId('filesFilters'),
    search: byId('filesSearch'),
    client: byId('filesClient'),
    alert: byId('filesAlert'),
    all: byId('filesAll'),
    list: byId('filesList'),
    detail: byId('fileDetail'),
    detailEyebrow: byId('fileDetailEyebrow'),
    detailTitle: byId('fileDetailTitle'),
    detailSubtitle: byId('fileDetailSubtitle'),
    detailBody: byId('fileDetailBody'),
    packing: byId('fileDetailPacking'),
    documentDialog: byId('documentDialog'),
    documentForm: byId('documentForm'),
    documentEyebrow: byId('documentEyebrow'),
    documentTitle: byId('documentTitle'),
    documentDescription: byId('documentDescription'),
    documentNumber: byId('documentNumber'),
    documentNumberLabel: byId('documentNumberLabel'),
    documentDate: byId('documentDate'),
    documentDateLabel: byId('documentDateLabel'),
    documentReference: byId('documentReference'),
    documentFile: byId('documentFile'),
    documentFileLabel: byId('documentFileLabel'),
    documentCurrentFile: byId('documentCurrentFile'),
    documentReasonField: byId('documentReasonField'),
    documentReason: byId('documentReason'),
    documentNote: byId('documentNote'),
    documentMessage: byId('documentMessage'),
    documentSubmit: byId('documentSubmit'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity', filters: 'folios_comex_expedientes_filtros' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    detail: null,
    editing: null,
    // Respuestas que llegan tarde (otro rango u otro embarque) no pisan las nuevas.
    loads: 0,
    detailLoads: 0,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function busy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, failed = false) {
    const node = document.createElement('div');
    node.className = `toast${failed ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 6000);
}

function clearSession() {
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    location.reload();
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        return { ok: false, status: 0, data: { message: 'No fue posible conectar con el servidor.' } };
    }
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) clearSession();
    if (response.status === 413) data.message = 'El respaldo es demasiado grande para subirlo.';
    if (response.status === 429) data.message = 'Demasiadas solicitudes seguidas. Espere un minuto e intente de nuevo.';

    return { ok: response.ok, status: response.status, data };
}

function errorText(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

function can(permission) {
    return (state.detail?.permisos || state.data?.permisos)?.[permission] === true;
}

function today() {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit' })
        .formatToParts(new Date()).map((part) => [part.type, part.value]));

    return `${parts.year}-${parts.month}-${parts.day}`;
}

function saveFilters() {
    try {
        localStorage.setItem(keys.filters, JSON.stringify({ all: elements.all.checked }));
    } catch { /* Preferencia opcional. */ }
}

function chip(alert) {
    const info = alertInfo(alert);

    return `<span class="cx-chip cx-chip--${info.tone}" title="${escapeHtml(info.note)}">${escapeHtml(info.label)}</span>`;
}

function documentChip(item) {
    const tone = item.resuelto ? 'ok' : (item.estado === 'en_tramite' || item.faltantes?.length ? 'atencion' : (item.exigencia === 'exigido' ? 'critico' : 'neutro'));

    return `<span class="cx-chip cx-chip--${tone}">${escapeHtml(item.faltantes?.length ? 'Listo, incompleto' : documentStateLabel(item.estado))}</span>`;
}

function renderStatus(data) {
    const summary = data.resumen || {};
    const critical = Number(summary.urgentes || 0) + Number(summary.salieron_incompletos || 0);
    elements.status.className = `cx-status${critical ? ' cx-status--alerta' : ''}`;
    elements.status.innerHTML = `<strong>Del ${escapeHtml(formatDay(data.periodo.desde))} al ${escapeHtml(formatDay(data.periodo.hasta))}: ${escapeHtml(countText(summary.embarques, 'embarque'))} con seguimiento.</strong>`
        + `<small>${critical ? `${escapeHtml(countText(critical, 'embarque necesita', 'embarques necesitan'))} atención ahora. ` : ''}`
        + `Urgente: faltan documentos de antes de la salida y el embarque es en ${escapeHtml(countText(data.dias_alerta, 'día'))} o menos.`
        + `${summary.no_seguidos ? ` ${escapeHtml(countText(summary.no_seguidos, 'embarque cancelado o sin seguimiento queda oculto', 'embarques cancelados o sin seguimiento quedan ocultos'))}.` : ''}</small>`;
}

function renderSetup(data) {
    elements.setup.hidden = data.configurado;
    if (data.configurado) return;
    elements.setupText.textContent = can('configurar')
        ? 'Agregue los documentos sugeridos de una exportación de fruta fresca y ajústelos después en la configuración, o defina los suyos.'
        : 'Pida a quien configura Comex que defina qué documentos se exigen. Mientras tanto, los embarques no piden documentos.';
    elements.setupActions.innerHTML = can('configurar')
        ? '<button class="primary-button" type="button" data-files-suggested>Agregar documentos sugeridos</button><a class="secondary-button" href="/oficina/comex/configuracion">Ir a la configuración</a>'
        : '';
}

function renderKpis(data) {
    const summary = data.resumen || {};
    const cards = [
        { alert: '', label: 'Embarques', value: summary.embarques, note: 'con seguimiento' },
        { alert: 'salio_incompleto', label: 'Salieron incompletos', value: summary.salieron_incompletos, note: 'sin documentos de antes', tone: 'critico' },
        { alert: 'urgente', label: 'Urgentes', value: summary.urgentes, note: 'salen pronto', tone: 'critico' },
        { alert: 'pendiente', label: 'Pendientes', value: summary.pendientes, note: 'con algo por completar', tone: 'atencion' },
        { alert: 'completo', label: 'Completos', value: summary.completos, note: 'todo resuelto', tone: 'ok' },
        { alert: null, label: 'Documentos faltantes', value: summary.documentos_faltantes, note: 'exigidos sin resolver' },
    ];
    elements.kpis.innerHTML = cards.map((card) => {
        const active = card.alert !== null && elements.alert.value === card.alert;
        const tag = card.alert === null ? 'div' : 'button';

        return `<${tag} class="cx-kpi${card.tone && Number(card.value) ? ` cx-kpi--${card.tone}` : ''}${active ? ' is-active' : ''}"${card.alert === null ? '' : ` type="button" data-files-kpi="${card.alert}" aria-pressed="${active}"`}>`
            + `<span>${escapeHtml(card.label)}</span><strong>${escapeHtml(String(Number(card.value || 0).toLocaleString('es-CL')))}</strong><small>${escapeHtml(card.note)}</small></${tag}>`;
    }).join('');
}

function renderClients(data) {
    const current = elements.client.value;
    elements.client.innerHTML = '<option value="">Todos</option>'
        + clientsOf(data.data).map((client) => `<option value="${escapeHtml(client.id)}">${escapeHtml(client.name)}</option>`).join('');
    elements.client.value = clientsOf(data.data).some((client) => client.id === current) ? current : '';
}

function rowCard(row, day) {
    const percent = progressPercent(row.resumen);
    const destinations = row.destinos.join(', ') || 'Destino sin informar';
    const transport = [transportLabel(row.modalidad), row.nave_vuelo, row.contenedor].filter(Boolean).join(' · ');
    const load = row.carga ? `Carga ${row.carga.codigo}${row.carga.salio ? ' · salió' : ''}` : 'Sin carga asignada';

    return `<button class="cx-embarque cx-embarque--${alertInfo(row.alerta).tone}" type="button" data-file="${escapeHtml(row.id)}">`
        + '<span class="cx-embarque__fecha">'
        + `<strong>${escapeHtml(formatDay(row.fecha_programada))}</strong><small>${escapeHtml(relativeDay(row.fecha_programada, day))} · ${escapeHtml(row.hora_programada)}</small>`
        + '</span>'
        + '<span class="cx-embarque__datos">'
        + `<strong>${escapeHtml(row.codigo)} · ${escapeHtml(row.cliente_nombre)}</strong>`
        + `<small>${escapeHtml(destinations)} · ${escapeHtml(transport)}</small>`
        + `<small>${escapeHtml(load)}${row.gestion === 'propia' ? ' · exportación propia' : ''}</small>`
        + '</span>'
        + '<span class="cx-embarque__avance">'
        + `<span class="cx-barra" aria-hidden="true"><span style="width:${percent}%"></span></span>`
        + `<small>${escapeHtml(progressText(row.resumen))}</small>`
        + '</span>'
        + `<span class="cx-embarque__falta">${escapeHtml(missingText(row))}</span>`
        + `<span class="cx-embarque__alerta">${chip(row.alerta)}</span>`
        + '</button>';
}

function renderList() {
    const data = state.data;
    if (!data) return;
    const rows = filterRows(data.data, { term: elements.search.value, client: elements.client.value, alert: elements.alert.value, all: elements.all.checked });
    if (!rows.length) {
        elements.list.innerHTML = `<p class="cx-vacio">${data.data.length ? 'Ningún embarque coincide con los filtros.' : 'No hay embarques programados en estas fechas.'}</p>`;
        return;
    }
    elements.list.innerHTML = rows.map((row) => rowCard(row, data.hoy)).join('');
}

function render(data) {
    state.data = data;
    renderStatus(data);
    renderSetup(data);
    renderKpis(data);
    renderClients(data);
    renderList();
}

async function load() {
    const sequence = ++state.loads;
    const params = new URLSearchParams({ desde: elements.from.value, hasta: elements.to.value });
    elements.status.className = 'cx-status';
    elements.status.textContent = 'Cargando…';
    const response = await api(`/api/comex/expedientes?${params}`);
    if (sequence !== state.loads) return;
    if (!response.ok) {
        elements.status.className = 'cx-status cx-status--alerta';
        elements.status.textContent = errorText(response.data, 'No fue posible cargar los expedientes.');
        elements.list.innerHTML = '';
        elements.kpis.innerHTML = '';
        return;
    }
    render(response.data);
}

async function loadSuggested() {
    busy(true, 'Agregando los documentos sugeridos…');
    const response = await api('/api/comex/configuracion/sugeridos', { method: 'POST' });
    busy(false);
    if (!response.ok) {
        toast(errorText(response.data, 'No fue posible agregar los documentos sugeridos.'), true);
        return;
    }
    toast(response.data.message);
    load();
}

/* Detalle del expediente. */

function infoItem(label, value) {
    return value ? `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>` : '';
}

function documentRow(item) {
    const detail = documentDetail(item);
    const note = requirementNote(item);
    const file = item.archivo
        ? `<a class="cx-boton" href="#" data-document-file="${item.tipo.id}" title="${escapeHtml(`${item.archivo.nombre} · ${formatBytes(item.archivo.bytes)}`)}">Ver respaldo</a>`
        : '';
    const history = (item.archivos || []).filter((archivo) => archivo.reemplazado_at);
    const reference = item.referencia && item.estado === 'pendiente'
        ? `<small class="cx-documento__ref">${escapeHtml(item.referencia.etiqueta)} en la operación: ${escapeHtml(item.referencia.valores.join(', '))}</small>` : '';
    const action = can('gestionar') && state.detail.embarque.estado !== 'cancelado' && (item.tipo.activo || item.documento_id)
        ? `<button class="cx-boton cx-boton--principal" type="button" data-document="${item.tipo.id}">${item.documento_id ? 'Editar' : 'Registrar'}</button>` : '';

    const missing = !item.resuelto && item.exigencia === 'exigido' ? ` is-falta-${item.tipo.momento === 'antes_salida' ? 'antes' : 'despues'}` : '';

    return `<li class="cx-documento${item.resuelto ? ' is-resuelto' : ''}${missing}">`
        + '<div class="cx-documento__nombre">'
        + `<strong>${escapeHtml(item.tipo.nombre)}</strong>${documentChip(item)}`
        + (detail ? `<small>${escapeHtml(detail)}</small>` : '')
        + (note ? `<small class="cx-documento__nota">${escapeHtml(note)}</small>` : '')
        + reference
        + (item.observacion ? `<small>Obs.: ${escapeHtml(item.observacion)}</small>` : '')
        + (item.actualizado_at ? `<small>Actualizado por ${escapeHtml(item.actualizado_por || 'usuario sin nombre')} el ${escapeHtml(formatMoment(item.actualizado_at))}</small>` : '')
        + (history.length ? `<details class="cx-documento__versiones"><summary>${escapeHtml(countText(history.length, 'respaldo anterior', 'respaldos anteriores'))}</summary><ul>${history.map((archivo) => `<li><a href="#" data-archive="${escapeHtml(archivo.id)}">${escapeHtml(archivo.nombre)}</a> <small>subido el ${escapeHtml(formatMoment(archivo.subido_at))} por ${escapeHtml(archivo.subido_por || 'usuario sin nombre')}</small></li>`).join('')}</ul></details>` : '')
        + '</div>'
        + `<div class="cx-acciones">${file}${action}</div>`
        + '</li>';
}

function renderDetail(detail) {
    state.detail = detail;
    const shipment = detail.embarque;
    const info = alertInfo(detail.alerta);
    elements.detailEyebrow.textContent = `EXPEDIENTE · ${info.label.toLocaleUpperCase('es')}`;
    elements.detailTitle.textContent = `${shipment.codigo} · ${shipment.cliente_nombre}`;
    elements.detailSubtitle.textContent = `${formatDay(shipment.fecha_programada)} ${shipment.hora_programada} · ${transportLabel(shipment.modalidad)} · ${managementLabel(detail.gestion)}${detail.gestion_configurada ? '' : ' (por omisión)'}`;
    elements.packing.disabled = !shipment.carga;
    elements.packing.title = shipment.carga ? '' : 'El embarque todavía no tiene una carga asignada.';

    const instructions = shipment.instructivos.map((item) => `<li><strong>${escapeHtml(item.numero || `Instructivo ${item.orden}`)}</strong>`
        + `<small>${escapeHtml([item.recibidor, [item.ciudad, item.pais].filter(Boolean).join(', ') || 'destino sin informar', item.pallets ? countText(item.pallets, 'pallet') : null].filter(Boolean).join(' · '))}</small>`
        + `<small>${escapeHtml(['booking', 'dus', 'planilla_sag', 'sps', 'sello_sag'].filter((key) => item[key]).map((key) => `${{ booking: 'Booking', dus: 'DUS', planilla_sag: 'Planilla SAG', sps: 'SPS', sello_sag: 'Sello SAG' }[key]} ${item[key]}`).join(' · '))}</small></li>`).join('');
    const load = shipment.carga
        ? `${shipment.carga.codigo} · ${shipment.carga.salio ? `salió${shipment.carga.cerrada_at ? ` el ${formatMoment(shipment.carga.cerrada_at)}` : ''}` : 'en preparación'} · ${countText(shipment.carga.folios, 'pallet')}`
        : 'Sin carga asignada';
    const groups = groupDocuments(detail.documentos);
    const summary = detail.resumen;

    elements.detailBody.innerHTML = `<p class="cx-detalle__alerta cx-detalle__alerta--${info.tone}"><strong>${escapeHtml(info.label)}.</strong> ${escapeHtml(info.note)} ${escapeHtml(progressText(summary))}.</p>`
        + '<dl class="cx-datos">'
        + infoItem('Estado en el calendario', { confirmado: 'Confirmado', tentativo: 'Tentativo', cancelado: 'Cancelado' }[shipment.estado] || shipment.estado)
        + infoItem('Nave o vuelo', shipment.nave_vuelo)
        + infoItem('Puerto de embarque', shipment.puerto_embarque)
        + infoItem('Transportista', shipment.transportista)
        + infoItem('Contenedor', shipment.contenedor)
        + infoItem('Sello', shipment.sello)
        + infoItem('Patentes', shipment.patentes)
        + infoItem('Carga', load)
        + '</dl>'
        + (shipment.documentos_operacion ? `<p class="cx-detalle__nota"><strong>Documentos anotados en el calendario:</strong> ${escapeHtml(shipment.documentos_operacion)}</p>` : '')
        + (instructions ? `<details class="cx-detalle__instructivos"${shipment.instructivos.length <= 2 ? ' open' : ''}><summary>${escapeHtml(countText(shipment.instructivos.length, 'instructivo'))}</summary><ul>${instructions}</ul></details>` : '<p class="cx-detalle__nota">El embarque todavía no tiene instructivos: sin destino no se sabe qué documentos exige el país.</p>')
        + (groups.length ? groups.map((group) => `<section class="cx-grupo"><h3>${escapeHtml(group.title)}</h3><p>${escapeHtml(group.note)}</p><ul class="cx-documentos">${group.items.map(documentRow).join('')}</ul></section>`).join('')
            : '<p class="cx-vacio">No hay documentos configurados para este embarque.</p>')
        + (detail.historial.length ? `<details class="cx-historial"><summary>Historial (${detail.historial.length})</summary><ol>${detail.historial.map((event) => `<li><span>${escapeHtml(historyText(event))}</span><small>${escapeHtml(formatMoment(event.at))} · ${escapeHtml(event.usuario || 'usuario sin nombre')}</small></li>`).join('')}</ol></details>` : '');
}

async function openDetail(id) {
    const sequence = ++state.detailLoads;
    state.detail = null;
    elements.detailTitle.textContent = 'Cargando…';
    elements.detailSubtitle.textContent = '';
    elements.detailBody.innerHTML = '<p class="cx-vacio">Cargando el expediente…</p>';
    if (!elements.detail.open) elements.detail.showModal();
    const response = await api(`/api/comex/expedientes/${encodeURIComponent(id)}`);
    if (sequence !== state.detailLoads) return;
    if (!response.ok) {
        elements.detailTitle.textContent = 'Expediente';
        elements.detailBody.innerHTML = `<p class="cx-vacio">${escapeHtml(errorText(response.data, 'No fue posible cargar el expediente.'))}</p>`;
        return;
    }
    renderDetail(response.data);
}

/* Registro de un documento. */

function selectedState() {
    return elements.documentForm.querySelector('input[name="estado"]:checked')?.value || '';
}

function updateDocumentForm() {
    const item = state.editing;
    if (!item) return;
    const status = selectedState();
    const ready = status === 'listo';
    elements.documentReasonField.hidden = status !== 'no_aplica';
    elements.documentNumberLabel.textContent = `Número${item.tipo.pide_numero && ready ? ' (obligatorio)' : ''}`;
    elements.documentDateLabel.textContent = `Fecha de emisión${item.tipo.pide_fecha && ready ? ' (obligatoria)' : ''}`;
    elements.documentFileLabel.textContent = `${item.archivo ? 'Reemplazar respaldo' : 'Respaldo'} (PDF, JPG, PNG o WebP)${item.tipo.pide_archivo && ready && !item.archivo ? ' (obligatorio)' : ''}`;
}

function openDocument(typeId) {
    const item = state.detail?.documentos?.find((documentItem) => String(documentItem.tipo.id) === String(typeId));
    if (!item) return;
    state.editing = item;
    elements.documentForm.reset();
    elements.documentEyebrow.textContent = `${state.detail.embarque.codigo} · ${item.exigencia === 'exigido' ? (item.tipo.momento === 'antes_salida' ? 'ANTES DE LA SALIDA' : 'DESPUÉS DE LA SALIDA') : 'NO EXIGIDO'}`;
    elements.documentTitle.textContent = item.tipo.nombre;
    elements.documentDescription.textContent = [item.tipo.descripcion, requirementNote(item)].filter(Boolean).join(' ');
    const status = item.estado === 'pendiente' ? 'en_tramite' : item.estado;
    elements.documentForm.querySelector(`input[name="estado"][value="${status}"]`).checked = true;
    elements.documentNumber.value = item.numero || (item.referencia?.valores?.length === 1 ? item.referencia.valores[0] : '');
    elements.documentDate.value = item.fecha_emision || '';
    elements.documentReason.value = item.motivo_no_aplica || '';
    elements.documentNote.value = item.observacion || '';
    elements.documentReference.hidden = !item.referencia;
    elements.documentReference.textContent = item.referencia ? `${item.referencia.etiqueta} informado en la operación: ${item.referencia.valores.join(', ')}.${item.numero ? '' : ' Se propone como número; corríjalo si no corresponde.'}` : '';
    elements.documentCurrentFile.textContent = item.archivo
        ? `Respaldo actual: ${item.archivo.nombre} (${formatBytes(item.archivo.bytes)}), subido el ${formatMoment(item.archivo.subido_at)}. Si sube otro, el actual queda en el historial.`
        : '';
    elements.documentMessage.textContent = '';
    updateDocumentForm();
    elements.documentDialog.showModal();
}

async function submitDocument(event) {
    event.preventDefault();
    const item = state.editing;
    if (!item || elements.documentSubmit.disabled) return;
    const file = elements.documentFile.files[0] || null;
    const values = {
        estado: selectedState(),
        numero: elements.documentNumber.value,
        fecha_emision: elements.documentDate.value,
        motivo: elements.documentReason.value,
    };
    const problem = documentFormProblem(item.tipo, values, Boolean(file || item.archivo));
    if (problem) {
        elements.documentMessage.textContent = problem;
        return;
    }
    const body = new FormData();
    body.set('estado', values.estado);
    body.set('numero', values.numero.trim());
    body.set('fecha_emision', values.fecha_emision);
    body.set('motivo', values.estado === 'no_aplica' ? values.motivo.trim() : '');
    body.set('observacion', elements.documentNote.value.trim());
    body.set('version', String(item.version));
    if (file) body.set('archivo', file);

    elements.documentSubmit.disabled = true;
    elements.documentMessage.textContent = '';
    busy(true, file ? 'Subiendo el respaldo…' : 'Guardando…');
    const embarque = state.detail.embarque.id;
    const response = await api(`/api/comex/expedientes/${encodeURIComponent(embarque)}/documentos/${item.tipo.id}`, { method: 'POST', body });
    busy(false);
    elements.documentSubmit.disabled = false;
    if (!response.ok) {
        elements.documentMessage.textContent = errorText(response.data, 'No fue posible guardar el documento.');
        // Otra persona lo cambió: se recarga el expediente para que vea lo vigente.
        if (response.data?.errors?.version) openDetail(embarque);
        return;
    }
    elements.documentDialog.close();
    toast(response.data.message);
    if (state.detail?.embarque?.id === embarque) renderDetail(response.data.data);
    load();
}

async function download(path, fallbackName, failure) {
    busy(true, 'Preparando la descarga…');
    try {
        const response = await fetch(path, { headers: { Authorization: `Bearer ${state.token}` } });
        if (response.status === 401) clearSession();
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            toast(errorText(data, failure), true);
            return;
        }
        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name = decodeURIComponent(/filename\*=UTF-8''([^;]+)/.exec(disposition)?.[1] ?? /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName);
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
        toast('No fue posible conectar con el servidor.', true);
    } finally {
        busy(false);
    }
}

function showApp() {
    if (state.identity?.puede_consultar_comex !== true && state.identity?.capacidades?.puede_consultar_comex !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = String(state.identity?.rol || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    return true;
}

function start() {
    const day = today();
    elements.from.value ||= addDays(day, -7);
    elements.to.value ||= addDays(day, 21);
    elements.all.checked = readJson(keys.filters)?.all === true;
    load();
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const response = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
    if (!response.ok) {
        elements.loginError.textContent = errorText(response.data, 'No fue posible ingresar.');
        return;
    }
    state.token = response.data.token;
    state.identity = response.data.usuario;
    localStorage.setItem(keys.token, state.token);
    localStorage.setItem(keys.identity, JSON.stringify(state.identity));
    if (!showApp()) {
        elements.loginError.textContent = 'Su perfil no tiene acceso a Comex.';
        return;
    }
    start();
});
elements.logout?.addEventListener('click', clearSession);
elements.range.addEventListener('submit', (event) => {
    event.preventDefault();
    if (elements.from.value && elements.to.value && elements.to.value < elements.from.value) {
        toast('La fecha de término debe ser igual o posterior a la de inicio.', true);
        return;
    }
    load();
});
elements.filters.addEventListener('submit', (event) => event.preventDefault());
elements.search.addEventListener('input', renderList);
elements.client.addEventListener('change', renderList);
elements.alert.addEventListener('change', () => { renderKpis(state.data || {}); renderList(); });
elements.all.addEventListener('change', () => { saveFilters(); renderList(); });
elements.kpis.addEventListener('click', (event) => {
    const card = event.target.closest('[data-files-kpi]');
    if (!card) return;
    elements.alert.value = elements.alert.value === card.dataset.filesKpi ? '' : card.dataset.filesKpi;
    renderKpis(state.data);
    renderList();
});
elements.setupActions.addEventListener('click', (event) => {
    if (event.target.closest('[data-files-suggested]')) loadSuggested();
});
elements.list.addEventListener('click', (event) => {
    const row = event.target.closest('[data-file]');
    if (row) openDetail(row.dataset.file);
});
elements.detailBody.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-document]');
    if (edit) openDocument(edit.dataset.document);
    const file = event.target.closest('[data-document-file]');
    if (file) {
        event.preventDefault();
        download(`/api/comex/expedientes/${encodeURIComponent(state.detail.embarque.id)}/documentos/${file.dataset.documentFile}/archivo`, 'respaldo.pdf', 'No fue posible descargar el respaldo.');
    }
    const archive = event.target.closest('[data-archive]');
    if (archive) {
        event.preventDefault();
        download(`/api/comex/archivos/${encodeURIComponent(archive.dataset.archive)}`, 'respaldo.pdf', 'No fue posible descargar el respaldo.');
    }
});
elements.packing.addEventListener('click', () => {
    const shipment = state.detail?.embarque;
    if (shipment) download(`/api/comex/expedientes/${encodeURIComponent(shipment.id)}/packing-list`, `packing-list-${shipment.codigo.toLowerCase()}.xlsx`, 'No fue posible preparar el packing list.');
});
elements.documentForm.addEventListener('change', (event) => {
    if (event.target.name === 'estado') updateDocumentForm();
});
elements.documentForm.addEventListener('submit', submitDocument);
for (const dialog of [elements.detail, elements.documentDialog]) {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || event.target.closest('[data-cx-close]')) dialog.close();
    });
}
elements.detail.addEventListener('close', () => { state.detailLoads++; state.detail = null; });

if (state.token && showApp()) start();
