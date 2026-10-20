import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { OPERATIONAL_POLL_INTERVAL_MS } from '../../config/polling';
import { isFinishedLoad, operatorLoadCounts, operatorLoadDate, operatorLoadState, type OperatorLoad, type OperatorLoadFeed } from '../../domain/operatorLoads';
import { useOperationalPolling } from '../../hooks/useOperationalPolling';
import type { OperationalTasksApi } from '../../services/operationalTasksApi';
import { operatorTheme as o } from '../../theme/operatorTheme';

type Filter = 'todas' | 'activas' | 'terminadas';

export function OperatorLoadStatus({ api, token, onBack }: { api: OperationalTasksApi; token: string; onBack: () => void }) {
  const [feed, setFeed] = useState<OperatorLoadFeed | null>(null);
  const [filter, setFilter] = useState<Filter>('todas');
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [checkedAt, setCheckedAt] = useState<string | null>(null);
  const inFlight = useRef(false);
  const received = useRef(false);

  async function refresh() {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    try {
      const response = await api.loadStatus(token, received.current);
      if (response) { setFeed(response); received.current = true; }
      setCheckedAt(new Date().toISOString());
      setError('');
    } catch {
      setError('No se pudo actualizar el estado de las cargas. Revisa la conexión y vuelve a intentar.');
    } finally {
      setBusy(false);
      inFlight.current = false;
    }
  }

  useEffect(() => { void refresh(); }, [api, token]);
  useOperationalPolling(refresh, { intervalMs: OPERATIONAL_POLL_INTERVAL_MS, enabled: !busy });

  const all = feed?.data ?? [];
  const active = all.filter((load) => !isFinishedLoad(load));
  const finished = all.filter(isFinishedLoad);
  const loads = filter === 'activas' ? active : filter === 'terminadas' ? finished : [...active, ...finished];
  const zone = feed?.meta.turno.zona_horaria ?? 'America/Santiago';

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <View style={styles.header}>
        <Pressable accessibilityRole="button" onPress={onBack} style={styles.button}><Text style={styles.buttonText}>‹ Volver a labores</Text></Pressable>
        <Pressable accessibilityRole="button" disabled={busy} onPress={() => void refresh()} style={styles.button}><Text style={styles.buttonText}>{busy ? 'Actualizando…' : '↻ Actualizar'}</Text></Pressable>
      </View>
      <Text accessibilityRole="header" style={styles.title}>Cargas de la operación</Text>
      <Text style={styles.copy}>Cargas activas y terminadas durante el turno. Toca una carga para ver sus pallets y ubicación.</Text>
      {feed ? <Text style={styles.turn}>Turno {feed.meta.turno.nombre} · {operatorLoadDate(feed.meta.turno.inicio_at, zone)} a {operatorLoadDate(feed.meta.turno.fin_at, zone)}</Text> : null}
      {checkedAt ? <Text style={styles.copy}>Última actualización: {operatorLoadDate(checkedAt, zone)}{error ? ' · información sin actualizar' : ' · actualización automática'}</Text> : null}
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {busy && !feed ? <ActivityIndicator color={o.color.primary} /> : null}
      <View style={styles.filters}>
        {([['todas', `Todas · ${all.length}`], ['activas', `Activas · ${active.length}`], ['terminadas', `Terminadas del turno · ${finished.length}`]] as const).map(([value, label]) => (
          <Pressable key={value} accessibilityRole="button" accessibilityState={{ selected: filter === value }} onPress={() => setFilter(value)} style={[styles.button, filter === value && styles.selected]}><Text style={styles.buttonText}>{label}</Text></Pressable>
        ))}
      </View>
      {feed && !loads.length ? <Text style={styles.copy}>No hay cargas en esta selección.</Text> : null}
      {loads.map((load) => <LoadStatusCard key={load.id} load={load} zone={zone} expanded={expandedId === load.id} onToggle={() => setExpandedId(expandedId === load.id ? null : load.id)} />)}
    </ScrollView>
  );
}

