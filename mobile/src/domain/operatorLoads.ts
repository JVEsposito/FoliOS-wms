import type { LoadFolio, RefrigeratedLoad } from './estiba';

export type OperatorLoad = Omit<RefrigeratedLoad, 'estado' | 'folios'> & {
  estado: RefrigeratedLoad['estado'] | 'despachada' | 'cerrada' | 'cancelada';
  folios: Array<Omit<LoadFolio, 'estado_carga'> & { estado_carga: string }>;
  camion_en_anden: { patente: string; anden: { nombre: string } | null } | null;
  embarque: { codigo: string; numeros_externos: string[]; fecha_programada: string | null; hora_programada: string | null } | null;
  cierre: { patente: string | null; cerrada_at: string } | null;
  cancelada_at: string | null;
};

export type OperatorLoadFeed = {
  data: OperatorLoad[];
  meta: { turno: { nombre: string; inicio_at: string; fin_at: string; zona_horaria: string } };
};

export function isFinishedLoad(load: Pick<OperatorLoad, 'estado'>) {
  return ['cerrada', 'despachada', 'cancelada'].includes(load.estado);
}

export function operatorLoadState(state: string) {
  const labels: Record<string, string> = {
    pendiente: 'Pendiente', en_preparacion: 'En preparación', despacho_parcial: 'Despacho parcial',
    en_separacion: 'En separación', separada: 'Separada', separacion_completa: 'Separación completa',
    despachada: 'Despachada', cerrada: 'Cerrada', cancelada: 'Cancelada',
    en_anden: 'En andén', despachado: 'Despachado', con_incidencia: 'Con incidencia', cancelado: 'Cancelado',
  };
  return labels[state] ?? state.replaceAll('_', ' ');
}

export function operatorLoadCounts(load: OperatorLoad) {
  return {
    total: load.total_folios,
    dock: load.folios.filter((folio) => folio.estado_carga === 'en_anden').length,
    shipped: load.folios.filter((folio) => folio.estado_carga === 'despachado').length,
    pending: load.folios.filter((folio) => !['en_anden', 'despachado', 'cancelado'].includes(folio.estado_carga)).length,
  };
}

export function operatorLoadDate(value: string, timeZone: string) {
  return new Intl.DateTimeFormat('es-CL', { timeZone, dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}
