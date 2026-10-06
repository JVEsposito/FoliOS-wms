export type ReceptionPallet = {
  id?: string;
  folio_origen: string;
  tipo_bulto: 'pallet' | 'saldo';
  origen_validacion_id: string;
  articulo_validacion_id: string;
  especie?: string;
  variedad?: string;
  embalaje?: string;
  calibre?: string;
  csg?: string;
  csp: string | null;
  cantidad_cajas: number;
  fecha_proceso_origen: string;
  temperatura_pulpa_c: number | string;
  condicion_sag_id: string | null;
  condicion_sag_personalizada: boolean;
  folio_repetido?: boolean;
};
export type ReceptionHeader = {
  temporada_id: string;
  cliente_id: string;
  planta_origen_id: string;
  numero_guia: string;
  servicio: 'almacenaje' | 'prefrio';
  turno: string;
  validador_id: number;
  recepcion_at: string;
  salida_at: string | null;
  chofer: string;
  rut_chofer: string | null;
  patente_delantera: string;
  patente_carro: string | null;
  llega_con_prefrio: boolean;
  condicion_sag_id: string | null;
  observacion: string | null;
};
export type ReceptionPayload = ReceptionHeader & { operacion_id: string; version_conocida?: number; confirmar_guia_duplicada?: boolean; pallets: ReceptionPallet[] };
export type Reception = ReceptionHeader & {
  id: string; estado: 'borrador' | 'aceptada' | 'anulada'; version: number; editable: boolean;
  pallets: ReceptionPallet[]; pallets_count?: number;
  cliente?: { nombre: string }; planta_origen?: { nombre: string };
};
export type ReceptionOptions = {
  temporada: { id: string; nombre: string } | null;
  clientes: { id: string; nombre: string; catalogo_validacion_ids: string[] }[];
  plantas_origen: { id: string; nombre: string }[];
  condiciones_sag: { id: string; nombre: string }[];
  validadores: { id: number; name: string }[];
};
// Envía exclusivamente la captura. No permite propagar estado ni referencias de inventario.
export function palletPayload(p: ReceptionPallet): ReceptionPallet {
  return {
    ...(p.id ? { id: p.id } : {}), folio_origen: p.folio_origen.trim().toUpperCase(), tipo_bulto: p.tipo_bulto,
    origen_validacion_id: p.origen_validacion_id, articulo_validacion_id: p.articulo_validacion_id,
    csp: p.csp || null, cantidad_cajas: Number(p.cantidad_cajas), fecha_proceso_origen: p.fecha_proceso_origen,
    temperatura_pulpa_c: Number(p.temperatura_pulpa_c), condicion_sag_id: p.condicion_sag_id,
    condicion_sag_personalizada: p.condicion_sag_personalizada,
  };
}
