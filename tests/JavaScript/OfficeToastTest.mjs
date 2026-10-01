import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { showOfficeToast } from '../../resources/js/office-toast.js';

function host() {
    const children = [];
    return {
        children,
        append(node) { children.push(node); },
        querySelector() { return children.find((node) => node.dataset?.dialogToasts !== undefined) || null; },
        setAttribute() {},
        remove() {},
    };
}

test('el aviso aparece dentro del diálogo modal mientras está abierto', () => {
    const region = host();
    const dialog = host();
    globalThis.document = {
        querySelector: (selector) => selector === 'dialog:modal' ? dialog : null,
        createElement: () => ({ ...host(), dataset: {} }),
    };
    globalThis.window = { setTimeout() {} };
    const item = showOfficeToast(region, 'Guardado');
    assert.equal(region.children.length, 0);
    assert.equal(dialog.children[0].children[0], item);
    assert.equal(item.textContent, 'Guardado');
    delete globalThis.document;
    delete globalThis.window;
});

test('sin diálogo usa la región habitual y conserva el mensaje de error', () => {
    const region = host();
    globalThis.document = { querySelector: () => null, createElement: () => ({ ...host(), dataset: {} }) };
    globalThis.window = { setTimeout() {} };
    const item = showOfficeToast(region, 'Mensaje del servidor', true);
    assert.equal(region.children[0], item);
    assert.equal(item.className, 'toast toast--error');
    assert.equal(item.textContent, 'Mensaje del servidor');
    delete globalThis.document;
    delete globalThis.window;
});

test('Inspección SAG confirma sus cuatro acciones y muestra errores', () => {
    const js = readFileSync(new URL('../../resources/js/office-sag-inspections.js', import.meta.url), 'utf8');
    assert.match(js, /toast\(`\$\{lot\.codigo\} creado correctamente\.`\)/);
    for (const action of ['start', 'finish', 'cancel']) assert.match(js, new RegExp(`action === '${action}'`));
    assert.match(js, /toast\(error\.message, true\)/);
});
