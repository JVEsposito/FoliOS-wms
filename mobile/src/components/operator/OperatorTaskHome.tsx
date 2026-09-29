import { Pressable, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import type { ReactNode } from 'react';

import {
  OperationalTask,
  operationalTaskDestinationLabel,
  operationalTaskLabel,
  operationalTaskPositionLabel,
  operationalTaskReason,
} from '../../domain/operationalTasks';
import type { OperatorQueueItem, OperatorQueueSource } from '../../domain/operatorTaskQueue';
import { operatorTheme as o } from '../../theme/operatorTheme';
import {
  OperatorEntityCode,
  OperatorPriorityBadge,
  OperatorRouteLine,
  OperatorStatusBadge,
} from './OperatorPrimitives';

type Props = {
  availableCount: number;
  busy: boolean;
  mineCount: number;
  next: OperatorQueueItem | null;
  plannerFresh: boolean | null;
  onOpen: (item: OperatorQueueItem) => void;
  onRefresh: () => void;
  onViewChange: (source: OperatorQueueSource) => void;
  queue: OperatorQueueItem[];
  suggestedDestinations: Record<string, string>;
  view: OperatorQueueSource;
  verification?: ReactNode;
};

export function OperatorTaskHome({
  availableCount,
  busy,
  mineCount,
  next,
  plannerFresh,
  onOpen,
  onRefresh,
  onViewChange,
  queue,
  suggestedDestinations,
  view,
  verification,
}: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;

  return (
    <ScrollView contentContainerStyle={styles.content} style={styles.screen}>
      <View style={styles.sectionHeader}>
        <View>
          <Text style={styles.eyebrow}>TRABAJO GUIADO</Text>
          <Text accessibilityRole="header" style={styles.title}>Mi jornada operacional</Text>
        </View>
        <Pressable disabled={busy} onPress={onRefresh} style={styles.refreshButton}>
          <Text style={styles.refreshText}>↻ Actualizar</Text>
        </Pressable>
      </View>

      {next ? (
        <NextTaskCard busy={busy} compact={compact} item={next} onOpen={() => onOpen(next)} suggestion={suggestedDestinations[next.task.id]} />
      ) : (
        <View style={styles.emptyHero}>
          <OperatorStatusBadge label={plannerFresh === false ? 'PLANIFICADOR SIN ACTUALIZAR' : 'SIN LABORES PENDIENTES'} tone={plannerFresh === false ? 'warning' : 'success'} />
          <Text style={styles.emptyHeroTitle}>{plannerFresh === false ? 'Planificador sin actualizar: las tareas volverán cuando se recalcule' : 'No hay una maniobra para iniciar'}</Text>
          <Text style={styles.emptyHeroCopy}>{plannerFresh === false ? 'Espera la próxima evaluación del servidor y actualiza la bandeja.' : 'La bandeja se actualizará automáticamente cuando el servidor publique trabajo.'}</Text>
        </View>
      )}

      <View style={styles.queuePanel}>
        <View style={[styles.queueHeader, compact && styles.queueHeaderCompact]}>
          <View>
            <Text style={styles.eyebrow}>SIGUIENTES</Text>
            <Text accessibilityRole="header" style={styles.queueTitle}>Próximas maniobras en cola</Text>
          </View>
          <View style={styles.queueTabs}>
            <QueueTab active={view === 'mine'} count={mineCount} label="Mis tareas" onPress={() => onViewChange('mine')} />
            <QueueTab active={view === 'available'} count={availableCount} label="Disponibles" onPress={() => onViewChange('available')} />
          </View>
        </View>

        {queue.length ? (
          <View style={styles.queueList}>
            {queue.map((item, index) => (
              <QueueRow
                busy={busy}
                compact={compact}
                index={index + 1}
                item={item}
                key={`${item.source}-${item.task.id}`}
                onOpen={() => onOpen(item)}
                suggestion={suggestedDestinations[item.task.id]}
              />
            ))}
          </View>
        ) : (
          <View style={styles.emptyQueue}>
            <Text style={styles.emptyQueueTitle}>{view === 'mine' ? 'No tienes más tareas tomadas' : 'No hay tareas disponibles'}</Text>
            <Text style={styles.emptyQueueCopy}>
              {view === 'mine'
                ? 'La maniobra principal se muestra arriba. Puedes revisar las tareas ofrecidas en Disponibles.'
                : plannerFresh === false
                  ? 'Planificador sin actualizar: las tareas volverán cuando se recalcule.'
                  : 'No se recibieron nuevas labores para esta selección.'}
            </Text>
          </View>
        )}
      </View>
      {verification}
    </ScrollView>
  );
}

function NextTaskCard({ busy, compact, item, onOpen, suggestion }: {
  busy: boolean;
  compact: boolean;
  item: OperatorQueueItem;
  onOpen: () => void;
  suggestion?: string;
}) {
  const { task } = item;
  const paused = task.maniobra?.estado === 'pausada_discrepancia';
  const alternative = isArbitrationAlternative(task);
  const state = paused
    ? { label: 'EN ESPERA DE SUPERVISIÓN', tone: 'warning' as const }
    : alternative
      ? { label: 'ALTERNATIVA', tone: 'warning' as const }
      : task.estado === 'en_proceso'
        ? { label: 'EN EJECUCIÓN', tone: 'critical' as const }
        : item.source === 'mine'
          ? { label: 'ASIGNADA', tone: 'success' as const }
          : { label: 'DISPONIBLE', tone: 'info' as const };

  return (
    <View style={styles.hero}>
      <View style={[styles.heroHeader, compact && styles.heroHeaderCompact]}>
        <View style={styles.heroHeading}>
          <Text style={styles.heroEyebrow}>{paused ? 'MANIOBRA BAJO CONTROL' : 'SIGUIENTE MANIOBRA'}</Text>
          <Text style={styles.heroHint}>
            {paused
              ? 'No continúes movimientos hasta recibir la decisión supervisada'
              : alternative
                ? 'Visible sin reservas; se habilitará cuando el servidor libere capacidad'
                : item.source === 'mine' ? 'Tu siguiente tarea en la cola' : 'Disponible para tomar'}
          </Text>
        </View>
        <Pressable disabled={busy || alternative} onPress={onOpen} style={[styles.heroAction, compact && styles.heroActionCompact, (busy || alternative) && styles.disabled]}>
          <Text style={styles.heroActionText}>{primaryActionLabel(item)} →</Text>
        </Pressable>
      </View>

      <View style={[styles.heroBody, compact && styles.heroBodyCompact]}>
        <View style={[styles.heroIdentity, compact && styles.heroIdentityCompact]}>
          <View style={styles.heroBadges}>
            <OperatorStatusBadge label={state.label} tone={state.tone} />
            <OperatorPriorityBadge priority={task.prioridad} />
            {maneuverObjectiveCount(task) > 1 ? (
              <OperatorStatusBadge label={`${maneuverObjectiveCount(task)} OBJETIVOS`} tone="info" />
            ) : null}
          </View>
          <Text style={styles.heroTitle}>{operationalTaskLabel(task.plan.tipo)}</Text>
          <Text style={styles.folioLabel}>Folio / pallet</Text>
          <OperatorEntityCode prominent value={task.folio.numero_folio} />
        </View>

        <View style={[styles.heroRoute, compact && styles.heroRouteCompact]}>
          <OperatorRouteLine label="Origen" value={operationalTaskPositionLabel(task.origen)} />
          <OperatorRouteLine label="Destino" value={suggestion ? `${suggestion} · sugerido` : operationalTaskDestinationLabel(task)} strong />
          <OperatorRouteLine label="Motivo" value={operationalTaskReason(task)} />
        </View>
      </View>

      <View style={styles.heroFooter}>
        <Metric label="Movimientos físicos" value={String(task.maniobra?.pasos_totales ?? 1)} />
        <Metric label="Paso" value={maneuverStep(task)} />
        <Metric label="Compromiso" value={commitmentLabel(task)} />
      </View>
    </View>
  );
}

function QueueTab({ active, count, label, onPress }: { active: boolean; count: number; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.queueTab, active && styles.queueTabActive]}>
      <Text style={[styles.queueTabText, active && styles.queueTabTextActive]}>{label} · {count}</Text>
    </Pressable>
  );
}

