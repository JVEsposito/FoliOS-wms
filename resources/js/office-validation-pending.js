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
    source: byId('pendingSource'),
    read: byId('pendingReadButton'),
    excel: byId('pendingExcelButton'),
    search: byId('pendingSearch'),
    kpiOrigin: byId('kpiOrigin'),
    kpiValidated: byId('kpiValidated'),
    kpiPending: byId('kpiPending'),
    kpiPendingBoxes: byId('kpiPendingBoxes'),
    kpiUnresolved: byId('kpiUnresolved'),
    kpiDifferences: byId('kpiDifferences'),
    kpiOnlyFolios: byId('kpiOnlyFolios'),
    tabPending: byId('tabPendingCount'),
    tabUnresolved: byId('tabUnresolvedCount'),
    tabDifferences: byId('tabDifferencesCount'),
    tabOnlyFolios: byId('tabOnlyFoliosCount'),
    pendingBody: byId('pendingBody'),
    unresolvedBody: byId('unresolvedBody'),
    differencesBody: byId('differencesBody'),
    onlyFoliosBody: byId('onlyFoliosBody'),
    loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'),
    toasts: byId('officeToasts'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const REFRESH_MS = 60_000;
const situations = {
    observado: 'Observado',
    rechazado: 'Rechazo definitivo',
    conflicto: 'En conflicto',
    anulado: 'Validación anulada',
    sin_folio: 'Sin folio',
};
const fieldLabels = { estado: 'Estado', tipo: 'Tipo', cajas: 'Cajas', calibre: 'Calibre', csg: 'Cajas por CSG' };
const causes = {
    sin_controlador: 'Falta pdo_mysql',
    servidor_inaccesible: 'Sin acceso al servidor',
    acceso_denegado: 'Usuario o clave rechazados',
    sin_permiso_base: 'Sin permiso sobre la base',
    base_inexistente: 'Base inexistente',
    tabla_inexistente: 'Tabla inexistente',
    sin_permiso_tabla: 'Sin permiso sobre la tabla',
    columna_faltante: 'Falta una columna',
    tiempo_agotado: 'Tiempo agotado',
    sin_filas: 'Sin pallets en la bodega',
};
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    data: null,
    timer: null,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function formatDay(value) {
    if (!value) return '—';
    const [year, month, day] = value.split('-');
    return `${day}-${month}-${year}`;
}

/** «hace 3 h», «hace 2 d»: cuánto lleva en bodega sin validar. */
function age(value) {
    if (!value) return '';
    const minutes = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 60000));
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.round(minutes / 60);
    return hours < 48 ? `hace ${hours} h` : `hace ${Math.round(hours / 24)} d`;
}

function number(value) {
    return new Intl.NumberFormat('es-CL').format(Number(value || 0));
}

function busy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}

