import { createUuid as orderUuid } from './shared/uuid.js';
import { createOperationalPoller } from './shared/operational-poller';
import { createOfficeSessionSync } from './shared/office-session-sync';

const orderTokenKey = 'estiba_wms_office_token';
const orderIdentityKey = 'estiba_wms_office_identity';

const orderState = {
    token: null,
    identity: null,
    recipes: [],
    orders: [],
    // Tarja de armado: si la bodega tiene código, la línea es la máquina y el turno es 01 o 02.
    armado: null,
    inventory: [],
    bodegas: [],
    loadedToken: null,
    loading: false,
    creationOperation: null,
    planningOperations: new Map(),
    cancellationOperations: new Map(),
    loadingDetails: new Set(),
    poller: null,
    session: null,
};

const orderElements = {};

class OrderApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.status = status;
    }
}

function orderReadJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function orderEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function orderQuantity(value, maximumFractionDigits = 3) {
    return new Intl.NumberFormat('es-CL', { maximumFractionDigits }).format(Number(value || 0));
}

function orderDate(value) {
    if (!value) return 'Sin fecha';
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    return new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' })
        .format(new Date(year, month - 1, day));
}

function orderDateTime(value) {
    if (!value) return 'Pendiente';
    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function orderToday() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 10);
}

function orderNormalize(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function orderCode(order) {
    return `OT-${String(order?.id || '').slice(0, 8).toUpperCase()}`;
}

function orderStatusLabel(value) {
    return ({
        borrador: 'Borrador',
        planificada: 'Planificada',
        en_proceso: 'En proceso',
        pendiente_cierre: 'Pendiente de cierre',
        cerrada: 'Cerrada',
        cancelada: 'Cancelada',
    })[value] || String(value || 'Sin estado').replaceAll('_', ' ');
}

function canConsultOrders() {
    if (orderState.identity?.puede_consultar_transformaciones_materiales === true) return true;
    return ['administrador', 'supervisor_materiales', 'camarero_materiales', 'despachador', 'consulta']
        .includes(orderState.identity?.rol);
}

function canManageOrders() {
    if (orderState.identity?.puede_gestionar_transformaciones_materiales === true) return true;
    return ['administrador', 'supervisor_materiales'].includes(orderState.identity?.rol);
}

function orderSectionIsActive() {
    return document.getElementById('officeApp')?.dataset.materialsSection === 'ordenes';
}

function orderErrorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

async function orderApi(path, options = {}, token = orderState.token) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (options.body) headers.set('Content-Type', 'application/json');

    let response;
    try {
        response = await fetch(path, { ...options, headers });
    } catch {
        throw new OrderApiError('No fue posible conectar con Laravel.');
    }

    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new OrderApiError(
            orderErrorMessage(data, 'No fue posible completar la operación de transformación.'),
            response.status,
        );
    }

    return data;
}

function orderToast(message, error = false) {
    const container = document.getElementById('officeToasts');
    if (!container) return;
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
    node.textContent = message;
    container.append(node);
    window.setTimeout(() => node.remove(), 4500);
}

