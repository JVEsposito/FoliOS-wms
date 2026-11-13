export function communicationsSession() {
    try {
        const token = localStorage.getItem('estiba_wms_office_token');
        const user = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
        if (!token || !user || user.es_cliente || user.rol === 'cliente') return null;
        return { token, id: user.id, nombre: user.nombre || user.name || 'Usuario' };
    } catch { return null; }
}

export function createCommunicationsClient() {
    const controllers = new Set();
    let generation = 0;
    const abort = () => Object.assign(new Error('La sesión o la vista cambió.'), { name: 'AbortError' });
    return {
        reset() { generation += 1; controllers.forEach(c => c.abort()); controllers.clear(); },
        async request(path, { method = 'GET', body, image = false } = {}) {
            if (!path.startsWith('/api/comunicaciones/')) throw new Error('Ruta de comunicaciones inválida.');
            const auth = communicationsSession();
            if (!auth) throw abort();
            const version = generation;
            const controller = new AbortController(); controllers.add(controller);
            const timeout = setTimeout(() => controller.abort(), 20000);
            try {
                const multipart = body instanceof FormData;
                const response = await fetch(path, { method, body: body === undefined ? undefined : multipart ? body : JSON.stringify(body),
                    cache: 'no-store', signal: controller.signal,
                    headers: { Accept: image ? 'image/*' : 'application/json', Authorization: `Bearer ${auth.token}`, ...(body !== undefined && !multipart ? { 'Content-Type': 'application/json' } : {}) } });
                const data = image && response.ok ? await response.blob() : await response.json().catch(() => ({}));
                const current = communicationsSession();
                if (generation !== version || current?.token !== auth.token || current?.id !== auth.id) throw abort();
                if (!response.ok) throw Object.assign(new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No se pudo completar la solicitud.'), { status: response.status });
                return data;
            } catch (error) {
                const current = communicationsSession();
                if (generation !== version || current?.token !== auth.token || current?.id !== auth.id) throw abort();
                throw error;
            } finally { clearTimeout(timeout); controllers.delete(controller); }
        },
    };
}

export function escapeCommunications(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function communicationsDate(value) {
    if (!value) return '';
    return new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' }).format(new Date(value));
}
