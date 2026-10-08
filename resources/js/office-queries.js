import { loadPrivateItemImages, releasePrivateItemImages } from './shared/private-item-photos';
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
    reload: byId('reloadButton'),
    producerCount: byId('producerCount'),
    pendingCount: byId('pendingCount'),
    associatedCount: byId('associatedCount'),
    sagTodayCount: byId('sagTodayCount'),
    globalSearch: byId('globalSearchForm'),
    traceLots: byId('traceLotsForm'),
    traceLotsResults: byId('traceLotsResults'),
    traceLotsSeason: byId('traceLotsSeason'),
    searchResults: byId('searchResults'),
    sagSearch: byId('sagSearchForm'),
    sagResult: byId('sagResult'),
    producerFilters: byId('producerFilters'),
    producerList: byId('producerList'),
    producerDialog: byId('producerDialog'),
    producerDialogTitle: byId('producerDialogTitle'),
    producerDialogBody: byId('producerDialogBody'),
    closeProducerDialog: byId('closeProducerDialog'),
    folioDialog: byId('folioDialog'),
    folioDialogTitle: byId('folioDialogTitle'),
    folioDialogBody: byId('folioDialogBody'),
    closeFolioDialog: byId('closeFolioDialog'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    activeSection: elements.app.dataset.queriesSection || 'busqueda',
    catalogs: { clientes: [] },
    producers: [],
    traceTerm: '',
    traceSeason: '',
};

class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function label(value) {
    const labels = {
        administrador: 'Administrador',
        supervisor_frio: 'Supervisor de frío',
        digitador_materia_prima: 'Digitador de materia prima',
        pendiente_cliente: 'Pendiente de cliente',
        asociado: 'Asociado',
        folios: 'Folios',
        lotes: 'Lotes',
        productores: 'Productores',
        recepciones: 'Recepciones',
        pallet: 'Pallet',
        saldo: 'Saldo',
        material: 'Material',
        agotado: 'Agotado',
        disponible: 'Disponible',
        pendiente_ubicacion: 'Pendiente de ubicación',
        bloqueado: 'Bloqueado',
        despachado: 'Despachado',
        retirado_definitivo: 'Retirado definitivo',
        pendiente_prefrio: 'Pendiente de prefrío',
        prefrio_aprobado: 'Prefrío aprobado',
        agotado_por_repaletizaje: 'Agotado por repaletizaje',
        aprobado: 'Aprobado',
        observado: 'Observado',
        rechazado: 'Rechazado',
    };
    return labels[value] || String(value || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function formatDate(value, fallback = '—') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function formatWeight(value) {
    if (value === null || value === undefined) return '—';
    return `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(value))} kg`;
}

function formatBoxes(value, fallback = '—') {
    if (value === null || value === undefined) return fallback;
    return `${new Intl.NumberFormat('es-CL').format(Number(value))} cajas`;
}

function setBusy(active, message = 'Procesando…') {
    elements.loadingText.textContent = message;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, isError = false) {
    const node = document.createElement('div');
    node.className = `toast${isError ? ' toast--error' : ''}`;
    node.textContent = message;
    elements.toasts.append(node);
    window.setTimeout(() => node.remove(), 5000);
}

function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}

async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch {
        throw new ApiError('No fue posible conectar con Laravel.', 0);
    }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status, data);
    }
    return data;
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
    localStorage.removeItem(keys.token);
    localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden');
    elements.access.classList.remove('is-hidden');
}