function injectOrderPanel() {
    if (document.getElementById('materialsTransformationOrdersPanel')) return;
    const workspace = document.querySelector('.materials-workspace');
    const operations = document.querySelector('.materials-operation-grid');
    if (!workspace || !operations) return;

    const section = document.createElement('section');
    section.className = 'panel materials-panel materials-orders-panel is-hidden';
    section.id = 'materialsTransformationOrdersPanel';
    section.dataset.materialsView = 'ordenes';
    section.innerHTML = `
        <div class="materials-panel__heading">
            <div>
                <p class="eyebrow">PROGRAMACIÓN DE PRODUCCIÓN</p>
                <h2>Órdenes de transformación</h2>
                <p class="materials-help">Crea la orden, reserva sus componentes mediante FIFO y déjala disponible para ejecución en PDA/tablet.</p>
            </div>
            <div class="materials-panel__tools">
                <span id="materialsOrdersSummary">0 órdenes</span>
                <button class="secondary-button" id="reloadMaterialOrders" type="button">↻ Actualizar órdenes</button>
            </div>
        </div>
        <div class="materials-order-metrics" id="materialsOrderMetrics"></div>
        <div class="materials-orders-layout">
            <form class="materials-form materials-order-form is-hidden" id="materialOrderForm" novalidate>
                <div><p class="eyebrow">NUEVA ORDEN</p><h3>Preparar borrador</h3></div>
                <label><span>Receta y versión activa *</span><select name="version_receta_material_id" required></select></label>
                <label><span>Bodega de componentes *</span><select name="almacen_origen_id" required></select></label>
                <p class="materials-help">Las reservas y devoluciones corresponden a esta bodega. Los folios producidos ingresan a Bodega Central para su ubicación o transferencia posterior.</p>
                <div class="materials-form__grid">
                    <label><span>Cantidad planificada de salida *</span><input name="cantidad_planificada_salida" type="number" min="0.001" step="0.001" required></label>
                    <label><span>Fecha operacional *</span><input name="fecha_operacional" type="date" required></label>
                    <label><span>Referencia OT</span><input name="referencia_ot" maxlength="100" placeholder="Referencia de producción (opcional)"></label>
                    <label><span>Proceso</span><input name="proceso" maxlength="100" placeholder="Armado, embalaje…"></label>
                    <label><span>Operador físico</span><input name="operador_nombre" maxlength="150" placeholder="Persona que realiza el trabajo"></label>
                    <label data-order-free-field><span>Línea</span><input name="linea" maxlength="100" placeholder="Línea 1"></label>
                    <label data-order-free-field><span>Turno</span><input name="turno" maxlength="80" placeholder="Día / Noche"></label>
                    <label data-order-armado-field hidden><span>Máquina de armado *</span><input name="maquina" type="number" min="1" max="99" step="1" inputmode="numeric" placeholder="3" disabled></label>
                    <label data-order-armado-field hidden><span>Turno de armado *</span><select name="turno_armado" disabled></select></label>
                    <p class="materials-help materials-wide" data-order-armado-field hidden id="materialOrderArmadoHelp"></p>
                    <label class="materials-wide"><span>Observación</span><textarea name="observacion" maxlength="2000" rows="2"></textarea></label>
                </div>
                <div class="materials-order-requirements" id="materialOrderRequirements"></div>
                <p class="materials-help">La disponibilidad mostrada es referencial. La reserva definitiva se valida nuevamente al planificar la orden.</p>
                <p class="form-error" id="materialOrderError" role="alert"></p>
                <div class="materials-actions"><button class="primary-button" id="saveMaterialOrder" type="submit">Crear orden en borrador</button></div>
            </form>
            <div class="materials-order-browser">
                <div class="materials-order-filters">
                    <select id="materialOrdersStateFilter" aria-label="Filtrar órdenes por estado">
                        <option value="">Todos los estados</option>
                        <option value="borrador">Borrador</option>
                        <option value="planificada">Planificada</option>
                        <option value="en_proceso">En proceso</option>
                        <option value="pendiente_cierre">Pendiente de cierre</option>
                        <option value="cerrada">Cerrada</option>
                        <option value="cancelada">Cancelada</option>
                    </select>
                    <select id="materialOrdersClientFilter" aria-label="Filtrar órdenes por cliente"><option value="">Todos los clientes</option></select>
                    <input id="materialOrdersSearch" type="search" placeholder="Buscar orden, receta, línea o turno">
                </div>
                <div class="materials-order-list" id="materialOrdersList"></div>
            </div>
        </div>
    `;
    workspace.insertBefore(section, operations);

    Object.assign(orderElements, {
        panel: section,
        summary: document.getElementById('materialsOrdersSummary'),
        reload: document.getElementById('reloadMaterialOrders'),
        metrics: document.getElementById('materialsOrderMetrics'),
        form: document.getElementById('materialOrderForm'),
        requirements: document.getElementById('materialOrderRequirements'),
        error: document.getElementById('materialOrderError'),
        save: document.getElementById('saveMaterialOrder'),
        stateFilter: document.getElementById('materialOrdersStateFilter'),
        clientFilter: document.getElementById('materialOrdersClientFilter'),
        search: document.getElementById('materialOrdersSearch'),
        list: document.getElementById('materialOrdersList'),
    });

    orderElements.form.elements.fecha_operacional.value = orderToday();
    orderElements.reload.addEventListener('click', () => loadOrdersOffice(true));
    orderElements.form.addEventListener('submit', submitOrderForm);
    orderElements.form.addEventListener('input', () => {
        orderState.creationOperation = null;
        renderOrderRequirements();
    });
    orderElements.form.elements.version_receta_material_id.addEventListener(
        'change',
        renderOrderRequirements,
    );
    orderElements.stateFilter.addEventListener('change', renderOrders);
    orderElements.clientFilter.addEventListener('change', renderOrders);
    orderElements.search.addEventListener('input', renderOrders);
    orderElements.list.addEventListener('click', handleOrderAction);
}

const orderArmadoShifts = { '01': 'Día', '02': 'Tarde' };

function orderArmadoShiftName(code) {
    return orderState.armado?.turnos?.find((shift) => shift.codigo === code)?.nombre
        || orderArmadoShifts[code]
        || null;
}

function orderLineLabel(order) {
    const machine = order.armado?.maquina || (orderState.armado && /^\d{2}$/.test(order.linea || '') ? order.linea : null);
    if (machine) return `Máquina ${machine}`;
    return order.linea || 'Sin línea';
}

function orderShiftLabel(order) {
    const code = order.armado?.turno || order.turno;
    const name = (order.armado || orderState.armado) ? orderArmadoShiftName(code) : null;
    if (name) return `Turno ${code} · ${name}`;
    return order.turno || 'Sin turno';
}

function orderArmadoNotice(order) {
    const armado = order.armado;
    if (!armado?.lote) return '';
    const provider = armado.proveedor_codigo || armado.proveedor_nombre || 'sin proveedor';
    return `<p class="materials-help">Tarja de armado · lote <strong>${orderEscape(armado.lote)}</strong> · guía ${orderEscape(armado.guia)} · proveedor ${orderEscape(provider)}</p>`;
}

/** Con tarja de armado, el formulario pide máquina y turno 01/02 en vez de línea y turno libres. */
function applyOrderArmadoFields() {
    const form = orderElements.form;
    if (!form) return;
    const armado = orderState.armado;
    form.querySelectorAll('[data-order-free-field]').forEach((field) => {
        field.hidden = Boolean(armado);
        field.querySelectorAll('input').forEach((input) => { input.disabled = Boolean(armado); });
    });
    form.querySelectorAll('[data-order-armado-field]').forEach((field) => {
        field.hidden = !armado;
        field.querySelectorAll('input, select').forEach((input) => { input.disabled = !armado; });
    });
    if (!armado) return;

    const select = form.elements.turno_armado;
    const selected = select.value;
    select.innerHTML = (armado.turnos || [])
        .map((shift) => `<option value="${orderEscape(shift.codigo)}">${orderEscape(shift.codigo)} · ${orderEscape(shift.nombre)}</option>`)
        .join('');
    if (selected) select.value = selected;
    document.getElementById('materialOrderArmadoHelp').textContent = `Cada pallet sale con folio único AR + código del ítem + ${armado.codigo_bodega} + correlativo, y lote ${armado.codigo_bodega} + guía + fecha + turno + máquina. La guía es la que aporta más cajas al planificar.`;
}

function latestActiveRecipeVersion(recipe) {
    return [...(recipe?.versiones || [])]
        .filter((version) => version.estado === 'activa')
        .sort((left, right) => Number(right.numero_version) - Number(left.numero_version))[0] || null;
}

