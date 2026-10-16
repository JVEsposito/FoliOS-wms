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
    catalogNote: byId('pendingCatalogNote'),
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
    tabCatalog: byId('tabCatalogCount'),
    pendingBody: byId('pendingBody'),
    unresolvedBody: byId('unresolvedBody'),
    differencesBody: byId('differencesBody'),
    onlyFoliosBody: byId('onlyFoliosBody'),
    catalogBody: byId('catalogBody'),
    catalogHelp: byId('catalogHelp'),
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
const requirements = {
    cliente: (item) => `Primero vincula el cliente ${item.cliente}.`,
    especie: (item) => `Primero vincula o agrega la especie ${item.especie}.`,
};
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

/** Mismo criterio que el servidor: sin distinguir mayúsculas, tildes ni espacios. */
function sameText(first, second) {
    const key = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
    return key(first) === key(second);
}

/** Artículo del pallet. En un folio no hay sugerencias: la categoría es la que quedó validada. */
function article(item, { folio = false } = {}) {
    const main = [item.variedad, item.calibre].filter(Boolean).join(' · ');
    const detail = [item.especie, item.envase].filter(Boolean).join(' · ');
    const owner = [item.cliente || item.empresa, item.marca].filter(Boolean).join(' · ');
    let category = '';
    if (folio) {
        category = item.categoria ? `<small>Categoría ${escapeHtml(item.categoria)}</small>` : '';
    } else if (item.categoria_sugerida) {
        const original = item.categoria && !sameText(item.categoria, item.categoria_sugerida) ? ` (${item.categoria} en origen)` : '';
        category = `<small>Categoría sugerida: <b>${escapeHtml(item.categoria_sugerida)}</b>${escapeHtml(original)}</small>`;
    } else if (item.categoria) {
        category = `<small>Categoría en origen: ${escapeHtml(item.categoria)}</small>`;
    }
    const mixed = item.mezclados?.length ? `<small class="pending-warning">Mezcla en origen: ${escapeHtml(item.mezclados.join(', '))}</small>` : '';
    const missing = item.sin_catalogo?.length ? `<small class="pending-warning">Falta en catálogo: ${escapeHtml(item.sin_catalogo.join(', '))}</small>` : '';
    return `<b>${escapeHtml(main || '—')}</b><small>${escapeHtml(detail)}</small>${owner ? `<small>${escapeHtml(owner)}</small>` : ''}${category}${mixed}${missing}`;
}

function differenceItem(difference, suggestion = false) {
    const label = difference.etiqueta || fieldLabels[difference.campo] || difference.campo;
    const catalog = difference.catalogo && !sameText(difference.catalogo, difference.origen) ? ` → ${difference.catalogo}` : '';
    return `<li${suggestion ? ' class="pending-suggestion"' : ''}><b>${escapeHtml(label)}${suggestion ? ' · sugerida' : ''}</b><span>origen ${escapeHtml(difference.origen)}${escapeHtml(catalog)}</span><span>FoliOS ${escapeHtml(difference.folios ?? '—')}</span></li>`;
}

function bulkType(item) {
    const label = item.tipo_bulto === 'saldo' ? 'Saldo' : 'Pallet';
    return `<span class="pending-chip${item.tipo_bulto === 'saldo' ? ' pending-chip--saldo' : ''}">${label}</span>${item.tipo_mezclado ? '<small class="pending-warning">PC/PI mezclado</small>' : ''}`;
}

