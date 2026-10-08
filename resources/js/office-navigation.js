import './office-material-replenishment-indicator';
import './office-material-inventory-actions.js';
import { installOfficeUnexpectedErrorNotice } from './shared/office-unexpected-error.js';
import { initializeOfficeShell, refreshOfficeShell } from './office-shell.js';
import {
    nextOfficeTheme,
    normalizeOfficeTheme,
    OFFICE_THEME_KEY,
    OFFICE_THEMES,
} from './shared/office-preferences.js';

const tokenKey = 'estiba_wms_office_token';
installOfficeUnexpectedErrorNotice();
const identityKey = 'estiba_wms_office_identity';
const lastDomainKey = 'estiba_wms_last_domain';
const moduleAliases = {
    'administracion.maestros-temporada': ['frigorifico.catalogos'],
};

function storedOfficeTheme() {
    try { return normalizeOfficeTheme(localStorage.getItem(OFFICE_THEME_KEY)); } catch { return normalizeOfficeTheme(null); }
}

let officeTheme = storedOfficeTheme();

function refreshOfficeThemeControls() {
    const dark = officeTheme === 'dark-industrial';
    const action = dark ? 'Activar modo claro' : 'Activar modo oscuro';
    document.querySelectorAll('[data-office-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(dark));
        button.setAttribute('aria-label', action);
        button.title = action;
        const label = button.querySelector('[data-office-theme-label]');
        if (label) label.textContent = dark ? 'Modo claro' : 'Modo oscuro';
    });
}

function applyOfficeTheme(theme, { persist = true } = {}) {
    officeTheme = normalizeOfficeTheme(theme);
    document.documentElement.dataset.officeTheme = officeTheme;
    document.querySelector('meta[name="color-scheme"]')?.setAttribute(
        'content',
        officeTheme === 'dark-industrial' ? 'dark' : 'light',
    );
    document.querySelector('meta[name="theme-color"]')?.setAttribute(
        'content',
        officeTheme === 'dark-industrial' ? '#0f1c24' : '#102f43',
    );
    if (persist) {
        try { localStorage.setItem(OFFICE_THEME_KEY, officeTheme); } catch { /* Preferencia opcional. */ }
    }
    refreshOfficeThemeControls();
    return officeTheme;
}

applyOfficeTheme(officeTheme, { persist: false });

if (window.location.pathname.startsWith('/oficina/romana')) {
    import('./office-weighbridge-drawer.js').catch((error) => {
        console.error('No fue posible cargar el panel lateral de Romana.', error);
    });
}

function readIdentity() {
    try {
        return JSON.parse(localStorage.getItem(identityKey) || 'null');
    } catch {
        return null;
    }
}

function capabilities(identity) {
    return {
        ...(identity?.capacidades || {}),
        ...(identity || {}),
    };
}

function can(identity, permission) {
    if (!identity || !permission) return false;
    if (identity.rol === 'administrador') return true;

    const values = capabilities(identity);
    if (permission === 'ambito_camaras_productos') {
        return ['productos', 'ambos'].includes(values.ambito_camaras);
    }

    return values[permission] === true;
}

function permissionsFrom(value) {
    return String(value || '')
        .split(',')
        .map((permission) => permission.trim())
        .filter(Boolean);
}

function hasModule(identity, module) {
    if (!module) return true;
    const modules = capabilities(identity).modulos_acceso;
    if (!Array.isArray(modules)) return true;

    return modules.includes(module)
        || (moduleAliases[module] || []).some((alias) => modules.includes(alias));
}

function hasAnyPermission(identity, permissions) {
    return permissions.some((permission) => can(identity, permission));
}

function setVisibility(element, visible) {
    element.classList.toggle('is-hidden', !visible);
    element.setAttribute('aria-hidden', String(!visible));
    if (visible) element.removeAttribute('tabindex');
    else element.setAttribute('tabindex', '-1');
}

function domainTargets(link) {
    try {
        const targets = JSON.parse(link.dataset.navigationTargets || '[]');

        return Array.isArray(targets) ? targets : [];
    } catch {
        return [];
    }
}

function firstAccessibleTarget(identity, targets) {
    return targets.find((target) => (
        hasModule(identity, target.module)
        && hasAnyPermission(identity, target.permissions || [])
    )) || null;
}