function availableRecipeVersions() {
    return orderState.recipes
        .filter((recipe) => recipe.activa !== false && recipe.temporada?.activa === true)
        .map((recipe) => ({ recipe, version: latestActiveRecipeVersion(recipe) }))
        .filter((entry) => entry.version)
        .sort((left, right) => {
            const leftLabel = `${left.recipe.cliente?.nombre} ${left.recipe.nombre}`;
            const rightLabel = `${right.recipe.cliente?.nombre} ${right.recipe.nombre}`;
            return leftLabel.localeCompare(rightLabel, 'es');
        });
}

function selectedRecipeEntry() {
    const versionId = orderElements.form?.elements.version_receta_material_id.value;
    return availableRecipeVersions().find((entry) => entry.version.id === versionId) || null;
}

function availableByItem(itemId, operationalCategory = null) {
    return Math.round(orderState.inventory
        .filter((folio) => folio.item?.id === itemId
            && (!folio.almacen || folio.almacen.id === orderElements.form?.elements.almacen_origen_id.value)
            && (!operationalCategory || folio.categoria_operacional === operationalCategory))
        .reduce((sum, folio) => sum + Number(folio.cantidad_disponible || 0), 0) * 1000) / 1000;
}

function requirementsForSnapshot(snapshot, plannedOutput) {
    const baseOutput = Number(snapshot?.salida?.cantidad_base || 0);
    if (baseOutput <= 0 || plannedOutput <= 0) return [];
    return (snapshot?.componentes || []).map((component) => ({
        ...component,
        required: Math.round(
            (Number(component.cantidad_estandar || 0) * plannedOutput / baseOutput) * 1000,
        ) / 1000,
    }));
}

function requirementsForRecipe(entry, plannedOutput) {
    if (!entry) return [];
    const baseOutput = Number(entry.version.cantidad_base_salida || 0);
    if (baseOutput <= 0 || plannedOutput <= 0) return [];
    return (entry.version.componentes || []).map((component) => ({
        item_id: component.item?.id,
        codigo: component.item?.codigo,
        nombre: component.item?.nombre,
        unidad_medida: component.unidad_medida,
        es_componente_principal: component.es_componente_principal,
        categoria_operacional: component.item?.categoria_operacional,
        required: Math.round(
            (Number(component.cantidad_estandar || 0) * plannedOutput / baseOutput) * 1000,
        ) / 1000,
    }));
}

function renderOrderSelectors() {
    const source = orderElements.form.elements.almacen_origen_id;
    const previousSource = source.value;
    source.innerHTML = '<option value="">Selecciona una bodega</option>' + orderState.bodegas.map((w) => `<option value="${orderEscape(w.id)}">${orderEscape(w.codigo)} · ${orderEscape(w.nombre)}</option>`).join('');
    source.value = orderState.bodegas.some((w) => w.id === previousSource) ? previousSource : previousSource ? '' : orderState.bodegas.find((w) => w.codigo === 'BOD-CENTRAL')?.id || '';
    const previousVersion = orderElements.form.elements.version_receta_material_id.value;
    const entries = availableRecipeVersions();
    orderElements.form.elements.version_receta_material_id.innerHTML = entries.map(({ recipe, version }) => `
        <option value="${orderEscape(version.id)}">${orderEscape(recipe.cliente?.codigo)} · ${orderEscape(recipe.nombre)} · versión ${orderEscape(version.numero_version)} → ${orderEscape(recipe.item_salida?.codigo)}</option>
    `).join('') || '<option value="">No existen recetas activas en la temporada global</option>';
    if (entries.some(({ version }) => version.id === previousVersion)) {
        orderElements.form.elements.version_receta_material_id.value = previousVersion;
    }

    const previousClient = orderElements.clientFilter.value;
    const clients = new Map();
    orderState.recipes.forEach((recipe) => {
        if (recipe.cliente?.id) clients.set(recipe.cliente.id, recipe.cliente);
    });
    orderState.orders.forEach((order) => {
        if (order.cliente?.id) clients.set(order.cliente.id, order.cliente);
    });
    orderElements.clientFilter.innerHTML = '<option value="">Todos los clientes</option>'
        + [...clients.values()]
            .sort((left, right) => String(left.nombre).localeCompare(String(right.nombre), 'es'))
            .map((client) => `<option value="${orderEscape(client.id)}">${orderEscape(client.codigo)} · ${orderEscape(client.nombre)}</option>`)
            .join('');
    if (clients.has(previousClient)) orderElements.clientFilter.value = previousClient;

    renderOrderRequirements();
}

function renderOrderRequirements() {
    if (!orderElements.requirements) return;
    const entry = selectedRecipeEntry();
    const plannedOutput = Number(orderElements.form.elements.cantidad_planificada_salida.value || 0);
    const requirements = requirementsForRecipe(entry, plannedOutput);
    const unitsPerFolio = Number(entry?.version?.unidades_por_folio_salida || 0);
    const expectedFolios = unitsPerFolio > 0 && plannedOutput > 0
        ? Math.ceil(plannedOutput / unitsPerFolio)
        : 0;
    const finalFolioUnits = expectedFolios > 0
        ? Math.round((plannedOutput - (expectedFolios - 1) * unitsPerFolio) * 1000) / 1000
        : 0;

    if (!entry) {
        orderElements.requirements.innerHTML = '<p class="materials-order-empty">Crea una receta activa antes de preparar órdenes.</p>';
        return;
    }
    if (plannedOutput <= 0) {
        orderElements.requirements.innerHTML = `<p class="materials-order-empty">Indica la salida planificada en ${orderEscape(entry.recipe.item_salida?.unidad_medida)} para calcular los componentes.</p>`;
        return;
    }

    orderElements.requirements.innerHTML = `
        <div class="materials-order-requirement">
            <div><strong>Salida: ${orderEscape(entry.recipe.item_salida?.codigo)} · ${orderEscape(entry.recipe.item_salida?.nombre)}</strong><small>Receta base ${orderQuantity(entry.version.cantidad_base_salida)} ${orderEscape(entry.version.unidad_medida_salida)}${unitsPerFolio > 0 ? ` · ${orderQuantity(unitsPerFolio)} por folio · ${expectedFolios} folios esperados${finalFolioUnits !== unitsPerFolio ? ` (último con ${orderQuantity(finalFolioUnits)})` : ''}` : ' · sin regla de folios'}</small></div>
            <strong class="materials-order-requirement__stock">${orderQuantity(plannedOutput)} ${orderEscape(entry.version.unidad_medida_salida)}</strong>
        </div>
        ${requirements.map((requirement) => {
        const available = availableByItem(
            requirement.item_id,
            requirement.categoria_operacional,
        );
        const shortage = Math.max(0, requirement.required - available);
        return `
                <div class="materials-order-requirement${shortage > 0.0001 ? ' materials-order-requirement--short' : ''}">
                    <div><strong>${orderEscape(requirement.codigo)} · ${orderEscape(requirement.nombre)}${requirement.es_componente_principal ? ' · principal' : ''}</strong><small>Requerido: ${orderQuantity(requirement.required)} ${orderEscape(requirement.unidad_medida)}</small></div>
                    <div class="materials-order-requirement__stock"><strong>${orderQuantity(available)} disponibles</strong><small>${shortage > 0.0001 ? `Faltan ${orderQuantity(shortage)}` : 'Cobertura suficiente'}</small></div>
                </div>
            `;
    }).join('')}
    `;
}

