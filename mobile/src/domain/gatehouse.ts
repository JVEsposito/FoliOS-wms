export type GatehouseReason = 'carga' | 'vacio' | 'servicio';
export type GatehouseUnit = 'bins' | 'bultos';
export type GatehouseClient = { id: string; nombre: string };
export type GatehouseDraft = {
  cliente_id: string; cliente_otro: string; otro: boolean;
  motivo: GatehouseReason; patente: string; patente_carro: string;
  tipo_camion: string; nombre_conductor: string; guias: string[];
  cantidad: string; unidad: GatehouseUnit | ''; observacion: string;
};
export type GatehousePayload = {
  operacion_id: string; cliente_id: string | null; cliente_otro: string | null;
  motivo: GatehouseReason; patente: string; patente_carro: string | null;
  tipo_camion: string; nombre_conductor: string; guias: string[];
  cantidad: number | null; unidad: GatehouseUnit | null; observacion: string | null;
};
export type GatehouseEntry = {
  id: number; codigo: string; cliente_id: string | null; cliente_nombre: string;
  cliente_es_otro: boolean; motivo: GatehouseReason; patente: string;
  patente_carro: string | null; tipo_camion: string; nombre_conductor: string;
  guias: string[]; cantidad: number | null; unidad: GatehouseUnit | null;
  observacion: string | null; ingreso_at: string; creado_por_nombre: string;
  revisado_at: string | null; revisado_por_nombre: string | null;
  anulado_at: string | null; motivo_anulacion: string | null; version: number;
};
export type GatehousePage = {
  data: GatehouseEntry[]; pagina: number; ultima_pagina: number; total: number; fecha: string;
};
export type GatehouseDetail = {
  data: GatehouseEntry;
  eventos: { accion: string; user_nombre: string; created_at: string; datos: unknown }[];
};
export type GatehousePending = { payload: GatehousePayload };

export function blankGatehouseDraft(): GatehouseDraft {
  return { cliente_id: '', cliente_otro: '', otro: false, motivo: 'carga', patente: '',
    patente_carro: '', tipo_camion: '', nombre_conductor: '', guias: [''],
    cantidad: '', unidad: '', observacion: '' };
}

export function gatehousePayload(draft: GatehouseDraft, operationId: string): GatehousePayload {
  const plate = (value: string) => value.trim().toUpperCase().replace(/[\s-]+/g, '');
  const patente = plate(draft.patente);
  const carro = plate(draft.patente_carro);
  if (!/^[A-Z0-9]{4,12}$/.test(patente)) throw new Error('Ingresa una patente de 4 a 12 letras y números.');
  if (carro && !/^[A-Z0-9]{4,12}$/.test(carro)) throw new Error('Revisa la patente del carro.');
  if (draft.otro ? !draft.cliente_otro.trim() : !draft.cliente_id) throw new Error('Selecciona el cliente o completa Otro.');
  if (draft.otro && draft.cliente_otro.trim().length > 180) throw new Error('El nombre de Otro admite hasta 180 caracteres.');
  if (!draft.nombre_conductor.trim() || !draft.tipo_camion.trim()) throw new Error('Completa conductor y tipo de camión.');
  if (draft.nombre_conductor.trim().length > 180 || draft.tipo_camion.trim().length > 80) throw new Error('Revisa el largo del conductor o tipo de camión.');
  const guias = draft.guias.map((value) => value.trim()).filter(Boolean);
  if (draft.motivo === 'carga' && !guias.length) throw new Error('Para un camión con carga, agrega al menos una guía.');
  if (guias.length > 30 || guias.some((value) => value.length > 50)) throw new Error('Admite hasta 30 guías de 50 caracteres.');
  if (new Set(guias).size !== guias.length) throw new Error('Una guía se repite en este ingreso.');
  const quantity = draft.cantidad.trim();
  if (quantity && (!/^\d+$/.test(quantity) || Number(quantity) > 1_000_000)) throw new Error('La cantidad debe ser un entero entre 0 y 1.000.000.');
  if ((quantity !== '') !== (draft.unidad !== '')) throw new Error('Completa cantidad y unidad juntas, o deja ambas vacías.');
  if (draft.observacion.trim().length > 500) throw new Error('La observación admite hasta 500 caracteres.');
  return { operacion_id: operationId, cliente_id: draft.otro ? null : draft.cliente_id,
    cliente_otro: draft.otro ? draft.cliente_otro.trim() : null, motivo: draft.motivo,
    patente, patente_carro: carro || null, tipo_camion: draft.tipo_camion.trim(),
    nombre_conductor: draft.nombre_conductor.trim(), guias, cantidad: quantity === '' ? null : Number(quantity),
    unidad: draft.unidad || null, observacion: draft.observacion.trim() || null };
}

export function gatehouseDraftFromPayload(payload: GatehousePayload): GatehouseDraft {
  return { cliente_id: payload.cliente_id ?? '', cliente_otro: payload.cliente_otro ?? '',
    otro: payload.cliente_id === null, motivo: payload.motivo, patente: payload.patente,
    patente_carro: payload.patente_carro ?? '', tipo_camion: payload.tipo_camion,
    nombre_conductor: payload.nombre_conductor, guias: payload.guias.length ? [...payload.guias] : [''],
    cantidad: payload.cantidad === null ? '' : String(payload.cantidad),
    unidad: payload.unidad ?? '', observacion: payload.observacion ?? '' };
}

export function gatehousePendingKey(baseUrl: string, userId: string, deviceId: string): string {
  return 'folios:porteria:pendiente:' + [baseUrl.replace(/\/+$/, ''), userId, deviceId].map(encodeURIComponent).join(':');
}

export function parseGatehousePending(raw: string | null): GatehousePending | null {
  if (raw === null) return null;
  try {
    const parsed = JSON.parse(raw) as GatehousePending;
    const p = parsed.payload;
    if (!p || typeof p.operacion_id !== 'string' || !p.operacion_id
      || typeof p.patente !== 'string' || typeof p.nombre_conductor !== 'string'
      || typeof p.tipo_camion !== 'string' || !['carga', 'vacio', 'servicio'].includes(p.motivo)
      || !Array.isArray(p.guias) || p.guias.some((value) => typeof value !== 'string')
      || (p.cliente_id !== null && typeof p.cliente_id !== 'string')
      || (p.cliente_otro !== null && typeof p.cliente_otro !== 'string')
      || (p.patente_carro !== null && typeof p.patente_carro !== 'string')
      || (p.cantidad !== null && (typeof p.cantidad !== 'number' || !Number.isFinite(p.cantidad)))
      || (p.unidad !== null && !['bins', 'bultos'].includes(p.unidad))
      || (p.observacion !== null && typeof p.observacion !== 'string')) throw new Error();
    gatehousePayload(gatehouseDraftFromPayload(p), p.operacion_id);
    return { payload: p };
  } catch {
    throw new Error('No se pudo leer el envío pendiente. Conserva los datos del equipo y solicita ayuda antes de registrar otro camión.');
  }
}

export function gatehouseStatus(entry: GatehouseEntry): string {
  return entry.anulado_at ? 'Anulado' : entry.revisado_at ? 'Revisado en Romana' : 'Pendiente de Romana';
}
