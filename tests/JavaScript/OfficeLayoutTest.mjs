import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const readCss = (file) => readFileSync(new URL(`../../resources/css/${file}.css`, import.meta.url), 'utf8');
const corporate = readCss('office-corporate');
const shell = readCss('office-shell');

// Contratos estáticos de la cascada; no sustituyen la inspección renderizada.
function rules(css, selector) {
    return [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
        .filter((match) => match[1].includes(selector))
        .map((match) => match[2]);
}

test('las cabeceras compartidas no asumen un relleno negativo en los paneles', () => {
    for (const heading of ['.raw-material-panel-heading', '.process-panel__heading', '.return-panel__heading', '.admin-panel__heading', '.discrepancies-panel__heading']) {
        const margins = rules(corporate, heading).filter((body) => /\bmargin:/.test(body));
        assert.ok(margins.length > 0, heading);
        margins.forEach((body) => assert.match(body, /\bmargin:\s*0\s*!important/, heading));
    }
    assert.match(rules(corporate, '.office-app .discrepancies-panel__heading').join('\n'), /margin-top:\s*16px\s*!important/);
});

test('el logo conserva su columna cuando se oculta el menú lateral', () => {
    assert.match(shell, /--office-brand-width:\s*248px/);
    assert.match(shell, /\[data-menu-collapsed\]\)\s*\{\s*--office-sidebar-width:\s*0px/);
    const desktopGrids = rules(shell, '.estiba-office-header').filter((body) => /grid-template-columns:.*minmax\(.*minmax\(/.test(body));
    assert.ok(desktopGrids.length >= 2);
    desktopGrids.forEach((body) => assert.match(body, /grid-template-columns:\s*var\(--office-brand-width\)/));
    assert.doesNotMatch(shell, /grid-template-columns:\s*142px/);
});

test('la casilla de temporada no hereda el ancho completo de los campos de texto', () => {
    const checkbox = rules(readCss('office-admin'), '.admin-form .admin-check input[type="checkbox"]').join('\n');
    assert.match(checkbox, /width:\s*18px/);
    assert.match(checkbox, /flex:\s*0 0 18px/);
    assert.match(checkbox, /padding:\s*0/);
});

test('digitación puede reducir el ancho sin forzar un escritorio de 1050px', () => {
    const css = readCss('office-raw-material');
    assert.match(rules(css, '.raw-material-workspace').join('\n'), /min-width:\s*0/);
    assert.doesNotMatch(rules(css, '.raw-material-workspace').join('\n'), /min-width:\s*[1-9]\d*px/);
    assert.match(rules(css, '.raw-material-grid').join('\n'), /grid-template-columns:\s*1fr/);
    assert.match(rules(corporate, '.raw-material-panel-heading').join('\n'), /flex-wrap:\s*wrap/);
});

test('retornos y bandas de cámara consumen las superficies del tema', () => {
    for (const selector of ['.office-app .origin-builder__heading', '.office-app .preview-band']) {
        assert.match(rules(corporate, selector).join('\n'), /background:\s*var\(--surface-muted\)/);
    }
    assert.match(rules(corporate, '.office-app .preview-position.is-disabled').join('\n'), /background:\s*var\(--warning-bg\)/);
    assert.match(rules(corporate, '.office-app .preview-position.is-disabled').join('\n'), /opacity:\s*1/);
});

test('las pestañas activas usan texto y fondo de selección del mismo tema', () => {
    const active = rules(corporate, '.raw-material-module-links > a).is-active').join('\n');
    assert.match(active, /background:\s*var\(--surface-selected\)\s*!important/);
    assert.match(active, /color:\s*var\(--corporate-blue\)\s*!important/);
    assert.match(rules(corporate, '.raw-material-module-links > a.is-active :is(span, strong)').join('\n'), /color:\s*var\(--corporate-blue\)/);
});

test('Discrepancias enlaza las superficies EUI con el tema de Oficina', () => {
    const eui = rules(corporate, '.office-app .discrepancies-shell').join('\n');
    assert.match(eui, /--eui-color-canvas:\s*var\(--corporate-canvas\)/);
    assert.match(eui, /--eui-color-surface:\s*var\(--corporate-card\)/);
    assert.match(eui, /--eui-color-muted:\s*var\(--text-subtle\)/);
    assert.match(eui, /--eui-signal-warning-text:\s*var\(--warning-text\)/);
});
