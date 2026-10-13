import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const tokens = JSON.parse(await readFile(new URL('../../design/estiba.tokens.json', import.meta.url), 'utf8'));

function luminance(hex) {
    const channels = hex.slice(1).match(/../g).map((channel) => parseInt(channel, 16) / 255);
    const [r, g, b] = channels.map((c) => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
    const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (values[0] + 0.05) / (values[1] + 0.05);
}

test('los textos de estados son legibles en su superficie y en filas blancas', () => {
    for (const [name, signal] of Object.entries(tokens.signal)) {
        for (const surface of [signal.surface, tokens.color.surface, tokens.color.canvas]) {
            assert.ok(contrast(signal.text, surface) >= 4.5, `Contraste insuficiente: ${name} / ${surface}`);
        }
    }
});

test('texto general, acciones y foco conservan contraste suficiente', () => {
    for (const foreground of [tokens.color.text, tokens.color.muted]) {
        for (const background of [tokens.color.surface, tokens.color.canvas, tokens.color.subtle]) {
            assert.ok(contrast(foreground, background) >= 4.5);
        }
    }
    for (const background of [tokens.color.primary, tokens.color.primaryHover, tokens.signal.success.text]) {
        assert.ok(contrast(tokens.color.onPrimary, background) >= 4.5);
    }
    assert.ok(contrast(tokens.color.onNavy, tokens.color.navy) >= 4.5);
    assert.ok(contrast(tokens.color.inputBorder, tokens.color.surface) >= 3);
    assert.ok(contrast(tokens.color.focus, tokens.color.canvas) >= 3);
});

test('el tamaño táctil y la escala de lectura no se reducen al compactar oficina', () => {
    assert.ok(tokens.density.touch.control >= 56);
    assert.ok(tokens.density.touch.row >= 56);
    assert.ok(tokens.density.compact.control < tokens.density.comfortable.control);
    assert.ok(tokens.fontSize.body >= 16);
    assert.ok(tokens.fontSize.small >= 14);
});

test('modo oscuro: los textos de estado son legibles en su superficie y en filas oscuras', () => {
    for (const [name, signal] of Object.entries(tokens.signalDark)) {
        for (const surface of [signal.surface, tokens.colorDark.surface, tokens.colorDark.canvas]) {
            assert.ok(contrast(signal.text, surface) >= 4.5, `Contraste insuficiente (dark): ${name} / ${surface}`);
        }
    }
});

test('modo oscuro: texto general, acciones y foco conservan contraste suficiente', () => {
    for (const foreground of [tokens.colorDark.text, tokens.colorDark.muted]) {
        for (const background of [tokens.colorDark.surface, tokens.colorDark.canvas]) {
            assert.ok(contrast(foreground, background) >= 4.5, `Contraste insuficiente (dark): ${foreground} / ${background}`);
        }
    }
    for (const background of [tokens.colorDark.primary, tokens.colorDark.primaryHover, tokens.signalDark.success.text]) {
        assert.ok(contrast(tokens.colorDark.onPrimary, background) >= 4.5, `on-primary (dark) sobre ${background}`);
    }
    assert.ok(contrast(tokens.colorDark.inputBorder, tokens.colorDark.surface) >= 3);
    assert.ok(contrast(tokens.colorDark.focus, tokens.colorDark.canvas) >= 3);
});

test('frost (identidad) pasa contraste de texto en ambos temas, sobre canvas y superficie', () => {
    assert.ok(contrast(tokens.color.frost, tokens.color.canvas) >= 4.5, 'frost claro / canvas claro');
    assert.ok(contrast(tokens.color.frost, tokens.color.surface) >= 4.5, 'frost claro / surface claro');
    assert.ok(contrast(tokens.colorDark.frost, tokens.colorDark.canvas) >= 4.5, 'frost oscuro / canvas oscuro');
    assert.ok(contrast(tokens.colorDark.frost, tokens.colorDark.surface) >= 4.5, 'frost oscuro / surface oscuro');
    // FoliOS v2: frost es la marca y también la acción principal (una sola luz de acción).
    assert.equal(tokens.color.frost, tokens.color.primary);
    assert.equal(tokens.colorDark.frost, tokens.colorDark.primary);
});

test('FoliOS v2: las siete áreas del gajo son legibles en etiqueta, sobre blanco y sobre navy', () => {
    const areas = ['operacion', 'materiaPrima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion'];
    assert.deepEqual(Object.keys(tokens.area), areas);
    assert.deepEqual(Object.keys(tokens.areaDark), areas);
    for (const name of areas) {
        const light = tokens.area[name];
        const dark = tokens.areaDark[name];
        assert.ok(contrast(light.text, light.tint) >= 4.5, `${name}: texto / tinte`);
        assert.ok(contrast(light.text, tokens.color.surface) >= 4.5, `${name}: texto / etiqueta`);
        assert.ok(contrast(light.ink, tokens.color.surface) >= 3, `${name}: gajo / etiqueta`);
        assert.ok(contrast(light.bright, tokens.color.navy) >= 4.5, `${name}: gajo claro / navy`);
        for (const surface of [dark.tint, tokens.colorDark.surface, tokens.colorDark.canvas]) {
            assert.ok(contrast(dark.text, surface) >= 4.5, `${name} (oscuro): texto / ${surface}`);
        }
    }
});

test('FoliOS v2: sello y relleno crítico conservan contraste en ambos temas', () => {
    assert.ok(contrast(tokens.color.seal, tokens.color.sealSurface) >= 4.5);
    assert.ok(contrast(tokens.color.seal, tokens.color.surface) >= 4.5);
    assert.ok(contrast(tokens.colorDark.seal, tokens.colorDark.sealSurface) >= 4.5);
    assert.ok(contrast(tokens.color.onCriticalFill, tokens.color.criticalFill) >= 4.5);
    assert.ok(contrast(tokens.colorDark.onCriticalFill, tokens.colorDark.criticalFill) >= 4.5);
    assert.ok(contrast(tokens.color.onNavyMuted, tokens.color.navy) >= 4.5);
    assert.ok(contrast(tokens.color.onNavyMuted, tokens.color.navyHover) >= 4.5);
});
