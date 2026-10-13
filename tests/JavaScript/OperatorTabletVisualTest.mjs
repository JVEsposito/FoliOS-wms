import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const theme = await readFile(
    new URL('../../mobile/src/theme/operatorTheme.ts', import.meta.url),
    'utf8',
);
const header = await readFile(
    new URL('../../mobile/src/components/operator/OperatorHeader.tsx', import.meta.url),
    'utf8',
);
const primitives = await readFile(
    new URL('../../mobile/src/components/operator/OperatorPrimitives.tsx', import.meta.url),
    'utf8',
);
const workspace = await readFile(
    new URL('../../mobile/src/screens/OperationalWorkspaceScreen.tsx', import.meta.url),
    'utf8',
);
const inbox = await readFile(
    new URL('../../mobile/src/components/OperationalTaskInbox.tsx', import.meta.url),
    'utf8',
);
const home = await readFile(
    new URL('../../mobile/src/components/operator/OperatorTaskHome.tsx', import.meta.url),
    'utf8',
);
const execution = await readFile(
    new URL('../../mobile/src/components/operator/OperatorTaskExecution.tsx', import.meta.url),
    'utf8',
);

function luminance(hex) {
    const channels = hex.slice(1).match(/../g).map((channel) => parseInt(channel, 16) / 255);
    const [r, g, b] = channels.map((value) => (
        value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
    ));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
    const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (values[0] + 0.05) / (values[1] + 0.05);
}

test('el tema del camarero (siempre oscuro, FoliOS v2) conserva contraste AA', () => {
    const pairs = [
        ['#E8F3F6', '#0F212B'],
        ['#9FBCC6', '#0F212B'],
        ['#9FBCC6', '#15303C'],
        ['#C5D9DF', '#1C3B49'],
        ['#E8F3F6', '#0B1A22'],
        ['#062530', '#5FD3E6'],
        ['#5FD3E6', '#12313D'],
        ['#6FD6A0', '#11302A'],
        ['#F3B94F', '#2C2716'],
        ['#FF8B7B', '#2E1B1C'],
        ['#9FBCC6', '#24414F'],
    ];

    for (const [foreground, background] of pairs) {
        assert.ok(
            contrast(foreground, background) >= 4.5,
            `Contraste insuficiente: ${foreground} sobre ${background}`,
        );
        assert.match(theme, new RegExp(foreground.replace('#', '#')));
        assert.match(theme, new RegExp(background.replace('#', '#')));
    }
});

test('la base visual expone cabecera, estados, rutas y folios reutilizables', () => {
    assert.match(header, /useWindowDimensions/);
    assert.match(header, /Estado de conexión/);
    assert.match(primitives, /export function OperatorStatusBadge/);
    assert.match(primitives, /export function OperatorPriorityBadge/);
    assert.match(primitives, /export function OperatorEntityCode/);
    assert.match(primitives, /export function OperatorRouteLine/);
    assert.match(workspace, /<OperatorHeader/);
    assert.match(workspace, /navigation=\{<View accessibilityRole="tablist"/);
    assert.doesNotMatch(workspace, /<View style=\{styles\.navigation\}>/);
    assert.match(header, /\{navigation \? <View style=\{styles\.navigation\}>\{navigation\}<\/View> : null\}/);
    assert.match(inbox, /<OperatorTaskHome/);
    assert.match(inbox, /<OperatorTaskExecution/);
    assert.match(home, /<OperatorPriorityBadge/);
    assert.match(home, /<OperatorRouteLine/);
    assert.match(execution, /<OperatorEntityCode/);
});

test('la bandeja responde a anchos compactos y mantiene controles táctiles', () => {
    assert.match(theme, /minimum: t\.density\.touch\.control/);
    assert.match(theme, /prominent: 64/);
    assert.match(home, /width < o\.breakpoint\.compact/);
    assert.match(execution, /width < o\.breakpoint\.compact/);
    assert.match(home, /minHeight: o\.touch\.minimum/);
    assert.match(execution, /minHeight: o\.touch\.minimum/);
    assert.doesNotMatch(home, /fontSize:\s*[0-9]\b/);
});