function renderOrderMetrics() {
    const metrics = [
        ['BORRADORES', orderState.orders.filter((order) => order.estado === 'borrador').length],
        ['PLANIFICADAS', orderState.orders.filter((order) => order.estado === 'planificada').length],
        ['EN EJECUCIÓN', orderState.orders.filter((order) => ['en_proceso', 'pendiente_cierre'].includes(order.estado)).length],
        ['CERRADAS', orderState.orders.filter((order) => order.estado === 'cerrada').length],
        ['CANCELADAS', orderState.orders.filter((order) => order.estado === 'cancelada').length],
    ];
    orderElements.metrics.innerHTML = metrics.map(([label, value]) => `
        <article><span>${label}</span><strong>${value}</strong></article>
    `).join('');
    orderElements.summary.textContent = `${orderState.orders.length} ${orderState.orders.length === 1 ? 'orden' : 'órdenes'}`;
}

function reservationsByItem(order) {
    const grouped = new Map();
    (order.reservas || []).forEach((reservation) => {
        const current = grouped.get(reservation.item_material_id) || {
            quantity: 0,
            consumed: 0,
            pending: 0,
            folios: [],
        };
        current.quantity += Number(reservation.cantidad || 0);
        current.consumed += Number(reservation.cantidad_consumida || 0);
        current.pending += Number(reservation.cantidad_pendiente || 0);
        current.folios.push(reservation);
        grouped.set(reservation.item_material_id, current);
    });
    return grouped;
}

function orderRequirementTable(order) {
    const requirements = requirementsForSnapshot(
        order.receta_snapshot,
        Number(order.cantidad_planificada_salida || 0),
    );
    const reservations = reservationsByItem(order);
    if (!requirements.length) return '<p class="materials-order-empty">La orden no posee requerimientos legibles.</p>';

    return `
        <div class="materials-order-table-wrap">
            <table class="materials-order-table">
                <thead><tr><th>Componente</th><th>Requerido</th><th>Reservado</th><th>Consumido</th><th>Pendiente</th><th>Folios FIFO</th></tr></thead>
                <tbody>${requirements.map((requirement) => {
        const reserved = reservations.get(requirement.item_id);
        return `<tr>
                        <td><strong>${orderEscape(requirement.codigo)}</strong><small>${orderEscape(requirement.nombre)}${requirement.es_componente_principal ? ' · principal' : ''}</small></td>
                        <td>${orderQuantity(requirement.required)} ${orderEscape(requirement.unidad_medida)}</td>
                        <td>${orderQuantity(reserved?.quantity)} ${orderEscape(requirement.unidad_medida)}</td>
                        <td>${orderQuantity(reserved?.consumed)} ${orderEscape(requirement.unidad_medida)}</td>
                        <td>${orderQuantity(reserved?.pending)} ${orderEscape(requirement.unidad_medida)}</td>
                        <td>${reserved?.folios.length
        ? reserved.folios.map((reservation) => `<strong>${orderEscape(reservation.folio?.numero_folio || 'Sin folio')}</strong><small>#${reservation.orden_fifo} · ${orderQuantity(reservation.cantidad_pendiente)} pendientes · ${orderEscape(reservation.folio?.ubicacion ? `${reservation.folio.ubicacion.camara}/${reservation.folio.ubicacion.posicion}` : 'sin ubicación')}</small>`).join('')
        : 'Sin reserva'}</td>
                    </tr>`;
    }).join('')}</tbody>
            </table>
        </div>
    `;
}

function orderLotsTable(order) {
    const lots = order.lotes || [];
    if (!lots.length) return '<p class="materials-order-empty">La PDA todavía no registra lotes para esta orden.</p>';
    return `
        <div class="materials-order-table-wrap">
            <table class="materials-order-table">
                <thead><tr><th>Lote</th><th>Estado</th><th>Plan / real</th><th>Merma real</th><th>Folios de salida</th></tr></thead>
                <tbody>${lots.map((lot) => `<tr>
                    <td><strong>#${lot.numero_lote}</strong><small>${orderDateTime(lot.cerrado_at || lot.iniciado_at)}</small></td>
                    <td>${orderEscape(orderStatusLabel(lot.estado))}${lot.motivo_reversa ? `<small>Reversa: ${orderEscape(lot.motivo_reversa)}</small>` : ''}</td>
                    <td>${orderQuantity(lot.cantidad_planificada_salida)} / ${orderQuantity(lot.cantidad_real_salida)}</td>
                    <td>${lot.merma_real === null ? '—' : orderQuantity(lot.merma_real)}</td>
                    <td>${(lot.salidas || []).map((output) => `<strong>${orderEscape(output.numero_folio)}</strong><small>${orderQuantity(output.cantidad_producida)} ${orderEscape(output.item?.unidad_medida)}</small>`).join('') || 'Sin salida'}</td>
                </tr>`).join('')}</tbody>
            </table>
        </div>
    `;
}

