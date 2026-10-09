/** Transferencias supervisadas: el UUID se conserva durante los reintentos del diálogo. */
export function createClientTransfers({ api, getIdentity, getClients, getToken, uuid, onRefresh }) {
    const root = document.getElementById('materialClientTransfers');
    const dialog = document.getElementById('materialClientTransferDialog');
    if (!root || !dialog) return { refresh: async () => {} };
    const form = dialog.querySelector('form');
    const error = dialog.querySelector('[data-transfer-error]');
    const result = dialog.querySelector('[data-transfer-result]');
    const filters = root.querySelector('form');
    let generation = 0; let options = null; let operation = null; let busy = false; let page = 1; let records = []; let printOperation = null;
    const escape = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    const qty = (v) => Number(v || 0).toLocaleString('es-CL', { maximumFractionDigits: 3 });
    const canTransfer = () => getIdentity()?.puede_transferir_folios_materiales_clientes === true;
    const canRead = () => canTransfer() || getIdentity()?.puede_consultar_kardex_materiales === true;
    function query() { const q = new URLSearchParams(); for (const [k, v] of new FormData(filters)) if (v) q.set(k, String(v)); return q; }
    async function download(path, init, fallback) {
        const response = await fetch(path, { ...init, headers: { Authorization: `Bearer ${getToken()}`, 'Content-Type': 'application/json', ...init?.headers }, cache: 'no-store' });
        if (!response.ok) { const data = await response.json(); throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible descargar.'); }
        const url = URL.createObjectURL(await response.blob()); const link = document.createElement('a');
        link.href = url; link.download = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || fallback; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    async function refresh() {
        const ticket = generation;
        root.hidden = !canRead(); if (!canRead()) return;
        const client = filters.elements.cliente_id; const selected = client.value;
        client.innerHTML = '<option value="">Todos los clientes</option>' + getClients().map((c) => `<option value="${escape(c.cliente_id)}">${escape(c.codigo)} · ${escape(c.nombre)}</option>`).join(''); client.value = selected;
        const q = query(); q.set('page', String(page));
        try {
            const response = await api(`/api/materiales/transferencias-clientes?${q}`); if (ticket !== generation) return; records = response.data;
            root.querySelector('tbody').innerHTML = records.map((t) => `<tr><td>${escape(new Date(t.ocurrido_at).toLocaleString('es-CL'))}</td><td>${escape(t.folio_origen.numero_folio)} → ${escape(t.folio_destino.numero_folio)}</td><td>${escape(t.cliente_origen.codigo)} → ${escape(t.cliente_destino.codigo)}</td><td>${escape(t.item_origen.codigo)} → ${escape(t.item_destino.codigo)}</td><td>${qty(t.cantidad)} ${escape(t.unidad_medida)}</td><td>${escape(t.motivo)}<small>${escape(t.documento_respaldo || 'Sin respaldo')}</small></td><td>${escape(t.usuario.name)}</td><td>${getIdentity()?.puede_imprimir_etiquetas_materiales ? `<button type="button" data-print-transfer="${escape(t.id)}">Imprimir etiqueta</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="8">No hay transferencias para este filtro.</td></tr>';
            root.querySelector('[data-transfer-page]').textContent = `Página ${response.meta.current_page} de ${response.meta.last_page}`;
            root.querySelector('[data-transfer-previous]').disabled = response.meta.current_page <= 1;
            root.querySelector('[data-transfer-next]').disabled = response.meta.current_page >= response.meta.last_page;
            root.querySelector('[data-transfer-list-error]').textContent = '';
        } catch (reason) { root.querySelector('[data-transfer-list-error]').textContent = reason.message; }
    }
    function selectClient() {
        const client = options?.clientes.find((c) => c.id === form.elements.cliente_destino_id.value);
        const items = client?.items || [];
        form.elements.item_destino_id.innerHTML = '<option value="">Selecciona el ítem destino</option>' + items.map((i) => `<option value="${escape(i.id)}">${escape(i.codigo)} · ${escape(i.nombre)}</option>`).join('');
        form.elements.item_destino_id.value = client?.item_sugerido_id || '';
        error.textContent = client && !items.length ? 'El cliente destino no tiene un ítem compatible. Créalo en el catálogo de ítems y vuelve a intentar' : '';
        form.querySelector('[type="submit"]').disabled = !items.length;
    }
    function describeMode() {
        const total = options && Math.abs(Number(form.elements.cantidad.value) - Number(options.cantidad_actual)) < 0.0001 && !options.tiene_reservas_activas && Number(options.en_centros_costo) === 0 && Number(options.reservado_bodega) === 0;
        dialog.querySelector('[data-transfer-mode]').textContent = total ? 'Transferencia total: el folio origen se retira y el nuevo toma su ubicación. Reemplaza la etiqueta.' : 'Transferencia parcial: el origen conserva su ubicación y etiqueta. Separa el bulto transferido, imprime su nueva etiqueta y ubícalo desde la tablet.';
    }
    async function open(folioId) {
        const ticket = generation;
        if (!canTransfer() || busy) return; busy = true;
        try {
            const loaded = (await api(`/api/materiales/transferencias-clientes/opciones?folio=${encodeURIComponent(folioId)}`)).data;
            if (ticket !== generation) return; options = loaded;
            operation = uuid(); form.reset(); result.replaceChildren(); error.textContent = ''; form.hidden = false;
            form.elements.cliente_destino_id.innerHTML = '<option value="">Selecciona el cliente destino</option>' + options.clientes.map((c) => `<option value="${escape(c.id)}">${escape(c.codigo)} · ${escape(c.nombre)}</option>`).join('');
            form.elements.cantidad.value = options.disponible_bodega; form.elements.cantidad.max = options.disponible_bodega;
            dialog.querySelector('[data-transfer-context]').textContent = `${options.numero_folio} · Bodega disponible: ${qty(options.disponible_bodega)} · reservado: ${qty(options.reservado_bodega)} · centros de costo: ${qty(options.en_centros_costo)} ${options.unidad_medida}`;
            selectClient(); describeMode(); dialog.showModal();
        } catch (reason) { root.querySelector('[data-transfer-list-error]').textContent = reason.message; }
        finally { busy = false; }
    }
    async function showResult(t) {
        const ticket = generation;
        dialog.querySelector('[data-transfer-context]').textContent = `${t.folio_origen.numero_folio} → ${t.folio_destino.numero_folio} · ${t.cliente_origen.codigo} → ${t.cliente_destino.codigo}`;
        form.hidden = true; result.innerHTML = `<h3>Folio nuevo: ${escape(t.folio_destino.numero_folio)}</h3><p>${t.modalidad === 'parcial' ? 'El bulto separado queda pendiente de ubicación. Conserva la etiqueta del folio origen.' : 'El folio nuevo conserva la ubicación del origen. Reemplaza la etiqueta antigua.'}</p>`;
        if (!getIdentity()?.puede_imprimir_etiquetas_materiales) return;
        const profiles = (await api('/api/materiales/recepciones/perfiles-impresion')).data;
        if (ticket !== generation) return;
        result.insertAdjacentHTML('beforeend', `<form data-print-form><label>Perfil<select name="perfil_id" required>${profiles.map((p) => `<option value="${escape(p.id)}" ${p.predeterminado ? 'selected' : ''}>${escape(p.nombre)} · ${escape(p.ancho_mm)}×${escape(p.alto_mm)} mm</option>`).join('')}</select></label><label>Formato<select name="formato"><option value="pdf">PDF</option><option value="zpl">ZPL</option><option value="nlbl">NiceLabel</option></select></label><label>Código<select name="simbologia"><option value="code128">Code 128</option><option value="qr">QR (ZPL / NiceLabel)</option></select></label><label>Copias<input name="copias" type="number" value="1" min="1" max="20" required></label><label>Motivo de reimpresión (si corresponde)<input name="motivo_reimpresion" minlength="5" maxlength="1000"></label><button type="submit" ${profiles.length ? '' : 'disabled'}>Imprimir etiqueta</button><p role="alert" data-print-error></p></form>`);
        const printForm = result.querySelector('form'); printOperation = null;
        printForm.addEventListener('change', () => { printOperation = null; const qr = printForm.elements.simbologia.querySelector('[value="qr"]'); qr.disabled = printForm.elements.formato.value === 'pdf'; if (qr.disabled) printForm.elements.simbologia.value = 'code128'; });
        printForm.elements.simbologia.querySelector('[value="qr"]').disabled = true;
        printForm.addEventListener('submit', async (event) => {
            event.preventDefault(); const button = printForm.querySelector('button'); if (button.disabled) return; button.disabled = true;
            try {
                printOperation ||= uuid(); const data = Object.fromEntries(new FormData(printForm));
                await download(`/api/materiales/transferencias-clientes/${t.id}/etiquetas`, { method: 'POST', body: JSON.stringify({ ...data, operacion_id: printOperation, canal: 'oficina_descarga', folio_ids: [t.folio_destino.id], copias: Number(data.copias), motivo_reimpresion: data.motivo_reimpresion.trim() || null }) }, `etiqueta-${t.folio_destino.numero_folio}.${data.formato}`);
                printForm.querySelector('[data-print-error]').textContent = 'Etiqueta generada. Si vuelves a imprimir, indica el motivo.'; printOperation = null;
            } catch (reason) { printForm.querySelector('[data-print-error]').textContent = reason.message; }
            finally { button.disabled = false; }
        });
    }
    document.getElementById('materialsInventoryBody')?.addEventListener('click', (event) => { const button = event.target.closest('[data-transfer-client]'); if (button) void open(button.dataset.transferClient); });
    form.elements.cliente_destino_id.addEventListener('change', selectClient); form.elements.cantidad.addEventListener('input', describeMode);
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); const ticket = generation; if (busy || !canTransfer()) return; busy = true; error.textContent = ''; const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            const data = Object.fromEntries(new FormData(form));
            const t = (await api(`/api/materiales/inventario/${options.folio_origen_id}/transferir-cliente`, { method: 'POST', body: JSON.stringify({ ...data, cantidad: Number(data.cantidad), operacion_id: operation }) })).data;
            if (ticket !== generation) return; await showResult(t); await onRefresh();
        } catch (reason) { error.textContent = reason.message; }
        finally { busy = false; button.disabled = false; }
    });
    filters.addEventListener('submit', (event) => { event.preventDefault(); page = 1; void refresh(); });
    root.addEventListener('click', async (event) => {
        const ticket = generation; const button = event.target.closest('button'); if (!button || busy) return;
        try {
            if (button.hasAttribute('data-transfer-csv')) await download(`/api/materiales/transferencias-clientes/exportar.csv?${query()}`, {}, 'transferencias-clientes-materiales.csv');
            else if (button.hasAttribute('data-transfer-previous')) { page--; await refresh(); }
            else if (button.hasAttribute('data-transfer-next')) { page++; await refresh(); }
            else if (button.dataset.printTransfer) { const t = records.find((r) => r.id === button.dataset.printTransfer); error.textContent = ''; await showResult(t); if (ticket === generation) dialog.showModal(); }
        } catch (reason) { root.querySelector('[data-transfer-list-error]').textContent = reason.message; }
    });
    dialog.querySelector('[data-transfer-close]').addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
    window.addEventListener('estiba:office-session', () => { generation++; options = null; operation = null; dialog.close(); result.replaceChildren(); root.hidden = true; });
    return { refresh };
}
