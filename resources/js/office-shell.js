// Cabecera y menú compartidos; no intervienen en las operaciones de cada módulo.
import {
    isOfficeMenuCollapsed,
    OFFICE_MENU_KEY,
    officeMenuPreference,
} from './shared/office-preferences.js';

const tokenKey = 'estiba_wms_office_token';
let contextToken = null;
let controller = null;
let generation = 0;
let initialized = false;

const shell = () => document.querySelector('[data-office-shell]');
const field = (name) => shell()?.querySelector(`[data-office-${name}]`);
function token() {
    try { return localStorage.getItem(tokenKey); } catch { return null; }
}
function text(name, value) {
    const element = field(name);
    if (element) element.textContent = value;
}

function contextState(label, detail, tone = 'neutral') {
    text('context-label', label);
    text('context-status', detail);
    const status = field('context-refresh');
    if (status) status.dataset.tone = tone;
}

async function loadContext() {
    if (!shell()) return;
    const currentToken = token();
    if (!currentToken) return;
    controller?.abort();
    controller = new AbortController();
    const requestController = controller;
    const currentGeneration = ++generation;
    const button = field('context-refresh');
    button.disabled = true;
    contextState('Verificando contexto', 'Consultando temporada y planta…', 'neutral');
    const timeout = setTimeout(() => requestController.abort(), 10000);
    try {
        const response = await fetch('/api/oficina/contexto', {
            headers: { Accept: 'application/json', Authorization: `Bearer ${currentToken}` },
            cache: 'no-store', signal: requestController.signal,
        });
        if (!response.ok) throw new Error(response.status === 401 ? 'Sesión vencida. Vuelve a ingresar.' : 'No se pudo verificar el contexto.');
        const { data } = await response.json();
        if (!data || !Object.hasOwn(data, 'temporada') || !Object.hasOwn(data, 'planta')
            || (data.temporada !== null && typeof data.temporada?.codigo !== 'string')
            || (data.planta !== null && typeof data.planta !== 'string')) throw new Error('Respuesta de contexto incompleta.');
        if (currentGeneration !== generation || currentToken !== token()) return;
        text('plant', data.planta || 'Sin configurar');
        text('season', data.temporada?.codigo || 'Sin temporada activa');
        renderNotices([...(Array.isArray(data.avisos_cierre) ? data.avisos_cierre : []), ...(Array.isArray(data.avisos_materiales) ? data.avisos_materiales : [])]);
        contextState(
            'Contexto verificado',
            `Actualizado ${new Date().toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}`,
            'success',
        );
    } catch (error) {
        if (currentGeneration !== generation || currentToken !== token()) return;
        text('plant', 'Sin verificar');
        text('season', 'Sin verificar');
        contextState(
            'Contexto no verificado',
            error.name === 'AbortError' ? 'La consulta tardó demasiado' : error.message,
            'critical',
        );
    } finally {
        clearTimeout(timeout);
        if (currentGeneration === generation) button.disabled = !token();
    }
}

function escapeText(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]);
}

// Avisos de cierre de temporada: el administrador los envía desde Accesos.
function renderNotices(notices) {
    const region = field('notices');
    if (!region) return;
    region.innerHTML = notices.map((notice) => `
        <div class="estiba-office-notice" data-notice-id="${escapeText(notice.id)}">
            <strong>${escapeText(notice.titulo)}</strong>
            <p>${escapeText(notice.mensaje)}</p>
            <button type="button" data-read-notice="${escapeText(notice.id)}">Entendido</button>
        </div>`).join('');
    region.hidden = notices.length === 0;
}