const outputFolioStates = {
    reservado: 'Reservado',
    usado: 'Producido',
    anulado: 'Anulado',
};

function orderOutputFolioPrint(folio) {
    if (folio.requiere_reimpresion) return 'Reimprimir: cambió la cantidad';
    if (folio.estado === 'anulado') return folio.impresiones > 0 ? 'Etiqueta impresa: retirarla' : '—';
    return folio.impresiones > 0
        ? `Impresa ${folio.impresiones} ${folio.impresiones === 1 ? 'vez' : 'veces'}`
        : 'Sin imprimir';
}

function orderOutputFoliosTable(order) {
    const unit = order.version_receta?.receta?.item_salida?.unidad_medida || '';
    return `
        <div class="materials-order-table-wrap">
            <table class="materials-order-table">
                <thead><tr><th>Folio</th><th>Estado</th><th>Teórica / real</th><th>Etiqueta</th></tr></thead>
                <tbody>${order.folios_salida.map((folio) => `<tr>
                    <td><strong>${orderEscape(folio.numero_folio)}</strong><small>Folio ${folio.secuencia}</small></td>
                    <td>${orderEscape(outputFolioStates[folio.estado] || folio.estado)}${folio.motivo_anulacion ? `<small>${orderEscape(folio.motivo_anulacion)}</small>` : ''}</td>
                    <td>${orderQuantity(folio.cantidad_teorica)} / ${folio.cantidad_real === null ? '—' : orderQuantity(folio.cantidad_real)} ${orderEscape(unit)}</td>
                    <td>${orderEscape(orderOutputFolioPrint(folio))}</td>
                </tr>`).join('')}</tbody>
            </table>
        </div>
    `;
}

/** Modo cuadratura: retirado = utilizado + merma + sin armar, por componente. */
function orderReconciliationTable(order) {
    return `
        <div class="materials-order-table-wrap">
            <table class="materials-order-table">
                <thead><tr><th>Componente</th><th>Retirado</th><th>Según receta</th><th>Merma</th><th>Sin armar</th><th>Utilizado</th><th>Diferencia</th></tr></thead>
                <tbody>${order.cuadratura.map((row) => `<tr>
                    <td><strong>${orderEscape(row.item?.codigo)}</strong><small>${orderEscape(row.item?.nombre)}${row.es_componente_principal ? ' · principal' : ''}</small></td>
                    <td>${orderQuantity(row.cantidad_retirada)}</td>
                    <td>${orderQuantity(row.cantidad_teorica)}</td>
                    <td>${orderQuantity(row.cantidad_merma)}</td>
                    <td>${orderQuantity(row.cantidad_sin_armar)}</td>
                    <td>${orderQuantity(row.cantidad_utilizada)}</td>
                    <td>${orderQuantity(row.desviacion)} ${orderEscape(row.item?.unidad_medida)}</td>
                </tr>`).join('')}</tbody>
            </table>
        </div>
    `;
}

/** Aviso de etiquetas: folios reservados sin imprimir o con cantidad real distinta. */
function orderOutputFolioNotice(order) {
    const summary = order.folios_salida_resumen;
    if (!summary || ['cerrada', 'cancelada'].includes(order.estado)) return '';
    const notices = [];
    if (summary.sin_imprimir > 0) {
        notices.push(`${summary.sin_imprimir} ${summary.sin_imprimir === 1 ? 'folio reservado sin imprimir' : 'folios reservados sin imprimir'}: imprime las etiquetas antes de producir.`);
    }
    if (summary.por_reimprimir > 0) {
        notices.push(`${summary.por_reimprimir} ${summary.por_reimprimir === 1 ? 'folio cambió' : 'folios cambiaron'} de cantidad al producirse: reimprime su etiqueta.`);
    }
    return notices.map((notice) => `<p class="materials-order-notice">${orderEscape(notice)}</p>`).join('');
}

function orderAudit(order) {
    const events = [...(order.eventos || [])].slice(-6).reverse();
    return events.map((event) => `
        <div><strong>${orderEscape(orderStatusLabel(event.tipo))}</strong> · ${orderEscape(event.usuario?.nombre || 'Sistema')} · ${orderEscape(orderDateTime(event.ocurrido_at))}${event.observacion ? `<br>${orderEscape(event.observacion)}` : ''}</div>
    `).join('') || '<div>Sin eventos registrados.</div>';
}

function orderDetailContent(order) {
    return `
        <p><a href="/oficina/materiales/consumos?q=${encodeURIComponent(order.id)}">Ver consumo esperado, real y movimientos de esta OT</a></p>
        ${orderRequirementTable(order)}
        ${(order.folios_salida || []).length ? `<h4>Folios de salida reservados</h4>${orderOutputFoliosTable(order)}` : ''}
        ${(order.cuadratura || []).length ? `<h4>Cuadratura de cierre</h4>${orderReconciliationTable(order)}` : ''}
        <h4>Lotes operacionales</h4>
        ${orderLotsTable(order)}
        <div class="materials-order-audit">${orderAudit(order)}</div>
    `;
}

function orderReservationCount(order) {
    return Number(order.reservas_count ?? order.reservas?.length ?? 0);
}

function orderLotCount(order) {
    return Number(order.lotes_count ?? order.lotes?.length ?? 0);
}

function orderHasOutputs(order) {
    if (typeof order.tiene_salidas === 'boolean') return order.tiene_salidas;
    return (order.lotes || []).some(
        (lot) => lot.estado === 'cerrado' && (lot.salidas || []).length,
    );
}

