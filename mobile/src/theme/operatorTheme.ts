import { estibaTokens as t } from './estibaTokens';
import { font } from './typography';

/**
 * Tema de terreno FoliOS v2 (tablet del camarero y PDA): siempre oscuro.
 *
 * En cámara se trabaja con luz artificial y poca luminosidad. Los valores son los de
 * `colorDark`/`signalDark` de design/estiba.tokens.json (verificados en
 * tests/JavaScript/OperatorTabletVisualTest.mjs) y se escriben aquí en literal para que
 * la prueba de contraste los lea. Ver docs/diseno/folios-v2/README.md § 1.2 y § 5.
 */
export const operatorTheme = {
  color: {
    canvas: '#0F212B',
    surface: '#15303C',
    surfaceMuted: '#1C3B49',
    navy: '#0B1A22',
    navyRaised: '#1C3B49',
    onNavy: '#E8F3F6',
    text: '#E8F3F6',
    textSecondary: '#C5D9DF',
    muted: '#9FBCC6',
    border: '#2A4D5C',
    borderStrong: '#648D9D',
    primary: '#5FD3E6',
    primaryPressed: '#8DE3F0',
    onPrimary: '#062530',
    selected: '#12313D',
    success: '#6FD6A0',
    successSurface: '#11302A',
    successBorder: '#2F6B52',
    warning: '#F3B94F',
    warningSurface: '#2C2716',
    warningBorder: '#7A5F22',
    critical: '#FF8B7B',
    criticalSurface: '#2E1B1C',
    criticalBorder: '#7D3F38',
    infoBorder: '#2F6F80',
    disabled: '#24414F',
    disabledText: '#9FBCC6',
    shadow: '#000000',
  },
  space: t.space,
  radius: {
    control: 10,
    panel: 14,
    pill: 999,
  },
  type: {
    caption: 13,
    small: 15,
    body: 16,
    heading: 22,
    title: 28,
    folio: 30,
    display: 36,
  },
  font: {
    // Inter / JetBrains Mono, cargadas en App.tsx (ver theme/typography.ts). Las claves
    // condensed y mono se conservan por compatibilidad con estilos armados en código.
    ...font,
    condensed: font.heavy,
    mono: font.monoBold,
  },
  touch: {
    minimum: t.density.touch.control,
    prominent: 64,
  },
  breakpoint: {
    compact: 800,
  },
} as const;

export type OperatorTone = 'neutral' | 'info' | 'success' | 'warning' | 'critical';
