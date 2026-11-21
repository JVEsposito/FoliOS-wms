import type { LoadFolio, RefrigeratedLoad } from './estiba';

export type OperatorLoad = Omit<RefrigeratedLoad, 'estado' | 'folios'> & {
  estado: RefrigeratedLoad['estado'] | 'cerrada' | 'cancelada' | 'retornada' | 'retorno_parcial';
  folios: Array<Omit<LoadFolio, 'estado_carga'> & { estado_carga: string }>;
  progreso: RefrigeratedLoad['progreso'] & { folios_concentrados?: string[] };
  camion_en_anden: { patente: string; anden: { nombre: string } | null } | null;
  embarque: { codigo: string; numeros_externos: string[]; fecha_programada: string | null; hora_programada: string | null } | null;
  cierre: { patente: string | null; cerrada_at: string } | null;
  cancelada_at: string | null;
};

export type OperatorLoadFeed = {
  data: OperatorLoad[];
  meta: { turno: { nombre: string; inicio_at: string; fin_at: string; zona_horaria: string } };
};

export function isFinishedLoad(load: Pick<OperatorLoad, 'estado' | 'folios'>) {
  return ['cerrada', 'cancelada', 'retornada', 'retorno_parcial'].includes(load.estado)
    || (load.estado === 'despachada' && load.folios.length > 0
      && load.folios.every((folio) => folio.estado_carga === 'despachado'));
}

export function operatorLoadState(state: string) {
  const labels: Record<string, string> = {
    pendiente: 'Pendiente', en_preparacion: 'En preparación', despacho_parcial: 'Despacho parcial',
    en_separacion: 'En separación', separada: 'Separada', separacion_completa: 'Separación completa',
    despachada: 'Despachada', cerrada: 'Cerrada', cancelada: 'Cancelada', retornada: 'Retornada a planta', retorno_parcial: 'Retorno parcial',
    en_anden: 'En andén', cargado: 'Cargado', despachado: 'Despachado', con_incidencia: 'Con incidencia', cancelado: 'Cancelado',
  };
  return labels[state] ?? state.replaceAll('_', ' ');
}

export function operatorLoadCounts(load: OperatorLoad) {
  return {
    total: load.total_folios,
    dock: load.folios.filter((folio) => folio.estado_carga === 'en_anden' || folio.estado_carga === 'cargado').length,
    shipped: load.folios.filter((folio) => folio.estado_carga === 'despachado').length,
    pending: load.folios.filter((folio) => !['en_anden', 'cargado', 'despachado', 'cancelado'].includes(folio.estado_carga)).length,
  };
}

export type OperatorLoadPhase = 'separacion' | 'carguio';

export function operatorLoadPalletProgress(load: OperatorLoad, phase: OperatorLoadPhase, folioId?: string) {
  const total = load.progreso.total ?? load.total_folios;
  if (phase === 'separacion') {
    const done = load.progreso.concentrados;
    const concentratedIds = load.progreso.folios_concentrados;
    let selected = -1;
    if (folioId && concentratedIds?.length === done) {
      const ready = new Set(concentratedIds);
      const concentrated = load.folios.filter((folio) => ready.has(folio.id));
      const remaining = load.folios.filter((folio) => !ready.has(folio.id));
      if (concentrated.length === done) selected = [...concentrated, ...remaining].findIndex((folio) => folio.id === folioId);
    }
    return { total, done, dock: 0, selected };
  }

  const loaded = load.folios.filter((folio) => ['cargado', 'despachado'].includes(folio.estado_carga));
  const atDock = load.folios.filter((folio) => folio.estado_carga === 'en_anden');
  const pending = load.folios.filter((folio) => ['pendiente', 'con_incidencia'].includes(folio.estado_carga));
  // Las celdas se ordenan por estado. Dentro de cada estado siguen el orden del servidor.
  const selected = folioId ? [...loaded, ...atDock, ...pending].findIndex((folio) => folio.id === folioId) : -1;
  return { total, done: loaded.length, dock: atDock.length, selected };
}

export function operatorLoadDate(value: string, timeZone: string) {
  return new Intl.DateTimeFormat('es-CL', { timeZone, dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}
