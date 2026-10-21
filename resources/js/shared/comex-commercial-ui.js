export const $ = (id) => document.getElementById(id);
export const escape = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);
export const choices = (items, empty = '') => (empty ? `<option value="">${escape(empty)}</option>` : '') + items.map(([id, label]) => `<option value="${escape(id)}">${escape(label)}</option>`).join('');
export const table = (headers, rows) => `<table><thead><tr>${headers.map((h) => `<th>${escape(h)}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td colspan="${headers.length}">Sin registros.</td></tr>`}</tbody></table>`;
export const values = (f) => Object.fromEntries([...new FormData(f)].filter(([, v]) => v !== ''));
export const roleLabels = { operador: 'Operador de planta', vendedor: 'Vendedor', exportador: 'Exportador legal', propietario: 'Propietario económico', comprador: 'Comprador', recibidor: 'Recibidor', consignatario: 'Consignatario', notify: 'Notify party', pagador: 'Pagador', naviera: 'Naviera', forwarder: 'Forwarder', agente_aduana: 'Agencia de aduana', transportista: 'Transportista' };
export function open(id) { $(id).querySelector('[data-cc-error]')?.replaceChildren(); if (!$(id).open) $(id).showModal(); }
export function pages(id, p, callback) {
    $(id).replaceChildren();
    for (const [label, n] of [['Anterior', p.current_page - 1], ['Siguiente', p.current_page + 1]]) {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'secondary-button'; b.textContent = label;
        b.disabled = n < 1 || n > p.last_page; b.onclick = () => safe(() => callback(n)); $(id).append(b);
    }
    $(id).append(document.createTextNode(` Página ${p.current_page} de ${p.last_page}`));
}
export const message = (text) => { $('ccMessage').textContent = text; };
export async function safe(fn) { try { await fn(); } catch (e) { message(e.message); } }
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
let identity;
export const capability = (key) => identity?.[key] === true || identity?.capacidades?.[key] === true;
export async function api(path, method = 'GET', data) {
    let r;
    try { r = await fetch(path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem(keys.token) || ''}` }, ...(data ? { body: JSON.stringify(data) } : {}) }); }
    catch { throw new Error('No fue posible conectar. Reintente la misma solicitud.'); }
    const body = await r.json().catch(() => ({}));
    if (r.status === 401) { clear(); throw new Error('Ingrese nuevamente para continuar.'); }
    if (!r.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'No fue posible completar la operación.');
    return body;
}
function clear() { localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity); document.querySelectorAll('dialog[open]').forEach((d) => d.close()); $('officeApp').classList.add('is-hidden'); $('officeAccess').classList.remove('is-hidden'); }
export function startCommercial(onLoad, readCapability = 'puede_consultar_comercial_comex') {
    async function show(user) {
        identity = user; localStorage.setItem(keys.identity, JSON.stringify(user));
        if (!capability(readCapability)) throw new Error('Su perfil no tiene acceso a esta sección.');
        $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden');
        $('officeUserName').textContent = user.nombre; $('officeUserRole').textContent = user.rol;
        $('officeInitials').textContent = user.nombre.split(/\s+/).slice(0, 2).map((s) => s[0]).join('');
        await onLoad();
    }
    $('officeLoginForm').addEventListener('submit', async (e) => {
        e.preventDefault(); const b = e.submitter; if (b.disabled) return; b.disabled = true;
        try { const r = await api('/api/acceso-oficina', 'POST', values(e.target)); localStorage.setItem(keys.token, r.token); await show(r.usuario); }
        catch (error) { $('officeLoginError').textContent = error.message; } finally { b.disabled = false; }
    });
    $('officeLogoutButton')?.addEventListener('click', async () => { try { await api('/api/acceso-oficina', 'DELETE'); } finally { clear(); location.reload(); } });
    document.addEventListener('click', (e) => e.target.closest('[data-cc-close]')?.closest('dialog').close());
    if (localStorage.getItem(keys.token)) (async () => {
        try { const r = await api('/api/acceso-oficina'); await show(r.usuario); }
        catch (error) { $('officeLoginError').textContent = error.message; message(error.message); }
    })();
}
