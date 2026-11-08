import type { ValidationCatalog } from './validation';
import { assertValidationCatalog } from './validationCatalogData';
import { catalogCombinationCount, createCatalogCompatibilityBuilder } from './validationCatalogIndex';

type Master = 'categorias' | 'articulos' | 'origenes';
type Change<T, K> = { altas: T[]; retiros: K[] };
type Pair = [articleId: string, originId: string];
type Delta = Pick<ValidationCatalog, 'temporada' | 'tipos_bulto' | 'resultados' | 'motivos' | 'generado_at'> & {
  formato: 'delta-v1';
  base: number;
  conteos: Record<Master | 'combinaciones', number>;
  cambios: { [K in Master]: Change<ValidationCatalog[K][number], string> } & { combinaciones: Change<Pair, Pair> };
};

function invalid(): never {
  throw new Error('Los cambios no coinciden con el catálogo guardado.');
}

function applyRows<T extends { id: string; temporada_id: string; activo: boolean }>(
  rows: T[], change: Change<T, string>, seasonId: string, count: number,
): T[] {
  if (!Array.isArray(change?.altas) || !Array.isArray(change.retiros)) invalid();
  if (change.altas.length === 0 && change.retiros.length === 0) {
    if (rows.length !== count) invalid();
    return rows;
  }
  const result = new Map(rows.map((row) => [row.id, row]));
  for (const id of change.retiros) {
    if (typeof id !== 'string' || !result.delete(id)) invalid();
  }
  const seen = new Set<string>();
  for (const row of change.altas) {
    if (!row || typeof row.id !== 'string' || !row.id || row.temporada_id !== seasonId || row.activo !== true || seen.has(row.id)) invalid();
    seen.add(row.id);
    result.set(row.id, row);
  }
  if (result.size !== count) invalid();
  return [...result.values()];
}

function pairsByOrigin(pairs: Pair[]): Map<string, Set<string>> {
  if (!Array.isArray(pairs)) invalid();
  const result = new Map<string, Set<string>>();
  for (const pair of pairs) {
    if (!Array.isArray(pair) || pair.length !== 2 || pair.some((id) => typeof id !== 'string' || !id)) invalid();
    const [article, origin] = pair;
    const articles = result.get(origin) ?? new Set<string>();
    if (articles.has(article)) invalid();
    articles.add(article);
    result.set(origin, articles);
  }
  return result;
}

export function applyValidationCatalogDelta(base: ValidationCatalog, data: unknown): ValidationCatalog {
  const delta = data as Delta;
  const seasonId = base.temporada.id;
  if (delta?.formato !== 'delta-v1' || delta.temporada?.id !== seasonId || delta.base !== base.temporada.version_catalogo
    || !Number.isSafeInteger(delta.temporada.version_catalogo) || delta.temporada.version_catalogo <= delta.base
    || !Array.isArray(delta.tipos_bulto) || !Array.isArray(delta.resultados) || !Array.isArray(delta.motivos)
    || !delta.cambios?.combinaciones || !delta.conteos) invalid();

  const { cambios, conteos, temporada, tipos_bulto, resultados, motivos, generado_at } = delta;
  const categorias = applyRows(base.categorias, cambios.categorias, seasonId, conteos.categorias);
  const articulos = applyRows(base.articulos, cambios.articulos, seasonId, conteos.articulos);
  const origenes = applyRows(base.origenes, cambios.origenes, seasonId, conteos.origenes);
  const added = pairsByOrigin(cambios.combinaciones.altas);
  const retired = pairsByOrigin(cambios.combinaciones.retiros);
  const result: ValidationCatalog = { temporada, tipos_bulto, resultados, motivos, generado_at, categorias, articulos, origenes, combinaciones: [] };

  if (base.compatibilidad && !cambios.articulos.retiros.length && !cambios.origenes.retiros.length
    && origenes.length === base.origenes.length && !added.size && !retired.size) {
    result.compatibilidad = base.compatibilidad;
  } else {
    const builder = createCatalogCompatibilityBuilder(result);
    const appendOld = (article: string, origin: string) => {
      if (retired.get(origin)?.delete(article)) return;
      if (added.get(origin)?.has(article)) invalid();
      builder.appendPair(article, origin);
    };
    if (base.compatibilidad) {
      const { offsets, indices } = base.compatibilidad;
      base.origenes.forEach((origin, position) => {
        for (let i = offsets[position]; i < offsets[position + 1]; i++) {
          appendOld(base.articulos[indices[i]].id, origin.id);
        }
      });
    } else {
      base.combinaciones.forEach((row) => appendOld(row.articulo_validacion_id, row.origen_validacion_id));
    }
    for (const articles of retired.values()) {
      if (articles.size) invalid();
    }
    for (const [origin, articles] of added) {
      for (const article of articles) builder.appendPair(article, origin);
    }
    result.compatibilidad = builder.finish();
  }

  if (catalogCombinationCount(result) !== conteos.combinaciones) invalid();
  assertValidationCatalog(result);
  return result;
}
