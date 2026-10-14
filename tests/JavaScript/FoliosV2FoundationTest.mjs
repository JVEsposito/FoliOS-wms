import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const tokens = JSON.parse(read('design/estiba.tokens.json'));
const corporate = read('resources/css/office-corporate.css');
const folios = read('resources/css/office-folios.css');
const kebab = (value) => value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

function themeVariables(selector) {
    const start = corporate.lastIndexOf(selector);
    assert.notEqual(start, -1, `No se encontró ${selector}`);
    const block = corporate.slice(corporate.indexOf('{', start) + 1, corporate.indexOf('}', start));
    return Object.fromEntries([...block.matchAll(/--([\w-]+):\s*(#[\da-fA-F]{6})\s*;/g)].map((m) => [m[1], m[2].toUpperCase()]));
}

test('FoliOS v2: los temas de Oficina usan exactamente los valores de los tokens', () => {
    const pairs = [
        ['corporate-canvas', 'canvas'], ['corporate-card', 'surface'], ['corporate-border', 'border'],
        ['line-soft', 'borderSoft'], ['input-border', 'inputBorder'], ['text-strong', 'text'],
        ['text-secondary', 'textSecondary'], ['text-subtle', 'muted'], ['primary', 'primary'],
        ['primary-hover', 'primaryHover'], ['on-primary', 'onPrimary'], ['frost', 'frost'],
        ['seal', 'seal'], ['seal-bg', 'sealSurface'], ['critical-fill', 'criticalFill'],
    ];
    const light = themeVariables(':root[data-office-theme="light-professional"]');
    const dark = themeVariables(':root[data-office-theme="dark-industrial"]');
    const darkTokens = { ...tokens.color, ...tokens.colorDark };
    for (const [variable, token] of pairs) {
        assert.equal(light[variable], tokens.color[token], `claro --${variable}`);
        assert.equal(dark[variable], darkTokens[token], `oscuro --${variable}`);
    }
    for (const [area, values] of Object.entries(tokens.area)) {
        for (const [part, value] of Object.entries(values)) {
            assert.equal(light[`area-${kebab(area)}-${part}`], value, `claro --area-${kebab(area)}-${part}`);
            assert.equal(dark[`area-${kebab(area)}-${part}`], tokens.areaDark[area][part], `oscuro --area-${kebab(area)}-${part}`);
        }
    }
});

test('FoliOS v2: la capa transversal se carga al final y con fuentes autoalojadas', () => {
    // Va al final de <head> (office/partials/arranque), después de los estilos de cada página.
    const boot = read('resources/views/office/partials/arranque.blade.php');
    assert.match(boot, /'resources\/css\/office-corporate\.css', 'resources\/css\/office-shell\.css', 'resources\/css\/office-folios\.css'/);
    const validation = read('resources/views/office/validation.blade.php');
    assert.ok(validation.indexOf("resources/css/office-validation.css") < validation.indexOf("@include('office.partials.arranque')"));
    assert.ok(validation.indexOf("@include('office.partials.arranque')") < validation.indexOf('</head>'));
    assert.match(read('vite.config.js'), /'resources\/css\/office-folios\.css'/);
    assert.match(folios, /^@import '\.\/folios-fonts\.css';/m);
    const fonts = read('resources/css/folios-fonts.css');
    assert.match(fonts, /@fontsource-variable\/archivo\/standard\.css/);
    assert.match(fonts, /@fontsource-variable\/azeret-mono\/wght\.css/);
    assert.doesNotMatch(fonts + folios, /fonts\.googleapis\.com/);
    const pkg = JSON.parse(read('package.json'));
    assert.ok(pkg.dependencies['@fontsource-variable/archivo']);
    assert.ok(pkg.dependencies['@fontsource-variable/azeret-mono']);
});

test('FoliOS v2: cada área del gajo tiene su regla de activación y su gajo', () => {
    for (const area of ['materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion', 'operacion']) {
        const rule = folios.match(new RegExp(`\\{\\n    --area-ink: var\\(--area-${area}-ink\\);[^}]*--area-gajo: url\\("data:image\\/svg\\+xml,[^"]+"\\);\\n\\}`));
        assert.ok(rule, `Falta la regla de ${area}`);
    }
});
