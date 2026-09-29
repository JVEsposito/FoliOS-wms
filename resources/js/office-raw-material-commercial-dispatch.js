const byId = (id) => document.getElementById(id);
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const endpoint = '/api/materia-prima/despachos-comerciales';
const state = { token: localStorage.getItem(keys.token), identity: readIdentity(), available: [], dispatches: [], selected: new Set(), confirming: null, busy: false };

function readIdentity() { try { return JSON.parse(localStorage.getItem(keys.identity) || 'null'); } catch { return null; } }
function escapeHtml(value) { return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
function number(value, digits = 0) { return new Intl.NumberFormat('es-CL', { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(Number(value || 0)); }
function date(value) { return value ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'; }
function can(name) { return state.identity?.capacidades?.[name] === true || state.identity?.[name] === true; }
function busy(value) { state.busy = value; byId('officeLoading').classList.toggle('is-hidden', !value); }
function toast(message, error = false) { const item = document.createElement('div'); item.className = `toast${error ? ' toast--error' : ''}`; item.textContent = message; byId('officeToasts').append(item); setTimeout(() => item.remove(), 5500); }
function errorMessage(data) { return Object.values(data?.errors || {}).flat()[0] || data?.message || 'No fue posible completar la operación.'; }
function hideSession() { state.token = null; state.identity = null; localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); byId('officeApp').classList.add('is-hidden'); byId('officeAccess').classList.remove('is-hidden'); }

async function api(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}) };
    let response;
    try { response = await fetch(path, { ...options, headers }); } catch { throw new Error('No fue posible conectar con el servidor.'); }
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) { if (response.status === 401 && path !== '/api/acceso-oficina') hideSession(); throw new Error(errorMessage(payload)); }
    return payload;
}

function showApp() {
    if (!can('puede_consultar_despacho_comercial')) return false;
    byId('officeAccess').classList.add('is-hidden'); byId('officeApp').classList.remove('is-hidden');
    byId('officeUserName').textContent = state.identity?.nombre || 'Oficina';
    byId('officeUserRole').textContent = state.identity?.perfil_acceso?.nombre || state.identity?.rol || 'Oficina';
    byId('officeInitials').textContent = (state.identity?.nombre || 'OF').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    byId('creationPanel').classList.toggle('is-hidden', !can('puede_gestionar_despacho_comercial'));
    return true;
}

async function reload() {
    if (!state.token || !showApp()) return;
    busy(true);
    try {
        const [available, dispatches] = await Promise.all([api(`${endpoint}/bins-disponibles`), api(endpoint)]);
        state.available = available.data || []; state.dispatches = dispatches.data || [];
        const availableIds = new Set(state.available.map((bin) => bin.id));
        state.selected = new Set([...state.selected].filter((id) => availableIds.has(id)));
        render();
    } catch (error) { toast(error.message, true); }
    finally { busy(false); }
}

function renderAvailable() {
    const search = byId('binSearch').value.trim().toLocaleLowerCase('es-CL');
    const matches = state.available.filter((bin) => !search || [bin.folio, bin.clasificacion, ...bin.origenes.flatMap((origen) => [origen.lote, origen.orden])].some((value) => String(value || '').toLocaleLowerCase('es-CL').includes(search)));
    byId('availableBins').innerHTML = matches.length ? matches.map((bin) => `
        <label class="commercial-bin"><input type="checkbox" data-bin-id="${escapeHtml(bin.id)}" ${state.selected.has(bin.id) ? 'checked' : ''}>
            <span><strong>${escapeHtml(bin.folio)}</strong><small>${escapeHtml(bin.clasificacion)} · ${number(bin.kilos, 3)} kg · ${escapeHtml(bin.origenes.map((origen) => origen.lote || origen.orden).filter(Boolean).join(', ') || 'Origen registrado')}</small></span>
        </label>`).join('') : '<p class="commercial-empty">No hay bins regularizados disponibles con ese criterio.</p>';
    const selected = state.available.filter((bin) => state.selected.has(bin.id));
    byId('selectedSummary').textContent = selected.length ? `${selected.length} bin(es) · ${number(selected.reduce((sum, bin) => sum + Number(bin.kilos), 0), 3)} kg definitivos` : 'Selecciona al menos un bin.';
}

