import { createReceptionPhotosPanel } from './office-material-reception-photos.js';
import './office-material-reception-import.js';

const receptionElements = {
    workspace: document.getElementById('materialReceptionsWorkspace'),
    summary: document.getElementById('materialReceptionsSummary'),
    error: document.getElementById('materialReceptionsError'),
    list: document.getElementById('materialReceptionsList'),
    reload: document.getElementById('reloadMaterialReceptions'),
    blankSampling: document.getElementById('downloadBlankMaterialSampling'),
    create: document.getElementById('newMaterialReception'),
    filters: document.getElementById('materialReceptionsFilters'),
    previous: document.getElementById('materialReceptionsPrevious'),
    next: document.getElementById('materialReceptionsNext'),
    pageSummary: document.getElementById('materialReceptionsPageSummary'),
    draftCount: document.getElementById('materialReceptionDraftCount'),
    confirmedCount: document.getElementById('materialReceptionConfirmedCount'),
    cancelledCount: document.getElementById('materialReceptionCancelledCount'),
    folioCount: document.getElementById('materialReceptionFolioCount'),
    eliminations: document.getElementById('materialReceptionEliminations'),
    showEliminations: document.getElementById('showMaterialReceptionEliminations'),
    photos: document.getElementById('materialReceptionPhotos'),
    dialog: document.getElementById('materialReceptionDialog'),
    form: document.getElementById('materialReceptionForm'),
    formError: document.getElementById('materialReceptionFormError'),
    dialogEyebrow: document.getElementById('materialReceptionDialogEyebrow'),
    dialogTitle: document.getElementById('materialReceptionDialogTitle'),
    dialogHelp: document.getElementById('materialReceptionDialogHelp'),
    close: document.getElementById('closeMaterialReceptionDialog'),
    cancel: document.getElementById('cancelMaterialReception'),
    addLine: document.getElementById('addMaterialReceptionLine'),
    lines: document.getElementById('materialReceptionLines'),
    reason: document.getElementById('materialReceptionCorrectionReason'),
    warning: document.getElementById('materialReceptionConfirmedWarning'),
    saveDraft: document.getElementById('saveMaterialReceptionDraft'),
    saveConfirm: document.getElementById('saveAndConfirmMaterialReception'),
    deleteDialog: document.getElementById('materialReceptionDeleteDialog'),
    deleteForm: document.getElementById('materialReceptionDeleteForm'),
    deleteSummary: document.getElementById('materialReceptionDeleteSummary'),
    deleteError: document.getElementById('materialReceptionDeleteError'),
    deleteClose: document.getElementById('closeMaterialReceptionDeleteDialog'),
    deleteCancel: document.getElementById('cancelMaterialReceptionDelete'),
};

const receptionState = {
    records: [],
    catalogs: { clients: [], providers: [], items: [] },
    meta: null,
    page: 1,
    current: null,
    mode: 'view',
    lines: [],
    loading: false,
};

const photosPanel = receptionElements.photos ? createReceptionPhotosPanel(receptionElements.photos, {
    getReception: () => receptionState.current, token: receptionToken, canManage: receptionCanManage, canAdminister: receptionCanAdminister,
    onChange: (fotos) => {
        if (!receptionState.current) return;
        receptionState.current.fotos = fotos;
        receptionState.current.fotos_documento = fotos.filter((f) => f.tipo === 'documento').length;
        configureReceptionDialog();
    },
    onConfirm: async () => {
        const current = receptionState.current;
        current.photoConfirmationOperation ||= receptionUuid();
        try {
            const response = await receptionApi(`/api/materiales/recepciones/${current.id}/confirmar`, {
                method: 'POST', body: JSON.stringify({ operacion_id: current.photoConfirmationOperation, version_conocida: current.version }),
            });
            populateReceptionForm(response.data); configureReceptionDialog(); await photosPanel.render(); await loadReceptions();
        } catch (error) { receptionElements.photos.scrollIntoView({ block: 'center' }); throw error; }
    },
}) : null;
receptionElements.dialog?.addEventListener('close', () => photosPanel?.clear());

