const images = new Map();
const dialogs = new Set();
const generations = new WeakMap();
const controllers = new Map();
const token = () => localStorage.getItem('estiba_wms_office_token');
const esc = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

export function releasePrivateItemImages(root) {
    controllers.get(root)?.abort(); controllers.delete(root);
    generations.set(root, (generations.get(root) || 0) + 1);
    for (const [node, url] of images) if (root.contains(node) || !node.isConnected) { URL.revokeObjectURL(url); node.removeAttribute('src'); images.delete(node); }
}

export async function loadPrivateItemImages(root) {
    releasePrivateItemImages(root);
    const generation = generations.get(root); const auth = token();
    if (!auth) return;
    const controller = new AbortController(); controllers.set(root, controller);
    const queue = [...root.querySelectorAll('img[data-private-item-photo]')];
    await Promise.all(Array.from({ length: Math.min(4, queue.length) }, async () => {
        while (queue.length && !controller.signal.aborted) {
            const node = queue.shift(); const path = node.dataset.privateItemPhoto;
            // Los metadatos del servidor solo contienen rutas locales, nunca enlaces con credenciales.
            if (!path?.startsWith('/api/materiales/fotos-items/')) continue;
            try {
                const r = await fetch(path, { headers: { Authorization: `Bearer ${auth}` }, cache: 'no-store', signal: controller.signal });
                if (!r.ok) continue;
                const blob = await r.blob();
                if (!node.isConnected || generations.get(root) !== generation || token() !== auth || controller.signal.aborted) continue;
                const url = URL.createObjectURL(blob); images.set(node, url); node.src = url;
            } catch { /* Sin imagen se conserva la identificación por código y nombre. */ }
        }
    }));
}

