import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const tokens = JSON.parse(read('design/estiba.tokens.json'));

test('FoliOS v2 móvil: el tema de terreno usa la paleta oscura de los tokens', () => {
    const theme = read('mobile/src/theme/operatorTheme.ts');
    const dark = { ...tokens.color, ...tokens.colorDark };
    for (const [key, token] of [['canvas', 'canvas'], ['surface', 'surface'], ['surfaceMuted', 'subtle'], ['navy', 'navy'], ['text', 'text'], ['textSecondary', 'textSecondary'], ['muted', 'muted'], ['border', 'border'], ['primary', 'primary'], ['onPrimary', 'onPrimary'], ['selected', 'selected'], ['disabled', 'disabledSurface']]) {
        assert.match(theme, new RegExp(`\\b${key}: '${dark[token]}'`), key);
    }
    for (const [key, tone] of [['success', 'success'], ['warning', 'warning'], ['critical', 'critical']]) {
        assert.match(theme, new RegExp(`\\b${key}: '${tokens.signalDark[tone].text}'`), key);
        assert.match(theme, new RegExp(`\\b${key}Surface: '${tokens.signalDark[tone].surface}'`), `${key}Surface`);
        assert.match(theme, new RegExp(`\\b${key}Border: '${tokens.signalDark[tone].border}'`), `${key}Border`);
    }
});

test('FoliOS v2 móvil: siete gajos de área, uno por archivo', () => {
    const areas = read('mobile/src/theme/areas.ts');
    for (const area of ['operacion', 'materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion']) {
        assert.ok(existsSync(new URL(`../../mobile/assets/gajo/gajo-${area}.png`, import.meta.url)), area);
        assert.match(areas, new RegExp(`assets/gajo/gajo-${area}\\.png`));
    }
});

test('FoliOS v2 móvil: los componentes del camarero no fijan colores propios', () => {
    const directory = new URL('../../mobile/src/components/operator/', import.meta.url);
    const offenders = readdirSync(directory)
        .filter((name) => name.endsWith('.tsx'))
        .flatMap((name) => [...readFileSync(new URL(name, directory), 'utf8').matchAll(/'#[0-9A-Fa-f]{3,8}'/g)].map((match) => `${name}: ${match[0]}`));
    assert.deepEqual(offenders, []);
});
