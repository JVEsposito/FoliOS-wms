import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import type { ReactNode } from 'react';

import {
  OperationalTask,
  OperationalShiftContext,
  operationalTaskDestinationLabel,
  operationalTaskOriginLabel,
} from '../../domain/operationalTasks';
import type { OperatorQueueItem, OperatorQueueSource } from '../../domain/operatorTaskQueue';
import { operatorTaskPurpose } from '../../domain/operatorTaskPurpose';
import { OperatorShiftDuties } from './OperatorShiftDuties';
import { operatorTheme as o } from '../../theme/operatorTheme';
import {
  OperatorEntityCode,
  OperatorPriorityBadge,
  OperatorRouteLine,
  OperatorStatusBadge,
} from './OperatorPrimitives';
import { font } from '../../theme/typography';

export type OperatorLastMovement = { at: string; destination: string; folio: string };

type Props = {
  shiftContext?: OperationalShiftContext | null;
  availableCount: number;
  busy: boolean;
  lastMovement?: OperatorLastMovement | null;
  mineCount: number;
  next: OperatorQueueItem | null;
  plannerFresh: boolean | null;
  onOpen: (item: OperatorQueueItem) => void;
  onOpenLoads?: () => void;
  onRefresh: () => void;
  onViewChange: (source: OperatorQueueSource) => void;
  queue: OperatorQueueItem[];
  suggestedDestinations: Record<string, string>;
  syncLabel?: string;
  view: OperatorQueueSource;
  verification?: ReactNode;
};

const QUEUE_PREVIEW = 3;

/**
 * Portada «Mi jornada» del camarero (FoliOS v2): una maniobra a la vez, con el folio y el
 * recorrido Desde → Hacia en grande y una sola acción principal; la cola, el último
 * movimiento y las cargas quedan en una columna lateral. Ver
 * docs/diseno/folios-v2/pr-12-tablet-portada.md.
 */
