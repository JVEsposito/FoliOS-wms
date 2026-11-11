/**
 * Planilla POEMP R-24 «Control de despacho área frío» en la PDA de consolidación.
 * El servidor envía el catálogo del formulario; aquí solo hay lógica de pantalla.
 */

export type ConsolidationFieldType = 'texto' | 'opcion' | 'fecha' | 'hora' | 'entero' | 'nota';
export type ConsolidationField = { clave: string; etiqueta: string; tipo: ConsolidationFieldType; opciones?: string[]; grupo?: string };
export type ConsolidationCheck = { clave: string; etiqueta: string; aplica: 'contenedor' | 'thermo' | null };
export type ConsolidationPhotoType = { clave: string; nombre: string; obligatoria: boolean; maximo: number };
export type ConsolidationCatalog = {
  codigo: string; posicion_maxima: number;
  encabezado: ConsolidationField[]; inspeccion: ConsolidationCheck[]; cierre: ConsolidationField[]; tipos_foto: ConsolidationPhotoType[];
};

export type ConsolidationLoad = {
  id: string; codigo: string; estado: string; exportadora: string | null; embarque: string | null; contenedor: string | null;
  anden: string | null; patente: string | null; pallets: number; en_anden: number; cargados: number;
  control: { id: string; estado: 'borrador' | 'cerrado'; posicionados: number } | null;
};

export type ConsolidationPosition = {
  id: string; posicion: number; codigo_pe: string | null; cajas: number | null; temperatura: number | null; registrado_at: string | null; registrado_por: string | null;
};
export type ConsolidationPallet = {
  asignacion_id: string; folio_id: string; numero_folio: string; estado_carga: string; cargado_at: string | null;
  especie: string | null; variedad: string | null; cajas_folio: number; posicion: ConsolidationPosition | null;
};
export type ConsolidationPhoto = { id: string; tipo: string; orden: number; miniatura_url: string; url: string; subida_at: string | null };
export type ConsolidationValues = Record<string, string | number | null>;
export type ConsolidationControl = {
  id: string; estado: 'borrador' | 'cerrado'; version: number;
  carga: { id: string; codigo: string; estado: string; embarque: string | null; anden: string | null; patente: string | null };
  encabezado: ConsolidationValues; sugerido: { encabezado: ConsolidationValues; cierre: ConsolidationValues };
  inspeccion: Record<string, 'si' | 'no' | 'na' | null>; cierre: ConsolidationValues;
  pallets: ConsolidationPallet[];
  totales: { pallets: number; cargados: number; en_anden: number; en_camara: number; posicionados: number; con_temperatura: number; cajas_pcd: number; cajas_carga: number };
  iniciado_at: string | null; iniciado_por: string | null; cerrado_at: string | null; cerrado_por: string | null;
  reaperturas: { motivo: string; nombre: string; reabierto_at: string }[];
  fotos: ConsolidationPhoto[]; faltantes_cierre: string[];
};

export type ConsolidationSection = 'encabezado' | 'inspeccion' | 'cierre';
export type ConsolidationDraft = Partial<Record<ConsolidationSection, ConsolidationValues>>;

export const OPTION_LABELS: Record<string, string> = {
  agrorosario: 'Agrorosario', otra: 'Otra', contenedor: 'Contenedor', thermo: 'Thermo',
  si: 'Sí', no: 'No', na: 'No aplica', cumple: 'Cumple', no_cumple: 'No cumple',
};

export const HEADER_GROUPS: { key: string; title: string }[] = [
  { key: 'operacion', title: 'Operación' },
  { key: 'destino', title: 'Destino y embarque' },
  { key: 'transporte', title: 'Transporte' },
  { key: 'unidad', title: 'Contenedor o thermo' },
  { key: 'termografos', title: 'Termógrafos' },
];

/**
 * Columna P/E: orientación del pallet respecto del frente del camión. En un pallet
 * 1000 × 1200, punta lleva el lado angosto hacia el frente y espejo el lado ancho.
 */
export const PALLET_ORIENTATIONS: { code: 'P' | 'E'; label: string; hint: string }[] = [
  { code: 'P', label: 'Punta', hint: 'Lado angosto al frente' },
  { code: 'E', label: 'Espejo', hint: 'Lado ancho al frente' },
];

export function orientationLabel(code: string | null | undefined): string | null {
  return code ? PALLET_ORIENTATIONS.find((option) => option.code === code)?.label ?? code : null;
}

export const PALLET_STATE_LABELS: Record<string, string> = {
  pendiente: 'En cámara', con_incidencia: 'Con incidencia', en_anden: 'En andén', cargado: 'Cargado', despachado: 'Despachado',
};

export function consolidationStorageKey(baseUrl: string, userId: string, controlId: string) {
  return `folios.consolidacion.v1:${baseUrl}:${userId}:${controlId}`;
}

/** Punto de inspección que no corresponde al tipo de camión elegido. */
export function checkNotApplicable(check: ConsolidationCheck, truckType: unknown): boolean {
  return check.aplica !== null && (truckType === 'contenedor' || truckType === 'thermo') && check.aplica !== truckType;
}

