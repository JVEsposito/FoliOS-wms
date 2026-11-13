import { createOperationalPoller } from './shared/operational-poller.js';
import { communicationsSession, createCommunicationsClient } from './shared/communications-client.js';

export function initializeCommunications() {
    const button = document.querySelector('[data-communications-open]');
    const dialog = document.getElementById('communicationsDialog');
    if (!button || !dialog || button.dataset.ready) return;
    button.dataset.ready = 'true';
    const api = createCommunicationsClient();
    const badge = button.querySelector('[data-communications-badge]');
    let session = null;
    let app = null;
    let opening = false;
    async function summary() {
        if (!communicationsSession()) return;
        try {
            const { data } = await api.request('/api/comunicaciones/resumen');
            const mail = Number(data?.correos_no_leidos || 0);
            const mentions = Number(data?.menciones_sin_leer || 0);
            const chat = data?.chat_no_leido === true;
            const mailText = mail > 99 ? '99+' : String(mail);
            badge.hidden = !chat && mail === 0 && mentions === 0;
            badge.textContent = mentions > 0 ? (mail > 0 ? `@ ${mailText}` : '@') : mail > 0 ? mailText : '•';
            badge.dataset.mention = String(mentions > 0);
            button.setAttribute('aria-label', `Abrir chat y correo${mentions ? `, te mencionaron ${mentions === 1 ? 'una vez' : `${mentions} veces`}` : ''}${chat ? ', mensajes de chat nuevos' : ''}${mail ? `, ${mail} correos no leídos` : ''}`);
        } catch { /* Una comunicación no interrumpe la operación de la oficina. */ }
    }
    const poller = createOperationalPoller(summary, { intervalMs: 30000, canRun: () => Boolean(communicationsSession()) });
    function sync() {
        const current = communicationsSession();
        if (session?.token !== current?.token || session?.id !== current?.id) {
            api.reset(); app?.reset(); session = current; badge.hidden = true;
            poller.stop(); if (current) poller.start({ immediate: true });
        }
        button.hidden = !current;
    }
    button.addEventListener('click', async () => {
        if (opening || !communicationsSession()) return;
        opening = true; button.disabled = true;
        try {
            const module = await import('./office-communications.js');
            if (!communicationsSession()) return;
            app ??= module.createCommunicationsApp(dialog, api, () => { void poller.runNow(); });
            await app.open();
        } catch {
            button.title = 'No se pudo abrir. Vuelve a intentarlo.';
        } finally { opening = false; button.disabled = false; }
    });
    window.addEventListener('estiba:office-session', sync);
    window.addEventListener('storage', event => { if (['estiba_wms_office_token', 'estiba_wms_office_identity', null].includes(event.key)) sync(); });
    window.addEventListener('pagehide', () => { api.reset(); app?.reset(); poller.stop(); });
    window.addEventListener('pageshow', event => { if (event.persisted) { session = null; sync(); } });
    sync();
}
