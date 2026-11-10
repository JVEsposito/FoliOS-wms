import { repackingBalance } from './shared/repacking-balance.js';

export function createRepackingEditor({ root, api, escapeHtml: esc, uuid, shift, onSuccess }) {
    let source = null; let results = []; let pending = false; let operation = null;
    const kg = (u) => `${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 4 }).format(u / 10000)} kg`;
    function read() {
        if (!source) return { results: [], losses: {} };
        results = [...root.querySelectorAll('[data-repack-result]')].map((card) => ({
            numero_folio: card.querySelector('[data-number]').value.trim().toUpperCase(),
            tipo_resultado: card.querySelector('[data-type]').value,
            cantidad_objetivo: Number(card.querySelector('[data-capacity]').value || 0) || null,
            envase_validacion_id: card.querySelector('[data-format]').value,
            cantidades: Object.fromEntries([...card.querySelectorAll('[data-count]')].map((input) => [input.dataset.count, input.value])),
        }));
        return { results, losses: Object.fromEntries([...root.querySelectorAll('[data-loss]')].map((input) => [input.dataset.loss, input.value])) };
    }
    function preview() {
        if (!source) return;
        const draft = read(); const balance = repackingBalance(source.reembalaje, draft.results, draft.losses);
        root.querySelector('[data-balance]').textContent = `Entrada ${kg(balance.input)} · Salida ${kg(balance.output)} · Merma ${kg(balance.loss)} · ${balance.balanced ? 'Cuadrado' : 'Pendiente de cuadrar'}`;
        for (const row of balance.rows) root.querySelector(`[data-difference="${row.clave}"]`).textContent = row.valid ? `Diferencia: ${kg(row.difference)}` : 'Revisa cantidades y kilos';
    }
    function renderResults() {
        const context = source.reembalaje;
        root.querySelector('[data-results]').innerHTML = results.map((result, index) => `<fieldset class="repacking-result" data-repack-result><legend>Salida ${index + 1}</legend>
            <div class="repa-grid"><label><span>Folio de salida *</span><input data-number aria-label="Folio de salida ${index + 1}" maxlength="80" value="${esc(result.numero_folio)}" ${index === 0 && result.numero_folio === source.numero_folio ? 'readonly' : ''}></label>
            <label><span>Formato *</span><select data-format aria-label="Formato de salida ${index + 1}"><option value="">Seleccionar formato</option>${context.formatos.map((f) => `<option value="${esc(f.id)}" ${result.envase_validacion_id === f.id ? 'selected' : ''}>${esc(f.nombre)} · ${esc(f.kilos_netos_por_caja)} kg/caja</option>`).join('')}</select></label>
            <label><span>Tipo de bulto</span><select data-type><option value="pallet" ${result.tipo_resultado === 'pallet' ? 'selected' : ''}>Pallet completo</option><option value="saldo" ${result.tipo_resultado === 'saldo' ? 'selected' : ''}>Saldo</option></select></label>
            <label><span>Capacidad del pallet</span><input data-capacity type="number" min="1" max="100000" value="${result.cantidad_objetivo || ''}"></label></div>
            ${index === 0 ? `<label class="repacking-keep"><input type="checkbox" data-keep ${result.numero_folio === source.numero_folio ? 'checked' : ''}> Conservar folio de origen</label>` : ''}
            ${context.componentes.map((line) => `<label class="repacking-count"><span>CSG ${esc(line.csg)} · ${esc(line.variedad || '')} · ${esc(line.calibre || '')} · ${esc(line.fecha_embalaje || '')}</span><input data-count="${line.clave}" aria-label="Cajas salida ${index + 1} CSG ${esc(line.csg)}" type="number" min="0" max="100000" step="1" value="${result.cantidades[line.clave] || ''}" placeholder="Cajas de salida"></label>`).join('')}
            ${index > 0 ? `<button type="button" class="secondary-button" data-remove-result="${index}">Quitar salida</button>` : ''}</fieldset>`).join('');
        preview();
    }
    function reset() {
        source = null; results = []; operation = null;
        root.innerHTML = `<h2>Reembalaje · control de kilos netos</h2><p>Un folio completo puede salir en distintos formatos. Entrada = salida + merma declarada. Para trabajar una parte, divide primero el folio.</p><div class="source-entry"><label><span>Folio de origen</span><input data-lookup maxlength="80" placeholder="Escanear o escribir folio"></label><button type="button" class="secondary-button" data-find>Consultar folio</button></div><p data-repack-error class="form-error" role="alert"></p><p data-success aria-live="polite"></p><div data-work></div>`;
    }
    const error = (message) => { root.querySelector('[data-repack-error]').textContent = message; };
    async function find() {
        if (pending) return;
        const number = root.querySelector('[data-lookup]').value.trim(); if (!number) return;
        pending = true; error(''); root.querySelector('[data-success]').textContent = ''; source = null; root.querySelector('[data-work]').innerHTML = '';
        try {
            const loaded = await api(`/api/validacion/repaletizajes/folios/${encodeURIComponent(number)}?reembalaje=1`);
            if (!loaded.existe || !loaded.activo || loaded.cantidad_cajas < 1) throw Error('Consulta un pallet o saldo activo con cajas.');
            if (loaded.desglose_incompleto) throw Error('Regulariza el desglose del MIX antes de reembalar.');
            if (!loaded.reembalaje) throw Error('Actualiza el servidor para habilitar reembalajes.');
            if (loaded.reembalaje.errores.length) throw Error(loaded.reembalaje.errores.join(' '));
            source = loaded; operation = null;
            results = [{ numero_folio: source.numero_folio, tipo_resultado: 'saldo', cantidad_objetivo: 120, envase_validacion_id: '', cantidades: {} }];
            root.querySelector('[data-work]').innerHTML = `${(source.reembalaje.advertencias || []).map((warning) => `<p role="status">${esc(warning)}</p>`).join('')}<p><strong>${esc(source.numero_folio)} · ${esc(source.cliente)}</strong> · ${source.cantidad_cajas} cajas · ${esc(source.reembalaje.kilos_entrada)} kg de entrada</p>
                <div data-results></div><button type="button" class="secondary-button" data-add-result>+ Agregar salida / formato</button>
                <h3>Merma por componente</h3><p>Declárala expresamente; no se calcula ni se acepta como ajuste automático.</p>
                ${source.reembalaje.componentes.map((l) => `<label class="repacking-count"><span>CSG ${esc(l.csg)} · ${esc(l.variedad || '')} · ${esc(l.calibre || '')}<small>${l.cantidad_cajas} cajas × ${esc(l.kilos_netos_por_caja)} kg</small><small data-difference="${l.clave}"></small></span><input data-loss="${l.clave}" aria-label="Merma kg CSG ${esc(l.csg)}" type="number" min="0" step="0.0001" value="0"></label>`).join('')}
                <label><span>Motivo de la merma</span><textarea data-reason maxlength="1000" rows="2"></textarea></label>
                <p class="hard-rule" data-balance aria-live="polite"></p><button class="primary-button" type="button" data-confirm>Confirmar reembalaje</button>`;
            renderResults();
        } catch (reason) { error(reason.message); } finally { pending = false; }
    }
    async function submit() {
        if (pending || !source) return;
        error(''); const { results: outputs, losses } = read(); const balance = repackingBalance(source.reembalaje, outputs, losses);
        if (!balance.balanced) return error('Deben cuadrar los kilos de cada componente: entrada = salida + merma.');
        if (outputs.some((r) => !r.numero_folio || !r.envase_validacion_id)) return error('Completa folio y formato de cada salida.');
        if (new Set(outputs.map((r) => r.numero_folio)).size !== outputs.length) return error('No repitas folios entre las salidas.');
        for (const [index, row] of outputs.entries()) {
            const quantity = Object.values(row.cantidades).reduce((n, c) => n + Number(c || 0), 0);
            if (quantity < 1 || quantity > 100000) return error(`Salida ${index + 1}: registra entre 1 y 100.000 cajas.`);
            if (row.tipo_resultado === 'pallet' && (!row.cantidad_objetivo || quantity !== row.cantidad_objetivo)) return error(`Salida ${index + 1}: el pallet debe completar su capacidad exacta.`);
            if (row.tipo_resultado === 'saldo' && row.cantidad_objetivo && quantity >= row.cantidad_objetivo) return error(`Salida ${index + 1}: un saldo debe quedar bajo la capacidad del pallet.`);
        }
        const motivo = root.querySelector('[data-reason]').value.trim();
        if (balance.loss > 0 && !motivo) return error('Indica el motivo de la merma.');
        const payload = { modalidad: 'reembalaje', ...shift(), huella_reembalaje: source.reembalaje.huella,
            origenes: [{ folio_id: source.id, cantidad_aportada: source.cantidad_cajas }],
            resultados: outputs.map((r) => ({ numero_folio: r.numero_folio, tipo_resultado: r.tipo_resultado, cantidad_objetivo: r.cantidad_objetivo,
                envase_validacion_id: r.envase_validacion_id, cantidad_resultante: Object.values(r.cantidades).reduce((n, c) => n + Number(c || 0), 0),
                composicion: Object.entries(r.cantidades).filter(([, c]) => Number(c) > 0).map(([clave, c]) => ({ clave, cantidad_cajas: Number(c) })) })),
            mermas: Object.entries(losses).map(([clave, kilos]) => ({ clave, kilos: kilos || '0' })), motivo_merma: motivo || null };
        const signature = JSON.stringify(payload); if (operation?.signature !== signature) operation = { signature, id: uuid() };
        pending = true; const button = root.querySelector('[data-confirm]'); button.disabled = true;
        try {
            const response = await api('/api/validacion/repaletizajes', { method: 'POST', body: JSON.stringify({ ...payload, operacion_id: operation.id }) });
            reset(); root.querySelector('[data-success]').textContent = `${response.data.codigo}: reembalaje confirmado y kilos cuadrados.`;
            await onSuccess(response.data);
        } catch (reason) { error(reason.message); } finally { pending = false; if (button.isConnected) button.disabled = false; }
    }
    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-find]')) void find();
        if (event.target.closest('[data-confirm]')) void submit();
        if (pending || !source) return;
        if (event.target.closest('[data-add-result]') && results.length < 20) { read(); results.push({ numero_folio: '', tipo_resultado: 'saldo', cantidad_objetivo: 120, envase_validacion_id: '', cantidades: {} }); renderResults(); }
        const remove = event.target.closest('[data-remove-result]'); if (remove) { read(); results.splice(Number(remove.dataset.removeResult), 1); renderResults(); }
    });
    root.addEventListener('input', preview);
    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-keep]')) { const input = root.querySelector('[data-number]'); input.value = event.target.checked ? source.numero_folio : ''; input.readOnly = event.target.checked; }
        preview();
    });
    root.addEventListener('keydown', (event) => { if (event.key === 'Enter' && event.target.matches('[data-lookup]')) { event.preventDefault(); void find(); } });
    reset(); return { reset, submit };
}
