export type SagKind = 'repaletizaje' | 'anulacion' | 'cambio_mercado' | 'despacho';
export type SagLine = {
  folio_id: string; folio: string; especie: string; cantidad: number; linea_id?: string;
  variedad?: string; kilos?: number | null; mercados_anteriores?: string; nuevo_destino?: string;
  tipo_actividad?: string; numero_actividad?: string; causal?: string; con_condicion?: boolean;
  provincia?: string; comuna?: string; csg?: string; csp?: string; incluido?: boolean;
};
export type SagInstruction = { id: string; numero: string; destino_pais: string; destino_ciudad: string; recibidor: string; planilla_sag?: string };
export type SagDispatch = Record<string, string | boolean | null>;
export type SagSource = {
  repaletizaje_id?: string; lote_inspeccion_sag_id?: string; embarque_id?: string;
  fecha_actividad: string; solicitud?: string; originales?: SagLine[]; resultados?: { folio: string; cantidad: number }[];
  lineas?: SagLine[]; transporte?: SagDispatch; instructivo_id?: string; instructivos?: SagInstruction[];
};
export type SagDocument = {
  id: string; tipo: SagKind; estado: string; numero: number | null; version: number; fecha_actividad: string; responsable_id: string;
  encabezado: { observaciones?: string; programa?: string; motivo?: string; certificados?: number; certificados_devueltos?: number; certificados_asignados?: number; despacho?: SagDispatch };
  detalle: SagSource; entrada?: SagPayload;
  archivos?: { id: string; nombre: string }[];
  historial?: { tipo: string; created_at: string; datos: { referencia?: string; resuelto_por?: string } }[];
};
export type SagForm = {
  tipo: SagKind; fecha: string; responsable: string; referencia: string; observaciones: string; programa: string; motivo: string;
  certificados: string; devueltos: string; asignados: string; lineas: SagLine[]; fuente: SagSource | null;
  despacho: SagDispatch; instructivo: string; documento: { id: string; version: number } | null;
};
export type SagPayload = {
  operacion_id: string; version: number; tipo: SagKind; responsable_id: string; fecha_actividad: string;
  encabezado: { observaciones: string; programa: string; motivo: string; certificados: number; certificados_devueltos: number; certificados_asignados: number; despacho?: SagDispatch };
  repaletizaje_id?: string; lote_inspeccion_sag_id?: string; embarque_id?: string; instructivo_id?: string | null;
  folios?: { folio_id: string; cantidad: number; causal: string; tipo_actividad?: string; numero_actividad?: string }[];
  actividades?: Record<string, { tipo?: string; numero?: string }>;
  kilos?: Record<string, number>; mercados_anteriores?: Record<string, string>;
  seleccion_folios?: string[]; origenes?: Record<string, Record<string, string>>;
};
export const sagNames: Record<SagKind, string> = { repaletizaje: 'Repaletizaje SAG', anulacion: 'Anulación / devolución', cambio_mercado: 'Cambio de mercado', despacho: 'Despacho / Multipuerto' };
export function blankSagForm(): SagForm {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map((p) => [p.type, p.value]));
  return { tipo: 'repaletizaje', fecha: `${parts.year}-${parts.month}-${parts.day}`, responsable: '', referencia: '', observaciones: '', programa: '', motivo: '', certificados: '0', devueltos: '0', asignados: '0', lineas: [], fuente: null, despacho: {}, instructivo: '', documento: null };
}
export function sagFormFromDocument(d: SagDocument): SagForm {
  return { ...blankSagForm(), tipo: d.tipo, fecha: d.fecha_actividad.slice(0, 10), responsable: d.responsable_id,
    referencia: d.detalle.solicitud || '', observaciones: d.encabezado.observaciones || '', programa: d.encabezado.programa || '', motivo: d.encabezado.motivo || '',
    certificados: String(d.encabezado.certificados ?? 0), devueltos: String(d.encabezado.certificados_devueltos ?? 0), asignados: String(d.encabezado.certificados_asignados ?? 0),
    fuente: d.detalle, lineas: (d.detalle.originales || d.detalle.lineas || []).map((l) => ({ ...l, incluido: true })),
    despacho: { ...d.detalle.transporte }, instructivo: d.detalle.instructivo_id || '', documento: { id: d.id, version: d.version } };
}
export function sagPayload(form: SagForm, id: string): SagPayload {
  if (!form.responsable || !/^\d{4}-\d{2}-\d{2}$/.test(form.fecha)) throw new Error('Selecciona responsable y fecha de actividad.');
  const count = (value: string) => { const n = Number(value); if (!Number.isInteger(n) || n < 0 || n > 10000) throw new Error('Revisa la cantidad de certificados.'); return n; };
  const data: SagPayload = { operacion_id: id, version: form.documento?.version || 0, tipo: form.tipo, responsable_id: form.responsable, fecha_actividad: form.fecha,
    encabezado: { observaciones: form.observaciones, programa: form.programa, motivo: form.motivo, certificados: count(form.certificados), certificados_devueltos: count(form.devueltos), certificados_asignados: count(form.asignados) } };
  const activities = () => Object.fromEntries(form.lineas.map((l) => [l.folio_id, { tipo: l.tipo_actividad, numero: l.numero_actividad }]));
  if (form.tipo === 'anulacion') {
    data.folios = form.lineas.map((l) => {
      if (!Number.isInteger(l.cantidad) || l.cantidad < 1 || !l.causal?.trim()) throw new Error('Completa cajas y causal por folio.');
      return { folio_id: l.folio_id, cantidad: l.cantidad, causal: l.causal, tipo_actividad: l.tipo_actividad, numero_actividad: l.numero_actividad };
    });
  } else {
    if (!form.fuente) throw new Error('Carga primero el registro de origen.');
    if (form.tipo === 'repaletizaje') { data.repaletizaje_id = form.fuente.repaletizaje_id; data.actividades = activities(); }
    if (form.tipo === 'cambio_mercado') {
      data.lote_inspeccion_sag_id = form.fuente.lote_inspeccion_sag_id; data.actividades = activities();
      data.kilos = Object.fromEntries(form.lineas.filter((l) => l.kilos != null).map((l) => [l.folio_id, l.kilos!]));
      data.mercados_anteriores = Object.fromEntries(form.lineas.map((l) => [l.folio_id, l.mercados_anteriores || '']));
    }
    if (form.tipo === 'despacho') {
      const included = form.lineas.filter((l) => l.incluido);
      if (!included.length || !form.instructivo) throw new Error('Selecciona instructivo y pallets incluidos.');
      data.embarque_id = form.fuente.embarque_id; data.instructivo_id = form.instructivo; data.encabezado.despacho = form.despacho;
      data.seleccion_folios = [...new Set(included.map((l) => l.folio_id))];
      data.kilos = Object.fromEntries(included.filter((l) => l.kilos != null).map((l) => [l.linea_id!, l.kilos!]));
      data.origenes = Object.fromEntries(included.map((l) => [l.linea_id!, { provincia: l.provincia || '', comuna: l.comuna || '', csg: l.csg || '', csp: l.csp || '' }]));
    }
  }
  return data;
}
export function sagStorageKey(url: string, user: string, device: string) { return `folios:sag:${encodeURIComponent(url.replace(/\/+$/, ''))}:${encodeURIComponent(user)}:${encodeURIComponent(device)}`; }
