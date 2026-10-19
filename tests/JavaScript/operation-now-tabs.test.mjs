import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DEFAULT_OPERATION_TAB,
    OPERATION_TABS,
    OPERATION_TAB_STORAGE_KEY,
    createOperationTabs,
    resolveInitialTab,
    tabFromHash,
    tabFromKey,
} from '../../resources/js/shared/operation-now-tabs.js';

test('la dirección manda y, sin ella, vuelve la última pestaña del equipo', () => {
    assert.equal(tabFromHash('#plano'), 'plano');
    assert.equal(tabFromHash('#PLANO'), 'plano');
    assert.equal(tabFromHash('#desconocida'), null);
    assert.equal(tabFromHash('#%E0%A4%A'), null, 'una dirección mal codificada no rompe la vista');
    assert.equal(resolveInitialTab('#camaras', 'plano'), 'camaras');
    assert.equal(resolveInitialTab('', 'plano'), 'plano');
    assert.equal(resolveInitialTab('', 'inventada'), DEFAULT_OPERATION_TAB);
    assert.equal(DEFAULT_OPERATION_TAB, 'atencion');
});

test('las flechas recorren las pestañas de forma circular', () => {
    assert.equal(tabFromKey('atencion', 'ArrowRight'), 'maniobras');
    assert.equal(tabFromKey('atencion', 'ArrowLeft'), 'personal');
    assert.equal(tabFromKey('prefrio', 'Home'), 'atencion');
    assert.equal(tabFromKey('maniobras', 'End'), 'personal');
    assert.equal(tabFromKey('maniobras', 'Enter'), null);
});

function fakeDom(hash = '') {
    const listeners = {};
    const node = (attributes) => ({
        ...attributes,
        attributes: {},
        hidden: false,
        tabIndex: 0,
        focused: false,
        setAttribute(name, value) { this.attributes[name] = value; },
        focus() { this.focused = true; },
        closest(selector) { return selector === '[data-operation-tab]' && this.dataset.operationTab ? this : null; },
    });
    const buttons = OPERATION_TABS.map((tab) => node({ dataset: { operationTab: tab } }));
    const panels = OPERATION_TABS.map((tab) => node({ dataset: { operationPanel: tab } }));
    const root = {
        dataset: {},
        querySelectorAll: (selector) => (selector === '[data-operation-tab]' ? buttons : panels),
        addEventListener: (type, handler) => { listeners[type] = handler; },
    };
    const history = [];
    const win = {
        location: { hash },
        history: {
            state: null,
            replaceState(_state, _title, url) {
                history.push(url);
                win.location.hash = url;
            },
        },
        addEventListener: (type, handler) => { listeners[`window:${type}`] = handler; },
    };
    const store = new Map();
    const storage = {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => store.set(key, value),
    };
    return { root, win, storage, store, buttons, panels, listeners, history };
}

test('abre la pestaña de la dirección, oculta las demás y la recuerda', () => {
    const dom = fakeDom('#plano');
    const changes = [];
    const tabs = createOperationTabs({ root: dom.root, win: dom.win, storage: dom.storage, onChange: (tab) => changes.push(tab) });

    assert.equal(tabs.current, 'plano');
    assert.deepEqual(dom.panels.filter((panel) => !panel.hidden).map((panel) => panel.dataset.operationPanel), ['plano']);
    assert.equal(dom.buttons[2].attributes['aria-selected'], 'true');
    assert.equal(dom.buttons[2].tabIndex, 0);
    assert.equal(dom.buttons[0].tabIndex, -1);
    assert.equal(dom.store.get(OPERATION_TAB_STORAGE_KEY), 'plano');
    assert.deepEqual(dom.history, [], 'la dirección ya era correcta');
    assert.deepEqual(changes, ['plano']);
});

test('sin pestaña en la dirección usa la recordada y la escribe sin agregar historial', () => {
    const dom = fakeDom('');
    dom.store.set(OPERATION_TAB_STORAGE_KEY, 'prefrio');
    const tabs = createOperationTabs({ root: dom.root, win: dom.win, storage: dom.storage });

    assert.equal(tabs.current, 'prefrio');
    assert.deepEqual(dom.history, ['#prefrio']);
});

test('clic, teclado y enlaces cambian la pestaña; nada más lo hace', () => {
    const dom = fakeDom('');
    const tabs = createOperationTabs({ root: dom.root, win: dom.win, storage: dom.storage });

    dom.listeners.click({ target: dom.buttons[3] });
    assert.equal(tabs.current, 'camaras');
    assert.equal(dom.win.location.hash, '#camaras');

    let prevented = false;
    dom.listeners.keydown({ target: dom.buttons[3], key: 'ArrowRight', preventDefault: () => { prevented = true; } });
    assert.equal(tabs.current, 'prefrio');
    assert.ok(prevented);
    assert.ok(dom.buttons[4].focused, 'el foco acompaña a la pestaña activa');

    dom.win.location.hash = '#maniobras';
    dom.listeners['window:hashchange']();
    assert.equal(tabs.current, 'maniobras');

    dom.win.location.hash = '#otra-cosa';
    dom.listeners['window:hashchange']();
    assert.equal(tabs.current, 'maniobras', 'un ancla ajena no mueve la vista');
});

test('sin almacenamiento local la pestaña sigue funcionando', () => {
    const dom = fakeDom('#personal');
    const broken = { getItem() { throw new Error('bloqueado'); }, setItem() { throw new Error('bloqueado'); } };
    const tabs = createOperationTabs({ root: dom.root, win: dom.win, storage: broken });

    assert.equal(tabs.current, 'personal');
});
