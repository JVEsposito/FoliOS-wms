import './office-navigation.js';

const $ = (id) => document.getElementById(`supervision${id}`);
const form = $('Filters');
const esc = (s) => String(s ?? '—').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const qty = (v) => v === null || v === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 }).format(Number(v));
const label = (v) => ({ corregida_administrativamente: 'Corrección administrativa', en_conteo: 'En conteo', en_revision: 'En revisión' })[v] || String(v ?? '—').replaceAll('_', ' ');
const types = {
    inventario: ['transferencia_cliente_salida', 'transferencia_cliente_entrada', 'ingreso', 'ingreso_recepcion', 'anulacion_recepcion', 'despacho', 'transferencia_interna', 'consumo_centro_costo', 'ajuste_almacen', 'ajuste', 'devolucion', 'correccion_item_salida', 'correccion_item_entrada', 'consumo_transformacion', 'produccion_transformacion', 'merma_transformacion', 'reversa_transformacion', 'devolucion_transformacion'],
    almacenes: ['entrega', 'transferencia', 'devolucion', 'consumo', 'ajuste'],
    recepciones: ['creada', 'actualizada', 'confirmada', 'regularizada', 'corregida_administrativamente', 'anulada', 'eliminada'],
    diferencias: ['faltante', 'sobrante', 'diferencia_cantidad'],
};
const columns = {
    stock: [['folio', 'Folio'], ['cliente', 'Cliente'], ['item', 'Ítem'], ['almacen', 'Almacén'], ['unidad', 'Unidad'], ['actual', 'Existencia'], ['reservada', 'Reservada'], ['bloqueada', 'Bloqueada / vencida'], ['disponible', 'Disponible']],
    inventario: [['fecha', 'Fecha'], ['folio', 'Folio'], ['item', 'Ítem'], ['tipo', 'Movimiento'], ['cantidad', 'Cantidad'], ['unidad', 'Unidad'], ['usuario', 'Usuario']],
    almacenes: [['fecha', 'Fecha'], ['folio', 'Folio'], ['tipo', 'Movimiento'], ['origen', 'Origen'], ['destino', 'Destino'], ['cantidad', 'Cantidad'], ['unidad', 'Unidad'], ['usuario', 'Usuario']],
    recepciones: [['fecha', 'Fecha'], ['guia', 'Guía'], ['cliente', 'Cliente'], ['tipo', 'Evento'], ['usuario', 'Usuario'], ['motivo', 'Motivo']],
    diferencias: [['fecha', 'Fecha del conteo'], ['folio', 'Folio'], ['item', 'Ítem'], ['cantidad_esperada', 'Esperada'], ['cantidad_contada', 'Contada'], ['diferencia', 'Diferencia'], ['unidad', 'Unidad'], ['estado', 'Estado de toma'], ['usuario', 'Contado por']],
};
const fieldLabels = { fecha_despacho: 'Entrega física', numero_documento: 'Documento de entrega', fecha_documento: 'Emisión del documento de entrega', provisional: 'Entrega provisional', registro_retroactivo: 'Registro retroactivo', fecha_limite_regularizacion: 'Plazo documental', autorizado_at: 'Fecha de autorización', autorizado_por: 'Autorizó', motivo_autorizacion: 'Motivo de autorización', regularizado_at: 'Fecha de regularización', regularizado_por: 'Regularizó', motivo_regularizacion: 'Motivo de regularización', id: 'ID del registro', folio_id: 'ID del folio', folio: 'Folio', codigo: 'Código de ítem', item: 'Ítem', cliente: 'Cliente', almacen: 'Almacén', unidad: 'Unidad', actual: 'Existencia actual', reservada: 'Reservada', bloqueada: 'Bloqueada o vencida', disponible: 'Disponible', motivo_bloqueo: 'Motivo de bloqueo', fecha_vencimiento: 'Vencimiento', estado_operacional: 'Estado operativo', version: 'Versión del saldo', tipo: 'Tipo', cantidad: 'Cantidad registrada', fecha: 'Fecha y hora', user_id: 'ID del usuario', usuario: 'Usuario', motivo: 'Motivo', despacho_material_id: 'ID del despacho', retiro_material_id: 'ID del retiro', origen: 'Almacén de origen', destino: 'Almacén de destino', documento_relacionado: 'Documento relacionado', operacion_id: 'ID de operación', saldo_origen_anterior: 'Saldo de origen antes', saldo_origen_resultante: 'Saldo de origen después', saldo_destino_anterior: 'Saldo de destino antes', saldo_destino_resultante: 'Saldo de destino después', cantidad_anterior: 'Cantidad antes', cantidad_resultante: 'Cantidad después', recepcion_id: 'ID de recepción', guia: 'Guía', estado_actual: 'Estado actual de recepción', cantidad_esperada: 'Cantidad esperada al contar', cantidad_contada: 'Cantidad contada', diferencia: 'Diferencia', accion: 'Decisión registrada', movimiento_almacen_id: 'ID del ajuste aplicado', posicion_id: 'ID de posición', toma_id: 'ID de toma de inventario', estado: 'Estado de la toma', aprobada_at: 'Aprobación', aprobado_por: 'Aprobado por', motivo_anulacion: 'Motivo de anulación' };
const numeric = new Set(['actual', 'reservada', 'bloqueada', 'disponible', 'cantidad', 'cantidad_anterior', 'cantidad_resultante', 'saldo_origen_anterior', 'saldo_origen_resultante', 'saldo_destino_anterior', 'saldo_destino_resultante', 'cantidad_esperada', 'cantidad_contada', 'diferencia']);
let catalogs; let rows = []; let current; let version = 0; let detailVersion = 0; let zone = 'America/Santiago'; let requestController;

