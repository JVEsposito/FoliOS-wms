export type SagLotAlert = {
  id: string;
  lote: string;
  estado: 'con_observaciones' | 'retenido' | 'liberado_con_restricciones' | 'liberado';
  cajas_lote: number;
  observacion: string;
  todos_destinos: boolean;
  destinos: Array<{ tipo: 'pais' | 'bloque'; id: string; nombre: string }>;
  instruccion_segregacion?: string | null;
  solo_segregacion?: boolean;
  bloquea_salida?: boolean;
  destino?: string | null;
  destino_sin_definir?: boolean;
  instructivo_numero?: string | null;
  folio?: string;
};

function hasDestinationRestrictions(alert: SagLotAlert): boolean {
  return !alert.todos_destinos && alert.destinos.length > 0;
}

export function sagLotOperationalAlerts(alerts: SagLotAlert[] = []): SagLotAlert[] {
  return alerts.flatMap((alert) => {
    if (alert.estado === 'liberado' || alert.cajas_lote <= 0) return [];
    if (!hasDestinationRestrictions(alert) || alert.bloquea_salida === true) return [alert];
    return alert.instruccion_segregacion
      ? [{ ...alert, solo_segregacion: true, destinos: [] }]
      : [];
  });
}

const labels = {
  con_observaciones: 'Con observaciones',
  retenido: 'Retenido',
  liberado_con_restricciones: 'Liberado con restricciones',
  liberado: 'Liberado',
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
  return destinations ? 'Despacho restringido a ' + destinations + '.' : 'Seguimiento SAG sin restricción de destino.';
}

export function sagLotAlertText(alert: SagLotAlert): string {
  const estado = alert.estado === 'retenido' && hasDestinationRestrictions(alert)
    ? 'liberado_con_restricciones' : alert.estado;
  const cajas = alert.cajas_lote === 1 ? '1 caja' : alert.cajas_lote + ' cajas';
  return [
    [alert.folio && 'Folio ' + alert.folio, 'Lote ' + alert.lote, cajas, !alert.solo_segregacion && labels[estado]].filter(Boolean).join(' · '),
    !alert.solo_segregacion && ((alert.instructivo_numero ? 'Instructivo ' + alert.instructivo_numero + ' · ' : '') + restrictionText(alert)),
    !alert.solo_segregacion && alert.observacion,
    alert.instruccion_segregacion && 'Segregación indicada · ' + alert.instruccion_segregacion,
  ].filter(Boolean).join('\n');
}
