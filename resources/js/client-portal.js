/**
 * Portal de clientes (/oficina/clientes, también /portal). Comparte la sesión de FoliOS
 * web, pero solo usa /api/portal: el servidor rechaza cualquier otra ruta a estas cuentas.
 */
import { OFFICE_IDENTITY_KEY, OFFICE_TOKEN_KEY, officeHomePath } from './shared/folios-entry.js';

const CLIENT_KEY = 'folios_portal_cliente';
const $ = (id) => document.getElementById(id);
const store = {
    get: (key) => { try { return localStorage.getItem(key); } catch { return null; } },
    set: (key, value) => { try { value == null ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch { /* sin almacenamiento */ } },
};
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const number = (value, digits = 0) => new Intl.NumberFormat('es-CL', { maximumFractionDigits: digits, minimumFractionDigits: 0 }).format(Number(value || 0));
const dateTime = (value) => value ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';
const shortDate = (iso) => iso ? new Intl.DateTimeFormat('es-CL', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(`${iso}T00:00:00Z`)) : '—';
const labels = {
    folio: { pendiente_prefrio: 'En prefrío', pendiente_ubicacion: 'Por ubicar', disponible: 'En cámara', bloqueado: 'Retenido', despachado: 'Despachado', agotado: 'Consumido', anulado: 'Anulado', retirado_definitivo: 'Retirado' },
    embarque: { tentativo: 'Tentativo', confirmado: 'Confirmado', cancelado: 'Cancelado' },
    modalidad: { maritimo: 'Marítimo', aereo: 'Aéreo', terrestre: 'Terrestre', por_confirmar: 'Por confirmar' },
    solicitud: { solicitada: 'Esperando respuesta', aceptada: 'Aceptada', rechazada: 'Rechazada', anulada: 'Anulada' },
    carga: { borrador: 'En preparación', pendiente: 'En preparación', en_preparacion: 'En preparación', en_separacion: 'Separando', separada: 'Separada', separacion_completa: 'Separada', despacho_parcial: 'Despacho parcial', despachada: 'Despachada', cerrada: 'Despachada', cancelada: 'Cancelada', retornada: 'Retornada', retorno_parcial: 'Retorno parcial' },
};
const state = { identity: null, client: null, tab: 'resumen', day: null, slot: null, requestId: null, folioPage: 1, receptionPage: 1 };

async function api(path, options = {}) {
    const token = store.get(OFFICE_TOKEN_KEY);
    const headers = { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) };
    let response;
    try {
        response = await fetch(path, { ...options, headers, body: options.body ? JSON.stringify(options.body) : undefined });
    } catch {
        throw Object.assign(new Error('No fue posible conectar con FoliOS. Revisa tu conexión.'), { status: 0 });
    }
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = Object.values(payload.errors || {}).flat()[0];
        throw Object.assign(new Error(validation || payload.message || 'No fue posible completar la consulta.'), { status: response.status });
    }
    return payload;
}

function show(view) {
    $('portalStatus').hidden = view !== 'loading';
    $('portalLogin').hidden = view !== 'login';
    $('portalPassword').hidden = view !== 'password';
    $('portalApp').hidden = view !== 'app';
    $('portalSession').hidden = !['app', 'password'].includes(view);
}

function fail(error, target = 'portalError') {
    if (error.status === 401) return logout(false);
    $(target).textContent = error.message;
}

async function start() {
    show('loading');
    if (!store.get(OFFICE_TOKEN_KEY)) return show('login');
    try {
        const context = await api('/api/portal/contexto');
        enter(context);
    } catch (error) {
        if (error.status === 403) {
            // Cuenta interna con sesión abierta: su lugar es la Oficina.
            const identity = JSON.parse(store.get(OFFICE_IDENTITY_KEY) || 'null');
            const home = officeHomePath(identity?.inicio);
            $('portalStatus').innerHTML = `Este portal es para usuarios de clientes. ${home ? `<a href="${escape(home)}">Volver a tu Oficina</a>` : '<a href="/FoliOS">Ir a FoliOS</a>'}.`;
            return;
        }
        if (error.status === 401) return logout(false);
        $('portalStatus').textContent = error.message;
    }
}