let eliminationsPage = 1;
async function loadEliminationEvidence(page = 1) {
    const response = await receptionApi(`/api/materiales/recepciones/eliminaciones?page=${page}`);
    eliminationsPage = response.current_page;
    const root = receptionElements.eliminations;
    root.classList.remove('is-hidden');
    root.innerHTML = '<h3>Evidencia de recepciones eliminadas</h3>' + response.data.map((record) => `<article><strong>Guía ${receptionEscape(record.numero_guia_despacho)}</strong><p>${receptionEscape(record.motivo)} · ${receptionEscape(record.eliminado_at)}</p>${record.fotos.map((foto) => `<button type="button" data-archived-photo="${receptionEscape(foto.url)}">${foto.tipo === 'documento' ? 'Documento' : 'Referencial'} ${foto.orden}</button>`).join('') || '<p>Sin fotos conservadas</p>'}</article>`).join('') + `<button type="button" data-archive-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button><button type="button" data-archive-page="${page + 1}" ${page >= response.last_page ? 'disabled' : ''}>Siguiente</button>`;
}
receptionElements.showEliminations?.addEventListener('click', () => { void loadEliminationEvidence(eliminationsPage).catch((error) => { receptionElements.error.textContent = error.message; }); });
receptionElements.eliminations?.addEventListener('click', async (event) => {
    const button = event.target.closest('button'); if (!button || button.disabled) return;
    try {
        if (button.dataset.archivePage) { await loadEliminationEvidence(Number(button.dataset.archivePage)); return; }
        const path = button.dataset.archivedPhoto; if (!path) return;
        const opened = window.open('about:blank', '_blank');
        try {
            const response = await fetch(path, { headers: { Authorization: `Bearer ${receptionToken()}` }, cache: 'no-store' });
            if (!response.ok) throw new Error('No fue posible descargar la evidencia.');
            const url = URL.createObjectURL(await response.blob());
            if (opened) { opened.opener = null; opened.location.href = url; }
            setTimeout(() => URL.revokeObjectURL(url), 120000);
        } catch (error) { opened?.close(); throw error; }
    } catch (error) { receptionElements.error.textContent = error.message; }
});

function receptionIsActive() {
    return receptionElements.workspace
        && document.getElementById('officeApp')?.dataset.materialsSection === 'recepciones';
}

function receptionToken() {
    return localStorage.getItem('estiba_wms_office_token');
}

function receptionIdentity() {
    try {
        return JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
    } catch {
        return null;
    }
}

function receptionCapabilities() {
    const identity = receptionIdentity() || {};
    return { ...(identity.capacidades || {}), ...identity };
}

function receptionCanManage() {
    return receptionCapabilities().puede_gestionar_recepciones_materiales === true;
}

function receptionCanAdminister() {
    return receptionCapabilities().puede_administrar_recepciones_materiales === true;
}

function receptionEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function receptionUuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function receptionError(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

async function receptionApi(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${receptionToken()}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(path, { ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(receptionError(data, 'No fue posible completar la operación.'));
    }
    return data;
}

async function downloadReceptionSampling(record = null) {
    receptionElements.error.textContent = '';
    const path = record
        ? `/api/materiales/recepciones/${record.id}/registro-muestreo`
        : '/api/materiales/recepciones/registro-muestreo/en-blanco';
    const response = await fetch(
        path,
        {
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                Authorization: `Bearer ${receptionToken()}`,
            },
        },
    );
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(receptionError(data, 'No fue posible generar el registro de muestreo.'));
    }

    const blob = await response.blob();
    const disposition = response.headers.get('content-disposition') || '';
    const fileName = disposition.match(/filename="?([^";]+)"?/i)?.[1]
        || (record
            ? `Registro_Muestreo_${record.numero_guia_despacho}.xlsx`
            : 'Registro_Muestreo_EN_BLANCO.xlsx');
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function receptionStatus(value) {
    return ({ borrador: 'Borrador', confirmada: 'Confirmada', anulada: 'Anulada' })[value] || value;
}

function receptionDate(value) {
    return value ? new Intl.DateTimeFormat('es-CL').format(new Date(`${value}T12:00:00`)) : 'Sin fecha';
}

function receptionQuantity(value) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value || 0));
}

function receptionFolioCount(record) {
    return record?.snapshot_confirmacion?.folios?.length || 0;
}

