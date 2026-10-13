/**
 * Paleta oscura de terreno FoliOS v2 para las pantallas que aún no usan operatorTheme
 * (Validación PT, Prefrío, Materiales y otras). Mismos valores que `colorDark`/`signalDark`
 * de design/estiba.tokens.json. Los colores del mapa de posiciones (free, pallet, saldo,
 * blocked) son semánticos del plano y no cambian.
 */
export const colors = {
  background: '#0F212B',
  backgroundDeep: '#0B1A22',
  panel: '#15303C',
  panelStrong: '#1C3B49',
  border: '#2A4D5C',
  borderSoft: '#24414F',
  cyan: '#5FD3E6',
  cyanDark: '#0F5C6E',
  blue: '#7DBCEB',
  green: '#6FD6A0',
  greenDark: '#11302A',
  amber: '#F3B94F',
  amberDark: '#2C2716',
  red: '#FF8B7B',
  redDark: '#2E1B1C',
  text: '#E8F3F6',
  textSecondary: '#C5D9DF',
  muted: '#9FBCC6',
  free: '#10283D',
  freeBorder: '#274761',
  pallet: '#53698F',
  palletBorder: '#7188B2',
  saldo: '#164A70',
  saldoBorder: '#397DA7',
  blocked: '#672C38',
  blockedBorder: '#A34C5D',
  selected: '#12313D',
  accentText: '#062530',
  shadow: '#000000',
} as const;
