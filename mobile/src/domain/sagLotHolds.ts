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
  destino?: string | null;
  destino_sin_definir?: boolean;
  instructivo_numero?: string | null;
  folio?: string;
};

function restrictionText(alert: SagLotAlert): string {
  const destinations = alert.todos_destinos ? 'todos los destinos' : alert.destinos.map((d) => d.nombre).join(', ');
  if (alert.bloquea_salida === true) {
    if (alert.destino_sin_definir) {
      return 'Define el destino del instructivo antes de salir · Restricción vigente para ' + destinations + '.';
    }
    const destination = alert.destino || 'todos los destinos';
    return 'Bloquea la salida a ' + destination
      + (alert.todos_destinos && alert.destino ? ' · Retención para todos los destinos' : '') + '.';
  }
  if (alert.bloquea_salida === false && destinations) {
    return (alert.destino ? 'Destino ' + alert.destino + ' · ' : '') + 'Restricción en otros destinos (' + destinations + ').';
  }
  return destinations ? 'Despacho restringido: ' + destinations + '.' : 'Seguimiento SAG sin restricción de destino.';
}

export function sagLotAlertText(alert: SagLotAlert): string {
  return [
    (alert.folio ? 'Folio ' + alert.folio + ' · ' : '') + 'Lote ' + alert.lote + ' · ' + alert.cajas_lote + ' cajas · ' + (alert.estado === 'retenido' ? 'Retenido' : 'Con observaciones'),
    (alert.instructivo_numero ? 'Instructivo ' + alert.instructivo_numero + ' · ' : '') + restrictionText(alert),
    alert.observacion,
    alert.instruccion_segregacion ? 'Segregación indicada: ' + alert.instruccion_segregacion : '',
  ].filter(Boolean).join('\n');
}
