export type SagLotAlert = {
  id: string;
  lote: string;
  estado: 'con_observaciones' | 'retenido';
  cajas_lote: number;
  observacion: string;
  todos_destinos: boolean;
  destinos: Array<{ tipo: 'pais' | 'bloque'; id: string; nombre: string }>;
  instruccion_segregacion?: string | null;
  bloquea_salida?: boolean;
  folio?: string;
};

export function sagLotAlertText(alert: SagLotAlert): string {
  const destinations = alert.todos_destinos ? 'todos los destinos' : alert.destinos.map((d) => d.nombre).join(', ');
  return [
    (alert.folio ? 'Folio ' + alert.folio + ' · ' : '') + 'Lote ' + alert.lote + ' · ' + alert.cajas_lote + ' cajas · ' + (alert.estado === 'retenido' ? 'Retenido' : 'Con observaciones'),
    destinations ? 'Despacho restringido: ' + destinations + '.' : 'Seguimiento SAG sin restricción de destino.',
    alert.observacion,
    alert.instruccion_segregacion ? 'Segregación indicada: ' + alert.instruccion_segregacion : '',
  ].filter(Boolean).join('\n');
}