function LoadStatusCard({ load, zone, expanded, onToggle }: { load: OperatorLoad; zone: string; expanded: boolean; onToggle: () => void }) {
  const counts = operatorLoadCounts(load);
  const finished = isFinishedLoad(load);
  const dock = load.camion_en_anden?.anden?.nombre ?? load.anden_previsto?.nombre;
  const plate = load.camion_en_anden?.patente ?? load.cierre?.patente;
  return (
    <View style={styles.card}>
      <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={onToggle} style={styles.cardHeading}>
        <Text style={styles.code}>{load.codigo} · {operatorLoadState(load.estado)}</Text>
        <Text style={styles.priority}>Prioridad {load.prioridad} · {expanded ? 'Ocultar detalle −' : 'Ver detalle +'}</Text>
      </Pressable>
      {load.numero_orden_externa ? <Text style={styles.copy}>Orden: {load.numero_orden_externa}</Text> : null}
      {load.estado === 'cancelada'
        ? <Text style={styles.summary}>Carga cancelada · sin asignaciones vigentes</Text>
        : <Text style={styles.summary}>{counts.total} pallets · {counts.dock} en andén · {counts.shipped} despachados · {counts.pending} pendientes</Text>}
      {!finished ? <Text style={styles.copy}>Separación: {load.progreso.porcentaje}% de concentración · {load.progreso.concentrados} concentrados · {load.progreso.faltantes} faltantes</Text> : null}
      <Text style={styles.copy}>{dock ? `Andén: ${dock}` : 'Andén sin asignar'}{plate ? ` · Camión ${plate}` : ''}</Text>
      {load.camara_objetivo ? <Text style={styles.copy}>Cámara objetivo: {load.camara_objetivo.nombre}</Text> : null}
      {load.incidencias_abiertas ? <Text style={styles.warning}>{load.incidencias_abiertas} incidencias abiertas</Text> : null}
      {load.cierre ? <Text style={styles.copy}>Cerrada: {operatorLoadDate(load.cierre.cerrada_at, zone)}</Text> : null}
      {load.cancelada_at ? <Text style={styles.copy}>Cancelada: {operatorLoadDate(load.cancelada_at, zone)}</Text> : null}
      {expanded ? <View style={styles.detail}>
        {load.embarque ? <Text style={styles.copy}>Embarque {load.embarque.codigo}{load.embarque.fecha_programada ? ` · ${load.embarque.fecha_programada} ${load.embarque.hora_programada ?? ''}` : ''}{load.embarque.numeros_externos.length ? ` · Instructivos ${load.embarque.numeros_externos.join(', ')}` : ''}</Text> : null}
        {load.observacion ? <Text style={styles.copy}>Observación: {load.observacion}</Text> : null}
        {load.folios.map((folio) => <View key={folio.asignacion_id} style={styles.folio}>
          <Text style={styles.folioCode}>{folio.numero_folio} · {operatorLoadState(folio.estado_carga)}</Text>
          <Text style={styles.copy}>{folio.estado_carga === 'despachado' ? 'Despachado de la planta' : folio.ubicacion ? `${folio.ubicacion.camara.nombre} · ${folio.ubicacion.posicion.etiqueta ?? `B${folio.ubicacion.posicion.banda}-P${folio.ubicacion.posicion.posicion}-N${folio.ubicacion.posicion.nivel}`}` : folio.anden ? `En ${folio.anden.nombre}` : 'Sin ubicación de cámara'}</Text>
        </View>)}
      </View> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { padding: 20, gap: 14 },
  header: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 10 },
  title: { color: o.color.text, fontSize: o.type.title, fontWeight: '800' },
  copy: { color: o.color.textSecondary, fontSize: o.type.body },
  turn: { color: o.color.primaryPressed, fontSize: o.type.body, fontWeight: '700' },
  filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  button: { minHeight: o.touch.minimum, justifyContent: 'center', padding: 14, borderRadius: o.radius.control, borderWidth: 1, borderColor: o.color.borderStrong, backgroundColor: o.color.surface },
  selected: { borderColor: o.color.primary, backgroundColor: o.color.selected },
  buttonText: { color: o.color.primaryPressed, fontSize: o.type.body, fontWeight: '700' },
  card: { padding: 18, gap: 8, backgroundColor: o.color.surface, borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel },
  cardHeading: { minHeight: o.touch.minimum, gap: 8, justifyContent: 'center' },
  code: { color: o.color.text, fontSize: o.type.heading, fontWeight: '800' },
  priority: { color: o.color.primary, fontSize: o.type.small, fontWeight: '700' },
  summary: { color: o.color.text, fontSize: o.type.body, fontWeight: '700' },
  detail: { borderTopWidth: 1, borderTopColor: o.color.border, paddingTop: 14, gap: 12 },
  folio: { gap: 4, paddingVertical: 8 },
  folioCode: { color: o.color.text, fontSize: o.type.body, fontWeight: '700' },
  error: { color: o.color.critical, fontSize: o.type.body },
  warning: { color: o.color.warning, fontSize: o.type.body, fontWeight: '700' },
});
