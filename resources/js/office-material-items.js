import './office-navigation';
import { loadPrivateItemImages, releasePrivateItemImages, openItemPhotoPanel } from './shared/private-item-photos';
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const token = () => localStorage.getItem('estiba_wms_office_token');
let rows = []; let page = 1; let pages = 1; let sequence = 0;
const params = () => new URLSearchParams([...new FormData($('itemFilters'))].filter(([, v]) => v));
async function load() {
    const generation = ++sequence; const auth = token(); $('itemNotice').textContent = '';
    try {
        const query = params(); query.set('page', page);
        const r = await fetch(`/api/materiales/items/catalogo?${query}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${auth}` } }); const body = await r.json();
        if (!r.ok) throw new Error(body.message || 'No se pudo consultar el catálogo.');
        if (generation !== sequence || token() !== auth) return;
        for (const [key, data, label] of [['temporada_id', body.catalogos.temporadas, 'codigo'], ['cliente_id', body.catalogos.clientes, 'nombre'], ['categoria', body.catalogos.categorias, null]]) {
            const select = $('itemFilters').elements[key]; const old = select.value;
            const options = key === 'cliente_id' && $('itemFilters').elements.temporada_id.value ? data.filter((c) => c.temporada_material_id === $('itemFilters').elements.temporada_id.value) : data;
            select.innerHTML = '<option value="">Todos</option>' + options.map((d) => `<option value="${esc(label ? d.id : d)}">${esc(label ? d[label] : d)}</option>`).join(''); select.value = old;
        }
        rows = body.data; pages = body.paginas; $('itemSummary').textContent = `${body.total} ítems · Página ${body.pagina} de ${pages}`;
        $('itemPrevious').disabled = page <= 1; $('itemNext').disabled = page >= pages;
        releasePrivateItemImages($('itemRows'));
        $('itemRows').innerHTML = rows.map((i) => `<tr><td>${i.foto_principal ? `<img class="item-photo-thumb" alt="${esc(i.nombre)}" data-private-item-photo="${esc(i.foto_principal.miniatura_url)}">` : '—'}</td><td>${esc(i.cliente.temporada.codigo)}</td><td>${esc(i.cliente.nombre)}</td><td>${esc(i.codigo)}</td><td><strong>${esc(i.nombre)}</strong><br><button type="button" data-photos="${esc(i.id)}">Fotos (${i.cantidad_fotos ?? 0})</button></td><td>${esc(i.categoria)}</td><td>${esc(i.categoria_operacional_etiqueta || 'Sin tipo')}</td><td>${esc(i.unidad_medida)}</td><td>${i.activo ? 'Activo' : 'Inactivo'}</td><td>${[i.stock_minimo, i.punto_reorden, i.stock_maximo].map((n) => n ?? '—').join(' / ')}</td></tr>`).join('') || '<tr><td colspan="10">Sin ítems para estos filtros.</td></tr>';
        void loadPrivateItemImages($('itemRows'));
    } catch (e) { if (generation === sequence) $('itemNotice').textContent = e.message; }
}
$('itemFilters').addEventListener('change', (e) => { page = 1; if (e.target.name === 'temporada_id') $('itemFilters').elements.cliente_id.value = ''; if (['temporada_id', 'cliente_id'].includes(e.target.name)) $('itemFilters').elements.categoria.value = ''; void load(); });
$('itemPrevious').addEventListener('click', () => { if (page > 1) { page--; void load(); } });
$('itemNext').addEventListener('click', () => { if (page < pages) { page++; void load(); } });
$('itemReload').addEventListener('click', () => void load());
$('itemRows').addEventListener('click', (e) => { const id = e.target.closest('[data-photos]')?.dataset.photos; if (id) void openItemPhotoPanel(rows.find((i) => i.id === id), load); });
document.querySelectorAll('[data-export-catalog]').forEach((button) => button.addEventListener('click', async () => {
    button.disabled = true;
    try { const r = await fetch(`/api/materiales/items/catalogo/exportar/${button.dataset.exportCatalog}?${params()}`, { headers: { Authorization: `Bearer ${token()}` } }); if (!r.ok) throw new Error('No se pudo descargar el catálogo.'); const url = URL.createObjectURL(await r.blob()); const a = document.createElement('a'); a.href = url; a.download = `catalogo-items-materiales.${button.dataset.exportCatalog}`; a.click(); URL.revokeObjectURL(url); }
    catch (e) { $('itemNotice').textContent = e.message; } finally { button.disabled = false; }
}));
if (!token()) window.location.replace('/oficina/materiales'); else void load();
