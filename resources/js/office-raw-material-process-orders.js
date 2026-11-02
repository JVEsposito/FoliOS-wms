import './office-navigation';
import { processOrderChanges } from './shared/process-order-changes';

const $ = (id) => document.getElementById(id);
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    catalogos: { lineas: [], turnos: [] },
    ordenes: [],
    detalle: null,
    editor: null,
    correccion: null,
    candidatos: [],
    busy: false,
};

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}
function escape(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
function can(capability) {
    return state.identity?.[capability] === true || state.identity?.capacidades?.[capability] === true;
}
function uuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const values = crypto.getRandomValues(new Uint8Array(16));
    values[6] = (values[6] & 15) | 64; values[8] = (values[8] & 63) | 128;
    const hex = [...values].map((item) => item.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
const number = (value, digits = 0) => value === null || value === undefined ? '—' : new Intl.NumberFormat('es-CL', { maximumFractionDigits: digits }).format(Number(value));
const time = (value) => value ? String(value).slice(11, 16) : '—';
const estados = { programada: 'Programada', en_proceso: 'En proceso', terminada: 'Terminada' };
const cierres = { completa: 'completa', cancelacion: 'por cancelación' };
const desfase = (minutos) => {
    if (minutos === null || minutos === undefined) return '—';
    if (Math.abs(minutos) < 1) return 'a tiempo';
    const texto = `${Math.floor(Math.abs(minutos) / 60) ? `${Math.floor(Math.abs(minutos) / 60)} h ` : ''}${Math.abs(minutos) % 60} min`;
    return minutos > 0 ? `+${texto}` : `−${texto}`;
};
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago' }).format(new Date());

async function api(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${state.token}`, ...(options.headers || {}) },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la operación.');
        error.status = response.status;
        throw error;
    }
    return data;
}

async function run(work, target = 'ordersNotice') {
    if (state.busy) return;
    state.busy = true;
    $(target).textContent = '';
    document.querySelectorAll('#ordersApp button').forEach((button) => { button.disabled = true; });
    try { await work(); } catch (error) { $(target).textContent = error.message; }
    finally {
        state.busy = false;
        document.querySelectorAll('#ordersApp button').forEach((button) => { button.disabled = false; });
    }
}

function lineaNombre(id) {
    return state.catalogos.lineas.find((linea) => linea.id === id)?.nombre ?? '—';
}

function renderFiltros() {
    const select = $('ordersFilters').elements.linea_proceso_id;
    const elegido = select.value;
    select.innerHTML = '<option value="">Todas</option>' + state.catalogos.lineas.map((linea) => `<option value="${escape(linea.id)}">${escape(linea.nombre)}</option>`).join('');
    select.value = elegido;
}

function renderLista() {
    if (!state.ordenes.length) {
        $('ordersList').innerHTML = '<p class="orders-muted">No hay órdenes para los filtros elegidos.</p>';
        return;
    }
    const porLinea = new Map();
    state.ordenes.forEach((orden) => {
        const clave = orden.linea?.nombre ?? 'Sin línea';
        porLinea.set(clave, [...(porLinea.get(clave) || []), orden]);
    });
    $('ordersList').innerHTML = [...porLinea.entries()].map(([linea, ordenes]) => `<section class="orders-line"><h2>${escape(linea)}</h2><div class="orders-cards">${ordenes.map(tarjeta).join('')}</div></section>`).join('');
}

function tarjeta(orden) {
    const avance = orden.progreso.bins_programados ? Math.round((orden.progreso.bins_entregados / orden.progreso.bins_programados) * 100) : 0;
    const estado = orden.estado === 'terminada' ? `Terminada ${cierres[orden.tipo_cierre] ?? ''}` : estados[orden.estado];
    return `<button class="order-card" type="button" data-order="${escape(orden.id)}">
        <div class="order-card__top"><strong>${escape(orden.codigo)} · v${escape(orden.numero_version_vigente)}</strong><span class="order-status">${escape(estado)}</span></div>
        <small>${escape(orden.fecha)} · Turno ${escape(orden.turno_inicio)} (${escape(orden.turno_pre_sif)}) · ${escape(time(orden.inicio_estimado))} a ${escape(time(orden.termino_estimado))}</small>
        <span>${escape(orden.clientes.join(' · ') || 'Sin lotes')}</span>
        <small>${number(orden.progreso.bins_entregados)} de ${number(orden.progreso.bins_programados)} bins entregados · ${number(orden.progreso.lotes)} lotes</small>
        <div class="order-progress" aria-hidden="true"><span style="width:${Math.min(100, avance)}%"></span></div>
    </button>`;
}

function renderDetalle() {
    const orden = state.detalle;
    const panel = $('orderDetail');
    if (!orden) { panel.hidden = true; return; }
    const vigente = orden.version_vigente;
    const abierta = orden.estado !== 'terminada';
    const filas = (vigente?.lotes || []).map((fila) => `<tr>
        <td class="orders-num">${escape(fila.secuencia)}</td><td>${escape(fila.numero_lote)}</td><td>${escape(fila.cliente?.nombre)}</td>
        <td>${escape(fila.variedad)}</td><td>${escape(fila.csg)}</td><td>${escape(fila.numero_proceso || '—')}</td>
        <td class="orders-num">${number(fila.bins_programados)}</td><td class="orders-num">${number(fila.bins_entregados)}</td><td class="orders-num">${number(fila.bins_pendientes)}</td>
        <td class="orders-num">${number(fila.kilos_programados, 1)}</td><td>${escape(fila.embalaje || '—')}</td><td class="orders-num">${number(fila.cajas_estimadas, 1)}</td>
        <td>${escape(fila.mercado_destino || '—')}</td><td>${escape(time(fila.inicio_estimado))} – ${escape(time(fila.termino_estimado))}</td>
        <td>${fila.primera_entrega_at ? `${escape(time(fila.primera_entrega_at))} – ${escape(time(fila.ultima_entrega_at))} · ${number(fila.viajes)} viaje${fila.viajes === 1 ? '' : 's'}` : '—'}</td>
        <td class="orders-num">${desfase(fila.desfase_minutos)}</td>
    </tr>`).join('');
    const versiones = orden.versiones.map((version) => `<span>v${escape(version.numero)} · ${escape(version.creada_por || '—')} · ${escape(new Date(version.creada_at).toLocaleString('es-CL'))}${version.origen === 'fuera_de_orden' ? ' · lote fuera de orden autorizado' : ''}${version.motivo_cambio ? ` · ${escape(version.motivo_cambio)}` : ''}</span>`).join('');
    const entregas = (orden.entregas || []).map((entrega) => `<tr>
        <td>${escape(entrega.numero_lote)}</td><td>${escape(new Date(entrega.entregado_at).toLocaleString('es-CL'))}</td>
        <td class="orders-num">${number(entrega.cantidad_envases)}</td><td>${escape(entrega.embalaje || 'Sin embalaje registrado')}</td>
        <td class="orders-num">${number(entrega.kilos_por_caja, 3)}</td><td>${escape(entrega.entregado_por || '—')}${entrega.anulado ? ' · Anulada' : ''}</td>
        <td>${!entrega.anulado && can('puede_gestionar_ordenes_proceso') ? `<button class="secondary-button" type="button" data-correct-packaging="${escape(entrega.id)}">Corregir embalaje</button>` : ''}
        ${(entrega.correcciones_embalaje || []).length ? `<details><summary>Historial de correcciones</summary>${entrega.correcciones_embalaje.map((correccion) => `<p>${escape(correccion.anterior.embalaje || 'Sin embalaje')} (${number(correccion.anterior.kilos_por_caja, 3)} kg/caja) → ${escape(correccion.nuevo.embalaje)} (${number(correccion.nuevo.kilos_por_caja, 3)} kg/caja) · ${escape(correccion.usuario)} · ${escape(new Date(correccion.created_at).toLocaleString('es-CL'))}<br>${escape(correccion.motivo)}</p>`).join('')}</details>` : ''}</td>
    </tr>`).join('');
    panel.innerHTML = `<header class="orders-panel__heading">
            <div><p class="eyebrow">${escape(orden.linea?.nombre)} · ${escape(orden.fecha)} · TURNO ${escape(orden.turno_inicio)}</p>
            <h2 id="orderDetailTitle">${escape(orden.codigo)} · v${escape(orden.numero_version_vigente)} · ${escape(orden.estado === 'terminada' ? `Terminada ${cierres[orden.tipo_cierre] ?? ''}` : estados[orden.estado])}</h2>
            <p class="orders-muted">Inicio ${escape(vigente?.hora_inicio)}${vigente?.colacion_desde ? ` · colación ${escape(vigente.colacion_desde)}–${escape(vigente.colacion_hasta)}` : ''} · ${number(vigente?.rendimiento_kg_hora)} kg/h${orden.motivo_cancelacion ? ` · Cancelada: ${escape(orden.motivo_cancelacion)}` : ''}</p></div>
            <div class="orders-panel__actions">
                ${abierta && can('puede_gestionar_ordenes_proceso') ? '<button class="primary-button" type="button" data-action="version">Nueva versión</button>' : ''}
                ${abierta && can('puede_cancelar_ordenes_proceso') ? '<button class="secondary-button" type="button" data-action="cancelar">Cancelar orden</button>' : ''}
                <button class="secondary-button" type="button" data-action="presif" data-format="xlsx">PRE-SIF Excel</button>
                <button class="secondary-button" type="button" data-action="presif" data-format="pdf">PRE-SIF PDF</button>
                <button class="secondary-button" type="button" data-action="cerrar">Cerrar</button>
            </div>
        </header>
        <div class="orders-table-wrap"><table class="orders-table"><thead><tr><th>#</th><th>Lote</th><th>Cliente</th><th>Variedad</th><th>CSG</th><th>N° proceso</th><th>Total programado</th><th>Entregados</th><th>Pendientes</th><th>Kilos</th><th>Embalaje pendiente</th><th>Cajas est.</th><th>Mercado</th><th>Horario est.</th><th>Real</th><th>Desfase</th></tr></thead><tbody>${filas}</tbody></table></div>
        <p class="orders-muted">Programado ${number(orden.progreso.bins_programados)} bins · entregado ${number(orden.progreso.bins_entregados)} · pendiente ${number(orden.progreso.bins_pendientes)}. El desfase compara el primer viaje real con el inicio estimado del lote.</p>
        <h3>Entregas realizadas a packing</h3>
        <p class="orders-muted">Cada viaje conserva su embalaje. Una nueva versión de la programación no cambia las entregas anteriores.</p>
        <div class="orders-table-wrap"><table class="orders-table"><thead><tr><th>Lote</th><th>Fecha</th><th>Bins entregados</th><th>Embalaje del viaje</th><th>Kg por caja</th><th>Camarero</th><th>Correcciones</th></tr></thead><tbody>${entregas || '<tr><td colspan="7">Todavía no hay entregas.</td></tr>'}</tbody></table></div>
        <details><summary>Versiones (${orden.versiones.length})</summary><div class="orders-versions">${versiones}</div></details>`;
    panel.hidden = false;
}

function abrirEditor(orden = null) {
    const form = $('orderEditorForm');
    const vigente = orden?.version_vigente;
    state.editor = {
        ordenId: orden?.id ?? null,
        version: orden?.version ?? null,
        operacionId: uuid(),
        originales: vigente?.lotes || [],
        filas: (vigente?.lotes || []).map((fila) => ({
            lote_materia_prima_id: fila.lote_materia_prima_id, numero_lote: fila.numero_lote, cliente: fila.cliente?.nombre,
            bins_programados: fila.bins_programados, minimo: fila.bins_entregados, numero_proceso: fila.numero_proceso ?? '',
            embalaje: fila.embalaje ?? '', kilos_por_caja: fila.kilos_por_caja ?? '', mercado_destino: fila.mercado_destino ?? '',
            comuna: fila.comuna ?? '', provincia: fila.provincia ?? '',
        })),
    };
    form.reset();
    $('orderConfirmReduction').checked = false;
    form.elements.linea_proceso_id.innerHTML = state.catalogos.lineas.filter((linea) => linea.activa || linea.id === orden?.linea?.id)
        .map((linea) => `<option value="${escape(linea.id)}">${escape(linea.nombre)} · ${number(linea.rendimiento_kg_hora)} kg/h</option>`).join('');
    form.elements.turno_inicio.innerHTML = state.catalogos.turnos.map((turno) => `<option value="${escape(turno.codigo)}">${escape(turno.etiqueta)} (${escape(turno.codigo_pre_sif)} · ${escape(turno.codigo_instructivo)})</option>`).join('');
    form.elements.fecha.value = orden?.fecha ?? today();
    form.elements.linea_proceso_id.value = orden?.linea?.id ?? state.catalogos.lineas.find((linea) => linea.activa)?.id ?? '';
    form.elements.turno_inicio.value = orden?.turno_inicio ?? 'A';
    form.elements.hora_inicio.value = vigente?.hora_inicio ?? '08:00';
    form.elements.colacion_desde.value = vigente?.colacion_desde ?? '';
    form.elements.colacion_hasta.value = vigente?.colacion_hasta ?? '';
    ['fecha', 'linea_proceso_id', 'turno_inicio'].forEach((name) => { form.elements[name].disabled = Boolean(orden); });
    $('orderReasonField').hidden = !orden;
    $('orderEditorEyebrow').textContent = orden ? `${orden.codigo} · VERSIÓN ${orden.numero_version_vigente + 1}` : 'NUEVA ORDEN';
    $('orderPublish').textContent = orden ? `Publicar v${orden.numero_version_vigente + 1}` : 'Publicar v1';
    $('orderEditorError').textContent = '';
    $('lotSearch').value = '';
    renderFilas();
    $('orderEditor').hidden = false;
    $('orderEditor').scrollIntoView({ behavior: 'smooth', block: 'start' });
    void cargarCandidatos();
}

function renderFilas() {
    const filas = state.editor?.filas || [];
    $('orderRows').innerHTML = filas.map((fila, indice) => `<tr data-row="${indice}">
        <td class="orders-num">${indice + 1}</td><td>${escape(fila.numero_lote)}</td><td>${escape(fila.cliente)}</td>
        <td><input type="number" min="${Math.max(1, fila.minimo || 0)}" ${fila.maximo ? `max="${fila.maximo}"` : ''} data-field="bins_programados" value="${escape(fila.bins_programados)}" aria-label="Total programado de ${escape(fila.numero_lote)}"><small>${number(fila.minimo || 0)} entregados</small></td>
        <td><input data-field="numero_proceso" maxlength="40" value="${escape(fila.numero_proceso)}" aria-label="N° de proceso"></td>
        <td><input data-field="embalaje" maxlength="150" value="${escape(fila.embalaje)}" aria-label="Embalaje"></td>
        <td><input type="number" step="0.1" min="0.1" data-field="kilos_por_caja" value="${escape(fila.kilos_por_caja)}" aria-label="Kilos por caja"></td>
        <td><input data-field="mercado_destino" maxlength="150" value="${escape(fila.mercado_destino)}" aria-label="Mercado de destino"></td>
        <td><input data-field="comuna" maxlength="100" value="${escape(fila.comuna)}" aria-label="Comuna"></td>
        <td><input data-field="provincia" maxlength="100" value="${escape(fila.provincia)}" aria-label="Provincia"></td>
        <td><div class="orders-row-actions">
            <button type="button" data-move="-1" aria-label="Subir">↑</button><button type="button" data-move="1" aria-label="Bajar">↓</button>
            ${fila.minimo > 0 ? '' : '<button type="button" data-remove aria-label="Quitar">✕</button>'}
        </div></td>
    </tr>`).join('') || '<tr><td colspan="11" class="orders-muted">Agrega lotes desde la búsqueda.</td></tr>';
    renderCambios();
}

function renderCambios() {
    const changes = processOrderChanges(state.editor?.originales, state.editor?.filas);
    $('orderChangeSummary').innerHTML = [
        ...changes.packaging.map((row) => `<p><strong>${escape(row.lot)}</strong>: el nuevo embalaje aplica a ${number(row.pending)} bins pendientes. Los ${number(row.delivered)} entregados conservan su embalaje; corrige sus viajes por separado si corresponde.</p>`),
        ...changes.reductions.map((row) => `<p><strong>${escape(row.lot)}: se liberarán ${number(row.released)} bins de esta orden.</strong> Total ${number(row.before)} → ${number(row.after)}; entregados ${number(row.delivered)}; pendientes después del cambio ${number(row.pending)}.</p>`),
    ].join('');
    $('orderReductionField').hidden = !changes.released;
    return changes;
}

function abrirCorreccion(entregaId) {
    const entrega = state.detalle?.entregas?.find((item) => item.id === entregaId);
    if (!entrega || entrega.anulado || !can('puede_gestionar_ordenes_proceso')) return;
    state.correccion = { entregaId, version: entrega.version_embalaje, operacionId: uuid(), ordenId: state.detalle.id };
    const form = $('deliveryPackagingForm');
    form.reset();
    form.elements.embalaje.value = entrega.embalaje || '';
    form.elements.kilos_por_caja.value = entrega.kilos_por_caja ?? '';
    $('deliveryPackagingContext').textContent = `${state.detalle.codigo} · lote ${entrega.numero_lote} · ${number(entrega.cantidad_envases)} bins entregados el ${new Date(entrega.entregado_at).toLocaleString('es-CL')}.`;
    $('deliveryPackagingError').textContent = '';
    $('deliveryPackagingDialog').showModal();
}

async function cargarCandidatos() {
    const params = new URLSearchParams();
    if (state.editor?.ordenId) params.set('orden_id', state.editor.ordenId);
    if ($('lotSearch').value.trim()) params.set('buscar', $('lotSearch').value.trim());
    const { data } = await api(`/api/materia-prima/ordenes-proceso/lotes-programables?${params}`);
    state.candidatos = data;
    renderCandidatos();
}

function renderCandidatos() {
    const usados = new Set((state.editor?.filas || []).map((fila) => fila.lote_materia_prima_id));
    const libres = state.candidatos.filter((lote) => !usados.has(lote.id));
    $('lotCandidates').innerHTML = libres.map((lote) => `<div class="orders-candidate">
        <span><strong>${escape(lote.numero_lote)}</strong> · ${escape(lote.cliente?.nombre)} · ${escape(lote.variedad)} · CSG ${escape(lote.csg)}<br>
        <small>${number(lote.bins_libres)} bins libres de ${number(lote.saldo_bins)} en ${escape(lote.camara?.codigo || 'salida directa')}${lote.bins_comprometidos ? ` · ${number(lote.bins_comprometidos)} en otras órdenes` : ''}</small></span>
        <button class="secondary-button" type="button" data-add="${escape(lote.id)}">Agregar</button>
    </div>`).join('') || '<p class="orders-muted">No hay lotes con bins libres para esa búsqueda.</p>';
}

function payloadEditor() {
    const form = $('orderEditorForm');
    const plan = {
        operacion_id: state.editor.operacionId,
        hora_inicio: form.elements.hora_inicio.value,
        colacion_desde: form.elements.colacion_desde.value || null,
        colacion_hasta: form.elements.colacion_hasta.value || null,
        lotes: state.editor.filas.map((fila) => ({
            lote_materia_prima_id: fila.lote_materia_prima_id,
            bins_programados: Number(fila.bins_programados),
            numero_proceso: fila.numero_proceso || null,
            embalaje: fila.embalaje || null,
            kilos_por_caja: fila.kilos_por_caja === '' ? null : Number(fila.kilos_por_caja),
            mercado_destino: fila.mercado_destino || null,
            comuna: fila.comuna || null,
            provincia: fila.provincia || null,
        })),
    };
    if (state.editor.ordenId) return { ...plan, version: state.editor.version, motivo_cambio: form.elements.motivo_cambio.value, confirmar_reduccion: $('orderConfirmReduction').checked };
    return { ...plan, fecha: form.elements.fecha.value, linea_proceso_id: form.elements.linea_proceso_id.value, turno_inicio: form.elements.turno_inicio.value };
}

async function cargarOrdenes() {
    const params = new URLSearchParams([...new FormData($('ordersFilters'))].filter(([, value]) => value !== ''));
    const { data } = await api(`/api/materia-prima/ordenes-proceso?${params}`);
    state.ordenes = data;
    renderLista();
}

async function abrirDetalle(id) {
    const { data } = await api(`/api/materia-prima/ordenes-proceso/${encodeURIComponent(id)}`);
    state.detalle = data;
    renderDetalle();
    $('orderDetail').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderLineas() {
    if (!can('puede_configurar_lineas_proceso')) return;
    $('linesPanel').hidden = false;
    $('linesList').innerHTML = state.catalogos.lineas.map((linea) => `<form data-line="${escape(linea.id)}" data-version="${escape(linea.version)}">
        <strong>${escape(linea.codigo)}</strong>
        <label>Nombre<input name="nombre" maxlength="60" value="${escape(linea.nombre)}"></label>
        <label>Rendimiento (kg/h)<input name="rendimiento_kg_hora" type="number" min="100" max="100000" step="50" value="${escape(linea.rendimiento_kg_hora)}"></label>
        <label><input name="activa" type="checkbox" ${linea.activa ? 'checked' : ''}> Activa</label>
        <button class="secondary-button" type="submit">Guardar</button>
    </form>`).join('');
}

async function cargarTodo() {
    state.catalogos = await api('/api/materia-prima/ordenes-proceso/catalogos');
    renderFiltros();
    renderLineas();
    $('ordersNew').hidden = !can('puede_gestionar_ordenes_proceso');
    await cargarOrdenes();
}

$('ordersReload').addEventListener('click', () => void run(cargarTodo));
$('ordersFilters').addEventListener('submit', (event) => { event.preventDefault(); void run(cargarOrdenes); });
$('ordersNew').addEventListener('click', () => void run(async () => abrirEditor()));
$('ordersList').addEventListener('click', (event) => {
    const card = event.target.closest('[data-order]');
    if (card) void run(() => abrirDetalle(card.dataset.order));
});
$('orderDetail').addEventListener('click', (event) => {
    const entregaId = event.target.closest('[data-correct-packaging]')?.dataset.correctPackaging;
    if (entregaId) abrirCorreccion(entregaId);
    const action = event.target.closest('[data-action]')?.dataset.action;
    if (action === 'cerrar') { state.detalle = null; renderDetalle(); }
    if (action === 'presif') {
        const formato = event.target.closest('[data-format]').dataset.format;
        void run(async () => {
            const response = await fetch(`/api/materia-prima/ordenes-proceso/${encodeURIComponent(state.detalle.id)}/pre-sif?formato=${formato}`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${state.token}` },
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || 'No fue posible generar la PRE-SIF.');
            }
            const nombre = /filename="?([^"]+)"?/.exec(response.headers.get('Content-Disposition') || '')?.[1] || `pre-sif.${formato}`;
            const url = URL.createObjectURL(await response.blob());
            const enlace = document.createElement('a');
            enlace.href = url; enlace.download = nombre; enlace.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
    }
    if (action === 'version') void run(async () => abrirEditor(state.detalle));
    if (action === 'cancelar') {
        const motivo = window.prompt(`Motivo para cancelar ${state.detalle.codigo}. Las entregas hechas se conservan y los bins pendientes quedan libres.`);
        if (!motivo) return;
        void run(async () => {
            const { data } = await api(`/api/materia-prima/ordenes-proceso/${encodeURIComponent(state.detalle.id)}/cancelar`, {
                method: 'POST', body: JSON.stringify({ version: state.detalle.version, motivo }),
            });
            state.detalle = data;
            renderDetalle();
            await cargarOrdenes();
        });
    }
});
$('orderEditorClose').addEventListener('click', () => { $('orderEditor').hidden = true; state.editor = null; });
$('orderRows').addEventListener('input', (event) => {
    const fila = state.editor?.filas[Number(event.target.closest('[data-row]')?.dataset.row)];
    if (fila && event.target.dataset.field) {
        fila[event.target.dataset.field] = event.target.value;
        $('orderConfirmReduction').checked = false;
        renderCambios();
    }
});
$('orderRows').addEventListener('click', (event) => {
    const indice = Number(event.target.closest('[data-row]')?.dataset.row);
    const filas = state.editor?.filas;
    if (!filas || Number.isNaN(indice)) return;
    const mover = event.target.closest('[data-move]');
    if (mover) {
        const destino = indice + Number(mover.dataset.move);
        if (destino < 0 || destino >= filas.length) return;
        [filas[indice], filas[destino]] = [filas[destino], filas[indice]];
    } else if (event.target.closest('[data-remove]')) {
        filas.splice(indice, 1);
    } else {
        return;
    }
    renderFilas();
    renderCandidatos();
});
let busqueda = null;
$('lotSearch').addEventListener('input', () => {
    clearTimeout(busqueda);
    busqueda = setTimeout(() => void run(cargarCandidatos, 'orderEditorError'), 300);
});
$('lotCandidates').addEventListener('click', (event) => {
    const id = event.target.closest('[data-add]')?.dataset.add;
    const lote = state.candidatos.find((candidato) => candidato.id === id);
    if (!lote || !state.editor) return;
    state.editor.filas.push({
        lote_materia_prima_id: lote.id, numero_lote: lote.numero_lote, cliente: lote.cliente?.nombre,
        bins_programados: lote.bins_libres, maximo: lote.bins_libres, minimo: 0, numero_proceso: '', embalaje: '',
        kilos_por_caja: '', mercado_destino: '', comuna: lote.comuna ?? '', provincia: lote.provincia ?? '',
    });
    renderFilas();
    renderCandidatos();
});
$('orderEditorForm').addEventListener('submit', (event) => {
    event.preventDefault();
    void run(async () => {
        const changes = renderCambios();
        if (changes.released && !$('orderConfirmReduction').checked) throw new Error(`Confirma que deseas liberar ${number(changes.released)} bins de esta orden. Si solo cambia el embalaje, conserva el total programado.`);
        const datos = payloadEditor();
        const url = state.editor.ordenId
            ? `/api/materia-prima/ordenes-proceso/${encodeURIComponent(state.editor.ordenId)}/versiones`
            : '/api/materia-prima/ordenes-proceso';
        const { data } = await api(url, { method: 'POST', body: JSON.stringify(datos) });
        $('orderEditor').hidden = true;
        state.editor = null;
        state.detalle = data;
        renderDetalle();
        await cargarOrdenes();
        $('ordersNotice').textContent = `${data.codigo} v${data.numero_version_vigente} publicada.`;
    }, 'orderEditorError');
});
$('deliveryPackagingCancel').addEventListener('click', () => $('deliveryPackagingDialog').close());
$('deliveryPackagingDialog').addEventListener('close', () => { state.correccion = null; });
$('deliveryPackagingForm').addEventListener('submit', (event) => {
    event.preventDefault();
    void run(async () => {
        const correction = state.correccion;
        if (!correction) return;
        const form = $('deliveryPackagingForm');
        const { data } = await api(`/api/materia-prima/fruta-proceso/entregas/${encodeURIComponent(correction.entregaId)}/embalaje`, {
            method: 'POST', body: JSON.stringify({
                operacion_id: correction.operacionId, version_embalaje: correction.version,
                embalaje: form.elements.embalaje.value.trim(),
                kilos_por_caja: form.elements.kilos_por_caja.value === '' ? null : Number(form.elements.kilos_por_caja.value),
                motivo: form.elements.motivo.value.trim(),
            }),
        });
        $('deliveryPackagingDialog').close();
        if (state.detalle?.id === correction.ordenId) {
            state.detalle.entregas = state.detalle.entregas.map((entrega) => entrega.id === data.id ? data : entrega);
            renderDetalle();
        }
        $('ordersNotice').textContent = 'Embalaje de la entrega corregido. Las cantidades de la orden se conservaron.';
    }, 'deliveryPackagingError');
});
$('linesList').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.target.closest('[data-line]');
    void run(async () => {
        await api(`/api/materia-prima/lineas-proceso/${encodeURIComponent(form.dataset.line)}`, {
            method: 'PUT',
            body: JSON.stringify({
                nombre: form.elements.nombre.value,
                rendimiento_kg_hora: Number(form.elements.rendimiento_kg_hora.value),
                activa: form.elements.activa.checked,
                version: Number(form.dataset.version),
            }),
        });
        await cargarTodo();
        $('ordersNotice').textContent = 'Línea actualizada.';
    });
});

if (!state.token) location.replace('/oficina/materia-prima'); else void run(cargarTodo);