/** Marca «no aplica» en los puntos del otro tipo de camión que siguen sin respuesta. */
export function fillNotApplicable(checks: ConsolidationCheck[], truckType: unknown, answers: Record<string, unknown>): Record<string, 'na'> {
  return Object.fromEntries(checks
    .filter((check) => checkNotApplicable(check, truckType) && !answers[check.clave])
    .map((check) => [check.clave, 'na' as const]));
}

/** Valor visible de un campo: el borrador local manda sobre lo que mandó el servidor. */
export function fieldValue(control: ConsolidationControl, draft: ConsolidationDraft, section: ConsolidationSection, key: string): string {
  const local = draft[section]?.[key];
  const value = local !== undefined ? local : section === 'inspeccion' ? control.inspeccion[key] : control[section][key];
  return value === null || value === undefined ? '' : String(value);
}

/** Cambios del borrador que difieren de lo guardado; los enteros se envían como número. */
export function sectionChanges(control: ConsolidationControl, draft: ConsolidationDraft, section: ConsolidationSection, fields: { clave: string; tipo?: string }[]): ConsolidationValues {
  const changes: ConsolidationValues = {};
  for (const field of fields) {
    const local = draft[section]?.[field.clave];
    if (local === undefined) continue;
    const saved = section === 'inspeccion' ? control.inspeccion[field.clave] : control[section][field.clave];
    const text = local === null ? '' : String(local).trim();
    if (text === (saved === null || saved === undefined ? '' : String(saved))) continue;
    if (field.tipo === 'entero') {
      if (text === '') { changes[field.clave] = null; continue; }
      const number = Number(text);
      if (!Number.isInteger(number) || number < 0) throw new Error(`Revisa «${'etiqueta' in field ? (field as ConsolidationField).etiqueta : field.clave}»: debe ser un número entero.`);
      changes[field.clave] = number;
    } else {
      changes[field.clave] = text === '' ? null : text;
    }
  }
  return changes;
}

export function hasDraftChanges(draft: ConsolidationDraft): boolean {
  return Object.values(draft).some((values) => values && Object.keys(values).length > 0);
}

/** Primera posición libre: la que sigue para el próximo pallet. */
export function nextFreePosition(pallets: ConsolidationPallet[], max = 60): number {
  const used = new Set(pallets.map((pallet) => pallet.posicion?.posicion).filter((value): value is number => typeof value === 'number'));
  for (let position = 1; position <= max; position++) if (!used.has(position)) return position;
  return max;
}

/** Folio que ya ocupa la posición, sin contar el mismo pallet. */
export function positionOccupant(pallets: ConsolidationPallet[], position: number, folio: string): string | null {
  const normalized = folio.trim().toUpperCase();
  const other = pallets.find((pallet) => pallet.posicion?.posicion === position && pallet.numero_folio.toUpperCase() !== normalized);
  return other?.numero_folio ?? null;
}

export function parsePosition(text: string, max = 60): number {
  const value = Number(text.trim());
  if (!Number.isInteger(value) || value < 1 || value > max) throw new Error(`La posición va de 1 a ${max}.`);
  return value;
}

/** Temperatura de pulpa: acepta coma o punto; vacío es «sin medir». */
export function parseTemperature(text: string): number | null {
  const clean = text.trim().replace(',', '.');
  if (clean === '') return null;
  const value = Number(clean);
  if (!Number.isFinite(value) || value < -10 || value > 40) throw new Error('La temperatura de pulpa va de -10 a 40 °C.');
  return Math.round(value * 10) / 10;
}

export function parseBoxes(text: string): number | null {
  const clean = text.trim();
  if (clean === '') return null;
  const value = Number(clean);
  if (!Number.isInteger(value) || value < 0 || value > 9999) throw new Error('Las cajas deben ser un número entero.');
  return value;
}

export function formatTemperature(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${String(value).replace('.', ',')} °C`;
}

/** Pallets para la lista de la PDA: primero los que esperan subir, luego por posición. */
export function palletsForList(pallets: ConsolidationPallet[]): ConsolidationPallet[] {
  const rank = (pallet: ConsolidationPallet) => (pallet.estado_carga === 'en_anden' ? 0 : pallet.posicion ? 2 : pallet.estado_carga === 'cargado' ? 1 : 3);
  return [...pallets].sort((a, b) => rank(a) - rank(b)
    || (a.posicion?.posicion ?? 999) - (b.posicion?.posicion ?? 999)
    || a.numero_folio.localeCompare(b.numero_folio));
}

/** Suma de los tipos de pallet declarados en el cierre. */
export function declaredPallets(values: (key: string) => string): number {
  return ['pallets_taco_1000', 'pallets_chep_1000', 'pallets_yugo_1000', 'pallets_extendido_1016', 'pallets_taco_800']
    .reduce((total, key) => total + (Number(values(key)) || 0), 0);
}