export function OperatorTaskHome({
  shiftContext,
  availableCount,
  busy,
  lastMovement,
  mineCount,
  next,
  plannerFresh,
  onOpen,
  onOpenLoads,
  onRefresh,
  onViewChange,
  queue,
  suggestedDestinations,
  syncLabel,
  view,
  verification,
}: Props) {
  const { width } = useWindowDimensions();
  const compact = width < o.breakpoint.compact;

  return (
    <ScrollView
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl colors={[o.color.primary]} onRefresh={onRefresh} refreshing={false} tintColor={o.color.primary} />}
      style={styles.screen}
    >
      <View style={[styles.columns, compact && styles.columnsCompact]}>
        <View style={styles.main}>
          {next ? (
            <NextTaskCard busy={busy} compact={compact} item={next} onOpen={() => onOpen(next)} suggestion={suggestedDestinations[next.task.id]} />
          ) : (
            <View style={styles.emptyHero}>
              {plannerFresh === false ? <OperatorStatusBadge label="PLANIFICADOR SIN ACTUALIZAR" tone="warning" /> : null}
              <Text accessibilityRole="header" style={styles.emptyHeroTitle}>
                {plannerFresh === false ? 'Las maniobras volverán cuando se recalcule' : 'Sin maniobras por ahora'}
              </Text>
              <Text style={styles.emptyHeroCopy}>
                {plannerFresh === false
                  ? 'Espera la próxima evaluación del servidor; esta pantalla se actualiza sola.'
                  : 'El planificador te asignará la siguiente cuando esté lista. Esta pantalla se actualiza sola.'}
              </Text>
            </View>
          )}
          {verification}
        </View>

        <View style={[styles.aside, compact && styles.asideCompact]}>
          <View style={styles.syncRow}>
            <Text accessibilityLiveRegion="polite" numberOfLines={1} style={styles.syncText}>{syncLabel ?? ''}</Text>
            <Pressable accessibilityLabel="Actualizar mis labores" accessibilityRole="button" disabled={busy} onPress={onRefresh} style={[styles.refreshButton, busy && styles.disabled]}>
              <Text style={styles.refreshText}>↻</Text>
            </Pressable>
          </View>

          <OperatorShiftDuties context={shiftContext ?? null} />

          <QueuePanel
            availableCount={availableCount}
            busy={busy}
            mineCount={mineCount}
            onOpen={onOpen}
            onViewChange={onViewChange}
            plannerFresh={plannerFresh}
            queue={queue}
            suggestedDestinations={suggestedDestinations}
            view={view}
          />

          {lastMovement ? (
            <View accessible accessibilityLabel={`Último movimiento: ${lastMovement.folio} en ${lastMovement.destination}`} style={styles.panel}>
              <View style={styles.lastRow}>
                <View style={styles.lastMark}><Text style={styles.lastMarkText}>✓</Text></View>
                <View style={styles.lastText}>
                  <Text style={styles.panelMeta}>Último movimiento · {timeLabel(lastMovement.at)}</Text>
                  <OperatorEntityCode value={lastMovement.folio} />
                  <Text numberOfLines={2} style={styles.lastDestination}>{lastMovement.destination}</Text>
                </View>
              </View>
            </View>
          ) : null}

          {onOpenLoads ? (
            <Pressable accessibilityRole="button" disabled={busy} onPress={onOpenLoads} style={[styles.loadsLink, busy && styles.disabled]}>
              <Text style={styles.loadsLinkText}>Cargas del turno</Text>
              <Text style={styles.loadsLinkArrow}>→</Text>
            </Pressable>
          ) : null}
        </View>
      </View>
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
  const purpose = operatorTaskPurpose(task);
  const paused = task.maniobra?.estado === 'pausada_discrepancia';
  const alternative = isArbitrationAlternative(task);
  // Solo se marca el estado cuando cambia lo que la persona puede hacer; «disponible» y
  // «asignada» ya los dice el botón principal.
  const state = paused
    ? { label: 'EN ESPERA DE SUPERVISIÓN', tone: 'warning' as const }
    : alternative
      ? { label: 'ALTERNATIVA', tone: 'warning' as const }
      : task.estado === 'en_proceso'
        ? { label: 'EN EJECUCIÓN', tone: 'critical' as const }
        : null;
  const hint = paused
    ? 'No continúes movimientos hasta recibir la decisión supervisada'
    : alternative
      ? 'Visible sin reservas; se habilitará cuando el servidor libere capacidad'
      : null;
  const destinationPending = !suggestion && !task.destino
    && task.destino_logico?.tipo !== 'anden' && task.tipo_paso_maniobra !== 'extraccion_temporal';
  const detail = [maneuverStep(task), commitmentLabel(task)].filter(Boolean).join(' · ');

  return (
    <View style={[styles.hero, compact && styles.heroCompact]}>
      <View style={styles.heroTop}>
        <Text style={styles.eyebrow}>{paused ? 'MANIOBRA BAJO CONTROL' : 'SIGUIENTE MANIOBRA'}</Text>
        <View style={styles.heroBadges}>
          {state ? <OperatorStatusBadge label={state.label} tone={state.tone} /> : null}
          <OperatorPriorityBadge priority={task.prioridad} />
          {maneuverObjectiveCount(task) > 1 ? (
            <OperatorStatusBadge label={`${maneuverObjectiveCount(task)} OBJETIVOS`} tone="info" />
          ) : null}
        </View>
      </View>

      <View style={styles.identity}>
        <Text accessibilityRole="header" style={styles.heroTitle}>{purpose.label}</Text>
        {purpose.goal ? <Text numberOfLines={2} style={styles.heroGoal}>{purpose.goal}</Text> : null}
        <Text style={styles.folioLabel}>Folio</Text>
        <FolioCode compact={compact} value={task.folio.numero_folio} />
        <Text style={styles.heroMeta}>Envase: {task.folio.envase || 'sin informar'}</Text>
      </View>

      <View style={[styles.route, compact && styles.routeCompact]}>
        <RouteBlock label="DESDE" value={operationalTaskOriginLabel(task)} />
        <Text accessible={false} style={[styles.routeArrow, compact && styles.routeArrowCompact]}>→</Text>
        <RouteBlock
          label="HACIA"
          pending={destinationPending}
          proposed={Boolean(suggestion)}
          value={suggestion ?? operationalTaskDestinationLabel(task)}
        />
      </View>

      {hint ? <Text style={styles.heroHint}>{hint}</Text> : null}
      {detail ? <Text style={styles.heroDetail}>{detail}</Text> : null}

      <View style={styles.heroSpacer} />

      <Pressable
        accessibilityRole="button"
        disabled={busy || alternative}
        onPress={onOpen}
        style={[styles.heroAction, (busy || alternative) && styles.disabled]}
      >
        <Text style={styles.heroActionText}>{primaryActionLabel(item)} →</Text>
      </Pressable>
    </View>
  );
}