export async function openItemPhotoPanel(item, onChanged = () => {}) {
    const dialog = document.createElement('dialog'); dialog.className = 'item-photo-dialog'; document.body.append(dialog); dialogs.add(dialog);
    let identity = null; try { identity = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null'); } catch { /* Se conservan los permisos del servidor. */ }
    const editor = identity?.puede_editar_fotos_items_materiales === true || ['administrador', 'supervisor_materiales'].includes(identity?.rol);
    let data = []; let version = 0; let busy = true; let closed = false; let enlarged = null;
    const auth = token();
    dialog.innerHTML = `<header><h2>${esc(item.codigo)} · ${esc(item.nombre)}</h2><button type="button" data-close>Cerrar</button></header><p>Fotos opcionales · máximo 10 · JPG, PNG o WebP · hasta 5 MiB por archivo</p><p role="alert" data-error></p><form data-upload ${editor ? '' : 'hidden'}><input type="file" name="fotos" multiple accept="image/jpeg,image/png,image/webp" required disabled><button type="submit" disabled>Subir fotos</button></form><div class="item-photo-gallery" data-gallery></div>`;
    const error = dialog.querySelector('[data-error]'); const gallery = dialog.querySelector('[data-gallery]');
    function render() {
        if (closed) return;
        releasePrivateItemImages(gallery);
        gallery.innerHTML = data.map((p, i) => `<article><button type="button" data-large="${esc(p.id)}"><img class="item-photo-preview" alt="Foto ${i + 1} de ${esc(item.nombre)}" data-private-item-photo="${esc(p.miniatura_url)}"></button><p>${p.principal ? 'Principal' : `Foto ${i + 1}`}</p>${editor ? `<div class="item-photo-actions"><button data-up="${esc(p.id)}" type="button" ${i === 0 ? 'disabled' : ''}>↑</button><button data-down="${esc(p.id)}" type="button" ${i === data.length - 1 ? 'disabled' : ''}>↓</button><button data-primary="${esc(p.id)}" type="button" ${p.principal ? 'disabled' : ''}>Principal</button><button data-delete="${esc(p.id)}" type="button">Eliminar</button></div>` : ''}</article>`).join('') || '<p>Este ítem no tiene fotos.</p>';
        void loadPrivateItemImages(gallery);
    }
    async function request(path, options = {}) {
        const r = await fetch(path, { headers: { Accept: 'application/json', Authorization: `Bearer ${auth}`, ...(!(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}) }, ...options });
        const body = await r.json(); if (!r.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudieron guardar las fotos.');
        if (token() !== auth) throw new Error('La sesión cambió. Abre nuevamente la ficha.');
        return body;
    }
    function apply(body) { data = body.data; version = body.version; render(); }
    async function action(run) {
        if (busy || closed) return; busy = true; error.textContent = '';
        dialog.querySelectorAll('button:not([data-close]), input').forEach((n) => { n.disabled = true; });
        try { apply(await run()); if (!closed) await onChanged(); }
        catch (e) { if (!closed) error.textContent = e.message; }
        finally { busy = false; if (!closed) { dialog.querySelectorAll('input, form button').forEach((n) => { n.disabled = false; }); render(); } }
    }
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { closed = true; dialogs.delete(dialog); enlarged?.close(); releasePrivateItemImages(gallery); dialog.remove(); });
    dialog.querySelector('[data-upload]').addEventListener('submit', (e) => {
        e.preventDefault(); const files = [...e.currentTarget.elements.fotos.files];
        if (files.length + data.length > 10 || files.some((f) => f.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(f.type))) { error.textContent = 'Selecciona hasta diez fotos en total, JPG/PNG/WebP y de hasta 5 MiB cada una.'; return; }
        const form = new FormData(); files.forEach((f) => form.append('fotografias[]', f)); form.append('version_conocida', version);
        void action(async () => { const body = await request(`/api/materiales/items/${item.id}/fotos`, { method: 'POST', body: form }); dialog.querySelector('[data-upload]').reset(); return body; });
    });
    gallery.addEventListener('click', (e) => {
        const button = e.target.closest('button'); if (!button || busy) return;
        const id = button.dataset.large || button.dataset.up || button.dataset.down || button.dataset.primary || button.dataset.delete;
        if (button.dataset.large) {
            enlarged = document.createElement('dialog'); enlarged.className = 'item-photo-dialog item-photo-large';
            enlarged.innerHTML = `<button type="button">Cerrar</button><img alt="Foto de ${esc(item.nombre)}" data-private-item-photo="${esc(data.find((p) => p.id === id).url)}">`;
            document.body.append(enlarged); const current = enlarged;
            current.querySelector('button').addEventListener('click', () => current.close());
            current.addEventListener('close', () => { releasePrivateItemImages(current); current.remove(); });
            current.showModal(); void loadPrivateItemImages(current); return;
        }
        if (button.dataset.delete) { void action(() => request(`/api/materiales/items/${item.id}/fotos/${id}`, { method: 'DELETE', body: JSON.stringify({ version_conocida: version }) })); return; }
        const ids = data.map((p) => p.id); const index = ids.indexOf(id); const to = button.dataset.up ? index - 1 : button.dataset.down ? index + 1 : index;
        [ids[index], ids[to]] = [ids[to], ids[index]];
        void action(() => request(`/api/materiales/items/${item.id}/fotos`, { method: 'PUT', body: JSON.stringify({ orden: ids, principal_id: button.dataset.primary ? id : data.find((p) => p.principal).id, version_conocida: version }) }));
    });
    dialog.showModal();
    try { apply(await request(`/api/materiales/items/${item.id}/fotos`)); busy = false; if (!closed) dialog.querySelectorAll('input, form button').forEach((n) => { n.disabled = false; }); } catch (e) { if (!closed) error.textContent = e.message; }
}

function clear() { for (const dialog of dialogs) dialog.close(); for (const c of controllers.values()) c.abort(); controllers.clear(); for (const [node, url] of images) { URL.revokeObjectURL(url); node.removeAttribute('src'); } images.clear(); }
window.addEventListener('pagehide', clear);
window.addEventListener('estiba:office-session', clear);
