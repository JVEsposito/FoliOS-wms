import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createManagementChartPalette, normalizeComputedRgb, readManagementChartPaletteSafely, withAlpha } from '../../resources/js/shared/management-chart-theme.js';

test('withAlpha acepta RGB computado con comas y espacios y rechaza un token sin resolver', () => {
    assert.equal(withAlpha('rgb(31, 68, 95)', 0.25), 'rgba(31, 68, 95, 0.25)');
    assert.equal(withAlpha('rgb(31 68 95)', 0.5), 'rgba(31, 68, 95, 0.5)');
    assert.throws(() => withAlpha('var(--chart-grid)', 0.5), TypeError);
    assert.throws(() => withAlpha('rgb(31, 68, 95)', 2), TypeError);
});

test('un token color-mix computado en sRGB se convierte a un color apto para la paleta', () => {
    assert.equal(normalizeComputedRgb('color(srgb 0.2 0.4 0.6)'), 'rgb(51, 102, 153)');
    assert.equal(normalizeComputedRgb('color(srgb 0.2 0.4 0.6 / 0.5)'), 'rgba(51, 102, 153, 0.5)');
    assert.throws(() => normalizeComputedRgb('var(--chart-grid)'), TypeError);
});

test('la paleta usa series del tema, grilla atenuada y tooltip legible', () => {
    const colors = {
        chartGrid: 'rgb(100, 120, 140)', chartText: 'rgb(36, 54, 72)',
        chartTooltipBg: 'rgb(244, 247, 249)', chartTooltipBorder: 'rgb(100, 120, 140)',
        textStrong: 'rgb(14, 36, 52)', seriesProduct: 'rgb(14, 99, 157)',
        seriesMaterial: 'rgb(105, 81, 155)', cyan: 'rgb(14, 99, 157)',
        cyanLight: 'rgb(15, 100, 157)', success: 'rgb(31, 114, 70)',
        warning: 'rgb(130, 82, 0)', danger: 'rgb(164, 43, 57)', quiet: 'rgb(82, 107, 121)',
    };
    const palette = createManagementChartPalette(colors);

    assert.equal(palette.blue, colors.seriesProduct);
    assert.equal(palette.purple, colors.seriesMaterial);
    assert.equal(palette.grid, 'rgba(100, 120, 140, 0.45)');
    assert.equal(palette.tooltipBg, colors.chartTooltipBg);
    assert.equal(palette.tooltipText, colors.textStrong);
});

test('un token incompatible conserva la paleta anterior o los valores por defecto en la primera carga', () => {
    const fallback = { blue: 'Chart.js default' };
    const previous = { blue: 'tema anterior' };
    const next = { blue: 'tema nuevo' };
    const incompatible = () => { throw new TypeError('Formato de color no compatible'); };

    assert.equal(readManagementChartPaletteSafely(null, fallback, incompatible), fallback);
    assert.equal(readManagementChartPaletteSafely(previous, fallback, incompatible), previous);
    assert.equal(readManagementChartPaletteSafely(previous, fallback, () => next), next);
});

test('los cuatro temas tienen tokens propios y gerencia no reintroduce literales ni el plugin de la dona', () => {
    const js = readFileSync(new URL('../../resources/js/office-management.js', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/office-corporate.css', import.meta.url), 'utf8');
    assert.doesNotMatch(js, /(?<!&)#[\da-f]{3,8}\b|\b(?:rgba?|hsla?)\s*\(|\bcenterLabel\b/i);

    for (const theme of ['dark-industrial', 'light-professional', 'light-natural', 'light-warm']) {
        const blocks = [...css.matchAll(new RegExp(`:root\\[data-office-theme="${theme}"\\]\\s*\\{([^}]+)\\}`, 'g'))];
        for (const token of ['chart-grid', 'chart-text', 'chart-tooltip-bg', 'chart-tooltip-border', 'series-product', 'series-material']) {
            assert.ok(blocks.some(([, body]) => body.includes(`--${token}:`)), `${theme}: falta --${token}`);
        }
    }
});
