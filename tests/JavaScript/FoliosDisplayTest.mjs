import assert from 'node:assert/strict';
import test from 'node:test';
import { officeStatus, palletCells } from '../../resources/js/shared/folios-display.js';

test('mantiene una celda por pallet en una carga real y conserva sus estados', () => {
    const html = palletCells(26, 13, 4);
    assert.equal(html.match(/data-cell="done"/g).length, 13);
    assert.equal(html.match(/data-cell="dock"/g).length, 4);
    assert.equal(html.match(/data-cell="pending"/g).length, 9);
});

test('resume inventarios grandes con tres tramos y el total exacto', () => {
    const html = palletCells(57227, 51013, 752, 'free');
    assert.equal(html.match(/data-cell=/g).length, 3);
    assert.match(html, /51\.013 de 57\.227.*752 en espera/);
    assert.match(html, /data-cell="free" style="flex: 5462 1 0"/);
});

test('el glifo complementa el texto y los estados desconocidos tienen un aspecto neutro', () => {
    const html = officeStatus('<Pendiente>', 'desconocido', text => text.replaceAll('<', '&lt;').replaceAll('>', '&gt;'));
    assert.match(html, /data-estado="pendiente"/);
    assert.match(html, /aria-hidden="true">○<\/span>&lt;Pendiente&gt;/);
});