function redirectFromUnavailableOffice(identity) {
    const header = document.querySelector('[data-office-shell-header]');
    const activeOffice = header?.dataset.activeOffice;
    const activeDomain = header?.dataset.activeDomain;
    if (!activeOffice || !activeDomain) return;

    const activeLink = document.querySelector(
        `[data-office-domain="${CSS.escape(activeDomain)}"][data-office-key="${CSS.escape(activeOffice)}"]`,
    );
    if (activeLink && !activeLink.classList.contains('is-hidden')) return;

    const activeDomainLink = document.querySelector(
        `[data-domain-key="${CSS.escape(activeDomain)}"]`,
    );
    const activeTarget = activeDomainLink
        ? firstAccessibleTarget(identity, domainTargets(activeDomainLink))
        : null;
    const fallback = activeTarget || [...document.querySelectorAll('[data-domain-key]')]
        .map((link) => firstAccessibleTarget(identity, domainTargets(link)))
        .find(Boolean);
    if (!fallback) return;

    const destination = new URL(fallback.href, window.location.origin);
    const current = new URL(window.location.href);
    if (destination.pathname === current.pathname && destination.hash === current.hash) return;
    window.location.replace(destination.href);
}

// Pantalla de inicio de cada rol. Los perfiles son configurables: si el perfil no
// habilita esa oficina, se usa la primera oficina accesible.
const roleHomes = {
    administrador: '/oficina/operacion-ahora',
    supervisor_frio: '/oficina/operacion-ahora',
    despachador: '/oficina/frigorifico/despacho/cargas',
    operador_prefrio: '/oficina/prefrio',
    operador_romana: '/oficina/romana',
    digitador_materia_prima: '/oficina/materia-prima/lotes',
    validador_mp: '/oficina/materia-prima',
    supervisor_materiales: '/oficina/materiales',
    camarero_materiales: '/oficina/materiales',
    validador: '/oficina/validacion',
    camarero_frio: '/oficina/frigorifico/camaras',
    tarjador: '/oficina/validacion/repaletizajes',
    consulta: '/oficina/consultas',
};

export function officeHomeFor(identity, domainLinks = [...document.querySelectorAll('[data-domain-key]')]) {
    if (!identity) return null;
    const targets = domainLinks.flatMap((link) => domainTargets(link));
    const accessible = (target) => hasModule(identity, target.module)
        && hasAnyPermission(identity, target.permissions || []);
    const preferred = roleHomes[identity.rol];
    const home = targets.find((target) => target.href === preferred && accessible(target))
        || domainLinks.map((link) => firstAccessibleTarget(identity, domainTargets(link))).find(Boolean);

    return home?.href || null;
}

function refreshHome(identity, hasSession) {
    const home = hasSession ? officeHomeFor(identity) : null;
    document.querySelectorAll('[data-office-home]').forEach((link) => {
        link.href = home || '/oficina';
    });
    if (!home || !document.querySelector('[data-office-home-redirect]')) return;
    const destination = new URL(home, window.location.origin);
    if (destination.pathname !== window.location.pathname) window.location.replace(destination.href);
}

function refreshNavigation() {
    const identity = readIdentity();
    const hasSession = Boolean(localStorage.getItem(tokenKey) && identity);
    const profileName = identity?.perfil_acceso?.nombre
        || identity?.capacidades?.perfil_acceso?.nombre;
    const roleLabel = document.getElementById('officeUserRole');
    if (hasSession && profileName && roleLabel) roleLabel.textContent = profileName;

    document.querySelectorAll('[data-office-key]').forEach((link) => {
        const permissions = permissionsFrom(link.dataset.navigationPermissions);
        setVisibility(
            link,
            hasSession
                && hasModule(identity, link.dataset.navigationModule)
                && hasAnyPermission(identity, permissions),
        );
    });

    document.querySelectorAll('[data-navigation-permissions]:not([data-office-key])').forEach((element) => {
        const permissions = permissionsFrom(element.dataset.navigationPermissions);
        setVisibility(
            element,
            hasSession
                && hasModule(identity, element.dataset.navigationModule)
                && hasAnyPermission(identity, permissions),
        );
    });

    document.querySelectorAll('[data-domain-key]').forEach((link) => {
        const target = hasSession
            ? firstAccessibleTarget(identity, domainTargets(link))
            : null;
        setVisibility(link, Boolean(target));
        if (target) link.href = target.href;
    });

    const activeDomain = document.querySelector('[data-office-shell-header]')?.dataset.activeDomain;
    if (hasSession && activeDomain) localStorage.setItem(lastDomainKey, activeDomain);
    if (hasSession) redirectFromUnavailableOffice(identity);
    refreshHome(identity, hasSession);
    refreshOfficeShell(identity, hasSession);
}

const panelStoragePrefix = 'estiba_wms_office_panel:';

function panelStorageKey(group) {
    return `${panelStoragePrefix}${group}`;
}

function storedOfficePanel(group) {
    try {
        return sessionStorage.getItem(panelStorageKey(group));
    } catch {
        return null;
    }
}

