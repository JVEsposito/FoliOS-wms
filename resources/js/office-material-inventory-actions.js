const bodySelector = '#materialsInventoryBody';
const actionSelector = [
    '[data-direct-dispatch]',
    '[data-correct-material]',
    '[data-correct-expiry]',
    '[data-block-material]',
    '[data-release-material]',
].join(', ');

let activeToggle = null;
let popover = null;
let inventoryObserver = null;

function actionPresentation(button) {
    if (button.matches('[data-direct-dispatch]')) return ['↗', 'Despachar directo', 'primary'];
    if (button.matches('[data-correct-expiry]')) return ['✎', 'Corregir vencimiento', 'neutral'];
    if (button.matches('[data-correct-material]')) return ['✎', 'Corregir código', 'neutral'];
    if (button.matches('[data-release-material]')) return ['✓', 'Liberar material', 'success'];
    return ['⊘', 'Bloquear material', 'danger'];
}

function ensurePopover() {
    if (popover) return popover;
    popover = document.createElement('div');
    popover.id = 'materialInventoryActionPopover';
    popover.className = 'material-inventory-action-popover';
    popover.setAttribute('role', 'menu');
    popover.hidden = true;
    document.body.append(popover);
    return popover;
}

function closeMenu({ focus = false } = {}) {
    if (!popover || popover.hidden) return;
    popover.hidden = true;
    popover.replaceChildren();
    activeToggle?.setAttribute('aria-expanded', 'false');
    if (focus) activeToggle?.focus();
    activeToggle = null;
}

function positionMenu(toggle) {
    const menu = ensurePopover();
    const rect = toggle.getBoundingClientRect();
    const gap = 6;
    const edge = 10;
    menu.style.minWidth = `${Math.max(210, Math.round(rect.width))}px`;
    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.hidden = false;
    const menuRect = menu.getBoundingClientRect();
    const left = Math.min(window.innerWidth - menuRect.width - edge, Math.max(edge, rect.right - menuRect.width));
    const top = window.innerHeight - rect.bottom >= menuRect.height + gap + edge
        ? rect.bottom + gap
        : Math.max(edge, rect.top - menuRect.height - gap);
    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
}

function openMenu(toggle, source) {
    if (activeToggle === toggle && popover && !popover.hidden) return closeMenu();
    closeMenu();
    const menu = ensurePopover();
    const actions = [...source.querySelectorAll(actionSelector)];
    if (!actions.length) return;

    actions.forEach((sourceButton) => {
        const [icon, label, tone] = actionPresentation(sourceButton);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `material-inventory-action-item material-inventory-action-item--${tone}`;
        button.setAttribute('role', 'menuitem');
        button.innerHTML = `<span class="material-inventory-action-icon" aria-hidden="true">${icon}</span><span>${label}</span>`;
        button.addEventListener('click', () => {
            closeMenu();
            sourceButton.click();
        });
        menu.append(button);
    });

    activeToggle = toggle;
    toggle.setAttribute('aria-expanded', 'true');
    positionMenu(toggle);
    menu.querySelector('button')?.focus();
}

function enhanceCell(cell) {
    if (cell.dataset.materialActionMenuReady === 'true') return;
    const buttons = [...cell.querySelectorAll(':scope > button')].filter((button) => button.matches(actionSelector));
    if (!buttons.length) return;

    cell.dataset.materialActionMenuReady = 'true';
    cell.classList.add('material-inventory-action-cell');
    const source = document.createElement('span');
    source.hidden = true;
    buttons.forEach((button) => source.append(button));

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'material-inventory-action-toggle';
    toggle.setAttribute('aria-haspopup', 'menu');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<span>Acciones</span><span class="material-inventory-action-chevron" aria-hidden="true">▾</span>';
    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        openMenu(toggle, source);
    });
    cell.replaceChildren(toggle, source);
}

function enhanceInventory() {
    const body = document.querySelector(bodySelector);
    if (!body) return false;
    body.querySelectorAll('tr > td:last-child').forEach(enhanceCell);
    if (!inventoryObserver) {
        inventoryObserver = new MutationObserver(enhanceInventory);
        inventoryObserver.observe(body, { childList: true, subtree: true });
    }
    return true;
}

function initialize() {
    if (!window.location.pathname.startsWith('/oficina/materiales/inventario')) return;
    ensurePopover();
    if (!enhanceInventory()) {
        const bootObserver = new MutationObserver(() => {
            if (enhanceInventory()) bootObserver.disconnect();
        });
        bootObserver.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.material-inventory-action-popover, .material-inventory-action-toggle')) closeMenu();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenu({ focus: true });
    });
    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
else initialize();
