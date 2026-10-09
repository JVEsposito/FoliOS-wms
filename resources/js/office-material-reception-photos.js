/** Evidencia privada: las URLs temporales no llevan tokens y se liberan al cerrar. */
export function createReceptionPhotosPanel(root, { getReception, token, uuid, canManage, canAdminister, onChange, onConfirm }) {
    let urls = [];
    let generation = 0;
    let busy = false;
    const pending = new Map();
    const escape = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    const path = (id) => `/api/materiales/recepciones/${id}/fotos`;
    async function request(url, options = {}, blob = false) {
        const headers = new Headers(options.headers);
        headers.set('Authorization', `Bearer ${token()}`);
        headers.set('Accept', blob ? '*/*' : 'application/json');
        if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
        const response = await fetch(url, { ...options, headers, cache: 'no-store' });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la operación.');
        }
        return blob ? response.blob() : response.status === 204 ? null : response.json();
    }
    function clear() { generation++; urls.forEach((url) => URL.revokeObjectURL(url)); urls = []; root.replaceChildren(); }
    async function render() {
        clear();
        const version = generation;
        const reception = getReception();
        if (!reception) { root.innerHTML = '<h3>Fotos</h3><p>Guarda el borrador para agregar la foto de la guía o factura antes de confirmar.</p>'; return; }
        const fotos = reception.fotos || [];
        const editable = canManage() && reception.estado !== 'anulada';
        root.innerHTML = `<h3>Fotos</h3>${['documento', 'referencial'].map((tipo) => {
            const group = fotos.filter((f) => f.tipo === tipo);
            return `<section><h4>${tipo === 'documento' ? 'Guía / factura (obligatoria)' : 'Foto de la recepción (opcional)'} · ${group.length}/5</h4>
                <div class="material-reception-photo-grid">${group.map((f) => `<article><button type="button" data-original="${f.id}" aria-label="Abrir foto ${f.orden}"><img data-thumbnail="${f.id}" alt="${tipo} ${f.orden}" width="120" height="120"></button>${editable && (reception.estado === 'borrador' || canAdminister()) ? `<button type="button" data-delete="${f.id}">Eliminar</button>` : ''}</article>`).join('')}</div>
                ${editable && group.length < 5 ? `<label>Subir fotos<input type="file" data-upload="${tipo}" accept="image/jpeg,image/png,image/webp" multiple></label>` : ''}
            </section>`;
        }).join('')}
        ${fotos.length ? '<button type="button" data-zip>Descargar fotos (ZIP)</button>' : ''}
        ${reception.estado === 'borrador' && canManage() ? `<p>${fotos.some((f) => f.tipo === 'documento') ? 'Documento recibido. Puedes confirmar la recepción.' : 'Falta la foto de la guía de despacho o factura. Agrégala antes de confirmar.'}</p><button type="button" data-confirm ${fotos.some((f) => f.tipo === 'documento') ? '' : 'disabled'}>Confirmar recepción</button>` : ''}
        <div data-pending></div><p role="alert" data-photo-error></p>`;
        renderPending();
        await Promise.all(fotos.map(async (foto) => {
            try {
                const blob = await request(foto.miniatura_url, {}, true);
                if (version !== generation) return;
                const url = URL.createObjectURL(blob); urls.push(url);
                const image = root.querySelector(`[data-thumbnail="${foto.id}"]`); if (image) image.src = url;
            } catch { /* No bloquear el expediente si falla una miniatura. */ }
        }));
    }
    function report(error) { const el = root.querySelector('[data-photo-error]'); if (el) el.textContent = error.message; }
    function renderPending() {
        const el = root.querySelector('[data-pending]'); if (!el) return;
        const id = getReception()?.id;
        el.innerHTML = [...pending].filter(([, p]) => p.receptionId === id).map(([op, p]) => `<p>${escape(p.file.name)}: ${escape(p.error || 'Subiendo…')} ${p.error ? `<button type="button" data-retry="${op}">Reintentar</button>` : ''}</p>`).join('');
    }
    async function upload(op) {
        const photo = pending.get(op);
        const body = new FormData(); body.append('archivo', photo.file); body.append('tipo', photo.tipo); body.append('operacion_id', op);
        try {
            const response = await request(path(photo.receptionId), { method: 'POST', body });
            pending.delete(op);
            if (getReception()?.id === photo.receptionId) {
                const reception = getReception();
                onChange([...(reception.fotos || []).filter((f) => f.id !== response.data.id), response.data]);
                await render();
            }
        } catch (error) { photo.error = error.message; renderPending(); }
    }
    root.addEventListener('change', async (event) => {
        const tipo = event.target.dataset.upload; if (!tipo || busy) return;
        const reception = getReception(); if (!reception) return;
        const files = Array.from(event.target.files);
        const saved = (reception.fotos || []).filter((foto) => foto.tipo === tipo).length;
        const waiting = [...pending.values()].filter((photo) => photo.receptionId === reception.id && photo.tipo === tipo).length;
        const accepted = files.slice(0, Math.max(0, 5 - saved - waiting));
        const omitted = files.length - accepted.length;
        busy = true;
        try {
            for (const file of accepted) {
                const op = uuid(); pending.set(op, { file, tipo, receptionId: reception.id }); renderPending(); await upload(op);
            }
            if (omitted && getReception()?.id === reception.id) report(new Error(`Se omitieron ${omitted} fotos: el máximo es 5 por tipo`));
            event.target.value = '';
        } finally { busy = false; }
    });
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('button'); if (!button || busy) return;
        const reception = getReception(); if (!reception) return;
        busy = true; button.disabled = true;
        try {
            if (button.dataset.retry) { await upload(button.dataset.retry); }
            else if (button.dataset.delete) {
                const motivo = reception.estado === 'confirmada' ? window.prompt('Motivo obligatorio de eliminación') : null;
                if (reception.estado === 'confirmada' && !motivo?.trim()) return;
                await request(`${path(reception.id)}/${button.dataset.delete}`, { method: 'DELETE', body: JSON.stringify({ motivo }) });
                onChange((reception.fotos || []).filter((f) => f.id !== button.dataset.delete)); await render();
            } else if (button.dataset.original) {
                const foto = reception.fotos.find((f) => f.id === button.dataset.original);
                const opened = window.open('about:blank', '_blank');
                try { const blob = await request(foto.url, {}, true); const url = URL.createObjectURL(blob); urls.push(url); if (opened) { opened.opener = null; opened.location.href = url; } }
                catch (error) { opened?.close(); throw error; }
            } else if (button.hasAttribute('data-zip')) {
                const blob = await request(`${path(reception.id)}.zip`, {}, true); const url = URL.createObjectURL(blob); urls.push(url);
                const link = document.createElement('a'); link.href = url; link.download = 'recepcion-fotos.zip'; link.click();
            } else if (button.hasAttribute('data-confirm')) { await onConfirm(); }
        } catch (error) { report(error); }
        finally { busy = false; button.disabled = false; }
    });
    return { render, clear, reset: () => { clear(); pending.clear(); } };
}