function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast${error ? ' toast--error' : ''}`;
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
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(path, { ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) clearSession();
    return { ok: response.ok, status: response.status, data };
}

function article(item) {
    const parts = [item.variedad, item.calibre].filter(Boolean).join(' · ');
    const mixed = item.mezclados?.length ? `<small class="pending-warning">Mezcla en origen: ${escapeHtml(item.mezclados.join(', '))}</small>` : '';
    return `<b>${escapeHtml(parts || '—')}</b><small>${escapeHtml(item.envase || '')}</small>${mixed}`;
}

function bulkType(item) {
    const label = item.tipo_bulto === 'saldo' ? 'Saldo' : 'Pallet';
    return `<span class="pending-chip${item.tipo_bulto === 'saldo' ? ' pending-chip--saldo' : ''}">${label}</span>${item.tipo_mezclado ? '<small class="pending-warning">PC/PI mezclado</small>' : ''}`;
}

function matches(item, term) {
    if (!term) return true;
    const text = [
        item.numero, item.variedad, item.calibre, item.envase, item.marca,
        ...(item.csgs || []), ...(item.procesos || []), ...(item.lotes || []),
        item.validacion?.situacion, item.folio?.estado_etiqueta,
    ].join(' ').toLowerCase();
    return text.includes(term);
}

function rows(list, render, empty, columns) {
    const term = elements.search.value.trim().toLowerCase();
    const filtered = list.filter((item) => matches(item, term));
    if (!filtered.length) {
        return `<tr class="pending-empty"><td colspan="${columns}">${term ? 'Sin resultados para la búsqueda.' : empty}</td></tr>`;
    }
    return filtered.map(render).join('');
}

function renderSource(data) {
    const { fuente, lectura } = data;
    const last = lectura.ultima;
    if (!fuente.habilitada || !fuente.conexion) {
        elements.source.className = 'pending-source pending-source--warning';
        elements.source.innerHTML = '<b>Lectura sin configurar.</b> Falta una conexión externa activa en Administración → Accesos → Conexiones externas.';
        return;
    }
    const origin = `${escapeHtml(fuente.conexion)} · ${escapeHtml(fuente.tabla)} · bodega ${escapeHtml(fuente.bodega)}${fuente.temporada ? ` · temporada ${escapeHtml(fuente.temporada)}` : ''}`;
    if (last?.estado === 'fallida') {
        elements.source.className = 'pending-source pending-source--error';
        elements.source.innerHTML = `<b>No se pudo leer el origen (${escapeHtml(causes[last.causa] || 'error')}).</b> ${escapeHtml(last.mensaje || '')} <small>Intento: ${formatDate(last.iniciada_at)} · Fuente: ${origin}${lectura.ultima_correcta_at ? ` · Se muestra la lectura correcta del ${formatDate(lectura.ultima_correcta_at)}` : ''}</small>`;
        return;
    }
    if (!lectura.ultima_correcta_at) {
        elements.source.className = 'pending-source pending-source--warning';
        elements.source.innerHTML = `<b>Todavía no hay una lectura del origen.</b> La primera corre en menos de cinco minutos, o usa «Leer ahora». <small>Fuente: ${origin}</small>`;
        return;
    }
    elements.source.className = `pending-source${lectura.desactualizada ? ' pending-source--warning' : ''}`;
    const changes = last ? ` · ${last.nuevos} nuevos, ${last.modificados} modificados, ${last.retirados} salieron de bodega` : '';
    elements.source.innerHTML = `<b>${lectura.desactualizada ? `Lectura desactualizada: más de ${lectura.vigencia_minutos} min.` : 'Stock de origen al día.'}</b> Última lectura: ${formatDate(lectura.ultima_correcta_at)} (${age(lectura.ultima_correcta_at)})${changes}. <small>Fuente: ${origin} · se lee cada cinco minutos.</small>`;
}

function render() {
    const data = state.data;
    if (!data) return;
    renderSource(data);
    const summary = data.resumen;
    elements.kpiOrigin.textContent = number(summary.en_origen);
    elements.kpiValidated.textContent = number(summary.validados);
    elements.kpiPending.textContent = number(summary.pendientes);
    elements.kpiPendingBoxes.textContent = `${number(summary.cajas_pendientes)} cajas`;
    elements.kpiUnresolved.textContent = number(summary.por_resolver);
    elements.kpiDifferences.textContent = number(summary.con_diferencias);
    elements.kpiOnlyFolios.textContent = number(summary.solo_en_folios);
    elements.tabPending.textContent = summary.pendientes;
    elements.tabUnresolved.textContent = summary.por_resolver;
    elements.tabDifferences.textContent = summary.con_diferencias;
    elements.tabOnlyFolios.textContent = summary.solo_en_folios;

    elements.pendingBody.innerHTML = rows(data.pendientes, (item) => `<tr>
        <td><b class="pending-number">${escapeHtml(item.numero)}</b>${bulkType(item)}</td>
        <td>${formatDate(item.recibido_origen_at)}<small>${age(item.recibido_origen_at)} · embalado ${formatDay(item.fecha_embalaje)}</small></td>
        <td class="pending-num">${number(item.cajas)}</td>
        <td>${article(item)}</td>
        <td>${escapeHtml(item.csgs.join(', ') || '—')}</td>
        <td>${escapeHtml(item.procesos.join(', ') || '—')}<small>${item.lotes.length ? `lote ${escapeHtml(item.lotes.join(', '))}` : 'sin lote'}</small></td>
    </tr>`, 'Todo lo que está en bodega según el origen ya pasó por Validación.', 6);

    elements.unresolvedBody.innerHTML = rows(data.por_resolver, (item) => `<tr>
        <td><b class="pending-number">${escapeHtml(item.numero)}</b>${bulkType(item)}</td>
        <td><span class="pending-chip pending-chip--${escapeHtml(item.validacion.situacion)}">${escapeHtml(situations[item.validacion.situacion] || item.validacion.situacion)}</span></td>
        <td>${escapeHtml([item.validacion.motivo?.replaceAll('_', ' '), item.validacion.observacion].filter(Boolean).join(' · ') || '—')}</td>
        <td>${formatDate(item.validacion.registrado_at)}<small>intento ${escapeHtml(item.validacion.intento)}</small></td>
        <td class="pending-num">${number(item.cajas)}</td>
        <td>${article(item)}</td>
    </tr>`, 'No hay pallets con validaciones observadas, rechazadas o anuladas.', 6);

    elements.differencesBody.innerHTML = rows(data.diferencias, (item) => `<tr>
        <td><b class="pending-number">${escapeHtml(item.numero)}</b>${bulkType(item)}</td>
        <td>${escapeHtml(item.folio.estado_etiqueta)}<small>validado ${formatDate(item.folio.validado_at)}</small></td>
        <td><ul class="pending-differences">${item.diferencias.map((difference) => `<li><b>${escapeHtml(fieldLabels[difference.campo] || difference.campo)}</b><span>origen ${escapeHtml(difference.origen)}</span><span>FoliOS ${escapeHtml(difference.folios ?? '—')}</span></li>`).join('')}</ul></td>
        <td>${article(item)}</td>
    </tr>`, 'Lo validado coincide con el origen.', 4);

    elements.onlyFoliosBody.innerHTML = rows(data.solo_en_folios, (item) => `<tr>
        <td><b class="pending-number">${escapeHtml(item.numero)}</b>${bulkType(item)}</td>
        <td>${escapeHtml(item.estado_etiqueta)}</td>
        <td>${formatDate(item.validado_at)}<small>${age(item.validado_at)}</small></td>
        <td class="pending-num">${number(item.cajas)}</td>
        <td>${article({ ...item, mezclados: [] })}</td>
        <td>${escapeHtml(item.csgs.join(', ') || '—')}</td>
    </tr>`, 'Todo lo validado que sigue en planta figura en el stock del origen.', 6);
}

async function load({ quiet = false } = {}) {
    if (!quiet) busy(true, 'Comparando con el sistema de origen…');
    try {
        const response = await api('/api/validacion/pendientes-origen');
        if (!response.ok) throw new Error(response.data?.message || 'No fue posible cargar los pendientes.');
        state.data = response.data;
        render();
    } catch (error) {
        if (!quiet) toast(error.message, true);
    } finally {
        if (!quiet) busy(false);
    }
}

async function readNow() {
    busy(true, 'Leyendo el stock del sistema de origen…');
    elements.read.disabled = true;
    try {
        const response = await api('/api/validacion/pendientes-origen/leer', { method: 'POST' });
        if (response.data?.resumen) {
            state.data = response.data;
            render();
        }
        toast(response.data?.message || (response.ok ? 'Stock de origen leído.' : 'No fue posible leer el origen.'), !response.ok);
    } catch {
        toast('No fue posible leer el origen.', true);
    } finally {
        elements.read.disabled = false;
        busy(false);
    }
}

async function downloadExcel() {
    busy(true, 'Generando Excel…');
    try {
        const response = await fetch('/api/validacion/pendientes-origen/excel', {
            headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json', Authorization: `Bearer ${state.token}` },
        });
        if (!response.ok) throw new Error('No fue posible generar el Excel.');
        const name = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/)?.[1] || 'pendientes-por-validar.xlsx';
        const url = URL.createObjectURL(await response.blob());
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = name;
        anchor.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toast(error.message, true);
    } finally {
        busy(false);
    }
}

function showApp() {
    if (state.identity?.puede_consultar_validaciones_pallet !== true) return false;
    elements.access.classList.add('is-hidden');
    elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    if (elements.userName) elements.userName.textContent = name;
    if (elements.userRole) elements.userRole.textContent = String(state.identity?.rol || '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    if (elements.initials) elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return true;
}

function start() {
    load();
    window.clearInterval(state.timer);
    state.timer = window.setInterval(() => {
        if (document.visibilityState === 'visible') load({ quiet: true });
    }, REFRESH_MS);
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    const response = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(elements.login))) });
    if (!response.ok) {
        elements.loginError.textContent = Object.values(response.data?.errors || {}).flat()[0] || response.data?.message || 'No fue posible ingresar.';
        return;
    }
    state.token = response.data.token;
    state.identity = response.data.usuario;
    localStorage.setItem(keys.token, state.token);
    localStorage.setItem(keys.identity, JSON.stringify(state.identity));
    if (!showApp()) {
        elements.loginError.textContent = 'Tu perfil no puede consultar validaciones de pallets.';
        return;
    }
    start();
});
elements.logout?.addEventListener('click', clearSession);
elements.read.addEventListener('click', readNow);
elements.excel.addEventListener('click', downloadExcel);
elements.search.addEventListener('input', render);

if (state.token && showApp()) start();
