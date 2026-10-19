import { createOperationalPoller } from './shared/operational-poller';
import { escapeGatehouse as esc, gatehouseDate } from './shared/gatehouse-logic';

export function createGatehouseRomana({ getToken }) {
    const panel = document.getElementById('romanaGatehouse');
    if (!panel) return { start() {}, stop() {} };
    let previous = null;
    let poller = null;
    async function refresh() {
        const token = getToken();
        if (!token) return;
        const response = await fetch('/api/romana/porteria/resumen', { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, cache: 'no-store' });
        if (!response.ok) throw new Error('No se pudo actualizar Portería.');
        const data = await response.json();
        if (token !== getToken()) return;
        panel.hidden = false;
        const fresh = previous !== null && Number(data.ultimo_ingreso_id) > previous;
        previous = Number(data.ultimo_ingreso_id);
        document.getElementById('romanaGatehouseCount').textContent = `${data.pendientes} ingresos pendientes`;
        document.getElementById('romanaGatehouseStatus').textContent = `${fresh ? 'Nueva llegada en Portería · ' : ''}Actualizado ${gatehouseDate(data.consultado_at, true)}`;
        document.getElementById('romanaGatehouseRecent').innerHTML = data.recientes.map((entry) => `<li><strong>${esc(entry.patente)}</strong> · ${esc(entry.cliente_nombre)} · ${esc(entry.guias.join(', ') || 'Sin guía')}</li>`).join('');
        panel.classList.toggle('gatehouse-arrival', fresh);
    }
    return {
        start() {
            poller?.stop();
            poller = createOperationalPoller(refresh, { intervalMs: 10000, canRun: () => Boolean(getToken()), onError: () => {
                document.getElementById('romanaGatehouseStatus').textContent = 'Portería sin actualizar. Revisar conexión; los pendientes se conservan.';
            } });
            poller.start({ immediate: true });
        },
        stop() { poller?.stop(); previous = null; },
    };
}
