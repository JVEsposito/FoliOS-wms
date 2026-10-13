/**
 * Entrada única de FoliOS web (/FoliOS). El servidor decide la pantalla de inicio de
 * cada usuario (App\Services\Autorizacion\InicioOficina) y la entrega en `usuario.inicio`.
 */
export const OFFICE_TOKEN_KEY = 'estiba_wms_office_token';
export const OFFICE_IDENTITY_KEY = 'estiba_wms_office_identity';
export const FOLIOS_ENTRY_PATH = '/FoliOS';

const OFFICE_PATH = /^\/oficina(?:\/[a-z0-9-]+)*$/;

/** Solo acepta rutas internas de Oficina, para no redirigir fuera de FoliOS. */
export function officeHomePath(value) {
    return typeof value === 'string' && OFFICE_PATH.test(value) ? value : null;
}

/**
 * Oficina a la que volver después de iniciar sesión (parámetro `volver` de /FoliOS):
 * ruta interna de Oficina, con su consulta y ancla si las trae.
 */
export function officeReturnPath(value) {
    if (typeof value !== 'string' || !value.startsWith('/oficina')) return null;

    try {
        const base = 'http://folios.invalid';
        const url = new URL(value, base);
        if (url.origin !== base || !officeHomePath(url.pathname)) return null;

        return `${url.pathname}${url.search}${url.hash}`;
    } catch {
        return null;
    }
}

/** Entrada única para una oficina sin sesión: vuelve a ella después de iniciar sesión. */
export function officeEntryHref(location) {
    const back = officeReturnPath(`${location?.pathname || ''}${location?.search || ''}${location?.hash || ''}`);

    return back ? `${FOLIOS_ENTRY_PATH}?volver=${encodeURIComponent(back)}` : FOLIOS_ENTRY_PATH;
}

/** Destino del logo «Ir a mi inicio»: el inicio del usuario o la entrada única. */
export function officeHomeHref(identity) {
    return officeHomePath(identity?.inicio) || FOLIOS_ENTRY_PATH;
}

/**
 * Qué mostrar en /FoliOS según la respuesta de GET o POST /api/acceso-oficina.
 *
 * @returns {{ view: 'redirect' | 'sin-oficinas' | 'acceso' | 'error', destination?: string, identity?: object, clearSession?: boolean, message?: string }}
 */
export function entryFromResponse(status, payload) {
    if (status === 200 && payload?.usuario) {
        const destination = officeHomePath(payload.usuario.inicio);

        return destination
            ? { view: 'redirect', destination, identity: payload.usuario }
            : { view: 'sin-oficinas', identity: payload.usuario };
    }

    if (status === 401) return { view: 'acceso', clearSession: true, message: '' };
    if (status === 403) {
        return {
            view: 'acceso',
            clearSession: true,
            message: 'Tu cuenta ya no tiene acceso a las oficinas web. Ingresa con otra cuenta o solicita la revisión de tu perfil.',
        };
    }

    return {
        view: 'error',
        message: status === 0
            ? 'Revisa la conexión del equipo con el servidor de FoliOS y vuelve a intentarlo.'
            : 'El servidor de FoliOS no respondió como se esperaba. Vuelve a intentarlo en unos segundos.',
    };
}

/** Mensaje del intento de inicio de sesión fallido. */
export function loginErrorMessage(status, payload) {
    if (status === 0) return 'No fue posible conectar con el servidor de FoliOS.';
    if (status === 429) return 'Demasiados intentos. Espera un minuto y vuelve a intentarlo.';

    const validation = Object.values(payload?.errors || {}).flat()[0];

    return validation || 'No fue posible iniciar sesión. Vuelve a intentarlo.';
}
