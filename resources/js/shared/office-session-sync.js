/**
 * Sincroniza los paneles de Oficina con el token de esta pestaña y de otras.
 * Una carga en curso termina antes de iniciar una sola carga para la sesión más reciente.
 */
export function createOfficeSessionSync({
    tokenKey,
    getToken,
    load,
    onSessionChange,
    onError = () => {},
    windowTarget = globalThis.window ?? null,
}) {
    if (!tokenKey || typeof getToken !== 'function' || typeof load !== 'function'
        || typeof onSessionChange !== 'function') {
        throw new TypeError('La sincronización de sesión requiere clave, token, carga y limpieza.');
    }

    let started = false;
    let token = null;
    let version = 0;
    let running = false;
    let pending = false;
    let pendingShowErrors = false;
    let activePromise = null;

    const readToken = () => getToken() || null;

    function updateSession(force = false) {
        const next = readToken();
        if (force || next !== token) {
            token = next;
            version += 1;
            onSessionChange(token);
        }
    }

    function reload(showErrors = false, forceChange = false) {
        if (!started) return Promise.resolve(false);
        updateSession(forceChange);
        if (running) {
            pending = true;
            pendingShowErrors ||= showErrors;
            return activePromise;
        }
        if (!token) return Promise.resolve(false);

        running = true;
        const startedToken = token;
        const startedVersion = version;
        const isCurrent = () => started && version === startedVersion && readToken() === startedToken;
        activePromise = Promise.resolve()
            .then(() => isCurrent() ? load(startedToken, isCurrent, showErrors) : false)
            .catch(onError)
            .finally(() => {
                running = false;
                activePromise = null;
                if (!pending || !started) return;
                const errors = pendingShowErrors;
                pending = false;
                pendingShowErrors = false;
                return reload(errors);
            });
        return activePromise;
    }

    function handleOfficeSession() {
        void reload(false, true);
    }

    function handleStorage(event) {
        if (event.key === tokenKey || event.key === null) void reload(false, true);
    }

    return {
        start() {
            if (started) return;
            started = true;
            windowTarget?.addEventListener?.('estiba:office-session', handleOfficeSession);
            windowTarget?.addEventListener?.('storage', handleStorage);
            void reload(false, true);
        },
        reload,
        stop() {
            if (!started) return;
            started = false;
            version += 1;
            pending = false;
            pendingShowErrors = false;
            windowTarget?.removeEventListener?.('estiba:office-session', handleOfficeSession);
            windowTarget?.removeEventListener?.('storage', handleStorage);
        },
    };
}
