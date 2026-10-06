import * as Updates from 'expo-updates';
import { resolveAppVariant, type AppVariant } from './resolveAppVariant';

function enabled(value: string | undefined): boolean {
  return value?.trim().toLowerCase() === 'true';
}

export const isDemoOnlyBuild = enabled(process.env.EXPO_PUBLIC_DEMO_ONLY);
export const isDemoRuntime = isDemoOnlyBuild || enabled(process.env.EXPO_PUBLIC_DEMO_MODE);

/**
 * Variante del APK. Se fija al compilar o publicar el bundle:
 * - `tablet`: operación de cámaras, Prefrío, Materiales y validaciones.
 * - `pda`: equipo de mano (Unitech EA520) para Validación PT, MP y Repaletizaje.
 *
 * Cada variante tiene su propio canal EAS (`production` y `pda`), de modo que
 * una actualización publicada para la tablet nunca llega a la PDA.
 */
export type { AppVariant } from './resolveAppVariant';

// El canal viene del binario instalado, no del bundle, y cuando existe manda:
// un bundle publicado por error en el canal equivocado no convierte una tablet
// en PDA ni al revés. Sin canal (Expo Go, `expo start`, exportación web) se usa
// la variable de entorno.
function nativeChannel(): string | null {
  try {
    return Updates.channel ?? null;
  } catch {
    return null;
  }
}

export const appChannel = nativeChannel();
export const appVariant: AppVariant = resolveAppVariant(appChannel, process.env.EXPO_PUBLIC_APP_VARIANT);
export const isPdaBuild = appVariant === 'pda';

/** Módulos que la PDA puede abrir; el resto del perfil se ignora en ese equipo. */
export const PDA_MODULES = ['validacion', 'validacion_mp', 'repaletizaje', 'recepcion_fruta_embalada'] as const;

export const deviceNoun = isPdaBuild ? 'PDA' : 'tablet';
