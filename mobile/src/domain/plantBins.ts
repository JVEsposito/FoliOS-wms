/**
 * Bins de la planta que completan una recepción cuando el campo manda más totes de los que
 * caben en sus bins: con 24 totes por bin, 31 totes sobre 1 bin necesitan 1 bin más.
 *
 * Es solo una sugerencia con la referencia de la especie. El validador la confirma, la cambia
 * o deja 0; la planta presta esos bins a la cuenta del cliente y los recupera en el destare.
 */
export function suggestedPlantBins(bins: number, totes: number, totesPerBin: number | null | undefined): number {
  if (!totesPerBin || totesPerBin < 1 || !Number.isFinite(totes) || totes <= 0) return 0;
  return Math.max(0, Math.ceil(totes / totesPerBin) - Math.max(0, Math.floor(Number.isFinite(bins) ? bins : 0)));
}

/** Sin editar (null) usa la sugerencia; lo escrito por el validador manda, y un campo vacío es 0. */
export function resolvePlantBins(typed: string | null, suggestion: number): number {
  return typed === null ? suggestion : Number(typed.replace(/\D/g, '') || 0);
}

export function plantBinsHint(bins: number, totes: number, totesPerBin: number | null | undefined): string {
  if (!totesPerBin) return 'La especie no tiene referencia de totes por bin. Indica los bins de planta si agregaste alguno.';
  const needed = Math.ceil(Math.max(0, totes) / totesPerBin);
  return `${totes} totes a ${totesPerBin} por bin necesitan ${needed} ${needed === 1 ? 'bin' : 'bins'}; llegaron ${bins}.`;
}