function enter(context) {
    state.identity = context.usuario;
    store.set(OFFICE_IDENTITY_KEY, JSON.stringify(context.usuario));
    $('portalSeason').textContent = context.temporada ? `Temporada ${context.temporada.nombre}` : 'Sin temporada activa';
    const clients = context.usuario.clientes || [];
    const saved = store.get(CLIENT_KEY);
    state.client = clients.find((c) => c.id === saved) || clients[0] || null;
    $('portalClient').innerHTML = clients.map((c) => `<option value="${escape(c.id)}">${escape(c.nombre)}</option>`).join('');
    if (state.client) $('portalClient').value = state.client.id;
    $('portalClientPicker').hidden = clients.length < 2;
    $('portalClientName').hidden = clients.length > 1;
    $('portalClientName').textContent = state.client?.nombre || '';
    if (context.usuario.debe_cambiar_password) {
        $('portalPasswordCancel').hidden = true;
        return show('password');
    }
    show('app');
    openTab((location.hash || '').slice(1) || 'resumen');
}

function clientPath(path) {
    return `/api/portal/clientes/${encodeURIComponent(state.client.id)}${path}`;
}

function openTab(tab) {
    if (!document.querySelector(`[data-panel="${tab}"]`)) tab = 'resumen';
    state.tab = tab;
    document.querySelectorAll('[data-tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
    document.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== tab; });
    if (location.hash.slice(1) !== tab) history.replaceState(null, '', `#${tab}`);
    $('portalError').textContent = '';
    ({ resumen: loadSummary, recepciones: loadReceptions, embarques: loadShipments, horarios: loadSlots, folios: loadFolios, materiales: loadMaterials })[tab]();
}

function kpi(label, value, detail = '') {
    return `<article class="portal-kpi"><span>${escape(label)}</span><strong>${value}</strong>${detail ? `<small>${escape(detail)}</small>` : ''}</article>`;
}

async function loadSummary() {
    $('portalKpis').innerHTML = '<p class="portal-help">Cargando…</p>';
    try {
        const { data: d } = await api(clientPath('/resumen'));
        $('portalKpis').innerHTML = [
            kpi('Kilos recepcionados', `${number(d.kilos_temporada, 1)} kg`, `${number(d.recepciones_temporada)} recepciones · ${number(d.kilos_ultimos_7_dias, 1)} kg en 7 días`),
            kpi('Folios en planta', number(d.folios_en_planta), `${number(d.cajas_en_planta)} cajas`),
            kpi('Embarques próximos', number(d.embarques_proximos)),
            kpi('Solicitudes de carga', number(d.solicitudes_pendientes), 'esperando respuesta'),
            kpi('Ítems de materiales', number(d.items_materiales), 'con existencia'),
        ].join('');
    } catch (error) { fail(error); $('portalKpis').innerHTML = ''; }
}

async function loadReceptions(page = state.receptionPage) {
    state.receptionPage = page;
    const form = new FormData($('portalReceptionFilters'));
    const query = new URLSearchParams({ page: String(page) });
    for (const key of ['desde', 'hasta']) if (form.get(key)) query.set(key, form.get(key));
    $('portalReceptions').innerHTML = '<p class="portal-help">Cargando…</p>';
    try {
        const { data } = await api(clientPath(`/recepciones?${query}`));
        $('portalReceptionTotals').innerHTML = kpi('Total', `${number(data.totales.kilos, 1)} kg`, `${number(data.totales.recepciones)} recepciones`)
            + data.totales.por_especie.map((e) => kpi(e.especie, `${number(e.kilos, 1)} kg`, `${number(e.recepciones)} recepciones`)).join('');
        $('portalReceptions').innerHTML = data.recepciones.data.map((r) => `<article class="eui-panel portal-item">
            <header><strong>${escape(r.numero_recepcion)}</strong><span>${dateTime(r.ingreso_at)}</span><b>${number(r.kilos_netos, 1)} kg</b></header>
            <p>${escape(r.especie || '')}${r.guias.length ? ` · Guía${r.guias.length > 1 ? 's' : ''} ${r.guias.map(escape).join(', ')}` : ''}</p>
            ${r.lotes.length ? `<ul class="portal-lots">${r.lotes.map((l) => `<li><span>Lote ${escape(l.numero_lote)}</span><span>${escape(l.variedad || '')}${l.guia ? ` · guía ${escape(l.guia)}` : ''}</span><b>${number(l.kilos, 1)} kg</b></li>`).join('')}</ul>` : ''}
        </article>`).join('') || '<p class="portal-empty">No hay recepciones cerradas en este período.</p>';
        pager('portalReceptionsPager', data.recepciones.pagina_actual, data.recepciones.ultima_pagina, loadReceptions);
    } catch (error) { fail(error); $('portalReceptions').innerHTML = ''; }
}

