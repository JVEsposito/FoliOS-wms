const $ = (id) => document.getElementById(id);
const root = '/api/validacion/pendientes-origen/conciliaciones';
const labels = { pendiente: 'Pendiente de cuadratura', en_revision: 'En revisión', cuadrado: 'Cuadrado', reabierto: 'Reabierto', sin_diferencias: 'Sin diferencias', por_revisar: 'Por revisar' };
const contexts = { vigente: 'Validación vigente', validacion_anulada: 'Validación anulada', salida_o_transformacion: 'Salida o transformación posterior', folio_modificado: 'Folio modificado después de validar' };
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const value = (v) => v && typeof v === 'object' ? Object.entries(v).map(([csg, boxes]) => `${csg}: ${boxes}`).join(' · ') : String(v ?? 'Sin dato');
const date = (d) => d ? new Intl.DateTimeFormat('es-CL', { timeZone: 'America/Santiago', dateStyle: 'short', timeStyle: 'short' }).format(new Date(d)) : 'Sin lectura';
let api; let notice; let current; let manageable = false; let generation = 0; let detailGeneration = 0; let pending = null; let working = false; let currentPage = 1;

function query(page = 1) { const q = new URLSearchParams(new FormData($('reconciliationFilters'))); q.set('page', page); return q; }
function fields(comparison) {
    return `<ul class="pending-differences">${Object.values(comparison).map((c) => `<li class="reconciliation-field--${escape(c.estado)}"><b>${escape(c.etiqueta)} · ${c.estado === 'coincide' ? 'Coincide' : c.estado === 'diferente' ? 'Diferente' : 'Por revisar'}</b><span>Planta: ${escape(value(c.planta))}</span><span>Físico validado: ${escape(value(c.validado))}</span>${c.diferencia != null && c.diferencia !== 0 ? `<strong>${c.diferencia > 0 ? '+' : ''}${escape(c.diferencia)} cajas</strong>` : ''}${c.planta_homologada != null && value(c.planta_homologada) !== value(c.planta) ? `<small>Equivalencia: ${escape(value(c.planta_homologada))}</small>` : ''}</li>`).join('')}</ul>`;
}
function original(c) {
    return `<div class="pending-table-scroll"><table class="pending-table"><thead><tr><th>Campo</th><th>Planta inicial</th><th>Físico validado inicial</th></tr></thead><tbody>${Object.values(c.comparacion).map((f) => `<tr><td>${escape(f.etiqueta)}</td><td>${escape(value(c.planta_inicial?.[f.campo]))}</td><td>${escape(value(c.validacion_inicial.datos[f.campo]))}</td></tr>`).join('')}</tbody></table></div>`;
}
function pages(id, page, go) {
    $(id).replaceChildren();
    for (const [name, target] of [['Anterior', page.current_page - 1], ['Siguiente', page.current_page + 1]]) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary-button'; button.textContent = name;
        button.disabled = target < 1 || target > page.last_page; button.onclick = () => go(target); $(id).append(button);
    }
    const text = document.createElement('span'); text.textContent = `${page.current_page} / ${page.last_page}`; $(id).append(text);
}
export async function loadReconciliations(page = 1) {
    if (!api) return;
    const revision = ++generation;
    try {
        const r = await api(`${root}?${query(page)}`);
        if (revision !== generation) return;
        if (!r.ok) throw new Error(r.data.message || 'No se pudieron consultar las cuadraturas.');
        manageable = r.data.puede_gestionar; currentPage = r.data.casos.current_page;
        $('tabReconciliationCount').textContent = r.data.casos.total;
        $('reconciliationCheck').hidden = !manageable;
        $('reconciliationStatus').textContent = `${r.data.casos.total} conciliaciones con estos filtros. ${r.data.fuente.vigente ? 'Lectura de planta vigente.' : 'Lectura sin vigencia confirmada: revisar antes de cuadrar.'}`;
        if (r.data.fuente.conciliacion_pendiente) $('reconciliationStatus').textContent += ' Hay conciliaciones pendientes: usa Registrar / recomprobar conciliaciones.';
        $('reconciliationBody').innerHTML = r.data.casos.data.map((c) => `<tr><td><b class="pending-number">${escape(c.numero_folio)}</b><small>${escape(c.validacion_inicial.usuario)} · ${date(c.validado_at)}</small></td><td><strong>${escape(labels[c.estado])}</strong><small>${escape(contexts[c.contexto_actual])}</small>${!c.comprobacion_vigente ? '<small class="pending-warning">Comprobación pendiente o sin vigencia</small>' : ''}<small>Planta: ${date(c.lectura_at)}</small></td><td>${fields(c.comparacion)}</td><td><button type="button" class="secondary-button" data-reconciliation="${escape(c.id)}">Ver seguimiento</button></td></tr>`).join('') || '<tr><td colspan="4">No hay conciliaciones con estos filtros. Una lectura correcta o una recomprobación incorpora las validaciones existentes.</td></tr>';
        pages('reconciliationPages', r.data.casos, (p) => void loadReconciliations(p));
    } catch (error) { if (revision === generation) $('reconciliationStatus').textContent = error.message; }
}
async function open(id, page = 1) {
    if (pending || working) return;
    const revision = ++detailGeneration;
    try {
        const r = await api(`${root}/${encodeURIComponent(id)}?pagina_historial=${page}`);
        if (revision !== detailGeneration) return;
        if (!r.ok) throw new Error(r.data.message || 'No se pudo abrir la conciliación.');
        current = r.data;
        $('reconciliationTitle').textContent = `${current.numero_folio} · ${labels[current.estado]}`;
        $('reconciliationDetailNotice').textContent = '';
        $('reconciliationDetail').innerHTML = `<p>${escape(contexts[current.contexto_actual])} · Planta: ${date(current.lectura_at)}</p>${!current.comprobacion_vigente ? '<p class="pending-warning">Esta comparación requiere revisión o una lectura vigente.</p>' : ''}${fields(current.comparacion)}<details><summary>Respaldo inicial conservado</summary><p>${current.captura === 'registro_servidor' ? 'Copia disponible al registrar en el servidor' : 'Primera comparación posterior a la validación'} · ${escape(current.validacion_inicial.usuario)} · validado ${date(current.validado_at)}</p><p>Planta inicial: ${date(current.planta_inicial?.leida_at)}</p>${original(current)}</details>`;
        $('reconciliationManagement').hidden = !manageable || !current.gestion_habilitada;
        if (page === 1) $('reconciliationManagement').reset();
        $('reconciliationHistory').innerHTML = current.historial.data.map((e) => `<article class="reconciliation-event"><strong>${escape(({ captura: 'Respaldo inicial', comprobacion: 'Comprobación', correccion_validacion: 'Validación corregida con autorización', revisar: 'Tomado en revisión', gestionada: 'Corrección informada en planta' })[e.tipo] || e.tipo)} · ${date(e.created_at)}</strong><p>${escape(e.usuario_nombre || 'Comprobación del sistema')}</p><p>${escape(e.comentario || '')} ${escape(e.referencia || '')}</p>${e.datos.despues?.comparacion ? `<details><summary>Valores comprobados en este evento</summary>${fields(e.datos.despues.comparacion)}<p>Lectura: ${date(e.datos.lectura_at)}</p></details>` : ''}</article>`).join('');
        pages('reconciliationHistoryPages', current.historial, (p) => void open(id, p));
        if (!$('reconciliationDialog').open) $('reconciliationDialog').showModal();
    } catch (error) { notice(error.message, true); }
}
function lock(active) {
    working = active;
    $('reconciliationManagement').querySelectorAll('input, textarea, select, button').forEach((e) => { e.disabled = active || pending !== null; });
    $('reconciliationPending').hidden = pending === null;
    $('reconciliationRetry').disabled = active;
}
async function send() {
    if (working || !pending) return;
    lock(true);
    try {
        const r = await api(`${root}/${pending.id}/gestiones`, { method: 'POST', body: JSON.stringify(pending.data) });
        if (!r.ok) {
            if (r.status >= 400 && r.status < 500) { pending = null; }
            throw new Error(r.data.message || 'No se confirmó la gestión. Reintenta el mismo envío.');
        }
        const id = pending.id; pending = null; lock(false);
        notice('Gestión registrada. La cuadratura se confirmará con una lectura posterior.');
        await open(id); await loadReconciliations(currentPage);
    } catch (error) { notice(error.message, true); }
    finally { lock(false); }
}
export function initReconciliations(request, showNotice, getToken) {
    api = request; notice = (message, error = false) => { if ($('reconciliationDialog').open) $('reconciliationDetailNotice').textContent = message; showNotice(message, error); };
    $('reconciliationFilters').onsubmit = (e) => { e.preventDefault(); void loadReconciliations(); };
    $('reconciliationBody').onclick = (e) => { const b = e.target.closest('[data-reconciliation]'); if (b) void open(b.dataset.reconciliation); };
    $('reconciliationClose').onclick = () => { if (!pending && !working) { detailGeneration++; $('reconciliationDialog').close(); } };
    $('reconciliationDialog').addEventListener('cancel', (e) => { if (pending || working) e.preventDefault(); });
    $('reconciliationManagement').onsubmit = (e) => {
        e.preventDefault(); if (pending || working || !current) return;
        pending = { id: current.id, data: { ...Object.fromEntries(new FormData(e.target)), operacion_id: crypto.randomUUID(), version: current.version } }; void send();
    };
    $('reconciliationRetry').onclick = () => void send();
    $('reconciliationCheck').onclick = async () => {
        $('reconciliationCheck').disabled = true;
        try { const r = await api(`${root}/recomprobar`, { method: 'POST' }); if (!r.ok) throw new Error(r.data.message || 'No se pudo recomprobar.'); notice(`${r.data.revisadas} conciliaciones revisadas.`); await loadReconciliations(); }
        catch (error) { notice(error.message, true); } finally { $('reconciliationCheck').disabled = false; }
    };
    $('reconciliationExcel').onclick = async () => {
        $('reconciliationExcel').disabled = true;
        try {
            const r = await fetch(`${root}/excel?${query()}`, { headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json' } });
            if (!r.ok) throw new Error('No se pudo descargar el Excel de cuadraturas.');
            const url = URL.createObjectURL(await r.blob()); const a = document.createElement('a'); a.href = url; a.download = 'conciliaciones-validacion-planta.xlsx'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 10000);
        } catch (error) { notice(error.message, true); } finally { $('reconciliationExcel').disabled = false; }
    };
    window.addEventListener('beforeunload', (e) => { if (pending) { e.preventDefault(); e.returnValue = ''; } });
}
