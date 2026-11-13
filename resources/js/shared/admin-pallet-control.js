import '../../css/admin-pallet-control.css';
import { createUuid } from './uuid.js';

const labels = { general: 'Uso general', despacho: 'Despacho prioritario', almacenamiento: 'Almacenamiento prioritario', salida_tuneles: 'Salida de túneles prioritaria' };
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const session = () => {
    try {
        const user = JSON.parse(localStorage.getItem('estiba_wms_office_identity') || 'null');
        const token = localStorage.getItem('estiba_wms_office_token');
        return user?.rol === 'administrador' && token ? { id: user.id, token } : null;
    } catch { return null; }
};

export function mountPalletAdministration({ anchor, onChanged = () => {} }) {
    const hosts = [...document.querySelectorAll(anchor)];
    if (!hosts.length) return;
    const launches = hosts.map(host => {
        const button = document.createElement('button'); button.type = 'button'; button.dataset.adminPallet = '';
        button.textContent = 'Administrar pallets y cámaras'; button.hidden = true; host.append(button); return button;
    });
    let dialog, person, generation = 0, lookupVersion = 0, pallet = null, cameras = [], busy = false, palletIntent = null, cameraIntent = null;
    const controllers = new Set();
    const same = () => person && session()?.id === person.id && session()?.token === person.token;
    const $ = selector => dialog.querySelector(selector);
    function say(message, error = false) { $('[data-admin-status]').textContent = message; $('[data-admin-status]').dataset.error = String(error); }
    function close() {
        generation += 1; lookupVersion += 1; controllers.forEach(c => c.abort()); controllers.clear();
        pallet = null; cameras = []; palletIntent = null; cameraIntent = null; person = null; busy = false;
        if (dialog) { if (dialog.open) dialog.close(); dialog.remove(); dialog = null; }
    }
    function sync() { launches.forEach(button => { button.hidden = !session(); }); if (dialog && !same()) close(); }
    async function api(path, options = {}) {
        const who = person, version = generation, controller = new AbortController(); controllers.add(controller);
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const r = await fetch(path, { ...options, signal: controller.signal, cache: 'no-store',
                headers: { Accept: 'application/json', Authorization: `Bearer ${who.token}`, ...(options.body ? { 'Content-Type': 'application/json' } : {}) } });
            const body = await r.json().catch(() => ({}));
            if (!same() || version !== generation) throw new DOMException('La sesión cambió.', 'AbortError');
            if (!r.ok) {
                const error = new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo completar la acción.');
                error.status = r.status; throw error;
            }
            return body;
        } finally { clearTimeout(timer); controllers.delete(controller); }
    }
    function intent(previous, payload) {
        const signature = JSON.stringify(payload);
        return previous?.signature === signature ? previous : { signature, id: createUuid() };
    }
    function updateAction() {
        const form = $('[data-admin-pallet-form]'), action = form.elements.accion.value;
        $('[data-admin-destination]').hidden = action === 'liberar_tareas';
        $('[data-admin-physical]').hidden = action !== 'corregir_ubicacion';
        $('[data-admin-physical] input').required = action === 'corregir_ubicacion';
        for (const input of $('[data-admin-destination]').querySelectorAll('input,select')) { input.required = action !== 'liberar_tareas'; input.disabled = action === 'liberar_tareas'; }
        $('[data-admin-help]').textContent = ({ liberar_tareas: 'Cancela las tareas reversibles y libera sus reservas. Conserva la ubicación física. Si la necesidad sigue vigente, el planificador puede proponer otra tarea.',
            designar_destino: 'Reemplaza las tareas reversibles por un destino elegido por administración. El camarero confirma el traslado; la ubicación todavía no cambia.',
            corregir_ubicacion: 'Registra dónde está realmente el pallet tras verificarlo físicamente. Conserva el historial y cancela las tareas que quedaron obsoletas.' })[action];
    }
    function fillCameras() {
        for (const node of dialog.querySelectorAll('[name="camara_id"]')) {
            const selected = node.value;
            node.innerHTML = '<option value="">Seleccionar cámara</option>' + cameras.map(c => `<option value="${esc(c.id)}">${esc(c.nombre)} · ${esc(labels[c.prioridad_operacional] || labels.general)}</option>`).join('');
            if (cameras.some(c => c.id === selected)) node.value = selected;
        }
        updateCamera();
    }
    function updateCamera() {
        const form = $('[data-admin-camera-form]'), camera = cameras.find(c => c.id === form.elements.camara_id.value);
        form.elements.prioridad_operacional.value = camera?.prioridad_operacional || 'general';
    }
    async function loadCameras() {
        const result = await api('/api/camaras');
        cameras = result.data.filter(c => c.contenido === 'productos' && c.estado === 'activa'); fillCameras();
    }
    async function lookup() {
        const version = ++lookupVersion;
        pallet = null; palletIntent = null; $('[data-admin-pallet-form]').hidden = true;
        const folio = $('[data-admin-lookup]').elements.folio.value.trim();
        if (!folio) return;
        say('Consultando pallet y tareas…'); $('[data-admin-pallet-summary]').textContent = '';
        const { data } = await api(`/api/intervenciones-planificador/pallets?${new URLSearchParams({ folio })}`);
        if (version !== lookupVersion || !dialog?.open) return;
        pallet = data;
        $('[data-admin-pallet-summary]').innerHTML = `<h3>${esc(data.numero_folio)}</h3><p>${data.ubicacion ? `${esc(data.ubicacion.camara)} · ${esc(data.ubicacion.posicion || 'Sin posición')}` : 'Sin ubicación en cámara'} · ${esc(data.estado)}</p><ul>${data.tareas.map(t => `<li>${esc(t.instruccion || 'Tarea de movimiento')} · ${esc(t.estado)} · ${esc(t.responsable || 'Sin asignar')}</li>`).join('') || '<li>Sin tareas activas.</li>'}</ul><details><summary>Últimas intervenciones</summary>${data.historial.map(h => `<p>${esc(h.usuario)} · ${esc(h.fecha)}<br>${esc(h.motivo)}</p>`).join('') || '<p>Sin intervenciones anteriores.</p>'}</details>`;
        $('[data-admin-pallet-form]').hidden = false; say('Pallet consultado. Selecciona la acción.');
    }
    async function submit(form, cameraOnly) {
        if (busy || !form.reportValidity() || (!cameraOnly && !pallet)) return;
        const selected = cameras.find(c => c.id === form.elements.camara_id.value);
        const action = cameraOnly ? null : form.elements.accion.value;
        const payload = cameraOnly ? { version_prioridad: selected?.version_prioridad || 1, prioridad_operacional: form.elements.prioridad_operacional.value, motivo: form.elements.motivo.value.trim() }
            : { accion: action, revision: pallet.revision, motivo: form.elements.motivo.value.trim(),
                ...(action === 'liberar_tareas' ? {} : { camara_id: selected?.id, banda: Number(form.elements.banda.value), posicion: Number(form.elements.posicion.value), nivel: Number(form.elements.nivel.value), version_plano_destino: selected?.version_plano }),
                ...(action === 'corregir_ubicacion' ? { confirmacion_fisica: form.elements.confirmacion_fisica.checked } : {}) };
        if (cameraOnly) cameraIntent = intent(cameraIntent, payload); else palletIntent = intent(palletIntent, payload);
        const operation = cameraOnly ? cameraIntent : palletIntent, version = generation;
        busy = true; dialog.setAttribute('aria-busy', 'true');
        const controls = [...dialog.querySelectorAll('button, input, select, textarea')];
        const disabled = controls.map(n => n.disabled); controls.forEach(n => { n.disabled = true; });
        say('Guardando intervención…');
        try {
            await api(cameraOnly ? `/api/configuracion/camaras/${selected.id}/prioridad-operacional` : `/api/intervenciones-planificador/pallets/${pallet.folio_id}`,
                { method: cameraOnly ? 'PATCH' : 'POST', body: JSON.stringify({ ...payload, operacion_id: operation.id }) });
            if (cameraOnly) cameraIntent = null; else palletIntent = null;
            say(cameraOnly ? 'Prioridad de cámara guardada. Se aplicará a los próximos cálculos.' : 'Intervención registrada y auditada.');
            form.elements.motivo.value = '';
            // Una recarga auxiliar fallida no vuelve a presentar la escritura confirmada como pendiente.
            await loadCameras().catch(() => {});
            if (pallet) await lookup().catch(() => say('Intervención guardada. Consulta el pallet para revisar el resultado.'));
            await Promise.resolve(onChanged()).catch(() => {});
        } catch (error) {
            if (same() && version === generation) {
                if (error.status) { if (cameraOnly) cameraIntent = null; else palletIntent = null; }
                say(`${error.message || 'No se confirmó la respuesta.'}${error.status ? ' Actualiza antes de reintentar.' : ' Reintenta sin cambiar el formulario para evitar duplicados.'}`, true);
            }
        } finally {
            if (same() && version === generation && dialog) {
                busy = false; dialog.setAttribute('aria-busy', 'false'); controls.forEach((n, i) => { if (n.isConnected) n.disabled = disabled[i]; }); updateAction();
            }
        }
    }
    async function open(folio = '') {
        if (!session()) return;
        if (dialog) close(); person = session(); generation += 1;
        dialog = document.createElement('dialog'); dialog.className = 'admin-pallet-dialog'; dialog.setAttribute('aria-labelledby', 'adminPalletTitle');
        dialog.innerHTML = `<header><div><small>ADMINISTRACIÓN DE FRIGORÍFICO</small><h2 id="adminPalletTitle">Pallets y prioridades de cámaras</h2></div><button type="button" data-admin-close>Cerrar</button></header>
            <p data-admin-status role="status" aria-live="polite"></p><div class="admin-pallet-body">
            <section><h3>Intervenir un pallet</h3><form data-admin-lookup><label>Folio<input name="folio" required maxlength="100" autocomplete="off"></label><button type="submit">Consultar pallet</button></form>
            <div data-admin-pallet-summary></div><form data-admin-pallet-form hidden>
                <label>Acción<select name="accion"><option value="designar_destino">Designar otro destino</option><option value="liberar_tareas">Liberar tareas y reservas</option><option value="corregir_ubicacion">Corregir ubicación verificada</option></select></label>
                <p data-admin-help></p><fieldset data-admin-destination><legend>Posición de destino</legend><label>Cámara<select name="camara_id" required></select></label><div class="admin-pallet-coordinates"><label>Banda<input name="banda" type="number" min="1" max="40" required></label><label>Posición<input name="posicion" type="number" min="1" max="40" required></label><label>Nivel<input name="nivel" type="number" min="1" max="10" value="1" required></label></div></fieldset>
                <label data-admin-physical hidden><input name="confirmacion_fisica" type="checkbox"> Verifiqué físicamente que el pallet está en esta posición.</label>
                <label>Motivo de la intervención<textarea name="motivo" minlength="3" maxlength="500" required rows="2"></textarea></label><button type="submit">Registrar intervención</button></form></section>
            <section><h3>Prioridad general por cámara</h3><p>Prevalece sobre afinidades de cliente, marca y formato al buscar destinos nuevos. Si no hay espacio válido se utiliza una alternativa. Las reservas en ejecución conservan su destino.</p>
            <form data-admin-camera-form><label>Cámara<select name="camara_id" required></select></label><label>Uso prioritario<select name="prioridad_operacional">${Object.entries(labels).map(([id, text]) => `<option value="${id}">${text}</option>`).join('')}</select></label><label>Motivo<textarea name="motivo" minlength="3" maxlength="500" required rows="2"></textarea></label><button type="submit">Guardar prioridad de cámara</button></form></section></div>`;
        document.body.append(dialog); dialog.showModal(); updateAction();
        $('[data-admin-close]').addEventListener('click', close);
        dialog.addEventListener('cancel', e => { if (busy) e.preventDefault(); });
        dialog.addEventListener('close', () => { if (dialog && !dialog.open) close(); });
        $('[data-admin-lookup]').addEventListener('submit', e => { e.preventDefault(); void lookup().catch(error => { if (same()) say(error.message, true); }); });
        $('[data-admin-pallet-form]').elements.accion.addEventListener('change', updateAction);
        $('[data-admin-camera-form]').elements.camara_id.addEventListener('change', updateCamera);
        for (const [selector, cameraOnly] of [['[data-admin-pallet-form]', false], ['[data-admin-camera-form]', true]]) {
            $(selector).addEventListener('submit', e => { e.preventDefault(); void submit(e.currentTarget, cameraOnly); });
        }
        $('[data-admin-lookup]').elements.folio.value = folio;
        try { await loadCameras(); if (folio) await lookup(); else say('Busca un pallet o configura la prioridad de una cámara.'); }
        catch (error) { if (same()) say(error.message, true); }
    }
    document.addEventListener('click', event => { const button = event.target.closest('[data-admin-pallet]'); if (button) void open(button.dataset.adminPallet); });
    window.addEventListener('estiba:office-session', sync); window.addEventListener('storage', sync); window.addEventListener('pagehide', close);
    const root = document.getElementById('officeApp') || document.getElementById('operationNowApp');
    if (root) new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class', 'hidden'] });
    sync();
}
