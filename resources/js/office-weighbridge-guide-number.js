// Corrección de administración del número de las guías de ingreso, en cualquier etapa de la recepción.
// Solo cambia los números: envases, conteos, lotes y kilos quedan como están.

export function createGuideNumberCorrection({ dialog, api, escapeHtml: esc, operationUuid, toast, onSaved }) {
    let reception = null;
    let attempt = null;

    function render() {
        dialog.innerHTML = `<form class="weighbridge-dialog__shell weighbridge-dialog__shell--compact" id="guideNumberForm" novalidate>
            <header class="weighbridge-dialog__heading"><div><p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p>
                <h2>Corregir N° de guía · ${esc(reception.numero_recepcion)}</h2>
                <p>Solo cambia el número. Envases, conteos, lotes y kilos no cambian. El número nuevo llega a la cuenta de envases, al RPR-01 y al registro de defectos.</p></div>
                <button class="dialog-close" type="button" data-cerrar aria-label="Cerrar">×</button></header>
            ${reception.guias.map((guia, index) => `<label class="field"><span>Guía ${reception.guias.length > 1 ? `${index + 1} ` : ''}· actual ${esc(guia.numero_guia_despacho)}</span>
                <input name="guia_${esc(guia.id)}" data-guide-id="${esc(guia.id)}" value="${esc(guia.numero_guia_despacho)}" maxlength="80" required autocomplete="off"></label>`).join('')}
            <label class="field"><span>Motivo *</span><textarea name="motivo" rows="3" minlength="10" maxlength="2000" required placeholder="Qué número se digitó y cuál es el de la guía física"></textarea></label>
            <p class="form-error" id="guideNumberError" role="alert"></p>
            <footer class="dialog-actions"><button class="secondary-button" type="button" data-cerrar>Cancelar</button><button class="primary-button" type="submit">Guardar N° de guía</button></footer>
        </form>`;
    }

    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-cerrar]') && !attempt?.submitting) dialog.close();
    });
    dialog.addEventListener('cancel', (event) => { if (attempt?.submitting) event.preventDefault(); });
    dialog.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = dialog.querySelector('#guideNumberForm');
        if (!reception || attempt?.submitting || !form.reportValidity()) return;
        const data = {
            version_conocida: reception.version, motivo: form.elements.motivo.value.trim(),
            guias: [...form.querySelectorAll('[data-guide-id]')].map((input) => ({ id: input.dataset.guideId, numero_guia_despacho: input.value.trim().toUpperCase() })),
        };
        const error = dialog.querySelector('#guideNumberError');
        if (data.guias.every((guia) => guia.numero_guia_despacho === reception.guias.find((g) => g.id === guia.id)?.numero_guia_despacho)) {
            error.textContent = 'Los números de guía no presentan cambios.';
            return;
        }
        // Conserva el UUID para repetir exactamente un envío cuya respuesta se perdió.
        const same = attempt?.payload && JSON.stringify(attempt.payload) === JSON.stringify(data);
        attempt = { operacion_id: same ? attempt.operacion_id : operationUuid(), payload: data, submitting: true };
        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        error.textContent = '';
        try {
            const respuesta = await api(`/api/romana/recepciones/${reception.id}/numero-guia`, { method: 'PUT', body: JSON.stringify({ ...data, operacion_id: attempt.operacion_id }) });
            dialog.close();
            toast(`N° de guía corregido: ${respuesta.data.numero_guia_despacho}.`);
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
            dialog.querySelector('[data-guide-id]')?.focus();
        },
    };
}
