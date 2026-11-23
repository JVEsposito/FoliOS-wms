import './office-navigation';
import { officeCode, officeStatus } from './shared/folios-display.js';

const $ = (id) => document.getElementById(id);
const levelsMode = $('materialReplenishmentApp').dataset.levelsMode === 'true';
const token = localStorage.getItem('estiba_wms_office_token');
const esc = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const numberFormat = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 });
const qty = (n) => n == null ? '—' : numberFormat.format(n);
const states = {
    quiebre: ['Quiebre', 'bloqueado'],
    bajo_minimo: ['Bajo mínimo', 'atencion'],
    reponer: ['Reponer', 'atencion'],
    normal: ['Normal', 'listo'],
    sobre_maximo: ['Sobre máximo', 'atencion'],
    sin_niveles: ['Sin niveles', 'pendiente'],
};
function status(value) {
    const [label, variant] = Object.hasOwn(states, value) ? states[value] : [value ?? 'Sin estado', 'pendiente'];
    return officeStatus(label, variant, esc);
}
const columns = [
    ['item', 'Ítem'], ['codigo', 'Código'], ['estado', 'Estado'], ['disponible', 'Disponible en bodega'],
    ...(levelsMode ? [] : [['cantidad_sugerida', 'Cantidad sugerida']]),
    ['unidad', 'Unidad'], ['stock_minimo', 'Mínimo'], ['punto_reorden', 'Reorden'], ['stock_maximo', 'Máximo'],
    ['cliente', 'Cliente'], ['categoria', 'Categoría'], ['reservado', 'Reservado'],
    ['bloqueado_vencido', 'Bloqueado / vencido'], ['total_empresa', 'Total empresa'],
    ['consumo_periodo', 'Consumo del período'], ['consumo_diario', 'Consumo diario'], ['dias_cobertura', 'Días de cobertura'],
    ...(levelsMode ? [['cantidad_sugerida', 'Cantidad sugerida']] : []),
];
const text = new Set(['cliente', 'categoria', 'unidad']);
let selected = null;
let loadVersion = 0;
let detailVersion = 0;

function renderCell(row, key) {
    const value = row[key];
    switch (key) {
        case 'item': return `<button type="button" class="materials-bandeja-item" data-item="${esc(row.id)}">${esc(value)}</button>`;
        case 'codigo': return `<span class="folios-code">${officeCode(value, esc)}</span>`;
        case 'estado': return status(value);
        case 'disponible':
        case 'cantidad_sugerida': return `<strong class="materials-bandeja-qty">${qty(value)}</strong>`;
        case 'dias_cobertura': return value === null ? 'Sin consumo' : qty(value);
        default: return text.has(key) ? esc(value) : qty(value);
    }
}

function levelChanges(c) {
    const before = typeof c.anteriores === 'string' ? JSON.parse(c.anteriores) : c.anteriores;
    const after = typeof c.nuevos === 'string' ? JSON.parse(c.nuevos) : c.nuevos;
    return [['stock_minimo', 'Mínimo'], ['punto_reorden', 'Reorden'], ['stock_maximo', 'Máximo']].map(([key, name]) => `${name}: ${qty(before[key])} → ${qty(after[key])}`).join(' · ');
}
function query() { return new URLSearchParams([...new FormData($('filters'))].filter(([, v]) => v)); }
async function api(path, options = {}) {
    const r = await fetch(path, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, ...options });
    const body = await r.json();
    if (!r.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo consultar la reposición.');
    return body;
}
$('columns').innerHTML = '<tr>' + columns.map(([, name]) => `<th scope="col">${esc(name)}</th>`).join('') + '</tr>';

async function load() {
    const version = ++loadVersion;
    $('replenishmentList').setAttribute('aria-busy', 'true');
    $('notice').textContent = '';
    $('summary').textContent = 'Consultando stock…';
    $('reload').disabled = true;
    $('excel').disabled = true;
    $('rows').replaceChildren();
    try {
        const body = await api(`${levelsMode ? '/api/materiales/items/niveles' : '/api/materiales/reposicion'}?${query()}`);
        if (version !== loadVersion) return;
        for (const [field, rows, label] of [['cliente_id', body.catalogos.clientes, 'nombre'], ['categoria', body.catalogos.categorias, null]]) {
            const select = $('filters').elements[field];
            const previous = select.value;
            select.innerHTML = `<option value="">${field === 'categoria' ? 'Todas' : 'Todos'}</option>` +
                rows.map((row) => `<option value="${esc(label ? row.id : row)}">${esc(label ? row[label] : row)}</option>`).join('');
            select.value = previous;
        }
        $('replenishmentOut').textContent = qty(body.indicador.quiebre);
        $('replenishmentLow').textContent = qty(body.indicador.bajo_minimo);
        $('summary').textContent = `${qty(body.data.length)} ${body.data.length === 1 ? 'ítem' : 'ítems'} · Consumo de ${qty(body.dias)} días`;
        $('rows').innerHTML = body.data.map((row) => '<tr>' + columns.map(([key]) => `<td>${renderCell(row, key)}</td>`).join('') + '</tr>').join('')
            || `<tr><td colspan="${columns.length}"><span class="folios-bandeja__estado">Sin ítems para estos filtros.</span></td></tr>`;
        $('excel').disabled = false;
        window.dispatchEvent(new Event('materiales:reposicion-actualizada'));
        if (selected) void detail(selected);
    } catch (error) {
        if (version !== loadVersion) return;
        $('summary').textContent = 'Consulta sin resultados';
        $('notice').textContent = error.message;
    } finally {
        if (version === loadVersion) {
            $('replenishmentList').setAttribute('aria-busy', 'false');
            $('reload').disabled = false;
        }
    }
}

