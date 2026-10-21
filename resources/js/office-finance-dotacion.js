import { attendanceIssueText } from './shared/rrhh-logic';

export function mountDotacionCharges({ api, data, canManage, escapeHtml: esc, toast, onSaved }) {
    const $ = (id) => document.getElementById(id);
    const dialog = $('dotacionChargeDialog'); const form = $('dotacionChargeForm');
    let page = 1; let rows = []; let selected = null; let generation = 0;
    const errorText = (r) => Object.values(r.data?.errors || {}).flat()[0] || r.data?.message || 'No fue posible leer la dotación.';
    const time = (s) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: document.body.dataset.financeZone }).format(new Date(s));
    function preview() {
        const metric = form.elements.medida.value;
        const actual = ['horas_asistidas', 'jornadas_asistidas'].includes(metric);
        const valid = selected && (!actual || (selected.asistencia_valida && selected.turno_finalizado));
        $('dotacionChargeQuantity').textContent = selected ? `${selected[metric]} ${metric.startsWith('horas') ? 'horas persona' : 'jornadas persona'} · ${selected.cliente_nombre || 'Sin cliente'} · ${selected.estado}` : 'Seleccione una asignación.';
        const service = data()?.servicios.find((s) => s.clave === form.elements.servicio.value);
        $('dotacionChargeUnit').textContent = `Concepto: ${service?.nombre || ''} · Unidad de su tarifa: ${service?.unidad_libre || ''}. Confirme que corresponde a la medida elegida.`;
        $('dotacionChargeSubmit').disabled = !valid;
        const execution = form.elements.base_cobro.querySelector('[value=ejecucion]'); execution.disabled = !actual;
        if (!actual && form.elements.base_cobro.value === 'ejecucion') form.elements.base_cobro.value = 'acuerdo';
        $('dotacionChargeNotice').textContent = selected && actual && !valid ? 'La asistencia necesita turno finalizado y marcajes completos sin incidencias. La programación puede cobrarse por acuerdo.' : 'Se creará un borrador. El cobro requiere aprobación económica en Cargos acordados.';
        $('dotacionChargeReplacement').hidden = !selected?.cargo_actual?.anulado;
        form.elements.confirmar_reemplazo.required = !!selected?.cargo_actual?.anulado;
    }
    async function load() {
        const request = ++generation; selected = null; preview();
        const r = await api(`/api/finanzas/dotacion?${new URLSearchParams({ mes: $('dotacionChargeMonth').value, servicio: form.elements.servicio.value, page })}`);
        if (request !== generation) return;
        if (!r.ok || !Array.isArray(r.data?.data?.data)) { $('dotacionChargeError').textContent = errorText(r); return; }
        rows = r.data.data.data;
        $('dotacionChargeRows').innerHTML = rows.map((a) => `<tr><td>${esc(a.cliente_nombre || 'Sin cliente')}<small>${esc(a.cargo)} · ${esc(a.area)} · ${esc(a.ceco)}</small><small>Asignación ${esc(a.id.slice(0, 8))} · ${esc(a.estado)}</small><small>${esc(time(a.desde_at))} → ${esc(time(a.hasta_at))}</small></td><td>${esc(a.horas_programadas)} h / ${esc(a.jornadas_programadas)} jornada</td><td>${esc(a.horas_asistidas)} h / ${esc(a.jornadas_asistidas)} jornada<small>${a.asistencia_valida && a.turno_finalizado ? 'Marcajes completos' : esc(attendanceIssueText(a.incidencias) || 'Turno en curso')}</small></td><td>`
            + (a.cargo_actual && !a.cargo_actual.anulado ? `Ya tiene cargo ${esc(a.cargo_actual.id.slice(0, 8))}` : `<button type="button" class="fin-boton" data-dotacion-choose="${esc(a.id)}" ${a.cliente_id ? '' : 'disabled'}>${a.cargo_actual?.anulado ? 'Reemplazar cargo anulado' : 'Elegir'}</button>`)
            + '</td></tr>').join('') || '<tr><td colspan="4">Sin asignaciones en este mes.</td></tr>';
        $('dotacionChargePage').textContent = `${r.data.data.current_page} / ${r.data.data.last_page}`;
        $('dotacionChargePrev').disabled = r.data.data.current_page <= 1; $('dotacionChargeNext').disabled = r.data.data.current_page >= r.data.data.last_page;
    }
    $('chargeFromRrhh').addEventListener('click', async () => {
        const services = data()?.servicios.filter((s) => s.fuente === 'declarado') || [];
        if (!services.length) { toast('Primero cree un concepto acordado/manual para RRHH, con unidad hora persona o jornada persona.', true); return; }
        form.reset(); selected = null; page = 1; $('dotacionChargeError').textContent = '';
        form.elements.servicio.innerHTML = services.map((s) => `<option value="${esc(s.clave)}">${esc(s.nombre)} · ${esc(s.unidad_libre)}</option>`).join('');
        form.elements.fecha.value = data().hoy; $('dotacionChargeMonth').value = $('chargeMonth').value; dialog.showModal(); await load();
    });
    form.elements.servicio.addEventListener('change', () => { page = 1; form.elements.confirmar_unidad.checked = false; load(); });
    form.elements.medida.addEventListener('change', () => { form.elements.confirmar_unidad.checked = false; preview(); });
    $('dotacionChargeMonth').addEventListener('change', () => { page = 1; load(); });
    $('dotacionChargePrev').addEventListener('click', () => { page--; load(); }); $('dotacionChargeNext').addEventListener('click', () => { page++; load(); });
    $('dotacionChargeRows').addEventListener('click', (event) => {
        const button = event.target.closest('[data-dotacion-choose]'); if (!button) return;
        selected = rows.find((a) => a.id === button.dataset.dotacionChoose); form.elements.confirmar_reemplazo.checked = false; preview();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!selected || $('dotacionChargeSubmit').disabled) return; $('dotacionChargeSubmit').disabled = true;
        try {
            const values = Object.fromEntries(new FormData(form));
            const r = await api('/api/finanzas/dotacion/cargos', { method: 'POST', body: JSON.stringify({ servicio: values.servicio, medida: values.medida, fecha: values.fecha, base_cobro: values.base_cobro,
                respaldo: values.respaldo, asignacion_id: selected.id, confirmar_unidad: form.elements.confirmar_unidad.checked,
                ...(selected.cargo_actual?.anulado && form.elements.confirmar_reemplazo.checked ? { reemplaza_cargo_id: selected.cargo_actual.id } : {}) }) });
            if (!r.ok) { $('dotacionChargeError').textContent = errorText(r); return; }
            dialog.close(); toast('Borrador de dotación guardado. Revíselo y apruébelo para valorizarlo.'); await onSaved();
        } finally { preview(); }
    });
    dialog.addEventListener('click', (event) => { if (event.target.closest('[data-dotacion-close]')) dialog.close(); });
    return { render() { $('chargeFromRrhh').hidden = !canManage() || data()?.puede_usar_rrhh !== true; } };
}