function showApp() {
    if (state.identity?.puede_consultar_oficina_consultas !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name;
    elements.userRole.textContent = label(state.identity?.rol);
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}

function producerBadge(producer) {
    const pending = producer.estado_asociacion === 'pendiente_cliente';
    return `<span class="query-badge ${pending ? 'query-badge--pending' : 'query-badge--active'}">${escapeHtml(label(producer.estado_asociacion))}</span>`;
}

function species(producer) {
    if (!producer.especies?.length) return '';
    return `<div class="sag-species">${producer.especies.map((item) => `<span>${escapeHtml(item)}</span>`).join('')}</div>`;
}

function associationForm(producer) {
    if (state.identity?.puede_asociar_productores_csg !== true) return '';
    const selected = new Set((producer.clientes || []).map((client) => client.id));
    const options = state.catalogs.clientes.map((client) => `
        <label>
            <input type="checkbox" name="cliente_ids" value="${escapeHtml(client.id)}" ${selected.has(client.id) ? 'checked' : ''}>
            <span>${escapeHtml(client.codigo)} · ${escapeHtml(client.nombre)}</span>
        </label>`).join('');
    return `<form class="producer-association" data-associate-producer="${escapeHtml(producer.id)}">
        <fieldset><legend>Clientes habilitados</legend>${options}</fieldset>
        <button type="submit">Guardar clientes</button>
    </form>`;
}

function producerCard(producer, includeAssociation = true) {
    const clients = producer.clientes?.length ? producer.clientes.map((client) => client.nombre).join(', ') : 'Sin cliente asociado';
    return `<article class="producer-card">
        <div class="producer-card__head"><div><small>${escapeHtml(producer.tipo_codigo || 'CSG')}</small><h3>${escapeHtml(producer.codigo)}</h3></div>${producerBadge(producer)}</div>
        <p><strong>${escapeHtml(producer.razon_social)}</strong></p>
        <p>${escapeHtml(producer.predio)}${producer.direccion ? ` · ${escapeHtml(producer.direccion)}` : ''}</p>
        <small>SAG: ${escapeHtml(label(producer.estado_sag))} · Verificado ${escapeHtml(formatDate(producer.ultima_verificacion_at))}</small>
        <small>Cliente: ${escapeHtml(clients)}</small>
        ${species(producer)}
        ${includeAssociation ? associationForm(producer) : ''}
        <div class="producer-actions"><span></span><button type="button" data-open-producer="${escapeHtml(producer.id)}">Ver expediente</button></div>
    </article>`;
}

function renderProducers() {
    elements.producerList.innerHTML = state.producers.length
        ? state.producers.map((producer) => producerCard(producer)).join('')
        : '<div class="query-empty">No hay productores para este filtro.</div>';
}

function upsertProducer(producer) {
    const index = state.producers.findIndex((item) => item.id === producer.id);
    if (index === -1) state.producers.unshift(producer);
    else state.producers[index] = producer;
    renderProducers();
}

async function refreshSummaryQuietly() {
    try {
        await loadBase({ includeCatalogs: false, includeProducers: false });
    } catch (error) {
        if (error.status !== 401) console.warn('No se pudo refrescar el resumen CSG en segundo plano.', error);
    }
}

function resultCard(type, item) {
    if (type === 'folios') {
        const location = item.ubicacion ? `${item.ubicacion.camara} · ${item.ubicacion.posicion}` : 'Sin ubicación actual';
        return `<article class="result-card result-card--folio">
            <div class="result-card__head"><strong>${escapeHtml(item.numero)}</strong><span class="query-badge">${escapeHtml(label(item.estado))}</span></div>
            <p>${escapeHtml([item.exportadora, item.marca, item.variedad, item.calibre].filter(Boolean).join(' · '))}</p>
            <div class="folio-card-status"><span>${escapeHtml(label(item.tipo_bulto))}</span><span>${escapeHtml(label(item.condicion_termica))}</span></div>
            <small>${escapeHtml(location)} · Ingreso ${escapeHtml(formatDate(item.fecha_ingreso))}</small>
            <button type="button" class="trace-button" data-open-folio="${escapeHtml(item.id)}">Ver trazabilidad →</button>
        </article>`;
    }
    if (type === 'lotes') {
        return `<article class="result-card"><div class="result-card__head"><strong>${escapeHtml(item.numero)}</strong><span class="query-badge">${escapeHtml(label(item.estado))}</span></div><p>${escapeHtml(item.cliente)} · CSG ${escapeHtml(item.csg)} · ${escapeHtml(item.especie)} ${escapeHtml(item.variedad)}</p><small>${escapeHtml(item.recepcion || 'Sin recepción')} · ${escapeHtml(formatWeight(item.kilos_netos))} · ${escapeHtml(item.camara || 'Sin cámara')}</small></article>`;
    }
    if (type === 'productores') {
        return `<article class="result-card"><div class="result-card__head"><strong>CSG ${escapeHtml(item.codigo)}</strong>${producerBadge(item)}</div><p>${escapeHtml(item.razon_social)} · ${escapeHtml(item.predio)}</p><small>${escapeHtml(item.clientes?.join(', ') || 'Sin cliente asociado')}</small><button type="button" class="secondary-button" data-open-producer="${escapeHtml(item.id)}">Ver expediente</button></article>`;
    }
    return `<article class="result-card"><div class="result-card__head"><strong>${escapeHtml(item.numero || 'Recepción')}</strong><span class="query-badge">${escapeHtml(label(item.estado))}</span></div><p>Guía ${escapeHtml(item.guia)} · ${escapeHtml(item.cliente)} · ${escapeHtml(item.patente_camion)}</p><small>${escapeHtml(item.conductor)} · ${escapeHtml(formatWeight(item.peso_neto))} · ${item.lotes} lote(s)</small></article>`;
}

function renderSearchResults(payload) {
    const groups = ['folios', 'lotes', 'productores', 'recepciones'];
    const total = groups.reduce((sum, group) => sum + (payload[group]?.length || 0), 0);
    if (!total) {
        elements.searchResults.innerHTML = `<div class="query-empty">No se encontraron registros para “${escapeHtml(payload.termino)}”.</div>`;
        return;
    }
    // Cada categoría muestra solo las coincidencias más recientes; no es un listado de alcance.
    const limit = Number(payload.limite_por_categoria || 0);
    const truncated = limit > 0 && groups.some((group) => (payload[group]?.length || 0) >= limit);
    const notice = truncated ? `<p class="query-search-limit">Se muestran hasta ${escapeHtml(limit)} coincidencias por categoría. Para conocer todos los folios de un lote o proceso usa <a href="/oficina/consultas/trazabilidad?q=${encodeURIComponent(payload.termino)}">Trazabilidad de lotes</a>.</p>` : '';
    elements.searchResults.innerHTML = notice + groups.filter((group) => payload[group]?.length).map((group) => `
        <section class="result-group"><h3>${escapeHtml(label(group))}<span>${limit > 0 && payload[group].length >= limit ? `primeros ${payload[group].length}` : payload[group].length}</span></h3>
        <div class="result-cards">${payload[group].map((item) => resultCard(group, item)).join('')}</div></section>`).join('');
}

async function loadBase(options = {}) {
    const includeCatalogs = options.includeCatalogs ?? state.activeSection !== 'busqueda';
    const includeProducers = options.includeProducers ?? state.activeSection === 'productores';
    const filters = new URLSearchParams(new FormData(elements.producerFilters));
    const [summary, catalogs, producers] = await Promise.all([
        api('/api/consultas/resumen'),
        includeCatalogs ? api('/api/consultas/catalogos') : Promise.resolve(null),
        includeProducers
            ? api(`/api/consultas/productores?${filters.toString()}`)
            : Promise.resolve(null),
    ]);
    if (catalogs) state.catalogs = catalogs;
    if (producers) state.producers = producers.data;
    elements.producerCount.textContent = summary.productores.total;
    elements.pendingCount.textContent = summary.productores.pendientes_cliente;
    elements.associatedCount.textContent = summary.productores.asociados;
    elements.sagTodayCount.textContent = summary.consultas_sag_hoy;
    if (producers) renderProducers();
}

async function openProducer(id) {
    setBusy(true, 'Cargando expediente CSG…');
    try {
        const payload = await api(`/api/consultas/productores/${id}`);
        const producer = payload.productor;
        elements.producerDialogTitle.textContent = `CSG ${producer.codigo}`;
        const rows = payload.lotes.length ? payload.lotes.map((lot) => `<tr><td>${escapeHtml(lot.numero)}</td><td>${escapeHtml(lot.temporada)}</td><td>${escapeHtml(lot.cliente)}</td><td>${escapeHtml(lot.especie)} · ${escapeHtml(lot.variedad)}</td><td>${escapeHtml(label(lot.estado))}</td><td>${escapeHtml(formatWeight(lot.kilos_netos))}</td><td>${escapeHtml(lot.camara || '—')}</td></tr>`).join('') : '<tr><td colspan="7">Sin lotes registrados para este CSG.</td></tr>';
        elements.producerDialogBody.innerHTML = `
            <div class="dossier-grid"><div><span>Razón social</span><strong>${escapeHtml(producer.razon_social)}</strong></div><div><span>Predio</span><strong>${escapeHtml(producer.predio)}</strong></div><div><span>Estado SAG</span><strong>${escapeHtml(label(producer.estado_sag))}</strong></div><div><span>RUT consultado</span><strong>${escapeHtml(producer.rut || 'No registrado')}</strong></div><div><span>Clientes</span><strong>${escapeHtml(producer.clientes.map((client) => client.nombre).join(', ') || 'Pendiente')}</strong></div><div><span>Trazabilidad</span><strong>${payload.totales.lotes} lotes · ${escapeHtml(formatWeight(payload.totales.kilos_netos))}</strong></div></div>
            ${species(producer)}
            <table class="dossier-lots"><thead><tr><th>Lote</th><th>Temporada</th><th>Cliente</th><th>Producto</th><th>Estado</th><th>Neto</th><th>Cámara</th></tr></thead><tbody>${rows}</tbody></table>`;
        elements.producerDialog.showModal();
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

function traceMeta(meta = {}) {
    const entries = Object.entries(meta);
    if (!entries.length) return '';
    return `<div class="trace-event__meta">${entries.map(([key, value]) => `<span><small>${escapeHtml(key)}</small><strong>${escapeHtml(value)}</strong></span>`).join('')}</div>`;
}

function traceOrigins(origins = []) {
    if (!origins.length) return '';
    return `<div class="trace-origins"><small>COMPOSICIÓN / ORÍGENES</small>${origins.map((origin) => `<div><strong>${escapeHtml(origin.folio || 'Folio')}</strong><span>${escapeHtml(formatBoxes(origin.aporte))} aportadas · ${escapeHtml(formatBoxes(origin.antes))} → ${escapeHtml(formatBoxes(origin.despues))}</span></div>`).join('')}</div>`;
}

function traceTimeline(events = []) {
    if (!events.length) return '<div class="query-empty">No hay eventos operacionales registrados para este folio.</div>';
    return `<div class="trace-timeline">${events.map((event) => `
        <article class="trace-event trace-event--${escapeHtml(event.tipo)}">
            <div class="trace-event__rail"><span></span></div>
            <div class="trace-event__body">
                <time>${escapeHtml(formatDate(event.fecha))}</time>
                <h4>${escapeHtml(event.titulo)}</h4>
                <p>${escapeHtml(event.descripcion)}</p>
                ${traceMeta(event.meta)}
                ${traceOrigins(event.origenes)}
            </div>
        </article>`).join('')}</div>`;
}

function specificationGrid(specifications = {}) {
    const labels = {
        cliente: 'Cliente', especie: 'Especie', marca: 'Marca', variedad: 'Variedad', calibre: 'Calibre',
        planta_origen: 'Planta de origen', guia: 'Guía', csp: 'CSP', referencia_externa: 'Referencia externa',
        envase: 'Envase', categoria: 'Categoría', csg: 'CSG', predio: 'Predio', cuartel: 'Cuartel',
    };
    return Object.entries(labels).map(([key, title]) => `<div><span>${title}</span><strong>${escapeHtml(specifications[key] || '—')}</strong></div>`).join('');
}

function formatMaterialQuantity(value, unit = '') {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    if (Number.isNaN(number)) return '—';
    const formatted = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(number);
    return `${formatted}${unit ? ` ${unit}` : ''}`;
}

function formatDateOnly(value) {
    if (!value) return '—';
    const parts = String(value).split('-');
    return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : String(value);
}

function materialSpecificationGrid(material = {}) {
    const identity = material.identidad || {};
    const specifications = [
        ['Cliente', identity.cliente],
        ['Código', identity.codigo],
        ['Ítem', identity.item],
        ['Categoría', identity.categoria],
        ['Categoría operacional', label(identity.categoria_operacional)],
        ['Proveedor', identity.proveedor],
        ['Lote', identity.lote],
        ['Fabricación', formatDateOnly(identity.fecha_fabricacion)],
        ['Vencimiento', `${formatDateOnly(identity.fecha_vencimiento)}${material.vencimiento?.etiqueta ? ` · ${material.vencimiento.etiqueta}` : ''}`],
    ];
    return specifications.map(([title, value]) => `<div><span>${escapeHtml(title)}</span><strong>${escapeHtml(value || '—')}</strong></div>`).join('');
}

function materialInventoryGrid(material = {}) {
    const inventory = material.inventario || {};
    const unit = inventory.unidad_medida || '';
    const summary = [
        ['Cantidad inicial', formatMaterialQuantity(inventory.inicial, unit)],
        ['Cantidad actual', formatMaterialQuantity(inventory.actual, unit)],
        ['Cantidad reservada', formatMaterialQuantity(inventory.reservada, unit)],
        ['Cantidad disponible', formatMaterialQuantity(inventory.disponible, unit)],
    ];
    const balances = (material.saldos || []).map((balance) => {
        const place = [balance.camara, balance.posicion].filter(Boolean).join(' · ');
        const context = [balance.centro_costo ? `CC ${balance.centro_costo}` : '', place].filter(Boolean).join(' · ');
        return [
            balance.almacen || balance.codigo || 'Almacén',
            formatMaterialQuantity(balance.cantidad_actual, unit),
            `${formatMaterialQuantity(balance.cantidad_disponible, unit)} disponibles${context ? ` · ${context}` : ''}`,
        ];
    });
    return [
        ...summary.map(([title, value]) => `<div><span>${escapeHtml(title)}</span><strong>${escapeHtml(value)}</strong></div>`),
        ...balances.map(([title, value, detail]) => `<div><span>${escapeHtml(title)}</span><strong>${escapeHtml(value)}</strong><small>${escapeHtml(detail)}</small></div>`),
    ].join('');
}

function materialLocation(material = {}, folio = {}) {
    const balances = material.saldos || [];
    if (balances.length === 1) {
        const balance = balances[0];
        return {
            title: balance.almacen || balance.codigo || 'Almacén con saldo',
            detail: [balance.centro_costo ? `CC ${balance.centro_costo}` : '', balance.camara, balance.posicion].filter(Boolean).join(' · ') || 'Saldo vigente',
        };
    }
    if (balances.length > 1) {
        return {
            title: `${balances.length} almacenes con saldo`,
            detail: balances.map((balance) => balance.almacen || balance.codigo).filter(Boolean).join(' · '),
        };
    }
    if (folio.ubicacion) {
        return {
            title: [folio.ubicacion.camara, folio.ubicacion.posicion].filter(Boolean).join(' · '),
            detail: 'Ubicación física actual',
        };
    }
    return { title: 'Sin saldo ubicado', detail: folio.temporada || 'Sin temporada' };
}

function traceCounts(payload, materialProfile) {
    const totals = payload.totales || {};
    const counts = materialProfile ? [
        [totals.recepciones_material || 0, 'recepción'],
        [totals.movimientos_material || 0, 'movimientos'],
        [totals.consumos_material || 0, 'consumos'],
        [totals.transformaciones_material || 0, 'transformaciones'],
    ] : [
        [totals.validaciones || 0, 'validación'],
        [totals.procesos_prefrio || 0, 'prefrío'],
        [totals.movimientos || 0, 'movimientos'],
        [totals.repaletizajes || 0, 'repas'],
    ];
    return counts.map(([count, title]) => `<span>${count} ${escapeHtml(title)}</span>`).join('');
}

async function openFolio(id) {
    setBusy(true, 'Construyendo trazabilidad del folio…');
    try {
        const payload = await api(`/api/consultas/folios/${id}`);
        const folio = payload.folio;
        const materialProfile = folio.tipo_bulto === 'material' && payload.material;
        const location = materialProfile
            ? materialLocation(payload.material, folio)
            : {
                title: folio.ubicacion ? [folio.ubicacion.camara, folio.ubicacion.posicion].filter(Boolean).join(' · ') : 'Sin ubicación actual',
                detail: folio.temporada || 'Sin temporada',
            };
        const originText = materialProfile
            ? `${payload.material.origen?.titulo || 'Ingreso de material'} · ${payload.material.origen?.referencia || 'Sin referencia'}`
            : (payload.validacion
                ? `Validado ${formatDate(payload.validacion.fecha)} · ${formatBoxes(payload.validacion.cantidad_cajas)}`
                : (payload.repaletizajes?.length ? `Origen trazado por ${payload.repaletizajes[0].codigo}` : 'Sin validación directa registrada'));
        const originDetail = materialProfile
            ? `Origen ${formatDate(payload.material.origen?.fecha || folio.fecha_ingreso)}`
            : `Ingreso ${formatDate(folio.fecha_ingreso)}`;
        const currentQuantity = materialProfile
            ? formatMaterialQuantity(payload.material.inventario?.actual, payload.material.inventario?.unidad_medida)
            : formatBoxes(folio.cantidad_cajas);
        const stateDetail = materialProfile
            ? `${formatMaterialQuantity(payload.material.inventario?.disponible, payload.material.inventario?.unidad_medida)} disponibles`
            : label(folio.condicion_termica);
        const identity = materialProfile
            ? materialSpecificationGrid(payload.material)
            : specificationGrid(folio.especificaciones);
        const inventory = materialProfile ? `
            <section class="folio-dossier-section">
                <div class="folio-dossier-heading"><div><p class="eyebrow">EXISTENCIA</p><h3>Saldo por almacén y centro de costo</h3></div></div>
                <div class="folio-spec-grid">${materialInventoryGrid(payload.material)}</div>
            </section>` : '';
        const exhaustion = folio.repaletizaje_agotamiento
            ? `<div class="trace-alert"><strong>AGOTADO POR REPALETIZAJE</strong><span>Este folio terminó su existencia física en ${escapeHtml(folio.repaletizaje_agotamiento)}.</span></div>`
            : '';

        elements.folioDialogTitle.textContent = folio.numero;
        releasePrivateItemImages(elements.folioDialogBody);
        elements.folioDialogBody.innerHTML = `
            ${materialProfile && payload.material.foto_principal ? `<img width="120" height="120" style="object-fit:contain" alt="Foto de ${escapeHtml(payload.material.identidad.item)}" data-private-item-photo="${escapeHtml(payload.material.foto_principal.miniatura_url)}">` : ''}
            ${exhaustion}
            <section class="folio-dossier-hero">
                <div><span>ESTADO ACTUAL</span><strong>${escapeHtml(label(folio.estado_explicado))}</strong><small>${escapeHtml(stateDetail)}</small></div>
                <div><span>CANTIDAD ACTUAL</span><strong>${escapeHtml(currentQuantity)}</strong><small>${escapeHtml(label(folio.tipo_bulto))}</small></div>
                <div><span>UBICACIÓN</span><strong>${escapeHtml(location.title)}</strong><small>${escapeHtml(location.detail)}</small></div>
                <div><span>ORIGEN</span><strong>${escapeHtml(originText)}</strong><small>${escapeHtml(originDetail)}</small></div>
            </section>
            <section class="folio-dossier-section">
                <div class="folio-dossier-heading"><div><p class="eyebrow">ESPECIFICACIONES</p><h3>${materialProfile ? 'Identidad del material' : 'Identidad del producto'}</h3></div></div>
                <div class="folio-spec-grid">${identity}</div>
            </section>
            ${inventory}
            <section class="folio-dossier-section">
                <div class="folio-dossier-heading"><div><p class="eyebrow">HISTORIA OPERACIONAL</p><h3>Línea de tiempo</h3></div><div class="trace-counts">${traceCounts(payload, materialProfile)}</div></div>
                ${traceTimeline(payload.timeline)}
            </section>`;
        void loadPrivateItemImages(elements.folioDialogBody);
        elements.folioDialog.showModal();
    } catch (error) {
        toast(error.message, true);
    } finally {
        setBusy(false);
    }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    setBusy(true, 'Verificando acceso…');
    try {
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
        if (payload.usuario?.puede_consultar_oficina_consultas !== true) throw new ApiError('El usuario no posee acceso a Consultas.', 403);
        persist(payload);
        showApp();
        await loadBase();
    } catch (error) { elements.loginError.textContent = error.message; } finally { setBusy(false); }
    if (state.token) runSearchFromUrl();
});

elements.logout.addEventListener('click', async () => {
    try { await api('/api/acceso-oficina', { method: 'DELETE' }); } finally { clearSession(); }
});

elements.reload.addEventListener('click', async () => {
    setBusy(true, 'Actualizando consultas…');
    try { await loadBase(); toast('Información actualizada.'); } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});

// El buscador global del menú llega con ?q=; al abrir la oficina se ejecuta esa búsqueda.
function runSearchFromUrl() {
    const term = new URLSearchParams(window.location.search).get('q')?.trim();
    if (!term || term.length < 2) return;
    const form = state.activeSection === 'trazabilidad' ? elements.traceLots : elements.globalSearch;
    if (!form?.elements.q) return;
    form.elements.q.value = term;
    form.requestSubmit();
}

elements.globalSearch.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(true, 'Buscando trazabilidad…');
    try {
        const params = new URLSearchParams(new FormData(elements.globalSearch));
        renderSearchResults(await api(`/api/consultas/buscar?${params.toString()}`));
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});

// Trazabilidad MP → PT: resumen total, lotes digitados y folios paginados con su composición.
// El Excel incluye todos los folios afectados y es el respaldo para decidir un retiro.
function renderTraceSeasons(seasons = [], current = null) {
    const select = elements.traceLotsSeason;
    if (!select || !seasons.length) return;
    select.innerHTML = seasons.map((season) => `<option value="${escapeHtml(season.id)}">${escapeHtml(season.codigo)}${season.activa ? ' · activa' : ' · cerrada'}</option>`).join('');
    if (current) select.value = current.id;
}

function renderTraceLots(payload) {
    const { resumen, lotes, folios, termino, paginacion, temporada, temporadas } = payload;
    const deliveries = payload.entregas_proceso || [];
    state.traceTerm = termino;
    state.traceSeason = temporada?.id ?? '';
    renderTraceSeasons(temporadas, temporada);
    const seasonLabel = temporada ? `temporada ${temporada.codigo}` : 'la temporada';
    if (!folios.length && !lotes.length && !deliveries.length) {
        elements.traceLotsResults.innerHTML = `<div class="query-empty">No hay folios, lotes ni entregas a proceso para ${escapeHtml(termino)} en ${escapeHtml(seasonLabel)}.</div>`;
        return;
    }
    const summary = `<div class="trace-lots-summary">
        <span><strong>${escapeHtml(resumen.folios)}</strong> folios en total en ${escapeHtml(seasonLabel)} (${escapeHtml(resumen.folios_activos)} activos)</span>
        <span><strong>${escapeHtml(resumen.cajas_coincidentes)}</strong> cajas de ${escapeHtml(termino)}</span>
        ${resumen.lineas_sin_lote_verificado ? `<span class="trace-lots-unregistered">${escapeHtml(resumen.lineas_sin_lote_verificado)} líneas sin recepción MP verificada</span>` : ''}
        ${resumen.folios ? '<button type="button" class="trace-button" data-trace-export>Descargar todos los folios (Excel)</button>' : ''}
    </div>`;
    const lots = lotes.length ? `<table class="trace-lots-table"><caption class="office-visually-hidden">Lotes de materia prima</caption>
        <thead><tr><th>Lote MP</th><th>Recepción</th><th>Cliente</th><th>CSG / predio</th><th>Variedad</th><th>Cosecha</th><th>Kilos netos</th><th>Estado</th></tr></thead>
        <tbody>${lotes.map((lote) => `<tr><td><strong>${escapeHtml(lote.numero)}</strong></td><td>${escapeHtml(lote.recepcion ?? '—')}<br><small>${escapeHtml(lote.guia ?? '')}</small></td><td>${escapeHtml(lote.cliente ?? '—')}</td><td>${escapeHtml(lote.csg)}<br><small>${escapeHtml(lote.predio ?? '')}</small></td><td>${escapeHtml(lote.variedad ?? '—')}</td><td>${escapeHtml(lote.fecha_cosecha ?? '—')}</td><td>${escapeHtml(Number(lote.kilos_netos).toLocaleString('es-CL'))}</td><td>${escapeHtml(label(lote.estado))}</td></tr>`).join('')}</tbody></table>` : '';
    // El proceso de packing es la orden de Fruta a Proceso: qué lotes MP recibió.
    const processDeliveries = deliveries.length ? `<table class="trace-lots-table"><caption class="trace-lots-caption">Proceso ${escapeHtml(termino)}: lotes MP entregados por Fruta a Proceso</caption>
        <thead><tr><th>Entrega</th><th>Lote MP</th><th>Cliente</th><th>Recepción</th><th>Línea / turno</th><th>Envases</th><th>Kilos</th></tr></thead>
        <tbody>${deliveries.map((delivery) => `<tr><td>${escapeHtml(delivery.entregado_at ? new Date(delivery.entregado_at).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }) : '—')}</td><td><strong>${escapeHtml(delivery.lote ?? '—')}</strong></td><td>${escapeHtml(delivery.cliente ?? '—')}</td><td>${escapeHtml(delivery.recepcion ?? '—')}</td><td>${escapeHtml([delivery.linea_proceso, delivery.turno].filter(Boolean).join(' · ') || '—')}</td><td>${escapeHtml(delivery.envases ?? '—')}</td><td>${delivery.kilos === null ? '—' : escapeHtml(Number(delivery.kilos).toLocaleString('es-CL'))}</td></tr>`).join('')}</tbody></table>` : '';
    const rows = folios.map((folio) => `<tr data-inactive="${folio.activo ? 'false' : 'true'}">
        <td><strong>${escapeHtml(folio.numero)}</strong><br><small>${escapeHtml(label(folio.estado))}${folio.activo ? '' : ' · inactivo'}</small></td>
        <td>${escapeHtml(folio.exportadora ?? '—')}<br><small>${escapeHtml([folio.variedad, folio.calibre].filter(Boolean).join(' · '))}</small></td>
        <td>${folio.ubicacion ? `${escapeHtml(folio.ubicacion.camara)} · ${escapeHtml(folio.ubicacion.posicion)}` : '—'}</td>
        <td>${folio.carga ? `${escapeHtml(folio.carga.codigo)}<br><small>${escapeHtml(label(folio.carga.estado))}</small>` : '—'}</td>
        <td><div class="trace-lots-lines">${folio.lineas.map((linea) => `<span data-match="${linea.coincide ? 'true' : 'false'}">${escapeHtml(linea.cantidad_cajas)} cajas · CSG ${escapeHtml(linea.csg ?? '—')} · lote ${escapeHtml(linea.lote_materia_prima ?? 'sin informar')}${linea.recepcion ? ` (${escapeHtml(linea.recepcion)})` : ''} · proceso ${escapeHtml(linea.proceso_packing ?? 'sin informar')}${linea.lote_materia_prima && !linea.lote_verificado ? ' <em class="trace-lots-unregistered">recepción MP no verificada</em>' : ''}</span>`).join('')}</div></td>
    </tr>`).join('');
    const pages = paginacion && paginacion.paginas > 1 ? `<nav class="trace-lots-pages" aria-label="Páginas de folios">
        <button type="button" class="trace-button" data-trace-page="${paginacion.pagina - 1}" ${paginacion.pagina <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span>Página ${escapeHtml(paginacion.pagina)} de ${escapeHtml(paginacion.paginas)}</span>
        <button type="button" class="trace-button" data-trace-page="${paginacion.pagina + 1}" ${paginacion.pagina >= paginacion.paginas ? 'disabled' : ''}>Siguiente →</button>
    </nav>` : '';
    const table = folios.length ? `<table class="trace-lots-table"><caption class="office-visually-hidden">Folios relacionados</caption>
        <thead><tr><th>Folio</th><th>Cliente / producto</th><th>Ubicación</th><th>Carga</th><th>Composición (cajas · CSG · lote · proceso)</th></tr></thead>
        <tbody>${rows}</tbody></table>` : '<div class="query-empty">Ningún folio informa todavía este lote o proceso.</div>';
    elements.traceLotsResults.innerHTML = summary + lots + processDeliveries + table + pages;
}