function renderOrders() {
    const stateFilter = orderElements.stateFilter.value;
    const clientFilter = orderElements.clientFilter.value;
    const search = orderNormalize(orderElements.search.value);
    const orders = orderState.orders.filter((order) => {
        const matchesState = !stateFilter || order.estado === stateFilter;
        const matchesClient = !clientFilter || order.cliente?.id === clientFilter;
        const haystack = orderNormalize([
            orderCode(order), order.referencia_ot, order.proceso, order.operador_nombre,
            order.cliente?.codigo,
            order.cliente?.nombre,
            order.version_receta?.receta?.nombre,
            order.version_receta?.receta?.item_salida?.codigo,
            order.linea,
            order.turno,
            orderLineLabel(order),
            order.armado?.lote,
            order.armado?.guia,
        ].join(' '));
        return matchesState && matchesClient && (!search || haystack.includes(search));
    });

    orderElements.list.innerHTML = orders.map((order) => {
        const planned = Number(order.cantidad_planificada_salida || 0);
        const actual = Number(order.cantidad_real_salida || 0);
        const progress = planned > 0 ? Math.min(100, Math.max(0, (actual / planned) * 100)) : 0;
        const activeSeason = order.temporada?.activa === true;
        const canPlan = canManageOrders() && order.estado === 'borrador' && activeSeason;
        const hasOutputs = orderHasOutputs(order);
        const outputFolios = order.folios_salida_resumen || null;
        const hasPrintableReservations = (outputFolios?.reservados || 0) > 0;
        const canCancel = canManageOrders() && (
            ['borrador', 'planificada'].includes(order.estado)
            || (order.estado === 'en_proceso' && !hasOutputs)
        );
        const cancelledDetail = order.estado === 'cancelada' && order.motivo_cancelacion
            ? `<p class="materials-help">Cancelada: ${orderEscape(order.motivo_cancelacion)}</p>`
            : '';
        const historicalWarning = !activeSeason && order.estado === 'borrador'
            ? '<p class="materials-help">Temporada histórica: puede cancelarse, pero no planificarse.</p>'
            : '';

        return `
            <article class="materials-order-card">
                <div class="materials-order-card__header">
                    <div>
                        <h3>${orderEscape(orderCode(order))} · ${orderEscape(order.version_receta?.receta?.nombre || 'Transformación')}</h3>
                        <small>${orderEscape(order.temporada?.codigo)} · ${orderEscape(order.cliente?.codigo)} · ${orderEscape(order.cliente?.nombre)} → ${orderEscape(order.version_receta?.receta?.item_salida?.codigo)}</small>
                    </div>
                    <span class="materials-order-status materials-order-status--${orderEscape(order.estado)}">${orderEscape(orderStatusLabel(order.estado))}</span>
                </div>
                <div class="materials-order-progress"><i style="width:${progress}%"></i></div>
                <p class="materials-help">${orderQuantity(actual)} de ${orderQuantity(planned)} ${orderEscape(order.version_receta?.receta?.item_salida?.unidad_medida || '')} producidos · ${orderQuantity(progress, 1)}%</p>
                <div class="materials-order-meta">
                    <span>${orderEscape(orderDate(order.fecha_operacional))}</span>
                    <span>Receta v${orderEscape(order.version_receta?.numero_version)}</span>
                    ${order.referencia_ot ? `<span>Referencia: ${orderEscape(order.referencia_ot)}</span>` : ''}
                    ${order.proceso ? `<span>Proceso: ${orderEscape(order.proceso)}</span>` : ''}
                    ${order.operador_nombre ? `<span>Operador: ${orderEscape(order.operador_nombre)}</span>` : ''}
                    <span>Bodega: ${orderEscape(order.almacen_origen?.nombre || 'Bodega Central')}</span>
                    <span>${orderEscape(orderLineLabel(order))}</span>
                    <span>${orderEscape(orderShiftLabel(order))}</span>
                    <span>Versión operacional ${order.version}</span>
                    <span>${orderReservationCount(order)} reservas</span>
                    <span>${orderLotCount(order)} lotes</span>
                    ${outputFolios ? `<span>${outputFolios.usados} de ${outputFolios.reservados + outputFolios.usados} folios de salida producidos</span>` : ''}
                    ${order.modo_operacion === 'cuadratura_cierre' ? '<span>Cuadratura al cierre</span>' : ''}
                </div>
                ${orderArmadoNotice(order)}
                ${orderOutputFolioNotice(order)}
                ${order.alerta_vencimiento ? `<p class="form-error">${orderEscape(order.alerta_vencimiento)}</p>` : ''}
                ${order.observacion ? `<p class="materials-help">${orderEscape(order.observacion)}</p>` : ''}
                ${cancelledDetail}
                ${historicalWarning}
                ${order.estado === 'planificada' ? '<p class="materials-help">Lista para iniciar desde la PDA/tablet de Materiales.</p>' : ''}
                <details class="materials-order-details">
                    <summary data-load-material-order-detail="${orderEscape(order.id)}">Ver componentes, reservas FIFO, lotes y auditoría</summary>
                    <div data-material-order-detail="${orderEscape(order.id)}">
                        <p class="materials-order-empty">Abre el detalle para cargar la trazabilidad completa.</p>
                    </div>
                </details>
                <div class="materials-order-actions">
                    ${canPlan ? `<button class="primary-button" data-plan-material-order="${orderEscape(order.id)}" type="button">Planificar y reservar FIFO</button>` : ''}
                    ${canCancel ? `<button class="secondary-button" data-cancel-material-order="${orderEscape(order.id)}" type="button">Cancelar orden</button>` : ''}
                    ${hasOutputs || hasPrintableReservations ? `<button class="secondary-button" data-label-material-order="${orderEscape(order.id)}" type="button">${hasOutputs ? 'Abrir etiquetas' : 'Imprimir folios reservados'}</button>` : ''}
                </div>
            </article>
        `;
    }).join('') || '<p class="materials-order-empty">No existen órdenes para los filtros seleccionados.</p>';
}

