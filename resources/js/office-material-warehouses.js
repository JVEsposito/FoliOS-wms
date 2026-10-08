const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const state = {
    token: localStorage.getItem(tokenKey),
    identity: null,
    data: null,
    kardex: [],
    tab: 'bodega',
};
const $ = (id) => document.getElementById(id);
class ApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
const qty = (value) => new Intl.NumberFormat('es-CL', {
    maximumFractionDigits: 3,
}).format(Number(value || 0));
const dateTime = (value) => value
    ? new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value))
    : '—';
const uuid = () => crypto.randomUUID
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.random() * 16 | 0;
        return (character === 'x' ? random : (random & 3 | 8)).toString(16);
    });

try {
    state.identity = JSON.parse(localStorage.getItem(identityKey) || 'null');
} catch {
    state.identity = null;
}

function capabilities() {
    return {
        ...(state.identity?.capacidades || {}),
        ...(state.identity || {}),
    };
}

function can(permission) {
    if (state.identity?.rol === 'administrador') return true;

    return capabilities()[permission] === true;
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
        throw new ApiError('No fue posible conectar con Laravel.');
    }
    const data = response.status === 204
        ? null
        : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new ApiError(
            Object.values(data?.errors || {}).flat()[0]
            || data?.message
            || 'No fue posible completar la operación.',
            response.status,
        );
    }
    return data;
}

function redirectToMaterialsAccess() {
    window.location.replace('/oficina/materiales');
}

function clearSession() {
    state.token = null;
    state.identity = null;
    localStorage.removeItem(tokenKey);
    localStorage.removeItem(identityKey);
    window.dispatchEvent(new CustomEvent('estiba:office-session', {
        detail: { authenticated: false },
    }));
    redirectToMaterialsAccess();
}

function handleAuthenticationError(error) {
    if (error instanceof ApiError && error.status === 401) {
        clearSession();
        return true;
    }

    return false;
}

function showApp() {
    $('custodyApp').classList.remove('is-hidden');
    const name = state.identity?.nombre || state.identity?.name || 'Usuario';
    const userName = $('officeUserName');
    if (userName) userName.textContent = name;
    const role = $('officeUserRole');
    if (role) role.textContent = String(state.identity?.rol || 'Oficina').replaceAll('_', ' ');
    const initials = $('officeInitials');
    if (initials) {
        initials.textContent = name.split(/\s+/).slice(0, 2)
            .map((part) => part[0]).join('').toUpperCase();
    }
    const logout = $('officeLogoutButton');
    if (logout) {
        logout.onclick = async () => {
            logout.disabled = true;
            try {
                await api('/api/acceso-oficina', { method: 'DELETE' });
            } finally {
                clearSession();
            }
        };
    }
}

async function load() {
    const puedeConsultarKardex = can('puede_consultar_kardex_materiales');
    const [data, movements] = await Promise.all([
        api('/api/materiales/almacenes'),
        puedeConsultarKardex
            ? api('/api/materiales/almacenes/movimientos?limite=100')
            : Promise.resolve({ data: [] }),
    ]);
    state.data = data;
    state.kardex = movements.data || [];
    renderFilterOptions();
    render();
}

function render() {
    const puedeGestionar = can('puede_gestionar_despachos_materiales');
    const puedeAjustar = can('puede_gestionar_bloqueos_materiales');
    const puedeConsultarKardex = can('puede_consultar_kardex_materiales');

    $('custodyMovementPanel').classList.toggle(
        'is-hidden',
        !puedeGestionar && !puedeAjustar,
    );
    $('custodyKardexPanel').classList.toggle('is-hidden', !puedeConsultarKardex);
    $('custodyFolioCount').textContent = state.data.resumen.folios;
    $('custodyWarehouseCount').textContent = state.data.resumen.almacenes;
    $('custodyItemCount').textContent = state.data.resumen.items;
    configureActions(puedeGestionar, puedeAjustar);
    renderTable();
    if (puedeGestionar || puedeAjustar) renderSelectors();
    if (puedeConsultarKardex) renderKardex();
}

