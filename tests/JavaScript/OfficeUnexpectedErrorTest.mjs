import assert from 'node:assert/strict';
import test from 'node:test';
import { installOfficeUnexpectedErrorNotice } from '../../resources/js/shared/office-unexpected-error.js';

test('Oficina avisa por errores imprevistos y rechazos de promesas sin duplicar avisos', () => {
    const events = new Map();
    const region = { items: [], closest: () => null, append(item) { item.isConnected = true; this.items.push(item); } };
    const win = { addEventListener: (name, listener) => events.set(name, listener), setTimeout: () => {} };
    const doc = {
        querySelectorAll: () => [region],
        createElement: () => ({ setAttribute() {}, remove() { this.isConnected = false; } }),
    };
    installOfficeUnexpectedErrorNotice(win, doc);
    events.get('error')({ target: win });
    events.get('unhandledrejection')();
    assert.equal(region.items.length, 1);
    assert.match(region.items[0].textContent, /error inesperado.*recarga la página/);
    assert.equal(region.items[0].className, 'toast toast--error');
    events.get('error')({ target: {} });
    assert.equal(region.items.length, 1);
});
