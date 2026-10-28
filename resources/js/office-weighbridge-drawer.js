import '../css/office-weighbridge-drawer.css';

function initializeWeighbridgeDrawer() {
    const app = document.getElementById('officeApp');
    const detail = document.getElementById('receptionDetail');
    const tableBody = document.getElementById('receptionTableBody');
    const closeButton = document.getElementById('closeDetailButton');
    const detailFacts = document.getElementById('detailFacts');
    const detailTimeline = document.getElementById('detailTimeline');
    const weightBalance = document.getElementById('weightBalance');
    const weighingPanel = document.getElementById('containerWeighingPanel');

    if (!app || !detail || !tableBody || !closeButton || !detailFacts || !detailTimeline || !weightBalance || !weighingPanel) {
        return;
    }

    let selectedReceptionId = null;
    let activeTab = 'summary';
    let offsetFrame = null;

    // El flujo original llevaba el expediente al final de la página. El panel
    // lateral conserva la posición del listado para cambiar de recepción rápido.
    Object.defineProperty(detail, 'scrollIntoView', {
        configurable: true,
        value: () => {},
    });

    detail.classList.add('reception-drawer');
    detail.setAttribute('role', 'region');
    detail.setAttribute('aria-label', 'Expediente de recepción');
    detail.setAttribute('aria-hidden', 'true');

    const heading = detail.querySelector('.weighbridge-panel-heading');
    const legacyActions = heading?.querySelector('.detail-actions');
    const timelineSection = detailTimeline.closest('section');
    const balanceSection = weightBalance.closest('section');
    const legacyBottom = detail.querySelector('.reception-detail__bottom');

    if (!heading || !legacyActions || !timelineSection || !balanceSection || !legacyBottom) {
        return;
    }

    heading.classList.add('reception-drawer__heading');
    closeButton.classList.add('reception-drawer__close');
    closeButton.textContent = '×';
    closeButton.title = 'Cerrar expediente';
    closeButton.setAttribute('aria-label', 'Cerrar expediente de recepción');

    const content = document.createElement('div');
    content.className = 'reception-drawer__content';

    const summaryPanel = document.createElement('section');
    summaryPanel.className = 'reception-drawer__panel';
    summaryPanel.id = 'receptionDrawerSummary';
    summaryPanel.dataset.drawerPanel = 'summary';
    summaryPanel.append(detailFacts, balanceSection);

    const weighingTabPanel = document.createElement('section');
    weighingTabPanel.className = 'reception-drawer__panel';
    weighingTabPanel.id = 'receptionDrawerWeighings';
    weighingTabPanel.dataset.drawerPanel = 'weighings';

    const weighingEmpty = document.createElement('div');
    weighingEmpty.className = 'reception-drawer__empty';
    weighingEmpty.innerHTML = '<strong>Sin pesajes acumulativos</strong><span>Esta recepción no posee lecturas por tanda o todavía no han sido registradas.</span>';
    weighingTabPanel.append(weighingPanel, weighingEmpty);

    const eventsPanel = document.createElement('section');
    eventsPanel.className = 'reception-drawer__panel';
    eventsPanel.id = 'receptionDrawerEvents';
    eventsPanel.dataset.drawerPanel = 'events';
    eventsPanel.append(timelineSection);

    content.append(summaryPanel, weighingTabPanel, eventsPanel);

    const tabs = document.createElement('nav');
    tabs.className = 'reception-drawer__tabs';
    tabs.setAttribute('aria-label', 'Secciones del expediente');
    tabs.innerHTML = `
        <button type="button" data-drawer-tab="summary" aria-controls="receptionDrawerSummary">Resumen</button>
        <button type="button" data-drawer-tab="weighings" aria-controls="receptionDrawerWeighings">Pesajes</button>
        <button type="button" data-drawer-tab="events" aria-controls="receptionDrawerEvents">Eventos</button>
    `;

    const footer = document.createElement('footer');
    footer.className = 'reception-drawer__actions';
    [...legacyActions.children]
        .filter((button) => button !== closeButton)
        .forEach((button) => footer.append(button));

    legacyBottom.remove();
    heading.after(tabs, content);
    detail.append(footer);

    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'reception-drawer-backdrop';
    backdrop.setAttribute('aria-label', 'Cerrar expediente de recepción');
    backdrop.addEventListener('click', () => closeButton.click());
    document.body.append(backdrop);

    function updateDrawerOffset() {
        offsetFrame = null;
        if (window.matchMedia('(max-width: 900px)').matches) {
            document.documentElement.style.removeProperty('--weighbridge-drawer-top');
            return;
        }

        const bars = [
            document.querySelector('[data-office-shell-header]'),
        ].filter(Boolean);
        const visibleBottoms = bars
            .map((bar) => bar.getBoundingClientRect().bottom)
            .filter((bottom) => bottom > 0 && bottom < window.innerHeight * .55);
        const offset = Math.max(12, ...visibleBottoms) + 10;
        document.documentElement.style.setProperty('--weighbridge-drawer-top', `${Math.round(offset)}px`);
    }

    function scheduleDrawerOffset() {
        if (offsetFrame !== null) return;
        offsetFrame = window.requestAnimationFrame(updateDrawerOffset);
    }

    function syncWeighingAvailability() {
        const available = !weighingPanel.classList.contains('is-hidden');
        weighingEmpty.hidden = available;
    }

    function activateTab(tabName, { focus = false } = {}) {
        activeTab = ['summary', 'weighings', 'events'].includes(tabName) ? tabName : 'summary';

        tabs.querySelectorAll('[data-drawer-tab]').forEach((button) => {
            const selected = button.dataset.drawerTab === activeTab;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            if (selected && focus) button.focus();
        });

        content.querySelectorAll('[data-drawer-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.drawerPanel !== activeTab;
        });

        syncWeighingAvailability();
    }

    function markSelectedRow() {
        tableBody.querySelectorAll('[data-reception-id]').forEach((row) => {
            const selected = selectedReceptionId !== null
                && String(row.dataset.receptionId) === String(selectedReceptionId);
            row.classList.toggle('is-selected', selected);
            row.setAttribute('aria-selected', String(selected));
        });
    }

    function syncDrawerState() {
        const open = !detail.classList.contains('is-hidden') && !app.classList.contains('is-hidden');
        document.body.classList.toggle('has-reception-drawer', open);
        detail.setAttribute('aria-hidden', String(!open));
        backdrop.tabIndex = open ? 0 : -1;

        if (open) {
            scheduleDrawerOffset();
            activateTab(activeTab);
            markSelectedRow();
        } else {
            selectedReceptionId = null;
            activeTab = 'summary';
            markSelectedRow();
        }
    }

    tabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-drawer-tab]');
        if (button) activateTab(button.dataset.drawerTab);
    });

    tabs.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const buttons = [...tabs.querySelectorAll('[data-drawer-tab]')];
        const current = buttons.findIndex((button) => button.dataset.drawerTab === activeTab);
        let next = current;
        if (event.key === 'ArrowRight') next = (current + 1) % buttons.length;
        if (event.key === 'ArrowLeft') next = (current - 1 + buttons.length) % buttons.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = buttons.length - 1;
        activateTab(buttons[next].dataset.drawerTab, { focus: true });
    });

    function rememberRow(event) {
        if (event.target.closest('button')) return;
        if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
        const row = event.target.closest('[data-reception-id]');
        if (!row) return;
        selectedReceptionId = row.dataset.receptionId;
        markSelectedRow();
    }

    tableBody.addEventListener('click', rememberRow, true);
    tableBody.addEventListener('keydown', rememberRow, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || detail.classList.contains('is-hidden')) return;
        const openDialog = document.querySelector('dialog[open]');
        if (!openDialog) closeButton.click();
    });

    const drawerObserver = new MutationObserver(syncDrawerState);
    drawerObserver.observe(detail, { attributes: true, attributeFilter: ['class'] });
    drawerObserver.observe(app, { attributes: true, attributeFilter: ['class'] });
    new MutationObserver(markSelectedRow).observe(tableBody, { childList: true });
    new MutationObserver(syncWeighingAvailability).observe(weighingPanel, {
        attributes: true,
        attributeFilter: ['class'],
    });

    window.addEventListener('resize', scheduleDrawerOffset);
    window.addEventListener('scroll', scheduleDrawerOffset, { passive: true });
    if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(scheduleDrawerOffset);
        document.querySelectorAll('[data-office-shell-header]').forEach((bar) => resizeObserver.observe(bar));
    }

    activateTab('summary');
    updateDrawerOffset();
    syncDrawerState();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWeighbridgeDrawer, { once: true });
} else {
    initializeWeighbridgeDrawer();
}