function renderReceptionList() {
    const admin = receptionCanAdminister();
    receptionElements.showEliminations?.classList.toggle('is-hidden', !admin);
    const counts = receptionState.records.reduce((result, record) => {
        result[record.estado] = (result[record.estado] || 0) + 1;
        return result;
    }, {});
    const folios = receptionState.records.reduce(
        (total, record) => total + receptionFolioCount(record),
        0,
    );

    receptionElements.draftCount.textContent = counts.borrador || 0;
    receptionElements.confirmedCount.textContent = counts.confirmada || 0;
    receptionElements.cancelledCount.textContent = counts.anulada || 0;
    receptionElements.folioCount.textContent = folios;
    receptionElements.summary.textContent = `${receptionState.meta?.total || receptionState.records.length} recepciones encontradas`;
    receptionElements.list.innerHTML = receptionState.records.map((record) => {
        const adminActions = admin
            ? `${record.estado !== 'anulada' ? `<button data-action="edit" data-id="${record.id}" type="button">Editar</button>` : ''}<button data-action="delete" data-id="${record.id}" type="button">Eliminar</button>`
            : '';
        const samplingAction = record.estado === 'confirmada'
            ? `<button data-action="sampling" data-id="${record.id}" type="button">Registro de muestreo</button>`
            : '';
        return `
            <tr>
                <td><strong>${receptionEscape(record.numero_guia_despacho)}</strong><small>${receptionEscape(record.orden_compra || 'Sin OC')}</small></td>
                <td><strong>${receptionEscape(record.cliente?.codigo)} · ${receptionEscape(record.cliente?.nombre)}</strong><small>${receptionEscape(record.proveedor?.codigo)} · ${receptionEscape(record.proveedor?.nombre)}</small></td>
                <td>${receptionEscape(receptionDate(record.fecha_documento))}</td>
                <td><span class="material-reception-state material-reception-state--${receptionEscape(record.estado)}">${receptionEscape(receptionStatus(record.estado))}</span>${!record.fotos_documento ? '<small>Sin foto de documento</small>' : ''}</td>
                <td>${receptionEscape(record.detalles_count ?? '—')} ítems · ${receptionFolioCount(record)} folios</td>
                <td><div class="material-reception-actions"><button data-action="view" data-id="${record.id}" type="button">Ver expediente</button>${samplingAction}${adminActions}</div></td>
            </tr>
        `;
    }).join('') || '<tr><td colspan="6"><p class="empty-state">No existen recepciones para los filtros seleccionados.</p></td></tr>';

    const current = receptionState.meta?.current_page || receptionState.page;
    const last = receptionState.meta?.last_page || 1;
    receptionElements.pageSummary.textContent = `Página ${current} de ${last}`;
    receptionElements.previous.disabled = current <= 1;
    receptionElements.next.disabled = current >= last;
    receptionElements.create.classList.toggle('is-hidden', !receptionCanManage());
}

async function loadReceptions(page = receptionState.page) {
    if (!receptionIsActive() || !receptionToken() || receptionState.loading) return;
    receptionState.loading = true;
    receptionElements.error.textContent = '';
    try {
        const data = new FormData(receptionElements.filters);
        const params = new URLSearchParams({ page: String(page), per_page: '25' });
        if (data.get('guia')) params.set('guia', data.get('guia'));
        if (data.get('estado')) params.set('estado', data.get('estado'));
        const response = await receptionApi(`/api/materiales/recepciones?${params}`);
        receptionState.records = response.data || [];
        receptionState.meta = response.meta || null;
        receptionState.page = response.meta?.current_page || page;
        renderReceptionList();
    } catch (error) {
        receptionElements.error.textContent = error.message;
    } finally {
        receptionState.loading = false;
    }
}

async function loadReceptionCatalogs() {
    if (receptionState.catalogs.clients.length) return;
    const catalogs = await receptionApi('/api/materiales/recepciones/catalogos');
    receptionState.catalogs = {
        clients: catalogs.clientes || [],
        providers: catalogs.proveedores || [],
        items: catalogs.items || [],
    };
}

function receptionClientId() {
    return receptionElements.form.elements.cliente_id.value;
}

function receptionProviderId() {
    return receptionElements.form.elements.proveedor_material_id.value;
}

function renderReceptionHeaderOptions() {
    const clientId = receptionClientId() || receptionState.current?.cliente?.id || '';
    const providerId = receptionProviderId() || receptionState.current?.proveedor?.id || '';
    receptionElements.form.elements.cliente_id.innerHTML = '<option value="">Seleccionar cliente</option>'
        + receptionState.catalogs.clients.map((client) =>
            `<option value="${client.id}"${client.id === clientId ? ' selected' : ''}>${receptionEscape(client.codigo)} · ${receptionEscape(client.nombre)}</option>`).join('');
    const providers = receptionState.catalogs.providers.filter((provider) =>
        !clientId || provider.cliente_ids.includes(clientId));
    receptionElements.form.elements.proveedor_material_id.innerHTML = '<option value="">Seleccionar proveedor</option>'
        + providers.map((provider) =>
            `<option value="${provider.id}"${provider.id === providerId ? ' selected' : ''}>${receptionEscape(provider.codigo)} · ${receptionEscape(provider.nombre)}</option>`).join('');
}

function receptionNormalizedSearch(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
}

function receptionItemLabel(item) {
    if (!item) return '';
    return `${item.codigo} · ${item.nombre} · ${item.categoria_operacional_etiqueta || item.categoria_operacional || 'Sin tipo'} · ${item.unidad_medida}`;
}

