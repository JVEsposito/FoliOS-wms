// Pestañas de Operación ahora. Cada pestaña responde una sola pregunta de supervisión
// y evita recorrer una tira larga donde otras vistas interfieren con la que se mira.
// La pestaña vive en la dirección (#plano) para compartirla o volver a ella, y se
// recuerda en el equipo. Una alerta nueva nunca cambia la pestaña: solo su insignia.

export const OPERATION_TABS = Object.freeze(['atencion', 'maniobras', 'plano', 'camaras', 'prefrio', 'personal']);
export const DEFAULT_OPERATION_TAB = 'atencion';
export const OPERATION_TAB_STORAGE_KEY = 'folios_operacion_ahora_pestana';

export function tabFromHash(hash) {
    let key = String(hash ?? '').replace(/^#/, '');
    try {
        key = decodeURIComponent(key);
    } catch {
        return null;
    }
    key = key.trim().toLowerCase();
    return OPERATION_TABS.includes(key) ? key : null;
}

/** La dirección manda; si no trae pestaña, se vuelve a la última vista del equipo. */
export function resolveInitialTab(hash, remembered) {
    return tabFromHash(hash)
        ?? (OPERATION_TABS.includes(remembered) ? remembered : DEFAULT_OPERATION_TAB);
}

/** Navegación de teclado del patrón de pestañas (flechas, Inicio y Fin). */
export function tabFromKey(current, key, tabs = OPERATION_TABS) {
    const index = Math.max(0, tabs.indexOf(current));
    if (key === 'Home') return tabs[0];
    if (key === 'End') return tabs[tabs.length - 1];
    if (key === 'ArrowRight') return tabs[(index + 1) % tabs.length];
    if (key === 'ArrowLeft') return tabs[(index - 1 + tabs.length) % tabs.length];
    return null;
}

function readRemembered(storage) {
    try {
        return storage?.getItem(OPERATION_TAB_STORAGE_KEY) ?? null;
    } catch {
        return null;
    }
}

function remember(storage, tab) {
    try {
        storage?.setItem(OPERATION_TAB_STORAGE_KEY, tab);
    } catch {
        // Sin almacenamiento local la pestaña sigue en la dirección.
    }
}

/**
 * Conecta botones [data-operation-tab] con secciones [data-operation-panel].
 * `activate` solo responde a la persona (clic, teclado, enlace o dirección): el
 * refresco de datos no lo llama, así la vista no salta mientras se lee.
 */
export function createOperationTabs({
    root,
    storage = globalThis.localStorage,
    win = globalThis.window,
    onChange = () => {},
} = {}) {
    const buttons = new Map([...root.querySelectorAll('[data-operation-tab]')]
        .map((button) => [button.dataset.operationTab, button]));
    const panels = new Map([...root.querySelectorAll('[data-operation-panel]')]
        .map((panel) => [panel.dataset.operationPanel, panel]));
    const order = OPERATION_TABS.filter((tab) => buttons.has(tab));
    let current = null;

    function activate(tab, { focus = false, syncHash = true } = {}) {
        if (!buttons.has(tab)) return current;
        const changed = tab !== current;
        current = tab;

        buttons.forEach((button, key) => {
            const selected = key === tab;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
        });
        panels.forEach((panel, key) => {
            panel.hidden = key !== tab;
        });
        root.dataset.activeTab = tab;
        remember(storage, tab);

        if (syncHash && tabFromHash(win?.location?.hash) !== tab) {
            win?.history?.replaceState(win.history.state, '', `#${tab}`);
        }
        if (focus) buttons.get(tab).focus();
        if (changed) onChange(tab);

        return tab;
    }

    root.addEventListener('click', (event) => {
        const button = event.target.closest?.('[data-operation-tab]');
        if (button) activate(button.dataset.operationTab);
    });

    root.addEventListener('keydown', (event) => {
        if (!event.target.closest?.('[data-operation-tab]')) return;
        const next = tabFromKey(current, event.key, order);
        if (!next) return;
        event.preventDefault();
        activate(next, { focus: true });
    });

    win?.addEventListener('hashchange', () => {
        const tab = tabFromHash(win.location.hash);
        if (tab) activate(tab, { syncHash: false });
    });

    activate(resolveInitialTab(win?.location?.hash, readRemembered(storage)));

    return {
        activate,
        get current() {
            return current;
        },
    };
}