function FolioCode({ compact, value }: { compact: boolean; value: string }) {
  // Los últimos cuatro dígitos son los que el camarero coteja con la etiqueta.
  const split = value.length > 6 ? value.length - 4 : 0;
  return (
    <Text accessibilityLabel={`Folio ${value}`} selectable style={[styles.folio, compact && styles.folioCompact]}>
      {split ? <Text style={styles.folioHead}>{value.slice(0, split)}</Text> : null}
      {value.slice(split)}
    </Text>
  );
}

function RouteBlock({ label, pending = false, proposed = false, value }: {
  label: string;
  pending?: boolean;
  proposed?: boolean;
  value: string;
}) {
  const [place, ...rest] = value.split(' · ');
  const position = rest.join(' · ');
  const code = /^[A-Z0-9][A-Z0-9-]*$/.test(position);
  return (
    <View
      accessible
      accessibilityLabel={`${label === 'DESDE' ? 'Origen' : 'Destino'}: ${value}${proposed ? ', propuesto' : ''}`}
      style={[styles.routeBlock, pending && styles.routeBlockPending]}
    >
      <View style={styles.routeLabelRow}>
        <Text style={styles.routeLabel}>{label}</Text>
        {proposed ? <Text style={styles.routeProposed}>PROPUESTO</Text> : null}
      </View>
      <Text numberOfLines={2} style={[styles.routePlace, pending && styles.routePlacePending]}>{place}</Text>
      {position ? <Text numberOfLines={2} style={code ? styles.routeCode : styles.routeNote}>{position}</Text> : null}
    </View>
  );
}

