export type AppVariant = 'tablet' | 'pda';

export function resolveAppVariant(channel: string | null, fallback?: string): AppVariant {
  const name = (channel || fallback || '').trim().toLowerCase();
  return name.startsWith('pda') ? 'pda' : 'tablet';
}
