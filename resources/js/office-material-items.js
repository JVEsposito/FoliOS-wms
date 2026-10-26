import './office-navigation';
import { loadPrivateItemImages, releasePrivateItemImages, openItemPhotoPanel } from './shared/private-item-photos';
import { materialCatalogLabel } from './shared/material-catalog-label.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const token = () => localStorage.getItem('estiba_wms_office_token');
const form = $('itemFilters');
let rows = []; let clients = []; let page = 1; let pages = 1; let sequence = 0; let initial = true;

function params() {
    const query = new URLSearchParams([...new FormData(form)].filter(([, value]) => value));
    const client = clients.find((c) => c.id === query.get('cliente_id'));
    if (client?.es_maestro) {
        query.delete('cliente_id');
        query.set('cliente_global_id', client.id);
    }
    if (initial) query.set('temporada_actual', '1');
    return query;
}

function options(key, data, label, empty = 'Todos') {
    const select = form.elements[key]; const old = select.value;
    select.innerHTML = `<option value="">${empty}</option>` + data.map((d) => `<option value="${esc(label ? d.id : d)}">${esc(label ? label(d) : materialCatalogLabel(d))}</option>`).join('');
    select.value = old;
    if (!select.value) select.value = '';
    return old !== select.value;
}

async function load() {
    const generation = ++sequence; const auth = token(); $('itemNotice').textContent = '';
    try {
        const query = params(); query.set('page', page);
        const r = await fetch(`/api/materiales/items/catalogo?${query}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${auth}` } });
        const body = await r.json();
        if (!r.ok) throw new Error(body.message || 'No se pudo consultar el catálogo.');
        if (generation !== sequence || token() !== auth) return;
        options('temporada_id', body.catalogos.temporadas, (s) => s.codigo, 'Todas las temporadas');
        if (initial) {
            form.elements.temporada_id.value = body.temporada_seleccionada_id || '';
            initial = false;
        }
        clients = body.catalogos.clientes_maestros || body.catalogos.clientes;
        const clientReset = options('cliente_id', clients, (c) => `${c.codigo ? `${c.codigo} · ` : ''}${c.nombre}${c.es_maestro === false ? ` (histórico${c.temporada_codigo ? ` · ${c.temporada_codigo}` : ''})` : ''}`);
        if (clientReset) form.elements.categoria.value = '';
        const categoryReset = options('categoria', body.catalogos.categorias);
        options('tipo_item_material_id', body.catalogos.tipos_item || [], (t) => materialCatalogLabel(t.nombre));
        if (clientReset || categoryReset || page > body.paginas) {
            page = 1;
            return void load();
        }
        rows = body.data; pages = body.paginas;
        $('itemSummary').textContent = `${body.total} ítems · Página ${body.pagina} de ${pages}`;
        const season = body.catalogos.temporadas.find((s) => s.id === form.elements.temporada_id.value);
        $('itemScope').textContent = season ? `Temporada: ${season.codigo}${season.activa ? ' · activa' : ' · histórica'}` : 'Consulta histórica · Todas las temporadas. Cada fila indica su temporada.';
        $('itemPrevious').disabled = page <= 1; $('itemNext').disabled = page >= pages;
        releasePrivateItemImages($('itemRows'));
        $('itemRows').innerHTML = rows.map((i) => `<tr><td>${i.foto_principal ? `<img class="item-photo-thumb" alt="${esc(i.nombre)}" data-private-item-photo="${esc(i.foto_principal.miniatura_url)}">` : '—'}</td><td>${esc(i.cliente.temporada.codigo)}</td><td>${esc(i.cliente.nombre)}</td><td>${esc(i.codigo)}</td><td><strong>${esc(materialCatalogLabel(i.nombre))}</strong><br><button type="button" data-photos="${esc(i.id)}">Fotos (${i.cantidad_fotos ?? 0})</button></td><td>${esc(materialCatalogLabel(i.categoria))}</td><td>${esc(materialCatalogLabel(i.tipo_item?.nombre || i.categoria_operacional_etiqueta || 'Sin tipo'))}</td><td>${esc(materialCatalogLabel(i.unidad_medida))}</td><td>${i.activo ? 'Activo' : 'Inactivo'}</td><td>${[i.stock_minimo, i.punto_reorden, i.stock_maximo].map((n) => n ?? '—').join(' / ')}</td></tr>`).join('') || '<tr><td colspan="10">Sin ítems para estos filtros.</td></tr>';
        void loadPrivateItemImages($('itemRows'));
    } catch (e) { if (generation === sequence) $('itemNotice').textContent = e.message; }
}

form.addEventListener('change', (e) => {
    initial = false; page = 1;
    if (['temporada_id', 'cliente_id'].includes(e.target.name)) form.elements.categoria.value = '';
    void load();
});
$('itemPrevious').addEventListener('click', () => { if (page > 1) { page--; void load(); } });
$('itemNext').addEventListener('click', () => { if (page < pages) { page++; void load(); } });
$('itemReload').addEventListener('click', () => void load());
$('itemRows').addEventListener('click', (e) => {
    const id = e.target.closest('[data-photos]')?.dataset.photos;
    if (id) void openItemPhotoPanel(rows.find((i) => i.id === id), load);
});
document.querySelectorAll('[data-export-catalog]').forEach((button) => button.addEventListener('click', async () => {
    button.disabled = true;
    try {
        const r = await fetch(`/api/materiales/items/catalogo/exportar/${button.dataset.exportCatalog}?${params()}`, { headers: { Authorization: `Bearer ${token()}` } });
        if (!r.ok) throw new Error('No se pudo descargar el catálogo.');
        const url = URL.createObjectURL(await r.blob()); const a = document.createElement('a');
        a.href = url; a.download = `catalogo-items-materiales.${button.dataset.exportCatalog}`; a.click(); URL.revokeObjectURL(url);
    } catch (e) { $('itemNotice').textContent = e.message; } finally { button.disabled = false; }
}));
if (!token()) window.location.replace('/oficina/materiales'); else void load();
