import { officeReturnPath } from './folios-entry.js';
import { createOperationalPoller } from './operational-poller.js';

const format = new Intl.NumberFormat('es-CL');
const numeral = (count) => Number.isSafeInteger(count) && count >= 0 ? count : null;
const gajoNumber = (count) => count > 99 ? '99+' : String(count);

function sourceLink({ etiqueta, href, cantidad }, showCount) {
    const path = officeReturnPath(href);
    if (!path || typeof etiqueta !== 'string') return null;
    const row = document.createElement('li');
    const link = document.createElement('a');
    link.href = path;
    link.textContent = etiqueta;
    row.append(link);
    if (showCount) {
        const count = numeral(cantidad);
        const detail = document.createElement('span');
        detail.textContent = count === null ? 'Sin lectura' : format.format(count);
        row.append(detail);
    }
    return row;
}

export function createFoliosAttention(root = document) {
    const panels = root.querySelectorAll('[data-folios-atencion]');
    const badges = root.querySelectorAll('[data-office-attention-count]');
    let token = null;
    let generation = 0;
    let controller = null;

    function render(snapshot, status) {
        const total = numeral(snapshot?.total);
        for (const panel of panels) {
            panel.querySelector('[data-gajo-total]').textContent = total === null ? '—' : gajoNumber(total);
            const caption = panel.querySelector('[data-attention-status]');
            const label = status || (total === null ? 'Sin lectura' : total === 0 && !snapshot?.parcial
                ? 'Al día' : `${format.format(total)} ${total === 1 ? 'pendiente' : 'pendientes'}${snapshot?.parcial ? ' · Lectura parcial' : ''}`);
            if (caption.textContent !== label) caption.textContent = label;

            for (const area of panel.querySelectorAll('[data-attention-area]')) {
                const key = area.dataset.attentionArea;
                const info = snapshot?.areas?.[key];
                const count = numeral(info?.cantidad);
                const reference = Boolean(info?.referencia);
                const state = !info ? 'hidden' : reference ? 'reference' : count > 0 ? 'pending' : count === 0 && !info.parcial ? 'clear' : 'unknown';
                const leaf = panel.querySelector(`[data-gajo-leaf="${key}"]`);
                leaf.dataset.state = state;
                panel.querySelector(`[data-gajo-count="${key}"]`).textContent = count > 0 ? gajoNumber(count) : '';
                area.hidden = !info;
                area.querySelector('[data-attention-label]').textContent = {
                    pending: `${format.format(count)}${info?.parcial ? ' · Parcial' : ''}`,
                    clear: 'Al día',
                    unknown: info?.parcial ? 'Lectura parcial' : 'Sin lectura',
                    reference: 'Referencia',
                    hidden: '',
                }[state];
                const sources = reference ? [{ etiqueta: 'Abrir Consultas', href: info.referencia }] : info?.fuentes;
                const rows = Array.isArray(sources) ? sources.map((source) => sourceLink(source, !reference)).filter(Boolean) : [];
                const list = area.querySelector('[data-attention-sources]');
                if (list.textContent !== rows.map((row) => row.textContent).join('')
                    || [...list.querySelectorAll('a')].some((a, i) => a.href !== rows[i]?.querySelector('a').href)) {
                    list.replaceChildren(...rows);
                }
            }
        }

        for (const badge of badges) {
            const info = snapshot?.areas?.[badge.dataset.officeAttentionCount];
            const count = numeral(info?.cantidad);
            badge.hidden = !(count > 0);
            badge.textContent = count > 0 ? format.format(count) : '';
            badge.title = info?.parcial ? 'Pendientes · Lectura parcial' : 'Pendientes por atender';
        }
    }

    const poller = createOperationalPoller(async () => {
        const read = generation;
        const request = new AbortController();
        controller = request;
        const timeout = setTimeout(() => request.abort(), 10_000);
        try {
            const response = await fetch('/api/oficina/atencion', {
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
                signal: request.signal,
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Lectura de pendientes no disponible');
            const { data } = await response.json();
            if (!data || !Object.hasOwn(data, 'total')) throw new Error('Lectura de pendientes incompleta');
            if (read === generation) render(data);
        } catch (error) {
            if (read === generation) {
                render(null, 'Sin lectura vigente');
                throw error;
            }
        } finally {
            clearTimeout(timeout);
            if (controller === request) controller = null;
            if (read !== generation && token && !document.hidden && navigator.onLine !== false) {
                setTimeout(() => {
                    if (token && generation !== read) void poller.runNow();
                }, 0);
            }
        }
    }, { intervalMs: 90_000, canRun: () => Boolean(token) });

    if (panels.length || badges.length) {
        window.addEventListener('offline', () => {
            if (!token) return;
            controller?.abort();
            render(null, 'Sin lectura vigente');
        });
    }

    return {
        session(nextToken) {
            if ((!panels.length && !badges.length) || token === nextToken) return;
            token = nextToken;
            generation += 1;
            controller?.abort();
            poller.stop();
            render(null, token ? 'Consultando pendientes…' : '');
            if (token) poller.start({ immediate: true });
        },
        refresh() {
            if (token && navigator.onLine !== false && !document.hidden) void poller.runNow();
        },
    };
}
