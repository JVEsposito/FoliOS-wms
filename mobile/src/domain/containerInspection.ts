export type InspectionItem = { tipo_envase: string; limpieza: boolean | null; condicion: 'buena' | 'regular' | 'mala' | null; nota?: string };
export type ContainerInspectionDraft = { items: InspectionItem[]; coincide_especie_variedad: boolean | null; coincide_cantidad_bins: boolean | null; bins_bien_etiquetados: boolean | null; observacion: string };
export function emptyContainerInspection(types: string[]): ContainerInspectionDraft {
  return { items: types.map((tipo_envase) => ({ tipo_envase, limpieza: null, condicion: null, nota: '' })), coincide_especie_variedad: null, coincide_cantidad_bins: null, bins_bien_etiquetados: null, observacion: '' };
}
export function inspectionPayload(draft: ContainerInspectionDraft, quantities: Array<{ tipo_envase: string; cantidad_validada: number }>) {
  const items = quantities.filter((item) => item.cantidad_validada > 0).map((quantity) => {
    const item = draft.items.find((value) => value.tipo_envase === quantity.tipo_envase);
    if (!item || item.limpieza === null || item.condicion === null) throw new Error('Completa limpieza y condición de cada tipo recibido para emitir RC-02.');
    return { tipo_envase: item.tipo_envase, limpieza: item.limpieza, condicion: item.condicion, nota: item.nota?.trim() || null };
  });
  for (const field of ['coincide_especie_variedad', 'coincide_cantidad_bins', 'bins_bien_etiquetados'] as const) if (draft[field] === null) throw new Error('Completa los tres controles Sí/No de RC-02.');
  return { ...draft, items, observacion: draft.observacion.trim() || null };
}
