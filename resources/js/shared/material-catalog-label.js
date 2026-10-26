/** Las etiquetas son independientes de códigos, alias y snapshots de conversión. */
export const materialCatalogLabel = (value) => String(value ?? '').trim().replace(/\s+/gu, ' ').toLocaleUpperCase('es-CL');