async function loadShipments() {
    $('portalShipments').innerHTML = '<p class="portal-help">Cargando…</p>';
    try {
        const { data } = await api(clientPath('/embarques'));
        $('portalShipments').innerHTML = data.map((e) => `<article class="eui-panel portal-item">
            <header><strong>${escape(e.codigo)}</strong><span>${shortDate(e.fecha)} · ${escape(e.hora)}</span><b class="portal-badge portal-badge--${escape(e.estado)}">${escape(labels.embarque[e.estado] || e.estado)}</b></header>
            <p>${escape(labels.modalidad[e.modalidad] || e.modalidad)}${e.nave_vuelo ? ` · ${escape(e.nave_vuelo)}` : ''}${e.puerto_embarque ? ` · ${escape(e.puerto_embarque)}` : ''}${e.contenedor ? ` · Contenedor ${escape(e.contenedor)}` : ''}</p>
            ${e.instructivos.length ? `<ul class="portal-lots">${e.instructivos.map((i) => `<li><span>${escape(i.numero || i.booking || 'Instructivo')}</span><span>${escape(i.destino || '')}</span><b>${i.pallets != null ? `${number(i.pallets)} pallets` : ''}</b></li>`).join('')}</ul>` : ''}
            ${e.carga ? `<p class="portal-progress">Carga: ${escape(labels.carga[e.carga.estado] || e.carga.estado)} · ${number(e.carga.pallets_cargados)} de ${number(e.carga.pallets_asignados)} pallets cargados</p>` : ''}
        </article>`).join('') || '<p class="portal-empty">No tienes embarques en la temporada.</p>';
    } catch (error) { fail(error); $('portalShipments').innerHTML = ''; }
}

function days() {
    const today = new Date();
    return Array.from({ length: 14 }, (_, i) => {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    });
}

async function loadSlots() {
    state.day ||= days()[0];
    $('portalDays').innerHTML = days().map((d) => `<button type="button" class="portal-day" data-day="${d}" aria-pressed="${d === state.day}">${shortDate(d)}</button>`).join('');
    $('portalSlots').innerHTML = '<p class="portal-help">Cargando…</p>';
    $('portalRequest').hidden = true;
    state.slot = null;
    try {
        const [{ data: slots }, { data: requests }] = await Promise.all([
            api(clientPath(`/horarios?desde=${state.day}&dias=1`)), api(clientPath('/solicitudes-carga')),
        ]);
        $('portalSlots').innerHTML = slots.map((s) => `<button type="button" class="portal-slot portal-slot--${s.estado}" data-slot="${escape(s.hora)}" ${s.estado === 'libre' ? '' : 'disabled'}
            aria-label="${escape(s.hora)} a ${escape(s.fin)}: ${s.estado === 'libre' ? 'libre' : s.estado === 'propio' ? 'tuyo' : 'ocupado'}"><span>${escape(s.hora)}</span><small>${s.estado === 'libre' ? 'Libre' : s.estado === 'propio' ? 'Tuyo' : 'Ocupado'}</small></button>`).join('')
            || '<p class="portal-empty">No quedan bloques disponibles este día.</p>';
        renderRequests(requests);
    } catch (error) { fail(error); $('portalSlots').innerHTML = ''; }
}

function renderRequests(requests) {
    $('portalRequests').innerHTML = requests.map((r) => `<article class="eui-panel portal-item">
        <header><strong>${escape(r.codigo)}</strong><span>${shortDate(r.fecha)} · ${escape(r.hora)}</span><b class="portal-badge portal-badge--${escape(r.estado)}">${escape(labels.solicitud[r.estado] || r.estado)}</b></header>
        <p>${escape(labels.modalidad[r.modalidad] || '')}${r.cantidad_pallets ? ` · ${number(r.cantidad_pallets)} pallets` : ''}${r.referencia ? ` · ${escape(r.referencia)}` : ''}</p>
        ${r.embarque ? `<p>Embarque ${escape(r.embarque.codigo)} (${escape(labels.embarque[r.embarque.estado] || r.embarque.estado)})</p>` : ''}
        ${r.motivo_resolucion ? `<p class="portal-reason">${escape(r.motivo_resolucion)}</p>` : ''}
        ${r.estado === 'solicitada' ? `<div class="eui-actions"><button type="button" class="eui-button eui-button--secondary" data-cancel="${escape(r.id)}" data-version="${r.version}">Anular solicitud</button></div>` : ''}
    </article>`).join('') || '<p class="portal-empty">Aún no has solicitado horarios.</p>';
}