async function loadOrderDetail(order) {
    const container = orderElements.list.querySelector(
        `[data-material-order-detail="${order.id}"]`,
    );
    if (!container || order._detailLoaded || orderState.loadingDetails.has(order.id)) return;

    orderState.loadingDetails.add(order.id);
    container.innerHTML = '<p class="materials-order-empty">Cargando detalle…</p>';
    try {
        const response = await orderApi(
            `/api/materiales/transformaciones/ordenes/${encodeURIComponent(order.id)}`,
        );
        Object.assign(order, response.data || {}, { _detailLoaded: true });
        container.innerHTML = orderDetailContent(order);
    } catch (error) {
        container.innerHTML = `<p class="materials-order-empty">${orderEscape(error.message)}</p>`;
    } finally {
        orderState.loadingDetails.delete(order.id);
    }
}

async function submitOrderForm(event) {
    event.preventDefault();
    orderElements.error.textContent = '';
    const data = Object.fromEntries(new FormData(orderElements.form));
    const plannedOutput = Number(data.cantidad_planificada_salida || 0);
    if (!data.version_receta_material_id || !Number.isFinite(plannedOutput) || plannedOutput <= 0) {
        orderElements.error.textContent = 'Selecciona una receta e indica una salida planificada mayor que cero.';
        return;
    }

    const armado = Boolean(orderState.armado);
    const machine = Number(data.maquina);
    if (armado && (!Number.isInteger(machine) || machine < 1 || machine > 99)) {
        orderElements.error.textContent = 'Indica la máquina de armado: un número de 1 a 99.';
        return;
    }
    if (armado && !data.turno_armado) {
        orderElements.error.textContent = 'Indica el turno de armado.';
        return;
    }

    const payloadWithoutOperation = {
        version_receta_material_id: data.version_receta_material_id,
        cantidad_planificada_salida: plannedOutput,
        fecha_operacional: data.fecha_operacional,
        referencia_ot: String(data.referencia_ot || '').trim() || null,
        proceso: String(data.proceso || '').trim() || null,
        operador_nombre: String(data.operador_nombre || '').trim() || null,
        linea: armado ? String(machine).padStart(2, '0') : (String(data.linea || '').trim() || null),
        turno: armado ? data.turno_armado : (String(data.turno || '').trim() || null),
        observacion: String(data.observacion || '').trim() || null,
        ...(data.almacen_origen_id ? { almacen_origen_id: data.almacen_origen_id } : {}),
    };
    if (orderState.bodegas.length && !orderState.bodegas.some((w) => w.id === data.almacen_origen_id)) {
        orderElements.error.textContent = 'Selecciona una bodega de componentes activa.';
        return;
    }
    const payloadKey = JSON.stringify(payloadWithoutOperation);
    if (orderState.creationOperation?.payloadKey !== payloadKey) {
        orderState.creationOperation = { id: orderUuid(), payloadKey };
    }

    orderElements.save.disabled = true;
    orderElements.save.textContent = 'Creando borrador…';
    try {
        const response = await orderApi('/api/materiales/transformaciones/ordenes', {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: orderState.creationOperation.id,
                ...payloadWithoutOperation,
            }),
        });
        orderState.creationOperation = null;
        orderElements.form.reset();
        orderElements.form.elements.fecha_operacional.value = orderToday();
        await loadOrdersOffice(true);
        orderToast(`${orderCode(response.data)} fue creada en borrador.`);
    } catch (error) {
        orderElements.error.textContent = error.message;
    } finally {
        orderElements.save.disabled = false;
        orderElements.save.textContent = 'Crear orden en borrador';
    }
}

async function planOrder(order) {
    if (!window.confirm(
        `${orderCode(order)} reservará físicamente los componentes siguiendo FIFO. ¿Confirmas la planificación?`,
    )) return;
    const previous = orderState.planningOperations.get(order.id);
    const operation = previous?.version === order.version
        ? previous
        : { id: orderUuid(), version: order.version };
    orderState.planningOperations.set(order.id, operation);

    try {
        const planned = await orderApi(`/api/materiales/transformaciones/ordenes/${order.id}/planificar`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operation.id,
                version_conocida: operation.version,
            }),
        });
        orderState.planningOperations.delete(order.id);
        await loadOrdersOffice(true);
        window.dispatchEvent(new CustomEvent('estiba:materials-updated'));
        const reservedOutputs = planned?.data?.folios_salida_resumen?.reservados || 0;
        orderToast(reservedOutputs
            ? `${orderCode(order)} quedó planificada y disponible en PDA. Sus ${reservedOutputs} folios de salida quedaron reservados para imprimir.`
            : `${orderCode(order)} quedó planificada y disponible en PDA.`);
    } catch (error) {
        orderToast(error.message, true);
    }
}

async function cancelOrder(order) {
    const previous = orderState.cancellationOperations.get(order.id);
    const reservedOutputs = order.folios_salida_resumen?.reservados || 0;
    const voidedOutputs = reservedOutputs
        ? ` Se anularán ${reservedOutputs} ${reservedOutputs === 1 ? 'folio de salida reservado' : 'folios de salida reservados'}; retira sus etiquetas si ya se imprimieron.`
        : '';
    const cancellationEffect = order.estado === 'en_proceso'
        ? `${voidedOutputs} Se descartará cualquier lote abierto y se liberarán sus reservas activas:`
        : `${voidedOutputs} Sus reservas activas serán liberadas:`;
    const reason = window.prompt(
        `Indica el motivo para cancelar ${orderCode(order)}.${cancellationEffect}`,
        previous?.reason || '',
    );
    if (reason === null) return;
    const normalizedReason = reason.trim();
    if (normalizedReason.length < 3) {
        orderToast('El motivo debe contener al menos 3 caracteres.', true);
        return;
    }
    const operation = previous?.reason === normalizedReason
        ? previous
        : { id: orderUuid(), reason: normalizedReason };
    orderState.cancellationOperations.set(order.id, operation);

    try {
        await orderApi(`/api/materiales/transformaciones/ordenes/${order.id}/cancelar`, {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: operation.id,
                motivo: operation.reason,
            }),
        });
        orderState.cancellationOperations.delete(order.id);
        await loadOrdersOffice(true);
        window.dispatchEvent(new CustomEvent('estiba:materials-updated'));
        const discardedOpenLot = order.estado === 'en_proceso'
            ? ' También se descartó cualquier lote abierto.'
            : '';
        orderToast(`${orderCode(order)} fue cancelada y sus reservas quedaron liberadas.${discardedOpenLot}`);
    } catch (error) {
        orderToast(error.message, true);
    }
}

