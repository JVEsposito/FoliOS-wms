export function createMaterialUnits({ api, getItems, getToken, authorized, escapeHtml: esc }) {
    const $ = (id) => document.getElementById(id);
    const form = $('materialUnitForm'); const conversionForm = $('materialUnitConversionForm');
    const baseSelect = document.querySelector('#itemMaterialForm [name="unidad_medida"]');
    let units = []; let conversions = []; let generation = 0; let conversionRevision = 0; let busy = false;
    const valid = (n, token) => n === generation && getToken() === token && authorized();
    const options = (values) => values.map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('');
    function reset() { form.reset(); form.elements.id.value = ''; form.elements.codigo.readOnly = false; form.elements.version_conocida.value = 0; }
    function renderBase() {
        const value = baseSelect.value;
        const known = new Map(units.filter((u) => u.activa || u.codigo === value).map((u) => [u.codigo, u.nombre]));
        for (const item of getItems()) if (!units.some((u) => !u.activa && (u.codigo === item.unidad_medida || u.alias?.includes(item.unidad_medida)))) known.set(item.unidad_medida, item.unidad_medida);
        if (value) known.set(value, value);
        baseSelect.innerHTML = '<option value="">Seleccione una unidad</option>' + options([...known]);
        baseSelect.value = value;
    }
    function selectConversion() {
        const entry = conversions.find((c) => c.origen.codigo === $('materialConversionUnit').value);
        conversionRevision = entry?.version || 0;
        conversionForm.elements.factor.value = entry?.factor || '';
        conversionForm.elements.activa.checked = entry ? entry.activa : true;
    }
    async function loadConversions() {
        const id = $('materialConversionItem').value; conversions = []; conversionRevision = 0;
        $('materialConversionsList').replaceChildren();
        if (!id || !authorized()) return;
        const n = ++generation; const token = getToken();
        try {
            const result = await api(`/api/administracion/materiales/items/${encodeURIComponent(id)}/unidades`);
            if (!valid(n, token)) return;
            conversions = result.data;
            $('materialConversionFactorLabel').textContent = `Cantidad en ${result.unidad_base} por unidad de ingreso *`;
            $('materialConversionsList').innerHTML = conversions.map((c) => `<div class="material-row"><strong>1 ${esc(c.origen.codigo)} = ${esc(c.factor)} ${esc(result.unidad_base)}</strong><small>Versión ${c.version} · ${c.activa ? 'Activa' : 'Inactiva'}</small></div>`).join('');
            selectConversion();
        } catch (error) { if (valid(n, token)) $('materialUnitsStatus').textContent = error.message; }
    }
    async function load() {
        if (!authorized() || busy) return;
        const n = ++generation; const token = getToken();
        try {
            const [data, history] = await Promise.all([api('/api/administracion/materiales/unidades'), api('/api/administracion/materiales/unidades/historial')]);
            if (!valid(n, token)) return;
            units = data.data; renderBase();
            $('materialUnitsList').innerHTML = units.map((u) => `<div class="material-row"><strong>${esc(u.codigo)} · ${esc(u.nombre)}</strong><small>${u.activa ? 'Activa' : 'Inactiva'} · ${esc(u.alias.join(', '))}</small><button type="button" data-edit-unit="${esc(u.id)}">Editar</button></div>`).join('');
            const item = $('materialConversionItem').value; const unit = $('materialConversionUnit').value;
            $('materialConversionItem').innerHTML = '<option value="">Seleccione un ítem</option>' + options(getItems().filter((i) => i.activo).map((i) => [i.id, `${i.cliente?.codigo || ''} · ${i.codigo} · ${i.nombre} (${i.unidad_medida})`]));
            $('materialConversionUnit').innerHTML = '<option value="">Seleccione una unidad</option>' + options(units.filter((u) => u.activa).map((u) => [u.codigo, u.nombre]));
            $('materialConversionItem').value = item; $('materialConversionUnit').value = unit;
            $('materialUnitsHistory').innerHTML = history.data.map((e) => `<div class="material-row"><strong>${esc(e.accion)} · ${esc(e.responsable || 'Sistema')}</strong><small>${esc(e.motivo || '')} · ${esc(e.created_at)}</small></div>`).join('');
            await loadConversions();
        } catch (error) { if (valid(n, token)) $('materialUnitsStatus').textContent = error.message; }
    }
    async function save(path, data, callback) {
        if (!authorized() || busy) return;
        busy = true; const n = generation; const token = getToken();
        $('materialUnitsStatus').textContent = 'Guardando…';
        try {
            await api(path, { method: data.id ? 'PUT' : 'POST', body: JSON.stringify(data) });
            if (!valid(n, token)) return;
            callback(); $('materialUnitsStatus').textContent = 'Guardado correctamente.';
        } catch (error) { if (valid(n, token)) $('materialUnitsStatus').textContent = error.message; }
        finally { busy = false; if (valid(n, token)) await load(); }
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault(); const data = Object.fromEntries(new FormData(form));
        data.alias = [...new Set(data.alias.split(',').map((a) => a.trim()).filter(Boolean))]; data.activa = form.elements.activa.checked; data.version_conocida = Number(data.version_conocida);
        void save(`/api/administracion/materiales/unidades${data.id ? `/${encodeURIComponent(data.id)}` : ''}`, data, reset);
    });
    conversionForm.addEventListener('submit', (event) => {
        event.preventDefault(); const id = $('materialConversionItem').value;
        void save(`/api/administracion/materiales/items/${encodeURIComponent(id)}/unidades`, { id, unidad_origen: $('materialConversionUnit').value, factor: conversionForm.elements.factor.value, activa: conversionForm.elements.activa.checked, motivo: conversionForm.elements.motivo.value, version_conocida: conversionRevision }, () => { conversionForm.elements.motivo.value = ''; });
    });
    $('materialUnitsList').addEventListener('click', (event) => {
        const u = units.find((u) => u.id === event.target.closest('[data-edit-unit]')?.dataset.editUnit); if (!u || busy) return;
        for (const name of ['id', 'codigo', 'nombre']) form.elements[name].value = u[name];
        form.elements.alias.value = u.alias.join(', '); form.elements.activa.checked = u.activa; form.elements.version_conocida.value = u.version; form.elements.codigo.readOnly = true; form.elements.motivo.value = ''; form.scrollIntoView({ block: 'center' });
    });
    $('newMaterialUnit').addEventListener('click', reset);
    $('reloadMaterialUnits').addEventListener('click', () => { void load(); });
    $('materialConversionItem').addEventListener('change', () => { void loadConversions(); });
    $('materialConversionUnit').addEventListener('change', selectConversion);
    $('itemMaterialEditor').addEventListener('toggle', () => { if ($('itemMaterialEditor').open) void load(); });
    document.addEventListener('estiba:office-panel-change', (event) => { if (event.detail?.group === 'materials-catalog' && event.detail.panel === 'units') void load(); });
    window.addEventListener('estiba:office-session', () => {
        generation++; units = []; conversions = []; reset(); conversionForm.reset();
        for (const id of ['materialUnitsList', 'materialConversionsList', 'materialUnitsHistory', 'materialUnitsStatus', 'materialConversionItem', 'materialConversionUnit']) $(id).replaceChildren();
    });
    return { renderBase };
}
