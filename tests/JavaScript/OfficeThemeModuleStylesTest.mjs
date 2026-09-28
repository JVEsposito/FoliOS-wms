import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Añadir aquí cada módulo cuando sus estilos migren a tokens de Oficina.
const migratedStyles = [
    'office-materials.css',
    'office-raw-material-returns.css',
    'office-validation-annulments.css',
    'office-inventory-exports.css',
    'office-management.css',
];

for (const name of migratedStyles) {
    test(`${name} usa tokens en lugar de colores fijos`, () => {
        const css = readFileSync(new URL(`../../resources/css/${name}`, import.meta.url), 'utf8');
        assert.doesNotMatch(css, /#[\da-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\s*\(|(?<![-\w])(?:white|black)(?![-\w])/i);
        assert.doesNotMatch(css, /var\(--selected\)/);
    });
}

test('las tres pantallas cargan estilos CSS sin inyectarlos desde JavaScript', () => {
    const css = readFileSync(new URL('../../resources/css/office-materials.css', import.meta.url), 'utf8');
    for (const [script, selector] of [
        ['office-material-orders.js', '.materials-order-card'],
        ['office-material-recipes.js', '.materials-recipe-card'],
        ['office-material-inventory-actions.js', '.material-inventory-action-popover'],
    ]) {
        const js = readFileSync(new URL(`../../resources/js/${script}`, import.meta.url), 'utf8');
        assert.ok(css.includes(selector), `${script}: falta ${selector} en office-materials.css`);
        assert.doesNotMatch(js, /createElement\(['"]style['"]\)|inject(?:Order|Recipe)?Styles\(/);
    }
});