function receptionMatchingItems(query, category = '') {
    const clientId = receptionClientId();
    const provider = receptionState.catalogs.providers.find((candidate) =>
        candidate.id === receptionProviderId());
    const providerCategories = new Set((provider?.categorias || [])
        .filter((assignment) => !clientId || assignment.cliente_id === clientId)
        .map((assignment) => receptionNormalizedSearch(assignment.categoria)));
    const normalized = receptionNormalizedSearch(query);
    return receptionState.catalogs.items
        .filter((item) => (!clientId || item.cliente_id === clientId)
            && (!provider || providerCategories.has(receptionNormalizedSearch(item.categoria)))
            && (!category || item.categoria_operacional === category)
            && (!normalized || receptionNormalizedSearch([
                item.codigo,
                item.nombre,
                item.categoria,
                item.categoria_operacional,
                item.categoria_operacional_etiqueta,
                item.unidad_medida,
            ].join(' ')).includes(normalized)))
        .sort((left, right) => receptionItemLabel(left).localeCompare(receptionItemLabel(right), 'es'))
        .slice(0, 15);
}

function closeReceptionItemResults(except = null) {
    receptionElements.lines.querySelectorAll('.material-item-results').forEach((results) => {
        if (results === except) return;
        results.classList.add('is-hidden');
        results.closest('.material-item-picker')?.querySelector('.material-item-search')?.setAttribute('aria-expanded', 'false');
    });
}

function renderReceptionItemResults(article) {
    const input = article.querySelector('.material-item-search');
    const filter = article.querySelector('.material-reception-item-filter');
    const results = article.querySelector('.material-item-results');
    const matches = receptionMatchingItems(input.value, filter.value);
    results.innerHTML = matches.map((item) => `<button class="material-item-option" data-select-reception-item="${item.id}" type="button" role="option"><span>${receptionEscape(item.codigo)} · ${receptionEscape(item.nombre)}</span><strong>${receptionEscape(item.categoria_operacional_etiqueta)} · ${receptionEscape(item.unidad_medida)}</strong></button>`).join('')
        || '<p class="material-item-empty">No hay productos que coincidan con la búsqueda y el filtro.</p>';
    results.classList.remove('is-hidden');
    input.setAttribute('aria-expanded', 'true');
}

function packageSizeFromExisting(packages) {
    if (!packages?.length) return '';
    return Math.max(...packages.map((packageItem) => Number(packageItem.cantidad || 0)));
}

function receptionLineState(detail = null) {
    const packages = detail?.bultos || [];
    const first = packages[0] || {};
    return {
        key: receptionUuid(),
        itemId: detail?.item?.id || '',
        itemSnapshot: detail?.item || null,
        itemFilter: '',
        documentary: detail?.cantidad_documental ?? '',
        counted: detail?.cantidad_contada ?? '',
        accepted: detail?.cantidad_aceptada ?? detail?.cantidad_recibida ?? '',
        rejected: detail?.cantidad_rechazada ?? 0,
        packageSize: packageSizeFromExisting(packages),
        lot: first.lote_proveedor || '',
        manufacturedAt: first.fecha_fabricacion || '',
        expiresAt: first.fecha_vencimiento || '',
        blocked: first.bloqueado === true,
        blockReason: first.motivo_bloqueo || '',
        observation: detail?.observacion || '',
        originalPackages: packages.map((packageItem) => ({
            cantidad: packageItem.cantidad,
            lote_proveedor: packageItem.lote_proveedor,
            fecha_fabricacion: packageItem.fecha_fabricacion,
            fecha_vencimiento: packageItem.fecha_vencimiento,
            bloqueado: packageItem.bloqueado,
            motivo_bloqueo: packageItem.motivo_bloqueo,
        })),
        packagesDirty: !detail,
    };
}

function packageSummary(line) {
    const accepted = Number(line.accepted || 0);
    const packageSize = Number(line.packageSize || 0);
    if (!accepted || !packageSize) return 'Indica cantidad aceptada y unidades por bulto.';
    const full = Math.floor(accepted / packageSize);
    const difference = Math.round((accepted - (full * packageSize)) * 1000) / 1000;
    const total = full + (difference > 0.0001 ? 1 : 0);
    return difference > 0.0001
        ? `${total} bultos: ${full} de ${receptionQuantity(packageSize)} y 1 de ${receptionQuantity(difference)}.`
        : `${total} bultos de ${receptionQuantity(packageSize)}.`;
}