function QueuePanel({ availableCount, busy, mineCount, onOpen, onViewChange, plannerFresh, queue, suggestedDestinations, view }: {
  availableCount: number;
  busy: boolean;
  mineCount: number;
  onOpen: (item: OperatorQueueItem) => void;
  onViewChange: (source: OperatorQueueSource) => void;
  plannerFresh: boolean | null;
  queue: OperatorQueueItem[];
  suggestedDestinations: Record<string, string>;
  view: OperatorQueueSource;
}) {
  const [expanded, setExpanded] = useState(false);
  const visible = expanded ? queue : queue.slice(0, QUEUE_PREVIEW);
  const hidden = queue.length - visible.length;

  return (
    <View accessibilityLabel="Próximas maniobras en cola" style={styles.panel}>
      <Text accessibilityRole="header" style={styles.eyebrow}>LUEGO</Text>
      <View accessibilityRole="tablist" style={styles.queueTabs}>
        <QueueTab active={view === 'mine'} count={mineCount} label="Mis tareas" onPress={() => onViewChange('mine')} />
        <QueueTab active={view === 'available'} count={availableCount} label="Disponibles" onPress={() => onViewChange('available')} />
      </View>

      {visible.length ? (
        <View>
          {visible.map((item, index) => (
            <QueueRow
              busy={busy}
              item={item}
              key={`${item.source}-${item.task.id}`}
              last={index === visible.length - 1}
              onOpen={() => onOpen(item)}
              suggestion={suggestedDestinations[item.task.id]}
            />
          ))}
          {hidden > 0 || expanded ? (
            <Pressable accessibilityRole="button" onPress={() => setExpanded((value) => !value)} style={styles.moreButton}>
              <Text style={styles.moreText}>{expanded ? 'Mostrar menos' : `Ver ${hidden} más en cola`}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : (
        <Text style={styles.queueEmpty}>
          {(view === 'mine' ? mineCount : availableCount) > 0
            // El contador incluye la maniobra destacada; la cola solo muestra las siguientes.
            ? 'No hay más maniobras en cola.'
            : view === 'mine'
            ? 'No tienes más tareas tomadas.'
            : plannerFresh === false
              ? 'Planificador sin actualizar: las tareas volverán cuando se recalcule.'
              : 'No hay tareas disponibles.'}
        </Text>
      )}
    </View>
  );
}

function QueueTab({ active, count, label, onPress }: { active: boolean; count: number; label: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="tab" accessibilityState={{ selected: active }} onPress={onPress} style={[styles.queueTab, active && styles.queueTabActive]}>
      <Text style={[styles.queueTabText, active && styles.queueTabTextActive]}>{label} · {count}</Text>
    </Pressable>
  );
}

function QueueRow({ busy, item, last, onOpen, suggestion }: {
  busy: boolean;
  item: OperatorQueueItem;
  last: boolean;
  onOpen: () => void;
  suggestion?: string;
}) {
  const task = item.task;
  const purpose = operatorTaskPurpose(task);
  const paused = task.maniobra?.estado === 'pausada_discrepancia';
  const alternative = isArbitrationAlternative(task);
  return (
    <View style={[styles.queueRow, last && styles.queueRowLast]}>
      <View style={styles.queueRowTop}>
        <OperatorEntityCode value={task.folio.numero_folio} />
        <OperatorPriorityBadge priority={task.prioridad} />
      </View>
      <Text numberOfLines={1} style={styles.queuePurpose}>{purpose.label}</Text>
      <OperatorRouteLine
        label="Ruta"
        value={`${operationalTaskOriginLabel(task)} → ${suggestion ? `${suggestion} · propuesto` : operationalTaskDestinationLabel(task)}`}
      />
      <Pressable accessibilityRole="button" disabled={busy || alternative} onPress={onOpen} style={[styles.queueAction, (busy || alternative) && styles.disabled]}>
        <Text style={styles.queueActionText}>{paused ? 'Ver estado' : alternative ? 'En espera' : item.source === 'mine' ? 'Abrir' : 'Tomar'} →</Text>
      </Pressable>
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
  if (!task.maniobra || task.maniobra.pasos_totales <= 1) return null;
  return `Paso ${task.secuencia_maniobra ?? task.maniobra.secuencia_actual} de ${task.maniobra.pasos_totales}`;
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
  return null;
}

function timeLabel(iso: string) {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { flexGrow: 1, paddingBottom: o.space[4] },
  columns: { flex: 1, flexDirection: 'row', alignItems: 'stretch', gap: o.space[6] },
  columnsCompact: { flexDirection: 'column', gap: o.space[4] },
  main: { flex: 1, minWidth: 0, gap: o.space[4] },
  aside: { width: 340, gap: o.space[4] },
  asideCompact: { width: 'auto' },
  eyebrow: { fontFamily: font.heavy, color: o.color.muted, fontSize: o.type.caption, letterSpacing: 1.2 },

  hero: {
    flexGrow: 1,
    minHeight: 560,
    padding: o.space[8],
    gap: o.space[6],
    borderWidth: 1,
    borderColor: o.color.border,
    borderRadius: o.radius.panel,
    backgroundColor: o.color.surface,
  },
  heroCompact: { minHeight: 0, padding: o.space[4], gap: o.space[4] },
  heroTop: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: o.space[3] },
  heroBadges: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[2] },
  identity: { gap: o.space[1] },
  heroTitle: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title, lineHeight: 34 },
  heroGoal: { fontFamily: font.regular, color: o.color.textSecondary, fontSize: o.type.body, lineHeight: 22 },
  folioLabel: { fontFamily: font.semibold, color: o.color.muted, fontSize: o.type.small, marginTop: o.space[3] },
  folio: { fontFamily: font.monoBold, color: o.color.text, fontSize: 72, lineHeight: 80 },
  folioCompact: { fontSize: 44, lineHeight: 52 },
  folioHead: { color: o.color.muted },
  heroMeta: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small },

  route: { flexDirection: 'row', alignItems: 'stretch', gap: o.space[3] },
  routeCompact: { flexDirection: 'column' },
  routeArrow: { fontFamily: font.bold, alignSelf: 'center', color: o.color.muted, fontSize: o.type.display, paddingHorizontal: o.space[2] },
  routeArrowCompact: { transform: [{ rotate: '90deg' }] },
  routeBlock: { flex: 1, minWidth: 0, gap: o.space[1], padding: o.space[6], borderRadius: o.radius.panel, borderWidth: 2, borderColor: 'transparent', backgroundColor: o.color.surfaceMuted },
  routeBlockPending: { borderStyle: 'dashed', borderColor: o.color.borderStrong, backgroundColor: 'transparent' },
  routeLabelRow: { flexDirection: 'row', alignItems: 'center', gap: o.space[2] },
  routeLabel: { fontFamily: font.heavy, color: o.color.muted, fontSize: o.type.caption, letterSpacing: 1.2 },
  routeProposed: { fontFamily: font.heavy, color: o.color.primaryPressed, fontSize: o.type.caption, letterSpacing: 1 },
  routePlace: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title, lineHeight: 34 },
  routePlacePending: { color: o.color.textSecondary },
  routeCode: { fontFamily: font.monoBold, color: o.color.primaryPressed, fontSize: o.type.heading },
  routeNote: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.body },

  heroHint: { fontFamily: font.bold, color: o.color.warning, fontSize: o.type.body },
  heroDetail: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small },
  heroSpacer: { flexGrow: 1 },
  heroAction: { minHeight: 84, paddingHorizontal: o.space[6], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.panel, backgroundColor: o.color.primary },
  heroActionText: { fontFamily: font.heavy, color: o.color.onPrimary, fontSize: o.type.heading },

  syncRow: { minHeight: o.touch.minimum, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[2] },
  syncText: { fontFamily: font.regular, flex: 1, color: o.color.muted, fontSize: o.type.small },
  refreshButton: { minHeight: o.touch.minimum, minWidth: o.touch.minimum, alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.pill, borderWidth: 1, borderColor: o.color.border },
  refreshText: { fontFamily: font.bold, color: o.color.textSecondary, fontSize: o.type.heading },

  panel: { padding: o.space[4], gap: o.space[3], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  panelMeta: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.caption },
  queueTabs: { flexDirection: 'row', gap: o.space[2] },
  queueTab: { flex: 1, minHeight: o.touch.minimum, paddingHorizontal: o.space[2], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, borderWidth: 1, borderColor: o.color.border },
  queueTabActive: { backgroundColor: o.color.surfaceMuted, borderColor: o.color.surfaceMuted },
  queueTabText: { fontFamily: font.semibold, color: o.color.textSecondary, fontSize: o.type.small },
  queueTabTextActive: { fontFamily: font.heavy, color: o.color.primaryPressed },
  queueRow: { paddingVertical: o.space[3], gap: o.space[2], borderBottomWidth: 1, borderBottomColor: o.color.border },
  queueRowLast: { borderBottomWidth: 0 },
  queueRowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[2] },
  queuePurpose: { fontFamily: font.bold, color: o.color.textSecondary, fontSize: o.type.small },
  queueAction: { minHeight: o.touch.minimum, alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, borderWidth: 1, borderColor: o.color.primary },
  queueActionText: { fontFamily: font.heavy, color: o.color.primaryPressed, fontSize: o.type.small },
  queueEmpty: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small, lineHeight: 20 },
  moreButton: { minHeight: o.touch.minimum, alignItems: 'center', justifyContent: 'center' },
  moreText: { fontFamily: font.bold, color: o.color.primaryPressed, fontSize: o.type.small },

  lastRow: { flexDirection: 'row', gap: o.space[3] },
  lastMark: { width: 36, height: 36, borderRadius: o.radius.pill, borderWidth: 1, borderColor: o.color.successBorder, backgroundColor: o.color.successSurface, alignItems: 'center', justifyContent: 'center' },
  lastMarkText: { fontFamily: font.heavy, color: o.color.success, fontSize: o.type.body },
  lastText: { flex: 1, minWidth: 0, gap: 2 },
  lastDestination: { fontFamily: font.regular, color: o.color.textSecondary, fontSize: o.type.small },

  loadsLink: { minHeight: o.touch.prominent, paddingHorizontal: o.space[4], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderRadius: o.radius.panel, borderWidth: 1, borderColor: o.color.border },
  loadsLinkText: { fontFamily: font.semibold, color: o.color.text, fontSize: o.type.body },
  loadsLinkArrow: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.heading },

  emptyHero: { flexGrow: 1, minHeight: 400, padding: o.space[8], alignItems: 'center', justifyContent: 'center', gap: o.space[3], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  emptyHeroTitle: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title, textAlign: 'center' },
  emptyHeroCopy: { fontFamily: font.regular, maxWidth: 520, color: o.color.muted, fontSize: o.type.body, lineHeight: 24, textAlign: 'center' },
  disabled: { opacity: 0.45 },
});