async function detail(id, page = 1) {
    const changedItem = selected !== id;
    selected = id;
    const version = ++detailVersion;
    $('detail').hidden = false;
    $('detail').setAttribute('aria-busy', 'true');
    $('detailNotice').textContent = '';
    $('itemSummary').textContent = 'Consultando detalle…';
    $('replenishmentHistory').hidden = true;
    if (changedItem) $('itemHeading').textContent = '';
    if (levelsMode) {
        $('levelsForm').hidden = true;
        $('levelsError').textContent = '';
    }
    try {
        const params = new URLSearchParams({ page });
        const days = $('filters').elements.dias.value;
        if (days) params.set('dias', days);
        const itemId = encodeURIComponent(id);
        const endpoint = levelsMode ? `/api/materiales/items/${itemId}/niveles` : `/api/materiales/reposicion/items/${itemId}`;
        const d = (await api(`${endpoint}?${params}`)).data;
        if (version !== detailVersion) return;
        if (levelsMode) {
            for (const key of ['stock_minimo', 'punto_reorden', 'stock_maximo']) $('levelsForm').elements[key].value = d.item[key] ?? '';
            $('levelsUnit').textContent = `Todos los niveles se expresan en ${d.item.unidad}. Dejar vacío elimina ese nivel. Mínimo ≤ reorden ≤ máximo.`;
            $('levelsForm').hidden = false;
        }
        $('itemHeading').textContent = `${d.item.codigo} · ${d.item.item}`;
        const coverage = d.item.dias_cobertura === null ? 'sin consumo' : `${qty(d.item.dias_cobertura)} días`;
        $('itemSummary').innerHTML = `${status(d.item.estado)} · Disponible ${qty(d.item.disponible)} ${esc(d.item.unidad)} · Cobertura ${coverage}`;
        $('weeks').innerHTML = d.semanas.map((w) => `<p>Semana del ${esc(w.semana)}: ${qty(w.consumo)} ${esc(d.item.unidad)}</p>`).join('') || '<p>Sin consumo.</p>';
        $('movements').innerHTML = d.movimientos.data.map((m) => `<tr><td>${esc(m.ocurrido_at)}</td><td><span class="folios-code">${officeCode(m.folio, esc)}</span></td><td>${esc(m.tipo.replaceAll('_', ' '))}</td><td>${qty(m.cantidad)} ${esc(d.item.unidad)}</td><td>${esc(m.motivo)}</td></tr>`).join('') || '<tr><td colspan="5">Sin movimientos de consumo.</td></tr>';
        const { current_page: current, last_page: last } = d.movimientos;
        $('pages').innerHTML = `${current > 1 ? `<button class="secondary-button" type="button" data-page="${current - 1}">Anterior</button>` : ''} Página ${current} de ${last} ${current < last ? `<button class="secondary-button" type="button" data-page="${current + 1}">Siguiente</button>` : ''}`;
        $('history').innerHTML = d.historial_niveles.map((c) => `<p>${esc(c.ocurrido_at)} · ${esc(c.responsable || 'Sistema')} · ${esc(levelChanges(c))}</p>`).join('') || '<p>Sin cambios registrados.</p>';
        $('replenishmentHistory').hidden = false;
    } catch (error) {
        if (version !== detailVersion) return;
        $('itemSummary').textContent = '';
        $('detailNotice').textContent = error.message;
    } finally {
        if (version === detailVersion) $('detail').setAttribute('aria-busy', 'false');
    }
}
$('filters').addEventListener('change', () => void load());
$('reload').addEventListener('click', () => void load());
$('rows').addEventListener('click', (e) => {
    const id = e.target.closest('[data-item]')?.dataset.item;
    if (!id) return;
    void detail(id);
    $('detail').focus();
});
$('pages').addEventListener('click', (e) => {
    if (!e.target.dataset.page) return;
    void detail(selected, Number(e.target.dataset.page));
    $('detail').focus();
});
$('excel').addEventListener('click', async () => {
    $('excel').disabled = true;
    try { const r = await fetch(`/api/materiales/reposicion/excel?${query()}`, { headers: { Authorization: `Bearer ${token}` } }); if (!r.ok) throw new Error('No se pudo exportar.'); const url = URL.createObjectURL(await r.blob()); const a = document.createElement('a'); a.href = url; a.download = 'reposicion-materiales.xlsx'; a.click(); URL.revokeObjectURL(url); }
    catch (e) { $('notice').textContent = e.message; } finally { $('excel').disabled = false; }
});
if (levelsMode) $('levelsForm').addEventListener('submit', async (e) => {
    e.preventDefault(); const button = e.currentTarget.querySelector('button'); button.disabled = true; $('levelsError').textContent = '';
    const data = Object.fromEntries([...new FormData(e.currentTarget)].map(([key, value]) => [key, value === '' ? null : value]));
    try { await api(`/api/materiales/items/${encodeURIComponent(selected)}/niveles`, { method: 'PUT', body: JSON.stringify(data) }); await load(); }
    catch (error) { $('levelsError').textContent = error.message; } finally { button.disabled = false; }
});
if (!token) window.location.replace('/oficina/materiales'); else void load();