async function loadTraceLots(term, page = 1, season = '') {
    setBusy(true, 'Trazando lote…');
    try {
        const params = new URLSearchParams({ q: term, pagina: String(page) });
        if (season) params.set('temporada_id', season);
        renderTraceLots((await api(`/api/consultas/trazabilidad?${params.toString()}`)).data);
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
}

async function downloadTraceLots(term, season = '') {
    setBusy(true, 'Preparando Excel de trazabilidad…');
    try {
        const params = new URLSearchParams({ q: term });
        if (season) params.set('temporada_id', season);
        const response = await fetch(`/api/consultas/trazabilidad/exportar?${params.toString()}`, {
            headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Authorization: `Bearer ${state.token}` },
        });
        if (!response.ok) throw new ApiError('No fue posible generar el Excel de trazabilidad.', response.status);
        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'trazabilidad.xlsx';
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
}

elements.traceLots?.addEventListener('submit', (event) => {
    event.preventDefault();
    const data = new FormData(elements.traceLots);
    void loadTraceLots(String(data.get('q') ?? ''), 1, String(data.get('temporada_id') ?? ''));
});

elements.traceLotsResults?.addEventListener('click', (event) => {
    const page = event.target.closest('[data-trace-page]');
    if (page && !page.disabled) {
        void loadTraceLots(state.traceTerm, Number(page.dataset.tracePage), state.traceSeason);
        return;
    }
    if (event.target.closest('[data-trace-export]')) void downloadTraceLots(state.traceTerm, state.traceSeason);
});

elements.sagSearch.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(true, 'Consultando registro público SAG…');
    try {
        const payload = await api('/api/consultas/sag', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.sagSearch))) });
        elements.sagResult.innerHTML = payload.data.length ? payload.data.map((producer) => `<div class="sag-producer">${producerCard(producer)}</div>`).join('') : `<div class="query-empty">${escapeHtml(payload.message)}</div>`;
        payload.data.forEach(upsertProducer);
        void refreshSummaryQuietly();
        toast(payload.message);
    } catch (error) {
        elements.sagResult.innerHTML = `<div class="query-empty">${escapeHtml(error.message)}</div>`;
        toast(error.message, true);
    } finally { setBusy(false); }
});

