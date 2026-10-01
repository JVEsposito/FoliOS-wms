const waitingReasons = {
    sin_destino_compatible: 'Sin espacio compatible en otras cámaras. Libera espacio y actualiza la vista.',
    sin_pallet_accesible: 'No hay un pallet accesible para trasladar.',
    retencion_prioritaria: 'Hay pallets retenidos pendientes de resolución.',
    inspeccion_sag_activa: 'Hay pallets con inspección SAG activa.',
    trabajo_prioritario_activo: 'Hay trabajos prioritarios pendientes.',
    labor_activa_previa: 'Existe otra labor activa sobre el pallet.',
    maniobra_en_ejecucion: 'Traslado en ejecución por el camarero.',
};

export function mountCameraVacating({ root, currentCamera, identity, api, refresh, notify }) {
    const el = Object.fromEntries(['Section', 'Status', 'Start', 'Cancel', 'Dialog', 'Form', 'Title', 'Help', 'Confirmation', 'Error', 'Submit', 'Close']
        .map((name) => [name, root.getElementById(`cameraVacating${name}`)]));
    if (!el.Section) return { render() {} };
    let pending = false;
    let captured = null;
    const supervisor = () => identity()?.puede_supervisar_camaras_productos === true;

    function render(camera) {
        const plan = camera.desocupacion;
        el.Start.hidden = !supervisor() || !!plan?.activa || !!camera.emergencia;
        el.Start.disabled = !camera.desocupacion_habilitada || pending;
        el.Cancel.hidden = !supervisor() || !plan?.activa;
        el.Cancel.disabled = pending;
        const states = {
            publicada: 'Traslado disponible para el camarero',
            en_ejecucion: 'Vaciado en ejecución',
            pendiente: 'Vaciado en espera',
            completada: 'Cámara vacía; lista para apagar',
            cancelada: 'Vaciado cancelado',
            shadow: 'Simulación: no se ejecutan traslados',
        };
        const progress = plan ? `${plan.pallets_evacuados} de ${plan.pallets_objetivo} pallets trasladados · ${plan.porcentaje_actual} % · ${plan.pallets_restantes} restantes.` : '';
        el.Status.textContent = [
            plan ? (states[plan.estado] || 'Desocupación programada') : 'Sin desocupación programada.',
            plan?.motivo,
            progress,
            waitingReasons[plan?.motivo_pendiente] || (plan?.motivo_pendiente ? 'El planificador espera que se resuelva un bloqueo operacional.' : ''),
            !camera.desocupacion_habilitada ? 'El planificador no está habilitado para ejecutar el vaciado de esta cámara.' : '',
            camera.emergencia ? 'La cámara tiene una evacuación de emergencia activa.' : '',
        ].filter(Boolean).join(' ');
    }

    function open(action) {
        const camera = currentCamera();
        if (pending || !camera || !supervisor()) return;
        if (action === 'iniciar' && (!camera.desocupacion_habilitada || camera.desocupacion?.activa || camera.emergencia)) return;
        if (action === 'cancelar' && !camera.desocupacion?.activa) return;
        captured = { id: camera.id, codigo: camera.codigo, planId: camera.desocupacion?.id, action };
        el.Form.reset();
        el.Error.textContent = '';
        el.Title.textContent = action === 'iniciar' ? `Vaciar ${camera.codigo}` : `Cancelar vaciado de ${camera.codigo}`;
        el.Help.textContent = action === 'iniciar'
            ? 'Se bloquearán nuevos ingresos. El planificador buscará espacio compatible en otras cámaras y los camareros ejecutarán los traslados.'
            : 'Los traslados ya completados se conservarán. Una maniobra en curso puede impedir la cancelación.';
        el.Confirmation.hidden = action !== 'iniciar';
        el.Form.elements.confirmacion.required = action === 'iniciar';
        el.Submit.textContent = action === 'iniciar' ? 'Iniciar vaciado' : 'Cancelar vaciado';
        el.Dialog.showModal();
    }

    el.Start.addEventListener('click', () => open('iniciar'));
    el.Cancel.addEventListener('click', () => open('cancelar'));
    el.Close.addEventListener('click', () => { if (!pending) el.Dialog.close(); });
    el.Dialog.addEventListener('cancel', (event) => { if (pending) event.preventDefault(); });
    el.Form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending || !captured || !supervisor()) return;
        const camera = currentCamera();
        if (!camera || camera.id !== captured.id || camera.desocupacion?.id !== captured.planId) {
            el.Error.textContent = 'La cámara o su plan cambió. Cierra el diálogo y vuelve a abrirlo.';
            return;
        }
        const motivo = el.Form.elements.motivo.value.trim();
        if (motivo.length < 3 || motivo.length > 500) {
            el.Error.textContent = 'Ingresa un motivo de entre 3 y 500 caracteres.';
            return;
        }
        if (captured.action === 'iniciar' && el.Form.elements.confirmacion.value !== captured.codigo) {
            el.Error.textContent = `Escribe exactamente ${captured.codigo} para iniciar el vaciado.`;
            return;
        }
        const { id, action } = captured;
        pending = true;
        el.Submit.disabled = true;
        el.Close.disabled = true;
        try {
            await api(`/api/desocupaciones-camara/${encodeURIComponent(id)}${action === 'cancelar' ? '/cancelar' : ''}`, {
                method: 'POST', body: JSON.stringify({ motivo }),
            });
            captured = null;
            el.Dialog.close();
            notify(action === 'iniciar' ? 'Desocupación programada iniciada.' : 'Desocupación cancelada.');
            if (currentCamera()?.id === id) {
                try { await refresh(id); } catch (error) { notify(`La operación fue registrada. Actualiza la vista: ${error.message}`, true); }
            }
        } catch (error) {
            el.Error.textContent = error.message;
        } finally {
            pending = false;
            el.Submit.disabled = false;
            el.Close.disabled = false;
            if (currentCamera()) render(currentCamera());
        }
    });
    return { render };
}
