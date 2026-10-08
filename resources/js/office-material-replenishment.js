import './office-navigation';
const $ = (id) => document.getElementById(id);
const levelsMode = $('materialReplenishmentApp').dataset.levelsMode === 'true';
const token = localStorage.getItem('estiba_wms_office_token');
const esc = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (n) => n === null || n === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(n);
const state = (s) => ({ quiebre: 'Quiebre', bajo_minimo: 'Bajo mínimo', reponer: 'Reponer', normal: 'Normal', sobre_maximo: 'Sobre máximo', sin_niveles: 'Sin niveles' })[s] || s;
const columns = [['cliente', 'Cliente'], ['codigo', 'Código'], ['item', 'Ítem'], ['categoria', 'Categoría'], ['unidad', 'Unidad'], ['disponible', 'Disponible en bodega'], ['reservado', 'Reservado'], ['bloqueado_vencido', 'Bloqueado / vencido'], ['total_empresa', 'Total empresa'], ['consumo_periodo', 'Consumo del período'], ['consumo_diario', 'Consumo diario'], ['dias_cobertura', 'Días de cobertura'], ['stock_minimo', 'Mínimo'], ['punto_reorden', 'Reorden'], ['stock_maximo', 'Máximo'], ['cantidad_sugerida', 'Cantidad sugerida'], ['estado', 'Estado']];
const text = new Set(['cliente', 'codigo', 'item', 'categoria', 'unidad', 'estado']);
let selected = null; let loadVersion = 0; let detailVersion = 0;
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
async function load() {
    const version = ++loadVersion; $('notice').textContent = ''; $('excel').disabled = true;
    try {
        const body = await api(`${levelsMode ? '/api/materiales/items/niveles' : '/api/materiales/reposicion'}?${query()}`); if (version !== loadVersion) return;
        for (const [field, rows, label] of [['cliente_id', body.catalogos.clientes, 'nombre'], ['categoria', body.catalogos.categorias, null]]) {
            const select = $('filters').elements[field]; const old = select.value;
            select.innerHTML = '<option value="">Todos</option>' + rows.map((r) => `<option value="${esc(label ? r.id : r)}">${esc(label ? r[label] : r)}</option>`).join(''); select.value = old;
        }
        $('summary').textContent = `${body.indicador.quiebre} ítems en quiebre · ${body.indicador.bajo_minimo} bajo mínimo · Consumo de ${body.dias} días · ${body.data.length} resultados`;
        $('columns').innerHTML = '<tr>' + columns.map(([, name]) => `<th>${esc(name)}</th>`).join('') + '</tr>';
        $('rows').innerHTML = body.data.map((r) => '<tr>' + columns.map(([key]) => `<td>${key === 'item' ? `<button type="button" data-item="${esc(r.id)}">${esc(r.item)}</button>` : key === 'estado' ? esc(state(r.estado)) : key === 'dias_cobertura' && r[key] === null ? 'Sin consumo' : text.has(key) ? esc(r[key]) : qty(r[key])}</td>`).join('') + '</tr>').join('') || `<tr><td colspan="${columns.length}">Sin ítems para estos filtros.</td></tr>`;
        window.dispatchEvent(new Event('materiales:reposicion-actualizada'));
        if (selected) await detail(selected);
    } catch (e) { if (version === loadVersion) $('notice').textContent = e.message; }
    finally { if (version === loadVersion) $('excel').disabled = false; }
}
async function detail(id, page = 1) {
    selected = id; const version = ++detailVersion;
    try {
        const params = new URLSearchParams({ page }); const days = $('filters').elements.dias.value; if (days) params.set('dias', days);
        const d = (await api(`${levelsMode ? '/api/materiales/items/' + encodeURIComponent(id) + '/niveles' : '/api/materiales/reposicion/items/' + encodeURIComponent(id)}?${params}`)).data; if (version !== detailVersion) return;
        if (levelsMode) {
            for (const key of ['stock_minimo', 'punto_reorden', 'stock_maximo']) $('levelsForm').elements[key].value = d.item[key] ?? '';
            $('levelsUnit').textContent = `Todos los niveles se expresan en ${d.item.unidad}. Dejar vacío elimina ese nivel. Mínimo ≤ reorden ≤ máximo.`;
        }
        $('detail').hidden = false; $('itemHeading').textContent = `${d.item.codigo} · ${d.item.item}`;
        $('itemSummary').textContent = `${state(d.item.estado)} · Disponible ${qty(d.item.disponible)} ${d.item.unidad} · Cobertura ${d.item.dias_cobertura === null ? 'sin consumo' : qty(d.item.dias_cobertura) + ' días'}`;
        $('weeks').innerHTML = d.semanas.map((w) => `<p>Semana del ${esc(w.semana)}: ${qty(w.consumo)} ${esc(d.item.unidad)}</p>`).join('') || '<p>Sin consumo.</p>';
        $('movements').innerHTML = d.movimientos.data.map((m) => `<tr><td>${esc(m.ocurrido_at)}</td><td>${esc(m.folio)}</td><td>${esc(m.tipo.replaceAll('_', ' '))}</td><td>${qty(m.cantidad)} ${esc(d.item.unidad)}</td><td>${esc(m.motivo)}</td></tr>`).join('') || '<tr><td colspan="5">Sin movimientos de consumo.</td></tr>';
        $('pages').innerHTML = `${d.movimientos.current_page > 1 ? `<button data-page="${d.movimientos.current_page - 1}">Anterior</button>` : ''} Página ${d.movimientos.current_page} de ${d.movimientos.last_page} ${d.movimientos.current_page < d.movimientos.last_page ? `<button data-page="${d.movimientos.current_page + 1}">Siguiente</button>` : ''}`;
        $('history').innerHTML = d.historial_niveles.map((c) => `<p>${esc(c.ocurrido_at)} · ${esc(c.responsable || 'Sistema')} · ${esc(levelChanges(c))}</p>`).join('') || '<p>Sin cambios registrados.</p>';
    } catch (e) { if (version === detailVersion) $('notice').textContent = e.message; }
}
$('filters').addEventListener('change', () => void load());
$('reload').addEventListener('click', () => void load());
$('rows').addEventListener('click', (e) => { const id = e.target.closest('[data-item]')?.dataset.item; if (id) void detail(id); });
$('pages').addEventListener('click', (e) => { if (e.target.dataset.page) void detail(selected, Number(e.target.dataset.page)); });
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