function officePanelData(switcher) {
    const group = switcher.dataset.officePanelSwitcher;
    const tabs = [...switcher.querySelectorAll('[data-office-panel-target]')];
    const panels = [...document.querySelectorAll(
        `[data-office-panel-group="${CSS.escape(group)}"][data-office-panel-id]`,
    )];

    return { group, tabs, panels };
}

function selectOfficePanel(switcher, requestedPanel, { focus = false, persist = true } = {}) {
    const { group, tabs, panels } = officePanelData(switcher);
    if (!group || !tabs.length || !panels.length) return null;

    const availableTabs = tabs.filter((tab) => !tab.classList.contains('is-hidden'));
    const defaultPanel = switcher.dataset.defaultPanel;
    const panelIds = new Set(panels.map((panel) => panel.dataset.officePanelId));
    const selectedPanel = [requestedPanel, defaultPanel, availableTabs[0]?.dataset.officePanelTarget]
        .find((panelId) => panelId && panelIds.has(panelId)
            && availableTabs.some((tab) => tab.dataset.officePanelTarget === panelId));

    if (!selectedPanel) return null;

    tabs.forEach((tab) => {
        const selected = tab.dataset.officePanelTarget === selectedPanel;
        tab.classList.toggle('is-active', selected);
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
        if (selected && focus) tab.focus();
    });

    panels.forEach((panel) => {
        panel.hidden = panel.dataset.officePanelId !== selectedPanel;
    });

    if (persist) {
        try {
            sessionStorage.setItem(panelStorageKey(group), selectedPanel);
        } catch {
            // La navegación sigue operativa aunque el navegador no permita persistencia.
        }
    }

    document.dispatchEvent(new CustomEvent('estiba:office-panel-change', {
        detail: { group, panel: selectedPanel },
    }));

    return selectedPanel;
}

function initializeOfficePanelSwitchers() {
    document.querySelectorAll('[data-office-panel-switcher]').forEach((switcher) => {
        const { group, tabs } = officePanelData(switcher);
        selectOfficePanel(switcher, storedOfficePanel(group), { persist: false });

        switcher.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-office-panel-target]');
            if (!tab || !switcher.contains(tab)) return;
            selectOfficePanel(switcher, tab.dataset.officePanelTarget);
        });

        switcher.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

            const availableTabs = tabs.filter((tab) => !tab.classList.contains('is-hidden'));
            const activeIndex = availableTabs.indexOf(document.activeElement);
            if (activeIndex < 0 || !availableTabs.length) return;

            event.preventDefault();
            let nextIndex = activeIndex;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = availableTabs.length - 1;
            if (event.key === 'ArrowLeft') nextIndex = (activeIndex - 1 + availableTabs.length) % availableTabs.length;
            if (event.key === 'ArrowRight') nextIndex = (activeIndex + 1) % availableTabs.length;

            const nextTab = availableTabs[nextIndex];
            selectOfficePanel(switcher, nextTab.dataset.officePanelTarget, { focus: true });
        });
    });
}

function scrollToOfficeTarget() {
    if (!location.hash) return;
    const id = decodeURIComponent(location.hash.slice(1));
    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts += 1;
        const target = document.getElementById(id);
        if (target) {
            const panel = target.closest('[data-office-panel-group][data-office-panel-id]');
            if (panel?.hidden) {
                const switcher = document.querySelector(
                    `[data-office-panel-switcher="${CSS.escape(panel.dataset.officePanelGroup)}"]`,
                );
                if (switcher) selectOfficePanel(switcher, panel.dataset.officePanelId);
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.classList.add('office-navigation-target');
            window.setTimeout(() => target.classList.remove('office-navigation-target'), 1800);
            window.clearInterval(timer);
        } else if (attempts >= 30) {
            window.clearInterval(timer);
        }
    }, 150);
}

