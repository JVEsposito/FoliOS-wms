import type { ValidationCatalog } from './validation';

export function assertValidationCatalog(value: unknown): asserts value is ValidationCatalog {
  const catalog = value as ValidationCatalog | null;
  if (!catalog || typeof catalog !== 'object' || !catalog.temporada
    || typeof catalog.temporada.id !== 'string' || !catalog.temporada.id
    || !Number.isInteger(catalog.temporada.version_catalogo) || catalog.temporada.version_catalogo < 1
    || !['categorias', 'articulos', 'origenes', 'combinaciones'].every((key) => Array.isArray((catalog as unknown as Record<string, unknown>)[key]))) {
    throw new Error('El servidor entregó un catálogo incompleto. Se conserva la última copia válida; vuelve a sincronizar.');
  }
  const articles = new Set(catalog.articulos.map((row) => row?.id));
  const origins = new Set(catalog.origenes.map((row) => row?.id));
  if (catalog.articulos.some((row) => !row || typeof row.id !== 'string' || !row.id)
    || catalog.origenes.some((row) => !row || typeof row.id !== 'string' || !row.id)
    || catalog.combinaciones.some((row) => !row || !articles.has(row.articulo_validacion_id) || !origins.has(row.origen_validacion_id))) {
    throw new Error('El catálogo contiene combinaciones sin artículo u origen. Se conserva la última copia válida; informa a Oficina.');
  }
}

/** No copiar timestamps ni campos internos de cada fila a la PDA. */
export function compactValidationCatalog(catalog: ValidationCatalog): ValidationCatalog {
  assertValidationCatalog(catalog);
  return {
    temporada: catalog.temporada,
    categorias: catalog.categorias.map(({ id, temporada_id, nombre, codigo_externo, activo }) => ({ id, temporada_id, nombre, codigo_externo, activo: Boolean(activo) })),
    articulos: catalog.articulos.map(({ id, temporada_id, variedad_validacion_id, especie, variedad, calibre, envase, codigo_externo, activo }) => ({ id, temporada_id, variedad_validacion_id, especie, variedad, calibre, envase, codigo_externo, activo: Boolean(activo) })),
    origenes: catalog.origenes.map(({ id, temporada_id, cliente_validacion_id, csg_validacion_id, variedad_ids, cliente, marca, csg, predio, codigo_externo, activo }) => ({ id, temporada_id, cliente_validacion_id, csg_validacion_id, variedad_ids, cliente, marca, csg, predio, codigo_externo, activo: Boolean(activo) })),
    combinaciones: catalog.combinaciones.map(({ id, articulo_validacion_id, origen_validacion_id, codigo_externo }) => ({ id, articulo_validacion_id, origen_validacion_id, codigo_externo })),
    tipos_bulto: catalog.tipos_bulto,
    resultados: catalog.resultados,
    motivos: catalog.motivos,
    generado_at: catalog.generado_at,
  };
}