function date(value) {
    if (!value) return '—';
    const s = String(value).replace(' ', 'T');
    const d = new Date(/[zZ]|[+-]\d\d:\d\d$/.test(s) ? s : `${s}Z`);
    return Number.isNaN(d.getTime()) ? String(value) : new Intl.DateTimeFormat('es-CL', { timeZone: zone, dateStyle: 'short', timeStyle: 'short' }).format(d);
}
function value(key, v) {
    if (numeric.has(key)) return qty(v);
    if (['fecha', 'aprobada_at', 'fecha_despacho', 'fecha_limite_regularizacion', 'autorizado_at', 'regularizado_at'].includes(key)) return date(v);
    if (['tipo', 'estado', 'estado_actual', 'accion', 'estado_operacional'].includes(key)) return label(v);
    return v ?? '—';
}
function options(select, records, all = true) {
    select.innerHTML = (all ? '<option value="">Todos</option>' : '') + records.map((r) => `<option value="${esc(r.id)}">${esc(r.nombre)}</option>`).join('');
}
function clients() {
    const previous = form.elements.cliente_id.value;
    const records = catalogs.clientes.filter((c) => c.temporada_id === form.elements.temporada_id.value);
    options(form.elements.cliente_id, [...new Map(records.map((c) => [c.id, c])).values()]);
    if (records.some((c) => c.id === previous)) form.elements.cliente_id.value = previous;
}
function mode() {
    const vista = form.elements.vista.value;
    for (const [group, show] of [['almacen', ['stock', 'almacenes'].includes(vista)], ['periodo', vista !== 'stock'], ['actividad', vista !== 'stock'], ['estado', vista === 'diferencias']]) {
        document.querySelectorAll(`[data-filter="${group}"]`).forEach((node) => {
            node.hidden = !show; node.querySelectorAll('select,input').forEach((input) => { input.disabled = !show; });
        });
    }
    options(form.elements.tipo, (types[vista] || []).map((t) => ({ id: t, nombre: label(t) })));
    $('SearchLabel').textContent = vista === 'recepciones' ? 'Guía o ID de recepción' : 'Folio o ítem';
    form.elements.busqueda.placeholder = vista === 'recepciones' ? 'Número de guía o ID' : 'Código, nombre o folio';
}
async function api(path, signal) {
    const response = await fetch(path, { signal, cache: 'no-store', headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('estiba_wms_office_token') || ''}` } });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(response.status === 401 ? 'Tu sesión venció. Ingresa nuevamente desde Materiales.' : response.status === 403 ? 'Tu perfil no tiene permiso para consultar el inventario de Materiales.' : Object.values(body.errors || {}).flat()[0] || body.message || 'No se pudo consultar. Intenta actualizar.');
    return body;
}
function closeDetail() { ++detailVersion; $('Detail').hidden = true; $('Evidence').replaceChildren(); }
async function load(page = 1) {
    const myVersion = ++version; requestController?.abort(); requestController = new AbortController();
    closeDetail(); rows = []; current = null; $('Results').hidden = true; $('Notice').textContent = 'Consultando…';
    const params = new URLSearchParams([...new FormData(form)].filter(([, v]) => v)); params.set('page', String(page));
    if (!params.get('temporada_id')) { $('Notice').textContent = 'No hay temporadas disponibles para consultar.'; return; }
    try {
        const body = await api(`/api/materiales/supervision?${params}`, requestController.signal);
        if (myVersion !== version) return;
        current = body; rows = body.data; zone = body.zona_horaria;
        const vista = body.filtros.vista;
        const descriptions = {
            stock: 'Saldo actual por folio y almacén. Reservas y bloqueos pueden coincidir; no deben sumarse. Disponible = saldo sin reserva de folios sin bloqueo ni vencimiento. Para entregar también se exige una ubicación válida.',
            inventario: 'Registro de inventario. Puede contener el reflejo de una entrega de almacén; las dos vistas no se suman. La unidad corresponde al catálogo del ítem.',
            almacenes: 'Entregas, transferencias, devoluciones, consumos y ajustes entre almacenes. Una transferencia conserva la existencia de la empresa.',
            recepciones: 'Eventos de recepciones conservadas y registros de eliminación. El detalle de cada eliminación conserva los eventos anteriores disponibles; no se cuentan de nuevo en este listado.',
            diferencias: 'Solo resultados vigentes del último conteo de cada posición. Incluye tomas anuladas, identificadas por su estado. Faltantes y sobrantes se muestran por separado. Usuario = responsable del conteo.',
        };
        $('Scope').textContent = `${descriptions[vista]} ${vista === 'stock' ? '' : `Período: ${body.filtros.desde} al ${body.filtros.hasta}, inclusive. `}Horas en ${zone}. Consulta: ${date(body.consultado_at)}.`;
        const summary = body.resumen;
        $('Summary').innerHTML = vista === 'stock' ? (summary.unidades.map((s) => `<article><h3>${esc(s.unidad)}</h3><p>Existencia <strong>${qty(s.actual)}</strong></p><p>Reservada ${qty(s.reservada)} · Bloqueada / vencida ${qty(s.bloqueada)}</p><p>Disponible <strong>${qty(s.disponible)}</strong></p></article>`).join(''))
            : vista === 'diferencias' ? summary.unidades.map((s) => `<article><h3>${esc(s.unidad || 'Sin unidad identificada')}</h3><p>Faltante <strong>${qty(s.faltante)}</strong></p><p>Sobrante <strong>${qty(s.sobrante)}</strong></p></article>`).join('')
                : summary.tipos.map((t) => `<article><h3>${esc(label(t.tipo))}</h3><p><strong>${qty(t.registros)}</strong> registros</p></article>`).join('');
        $('Count').textContent = `${qty(body.meta.total)} registros${vista === 'stock' ? ` · ${qty(summary.folios)} folios distintos` : ''} · Indicadores de todos los resultados filtrados`;
        $('Caption').textContent = form.elements.vista.selectedOptions[0].textContent;
        $('Columns').innerHTML = '<tr>' + columns[vista].map(([, title]) => `<th scope="col">${esc(title)}</th>`).join('') + '<th scope="col">Respaldo</th></tr>';
        $('Rows').innerHTML = rows.map((row, index) => '<tr>' + columns[vista].map(([key]) => `<td>${esc(value(key, row[key]))}</td>`).join('') + `<td><button type="button" class="secondary-button" data-detail="${index}" aria-label="Ver detalle de ${esc(row.folio || row.guia || row.id)}">Ver detalle</button></td></tr>`).join('') || `<tr><td colspan="${columns[vista].length + 1}">Sin registros para estos filtros.</td></tr>`;
        $('Pages').innerHTML = `<button type="button" class="secondary-button" data-page="${body.meta.pagina - 1}" ${body.meta.pagina <= 1 ? 'disabled' : ''}>Anterior</button><span>Página ${body.meta.pagina} de ${body.meta.paginas}</span><button type="button" class="secondary-button" data-page="${body.meta.pagina + 1}" ${body.meta.pagina >= body.meta.paginas ? 'disabled' : ''}>Siguiente</button>`;
        $('Results').hidden = false; $('Notice').textContent = '';
    } catch (error) { if (myVersion === version && error.name !== 'AbortError') $('Notice').textContent = error.message; }
}
function registrationEvidence(info, title) {
    if (!info) return '';
    return `<section><h4>${esc(title)}</h4><p>Recepción física: ${esc(date(info.fecha_recepcion))} · Plazo: ${esc(date(info.fecha_limite))}</p><p>${info.provisional ? 'Provisional' : 'Documentación completa'}${info.retroactivo ? ' · Registro retroactivo' : ''} · ${esc(info.motivo || 'Sin motivo adicional')}</p>${info.autorizado_at ? `<p>Autorizó ${esc(info.autorizado_por?.name || 'Usuario auditado')} · ${esc(date(info.autorizado_at))}: ${esc(info.motivo_autorizacion)}</p>` : ''}</section>`;
}
function documentEvidence(info, title) {
    if (!info) return '';
    return `<section><h4>${esc(title)}</h4><p>Guía: ${esc(info.numero_guia_despacho || 'Pendiente')} · Emisión: ${esc(info.fecha_documento || 'Pendiente')}</p>${(info.detalles || []).map((line) => `<p>${esc(line.item?.nombre || line.id)}: ${line.cantidad_documental == null ? 'Pendiente' : esc(qty(line.cantidad_documental))} ${esc(line.unidad_medida || '')}</p>`).join('')}</section>`;
}
function eventHtml(e) {
    return `<article><h3>${esc(label(e.tipo))}</h3><p>${esc(date(e.fecha))} · ${esc(e.usuario || (e.user_id ? `Usuario #${e.user_id}` : 'Sin usuario'))}</p><p>${esc(e.motivo || 'Sin observación registrada')}</p>${e.estado_anterior || e.estado_resultante ? `<p>Estado: ${esc(e.estado_anterior)} → ${esc(e.estado_resultante)}</p>` : ''}${['anteriores', 'resultantes'].map((key) => e[`folios_${key}`]?.length ? `<p>Folios ${key} (hasta 100): ${esc(e[`folios_${key}`].join(', '))}</p>` : '').join('')}${e.folios_confirmados?.length ? `<p>Folios confirmados: ${e.folios_confirmados.length} de ${qty(e.folios_confirmados_total)}</p>` + e.folios_confirmados.map((f) => `<p>${esc(f.numero_folio)} · Cantidad registrada ${qty(f.cantidad)} · Ítem ${esc(f.item_material_id)}</p>`).join('') : ''}${registrationEvidence(e.registro, 'Ingreso autorizado')}${registrationEvidence(e.registro_anterior, 'Ingreso antes de editar')}${registrationEvidence(e.registro_resultante, 'Ingreso después de editar')}${documentEvidence(e.documento_anterior, 'Respaldo anterior')}${documentEvidence(e.documento_resultante, 'Respaldo regularizado')}${e.fuera_plazo ? '<p>Excepción fuera del plazo de cinco días corridos.</p>' : ''}</article>`;
}
async function detail(index) {
    const row = rows[index]; if (!row || !current) return;
    const myDetail = ++detailVersion;
    $('DetailFields').innerHTML = Object.entries(row).filter(([k]) => fieldLabels[k]).map(([k, v]) => `<dt>${esc(fieldLabels[k])}</dt><dd>${esc(value(k, v))}</dd>`).join('');
    $('DetailTitle').textContent = `Evidencia · ${row.folio || row.guia || row.id}`;
    $('DetailNotice').textContent = ''; $('Evidence').replaceChildren(); $('Detail').hidden = false; $('Detail').focus();
    if (current.filtros.vista !== 'recepciones') return;
    $('DetailNotice').textContent = 'Buscando evidencia conservada…';
    try {
        const { data } = await api(`/api/materiales/supervision/evidencia/${encodeURIComponent(row.fuente)}/${encodeURIComponent(row.id)}`);
        if (myDetail !== detailVersion) return;
        $('Evidence').innerHTML = data.evento ? eventHtml({ ...data.evento, usuario: row.usuario }) : `<p>Estado antes de eliminar: ${esc(data.estado_anterior)}. Se muestran ${data.eventos.length} de ${data.eventos_total} eventos conservados.</p>` + data.eventos.map(eventHtml).join('')
            + (data.detalles?.length ? `<h3>Detalle conservado al eliminar (${data.detalles.length} de ${data.detalles_total})</h3>` + data.detalles.map((d) => `<p>Ítem ${esc(d.item_material_id)} · Documental ${qty(d.cantidad_documental)} · Contada ${qty(d.cantidad_contada)} · Aceptada ${qty(d.cantidad_aceptada)} · Rechazada ${qty(d.cantidad_rechazada)} ${esc(d.unidad_medida)}</p>`).join('') : '');
        $('DetailNotice').textContent = '';
    } catch (error) { if (myDetail === detailVersion) $('DetailNotice').textContent = error.message; }
}
form.addEventListener('submit', (event) => { event.preventDefault(); void load(); });
form.addEventListener('change', (event) => {
    if (event.target.name === 'vista') mode();
    if (event.target.name === 'temporada_id') clients();
    ++version; requestController?.abort(); closeDetail(); rows = []; current = null; $('Results').hidden = true; $('Notice').textContent = 'Pulsa Consultar para aplicar los filtros.';
});
form.elements.busqueda.addEventListener('input', () => { ++version; requestController?.abort(); closeDetail(); $('Results').hidden = true; $('Notice').textContent = 'Pulsa Consultar para aplicar los filtros.'; });
$('Reload').addEventListener('click', () => { if (form.reportValidity()) void load(); });
$('Rows').addEventListener('click', (event) => { const button = event.target.closest('[data-detail]'); if (button) void detail(Number(button.dataset.detail)); });
$('Pages').addEventListener('click', (event) => { const button = event.target.closest('[data-page]'); if (button && !button.disabled) void load(Number(button.dataset.page)); });
$('Close').addEventListener('click', () => { closeDetail(); $('Rows').querySelector('button')?.focus(); });
async function start() {
    if (!localStorage.getItem('estiba_wms_office_token')) { $('Notice').textContent = 'Ingresa desde Materiales para consultar el panel.'; return; }
    try {
        catalogs = await api('/api/materiales/supervision/catalogos');
        options(form.elements.temporada_id, catalogs.temporadas, false);
        const active = catalogs.temporadas.find((s) => Boolean(Number(s.activa))); if (active) form.elements.temporada_id.value = active.id;
        options(form.elements.almacen_id, catalogs.almacenes); options(form.elements.user_id, catalogs.usuarios); clients();
        form.elements.desde.value = catalogs.hoy; form.elements.hasta.value = catalogs.hoy;
        mode(); await load();
    } catch (error) { $('Notice').textContent = error.message; }
}
void start();