function renderReceptionLines() {
    const readonly = receptionState.mode === 'view';
    receptionElements.lines.innerHTML = receptionState.lines.map((line, index) => {
        const selectedItem = receptionState.catalogs.items.find((item) => item.id === line.itemId)
            || line.itemSnapshot;
        return `
        <article class="material-reception-line${readonly ? ' material-reception-readonly' : ''}" data-line-key="${line.key}">
            <div class="material-reception-line__heading"><strong>Producto ${index + 1}</strong>${readonly ? '' : `<button data-remove-line="${line.key}" type="button">Quitar</button>`}</div>
            <div class="material-reception-line__grid">
                <label class="wide"><span>Producto *</span><div class="material-item-picker material-reception-item-picker"><div class="material-reception-item-controls"><input class="material-item-search" type="search" value="${receptionEscape(receptionItemLabel(selectedItem))}" placeholder="Buscar por código, nombre, categoría o unidad" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"${readonly ? ' readonly' : ''}><select class="material-reception-item-filter" aria-label="Filtrar productos por tipo"${readonly ? ' disabled' : ''}><option value="">Todos los tipos</option><option value="insumo"${line.itemFilter === 'insumo' ? ' selected' : ''}>Insumos</option><option value="material_mp"${line.itemFilter === 'material_mp' ? ' selected' : ''}>Material MP</option><option value="material_pt"${line.itemFilter === 'material_pt' ? ' selected' : ''}>Material PT</option></select></div><input name="item_material_id" type="hidden" value="${receptionEscape(line.itemId)}"><div class="material-item-results is-hidden" role="listbox"></div><small class="material-reception-item-hint">Escribe para filtrar; Enter selecciona el primer resultado.</small></div></label>
                <label><span>Cantidad documental *</span><input name="cantidad_documental" type="number" min="0.001" step="0.001" value="${receptionEscape(line.documentary)}" required></label>
                <label><span>Cantidad contada *</span><input name="cantidad_contada" type="number" min="0.001" step="0.001" value="${receptionEscape(line.counted)}" required></label>
                <label><span>Cantidad aceptada *</span><input name="cantidad_aceptada" type="number" min="0" step="0.001" value="${receptionEscape(line.accepted)}" required></label>
                <label><span>Cantidad rechazada *</span><input name="cantidad_rechazada" type="number" min="0" step="0.001" value="${receptionEscape(line.rejected)}" required></label>
                <label><span>Unidades por bulto *</span><input name="tamano_bulto" type="number" min="0.001" step="0.001" value="${receptionEscape(line.packageSize)}"></label>
                <label><span>Lote proveedor</span><input name="lote_proveedor" maxlength="100" value="${receptionEscape(line.lot)}"></label>
                <label><span>Fabricación</span><input name="fecha_fabricacion" type="date" value="${receptionEscape(line.manufacturedAt)}"></label>
                <label><span>Vencimiento</span><input name="fecha_vencimiento" type="date" value="${receptionEscape(line.expiresAt)}"></label>
                <label><span>Bloqueo</span><select name="bloqueado"><option value="0"${!line.blocked ? ' selected' : ''}>Sin bloqueo</option><option value="1"${line.blocked ? ' selected' : ''}>Bloqueado</option></select></label>
                <label class="wide"><span>Motivo de bloqueo</span><input name="motivo_bloqueo" maxlength="2000" value="${receptionEscape(line.blockReason)}"></label>
                <label class="wide"><span>Observación del producto</span><input name="observacion_detalle" maxlength="2000" value="${receptionEscape(line.observation)}"></label>
            </div>
            <p class="material-reception-package-summary">${line.originalPackages.length && !line.packagesDirty ? `${line.originalPackages.length} bultos existentes; se conservarán mientras no cambies su distribución.` : packageSummary(line)}</p>
        </article>
    `;
    }).join('');
}

function populateReceptionForm(record = null) {
    receptionElements.form.reset();
    receptionState.current = record;
    receptionState.lines = (record?.detalles || []).map(receptionLineState);
    if (!receptionState.lines.length) receptionState.lines = [receptionLineState()];
    receptionElements.form.elements.cliente_id.value = record?.cliente?.id || '';
    renderReceptionHeaderOptions();
    receptionElements.form.elements.proveedor_material_id.value = record?.proveedor?.id || '';
    receptionElements.form.elements.numero_guia_despacho.value = record?.numero_guia_despacho || '';
    receptionElements.form.elements.fecha_documento.value = record?.fecha_documento || '';
    receptionElements.form.elements.orden_compra.value = record?.orden_compra || '';
    receptionElements.form.elements.patente.value = record?.patente || '';
    receptionElements.form.elements.transportista.value = record?.transportista || '';
    receptionElements.form.elements.observacion.value = record?.observacion || '';
    receptionElements.form.elements.motivo_correccion.value = '';
    renderReceptionLines();
}

