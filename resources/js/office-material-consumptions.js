import './office-navigation';

const $ = (id) => document.getElementById(id);
const escape = (v) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => v === null || v === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(v);
const label = (v) => ({ pendiente_cuadratura: 'Pendiente de cuadratura', sin_estandar: 'Sin estándar', fuera_tolerancia: 'Fuera de tolerancia', dentro_tolerancia: 'Dentro de tolerancia' })[v] || String(v || 'Sin declarar').replaceAll('_', ' ');
const when = (v) => v ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(v)) : '—';
const token = localStorage.getItem('estiba_wms_office_token');
let page = 1; let lastPage = 1; let busy = false;
const initialOrder = new URLSearchParams(location.search).get('q');
if (initialOrder) $('consumptionFilters').elements.q.value = initialOrder;
function query() { return new URLSearchParams([...new FormData($('consumptionFilters'))].filter(([, v]) => v !== '')); }
async function api(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible consultar los consumos.');
    return data;
}
function table(rows) {
    return `<div class="custody-table-wrap"><table class="custody-table"><thead><tr><th>Ítem / unidad</th><th>Estándar planificado</th><th>Estándar salida real</th><th>Retirado</th><th>Devuelto / reversado</th><th>Compartido</th><th>Neto</th><th>Merma declarada</th><th>Utilizado</th><th>Desviación utilizado</th><th>Desviación neta<br>incluye merma</th><th>Evaluación de utilizado</th></tr></thead><tbody>${rows.map(r => `<tr><td>${escape(r.codigo)} · ${escape(r.nombre)}<br>${escape(r.unidad)}</td><td>${qty(r.estandar_planificado)}</td><td>${qty(r.esperado)}</td><td>${qty(r.retirado)}</td><td>${qty(r.devuelto)}</td><td>${qty(r.compartido)}</td><td>${qty(r.consumo_neto)}</td><td>${qty(r.merma_declarada)}</td><td>${qty(r.utilizado)}</td><td>${qty(r.desviacion)}<br>${qty(r.desviacion_pct)} %</td><td>${qty(r.desviacion_neta)}<br>${qty(r.desviacion_neta_pct)} %</td><td>${escape(label(r.estado))}</td></tr>`).join('') || '<tr><td colspan="12">Sin consumos de componentes.</td></tr>'}</tbody></table></div>`;
}
function card(o) {
    return `<article class="custody-card materials-consumption-order"><h2>${escape(o.referencia_ot || o.codigo)} · ${escape(o.cliente)}</h2><p>${escape(o.temporada)} · ${escape(o.fecha_operacional)} · ${escape(label(o.estado))} · ${escape(o.bodega)}</p><p>Proceso: ${escape(o.proceso || 'Sin declarar')} · Línea: ${escape(o.linea || 'Sin declarar')} · Turno: ${escape(o.turno || 'Sin declarar')}</p><p>Operador físico: ${escape(o.operador || 'Sin declarar')} · Orden registrada por: ${escape(o.creada_por || 'Sin identificar')}</p><p>Salida real: ${qty(o.salida_real)} ${escape(o.unidad_salida)}${o.merma_produccion !== null ? ` · Merma de producción: ${qty(o.merma_produccion)} ${escape(o.unidad_salida)}` : ''}</p>${table(o.componentes)}<button class="secondary-button" data-records="${escape(o.id)}" type="button">Ver movimientos y responsables</button><div data-record-list="${escape(o.id)}" aria-live="polite"></div></article>`;
}
async function run(work) {
    if (busy) return;
    busy = true; $('consumptionNotice').textContent = '';
    document.querySelectorAll('#consumptionApp button').forEach(b => { b.disabled = true; });
    try { await work(); } catch (e) { $('consumptionNotice').textContent = e.message; }
    finally { busy = false; document.querySelectorAll('#consumptionApp button').forEach(b => { b.disabled = false; }); $('consumptionPrevious').disabled = page <= 1; $('consumptionNext').disabled = page >= lastPage; }
}
function options(name, rows, blank) {
    const field = $('consumptionFilters').elements[name]; const selected = field.value;
    field.innerHTML = `<option value="">${blank}</option>` + rows.map(r => `<option value="${escape(r.id)}">${escape(r.nombre)}</option>`).join(''); field.value = selected;
}
async function load() {
    const q = query(); q.set('page', page);
    const data = await api(`/api/materiales/transformaciones/consumos?${q}`);
    options('temporada_id', data.temporadas, 'Temporada activa'); options('cliente_id', data.clientes, 'Todos'); options('almacen_id', data.bodegas, 'Todas');
    page = data.meta.page; lastPage = data.meta.last_page;
    $('consumptionTotal').textContent = `${data.meta.total} órdenes encontradas. Cantidades separadas por ítem y unidad.`;
    $('consumptionPage').textContent = `${page} / ${lastPage}`;
    $('consumptionOrders').innerHTML = data.data.map(card).join('') || '<p class="custody-card">No hay órdenes para los filtros elegidos.</p>';
}
$('consumptionFilters').addEventListener('submit', e => { e.preventDefault(); void run(async () => { page = 1; await load(); }); });
$('reloadConsumptions').addEventListener('click', () => void run(load));
$('consumptionPrevious').addEventListener('click', () => void run(async () => { page--; await load(); }));
$('consumptionNext').addEventListener('click', () => void run(async () => { page++; await load(); }));
$('consumptionOrders').addEventListener('click', e => {
    const button = e.target.closest('[data-records]'); if (!button) return;
    void run(async () => {
        const { data } = await api(`/api/materiales/transformaciones/ordenes/${encodeURIComponent(button.dataset.records)}/consumos`);
        const target = [...document.querySelectorAll('[data-record-list]')].find(el => el.dataset.recordList === data.id);
        if (!target) return;
        target.innerHTML = '<h3>Movimientos de esta orden</h3>' + (data.registros.map(r => `<p>${escape(when(r.fecha_registro))} · ${escape(label(r.tipo))} · ${qty(r.cantidad)} ${escape(r.unidad)} · Operador: ${escape(r.operador || 'Sin declarar')} · Registrado por: ${escape(r.registrado_por || 'Sin identificar')}<br>${escape(r.motivo)}</p>`).join('') || '<p>Sin movimientos registrados.</p>');
    });
});
$('exportConsumptions').addEventListener('click', () => void run(async () => {
    const response = await fetch(`/api/materiales/transformaciones/consumos/excel?${query()}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    if (!response.ok) { const data = await response.json().catch(() => ({})); throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible exportar.'); }
    const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = 'consumos-por-ot.xlsx'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}));
if (!token) location.replace('/oficina/materiales'); else void run(load);