function matches(item, term) {
    if (!term) return true;
    const text = [
        item.numero, item.empresa, item.cliente, item.especie, item.variedad, item.calibre, item.categoria,
        item.categoria_sugerida, item.envase_codigo, item.envase, item.marca,
        ...(item.csgs || []), ...(item.procesos || []), ...(item.lotes || []),
        item.validacion?.situacion, item.folio?.estado_etiqueta,
        // Catálogo: el dato, su valor y en qué pallets está.
        item.dimension_etiqueta, item.etiqueta, item.csg, ...(item.pallets || []),
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
    elements.tabCatalog.textContent = summary.catalogo_pendiente;
    renderCatalogNote(data);

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
        <td><ul class="pending-differences">${item.diferencias.map((difference) => differenceItem(difference)).join('')}${(item.sugerencias || []).map((difference) => differenceItem(difference, true)).join('')}</ul></td>
        <td>${article(item)}</td>
    </tr>`, 'Lo validado coincide con el origen.', 4);

    elements.onlyFoliosBody.innerHTML = rows(data.solo_en_folios, (item) => `<tr>
        <td><b class="pending-number">${escapeHtml(item.numero)}</b>${bulkType(item)}</td>
        <td>${escapeHtml(item.estado_etiqueta)}</td>
        <td>${formatDate(item.validado_at)}<small>${age(item.validado_at)}</small></td>
        <td class="pending-num">${number(item.cajas)}</td>
        <td>${article(item, { folio: true })}</td>
        <td>${escapeHtml(item.csgs.join(', ') || '—')}</td>
    </tr>`, 'Todo lo validado que sigue en planta figura en el stock del origen.', 6);

    renderCatalog(data);
}

function renderCatalogNote(data) {
    const summary = data.resumen;
    if (!data.fuente.temporada_catalogo) {
        elements.catalogNote.hidden = false;
        elements.catalogNote.innerHTML = '<b>No hay temporada activa.</b> Sin catálogo de validación no se comparan cliente, marca, especie, variedad ni embalaje.';
        return;
    }
    elements.catalogNote.hidden = !summary.catalogo_pendiente;
    elements.catalogNote.innerHTML = summary.catalogo_pendiente
        ? `<b>El catálogo de ${escapeHtml(data.fuente.temporada_catalogo)} no reconoce ${number(summary.catalogo_pendiente)} ${summary.catalogo_pendiente === 1 ? 'dato' : 'datos'} del origen</b> (${number(summary.pallets_sin_catalogo)} ${summary.pallets_sin_catalogo === 1 ? 'pallet' : 'pallets'}). Esos datos no se comparan ni se podrán validar hasta vincularlos o agregarlos en la pestaña «Catálogo».`
        : '';
}

function catalogContext(item) {
    const parts = [];
    if (item.dimension === 'categoria') parts.push('Cada cliente usa su nomenclatura: vincúlala a la categoría que corresponde. En la validación solo se sugiere.');
    if (item.dimension === 'cliente') parts.push('Código de empresa del origen. Queda como alias del cliente.');
    if (['marca', 'envase'].includes(item.dimension) && item.cliente) parts.push(`Cliente ${item.cliente}`);
    if (['variedad', 'calibre', 'envase'].includes(item.dimension) && item.especie) parts.push(`Especie ${item.especie}`);
    if (item.dimension === 'especie' && item.codigo) parts.push(`Código ${item.codigo}`);
    if (item.dimension === 'variedad' && item.codigo) parts.push(`Código ${item.codigo}`);
    if (item.dimension === 'envase' && item.kilos) parts.push(`${number(item.kilos)} kg netos por caja`);
    if (item.dimension === 'csg') {
        if (item.predio) parts.push(`Predio ${item.predio}`);
        if (item.variedades?.length) parts.push(`Variedades: ${item.variedades.join(', ')}`);
    }
    if (item.dimension === 'csg_variedad') parts.push(`La variedad ${item.variedad} no está autorizada en el CSG ${item.csg}.`);
    return parts.map((part) => `<small>${escapeHtml(part)}</small>`).join('') || '<small>—</small>';
}

function catalogOptions(item) {
    const options = state.data?.opciones_catalogo || {};
    switch (item.dimension) {
    case 'cliente': return options.clientes || [];
    case 'marca': return options.marcas?.[item.cliente_validacion_id] || [];
    case 'especie': return options.especies || [];
    case 'variedad': return options.variedades?.[item.especie_validacion_id] || [];
    case 'calibre': return options.calibres?.[item.especie_validacion_id] || [];
    case 'envase': return options.envases?.[`${item.especie_validacion_id}|${item.cliente_validacion_id}`] || [];
    case 'categoria': return options.categorias || [];
    default: return [];
    }
}

function catalogActions(item) {
    if (item.requiere) return `<small class="pending-warning">${escapeHtml(requirements[item.requiere]?.(item) || 'Falta un dato previo.')}</small>`;
    if (!state.data.puede_administrar_catalogo) return '<small>Lo resuelve quien administra el catálogo de validación.</small>';
    const key = escapeHtml(item.clave);
    const parts = [];
    if (item.acciones.includes('vincular')) {
        const options = catalogOptions(item);
        if (options.length) {
            parts.push(`<div class="pending-link"><select data-catalog-entity="${key}" aria-label="${escapeHtml(`${item.dimension_etiqueta} del catálogo para ${item.etiqueta}`)}"><option value="">Corresponde a…</option>${options.map((option) => `<option value="${escapeHtml(option.id)}">${escapeHtml(option.nombre)}${option.codigo_externo ? ` · ${escapeHtml(option.codigo_externo)}` : ''}</option>`).join('')}</select><button class="secondary-button" type="button" data-catalog-action="vincular" data-catalog-key="${key}">Vincular</button></div>`);
        } else {
            parts.push(`<small>${item.dimension === 'cliente' ? 'No hay clientes activos. Créalo en Clientes y vuelve a vincularlo aquí.' : 'No hay elementos del catálogo para vincular.'}</small>`);
        }
    }
    if (item.acciones.includes('incorporar')) {
        parts.push(`<button class="primary-button" type="button" data-catalog-action="incorporar" data-catalog-key="${key}">${item.dimension === 'csg_variedad' ? 'Autorizar en el CSG' : 'Agregar al catálogo'}</button>`);
    }
    return `<div class="pending-catalog-actions">${parts.join('')}</div>`;
}

function renderCatalog(data) {
    // Una recarga no borra lo que alguien estaba eligiendo.
    const chosen = new Map([...elements.catalogBody.querySelectorAll('select[data-catalog-entity]')].map((select) => [select.dataset.catalogEntity, select.value]));
    elements.catalogHelp.textContent = data.puede_administrar_catalogo
        ? 'Vincula un dato a un elemento que ya existe, o agrégalo al catálogo de la temporada. Lo que se agrega queda en Maestros de temporada.'
        : 'Puedes ver qué falta. Vincular o agregar al catálogo lo hace quien administra el catálogo de validación.';
    elements.catalogBody.innerHTML = rows(data.catalogo || [], (item) => `<tr>
        <td><b>${escapeHtml(item.dimension_etiqueta)}</b></td>
        <td><b class="pending-number">${escapeHtml(item.etiqueta)}</b></td>
        <td>${catalogContext(item)}</td>
        <td class="pending-num">${number(item.pallets_total)}<small>${number(item.cajas)} cajas</small><small class="pending-catalog-pallets">${escapeHtml(item.pallets.join(', '))}${item.pallets_total > item.pallets.length ? '…' : ''}</small></td>
        <td>${catalogActions(item)}</td>
    </tr>`, data.fuente.temporada_catalogo ? 'El catálogo de la temporada reconoce todo lo que trae el origen.' : 'No hay temporada activa.', 5);
    elements.catalogBody.querySelectorAll('select[data-catalog-entity]').forEach((select) => {
        const value = chosen.get(select.dataset.catalogEntity);
        if (value && [...select.options].some((option) => option.value === value)) select.value = value;
    });
}

async function applyCatalog(button) {
    const key = button.dataset.catalogKey;
    const action = button.dataset.catalogAction;
    let entity = null;
    if (action === 'vincular') {
        const select = [...elements.catalogBody.querySelectorAll('select[data-catalog-entity]')].find((node) => node.dataset.catalogEntity === key);
        entity = select?.value || null;
        if (!entity) {
            toast('Elige a qué elemento del catálogo corresponde.', true);
            select?.focus();
            return;
        }
    }
    busy(true, action === 'vincular' ? 'Vinculando con el catálogo…' : 'Agregando al catálogo…');
    try {
        const response = await api('/api/validacion/pendientes-origen/catalogo', {
            method: 'POST',
            body: JSON.stringify({ clave: key, accion: action, entidad_id: entity }),
        });
        if (response.data?.resumen) {
            state.data = response.data;
            render();
        }
        toast(response.data?.message || (response.ok ? 'Catálogo actualizado.' : 'No fue posible actualizar el catálogo.'), !response.ok);
        if (!response.ok && !response.data?.resumen) {
            // Quizá otra persona ya lo resolvió: se vuelve a comparar.
            button.blur();
            load({ quiet: true });
        }
    } catch {
        toast('No fue posible actualizar el catálogo.', true);
    } finally {
        busy(false);
    }
}

async function load({ quiet = false } = {}) {
    // No recargar mientras alguien elige en el catálogo.
    if (quiet && elements.catalogBody.contains(document.activeElement)) return;
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
elements.catalogBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-catalog-action]');
    if (button) applyCatalog(button);
});

if (state.token && showApp()) start();