async function readNotice(id) {
    const currentToken = token();
    if (!currentToken) return;
    const notice = field('notices')?.querySelector(`[data-notice-id="${CSS.escape(id)}"]`);
    try {
        const response = await fetch(`/api/notificaciones-operacionales/${encodeURIComponent(id)}/leer`, {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${currentToken}` },
        });
        if (!response.ok) return;
        notice?.remove();
        const region = field('notices');
        if (region && !region.children.length) region.hidden = true;
    } catch { /* Se reintenta al actualizar el contexto. */ }
}

export function refreshOfficeShell(identity, hasSession) {
    if (!shell()) return;
    const readonly = field('readonly');
    if (readonly) readonly.hidden = !hasSession || !(identity?.solo_consulta || identity?.capacidades?.solo_consulta);
    const currentToken = hasSession ? token() : null;
    if (!currentToken) {
        contextToken = null;
        generation += 1;
        controller?.abort();
        text('plant', 'Sin consultar');
        text('season', 'Sin consultar');
        renderNotices([]);
        contextState('Contexto sin consultar', 'Inicia sesión para verificarlo', 'neutral');
        field('context-refresh').disabled = true;
    } else if (contextToken !== currentToken) {
        contextToken = currentToken;
        void loadContext();
    }
}

export function initializeOfficeShell() {
    const root = shell();
    if (!root || initialized) return;
    initialized = true;
    const header = root.querySelector('[data-office-shell-header]');
    const menu = field('menu');
    const menuLabel = field('menu-label');
    const desktop = window.matchMedia('(min-width: 1200px)');
    try {
        root.toggleAttribute('data-menu-collapsed', isOfficeMenuCollapsed(localStorage.getItem(OFFICE_MENU_KEY)));
    } catch {
        root.removeAttribute('data-menu-collapsed');
    }
    root.dataset.menuReady = '';
    function syncMenu() {
        const expanded = desktop.matches
            ? !root.hasAttribute('data-menu-collapsed')
            : root.hasAttribute('data-menu-open');
        const action = expanded ? 'Ocultar menú lateral' : 'Mostrar menú lateral';
        menu.setAttribute('aria-expanded', String(expanded));
        menu.setAttribute('aria-label', action);
        menu.title = action;
        if (menuLabel) menuLabel.textContent = desktop.matches ? 'Menú' : (expanded ? 'Cerrar' : 'Menú');
    }
    function closeMobileMenu() {
        root.removeAttribute('data-menu-open');
        syncMenu();
    }
    menu.addEventListener('click', () => {
        if (desktop.matches) {
            const collapsed = !root.hasAttribute('data-menu-collapsed');
            root.toggleAttribute('data-menu-collapsed', collapsed);
            try { localStorage.setItem(OFFICE_MENU_KEY, officeMenuPreference(collapsed)); } catch { /* Preferencia opcional. */ }
        } else {
            root.toggleAttribute('data-menu-open');
        }
        syncMenu();
    });
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !desktop.matches && root.hasAttribute('data-menu-open')) {
            event.preventDefault();
            closeMobileMenu();
            menu.focus();
        }
    });
    desktop.addEventListener('change', closeMobileMenu);
    syncMenu();
    const content = [...root.parentElement.children].find(element => element !== root && !['LINK', 'SCRIPT', 'STYLE'].includes(element.tagName));
    if (content) {
        if (!content.id) content.id = 'officeContent';
        const skip = field('skip');
        skip.href = `#${content.id}`;
        skip.addEventListener('click', () => {
            if (!desktop.matches) closeMobileMenu();
            if (!content.hasAttribute('tabindex')) content.setAttribute('tabindex', '-1');
            content.focus({ preventScroll: true });
        });
    }
    const size = () => {
        const height = header.getBoundingClientRect().height;
        if (height > 0) root.parentElement.style.setProperty('--office-header-height', `${Math.ceil(height)}px`);
    };
    new ResizeObserver(size).observe(header);
    size();
    field('context-refresh').addEventListener('click', () => void loadContext());
    field('notices')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-read-notice]');
        if (button) void readNotice(button.dataset.readNotice);
    });
    window.addEventListener('offline', () => contextState('Sin conexión', 'El contexto puede estar desactualizado', 'critical'));
    window.addEventListener('online', () => contextState('Conexión recuperada', 'Actualiza para verificar el contexto', 'neutral'));
}