function configureReceptionDialog() {
    const readonly = receptionState.mode === 'view';
    const editing = receptionState.mode === 'edit';
    const confirmed = receptionState.current?.estado === 'confirmada';
    receptionElements.dialogEyebrow.textContent = readonly ? 'EXPEDIENTE DE RECEPCIÓN' : editing ? 'CORRECCIÓN ADMINISTRATIVA' : 'NUEVA RECEPCIÓN';
    receptionElements.dialogTitle.textContent = receptionState.current
        ? `Guía ${receptionState.current.numero_guia_despacho}`
        : 'Registrar recepción';
    receptionElements.dialogHelp.textContent = readonly
        ? `${receptionStatus(receptionState.current.estado)} · versión ${receptionState.current.version}`
        : 'Los folios se asignan automáticamente al confirmar.';
    receptionElements.form.classList.toggle('material-reception-readonly', readonly);
    receptionElements.addLine.classList.toggle('is-hidden', readonly);
    window.estibaMaterialReceptionImporter?.setVisible(!readonly);
    receptionElements.reason.classList.toggle('is-hidden', !editing);
    receptionElements.warning.classList.toggle('is-hidden', !(editing && confirmed));
    receptionElements.saveDraft.classList.toggle('is-hidden', readonly || confirmed);
    receptionElements.saveConfirm.classList.toggle('is-hidden', readonly);
    receptionElements.saveConfirm.textContent = confirmed ? 'Guardar corrección' : 'Guardar y confirmar';
    receptionElements.saveConfirm.disabled = !confirmed && !receptionState.current?.fotos?.some((f) => f.tipo === 'documento');
}

async function openReception(id = null, mode = 'view') {
    receptionElements.formError.textContent = '';
    try {
        await loadReceptionCatalogs();
        const response = id ? await receptionApi(`/api/materiales/recepciones/${id}`) : null;
        receptionState.mode = mode;
        populateReceptionForm(response?.data || null);
        configureReceptionDialog();
        void photosPanel?.render();
        receptionElements.dialog.showModal();
    } catch (error) {
        receptionElements.error.textContent = error.message;
    }
}

function syncLineFromElement(article, markDirty = true) {
    const line = receptionState.lines.find((candidate) => candidate.key === article.dataset.lineKey);
    if (!line) return;
    line.itemId = article.querySelector('[name="item_material_id"]').value;
    line.documentary = article.querySelector('[name="cantidad_documental"]').value;
    line.counted = article.querySelector('[name="cantidad_contada"]').value;
    line.accepted = article.querySelector('[name="cantidad_aceptada"]').value;
    line.rejected = article.querySelector('[name="cantidad_rechazada"]').value;
    line.packageSize = article.querySelector('[name="tamano_bulto"]').value;
    line.lot = article.querySelector('[name="lote_proveedor"]').value;
    line.manufacturedAt = article.querySelector('[name="fecha_fabricacion"]').value;
    line.expiresAt = article.querySelector('[name="fecha_vencimiento"]').value;
    line.blocked = article.querySelector('[name="bloqueado"]').value === '1';
    line.blockReason = article.querySelector('[name="motivo_bloqueo"]').value;
    line.observation = article.querySelector('[name="observacion_detalle"]').value;
    if (markDirty) line.packagesDirty = true;
    article.querySelector('.material-reception-package-summary').textContent = packageSummary(line);
}

function generatedPackages(line) {
    if (!line.packagesDirty && line.originalPackages.length) return line.originalPackages;
    const accepted = Number(line.accepted || 0);
    const packageSize = Number(line.packageSize || 0);
    if (accepted === 0) return [];
    if (packageSize <= 0) throw new Error('Indica cuántas unidades contiene cada bulto.');
    const total = Math.ceil(accepted / packageSize);
    if (total > 500) throw new Error('Cada producto puede generar como máximo 500 bultos.');
    return Array.from({ length: total }, (_, index) => {
        const quantity = index === total - 1
            ? Math.round((accepted - (packageSize * index)) * 1000) / 1000
            : packageSize;
        return {
            cantidad: quantity,
            lote_proveedor: line.lot || null,
            fecha_fabricacion: line.manufacturedAt || null,
            fecha_vencimiento: line.expiresAt || null,
            bloqueado: line.blocked,
            motivo_bloqueo: line.blocked ? line.blockReason || null : null,
        };
    });
}

function receptionPayload() {
    if (receptionState.lines.some((line) => !line.itemId)) {
        throw new Error('Selecciona un producto válido en cada línea de la recepción.');
    }
    receptionElements.lines.querySelectorAll('.material-reception-line')
        .forEach((article) => syncLineFromElement(article, false));
    const form = new FormData(receptionElements.form);
    return {
        operacion_id: receptionUuid(),
        cliente_id: form.get('cliente_id'),
        proveedor_material_id: form.get('proveedor_material_id'),
        numero_guia_despacho: form.get('numero_guia_despacho'),
        fecha_documento: form.get('fecha_documento') || null,
        orden_compra: form.get('orden_compra') || null,
        patente: form.get('patente') || null,
        transportista: form.get('transportista') || null,
        observacion: form.get('observacion') || null,
        detalles: receptionState.lines.map((line) => ({
            item_material_id: line.itemId,
            cantidad_documental: Number(line.documentary),
            cantidad_contada: Number(line.counted),
            cantidad_aceptada: Number(line.accepted),
            cantidad_rechazada: Number(line.rejected),
            observacion: line.observation || null,
            bultos: generatedPackages(line),
        })),
    };
}

