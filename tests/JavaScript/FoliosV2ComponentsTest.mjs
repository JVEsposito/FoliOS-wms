import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const ui = read('resources/css/estiba-ui.css');
const components = read('resources/css/folios-components.css');
const rule = (css, selector) => css.match(new RegExp(`${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

test('FoliOS v2: títulos de x-estiba sin mayúsculas forzadas y con el eje de ancho', () => {
    for (const selector of ['.estiba-ui .eui-heading__title', '.estiba-ui .eui-panel__title', '.estiba-ui .eui-table th']) {
        assert.match(rule(ui, selector), /text-transform:\s*none/, selector);
    }
    assert.match(rule(ui, '.estiba-ui .eui-heading__title'), /font-stretch:\s*var\(--eui-font-stretch-masthead\)/);
    assert.match(rule(ui, '.estiba-ui .eui-panel__title'), /font-stretch:\s*var\(--eui-font-stretch-condensed\)/);
    assert.doesNotMatch(rule(ui, '.estiba-ui .eui-heading'), /border-bottom/);
});

test('FoliOS v2: el eyebrow de x-estiba es la etiqueta del área y el estado es un sello', () => {
    const eyebrow = rule(ui, '.estiba-ui .eui-heading__eyebrow');
    for (const part of ['line', 'tint', 'text']) assert.match(eyebrow, new RegExp(`var\\(--area-${part}`));
    const signal = rule(ui, '.estiba-ui .eui-signal');
    assert.match(signal, /border:\s*1\.5px solid var\(--eui-signal-border\)/);
    assert.match(signal, /border-radius:\s*var\(--eui-radius-seal\)/);
});

test('FoliOS v2: la etiqueta de área y el gajo cubren las siete áreas', () => {
    for (const area of ['materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion']) {
        assert.match(components, new RegExp(`\\.folios-gajo\\[data-area="${area}"\\]`));
        assert.match(components, new RegExp(`\\.folios-area-tag\\[data-area="${area}"\\]`));
    }
    assert.match(read('resources/css/office-folios.css'), /@import '\.\/folios-components\.css';/);
    assert.doesNotMatch(read('resources/css/office-shell.css'), /\.folios-gajo\[data-area=/);
});