function renderDispatches() {
    byId('dispatchList').innerHTML = state.dispatches.length ? state.dispatches.map((dispatch) => {
        const rows = dispatch.bins || [];
        const status = { borrador: 'Borrador · reservado', confirmado: 'Confirmado · salió', cancelado: 'Cancelado' }[dispatch.estado] || dispatch.estado;
        return `<article class="commercial-dispatch">
            <div class="commercial-dispatch__head"><div><strong>${escapeHtml(dispatch.numero)} · ${escapeHtml(dispatch.destinatario)}</strong><small>${date(dispatch.created_at)} · ${escapeHtml(dispatch.creado_por?.name || '')}</small></div><span class="commercial-status ${dispatch.estado === 'confirmado' ? 'is-confirmed' : ''}">${escapeHtml(status)}</span></div>
            <p>Guía SII: <strong>${escapeHtml(dispatch.numero_guia_sii || 'Pendiente de registrar')}</strong> · ${rows.length} bin(es) · ${number(rows.reduce((sum, row) => sum + Number(row.kilos_definitivos), 0), 3)} kg</p>
            <ul class="commercial-details">${rows.map((row) => `<li><strong>${escapeHtml(row.folio_definitivo)}</strong> · ${escapeHtml(row.clasificacion)} · ${number(row.kilos_definitivos, 3)} kg</li>`).join('')}</ul>
            <div class="commercial-actions">${dispatch.estado === 'borrador' && can('puede_gestionar_despacho_comercial') ? `<button type="button" class="primary-button" data-confirm="${escapeHtml(dispatch.id)}">Registrar guía y confirmar</button><button type="button" class="secondary-button" data-cancel="${escapeHtml(dispatch.id)}">Cancelar borrador</button>` : ''}<button type="button" class="secondary-button" data-print="${escapeHtml(dispatch.id)}">Imprimir comprobante interno</button></div>
        </article>`;
    }).join('') : '<p class="commercial-empty">Aún no hay despachos de retornos en esta temporada.</p>';
}
function render() { renderAvailable(); renderDispatches(); }

function printDispatch(dispatch) {
    byId('printRecord').innerHTML = `<h1>FoliOS · Comprobante interno de despacho</h1>
        <p><strong>Documento operacional interno. No es una guía electrónica ni sustituye el DTE del SII.</strong></p>
        <p>Número: ${escapeHtml(dispatch.numero)} · Estado: ${escapeHtml(dispatch.estado)} · Fecha: ${date(dispatch.confirmado_at || dispatch.created_at)}</p>
        <p>Destinatario: ${escapeHtml(dispatch.destinatario)} · Guía SII: ${escapeHtml(dispatch.numero_guia_sii || 'Pendiente')}</p>
        <p>Observación: ${escapeHtml(dispatch.observacion || '—')}</p>
        <table><thead><tr><th>Folio bin</th><th>Clasificación</th><th>Kilos definitivos</th></tr></thead><tbody>${(dispatch.bins || []).map((bin) => `<tr><td>${escapeHtml(bin.folio_definitivo)}</td><td>${escapeHtml(bin.clasificacion)}</td><td>${number(bin.kilos_definitivos, 3)} kg</td></tr>`).join('')}</tbody></table>`;
    window.print();
}

