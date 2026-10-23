export function createOriginStockMapping({ api, authorized, session, explorer, escapeHtml: esc }) {
    const $ = (id) => document.getElementById(id);
    const form = $('originStockMapping');
    let generation = 0;
    let source = null;
    let revision = 0;
    let signature = '';
    const valid = (version, token) => version === generation && token === session() && authorized();
    function clear() {
        generation++; source = null; revision = 0; signature = '';
        form.hidden = true; form.reset();
        $('originMappingTitle').textContent = 'Mapeo de stock';
        for (const id of ['originMappingFields', 'originMappingStatus', 'originMappingSample', 'originMappingFilters']) $(id).replaceChildren();
    }
    function busy(value) {
        form.querySelectorAll('select, input, button').forEach((element) => { element.disabled = value; });
        form.setAttribute('aria-busy', String(value));
    }
    $('originExplorer').addEventListener('origin:selection', (event) => {
        const next = event.detail;
        const key = JSON.stringify(next);
        if (key !== signature) { clear(); signature = key; }
        $('originConfigureStock').disabled = !next?.columnas?.length;
    });
    $('originConfigureStock').addEventListener('click', async () => {
        const chosen = explorer.source();
        if (!chosen?.columnas?.length || !authorized()) return;
        clear(); source = structuredClone(chosen); form.hidden = false; busy(true);
        const version = ++generation; const token = session();
        $('originMappingStatus').textContent = 'Cargando la configuración de lectura…';
        try {
            const { data } = await api('/lectura-stock');
            if (!valid(version, token)) return;
            revision = data.version;
            const same = data.conexion_id === source.conexion_id && data.ajustes?.tabla === source.tabla;
            $('originMappingEnabled').checked = same ? data.ajustes.habilitada : true;
            $('originMappingTitle').textContent = `Pendientes por validar · ${source.tabla}`;
            $('originMappingFilters').textContent = source.filtros.length
                ? `Se guardarán estos filtros: ${source.filtros.map((f) => `${f.columna} = ${f.valor}`).join(' · ')}`
                : 'Sin filtros: se leerá toda la fuente seleccionada.';
            $('originMappingFields').innerHTML = Object.entries(data.campos).map(([field, label]) => {
                const required = ['Pallet', 'cantidad'].includes(field);
                const column = same ? data.ajustes.campos[field] : source.columnas.find((c) => c.nombre.toLowerCase() === field.toLowerCase())?.nombre;
                return `<label class="field"><span>${esc(label)}${required ? ' *' : ''}</span><select data-stock-field="${esc(field)}" ${required ? 'required' : ''}><option value="">${required ? 'Seleccione una columna' : 'Sin asignar'}</option>${source.columnas.map((c) => `<option value="${esc(c.nombre)}" ${column === c.nombre ? 'selected' : ''}>${esc(c.nombre)}</option>`).join('')}</select></label>`;
            }).join('');
            $('originMappingStatus').textContent = same ? `Configuración guardada, versión ${revision}. Revise los filtros antes de volver a guardar.` : 'Asigne las columnas de folio y cajas; complete los demás campos que entregue el origen.';
        } catch (error) { if (valid(version, token)) $('originMappingStatus').textContent = error.message; }
        finally { if (valid(version, token)) busy(!$('originMappingFields').children.length); }
    });
    form.addEventListener('input', () => { generation++; $('originMappingSample').replaceChildren(); $('originMappingStatus').textContent = 'Cambios sin guardar. Puede probar una muestra con este mapeo.'; });
    async function submit(preview) {
        if (!source || !authorized() || !form.reportValidity()) return;
        const fields = Object.fromEntries([...form.querySelectorAll('[data-stock-field]')].map((el) => [el.dataset.stockField, el.value || null]));
        const payload = {
            conexion_id: source.conexion_id, version_conexion: source.version_conexion, version_conocida: revision,
            ajustes: { tabla: source.tabla, campos: fields, filtros: source.filtros, habilitada: $('originMappingEnabled').checked },
        };
        const version = ++generation; const token = session(); busy(true);
        $('originMappingSample').replaceChildren();
        $('originMappingStatus').textContent = preview ? 'Probando la lectura con las columnas asignadas…' : 'Guardando la configuración…';
        try {
            const { data } = await api(`/lectura-stock${preview ? '/simular' : ''}`, { method: preview ? 'POST' : 'PUT', body: JSON.stringify(payload), valid: () => valid(version, token), onProgress: (op) => { if (valid(version, token)) $('originMappingStatus').textContent = op.fase; } });
            if (!valid(version, token)) return;
            if (preview) {
                const columns = Object.keys(fields).filter((field) => fields[field]);
                $('originMappingSample').innerHTML = `<table class="admin-table"><thead><tr>${columns.map((c) => `<th>${esc(form.querySelector(`[data-stock-field="${c}"]`).closest('label').querySelector('span').textContent)}</th>`).join('')}</tr></thead><tbody>${data.filas.map((row) => `<tr>${columns.map((c) => `<td>${row[c] === null ? '—' : esc(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
                $('originMappingStatus').textContent = data.filas.length ? `Mapeo válido para ${data.filas.length} filas de muestra. Guarde para usarlo en las lecturas.` : 'La fuente respondió sin filas. Revise los filtros antes de guardar.';
            } else {
                revision = data.version;
                $('originMappingStatus').textContent = payload.ajustes.habilitada
                    ? 'Configuración guardada. Use Frigorífico → Pendientes por validar → Leer ahora; las lecturas programadas también usarán esta selección.'
                    : 'Configuración guardada con la lectura deshabilitada.';
            }
        } catch (error) { if (valid(version, token)) $('originMappingStatus').textContent = error.message; }
        finally { if (valid(version, token)) busy(false); }
    }
    $('originMappingPreview').addEventListener('click', () => { void submit(true); });
    form.addEventListener('submit', (event) => { event.preventDefault(); void submit(false); });
    return { clear };
}