elements.producerFilters.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(true, 'Filtrando productores…');
    try {
        await loadBase({ includeCatalogs: false, includeProducers: true });
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});

document.addEventListener('click', (event) => {
    const producerButton = event.target.closest('[data-open-producer]');
    if (producerButton) {
        void openProducer(producerButton.dataset.openProducer);
        return;
    }
    const folioButton = event.target.closest('[data-open-folio]');
    if (folioButton) void openFolio(folioButton.dataset.openFolio);
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-associate-producer]');
    if (!form) return;
    event.preventDefault();
    const clientIds = [...form.querySelectorAll('input[name="cliente_ids"]:checked')]
        .map((input) => input.value);
    if (!clientIds.length) { toast('Selecciona al menos un cliente.', true); return; }
    setBusy(true, 'Guardando clientes del CSG…');
    try {
        const payload = await api(`/api/consultas/productores/${form.dataset.associateProducer}/clientes`, { method: 'POST', body: JSON.stringify({ cliente_ids: clientIds }) });
        upsertProducer(payload.data);
        void refreshSummaryQuietly();
        toast(`CSG habilitado para ${clientIds.length} cliente(s).`);
    } catch (error) { toast(error.message, true); } finally { setBusy(false); }
});

elements.closeProducerDialog.addEventListener('click', () => elements.producerDialog.close());
elements.closeFolioDialog.addEventListener('click', () => elements.folioDialog.close());

async function boot() {
    if (!state.token || state.identity?.puede_consultar_oficina_consultas !== true) {
        if (state.token) clearSession();
        return;
    }
    showApp();
    setBusy(true, 'Cargando oficina de consultas…');
    try { await loadBase(); } catch (error) {
        if (error.status !== 401) toast(error.message, true);
    } finally { setBusy(false); }
    runSearchFromUrl();
}

void boot();

elements.folioDialog.addEventListener('close', () => releasePrivateItemImages(elements.folioDialogBody));