function csvCell(value) {
    const raw = String(value ?? '').replace(/^[\s\x00-\x1f]+/, '');
    const safe = /^[=+\-@]/.test(raw) ? `'${raw}` : raw;
    return `"${safe.replaceAll('"', '""')}"`;
}
function downloadCsv() {
    const rows = [['Despacho', 'Estado', 'Destinatario', 'Guía SII', 'Folio bin', 'Clasificación', 'Kilos definitivos', 'Confirmado']];
    state.dispatches.forEach((dispatch) => (dispatch.bins || []).forEach((bin) => rows.push([dispatch.numero, dispatch.estado, dispatch.destinatario, dispatch.numero_guia_sii || '', bin.folio_definitivo, bin.clasificacion, bin.kilos_definitivos, dispatch.confirmado_at || ''])));
    const blob = new Blob([`\ufeff${rows.map((row) => row.map(csvCell).join(';')).join('\r\n')}\r\n`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'despachos-comerciales-retornos.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}

byId('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault(); byId('officeLoginError').textContent = '';
    try {
        const form = new FormData(event.currentTarget);
        const payload = await api('/api/acceso-oficina', { method: 'POST', body: JSON.stringify({ email: form.get('email'), password: form.get('password') }) });
        if (!payload.usuario?.capacidades?.puede_consultar_despacho_comercial) throw new Error('Esta cuenta no tiene acceso al despacho comercial.');
        state.token = payload.token; state.identity = payload.usuario;
        localStorage.setItem(keys.token, payload.token); localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
        showApp(); reload();
    } catch (error) { byId('officeLoginError').textContent = error.message; }
});
byId('officeLogoutButton').addEventListener('click', () => { if (state.token) api('/api/acceso-oficina', { method: 'DELETE' }).catch(() => {}); hideSession(); });
byId('reloadButton').addEventListener('click', reload);
byId('binSearch').addEventListener('input', renderAvailable);
byId('availableBins').addEventListener('change', (event) => { const id = event.target.dataset.binId; if (!id) return; if (event.target.checked) state.selected.add(id); else state.selected.delete(id); renderAvailable(); });
byId('createDispatchForm').addEventListener('submit', async (event) => {
    event.preventDefault(); if (state.busy) return;
    byId('createError').textContent = '';
    if (!state.selected.size) { byId('createError').textContent = 'Selecciona al menos un bin.'; return; }
    const form = new FormData(event.currentTarget); busy(true);
    try {
        const response = await api(endpoint, { method: 'POST', body: JSON.stringify({ destinatario: form.get('destinatario'), observacion: form.get('observacion'), bins: [...state.selected] }) });
        toast(`Borrador ${response.data.numero} creado. Emite la guía SII antes de confirmar.`);
        event.currentTarget.reset(); state.selected.clear(); await reload();
    } catch (error) { byId('createError').textContent = error.message; }
    finally { busy(false); }
});
byId('dispatchList').addEventListener('click', async (event) => {
    const button = event.target.closest('button'); if (!button || state.busy) return;
    const id = button.dataset.confirm || button.dataset.cancel || button.dataset.print;
    const dispatch = state.dispatches.find((item) => item.id === id); if (!dispatch) return;
    if (button.dataset.print) { printDispatch(dispatch); return; }
    if (button.dataset.confirm) { state.confirming = id; byId('confirmTitle').textContent = `${dispatch.numero} · Guía SII`; byId('confirmError').textContent = ''; byId('confirmForm').reset(); byId('confirmDialog').showModal(); return; }
    if (!window.confirm(`¿Cancelar el borrador ${dispatch.numero} y liberar sus bins?`)) return;
    busy(true);
    try { await api(`${endpoint}/${id}/cancelar`, { method: 'POST' }); toast(`Borrador ${dispatch.numero} cancelado.`); await reload(); }
    catch (error) { toast(error.message, true); } finally { busy(false); }
});
byId('cancelConfirmButton').addEventListener('click', () => byId('confirmDialog').close());
byId('confirmForm').addEventListener('submit', async (event) => {
    event.preventDefault(); if (state.busy || !state.confirming) return;
    byId('confirmError').textContent = ''; busy(true);
    try {
        const guide = new FormData(event.currentTarget).get('numero_guia_sii');
        await api(`${endpoint}/${state.confirming}/confirmar`, { method: 'POST', body: JSON.stringify({ numero_guia_sii: guide }) });
        byId('confirmDialog').close(); toast(`Salida confirmada con guía SII ${guide}.`); state.confirming = null; await reload();
    } catch (error) { byId('confirmError').textContent = error.message; } finally { busy(false); }
});
byId('downloadCsvButton').addEventListener('click', downloadCsv);
if (state.token && showApp()) reload();
