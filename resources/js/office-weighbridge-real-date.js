// Fecha real de una recepción digitada después (por ejemplo, la noche que quedó pendiente).
// Cambia la fecha y hora del camión en la recepción y en sus movimientos de envases; los
// informes por día y jornada la toman desde ahí. El registro original queda en el historial.

/** Fecha (YYYY-MM-DD) y hora (HH:MM) de un instante en la zona horaria de la planta. */
export function plantDateTime(value, zone) {
    if (!value) return { date: '', time: '' };
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(value)).map((part) => [part.type, part.value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

export function plantLabel(value, zone) {
    if (!value) return '—';
    const { date, time } = plantDateTime(value, zone);
    return `${date.split('-').reverse().join('-')} ${time}`;
}

export function createRealDateCorrection({ dialog, api, escapeHtml: esc, operationUuid, toast, onSaved }) {
    const zone = dialog.dataset.zone || 'America/Santiago';
    let reception = null;
    let attempt = null;

    function render() {
        const ingreso = plantDateTime(reception.ingreso_at, zone);
        const salida = plantDateTime(reception.salida_at, zone);
        const registered = plantDateTime(reception.registrado_at, zone);
        const today = plantDateTime(new Date().toISOString(), zone).date;
        dialog.innerHTML = `<form class="weighbridge-dialog__shell weighbridge-dialog__shell--compact" id="realDateForm" novalidate>
            <header class="weighbridge-dialog__heading"><div><p class="eyebrow">REGISTRO POSTERIOR</p>
                <h2>Fecha real · ${esc(reception.numero_recepcion)}</h2>
                <p>Usa la hora en que el camión llegó y salió de la romana, no la de digitación. Se registró en FoliOS el ${esc(plantLabel(reception.registrado_at, zone))}.</p></div>
                <button class="dialog-close" type="button" data-cerrar aria-label="Cerrar">×</button></header>
            <fieldset class="real-date-pair"><legend>Ingreso del camión *</legend>
                <label class="field"><span>Fecha</span><input name="ingreso_fecha" type="date" value="${esc(ingreso.date)}" max="${esc(registered.date || today)}" required></label>
                <label class="field"><span>Hora</span><input name="ingreso_hora" type="time" value="${esc(ingreso.time)}" required></label>
            </fieldset>
            ${reception.salida_at ? `<fieldset class="real-date-pair"><legend>Salida / destare *</legend>
                <label class="field"><span>Fecha</span><input name="salida_fecha" type="date" value="${esc(salida.date)}" max="${esc(today)}" required></label>
                <label class="field"><span>Hora</span><input name="salida_hora" type="time" value="${esc(salida.time)}" required></label>
            </fieldset>` : '<p>La salida se registrará con su propia hora al hacer el destare.</p>'}
            <label class="field"><span>Motivo *</span><textarea name="motivo" rows="3" minlength="10" maxlength="1000" required placeholder="Ej.: camión de la noche digitado al día siguiente"></textarea></label>
            <p>También se mueven los movimientos de envases de este ingreso y su salida. Los lotes, kilos y guías no cambian.</p>
            <p class="form-error" id="realDateError" role="alert"></p>
            <footer class="dialog-actions"><button class="secondary-button" type="button" data-cerrar>Cancelar</button><button class="primary-button" type="submit">Guardar fecha real</button></footer>
        </form>`;
    }

    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-cerrar]') && !attempt?.submitting) dialog.close();
    });
    dialog.addEventListener('cancel', (event) => { if (attempt?.submitting) event.preventDefault(); });
    dialog.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = dialog.querySelector('#realDateForm');
        if (!reception || attempt?.submitting || !form.reportValidity()) return;
        const values = form.elements;
        const data = {
            version_conocida: reception.version,
            ingreso_fecha: values.ingreso_fecha.value, ingreso_hora: values.ingreso_hora.value,
            ...(values.salida_fecha ? { salida_fecha: values.salida_fecha.value, salida_hora: values.salida_hora.value } : {}),
            motivo: values.motivo.value.trim(),
        };
        const error = dialog.querySelector('#realDateError');
        const before = plantDateTime(reception.ingreso_at, zone);
        const beforeExit = plantDateTime(reception.salida_at, zone);
        if (data.ingreso_fecha === before.date && data.ingreso_hora === before.time
            && (!data.salida_fecha || (data.salida_fecha === beforeExit.date && data.salida_hora === beforeExit.time))) {
            error.textContent = 'La fecha y hora indicadas son las mismas ya registradas.';
            return;
        }
        // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
        const same = attempt?.payload && JSON.stringify(attempt.payload) === JSON.stringify(data);
        attempt = { operacion_id: same ? attempt.operacion_id : operationUuid(), payload: data, submitting: true };
        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        error.textContent = '';
        try {
            const respuesta = await api(`/api/romana/recepciones/${reception.id}/fecha-real`, { method: 'PUT', body: JSON.stringify({ ...data, operacion_id: attempt.operacion_id }) });
            dialog.close();
            toast(`Fecha real guardada: ingreso ${plantLabel(respuesta.data.ingreso_at, zone)}.`);
            await onSaved(respuesta.data);
        } catch (failure) {
            error.textContent = failure.message;
        } finally {
            attempt.submitting = false;
            buttons.forEach((button) => { button.disabled = false; });
        }
    });

    return {
        open(selected) {
            reception = selected;
            attempt = null;
            render();
            dialog.showModal();
            dialog.querySelector('[name="ingreso_fecha"]')?.focus();
        },
    };
}

/** Línea del historial para una corrección de fecha real. */
export function realDateAudit(event, zone) {
    if (event.tipo !== 'fecha_real_corregida') return '';
    const before = event.datos?.anterior || {};
    const after = event.datos?.posterior || {};
    const exit = before.salida_at && after.salida_at && before.salida_at !== after.salida_at
        ? ` · Salida ${plantLabel(before.salida_at, zone)} → ${plantLabel(after.salida_at, zone)}` : '';
    return `<small>Ingreso ${plantLabel(before.ingreso_at, zone)} → ${plantLabel(after.ingreso_at, zone)}${exit}</small>`;
}
