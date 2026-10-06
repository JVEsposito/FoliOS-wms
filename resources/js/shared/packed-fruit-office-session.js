import { createOfficeSessionSync } from './office-session-sync.js';
export const byId = (id) => document.getElementById(id);
export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export function message(text = '', error = false) { byId('rfeMessage').textContent = text; byId('rfeMessage').dataset.error = String(error); }
export class ApiError extends Error { constructor(text, status) { super(text); this.status = status; } }
export function officeSession(permission, load, reset) {
    const tokenKey = 'estiba_wms_office_token';
    const identityKey = 'estiba_wms_office_identity';
    let token = null;
    let identity = null;
    const can = (key) => identity?.capacidades?.[key] === true || identity?.[key] === true;
    const request = async (path, init = {}) => {
        const startedToken = token;
        const headers = new Headers(init.headers);
        headers.set('Accept', init.pdf ? 'application/pdf' : 'application/json');
        if (startedToken) headers.set('Authorization', `Bearer ${startedToken}`);
        if (init.body) headers.set('Content-Type', 'application/json');
        let response;
        try { response = await fetch(path, { ...init, headers }); }
        catch { throw new ApiError('No hay conexión. Reintenta el guardado cuando vuelva la red.', 0); }
        if (startedToken !== token) throw new ApiError('La sesión cambió. Vuelve a abrir la recepción.', 409);
        if (response.status === 304) return { notModified: true };
        if (response.ok && init.pdf) return response.blob();
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 401) { localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey); sync.reload(false, true); }
            throw new ApiError(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'No fue posible completar la operación.', response.status);
        }
        return { ...data, etag: response.headers.get('ETag') };
    };
    const sync = createOfficeSessionSync({
        tokenKey, getToken: () => localStorage.getItem(tokenKey),
        onSessionChange: (next) => {
            token = next;
            try { identity = JSON.parse(localStorage.getItem(identityKey) || 'null'); } catch { identity = null; }
            reset();
            byId('officeApp').classList.add('is-hidden'); byId('officeAccess').classList.remove('is-hidden');
            message();
        },
        load: async (_, isCurrent) => {
            await request('/api/oficina/contexto');
            if (!isCurrent()) return;
            identity = JSON.parse(localStorage.getItem(identityKey) || '{}');
            if (!can(permission)) throw new Error('Tu perfil no tiene acceso a esta pantalla. Si acabas de actualizar el sistema, cierra la sesión e ingresa nuevamente.');
            byId('officeApp').classList.remove('is-hidden'); byId('officeAccess').classList.add('is-hidden');
            byId('officeUserName').textContent = identity.nombre ?? 'Oficina';
            byId('officeUserRole').textContent = identity.rol ?? '';
            byId('officeInitials').textContent = (identity.nombre ?? 'OF').slice(0, 2).toUpperCase();
            await load(isCurrent);
        },
        onError: (error) => { message(error.message, true); byId('officeLoginError').textContent = error.message; },
    });
    byId('officeLoginForm').addEventListener('submit', async (event) => {
        event.preventDefault(); const button = event.submitter; button.disabled = true;
        try {
            const data = await request('/api/acceso-oficina', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(event.target))) });
            localStorage.setItem(tokenKey, data.token); localStorage.setItem(identityKey, JSON.stringify(data.usuario));
            window.dispatchEvent(new Event('estiba:office-session'));
        } catch (error) { byId('officeLoginError').textContent = error.message; } finally { button.disabled = false; }
    });
    byId('officeLogoutButton')?.addEventListener('click', async () => {
        try { await request('/api/acceso-oficina', { method: 'DELETE' }); } catch { /* Cierra la sesión local aun sin red. */ }
        localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey);
        window.dispatchEvent(new Event('estiba:office-session'));
    });
    byId('reloadButton').addEventListener('click', () => sync.reload(true));
    return { request, can, start: () => sync.start(), reload: () => sync.reload(true) };
}