function pickSlot(hour) {
    state.slot = hour;
    state.requestId = crypto.randomUUID();
    document.querySelectorAll('[data-slot]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.slot === hour)));
    $('portalRequestTitle').textContent = `Solicitud para el ${shortDate(state.day)} a las ${hour}`;
    $('portalRequestError').textContent = '';
    $('portalRequest').hidden = false;
    $('portalRequest').querySelector('select').focus();
}

async function sendRequest(event) {
    event.preventDefault();
    const element = event.currentTarget;
    const form = new FormData(element);
    const button = element.querySelector('[type="submit"]');
    button.disabled = true;
    try {
        await api(clientPath('/solicitudes-carga'), { method: 'POST', body: {
            operacion_id: state.requestId, fecha: state.day, hora: state.slot, modalidad: form.get('modalidad'),
            cantidad_pallets: form.get('cantidad_pallets') ? Number(form.get('cantidad_pallets')) : null,
            referencia: form.get('referencia') || null, observacion: form.get('observacion') || null,
        } });
        element.reset();
        await loadSlots();
    } catch (error) {
        // Si la respuesta se perdió, reintentar conserva el mismo identificador.
        fail(error, 'portalRequestError');
        if (error.status === 409 || error.status === 422) state.requestId = crypto.randomUUID();
    } finally { button.disabled = false; }
}

async function loadFolios(page = state.folioPage) {
    state.folioPage = page;
    const form = new FormData($('portalFolioFilters'));
    const query = new URLSearchParams({ page: String(page), situacion: form.get('situacion') || 'en_planta' });
    if (form.get('q')) query.set('q', String(form.get('q')).trim());
    $('portalFolios').innerHTML = '<tr><td colspan="6">Cargando…</td></tr>';
    try {
        const result = await api(clientPath(`/folios?${query}`));
        $('portalFolios').innerHTML = result.data.map((f) => `<tr>
            <td><strong>${escape(f.numero_folio)}</strong><small>${f.tipo === 'saldo' ? 'Saldo' : 'Pallet'}</small></td>
            <td data-label="Estado">${escape(labels.folio[f.estado] || f.estado)}</td>
            <td data-label="Producto">${escape([f.especie, f.variedad, f.calibre].filter(Boolean).join(' · '))}<small>${escape([f.marca, f.envase, f.categoria].filter(Boolean).join(' · '))}</small></td>
            <td data-label="Cajas">${number(f.cajas)}</td>
            <td data-label="Cajas por CSG">${f.csg.map((c) => `${escape(c.csg)}: ${number(c.cajas)}`).join('<br>') || '—'}</td>
            <td data-label="Proceso">${f.fecha_proceso ? shortDate(f.fecha_proceso) : '—'}<small>en planta desde ${dateTime(f.en_planta_desde)}</small></td>
        </tr>`).join('') || '<tr><td colspan="6">No hay folios con este criterio.</td></tr>';
        pager('portalFoliosPager', result.meta.pagina_actual, result.meta.ultima_pagina, loadFolios);
    } catch (error) { fail(error); $('portalFolios').innerHTML = ''; }
}

async function loadMaterials() {
    $('portalMaterials').innerHTML = '<tr><td colspan="5">Cargando…</td></tr>';
    try {
        const { data } = await api(clientPath('/materiales'));
        $('portalMaterials').innerHTML = data.map((m) => `<tr><td><strong>${escape(m.codigo)}</strong><small>${escape(m.nombre)}</small></td><td data-label="Categoría">${escape(m.categoria || '')}</td>
            <td data-label="Existencia">${number(m.cantidad, 2)} ${escape(m.unidad || '')}</td><td data-label="Reservado">${number(m.reservada, 2)}</td><td data-label="Disponible">${number(m.disponible, 2)}</td></tr>`).join('')
            || '<tr><td colspan="5">No tienes materiales con existencia en bodega.</td></tr>';
    } catch (error) { fail(error); $('portalMaterials').innerHTML = ''; }
}

function pager(id, current, last, load) {
    $(id).innerHTML = last > 1 ? `<button type="button" class="eui-button eui-button--secondary" ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}">Anterior</button>
        <span>Página ${current} de ${last}</span><button type="button" class="eui-button eui-button--secondary" ${current >= last ? 'disabled' : ''} data-page="${current + 1}">Siguiente</button>` : '';
    $(id).onclick = (event) => { const b = event.target.closest('[data-page]'); if (b) load(Number(b.dataset.page)); };
}

async function logout(callServer = true) {
    if (callServer) await api('/api/acceso-oficina', { method: 'DELETE' }).catch(() => {});
    store.set(OFFICE_TOKEN_KEY, null);
    store.set(OFFICE_IDENTITY_KEY, null);
    state.identity = null;
    show('login');
}

$('portalLogin').addEventListener('submit', async (event) => {
    event.preventDefault();
    const element = event.currentTarget;
    const form = new FormData(element);
    $('portalLoginError').textContent = '';
    try {
        const result = await api('/api/acceso-oficina', { method: 'POST', body: { email: form.get('email'), password: form.get('password') } });
        store.set(OFFICE_TOKEN_KEY, result.token);
        store.set(OFFICE_IDENTITY_KEY, JSON.stringify(result.usuario));
        if (!result.usuario.es_cliente) {
            const home = officeHomePath(result.usuario.inicio);
            if (home) return location.assign(home);
            $('portalLoginError').textContent = 'Tu cuenta no es de cliente. Ingresa por FoliOS.';
            return;
        }
        element.reset();
        start();
    } catch (error) {
        $('portalLoginError').textContent = error.status === 429 ? 'Demasiados intentos. Espera un minuto.' : error.message;
    }
});

$('portalPassword').addEventListener('submit', async (event) => {
    event.preventDefault();
    const element = event.currentTarget;
    const form = new FormData(element);
    $('portalPasswordError').textContent = '';
    try {
        await api('/api/usuario/password', { method: 'PUT', body: Object.fromEntries(form) });
        element.reset();
        $('portalPasswordCancel').hidden = false;
        start();
    } catch (error) { fail(error, 'portalPasswordError'); }
});

$('portalPasswordOpen').addEventListener('click', () => { $('portalPasswordCancel').hidden = false; show('password'); });
$('portalPasswordCancel').addEventListener('click', () => show('app'));
$('portalLogout').addEventListener('click', () => logout());
$('portalClient').addEventListener('change', (event) => {
    state.client = state.identity.clientes.find((c) => c.id === event.target.value) || state.client;
    store.set(CLIENT_KEY, state.client.id);
    state.folioPage = 1; state.receptionPage = 1;
    openTab(state.tab);
});
document.querySelector('.portal-tabs').addEventListener('click', (event) => { const b = event.target.closest('[data-tab]'); if (b) openTab(b.dataset.tab); });
$('portalReceptionFilters').addEventListener('submit', (event) => { event.preventDefault(); loadReceptions(1); });
$('portalFolioFilters').addEventListener('submit', (event) => { event.preventDefault(); loadFolios(1); });
$('portalDays').addEventListener('click', (event) => { const b = event.target.closest('[data-day]'); if (b) { state.day = b.dataset.day; loadSlots(); } });
$('portalSlots').addEventListener('click', (event) => { const b = event.target.closest('[data-slot]:not([disabled])'); if (b) pickSlot(b.dataset.slot); });
$('portalRequest').addEventListener('submit', sendRequest);
$('portalRequestCancel').addEventListener('click', () => { $('portalRequest').hidden = true; document.querySelectorAll('[data-slot]').forEach((b) => b.removeAttribute('aria-pressed')); });
$('portalRequests').addEventListener('click', async (event) => {
    const b = event.target.closest('[data-cancel]');
    if (!b) return;
    b.disabled = true;
    try {
        await api(clientPath(`/solicitudes-carga/${encodeURIComponent(b.dataset.cancel)}/anular`), { method: 'POST', body: { version: Number(b.dataset.version) } });
        await loadSlots();
    } catch (error) { fail(error); b.disabled = false; }
});
window.addEventListener('hashchange', () => { if (state.identity && !$('portalApp').hidden) openTab(location.hash.slice(1)); });

start();
