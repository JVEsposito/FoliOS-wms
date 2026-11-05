/**
 * Tipografía de terreno FoliOS: Inter para lectura y JetBrains Mono para folios, posiciones
 * y códigos. En React Native `fontWeight` no elige el archivo de una fuente cargada, así que
 * cada peso es su propia familia; los estilos usan `font.<peso>` y no llevan `fontWeight`.
 *
 * Las fuentes vienen dentro del paquete JS y se registran con expo-font (ya enlazado en el
 * APK), por lo que llegan por actualización OTA. Si no cargan, Android usa la fuente del
 * sistema con el mismo nombre de familia desconocido: el texto sigue legible.
 * Ver docs/diseno/folios-v2/pr-13-tablet-revision.md.
 */
export const font = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
  heavy: 'Inter_800ExtraBold',
  mono: 'JetBrainsMono_500Medium',
  monoBold: 'JetBrainsMono_700Bold',
} as const;

export type FontWeightName = 'regular' | 'medium' | 'semibold' | 'bold' | 'heavy';

/** Familia equivalente a un `fontWeight` numérico, para estilos que se arman en código. */
export function fontForWeight(weight?: string | number | null, mono = false): string {
  const value = weight === 'bold' ? 700 : weight === 'normal' || weight == null ? 400 : Number(weight);
  if (mono) return value >= 600 ? font.monoBold : font.mono;
  if (value >= 800) return font.heavy;
  if (value >= 700) return font.bold;
  if (value >= 600) return font.semibold;
  if (value >= 500) return font.medium;
  return font.regular;
}