function QueueRow({ busy, compact, index, item, onOpen, suggestion }: {
  busy: boolean;
  compact: boolean;
  index: number;
  item: OperatorQueueItem;
  onOpen: () => void;
  suggestion?: string;
}) {
  const task = item.task;
  const paused = task.maniobra?.estado === 'pausada_discrepancia';
  const alternative = isArbitrationAlternative(task);
  return (
    <View style={[styles.queueRow, compact && styles.queueRowCompact]}>
      <Text style={styles.queueIndex}>{index}</Text>
      <View style={[styles.queuePriority, compact && styles.queuePriorityCompact]}><OperatorPriorityBadge priority={task.prioridad} /></View>
      <View style={[styles.queueIdentity, compact && styles.queueIdentityCompact]}>
        <Text style={styles.queueMeta}>{paused ? 'EN ESPERA' : alternative ? 'ALTERNATIVA' : item.source === 'mine' ? 'ASIGNADA' : 'DISPONIBLE'}</Text>
        <OperatorEntityCode value={task.folio.numero_folio} />
      </View>
      <View style={[styles.queueRoute, compact && styles.queueRouteCompact]}>
        <Text numberOfLines={1} style={styles.queueRouteText}>{operationalTaskPositionLabel(task.origen)}</Text>
        <Text style={styles.queueArrow}>→</Text>
        <Text numberOfLines={1} style={styles.queueRouteDestination}>{suggestion ? `${suggestion} · sugerido` : operationalTaskDestinationLabel(task)}</Text>
      </View>
      <View style={styles.queueSteps}>
        <Text style={styles.queueStepsValue}>{task.maniobra?.pasos_totales ?? 1}</Text>
        <Text style={styles.queueStepsLabel}>mov.</Text>
      </View>
      <Pressable disabled={busy || alternative} onPress={onOpen} style={[styles.queueAction, (busy || alternative) && styles.disabled]}>
        <Text style={styles.queueActionText}>{paused ? 'Ver estado' : alternative ? 'En espera' : item.source === 'mine' ? 'Abrir' : 'Tomar'} →</Text>
      </Pressable>
    </View>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.metric}>
      <Text style={styles.metricLabel}>{label}</Text>
      <Text style={styles.metricValue}>{value}</Text>
    </View>
  );
}

