export type PackingReturnContainer = 'bins' | 'caja_3_4';

export function packingReturnContainerPayload(type: PackingReturnContainer, quantity: number) {
  if (!['bins', 'caja_3_4'].includes(type) || !Number.isInteger(quantity) || quantity < 1 || quantity > 100000) {
    throw new Error('Indica un envase válido y una cantidad entera entre 1 y 100000.');
  }
  return { tipo_envase: type, cantidad_envases: quantity, cantidad_bins: type === 'bins' ? quantity : 0 };
}

export function packingReturnUnits(record: { tipo_envase?: string; cantidad_envases?: number; cantidad_bins?: number }) {
  const quantity = record.cantidad_envases ?? record.cantidad_bins ?? 1;
  return `${new Intl.NumberFormat('es-CL').format(quantity)} ${record.tipo_envase === 'caja_3_4' ? 'CAJAS 3/4' : 'BINS'}`;
}

export function packingReturnSummary(summary: { bins_retornados: number; cajas_3_4_retornadas?: number }) {
  const bins = `${new Intl.NumberFormat('es-CL').format(summary.bins_retornados)} BINS`;
  return summary.cajas_3_4_retornadas
    ? `${bins} · ${new Intl.NumberFormat('es-CL').format(summary.cajas_3_4_retornadas)} CAJAS 3/4`
    : bins;
}
