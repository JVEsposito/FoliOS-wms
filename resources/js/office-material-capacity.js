import { createUuid } from './shared/uuid.js';
import './office-navigation.js';

const $ = (id) => document.getElementById(`capacity${id}`);
const base = '/api/materiales/capacidad';
const esc = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => v === null || v === undefined ? 'Sin dato' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(v));
const date = (value) => new Intl.DateTimeFormat('es-CL', { timeZone: catalog?.zona_horaria || 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T') + (/Z$|[+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z')));
const numeric = new Set(['largo_m', 'ancho_m', 'altura_total_m', 'altura_util_m', 'max_niveles', 'peso_maximo_kg', 'peso_unitario_kg', 'bultos_fisicos']);
let catalog = null; let rows = []; let type = null; let measurement = null; let busy = false;
let catalogSeq = 0; let stockSeq = 0; let simulationSeq = 0; let historySeq = 0; let historyFilter = {};
let warehousesPending = false;
const pending = new WeakMap();
function fields(form) {
    return Object.fromEntries([...new FormData(form)].map(([key, v]) => [key, v === '' ? null : numeric.has(key) ? Number(v) : key === 'activo' ? v === '1' : v]));
}
function fill(form, data) { for (const input of form.querySelectorAll('[name]')) input.value = data?.[input.name] ?? ''; }
function warehouse() { return catalog?.almacenes.find((a) => a.id === $('Warehouse').value); }
function typesOptions(select, selected = null) {
    select.innerHTML = '<option value="">Selecciona un tipo</option>' + (catalog?.tipos || []).map((t) => `<option value="${esc(t.id)}">${esc(t.codigo)} · ${esc(t.nombre)}${Number(t.activo) ? '' : ' (inactivo)'}</option>`).join('');
    select.value = selected || '';
}
async function api(path, options = {}) {
    const response = await fetch(base + path, { cache: 'no-store', ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token') || ''}` } });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(response.status === 401 ? 'Tu sesión venció. Ingresa nuevamente desde Materiales.' : response.status === 403 ? 'Tu perfil no tiene permiso para esta acción.' : Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo completar la solicitud.');
        error.status = response.status; throw error;
    }
    return body;
}
function permissions() {
    const can = catalog?.permisos || {};
    $('Warehouse').disabled = busy || !catalog;
    $('Reload').disabled = busy;
    const activeWarehouse = Number(warehouse()?.activo) === 1;
    $('WarehouseFields').disabled = busy || !can.configurar || !activeWarehouse;
    $('TypeFields').disabled = busy || !can.configurar;
    $('MeasurementFields').disabled = busy || !can.medir;
    $('SimulationFields').disabled = busy || !activeWarehouse;
    $('SaveWarehouse').hidden = !can.configurar; $('SaveWarehouse').disabled = busy || !activeWarehouse;
    $('NewType').hidden = !can.configurar; $('NewType').disabled = busy;
    for (const form of [$('TypeForm'), $('MeasurementForm'), $('SimulationForm')]) form.querySelector('[type="submit"]').disabled = busy || !catalog;
}
function editType(id = null) {
    if (busy || !catalog?.permisos.configurar) return;
    type = id ? catalog.tipos.find((t) => t.id === id) : null;
    pending.delete($('TypeForm'));
    $('TypeForm').hidden = false; $('Types').open = true;
    $('TypeTitle').textContent = type ? `Editar ${type.codigo}` : 'Crear tipo de pallet';
    fill($('TypeForm'), { ...type, activo: type ? String(Number(type.activo)) : '1', max_niveles: type?.max_niveles ?? 1 });
    permissions();
}
function zoneInput(title, key, value, { max = 1000, min = .001, step = .001, required = true } = {}) {
    return `<label>${title}<input data-zone-field="${key}" type="number" min="${min}" max="${max}" step="${step}" ${required ? 'required' : ''} value="${esc(value)}"></label>`;
}
function addZone(z = {}) {
    const node = document.createElement('fieldset'); node.className = 'capacity-zone'; node.dataset.zoneId = z.id || createUuid();
    node.innerHTML = `<legend>Zona útil</legend><div class="capacity-grid"><label>Nombre<input data-zone-field="nombre" required maxlength="100" value="${esc(z.nombre)}"></label><label>Modo<select data-zone-field="modo"><option value="piso" ${z.modo !== 'rack' ? 'selected' : ''}>Piso</option><option value="rack" ${z.modo === 'rack' ? 'selected' : ''}>Rack con huecos declarados</option></select></label>`
        + [['X desde el origen (m)', 'x_m'], ['Y desde el origen (m)', 'y_m']].map(([title, key]) => zoneInput(title, key, z[key] ?? 0, { min: 0 })).join('')
        + [['Largo útil (m)', 'largo_m'], ['Ancho útil (m)', 'ancho_m'], ['Altura útil (m)', 'altura_util_m']].map(([title, key]) => zoneInput(title, key, z[key], { max: key === 'altura_util_m' ? 50 : 1000 })).join('')
        + zoneInput('Máximo de niveles apilados', 'max_niveles', z.max_niveles ?? 1, { max: 20, min: 1, step: 1 })
        + zoneInput('Tope de pallets / huecos útiles de rack', 'limite_pallets', z.limite_pallets, { max: 10000000, min: 1, step: 1, required: false })
        + zoneInput('Peso máximo por pallet (kg, opcional)', 'peso_maximo_kg', z.peso_maximo_kg, { max: 1000000, required: false })
        + `<label>Permitir giro de 90°<input data-zone-field="permite_giro" type="checkbox" ${z.permite_giro ? 'checked' : ''}></label></div><div class="capacity-grid" data-rack-fields>`
        + [['Largo útil del hueco (m)', 'hueco_largo_m'], ['Ancho útil del hueco (m)', 'hueco_ancho_m'], ['Altura útil del hueco (m)', 'hueco_altura_m']].map(([title, key]) => zoneInput(title, key, z[key], { max: 20, required: false })).join('')
        + '</div><p>Piso: acomodo uniforme y apilado. Rack: cuenta los huecos físicos existentes y mide cada hueco; se admite un pallet por hueco, sin apilar dentro de él.</p><p>Tipos de pallet habilitados:</p><div class="capacity-type-options">'
        + (catalog?.tipos || []).map((t) => `<label><input type="checkbox" data-allowed-type value="${esc(t.id)}" ${(z.tipo_pallet_ids || []).includes(t.id) ? 'checked' : ''}>${esc(t.codigo)}${Number(t.activo) ? '' : ' (inactivo)'}</label>`).join('')
        + `</div><button type="button" class="secondary-button" data-remove-zone ${catalog?.permisos.configurar ? '' : 'hidden'}>Quitar zona</button>`;
    $('Zones').append(node);
    rackFields(node);
}
function rackFields(node) {
    const rack = node.querySelector('[data-zone-field="modo"]').value === 'rack';
    const group = node.querySelector('[data-rack-fields]'); group.hidden = !rack;
    group.querySelectorAll('input').forEach((input) => { input.disabled = !rack; input.required = rack; });
}
function zoneData() {
    return [...$('Zones').children].map((node) => ({ id: node.dataset.zoneId,
        ...Object.fromEntries([...node.querySelectorAll('[data-zone-field]')].filter((input) => !input.disabled).map((input) => [input.dataset.zoneField, input.type === 'checkbox' ? input.checked : input.value === '' ? null : input.type === 'number' ? Number(input.value) : input.value])),
        tipo_pallet_ids: [...node.querySelectorAll('[data-allowed-type]:checked')].map((input) => input.value),
    }));
}
function preset(form) {
    const t = catalog?.tipos.find((t) => t.id === form.elements.tipo_pallet_id.value);
    if (t) fill(form, { ...t, tipo_pallet_id: t.id, bultos_fisicos: form.elements.bultos_fisicos?.value ?? '', peso_unitario_kg: null });
}
async function selectWarehouse() {
    ++simulationSeq; $('SimulationResult').replaceChildren(); $('MeasurementForm').hidden = true;
    pending.delete($('WarehouseForm')); pending.delete($('MeasurementForm'));
    const a = warehouse(); const config = a?.configuracion;
    fill($('WarehouseForm'), config); $('Zones').replaceChildren();
    (config?.zonas || [{}]).forEach(addZone);
    $('Metrics').textContent = config ? `${qty(config.superficie_util_m2)} m² útiles · ${qty(config.volumen_util_m3)} m³ útiles · ${config.zonas.length} zonas · Versión ${config.version}` : 'Almacén sin dimensiones configuradas. No se presume capacidad.';
    permissions(); await loadStocks();
}
async function reload() {
    const sequence = ++catalogSeq; const oldWarehouse = $('Warehouse').value;
    ++stockSeq; ++simulationSeq; ++historySeq; $('Notice').textContent = 'Consultando…';
    catalog = null; permissions(); $('TypeList').replaceChildren(); $('Zones').replaceChildren(); $('Metrics').textContent = '';
    $('HistoryRows').replaceChildren(); $('HistoryPages').replaceChildren();
    $('StockRows').replaceChildren(); $('SimulationResult').replaceChildren(); $('MeasurementForm').hidden = true; $('TypeForm').hidden = true;
    try {
        const data = await api('/catalogo'); if (sequence !== catalogSeq) return;
        catalog = data; type = null;
        for (const form of [$('TypeForm'), $('WarehouseForm'), $('MeasurementForm')]) pending.delete(form);
        $('Warehouse').innerHTML = '<option value="">Selecciona un almacén</option>' + data.almacenes.map((a) => `<option value="${esc(a.id)}">${esc(a.codigo)} · ${esc(a.nombre)}${Number(a.activo) ? '' : ' (inactivo)'}</option>`).join('');
        $('Warehouse').value = data.almacenes.some((a) => a.id === oldWarehouse) ? oldWarehouse : data.almacenes.find((a) => Number(a.activo))?.id || '';
        $('TypeList').innerHTML = data.tipos.map((t) => `<article><p><strong>${esc(t.codigo)} · ${esc(t.nombre)}</strong> · ${qty(t.largo_m)} × ${qty(t.ancho_m)} × ${qty(t.altura_total_m)} m · ${qty(t.max_niveles)} niveles · ${Number(t.activo) ? 'Activo' : 'Inactivo'}</p>${data.permisos.configurar ? `<button type="button" class="secondary-button" data-edit-type="${esc(t.id)}">Editar</button>` : ''}<button type="button" class="secondary-button" data-history-kind="tipo" data-history-id="${esc(t.id)}">Historial</button></article>`).join('') || '<p>Crea un tipo de pallet para comenzar a definir las zonas.</p>';
        typesOptions($('SimulationForm').elements.tipo_pallet_id);
        $('SimulationForm').elements.tipo_pallet_id.value = data.tipos.find((t) => Number(t.activo))?.id || ''; preset($('SimulationForm'));
        await selectWarehouse(); if (sequence !== catalogSeq) return;
        if ($('History').open) await loadHistory();
        $('Notice').textContent = '';
    } catch (error) {
        if (sequence !== catalogSeq) return;
        catalog = null; rows = []; $('Notice').textContent = error.message;
    } finally { if (sequence === catalogSeq) permissions(); }
}
async function loadStocks(page = 1) {
    const sequence = ++stockSeq; rows = []; measurement = null; $('StockRows').replaceChildren(); $('StockPages').replaceChildren(); $('MeasurementForm').hidden = true;
    if (!catalog || !$('Warehouse').value) { $('StockNotice').textContent = 'Selecciona un almacén físico.'; return; }
    $('StockNotice').textContent = 'Consultando cargas…';
    const params = new URLSearchParams({ almacen_id: $('Warehouse').value, page, busqueda: $('SearchForm').elements.busqueda.value });
    try {
        const body = await api(`/cargas?${params}`); if (sequence !== stockSeq) return;
        rows = body.data;
        $('StockRows').innerHTML = rows.map((r, index) => `<tr><td><strong>${esc(r.folio)}</strong><br>${esc(r.item)}</td><td>${qty(r.cantidad_actual)} ${esc(r.unidad_medida)}</td><td>${r.id ? r.medidas_vigentes ? 'Medidas vigentes' : 'Verificar: cambió el saldo' : 'Pendiente de medición'}</td><td>${r.id ? `${qty(r.largo_m)} × ${qty(r.ancho_m)} × ${qty(r.altura_total_m)}` : 'Sin medir'}</td><td>${r.id ? qty(r.bultos_fisicos) : 'Sin medir'}</td><td>${catalog.permisos.medir ? `<button type="button" class="secondary-button" data-measure="${index}">Registrar medidas</button>` : ''}${r.medidas_vigentes ? `<button type="button" class="secondary-button" data-simulate="${index}">Usar en simulación</button>` : ''}<button type="button" class="secondary-button" data-history-kind="carga" data-history-id="${esc(r.saldo_id)}">Historial</button></td></tr>`).join('') || '<tr><td colspan="6">Sin cargas para estos filtros.</td></tr>';
        $('StockPages').innerHTML = pages(body.meta);
        $('StockNotice').textContent = `${qty(body.meta.total)} saldos físicos. Las mediciones se conservan separadas de la cantidad de material.`;
    } catch (error) { if (sequence === stockSeq) $('StockNotice').textContent = error.message; }
}
function pages(meta) { return `<button type="button" class="secondary-button" data-page="${meta.pagina - 1}" ${meta.pagina <= 1 ? 'disabled' : ''}>Anterior</button><span>Página ${meta.pagina} de ${meta.paginas}</span><button type="button" class="secondary-button" data-page="${meta.pagina + 1}" ${meta.pagina >= meta.paginas ? 'disabled' : ''}>Siguiente</button>`; }
function editMeasurement(index) {
    if (busy || !catalog?.permisos.medir) return;
    measurement = rows[index]; if (!measurement) return;
    const form = $('MeasurementForm'); pending.delete(form); typesOptions(form.elements.tipo_pallet_id, measurement.tipo_pallet_id);
    if (!measurement.id) { form.elements.tipo_pallet_id.value = catalog.tipos.find((t) => Number(t.activo))?.id || ''; preset(form); form.elements.bultos_fisicos.value = ''; }
    else fill(form, measurement);
    $('MeasurementTitle').textContent = `Medición de ${measurement.folio} · ${qty(measurement.cantidad_actual)} ${measurement.unidad_medida}`;
    form.hidden = false; form.scrollIntoView({ block: 'nearest' }); permissions();
}
async function save(form, path, body, method = 'PUT') {
    if (busy) return; busy = true; permissions(); $('Notice').textContent = 'Guardando…';
    const operation = pending.get(form) || { path, method, body: { ...body, operacion_id: createUuid() } }; pending.set(form, operation);
    let saved = false;
    try {
        await api(operation.path, { method: operation.method, body: JSON.stringify(operation.body) });
        saved = true; pending.delete(form); await reload();
        $('Notice').textContent = catalog ? 'Guardado y registrado en el historial.' : 'Guardado. Actualiza el panel para recuperar la consulta.';
    } catch (error) {
        if (error.status && error.status < 500) pending.delete(form);
        $('Notice').textContent = saved ? 'Guardado. No se pudo actualizar la consulta; pulsa Actualizar.' : error.message;
    } finally { busy = false; permissions(); if (warehousesPending) { warehousesPending = false; void reload(); } }
}
function describe(d) {
    if (!d) return '<p>Sin registro anterior.</p>';
    const labels = { codigo: 'Código', nombre: 'Nombre', activo: 'Estado', folio: 'Folio', almacen: 'Almacén', tipo_pallet_id: 'Tipo de pallet', largo_m: 'Largo (m)', ancho_m: 'Ancho (m)', altura_total_m: 'Altura total (m)', altura_util_m: 'Altura útil (m)', max_niveles: 'Niveles máximos', peso_maximo_kg: 'Peso máximo (kg)', peso_unitario_kg: 'Peso real unitario (kg)', bultos_fisicos: 'Unidades físicas', cantidad_referencia: 'Cantidad al medir', unidad_medida: 'Unidad de referencia', version: 'Versión', superficie_util_m2: 'Superficie útil (m²)', volumen_util_m3: 'Volumen útil (m³)' };
    const typeName = (id) => catalog?.tipos.find((t) => t.id === id)?.codigo || id;
    return '<ul>' + Object.entries(labels).filter(([key]) => d[key] !== undefined).map(([key, title]) => `<li>${esc(title)}: ${esc(key === 'activo' ? Number(d[key]) ? 'Activo' : 'Inactivo' : key === 'tipo_pallet_id' ? typeName(d[key]) : d[key] ?? 'Sin dato')}</li>`).join('')
        + (d.zonas || []).map((z) => `<li>Zona ${esc(z.nombre)}: ${qty(z.largo_m)} × ${qty(z.ancho_m)} × ${qty(z.altura_util_m)} m; origen ${qty(z.x_m)}, ${qty(z.y_m)}; ${z.modo === 'rack' ? `${qty(z.limite_pallets)} huecos de rack (${qty(z.hueco_largo_m)} × ${qty(z.hueco_ancho_m)} × ${qty(z.hueco_altura_m)} m)` : `hasta ${qty(z.max_niveles)} niveles apilados; tope ${z.limite_pallets === null ? 'sin definir' : qty(z.limite_pallets)}`}.
        Giro: ${z.permite_giro ? 'sí' : 'no'}; peso máximo ${z.peso_maximo_kg === null ? 'sin definir' : qty(z.peso_maximo_kg) + ' kg'}; tipos: ${esc(z.tipo_pallet_ids.map(typeName).join(', '))}.</li>`).join('') + '</ul>';
}
async function loadHistory(page = 1) {
    const sequence = ++historySeq; $('HistoryRows').textContent = 'Consultando historial…'; $('HistoryPages').replaceChildren();
    try {
        const body = await api(`/historial?${new URLSearchParams({ ...historyFilter, page })}`); if (sequence !== historySeq) return;
        $('HistoryRows').innerHTML = body.data.map((e) => `<details><summary>${esc(date(e.ocurrido_at))} · ${esc(e.usuario || `Usuario #${e.user_id}`)} · ${esc(e.entidad)} · ${esc(e.resultado.folio || e.resultado.codigo || catalog?.almacenes.find((a) => a.id === e.registro_id)?.nombre || 'Configuración')}</summary><div class="capacity-history-comparison"><div><h3>Antes</h3>${describe(e.anterior)}</div><div><h3>Después</h3>${describe(e.resultado)}</div></div></details>`).join('') || '<p>Sin cambios registrados.</p>';
        $('HistoryPages').innerHTML = pages(body.meta);
    } catch (error) { if (sequence === historySeq) $('HistoryRows').textContent = error.message; }
}
$('Reload').addEventListener('click', () => { if (!busy) void reload(); });
$('Warehouse').addEventListener('change', () => { if (!busy) void selectWarehouse(); });
$('NewType').addEventListener('click', () => editType());
$('TypeList').addEventListener('click', (event) => { const button = event.target.closest('[data-edit-type]'); if (button) editType(button.dataset.editType); });
$('AddZone').addEventListener('click', () => { if (!busy && catalog?.permisos.configurar) { pending.delete($('WarehouseForm')); addZone(); } });
$('Zones').addEventListener('click', (event) => { if (!busy && catalog?.permisos.configurar && event.target.closest('[data-remove-zone]')) { event.target.closest('.capacity-zone').remove(); pending.delete($('WarehouseForm')); } });
$('Zones').addEventListener('change', (event) => { if (event.target.matches('[data-zone-field="modo"]')) rackFields(event.target.closest('.capacity-zone')); });
$('TypeForm').addEventListener('submit', (event) => { event.preventDefault(); if (catalog?.permisos.configurar) void save(event.target, type ? `/tipos-pallet/${type.id}` : '/tipos-pallet', { ...fields(event.target), version: Number(type?.version || 0) }, type ? 'PUT' : 'POST'); });
$('WarehouseForm').addEventListener('submit', (event) => {
    event.preventDefault(); const a = warehouse(); if (!a || !catalog?.permisos.configurar) return;
    const data = Object.fromEntries(['largo_m', 'ancho_m', 'altura_util_m'].map((key) => [key, Number(event.target.elements[key].value)]));
    void save(event.target, `/almacenes/${a.id}`, { ...data, version: Number(a.configuracion?.version || 0), zonas: zoneData() });
});
$('MeasurementForm').addEventListener('submit', (event) => { event.preventDefault(); if (measurement && catalog?.permisos.medir) void save(event.target, `/cargas/${measurement.saldo_id}`, { ...fields(event.target), version: Number(measurement.version || 0), saldo_version: Number(measurement.saldo_version_actual) }); });
$('CancelMeasurement').addEventListener('click', () => { if (!busy) { measurement = null; $('MeasurementForm').hidden = true; } });
for (const form of [$('SimulationForm'), $('MeasurementForm')]) form.elements.tipo_pallet_id.addEventListener('change', () => preset(form));
document.querySelectorAll('#materialCapacityApp form').forEach((form) => form.addEventListener('input', () => {
    pending.delete(form);
    if (form === $('SimulationForm')) { ++simulationSeq; $('SimulationResult').replaceChildren(); }
}));
$('SimulationForm').addEventListener('submit', async (event) => {
    event.preventDefault(); const a = warehouse(); if (busy || !a) return;
    const sequence = ++simulationSeq; const params = new URLSearchParams(Object.entries(fields(event.target)).filter(([, v]) => v !== null));
    $('SimulationResult').textContent = 'Simulando…';
    try {
        const { data } = await api(`/almacenes/${a.id}/simular?${params}`); if (sequence !== simulationSeq) return;
        $('SimulationResult').innerHTML = `<p><strong>${qty(data.pallets_referencia)} pallets de referencia con restricciones verificadas.</strong></p><p>${esc(data.aviso)}</p><p>Las zonas pendientes de verificar peso no se suman al total.</p><div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Zona</th><th>Superficie / volumen útiles</th><th>Puestos a piso / huecos de rack</th><th>Apilado por puesto</th><th>Pallets de referencia</th><th>Evaluación</th></tr></thead><tbody>` + data.zonas.map((z) => `<tr><td>${esc(z.nombre)} · ${z.modo === 'rack' ? 'Rack' : 'Piso'}</td><td>${qty(z.superficie_util_m2)} m² / ${qty(z.volumen_util_m3)} m³</td><td>${qty(z.puestos)}</td><td>${qty(z.niveles)}</td><td>${qty(z.pallets_referencia)}</td><td>${esc(({ referencia: 'Cabe según referencia', pendiente_peso: 'Verificar peso', incompatible: 'Incompatible' })[z.estado])}${z.motivos.length ? ' · ' + esc(z.motivos.join(' ')) : ''}</td></tr>`).join('') + '</tbody></table></div>';
    } catch (error) { if (sequence === simulationSeq) $('SimulationResult').textContent = error.message; }
});
$('SearchForm').addEventListener('submit', (event) => { event.preventDefault(); if (!busy) void loadStocks(); });
$('SearchForm').elements.busqueda.addEventListener('input', () => { ++stockSeq; rows = []; measurement = null; $('StockRows').replaceChildren(); $('StockPages').replaceChildren(); $('MeasurementForm').hidden = true; $('StockNotice').textContent = 'Pulsa Buscar cargas para aplicar el filtro.'; });
$('StockRows').addEventListener('click', (event) => {
    if (busy) return; const button = event.target.closest('button'); if (!button) return;
    if (button.dataset.measure !== undefined) editMeasurement(Number(button.dataset.measure));
    if (button.dataset.simulate !== undefined) { const row = rows[Number(button.dataset.simulate)]; if (row?.medidas_vigentes) { typesOptions($('SimulationForm').elements.tipo_pallet_id, row.tipo_pallet_id); fill($('SimulationForm'), row); ++simulationSeq; $('SimulationResult').replaceChildren(); $('SimulationForm').scrollIntoView({ block: 'nearest' }); } }
});
$('StockPages').addEventListener('click', (event) => { const button = event.target.closest('[data-page]'); if (!busy && button && !button.disabled) void loadStocks(Number(button.dataset.page)); });
$('HistoryPages').addEventListener('click', (event) => { const button = event.target.closest('[data-page]'); if (button && !button.disabled) void loadHistory(Number(button.dataset.page)); });
$('History').addEventListener('toggle', () => { if ($('History').open) void loadHistory(); });
$('AllHistory').addEventListener('click', () => { historyFilter = {}; $('HistoryScope').textContent = 'Todos los cambios de capacidad y mediciones.'; void loadHistory(); });
document.getElementById('materialCapacityApp').addEventListener('click', (event) => {
    const button = event.target.closest('[data-history-kind]'); if (!button || busy) return;
    historyFilter = { entidad: button.dataset.historyKind, registro_id: button.dataset.historyId };
    $('HistoryScope').textContent = 'Cambios del registro seleccionado.'; const open = $('History').open; $('History').open = true;
    if (open) void loadHistory(); $('History').scrollIntoView({ block: 'nearest' });
});
permissions();
window.addEventListener('estiba:material-warehouses-updated', () => { if (busy) warehousesPending = true; else void reload(); });
if (localStorage.getItem('estiba_wms_office_token')) void reload();
else $('Notice').textContent = 'Ingresa desde Materiales para consultar la capacidad de bodegas.';