function primaryActionLabel(item: OperatorQueueItem) {
  if (item.task.maniobra?.estado === 'pausada_discrepancia') return 'VER ESTADO DE ESPERA';
  if (isArbitrationAlternative(item.task)) return 'ALTERNATIVA EN ESPERA';
  if (item.task.estado === 'en_proceso') return 'CONTINUAR MANIOBRA';
  return item.source === 'mine' ? 'INICIAR MANIOBRA' : 'TOMAR MANIOBRA';
}

function maneuverStep(task: OperationalTask) {
  if (!task.maniobra) return '1 de 1';
  return `${task.secuencia_maniobra ?? task.maniobra.secuencia_actual} de ${task.maniobra.pasos_totales}`;
}

function maneuverObjectiveCount(task: OperationalTask) {
  return Math.max(1, task.maniobra?.objetivos?.length ?? 0);
}

function isArbitrationAlternative(task: OperationalTask) {
  return task.maniobra?.arbitraje?.decision === 'alternativa';
}

function commitmentLabel(task: OperationalTask) {
  if (task.maniobra?.estado === 'pausada_discrepancia') return 'Pausa supervisada';
  if (task.estado === 'en_proceso') return 'Destino fijo';
  if (task.reserva?.tipo_compromiso === 'fisica') return 'Posición reservada';
  if (task.reserva) return 'Tarea reclamada';
  return 'Sin reserva';
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { gap: o.space[4], paddingBottom: o.space[8] },
  sectionHeader: { minHeight: o.touch.minimum, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[4] },
  eyebrow: { color: o.color.primaryPressed, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 1.1 },
  title: { color: o.color.text, fontSize: o.type.title, fontWeight: '900', marginTop: o.space[1] },
  refreshButton: { minHeight: o.touch.minimum, justifyContent: 'center', paddingHorizontal: o.space[4], borderWidth: 1, borderColor: o.color.primary, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  refreshText: { color: o.color.primaryPressed, fontSize: o.type.small, fontWeight: '900' },
  hero: { overflow: 'hidden', borderWidth: 1, borderColor: o.color.borderStrong, borderRadius: o.radius.panel, backgroundColor: o.color.surface, shadowColor: o.color.shadow, shadowOpacity: 0.12, shadowRadius: 6, shadowOffset: { width: 0, height: 3 }, elevation: 3 },
  heroHeader: { minHeight: 72, padding: o.space[3], backgroundColor: o.color.navy, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[4] },
  heroHeaderCompact: { alignItems: 'stretch', flexDirection: 'column' },
  heroHeading: { flex: 1 },
  heroEyebrow: { color: o.color.onNavy, fontSize: o.type.body, fontWeight: '900' },
  heroHint: { color: '#C9D8DF', fontSize: o.type.small, marginTop: o.space[1] },
  heroAction: { minHeight: o.touch.prominent, minWidth: 230, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.primary },
  heroActionCompact: { minWidth: 0, alignSelf: 'stretch' },
  heroActionText: { color: o.color.onPrimary, fontSize: o.type.body, fontWeight: '900' },
  heroBody: { flexDirection: 'row', gap: o.space[6], padding: o.space[6] },
  heroBodyCompact: { flexDirection: 'column', gap: o.space[4], padding: o.space[4] },
  heroIdentity: { flex: 0.44, minWidth: 0, gap: o.space[2] },
  heroIdentityCompact: { flex: 0 },
  heroBadges: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[2] },
  heroTitle: { color: o.color.text, fontSize: 34, lineHeight: 40, fontWeight: '900', textTransform: 'uppercase' },
  folioLabel: { color: o.color.muted, fontSize: o.type.small, fontWeight: '700', marginTop: o.space[2] },
  heroRoute: { flex: 0.56, minWidth: 0, paddingLeft: o.space[6], borderLeftWidth: 1, borderLeftColor: o.color.border, gap: o.space[2] },
  heroRouteCompact: { flex: 0, paddingLeft: 0, paddingTop: o.space[4], borderLeftWidth: 0, borderTopWidth: 1, borderTopColor: o.color.border },
  heroFooter: { minHeight: 76, flexDirection: 'row', backgroundColor: o.color.surfaceMuted, borderTopWidth: 1, borderTopColor: o.color.border },
  metric: { flex: 1, paddingHorizontal: o.space[4], paddingVertical: o.space[3], justifyContent: 'center', borderRightWidth: 1, borderRightColor: o.color.border },
  metricLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '700' },
  metricValue: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', marginTop: o.space[1] },
  queuePanel: { overflow: 'hidden', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  queueHeader: { minHeight: 72, padding: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[4], backgroundColor: o.color.navy },
  queueHeaderCompact: { alignItems: 'stretch', flexDirection: 'column' },
  queueTitle: { color: o.color.onNavy, fontSize: o.type.heading, fontWeight: '900', marginTop: o.space[1] },
  queueTabs: { flexDirection: 'row', gap: o.space[2] },
  queueTab: { minHeight: o.touch.minimum, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: '#77909C', borderRadius: o.radius.control },
  queueTabActive: { backgroundColor: o.color.surface, borderColor: o.color.surface },
  queueTabText: { color: '#D9E5EA', fontSize: o.type.small, fontWeight: '800' },
  queueTabTextActive: { color: o.color.primaryPressed },
  queueList: { backgroundColor: o.color.surface },
  queueRow: { minHeight: 88, paddingHorizontal: o.space[3], paddingVertical: o.space[2], borderBottomWidth: 1, borderBottomColor: o.color.border, flexDirection: 'row', alignItems: 'center', gap: o.space[3] },
  queueRowCompact: { flexWrap: 'wrap' },
  queueIndex: { width: 28, color: o.color.muted, fontSize: o.type.body, fontWeight: '900', textAlign: 'center' },
  queuePriority: { width: 106 },
  queuePriorityCompact: { width: 'auto' },
  queueIdentity: { width: 150, gap: o.space[1] },
  queueIdentityCompact: { flex: 1, width: 'auto' },
  queueMeta: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800' },
  queueRoute: { flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center', gap: o.space[2] },
  queueRouteCompact: { flexBasis: '100%', width: '100%' },
  queueRouteText: { flex: 1, color: o.color.text, fontSize: o.type.small },
  queueArrow: { color: o.color.primary, fontSize: o.type.heading, fontWeight: '900' },
  queueRouteDestination: { flex: 1, color: o.color.primaryPressed, fontSize: o.type.small, fontWeight: '900' },
  queueSteps: { width: 64, alignItems: 'center' },
  queueStepsValue: { color: o.color.text, fontSize: o.type.heading, fontWeight: '900' },
  queueStepsLabel: { color: o.color.muted, fontSize: o.type.caption },
  queueAction: { minHeight: o.touch.minimum, minWidth: 96, paddingHorizontal: o.space[3], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.selected, borderWidth: 1, borderColor: o.color.primary },
  queueActionText: { color: o.color.primaryPressed, fontSize: o.type.small, fontWeight: '900' },
  emptyHero: { minHeight: 230, padding: o.space[6], alignItems: 'center', justifyContent: 'center', gap: o.space[3], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  emptyHeroTitle: { color: o.color.text, fontSize: o.type.heading, fontWeight: '900', textAlign: 'center' },
  emptyHeroCopy: { maxWidth: 560, color: o.color.muted, fontSize: o.type.small, lineHeight: 20, textAlign: 'center' },
  emptyQueue: { minHeight: 150, padding: o.space[6], alignItems: 'center', justifyContent: 'center' },
  emptyQueueTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', textAlign: 'center' },
  emptyQueueCopy: { maxWidth: 560, color: o.color.muted, fontSize: o.type.small, lineHeight: 20, textAlign: 'center', marginTop: o.space[2] },
  disabled: { opacity: 0.45 },
});
