const TOKENS = {
    chartGrid: '--chart-grid',
    chartText: '--chart-text',
    chartTooltipBg: '--chart-tooltip-bg',
    chartTooltipBorder: '--chart-tooltip-border',
    textStrong: '--text-strong',
    seriesProduct: '--series-product',
    seriesMaterial: '--series-material',
    cyan: '--cyan',
    cyanLight: '--cyan-light',
    success: '--success-text',
    warning: '--warning-text',
    danger: '--danger-text',
    quiet: '--text-subtle',
};

export function normalizeComputedRgb(color) {
    if (/^rgba?\(/i.test(color)) return color;

    // color-mix(in srgb, ...) puede resolverse a color(srgb ...) en el navegador.
    const srgb = /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\s*\)$/i.exec(color);
    if (!srgb) throw new TypeError('El token no se resolvió a un color RGB compatible con el canvas.');

    const channels = srgb.slice(1, 4).map((value) => Math.round(Math.min(1, Math.max(0, Number(value))) * 255));
    return srgb[4] === undefined
        ? `rgb(${channels.join(', ')})`
        : `rgba(${channels.join(', ')}, ${srgb[4]})`;
}

export function withAlpha(rgb, alpha) {
    const match = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*[\d.]+)?\s*\)$/i.exec(rgb);
    if (!match || !Number.isFinite(alpha) || alpha < 0 || alpha > 1) {
        throw new TypeError('Se esperaba un color rgb() resuelto y una opacidad entre 0 y 1.');
    }

    return `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${alpha})`;
}

export function createManagementChartPalette(colors) {
    return {
        cyan: colors.cyan,
        cyanLight: colors.cyanLight,
        blue: colors.seriesProduct,
        purple: colors.seriesMaterial,
        green: colors.success,
        amber: colors.warning,
        red: colors.danger,
        quiet: colors.quiet,
        muted: colors.chartText,
        grid: withAlpha(colors.chartGrid, 0.45),
        tooltipBg: colors.chartTooltipBg,
        tooltipBorder: colors.chartTooltipBorder,
        tooltipText: colors.textStrong,
    };
}

export function readManagementChartPalette(root = document.body) {
    const probe = root.ownerDocument.createElement('span');
    probe.style.visibility = 'hidden';
    probe.style.position = 'absolute';
    probe.style.pointerEvents = 'none';
    root.append(probe);

    try {
        const resolved = Object.fromEntries(Object.entries(TOKENS).map(([key, token]) => {
            probe.style.color = `var(${token})`;
            try {
                return [key, normalizeComputedRgb(getComputedStyle(probe).color)];
            } catch {
                throw new Error(`El tema no resolvió ${token} a un color compatible con el canvas.`);
            }
        }));

        return createManagementChartPalette(resolved);
    } finally {
        probe.remove();
    }
}