function configureActions(puedeGestionar, puedeAjustar) {
    const select = $('custodyMovementForm').elements.tipo;

    [...select.options].forEach((option) => {
        const permitida = option.value === 'ajuste'
            ? puedeAjustar
            : puedeGestionar;
        option.disabled = !permitida;
        option.hidden = !permitida;
    });

    if (select.selectedOptions[0]?.disabled) {
        const primeraPermitida = [...select.options].find((option) => !option.disabled);
        if (primeraPermitida) select.value = primeraPermitida.value;
    }
}

function uniqueOptions(rows, key, label) {
    return [...new Map(rows
        .filter((row) => key(row))
        .map((row) => [key(row), label(row)])).entries()]
        .sort((left, right) => left[1].localeCompare(right[1], 'es'));
}

function setSelectOptions(select, options, placeholder) {
    const selected = select.value;
    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`
        + options.map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');
    if (options.some(([value]) => value === selected)) select.value = selected;
}

function renderFilterOptions() {
    if (!state.data) return;
    const balanceRows = [
        ...state.data.perspectivas.bodega,
        ...state.data.perspectivas.centros_costo,
    ];
    const allRows = [...balanceRows, ...state.data.perspectivas.total_empresa];
    const form = $('custodyFilters');

    setSelectOptions(
        form.elements.cliente_id,
        uniqueOptions(
            allRows,
            (row) => row.cliente?.id,
            (row) => `${row.cliente.codigo} · ${row.cliente.nombre}`,
        ),
        'Todos los clientes',
    );
    setSelectOptions(
        form.elements.item_id,
        uniqueOptions(
            allRows,
            (row) => row.item?.id,
            (row) => `${row.item.codigo} · ${row.item.nombre}`,
        ),
        'Todos los ítems',
    );
    setSelectOptions(
        form.elements.almacen_id,
        uniqueOptions(
            balanceRows,
            (row) => row.almacen?.id,
            (row) => `${row.almacen.codigo} · ${row.almacen.nombre}${row.almacen.centro_costo ? ` · ${row.almacen.centro_costo}` : ''}`,
        ),
        'Todos los almacenes',
    );
    setSelectOptions(
        form.elements.camara_id,
        uniqueOptions(
            state.data.perspectivas.bodega,
            (row) => row.camara?.id,
            (row) => `${row.camara.codigo} · ${row.camara.nombre}`,
        ),
        'Todas las cámaras',
    );
    renderFilterVisibility();
}

function normalize(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es-CL')
        .trim();
}

function searchableValues(row) {
    if (state.tab === 'total_empresa') {
        return [
            row.cliente?.codigo,
            row.cliente?.nombre,
            row.item?.codigo,
            row.item?.nombre,
            row.unidad_medida,
            row.en_bodega,
            qty(row.en_bodega),
            row.en_centros_costo,
            qty(row.en_centros_costo),
            row.total_empresa,
            qty(row.total_empresa),
            row.folios,
        ];
    }

    return [
        row.almacen?.codigo,
        row.almacen?.nombre,
        row.almacen?.centro_costo,
        row.cliente?.codigo,
        row.cliente?.nombre,
        row.item?.codigo,
        row.item?.nombre,
        row.numero_folio,
        row.lote,
        row.unidad_medida,
        row.cantidad_actual,
        qty(row.cantidad_actual),
        row.cantidad_reservada,
        qty(row.cantidad_reservada),
        row.cantidad_disponible,
        qty(row.cantidad_disponible),
        row.camara?.codigo,
        row.camara?.nombre,
        row.posicion?.etiqueta,
        row.bloqueado ? 'bloqueado' : 'disponible',
    ];
}

function visibleRows() {
    const rows = state.data?.perspectivas?.[state.tab] || [];
    const form = $('custodyFilters');
    const query = normalize(form.elements.q.value);
    const clientId = form.elements.cliente_id.value;
    const itemId = form.elements.item_id.value;
    const warehouseId = form.elements.almacen_id.value;
    const cameraId = form.elements.camara_id.value;

    return rows.filter((row) => {
        if (clientId && row.cliente?.id !== clientId) return false;
        if (itemId && row.item?.id !== itemId) return false;
        if (warehouseId && row.almacen?.id !== warehouseId) return false;
        if (cameraId && row.camara?.id !== cameraId) return false;
        if (!query) return true;

        return normalize(searchableValues(row).join(' ')).includes(query);
    });
}

function renderFilterVisibility() {
    const warehouseFilter = document.querySelector('[data-custody-filter-warehouse]');
    const cameraFilter = document.querySelector('[data-custody-filter-camera]');
    const form = $('custodyFilters');
    const total = state.tab === 'total_empresa';
    const centers = state.tab === 'centros_costo';

    warehouseFilter.classList.toggle('is-hidden', total);
    cameraFilter.classList.toggle('is-hidden', total || centers);
    if (total) form.elements.almacen_id.value = '';
    if (total || centers) form.elements.camara_id.value = '';
}

function renderTable() {
    const allRows = state.data.perspectivas[state.tab] || [];
    const rows = visibleRows();
    $('custodyResultsSummary').textContent = `Mostrando ${rows.length} de ${allRows.length} registros`;
    $('custodyExport').disabled = rows.length === 0;

    if (state.tab === 'total_empresa') {
        $('custodyTableHead').innerHTML = '<tr><th>Cliente</th><th>Ítem</th><th>En Bodega</th><th>En centros de costo</th><th>Total empresa</th><th>Folios</th></tr>';
        $('custodyTableBody').innerHTML = rows.map((row) => `<tr>
            <td>${escapeHtml(row.cliente.codigo)} · ${escapeHtml(row.cliente.nombre)}</td>
            <td><strong>${escapeHtml(row.item.codigo)}</strong><br>${escapeHtml(row.item.nombre)}</td>
            <td>${qty(row.en_bodega)} ${escapeHtml(row.unidad_medida)}</td>
            <td>${qty(row.en_centros_costo)} ${escapeHtml(row.unidad_medida)}</td>
            <td><strong>${qty(row.total_empresa)} ${escapeHtml(row.unidad_medida)}</strong></td>
            <td>${row.folios}</td>
        </tr>`).join('') || '<tr><td colspan="6">Sin resultados para los filtros seleccionados.</td></tr>';
        return;
    }

    const virtual = state.tab === 'centros_costo';
    $('custodyTableHead').innerHTML = `<tr><th>${virtual ? 'Centro de costo / almacén' : 'Almacén'}</th><th>Cliente</th><th>Ítem</th><th>Folio / lote</th><th>Cantidad</th><th>Reservada</th><th>Disponible</th>${virtual ? '' : '<th>Cámara / posición</th>'}</tr>`;
    $('custodyTableBody').innerHTML = rows.map((row) => `<tr>
        <td><strong>${escapeHtml(row.almacen.nombre)}</strong><br>${escapeHtml(row.almacen.centro_costo || row.almacen.codigo || '—')}</td>
        <td>${escapeHtml(row.cliente.codigo)} · ${escapeHtml(row.cliente.nombre)}</td>
        <td><strong>${escapeHtml(row.item.codigo)}</strong><br>${escapeHtml(row.item.nombre)}</td>
        <td>${escapeHtml(row.numero_folio)}${row.vencimiento?.etiqueta ? `<br><strong>${escapeHtml(row.vencimiento.etiqueta)}</strong>` : ''}<br>${escapeHtml(row.lote || 'Sin lote')}</td>
        <td>${qty(row.cantidad_actual)} ${escapeHtml(row.unidad_medida)}</td>
        <td>${qty(row.cantidad_reservada)}</td>
        <td><strong>${qty(row.cantidad_disponible)}</strong></td>
        ${virtual ? '' : `<td>${escapeHtml(row.camara?.codigo || 'Pendiente')}<br>${escapeHtml(row.posicion?.etiqueta || 'Sin posición')}</td>`}
    </tr>`).join('') || `<tr><td colspan="${virtual ? 7 : 8}">Sin resultados para los filtros seleccionados.</td></tr>`;
}

function exportParameters() {
    const form = $('custodyFilters');
    const params = new URLSearchParams({ perspectiva: state.tab });
    for (const name of ['q', 'cliente_id', 'item_id', 'almacen_id', 'camara_id']) {
        const value = form.elements[name].value.trim();
        if (value) params.set(name, value);
    }
    return params;
}

async function exportInventory() {
    const button = $('custodyExport');
    const error = $('custodyFilterError');
    error.textContent = '';
    button.disabled = true;
    try {
        const response = await fetch(`/api/materiales/almacenes/exportar?${exportParameters()}`, {
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                Authorization: `Bearer ${state.token}`,
            },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new ApiError(
                data.message || 'No fue posible exportar Inventario CC.',
                response.status,
            );
        }
        const blob = await response.blob();
        const disposition = response.headers.get('content-disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const fileName = match?.[1] || `Inventario_CC_${state.tab}.xlsx`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (downloadError) {
        if (handleAuthenticationError(downloadError)) return;
        error.textContent = downloadError.message;
    } finally {
        button.disabled = visibleRows().length === 0;
    }
}

function historyExportParameters() {
    const form = $('custodyHistoryFilters');
    const params = new URLSearchParams({ categoria: form.elements.categoria.value });
    for (const name of ['desde', 'hasta']) {
        const value = form.elements[name].value;
        if (value) params.set(name, value);
    }

    return params;
}

async function exportHistory() {
    const form = $('custodyHistoryFilters');
    const button = $('custodyHistoryExport');
    const error = $('custodyHistoryError');
    error.textContent = '';

    if (form.elements.desde.value
        && form.elements.hasta.value
        && form.elements.desde.value > form.elements.hasta.value) {
        error.textContent = 'La fecha desde no puede ser posterior a la fecha hasta.';
        return;
    }

    button.disabled = true;
    try {
        const response = await fetch(`/api/materiales/almacenes/movimientos/exportar?${historyExportParameters()}`, {
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                Authorization: `Bearer ${state.token}`,
            },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new ApiError(
                Object.values(data?.errors || {}).flat()[0]
                || data.message
                || 'No fue posible exportar el historial de Inventario CC.',
                response.status,
            );
        }
        const blob = await response.blob();
        const disposition = response.headers.get('content-disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const fileName = match?.[1] || `Inventario_CC_Historial_${form.elements.categoria.value}.xlsx`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (downloadError) {
        if (handleAuthenticationError(downloadError)) return;
        error.textContent = downloadError.message;
    } finally {
        button.disabled = false;
    }
}

function movementRows() {
    const warehouses = new Map(
        (state.data?.almacenes || []).map((warehouse) => [warehouse.id, warehouse]),
    );

    return [
        ...(state.data?.perspectivas?.bodega || []),
        ...(state.data?.perspectivas?.centros_costo || []),
    ].filter((row) => {
        const warehouse = warehouses.get(row.almacen?.id);
        const hasOperationalLocation = warehouse?.tipo === 'virtual'
            || !warehouse?.requiere_ubicacion_fisica
            || Boolean(row.camara?.id);

        return Number(row.cantidad_disponible) > 0
            && row.bloqueado !== true
            && hasOperationalLocation;
    });
}

function warehouseLabel(warehouse) {
    return [
        warehouse.codigo,
        warehouse.nombre,
        warehouse.centro_costo,
    ].filter(Boolean).join(' · ');
}

function renderSelectors() {
    const form = $('custodyMovementForm');
    const type = form.elements.tipo.value;
    const eligibleRows = movementRows().filter((row) => (
        type !== 'consumo' || row.almacen?.tipo === 'virtual'
    ));
    const availableWarehouseIds = new Set(
        eligibleRows.map((row) => row.almacen.id),
    );
    const originWarehouses = (state.data.almacenes || [])
        .filter((warehouse) => availableWarehouseIds.has(warehouse.id));
    const selectedOrigin = form.elements.almacen_origen_id.value;

    form.elements.almacen_origen_id.innerHTML = '<option value="">Seleccione el almacén de origen</option>'
        + originWarehouses.map((warehouse) => `<option value="${warehouse.id}">${escapeHtml(warehouseLabel(warehouse))}</option>`).join('');
    if (originWarehouses.some((warehouse) => warehouse.id === selectedOrigin)) {
        form.elements.almacen_origen_id.value = selectedOrigin;
    }

    form.elements.camara_destino_id.innerHTML = '<option value="">No aplica / conservar</option>'
        + (state.data.camaras || []).map((row) => `<option value="${row.id}">${escapeHtml(row.codigo)} · ${escapeHtml(row.nombre)}</option>`).join('');
    renderFoliosForOrigin();
    renderDestinationWarehouses();
    renderPositions();
}

function renderFoliosForOrigin() {
    const form = $('custodyMovementForm');
    const originId = form.elements.almacen_origen_id.value;
    const previousFolio = form.elements.folio_id.value;
    const rows = movementRows()
        .filter((row) => form.elements.tipo.value !== 'consumo'
            || row.almacen?.tipo === 'virtual')
        .filter((row) => row.almacen.id === originId)
        .sort((left, right) => String(left.numero_folio).localeCompare(
            String(right.numero_folio),
            'es',
            { numeric: true },
        ));
    const placeholder = originId
        ? (rows.length
            ? 'Seleccione un folio disponible'
            : 'Sin folios disponibles en este almacén')
        : 'Seleccione primero el almacén de origen';

    form.elements.folio_id.innerHTML = `<option value="">${placeholder}</option>`
        + rows.map((row) => {
            const location = row.almacen?.tipo === 'virtual'
                ? (row.almacen.centro_costo || 'Centro de costo')
                : [row.camara?.codigo, row.posicion?.etiqueta].filter(Boolean).join(' · ');
            const label = [
                row.numero_folio,
                row.item.codigo,
                row.item.nombre,
                `Disponible: ${qty(row.cantidad_disponible)} ${row.unidad_medida}`,
                location,
            ].filter(Boolean).join(' · ');

            return `<option value="${row.folio_id}">${escapeHtml(label)}</option>`;
        }).join('');
    form.elements.folio_id.disabled = !originId || rows.length === 0;

    if (rows.some((row) => row.folio_id === previousFolio)) {
        form.elements.folio_id.value = previousFolio;
    }

    const selected = rows.find((row) => row.folio_id === form.elements.folio_id.value);
    form.elements.cantidad.max = selected ? Number(selected.cantidad_disponible) : '';
    form.elements.cantidad.placeholder = selected
        ? `Máximo disponible: ${qty(selected.cantidad_disponible)}`
        : 'Seleccione un folio';

    $('custodyOriginSummary').textContent = originId
        ? `${rows.length} folio(s) con saldo operacional disponible en el almacén seleccionado.`
        : 'Seleccione un almacén para consultar únicamente su existencia disponible.';
}

function renderDestinationWarehouses() {
    const form = $('custodyMovementForm');
    const type = form.elements.tipo.value;
    const originId = form.elements.almacen_origen_id.value;
    const requiresDestination = ['devolucion', 'transferencia'].includes(type);
    const warehouses = (state.data.almacenes || []).filter((warehouse) => (
        warehouse.id !== originId
        && (type !== 'devolucion' || warehouse.tipo === 'fisica')
    ));

    form.elements.almacen_destino_id.innerHTML = '<option value="">'
        + (requiresDestination ? 'Seleccione el almacén destino' : 'No aplica')
        + '</option>'
        + warehouses.map((warehouse) => `<option value="${warehouse.id}">${escapeHtml(warehouseLabel(warehouse))}</option>`).join('');
    form.elements.almacen_destino_id.disabled = !requiresDestination;
    form.elements.almacen_destino_id.required = requiresDestination;
}

function renderPositions() {
    const form = $('custodyMovementForm');
    const camera = (state.data?.camaras || [])
        .find((row) => row.id === form.elements.camara_destino_id.value);
    form.elements.posicion_destino_id.innerHTML = '<option value="">Sin posición exacta</option>'
        + (camera?.posiciones || []).map((row) => `<option value="${row.id}">${escapeHtml(row.etiqueta)}</option>`).join('');
}

function renderKardex() {
    $('custodyKardex').innerHTML = state.kardex.map((row) => `<article class="custody-event">
        <strong>${escapeHtml(row.tipo.replaceAll('_', ' '))} · ${escapeHtml(row.folio.numero_folio)} · ${qty(row.cantidad)}</strong>
        <span>${escapeHtml(row.almacen_origen?.nombre || 'Empresa')} → ${escapeHtml(row.almacen_destino?.nombre || 'Consumo / ajuste')}</span>
        <small>${escapeHtml(row.item.codigo)} · ${escapeHtml(row.centro_costo || 'Sin centro de costo')} · ${dateTime(row.ocurrido_at)}</small>
        <small>${escapeHtml(row.motivo || '')}</small>
    </article>`).join('') || '<p>Sin movimientos distribuidos registrados.</p>';
}

document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
    state.tab = button.dataset.tab;
    document.querySelectorAll('[data-tab]').forEach((candidate) => {
        candidate.classList.toggle('is-active', candidate === button);
    });
    renderFilterVisibility();
    renderTable();
}));
$('custodyFilters').addEventListener('input', renderTable);
$('custodyFilters').addEventListener('change', renderTable);
$('custodyFiltersReset').addEventListener('click', () => {
    $('custodyFilters').reset();
    renderTable();
});
$('custodyExport').addEventListener('click', exportInventory);
$('custodyHistoryExport').addEventListener('click', exportHistory);
$('custodyReload').addEventListener('click', () => {
    load().catch((error) => {
        if (handleAuthenticationError(error)) return;
        $('custodyFilterError').textContent = error.message;
    });
});
$('custodyMovementForm').elements.tipo.addEventListener('change', renderSelectors);
$('custodyMovementForm').elements.almacen_origen_id.addEventListener('change', () => {
    renderFoliosForOrigin();
    renderDestinationWarehouses();
});
$('custodyMovementForm').elements.folio_id.addEventListener('change', renderFoliosForOrigin);
$('custodyMovementForm').elements.camara_destino_id.addEventListener('change', renderPositions);
$('custodyMovementForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    $('custodyMovementError').textContent = '';
    const movementForm = event.currentTarget;
    const submitButton = event.submitter
        ?? movementForm.querySelector('button[type="submit"]');
    const form = new FormData(movementForm);
    const payload = Object.fromEntries(form.entries());
    payload.operacion_id = uuid();
    Object.keys(payload).forEach((key) => {
        if (payload[key] === '') delete payload[key];
    });
    if (submitButton) submitButton.disabled = true;
    try {
        await api('/api/materiales/almacenes/movimientos', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        movementForm.elements.cantidad.value = '';
        movementForm.elements.motivo.value = '';
        await load();
    } catch (error) {
        if (handleAuthenticationError(error)) return;
        $('custodyMovementError').textContent = error.message;
    } finally {
        if (submitButton) submitButton.disabled = false;
    }
});

async function boot() {
    if (!state.token || !state.identity) {
        redirectToMaterialsAccess();
        return;
    }

    showApp();
    try {
        await load();
    } catch (error) {
        if (handleAuthenticationError(error)) return;
        $('custodyFilterError').textContent = error.message;
    }
}

void boot();
