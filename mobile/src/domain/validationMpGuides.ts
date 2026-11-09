import type { MpReception, MpSegmentDraft } from './validationMp';

/** Una guía puede dividirse por origen; ninguna se pierde del desglose. */
export function guideSegments(reception: MpReception, key: () => string): MpSegmentDraft[] {
  if ((reception.guias?.length ?? 0) < 2) return [];
  return reception.guias!.map((guide) => ({
    key: key(), guia_recepcion_romana_id: guide.id, motivos: ['guia'],
    csg_validacion_id: null, variedad_validacion_id: null, cuartel: '', bins_planta: null,
    cantidades: Object.fromEntries(guide.envases.map((item) => [item.tipo_envase, String(item.cantidad)])),
  }));
}
export function receptionGuideNumbers(reception: MpReception): string {
  return reception.guias?.length ? reception.guias.map((g) => g.numero_guia_despacho).join(' / ') : reception.numero_guia_despacho;
}
export function segmentGuideProblem(reception: MpReception, segments: MpSegmentDraft[]): string | null {
  if ((reception.guias?.length ?? 0) < 2) return null;
  if (segments.some((s) => !reception.guias!.some((g) => g.id === s.guia_recepcion_romana_id))) return 'Selecciona la guía de cada segmento.';
  const missing = reception.guias!.find((g) => !segments.some((s) => s.guia_recepcion_romana_id === g.id));
  return missing ? `La guía ${missing.numero_guia_despacho} no tiene un segmento. Completa su desglose.` : null;
}