async function saveReception(confirm) {
    if (receptionState.mode === 'view') return;
    receptionElements.formError.textContent = '';
    const payload = receptionPayload();
    const editing = receptionState.mode === 'edit';
    const confirmed = receptionState.current?.estado === 'confirmada';
    let response;

    if (!editing) {
        response = await receptionApi('/api/materiales/recepciones', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
    } else {
        payload.version_conocida = receptionState.current.version;
        payload.motivo_correccion = receptionElements.form.elements.motivo_correccion.value;
        if (confirmed) payload.confirmacion_operacion_id = receptionUuid();
        response = await receptionApi(
            `/api/materiales/recepciones/${receptionState.current.id}/administrar`,
            { method: 'PUT', body: JSON.stringify(payload) },
        );
    }

    if (confirm && response.data.estado === 'borrador') {
        response = await receptionApi(
            `/api/materiales/recepciones/${response.data.id}/confirmar`,
            {
                method: 'POST',
                body: JSON.stringify({
                    operacion_id: receptionUuid(),
                    version_conocida: response.data.version,
                }),
            },
        );
    }

    if (response.data.estado === 'borrador') {
        receptionState.mode = 'view';
        populateReceptionForm(response.data); configureReceptionDialog(); await photosPanel?.render();
        receptionElements.photos?.scrollIntoView({ block: 'center' });
    } else { receptionElements.dialog.close(); }
    await loadReceptions(receptionState.page);
}

function openReceptionDelete(record) {
    receptionState.current = record;
    receptionElements.deleteError.textContent = '';
    receptionElements.deleteForm.reset();
    receptionElements.deleteSummary.textContent = `Guía ${record.numero_guia_despacho} · ${receptionStatus(record.estado)} · ${receptionFolioCount(record)} folios.`;
    receptionElements.deleteDialog.showModal();
}

async function deleteReception() {
    receptionElements.deleteError.textContent = '';
    const form = new FormData(receptionElements.deleteForm);
    await receptionApi(`/api/materiales/recepciones/${receptionState.current.id}`, {
        method: 'DELETE',
        body: JSON.stringify({
            operacion_id: receptionUuid(),
            version_conocida: receptionState.current.version,
            motivo: form.get('motivo'),
        }),
    });
    receptionElements.deleteDialog.close();
    await loadReceptions(receptionState.page);
}

window.estibaMaterialReceptionImportContext = {
    context() {
        return {
            clienteId: receptionClientId(),
            proveedorId: receptionProviderId(),
            mode: receptionState.mode,
        };
    },
    apply(rows, replace = true) {
        if (receptionState.mode === 'view') {
            throw new Error('Una recepción en modo consulta no puede modificarse.');
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            throw new Error('La previsualización no contiene productos válidos.');
        }

        const importedLines = rows.map((row) => receptionLineState(row));
        const currentLines = receptionState.lines.filter((line) =>
            line.itemId || line.documentary || line.counted || line.accepted || line.packageSize);
        const resultingLines = replace ? importedLines : [...currentLines, ...importedLines];
        if (resultingLines.length > 100) {
            throw new Error('Una recepción puede contener como máximo 100 productos.');
        }

        receptionState.lines = resultingLines;
        renderReceptionLines();

        return importedLines.length;
    },
};

if (receptionElements.workspace) {
    receptionElements.reload.addEventListener('click', () => loadReceptions(receptionState.page));
    receptionElements.blankSampling.addEventListener('click', async () => {
        try {
            await downloadReceptionSampling();
        } catch (error) {
            receptionElements.error.textContent = error.message;
        }
    });
    receptionElements.create.addEventListener('click', () => openReception(null, 'create'));
    receptionElements.filters.addEventListener('submit', (event) => {
        event.preventDefault();
        loadReceptions(1);
    });
    receptionElements.previous.addEventListener('click', () => loadReceptions(receptionState.page - 1));
    receptionElements.next.addEventListener('click', () => loadReceptions(receptionState.page + 1));
    receptionElements.list.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const record = receptionState.records.find((item) => item.id === button.dataset.id);
        if (button.dataset.action === 'view') openReception(record.id, 'view');
        if (button.dataset.action === 'sampling') {
            try {
                await downloadReceptionSampling(record);
            } catch (error) {
                receptionElements.error.textContent = error.message;
            }
        }
        if (button.dataset.action === 'edit' && receptionCanAdminister()) openReception(record.id, 'edit');
        if (button.dataset.action === 'delete' && receptionCanAdminister()) openReceptionDelete(record);
    });
    receptionElements.form.elements.cliente_id.addEventListener('change', () => {
        renderReceptionHeaderOptions();
        receptionState.lines.forEach((line) => {
            line.itemId = '';
            line.itemSnapshot = null;
        });
        renderReceptionLines();
    });
    receptionElements.form.elements.proveedor_material_id.addEventListener('change', () => {
        receptionState.lines.forEach((line) => {
            line.itemId = '';
            line.itemSnapshot = null;
        });
        renderReceptionLines();
    });
    receptionElements.addLine.addEventListener('click', () => {
        receptionState.lines.push(receptionLineState());
        renderReceptionLines();
    });
    receptionElements.lines.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-line]');
        if (button) {
            receptionState.lines = receptionState.lines.filter((line) => line.key !== button.dataset.removeLine);
            if (!receptionState.lines.length) receptionState.lines.push(receptionLineState());
            renderReceptionLines();
            return;
        }
        const option = event.target.closest('[data-select-reception-item]');
        if (!option) return;
        const article = option.closest('.material-reception-line');
        const line = receptionState.lines.find((candidate) => candidate.key === article.dataset.lineKey);
        const item = receptionState.catalogs.items.find((candidate) => candidate.id === option.dataset.selectReceptionItem);
        if (!line || !item) return;
        line.itemId = item.id;
        line.itemSnapshot = item;
        article.querySelector('[name="item_material_id"]').value = item.id;
        article.querySelector('.material-item-search').value = receptionItemLabel(item);
        closeReceptionItemResults();
        article.querySelector('[name="cantidad_documental"]').focus();
    });
    receptionElements.lines.addEventListener('focusin', (event) => {
        const input = event.target.closest('.material-item-search');
        if (!input || receptionState.mode === 'view') return;
        const article = input.closest('.material-reception-line');
        const results = article.querySelector('.material-item-results');
        closeReceptionItemResults(results);
        renderReceptionItemResults(article);
    });
    receptionElements.lines.addEventListener('input', (event) => {
        const article = event.target.closest('.material-reception-line');
        const packageFields = new Set([
            'cantidad_aceptada',
            'tamano_bulto',
            'lote_proveedor',
            'fecha_fabricacion',
            'fecha_vencimiento',
            'bloqueado',
            'motivo_bloqueo',
        ]);
        if (article && receptionState.mode !== 'view') {
            if (event.target.matches('.material-item-search')) {
                const line = receptionState.lines.find((candidate) => candidate.key === article.dataset.lineKey);
                line.itemId = '';
                line.itemSnapshot = null;
                article.querySelector('[name="item_material_id"]').value = '';
                renderReceptionItemResults(article);
            }
            syncLineFromElement(article, packageFields.has(event.target.name));
        }
    });
    receptionElements.lines.addEventListener('change', (event) => {
        const article = event.target.closest('.material-reception-line');
        const packageFields = new Set([
            'cantidad_aceptada',
            'tamano_bulto',
            'lote_proveedor',
            'fecha_fabricacion',
            'fecha_vencimiento',
            'bloqueado',
            'motivo_bloqueo',
        ]);
        if (article && receptionState.mode !== 'view') {
            if (event.target.matches('.material-reception-item-filter')) {
                const line = receptionState.lines.find((candidate) => candidate.key === article.dataset.lineKey);
                line.itemFilter = event.target.value;
                renderReceptionItemResults(article);
            }
            syncLineFromElement(article, packageFields.has(event.target.name));
        }
    });
    receptionElements.lines.addEventListener('keydown', (event) => {
        if (!event.target.matches('.material-item-search')) return;
        if (event.key === 'Escape') {
            closeReceptionItemResults();
            event.target.blur();
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            const first = event.target.closest('.material-item-picker').querySelector('[data-select-reception-item]');
            if (first) {
                first.click();
            }
        }
    });
    receptionElements.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const confirmed = receptionState.current?.estado === 'confirmada';
            await saveReception(confirmed || event.submitter?.value === 'confirm');
        } catch (error) {
            receptionElements.formError.textContent = error.message;
        }
    });
    [receptionElements.close, receptionElements.cancel].forEach((button) =>
        button.addEventListener('click', () => receptionElements.dialog.close()));
    receptionElements.deleteForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await deleteReception();
        } catch (error) {
            receptionElements.deleteError.textContent = error.message;
        }
    });
    [receptionElements.deleteClose, receptionElements.deleteCancel].forEach((button) =>
        button.addEventListener('click', () => receptionElements.deleteDialog.close()));
    window.addEventListener('estiba:office-session', () => {
        photosPanel?.reset(); receptionElements.dialog.close(); receptionState.current = null;
        receptionElements.eliminations?.replaceChildren(); receptionElements.eliminations?.classList.add('is-hidden');
        void loadReceptions(1);
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.material-reception-item-picker')) closeReceptionItemResults();
    });
    loadReceptions(1);
}
