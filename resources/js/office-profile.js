const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const changedKey = 'estiba_wms_profile_changed';
const endpoint = '/api/usuario/foto-perfil';

export function initializeOfficeProfile() {
    const open = document.querySelector('[data-office-profile-open]');
    const dialog = document.getElementById('officeProfileDialog');
    if (!open || !dialog || open.dataset.profileReady) return;
    open.dataset.profileReady = 'true';
    const form = dialog.querySelector('[data-profile-form]');
    const input = dialog.querySelector('[data-profile-file]');
    const preview = dialog.querySelector('[data-profile-preview]');
    const fallback = dialog.querySelector('[data-profile-initials]');
    const photo = open.querySelector('[data-office-profile-photo]');
    const initials = document.getElementById('officeInitials');
    const error = dialog.querySelector('[data-profile-error]');
    const save = dialog.querySelector('[data-profile-save]');
    const remove = dialog.querySelector('[data-profile-remove]');
    const status = document.querySelector('[data-profile-status]');
    const controllers = new Set();
    let currentSession = null;
    let profile = null;
    let loadedVersion = null;
    let photoUrl = null;
    let previewUrl = null;
    let file = null;
    let validFile = false;
    let busy = false;
    let epoch = 0;
    let refreshNumber = 0;

    function session() {
        try {
            const identity = JSON.parse(localStorage.getItem(identityKey) || 'null');
            const token = localStorage.getItem(tokenKey);
            return identity && token ? { token, id: identity.id, nombre: identity.nombre || identity.name || 'Usuario' } : null;
        } catch { return null; }
    }
    function isCurrent(auth, version) {
        const now = session();
        return Boolean(now && now.token === auth.token && now.id === auth.id && epoch === version);
    }
    function invalidate() {
        epoch += 1;
        for (const controller of controllers) controller.abort();
        controllers.clear();
    }
    function setImage(node, url) {
        node.hidden = !url;
        if (url) node.src = url;
        else node.removeAttribute('src');
    }
    function renderPreview() {
        const name = session()?.nombre || 'Usuario';
        fallback.textContent = name.trim().split(/\s+/).slice(0, 2).map(part => part[0] || '').join('').toUpperCase();
        const url = previewUrl || photoUrl;
        setImage(preview, url);
        fallback.hidden = Boolean(url);
    }
    function clearSelection({ resetInput = true } = {}) {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        file = null;
        validFile = false;
        if (resetInput) input.value = '';
        renderPreview();
    }
    function clearPhoto() {
        if (photoUrl) URL.revokeObjectURL(photoUrl);
        photoUrl = null;
        loadedVersion = null;
        setImage(photo, null);
        initials.hidden = false;
        renderPreview();
    }
    function controls() {
        form.setAttribute('aria-busy', String(busy));
        input.disabled = busy;
        save.disabled = busy || !validFile;
        save.textContent = busy ? 'Guardando…' : 'Guardar foto';
        remove.disabled = busy || !profile?.foto_perfil_version;
        dialog.querySelectorAll('[data-profile-close]').forEach(button => { button.disabled = busy; });
    }
    async function request(path, auth, options = {}) {
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(path, {
                ...options, cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', Authorization: `Bearer ${auth.token}` },
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No se pudo actualizar tu foto. Vuelve a intentarlo.');
            }
            return options.image ? await response.blob() : await response.json();
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    }
    async function loadPhoto(auth, version) {
        const photoVersion = profile?.foto_perfil_version;
        if (!photoVersion) { clearPhoto(); return; }
        if (loadedVersion === photoVersion && photoUrl) { renderPreview(); return; }
        try {
            const blob = await request(endpoint, auth, { image: true });
            if (!isCurrent(auth, version) || profile?.foto_perfil_version !== photoVersion) return;
            clearPhoto();
            photoUrl = URL.createObjectURL(blob);
            loadedVersion = photoVersion;
            setImage(photo, photoUrl);
            initials.hidden = true;
            renderPreview();
        } catch {
            if (isCurrent(auth, version) && profile?.foto_perfil_version === photoVersion) clearPhoto();
        }
    }
    function acceptProfile(data, auth) {
        if (!data || !Object.hasOwn(data, 'foto_perfil_version') || (auth.id != null && data.id !== auth.id)) return false;
        profile = data;
        controls();
        return true;
    }
    async function refreshProfile() {
        const auth = session();
        if (!auth || busy) return;
        const version = epoch;
        const number = ++refreshNumber;
        try {
            const result = await request('/api/usuario/perfil', auth);
            if (!isCurrent(auth, version) || number !== refreshNumber || !acceptProfile(result.data, auth)) return;
            await loadPhoto(auth, version);
        } catch { /* El perfil operativo sigue disponible aunque la foto no cargue. */ }
    }
    function syncSession() {
        const auth = session();
        if (auth?.token === currentSession?.token && auth?.id === currentSession?.id) return;
        invalidate();
        currentSession = auth;
        profile = null;
        busy = false;
        if (dialog.open) dialog.close();
        clearSelection();
        clearPhoto();
        open.disabled = !auth;
        controls();
        if (auth) void refreshProfile();
    }
    async function changePhoto(method) {
        if (busy || (method === 'POST' && !validFile)) return;
        const auth = session();
        if (!auth) { syncSession(); return; }
        invalidate();
        const version = epoch;
        busy = true;
        error.textContent = '';
        controls();
        try {
            const body = method === 'POST' ? new FormData() : undefined;
            if (body) body.append('foto', file);
            const result = await request(endpoint, auth, { method, body });
            if (!isCurrent(auth, version) || !acceptProfile(result.data, auth)) return;
            clearSelection();
            await loadPhoto(auth, version);
            if (!isCurrent(auth, version)) return;
            try { localStorage.setItem(changedKey, `${Date.now()}-${Math.random()}`); } catch { /* Aviso opcional entre pestañas. */ }
            status.textContent = method === 'POST' ? 'Foto de perfil actualizada.' : 'Foto quitada. Se muestran tus iniciales.';
            dialog.close();
        } catch (failure) {
            if (isCurrent(auth, version)) error.textContent = failure.name === 'AbortError'
                ? 'La solicitud tardó demasiado. Vuelve a intentarlo para confirmar tu foto.' : failure.message;
        } finally {
            if (isCurrent(auth, version)) { busy = false; controls(); }
            else syncSession();
        }
    }

    open.addEventListener('click', () => {
        syncSession();
        if (!session()) return;
        clearSelection();
        error.textContent = '';
        controls();
        dialog.showModal();
        void refreshProfile();
    });
    input.addEventListener('change', async () => {
        const selected = input.files?.[0];
        clearSelection({ resetInput: false });
        error.textContent = '';
        if (!selected) { controls(); return; }
        if (selected.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(selected.type)) {
            error.textContent = 'Selecciona una foto JPG, PNG o WebP de hasta 5 MB.';
            controls(); return;
        }
        file = selected;
        previewUrl = URL.createObjectURL(selected);
        renderPreview();
        try {
            await preview.decode();
            if (file !== selected) return;
            validFile = true;
        } catch {
            if (file !== selected) return;
            clearSelection();
            error.textContent = 'No se pudo leer la foto. Selecciona otra imagen.';
        }
        controls();
    });
    form.addEventListener('submit', event => { event.preventDefault(); void changePhoto('POST'); });
    remove.addEventListener('click', () => void changePhoto('DELETE'));
    dialog.querySelectorAll('[data-profile-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { clearSelection(); controls(); open.focus(); });
    photo.addEventListener('error', clearPhoto);
    window.addEventListener('estiba:office-session', syncSession);
    window.addEventListener('storage', event => {
        if ([tokenKey, identityKey, null].includes(event.key)) syncSession();
        if (event.key === changedKey) void refreshProfile();
    });
    window.addEventListener('focus', () => { syncSession(); void refreshProfile(); });
    window.addEventListener('pagehide', () => {
        invalidate();
        busy = false;
        if (dialog.open) dialog.close();
        clearSelection(); clearPhoto(); controls();
    });
    window.addEventListener('pageshow', event => { if (event.persisted) { syncSession(); void refreshProfile(); } });
    syncSession();
}