function observeApplication() {
    const app = document.getElementById('officeApp');
    if (!app) return;
    new MutationObserver(() => refreshNavigation()).observe(app, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

const officeActionHostSelector = [
    '[data-office-action-menu]',
    '.camera-item__actions',
    '.admin-season-actions',
    '#accessProfilesTableBody td:last-child',
    '.material-reception-actions',
    '.material-row',
    '.dispatch-row__state',
    '.materials-order-actions',
    '#validationHistoryBody td:last-child',
    '.validation-row',
    '.validation-row__actions',
    '.annulment-card',
    '.repa-history-card',
    '.tunnel-card__footer',
    '.inventory-card__actions',
    '.folio-action-cell',
    '.incident-card',
    '.container-weighing-row',
    '.reservation-item',
    '.table-actions',
    '.guide-actions',
    '.segment-card__actions',
    '.lot-actions',
    '.process-actions',
    '.process-delivery-actions',
    '.bin-card__actions',
    '.legacy-card__actions',
    '.producer-actions',
    '.result-card',
].join(', ');

function officeActionElements(container) {
    return [...container.children].filter((element) => element.matches('button, a[href]'));
}

function isVisibleOfficeAction(action) {
    return !action.hidden
        && !action.classList.contains('is-hidden')
        && action.getAttribute('aria-hidden') !== 'true';
}

function officeActionItems(host) {
    const sources = host.querySelector(':scope > .office-action-sources');

    return sources
        ? officeActionElements(sources).filter(isVisibleOfficeAction)
        : officeActionElements(host).filter(isVisibleOfficeAction);
}

function actionOptionLabel(action) {
    return action.dataset.actionLabel
        || action.getAttribute('aria-label')
        || action.textContent.replace(/\s+/g, ' ').trim()
        || 'Ejecutar acción';
}

function createOfficeActionSelect(host) {
    const select = document.createElement('select');
    select.className = 'office-action-select';
    select.setAttribute('aria-label', 'Seleccionar acción');

    ['pointerdown', 'mousedown', 'touchstart', 'click', 'input', 'keydown'].forEach((eventName) => {
        select.addEventListener(eventName, (event) => event.stopPropagation());
    });

    select.addEventListener('change', (event) => {
        event.stopPropagation();
        const selectedIndex = Number(select.value);
        const actions = officeActionItems(host);
        const action = actions[selectedIndex];
        select.value = '';

        if (!action || action.disabled || action.getAttribute('aria-disabled') === 'true') return;
        action.click();
    });

    return select;
}

function syncOfficeActionSelect(host, select) {
    const actions = officeActionItems(host);
    const signature = JSON.stringify(actions.map((action) => ({
        label: actionOptionLabel(action),
        disabled: action.disabled || action.getAttribute('aria-disabled') === 'true',
    })));

    if (select.dataset.officeActionSignature === signature) return;
    select.dataset.officeActionSignature = signature;
    select.replaceChildren();

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = actions.length ? 'Seleccionar acción' : 'Sin acciones disponibles';
    select.append(placeholder);

    actions.forEach((action, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = actionOptionLabel(action);
        option.disabled = action.disabled || action.getAttribute('aria-disabled') === 'true';
        select.append(option);
    });

    select.disabled = actions.length === 0 || actions.every((action) => (
        action.disabled || action.getAttribute('aria-disabled') === 'true'
    ));
}

function upgradeOfficeActionHost(host) {
    let sources = host.querySelector(':scope > .office-action-sources');
    const pendingActions = officeActionElements(host);

    if (!sources && !pendingActions.some(isVisibleOfficeAction)) return;

    if (!sources) {
        sources = document.createElement('span');
        sources.className = 'office-action-sources';
        sources.hidden = true;
        host.append(sources);
    }
    if (pendingActions.length) sources.append(...pendingActions);

    let select = host.querySelector(':scope > .office-action-select');
    if (!select) {
        select = createOfficeActionSelect(host);
        host.insertBefore(select, sources);
    }

    host.dataset.officeActionMenuReady = 'true';
    syncOfficeActionSelect(host, select);
}

function upgradeOfficeActionMenus(root = document) {
    const hosts = [];

    if (root instanceof Element && root.matches(officeActionHostSelector)) hosts.push(root);
    if (root.querySelectorAll) hosts.push(...root.querySelectorAll(officeActionHostSelector));

    [...new Set(hosts)].forEach(upgradeOfficeActionHost);
}

function initializeOfficeActionMenus() {
    upgradeOfficeActionMenus();

    let scheduled = false;
    new MutationObserver(() => {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(() => {
            scheduled = false;
            upgradeOfficeActionMenus();
        });
    }).observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: [
            'class',
            'disabled',
            'hidden',
            'aria-disabled',
            'aria-hidden',
            'aria-label',
            'data-action-label',
        ],
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeOfficeShell();
    refreshOfficeThemeControls();
    document.querySelectorAll('[data-office-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => applyOfficeTheme(nextOfficeTheme(officeTheme)));
    });
    refreshNavigation();
    observeApplication();
    initializeOfficePanelSwitchers();
    initializeOfficeActionMenus();
    scrollToOfficeTarget();

    document.querySelectorAll('[data-domain-key]').forEach((link) => {
        link.addEventListener('click', () => localStorage.setItem(lastDomainKey, link.dataset.domainKey));
    });
});

window.addEventListener('storage', (event) => {
    if (event.key === OFFICE_THEME_KEY) applyOfficeTheme(event.newValue, { persist: false });
    refreshNavigation();
});
window.addEventListener('estiba:office-session', refreshNavigation);

window.EstibaOfficeTheme = {
    apply: (theme) => applyOfficeTheme(theme),
    current: () => officeTheme,
    themes: [...OFFICE_THEMES],
};
window.EstibaOfficeNavigation = { refresh: refreshNavigation };