function openOrderLabels(orderId) {
    if (!document.getElementById('officeApp')?.matches('[data-materials-section="recepcion"]')) {
        const destination = new URL('/oficina/materiales/recepcion', window.location.origin);
        destination.searchParams.set('origen', 'transformacion');
        destination.searchParams.set('id', orderId);
        window.location.assign(destination.href);
        return;
    }

    const workspace = document.getElementById('materialLabelWorkspace');
    const source = document.getElementById('materialLabelSource');
    const origin = document.getElementById('materialLabelReception');
    if (!workspace || !source || !origin) {
        orderToast('El panel de etiquetas no se encuentra disponible para esta sesión.', true);
        return;
    }
    source.value = 'transformacion';
    source.dispatchEvent(new Event('change', { bubbles: true }));
    if ([...origin.options].some((option) => option.value === orderId)) {
        origin.value = orderId;
        origin.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
        document.getElementById('reloadMaterialLabels')?.click();
        orderToast('Se actualizaron las órdenes etiquetables; selecciona la orden en el panel.');
    }
    workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function handleOrderAction(event) {
    const detailSummary = event.target.closest('[data-load-material-order-detail]');
    const planButton = event.target.closest('[data-plan-material-order]');
    const cancelButton = event.target.closest('[data-cancel-material-order]');
    const labelButton = event.target.closest('[data-label-material-order]');
    const orderId = detailSummary?.dataset.loadMaterialOrderDetail
        || planButton?.dataset.planMaterialOrder
        || cancelButton?.dataset.cancelMaterialOrder
        || labelButton?.dataset.labelMaterialOrder;
    const order = orderState.orders.find((candidate) => candidate.id === orderId);
    if (!order) return;
    if (detailSummary) void loadOrderDetail(order);
    if (planButton) void planOrder(order);
    if (cancelButton) void cancelOrder(order);
    if (labelButton) openOrderLabels(order.id);
}

function clearOrderSession(token) {
    orderState.token = token;
    orderState.identity = token ? orderReadJson(orderIdentityKey) : null;
    orderState.loadedToken = null;
    orderState.recipes = [];
    orderState.orders = [];
    orderState.inventory = [];
    orderState.bodegas = [];
    orderState.creationOperation = null;
    orderState.planningOperations.clear();
    orderState.cancellationOperations.clear();
    orderState.loadingDetails.clear();
    orderElements.panel?.classList.add('is-hidden');
    orderElements.form.reset();
    orderElements.form.elements.version_receta_material_id.innerHTML = '';
    orderElements.form.elements.almacen_origen_id.innerHTML = '';
    orderElements.form.elements.fecha_operacional.value = orderToday();
    orderElements.form.classList.add('is-hidden');
    orderElements.stateFilter.value = '';
    orderElements.clientFilter.innerHTML = '<option value="">Todos los clientes</option>';
    orderElements.search.value = '';
    orderElements.summary.textContent = '0 órdenes';
    orderElements.metrics.innerHTML = '';
    orderElements.requirements.innerHTML = '';
    orderElements.list.innerHTML = '';
    orderElements.error.textContent = '';
}

async function loadOrdersForToken(token, isCurrent, showErrors = false) {
    if (!orderSectionIsActive() || !isCurrent() || !canConsultOrders()) {
        orderElements.panel?.classList.add('is-hidden');
        return;
    }

    orderState.loading = true;
    orderState.loadedToken = token;
    orderElements.panel.classList.remove('is-hidden');
    orderElements.form.classList.toggle('is-hidden', !canManageOrders());
    try {
        const [recipes, orders, inventory] = await Promise.all([
            orderApi('/api/materiales/transformaciones/recetas?per_page=100', {}, token),
            orderApi('/api/materiales/transformaciones/ordenes?per_page=100', {}, token),
            orderApi('/api/materiales/inventario?vista=resumen', {}, token),
        ]);
        if (!isCurrent()) return;
        orderState.recipes = recipes.data || [];
        orderState.orders = orders.data || [];
        orderState.armado = orders.armado?.activo ? orders.armado : null;
        applyOrderArmadoFields();
        orderState.inventory = inventory.resumen_items_almacenes || inventory.resumen_items || [];
        orderState.bodegas = inventory.bodegas || [];
        renderOrderSelectors();
        renderOrderMetrics();
        renderOrders();
    } catch (error) {
        if (!isCurrent()) return;
        if (showErrors || !orderState.orders.length) {
            orderElements.list.innerHTML = `<p class="materials-order-empty">${orderEscape(error.message)}</p>`;
        }
    } finally {
        orderState.loading = false;
    }
}

function loadOrdersOffice(showErrors = false) {
    return orderState.session?.reload(showErrors) ?? Promise.resolve(false);
}

function bootMaterialOrders() {
    injectOrderPanel();
    if (!orderElements.panel) return;
    if (!orderSectionIsActive()) return;

    document.getElementById('reloadMaterialsButton')
        ?.addEventListener('click', () => loadOrdersOffice(false));
    orderState.session = createOfficeSessionSync({
        tokenKey: orderTokenKey,
        getToken: () => localStorage.getItem(orderTokenKey),
        onSessionChange: clearOrderSession,
        load: loadOrdersForToken,
    });
    orderState.poller = createOperationalPoller(
        () => loadOrdersOffice(false),
        {
            intervalMs: 30000,
            canRun: () => Boolean(orderState.loadedToken) && !orderState.loading,
        },
    );
    orderState.poller.start();
    orderState.session.start();
}

bootMaterialOrders();
