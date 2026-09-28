import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(new URL('../../resources/css/office-corporate.css', import.meta.url), 'utf8');
const operationCss = readFileSync(new URL('../../resources/css/office-operation-now.css', import.meta.url), 'utf8');

function luminance(hex) {
    const channels = [1, 3, 5]
        .map((index) => Number.parseInt(hex.slice(index, index + 2), 16) / 255)
        .map((channel) => (channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4));

    return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
}

function contrast(foreground, background) {
    const [lighter, darker] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
    return (lighter + 0.05) / (darker + 0.05);
}

function themeVariables(selector) {
    const start = css.lastIndexOf(selector);
    assert.notEqual(start, -1, `No se encontró ${selector}`);
    const openingBrace = css.indexOf('{', start);
    const closingBrace = css.indexOf('}', openingBrace);
    const block = css.slice(openingBrace + 1, closingBrace);

    return Object.fromEntries(
        [...block.matchAll(/--([\w-]+):\s*(#[\da-fA-F]{6})\s*;/g)]
            .map((match) => [match[1], match[2]]),
    );
}

test('los temas claro y oscuro mantienen contraste AA en contenido y selección', () => {
    const themes = [
        themeVariables(':root[data-office-theme="light-professional"]'),
        themeVariables(':root[data-office-theme="dark-industrial"]'),
    ];

    themes.forEach((variables) => {
        assert.ok(contrast(variables.text, variables['corporate-canvas']) >= 4.5);
        assert.ok(contrast(variables.text, variables['corporate-card']) >= 4.5);
        assert.ok(contrast(variables.muted, variables['corporate-card']) >= 4.5);
        assert.ok(contrast(variables['corporate-blue'], variables['surface-selected']) >= 4.5);
        assert.ok(contrast('#ffffff', variables['corporate-blue-dark']) >= 4.5);
        assert.ok(contrast(variables['text-secondary'], variables['button-secondary']) >= 4.5);
        assert.ok(contrast(variables['text-strong'], variables['surface-muted']) >= 4.5);
        assert.ok(contrast(variables['text-subtle'], variables['kpi-surface']) >= 4.5);
        assert.ok(contrast(variables['warning-text'], variables['warning-bg']) >= 4.5);
        assert.ok(contrast(variables['success-text'], variables['surface-muted']) >= 4.5);
        for (const text of ['text-strong', 'text-secondary', 'success-text', 'info-text', 'warning-text', 'danger-text']) {
            assert.ok(contrast(variables[text], variables.panel) >= 4.5, `${text} sobre el panel gerencial`);
        }
    });
});

test('Operación ahora mantiene contraste con filas alternas y superficies de cada estado', () => {
    function colorProperty(selector, property, variables) {
        const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const block = operationCss.match(new RegExp(`${escaped}\\s*\\{([^}]+)\\}`))?.[1];
        assert.ok(block, `Regla ausente: ${selector}`);
        const value = block.match(new RegExp(`(?:^|;)\\s*${property}:\\s*([^;]+)`))?.[1].trim();
        assert.ok(value, `${selector}: ${property}`);
        const variable = value.match(/^var\(--([\w-]+)\)$/)?.[1];
        const color = variable ? variables[variable] : value;
        assert.match(color, /^#[\da-f]{3}(?:[\da-f]{3})?$/i);
        return color.length === 4 ? `#${[...color.slice(1)].map((c) => c + c).join('')}` : color;
    }

    for (const theme of ['light-professional', 'dark-industrial']) {
        const variables = themeVariables(`:root[data-office-theme="${theme}"]`);
        const scope = operationCss.match(/\.operation-now\s*\{([^}]+)\}/)[1];
        for (const [, name, reference] of scope.matchAll(/--([\w-]+):\s*var\(--([\w-]+)\)/g)) {
            variables[name] = variables[reference];
        }
        const surfaces = ['.operation-now', '.operation-now-syncbar', '.operation-now-syncbar__metrics div',
            '.operation-now-panel', '.operation-now-table tbody tr:nth-child(even)',
            '.operation-now-operator', '.operation-now-operator:nth-child(even)',
            '.operation-now-alert-list', '.operation-now-alert:nth-child(even)', '.operation-now-facility',
            ...['success', 'warning', 'critical', 'info'].map((tone) => `.operation-now-facility__cell[data-tone="${tone}"]`)];
        for (const selector of surfaces) {
            const background = colorProperty(selector, 'background', variables);
            for (const text of ['text-strong', 'text-subtle']) {
                assert.ok(contrast(variables[text], background) >= 4.5, `${theme}: ${text} sobre ${selector}`);
            }
        }
        for (const tone of ['success', 'warning', 'critical', 'info']) {
            const foreground = colorProperty(`.operation-now-signal[data-tone="${tone}"]`, 'color', variables);
            for (const background of ['corporate-card', 'row-alt']) {
                assert.ok(contrast(foreground, variables[background]) >= 4.5, `${theme}: señal ${tone} sobre ${background}`);
            }
        }
    }
});

test('los títulos de superficies navy superan a la regla transversal de títulos en tema claro', () => {
    // Regresión: .office-app :is(h1, h2, h3, h4):not([data-office-shell] *) aplica
    // --text-strong con !important y, por especificidad, oscurecía los h2 de las
    // cabeceras navy de Cámaras PT en tema claro (navy sobre navy).
    const transversal = '.office-app :is(h1, h2, h3, h4):not([data-office-shell] *) { color: var(--text-strong) !important; }';
    const navy = '.office-app [data-estiba-contrast="navy"] :is(h1, h2, h3, h4):not([data-office-shell] *)';
    const transversalIndex = css.indexOf(transversal);
    const navyIndex = css.lastIndexOf(navy);

    assert.notEqual(transversalIndex, -1, 'No se encontró la regla transversal de títulos');
    assert.notEqual(navyIndex, -1, 'La superficie navy no declara un selector de títulos más específico');

    // El selector navy es la regla transversal más un atributo: siempre la supera.
    const block = css.slice(navyIndex, css.indexOf('}', navyIndex));
    assert.match(block, /color:\s*#ffffff\s*!important/);
});
