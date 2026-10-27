import type { AvailableValidationPrefill, ValidationPrefillField } from './validation';

/** Lo que el validador tiene hoy en pantalla, para compararlo con lo que informó la planta. */
export type ValidationPrefillForm = {
  packageType: 'pallet' | 'saldo';
  boxes: string;
  packingDate: string;
  articleId: string | null;
  categoryId: string;
  drafts: Array<{ originId: string; boxes: string; lot: string; process: string }>;
};

export const PREFILL_FIELD_LABELS: Record<ValidationPrefillField, string> = {
  tipo_bulto: 'tipo de bulto',
  cantidad_cajas: 'cajas',
  fecha_embalaje: 'fecha de embalaje',
  articulo: 'producto',
  categoria: 'categoría',
  composicion: 'composición',
};

function code(value: string | null | undefined) {
  return String(value ?? '').trim().toUpperCase();
}

/**
 * Campos en que el validador cambió lo que traía la planta. Completar un dato que la planta
 * no pudo traducir al catálogo no cuenta como cambio.
 */
export function prefillChanges(prefill: AvailableValidationPrefill, form: ValidationPrefillForm): ValidationPrefillField[] {
  const changes: ValidationPrefillField[] = [];
  if (form.packageType !== prefill.tipo_bulto) changes.push('tipo_bulto');
  if (Number(form.boxes) !== prefill.cantidad_cajas) changes.push('cantidad_cajas');
  if (prefill.fecha_embalaje && form.packingDate !== prefill.fecha_embalaje) changes.push('fecha_embalaje');
  if (prefill.articulo_validacion_id && form.articleId !== prefill.articulo_validacion_id) changes.push('articulo');
  if (prefill.categoria_validacion_id && form.categoryId !== prefill.categoria_validacion_id) changes.push('categoria');
  if (!sameComposition(prefill, form.drafts)) changes.push('composicion');
  return changes;
}

function sameComposition(prefill: AvailableValidationPrefill, drafts: ValidationPrefillForm['drafts']) {
  if (prefill.composicion.length !== drafts.length) return false;
  const remaining = [...drafts];
  for (const line of prefill.composicion) {
    const index = remaining.findIndex((draft) => (!line.origen_validacion_id || draft.originId === line.origen_validacion_id)
      && Number(draft.boxes) === line.cantidad_cajas
      && code(draft.lot) === code(line.lote_materia_prima)
      && code(draft.process) === code(line.proceso_packing));
    if (index < 0) return false;
    remaining.splice(index, 1);
  }
  return true;
}
