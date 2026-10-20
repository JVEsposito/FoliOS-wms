import { useEffect, useState, type ReactNode } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';

import {
  OperationalTask,
  type ManeuverDiscrepancyType,
  type ReportedManeuverDiscrepancy,
  operationalTaskLabel,
  operationalTaskPositionLabel,
  operationalTaskReason,
} from '../../domain/operationalTasks';
import {
  buildOperatorManeuverAction,
  buildOperatorManeuverSequence,
  type OperatorManeuverSequenceItem,
} from '../../domain/operatorManeuver';
import { operatorTheme as o } from '../../theme/operatorTheme';
import type { OperatorExceptionKind } from '../../domain/operatorManeuverException';
import type { StartConfirmation } from '../../services/operationalTasksApi';
import { OperatorExceptionReport } from './OperatorExceptionReport';
import { OperatorFolioConfirmation, type FolioConfirmationState } from './OperatorFolioConfirmation';
import { OperatorPhysicalContextPanel } from './OperatorPhysicalContextPanel';
import { OperatorTaskPurpose } from './OperatorTaskPurpose';
import { OperatorEntityCode, OperatorPriorityBadge, OperatorStatusBadge } from './OperatorPrimitives';

type Props = {
  busy: boolean;
  confirmation: FolioConfirmationState | null;
  deviceName: string;
  hasPhysicalDestination: boolean;
  leaseExpired: boolean;
  onBack: () => void;
  onCancelConfirmation: () => void;
  onComplete: () => void;
  onCompleteDirect: () => void;
  onCompleteTemporary: () => void;
  onReportException: (
    type: ManeuverDiscrepancyType,
    detail: string,
  ) => Promise<ReportedManeuverDiscrepancy | null>;
  onConfirmStart: (confirmation: StartConfirmation) => void;
  onCreatePin: (pin: string) => Promise<boolean>;
  onRecalculate: () => void;
  onRelease: () => void;
  /** Abre la confirmación de folio y PIN; el retiro se envía desde onConfirmStart. */
  onStart: () => void;
  operatorName: string;
  secondsRemaining: number | null;
  task: OperationalTask;
};

export function OperatorTaskExecution({
  busy,
  confirmation,
  deviceName,
  hasPhysicalDestination,
  leaseExpired,
  onBack,
  onCancelConfirmation,
  onComplete,
  onCompleteDirect,
  onCompleteTemporary,
  onReportException,
  onConfirmStart,
  onCreatePin,
  onRecalculate,
  onRelease,
  onStart,
  operatorName,
  secondsRemaining,
  task,
}: Props) {
  const { width } = useWindowDimensions();
  const [exceptionKind, setExceptionKind] = useState<OperatorExceptionKind | null>(null);
  const compact = width < o.breakpoint.compact;
  const wide = width >= 1000;
  const action = buildOperatorManeuverAction(task);
  const sequence = buildOperatorManeuverSequence(task);
  const moving = task.estado === 'en_proceso';
  const currentStep = task.secuencia_maniobra ?? task.maniobra?.secuencia_actual ?? 1;
  const totalSteps = task.maniobra?.pasos_totales ?? 1;
  const primaryDisabled = busy
    || leaseExpired
    || (!moving && task.tipo_movimiento !== 'retiro' && !hasPhysicalDestination);

  useEffect(() => setExceptionKind(null), [task.id]);
  useEffect(() => {
    if (task.maniobra?.estado !== 'pausada_discrepancia') setExceptionKind(null);
  }, [task.maniobra?.estado]);

  const runPrimary = () => {
    if (!moving) return onStart();
    if (task.tipo_movimiento !== 'retiro') return onComplete();
    if (task.tipo_paso_maniobra === 'extraccion_temporal') return onCompleteTemporary();
    return onCompleteDirect();
  };

  if (exceptionKind) {
    return (
      <OperatorExceptionReport
        busy={busy}
        kind={exceptionKind}
        onCancel={() => setExceptionKind(null)}
        onClose={onBack}
        onSubmit={onReportException}
        task={task}
      />
    );
  }

  if (confirmation && !moving) {
    return (
      <OperatorFolioConfirmation
        busy={busy}
        onCancel={onCancelConfirmation}
        onConfirm={onConfirmStart}
        onCreatePin={onCreatePin}
        onMismatch={() => {
          onCancelConfirmation();
          if (task.maniobra) setExceptionKind('mismatch');
        }}
        state={confirmation}
        task={task}
      />
    );
  }

  if (task.maniobra?.estado === 'pausada_discrepancia') {
    return <PausedManeuver onBack={onBack} task={task} />;
  }

  return (
    <ScrollView contentContainerStyle={[styles.content, wide && styles.wideContent]} style={styles.screen}>
      <OperatorTaskPurpose task={task} />
      {wide ? (
        <>
          <View style={styles.wideHeader}>
            <Pressable accessibilityLabel="Volver a maniobras" disabled={busy} onPress={onBack} style={styles.wideBack}>
              <Text style={styles.wideBackText}>‹</Text>
            </Pressable>
            <Text style={styles.wideTitle}>{task.maniobra?.titulo ?? operationalTaskLabel(task.plan.tipo)}</Text>
            <OperatorStatusBadge label={moving ? 'EN EJECUCIÓN' : 'PREPARADA'} tone={moving ? 'success' : 'info'} />
          </View>
          <View testID="maneuver-wide-columns" style={styles.wideColumns}>
            <View style={styles.wideLeft}>
              <View style={styles.wideSummary}>
                <Text style={styles.wideLabel}>Mi maniobra</Text>
                <Text style={styles.wideFolio}>{task.folio.numero_folio}</Text>
                <Text style={styles.wideLabor}>
                  {operationalTaskLabel(task.plan.tipo)}{maneuverObjectiveCount(task) > 1 ? ' · ' + maneuverObjectiveCount(task) + ' objetivos' : ''}
                </Text>
                <Text style={styles.wideRoute}>{operationalTaskPositionLabel(task.origen)} → {action.destination}</Text>
              </View>
              <View style={styles.wideSteps}>
                <Text style={styles.wideLabel}>PASO {currentStep} / {totalSteps}</Text>
                {Array.from({ length: totalSteps }, (_, index) => {
                  const number = index + 1;
                  const complete = number < currentStep;
                  const current = number === currentStep;
                  const step = sequence.find((item) => item.number === number);
                  return (
                    <View key={number} style={[styles.wideStep, complete && styles.wideStepComplete, current && styles.wideStepCurrent]}>
                      <View style={[styles.wideStepCircle, complete && styles.wideStepCircleComplete, current && styles.wideStepCircleCurrent]}>
                        <Text style={[styles.wideStepNumber, (complete || current) && styles.wideStepNumberActive]}>{complete ? '✓' : number}</Text>
                      </View>
                      <View style={styles.wideStepCopy}>
                        <Text style={styles.wideStepInstruction}>{current ? action.instruction : step?.action ?? 'Paso ' + number}</Text>
                        <Text style={styles.wideStepState}>{complete ? 'Completado' : current ? 'Ahora' : 'Pendiente'}</Text>
                      </View>
                    </View>
                  );
                })}
              </View>
              <View style={styles.wideExceptions}>
                {task.maniobra ? (
                  <View style={styles.wideExceptionRow}>
                    <Pressable disabled={busy} onPress={() => setExceptionKind('mismatch')} style={styles.wideExceptionButton}><Text style={styles.wideExceptionText}>NO COINCIDE</Text></Pressable>
                    <Pressable disabled={busy} onPress={() => setExceptionKind('impossible')} style={styles.wideExceptionButton}><Text style={styles.wideExceptionText}>NO ES POSIBLE</Text></Pressable>
                  </View>
                ) : null}
                {!moving ? <Pressable disabled={busy} onPress={onRelease} style={styles.wideRelease}><Text style={styles.wideReleaseText}>Liberar antes de iniciar</Text></Pressable> : null}
              </View>
            </View>
            <View style={styles.wideRight}>
              <Text style={styles.wideDestinationLabel}>{action.verb === 'RETIRAR' ? 'Retira el pallet de' : action.verb === 'UBICAR' ? 'Lleva el pallet a' : 'Entrega el pallet en'}</Text>
              <View style={styles.wideDestinationRow}>
                <Text style={[styles.wideDestination, (action.verb === 'RETIRAR' ? operationalTaskPositionLabel(task.origen) : action.destination).length > 14 && styles.wideDestinationLong]}>
                  {action.verb === 'RETIRAR' ? operationalTaskPositionLabel(task.origen) : action.destination}
                </Text>
                <Text style={styles.wideReason}>{operationalTaskReason(task)}</Text>
              </View>
              <PhysicalPattern dense task={task} />
              {!moving && task.tipo_movimiento !== 'retiro' && !hasPhysicalDestination ? (
                <View style={styles.destinationWarning}>
                  <View style={styles.destinationWarningCopy}><Text style={styles.destinationWarningTitle}>Destino físico pendiente</Text><Text style={styles.destinationWarningText}>El servidor debe validar una posición antes de retirar el pallet.</Text></View>
                  <Pressable disabled={busy || leaseExpired} onPress={onRecalculate} style={styles.recalculateButton}><Text style={styles.recalculateText}>Recalcular destino</Text></Pressable>
                </View>
              ) : null}
              {leaseExpired ? <View style={styles.leaseExpired}><Text style={styles.leaseExpiredTitle}>La reserva venció antes de iniciar</Text><Text style={styles.leaseExpiredCopy}>Vuelve a la bandeja y toma nuevamente la tarea.</Text></View> : null}
              <Pressable testID="maneuver-primary" disabled={primaryDisabled} onPress={runPrimary} style={[styles.widePrimary, primaryDisabled && styles.widePrimaryDisabled]}>
                <Text style={[styles.widePrimaryText, primaryDisabled && styles.widePrimaryTextDisabled]}>{action.primaryLabel}</Text>
              </Pressable>
              <Text style={styles.wideHelp}>{action.instruction}</Text>
            </View>
          </View>
        </>
      ) : (
        <>
          <View style={[styles.header, compact && styles.headerCompact]}>
            <Pressable disabled={busy} onPress={onBack} style={styles.backButton}>
              <Text style={styles.backIcon}>‹</Text>
              <View>
                <Text style={styles.headerTitle}>Mi maniobra</Text>
                <Text style={styles.headerCopy}>Ejecución paso a paso</Text>
              </View>
            </Pressable>
            <View style={styles.headerStatus}>
              <OperatorStatusBadge
                label={moving ? 'EN EJECUCIÓN' : 'PREPARADA'}
                tone={moving ? 'success' : 'info'}
              />
              <Text style={styles.headerManeuver}>{task.maniobra?.titulo ?? operationalTaskLabel(task.plan.tipo)}</Text>
            </View>
          </View>

          <View style={[styles.summary, compact && styles.summaryCompact]}>
            <SummaryCell label="Folio / pallet"><OperatorEntityCode prominent value={task.folio.numero_folio} /></SummaryCell>
            <SummaryCell label="Labor" value={operationalTaskLabel(task.plan.tipo)} />
            {maneuverObjectiveCount(task) > 1 ? (
              <SummaryCell label="Objetivos" value={String(maneuverObjectiveCount(task))} />
            ) : null}
            <SummaryCell label="Ubicación actual" value={operationalTaskPositionLabel(task.origen)} />
            <SummaryCell label="Operador" value={task.responsable?.nombre ?? operatorName} />
            <SummaryCell label="Dispositivo" value={task.dispositivo?.nombre ?? deviceName} />
          </View>

          <View style={styles.progressPanel}>
            <Text style={styles.progressTitle}>PASO {currentStep} / {totalSteps}</Text>
            <View style={styles.progressRail}>
              {Array.from({ length: totalSteps }, (_, index) => {
                const number = index + 1;
                const state = number < currentStep ? 'complete' : number === currentStep ? 'current' : 'pending';
                return (
                  <View key={number} style={styles.progressItem}>
                    <View style={[
                      styles.progressDot,
                      state === 'complete' && styles.progressDotComplete,
                      state === 'current' && styles.progressDotCurrent,
                    ]}>
                      <Text style={[
                        styles.progressDotText,
                        state !== 'pending' && styles.progressDotTextActive,
                      ]}>{state === 'complete' ? '✓' : number}</Text>
                    </View>
                    {number < totalSteps ? <View style={[
                      styles.progressLine,
                      number < currentStep && styles.progressLineComplete,
                    ]} /> : null}
                  </View>
                );
              })}
            </View>
            <Text style={styles.progressNext}>{action.instruction}</Text>
          </View>

          <View style={[styles.instructionGrid, compact && styles.instructionGridCompact]}>
            <View style={styles.actionColumn}>
              <View style={[
                styles.actionPanel,
                action.tone === 'critical' ? styles.actionPanelCritical : styles.actionPanelSuccess,
              ]}>
                <Text style={styles.actionKicker}>{moving ? 'ACCIÓN ACTUAL' : 'SIGUIENTE ACCIÓN'}</Text>
                <Text style={styles.actionVerb}>{action.verb}</Text>
                <Text style={styles.actionCopy}>{action.instruction}</Text>
                <OperatorEntityCode prominent value={task.folio.numero_folio} />
              </View>

              <View style={styles.locationCard}>
                <LocationLine label="Origen" value={operationalTaskPositionLabel(task.origen)} />
                <LocationLine label="Destino" value={action.destination} strong />
                <LocationLine label="Motivo" value={operationalTaskReason(task)} />
              </View>
            </View>

            <PhysicalPattern task={task} />
          </View>

          <OperatorPhysicalContextPanel task={task} />

          {!moving && task.tipo_movimiento !== 'retiro' && !hasPhysicalDestination ? (
            <View style={styles.destinationWarning}>
              <View style={styles.destinationWarningCopy}>
                <Text style={styles.destinationWarningTitle}>Destino físico pendiente</Text>
                <Text style={styles.destinationWarningText}>El servidor debe validar una posición antes de retirar el pallet.</Text>
              </View>
              <Pressable disabled={busy || leaseExpired} onPress={onRecalculate} style={styles.recalculateButton}>
                <Text style={styles.recalculateText}>Recalcular destino</Text>
              </Pressable>
            </View>
          ) : null}

          {leaseExpired ? (
            <View style={styles.leaseExpired}>
              <Text style={styles.leaseExpiredTitle}>La reserva venció antes de iniciar</Text>
              <Text style={styles.leaseExpiredCopy}>Vuelve a la bandeja y toma nuevamente la tarea.</Text>
            </View>
          ) : null}

          <Pressable
            disabled={primaryDisabled}
            onPress={runPrimary}
            style={[styles.primaryButton, primaryDisabled && styles.disabled]}
          >
            <Text style={styles.primaryButtonText}>{action.primaryLabel}</Text>
            <Text style={styles.primaryArrow}>›</Text>
          </Pressable>

          <View style={styles.secondaryActions}>
            {task.maniobra ? (
              <>
                <Pressable disabled={busy} onPress={() => setExceptionKind('mismatch')} style={styles.secondaryButton}>
                  <Text style={styles.secondarySymbol}>×</Text>
                  <Text style={styles.secondaryText}>NO COINCIDE</Text>
                </Pressable>
                <Pressable disabled={busy} onPress={() => setExceptionKind('impossible')} style={styles.secondaryButton}>
                  <Text style={styles.secondarySymbol}>!</Text>
                  <Text style={styles.secondaryText}>NO ES POSIBLE</Text>
                </Pressable>
              </>
            ) : null}
            {!moving ? (
              <Pressable disabled={busy} onPress={onRelease} style={styles.releaseButton}>
                <Text style={styles.releaseText}>Liberar antes de iniciar</Text>
              </Pressable>
            ) : null}
          </View>

        </>
      )}
      <SequencePanel currentStep={currentStep} items={sequence} />
      <CustodyPanel task={task} />
      {wide ? <OperatorPhysicalContextPanel task={task} /> : null}

      <View style={styles.footerActions}>
        <Pressable disabled={busy} onPress={onBack} style={styles.footerBack}>
          <Text style={styles.footerBackText}>‹ VOLVER A MANIOBRAS</Text>
        </Pressable>
        <CommitmentBadge seconds={secondsRemaining} task={task} />
      </View>
    </ScrollView>
  );
}

function maneuverObjectiveCount(task: OperationalTask) {
  return Math.max(1, task.maniobra?.objetivos?.length ?? 0);
}

function PausedManeuver({ onBack, task }: { onBack: () => void; task: OperationalTask }) {
  const physicalState = task.estado === 'en_proceso'
    ? 'Pallet en movimiento con destino fijo'
    : task.maniobra?.custodia_temporal_activa
      ? 'Existe custodia temporal activa'
      : 'Movimiento todavía no iniciado';
  const instruction = task.estado === 'en_proceso' || task.maniobra?.custodia_temporal_activa
    ? 'Mantén el pallet bajo control de esta maniobra. No lo ubiques ni lo entregues fuera de la instrucción que enviará supervisión.'
    : 'No retires el pallet. Conserva la posición actual hasta que supervisión confirme cómo continuar.';

  return (
    <ScrollView contentContainerStyle={styles.pausedContent} style={styles.screen}>
      <View style={styles.pausedHeader}>
        <OperatorStatusBadge label="EN ESPERA DE SUPERVISIÓN" tone="warning" />
        <Text accessibilityRole="header" style={styles.pausedTitle}>MANIOBRA PAUSADA</Text>
        <Text style={styles.pausedLead}>NO MUEVAS EL PALLET</Text>
        <Text style={styles.pausedCopy}>{instruction}</Text>
      </View>

      <View style={styles.pausedSummary}>
        <SummaryCell label="Folio / pallet"><OperatorEntityCode prominent value={task.folio.numero_folio} /></SummaryCell>
        <SummaryCell label="Estado físico" value={physicalState} />
        <SummaryCell label="Paso" value={`${task.secuencia_maniobra ?? task.maniobra?.secuencia_actual ?? 1} de ${task.maniobra?.pasos_totales ?? 1}`} />
        <SummaryCell label="Ubicación informada" value={operationalTaskPositionLabel(task.origen)} />
        <SummaryCell label="Destino protegido" value={buildOperatorManeuverAction(task).destination} />
      </View>

      <CustodyPanel task={task} />

      <View style={styles.pausedSync}>
        <Text style={styles.pausedSyncTitle}>Decisión supervisada pendiente</Text>
        <Text style={styles.pausedSyncCopy}>
          Esta pantalla se actualiza automáticamente. Si supervisión reanuda la maniobra, el mismo paso volverá listo a esta tablet.
        </Text>
      </View>

      <Pressable onPress={onBack} style={styles.footerBack}>
        <Text style={styles.footerBackText}>‹ VOLVER A MI JORNADA</Text>
      </Pressable>
    </ScrollView>
  );
}

function SummaryCell({ children, label, value }: { children?: ReactNode; label: string; value?: string }) {
  return (
    <View style={styles.summaryCell}>
      <Text style={styles.summaryLabel}>{label}</Text>
      {children ?? <Text numberOfLines={2} style={styles.summaryValue}>{value}</Text>}
    </View>
  );
}

function LocationLine({ label, strong = false, value }: { label: string; strong?: boolean; value: string }) {
  return (
    <View style={styles.locationLine}>
      <Text style={styles.locationLabel}>{label}</Text>
      <Text style={[styles.locationValue, strong && styles.locationValueStrong]}>{value}</Text>
    </View>
  );
}

function PhysicalPattern({ task, dense = false }: { task: OperationalTask; dense?: boolean }) {
  const endpoint = task.estado === 'en_proceso' && task.destino?.posicion ? task.destino : task.origen;
  const position = endpoint?.posicion;
  const camera = endpoint?.camara.nombre ?? 'Origen externo';
  return (
    <View style={[styles.patternCard, dense && styles.widePattern]}>
      <View style={styles.patternHeader}>
        <Text style={styles.patternTitle}>Patrón físico</Text>
        <Text style={styles.patternCamera}>{camera}</Text>
      </View>
      {position ? (
        <View style={[styles.patternBody, dense && styles.widePatternBody]}>
          <Text style={styles.patternDepth}>↑ FONDO</Text>
          <View style={styles.rack}>
            <View style={[styles.rackRail, dense && styles.wideRackRail]} />
            <View style={[styles.positionBox, dense && styles.widePositionBox]}>
              <Text style={styles.positionBand}>BANDA {String(position.banda).padStart(2, '0')}</Text>
              <Text style={styles.positionCode}>{position.etiqueta ?? `P${String(position.posicion).padStart(2, '0')} · N${position.nivel}`}</Text>
              <Text style={styles.positionStatus}>{task.estado === 'en_proceso' ? 'DESTINO' : 'ACTUAL'}</Text>
            </View>
            <View style={[styles.rackRail, dense && styles.wideRackRail]} />
          </View>
          <Text style={styles.patternDepth}>ENTRADA ↓</Text>
        </View>
      ) : (
        <View style={[styles.patternEmpty, dense && styles.widePatternEmpty]}>
          <Text style={styles.patternEmptyTitle}>Sin posición permanente</Text>
          <Text style={styles.patternEmptyCopy}>El WMS mantiene el pallet bajo control de la maniobra.</Text>
        </View>
      )}
    </View>
  );
}

function SequencePanel({ currentStep, items }: { currentStep: number; items: OperatorManeuverSequenceItem[] }) {
  return (
    <View style={styles.sequencePanel}>
      <View style={styles.panelHeader}>
        <Text style={styles.panelTitle}>Secuencia de la maniobra</Text>
        <Text style={styles.panelMeta}>{items.length} {items.length === 1 ? 'paso' : 'pasos'} en total</Text>
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false}>
        <View style={styles.sequenceRow}>
          {items.map((item) => (
            <View key={item.id} style={[
              styles.sequenceItem,
              item.state === 'current' && styles.sequenceItemCurrent,
              item.state === 'complete' && styles.sequenceItemComplete,
            ]}>
              <View style={styles.sequenceTopline}>
                <View style={[
                  styles.sequenceNumber,
                  item.state === 'current' && styles.sequenceNumberCurrent,
                  item.state === 'complete' && styles.sequenceNumberComplete,
                ]}>
                  <Text style={styles.sequenceNumberText}>{item.state === 'complete' ? '✓' : item.number}</Text>
                </View>
                <Text style={styles.sequenceState}>{item.state === 'complete' ? 'COMPLETADO' : item.number === currentStep ? 'EN CURSO' : 'PENDIENTE'}</Text>
              </View>
              <Text style={styles.sequenceFolio}>{item.folio}</Text>
              <Text numberOfLines={3} style={styles.sequenceRoute}>{item.route}</Text>
              <Text numberOfLines={2} style={styles.sequenceAction}>{item.action}</Text>
            </View>
          ))}
        </View>
      </ScrollView>
    </View>
  );
}

function CustodyPanel({ task }: { task: OperationalTask }) {
  const custodias = task.maniobra?.custodias_temporales ?? [];
  return (
    <View style={styles.custodyPanel}>
      <View style={styles.panelHeader}>
        <Text style={styles.panelTitle}>Bajo maniobra</Text>
        <Text style={styles.panelMeta}>{custodias.length} {custodias.length === 1 ? 'pallet' : 'pallets'}</Text>
      </View>
      {custodias.length ? custodias.map((custodia) => (
        <View key={custodia.id} style={styles.custodyRow}>
          <View style={styles.custodyState}><Text style={styles.custodyStateText}>EXTRAÍDO TEMPORALMENTE</Text></View>
          <OperatorEntityCode value={custodia.folio?.numero_folio ?? 'Folio bajo custodia'} />
          <Text style={styles.custodyOrigin}>Origen: {operationalTaskPositionLabel(custodia.origen)}</Text>
        </View>
      )) : (
        <View style={styles.custodyEmpty}>
          <Text style={styles.custodyEmptyText}>No hay pallets extraídos temporalmente en este momento.</Text>
        </View>
      )}
    </View>
  );
}

function CommitmentBadge({ task, seconds }: { task: OperationalTask; seconds: number | null }) {
  if (task.estado === 'en_proceso') {
    return <OperatorStatusBadge label="DESTINO FIJO · PUNTO DE NO RETORNO" tone="critical" />;
  }
  const expired = seconds !== null && seconds <= 0;
  const warning = seconds !== null && seconds > 0 && seconds < 180;
  const prefix = task.reserva?.tipo_compromiso === 'fisica' ? 'RESERVA FÍSICA' : 'TAREA TOMADA';
  return (
    <OperatorStatusBadge
      label={expired ? `${prefix} VENCIDA` : `${prefix} · ${seconds === null ? 'ACTIVA' : formatDuration(seconds)}`}
      tone={expired ? 'critical' : warning ? 'warning' : 'success'}
    />
  );
}

function formatDuration(seconds: number) {
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

const styles = StyleSheet.create({
  wideContent: { padding: 20, gap: 20 },
  wideHeader: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  wideBack: { width: 48, minHeight: 48, borderWidth: 1.5, borderColor: o.color.borderStrong, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  wideBackText: { color: o.color.text, fontSize: 36 },
  wideTitle: { flex: 1, color: o.color.text, fontFamily: o.font.condensed, fontSize: 22, fontWeight: '800' },
  wideColumns: { flexDirection: 'row', alignItems: 'flex-start', gap: 20 },
  wideLeft: { width: 340, gap: 16 },
  wideSummary: { backgroundColor: o.color.surface, borderRadius: 14, padding: 18, gap: 8 },
  wideLabel: { color: o.color.muted, fontSize: 14, fontWeight: '600' },
  wideFolio: { color: o.color.primary, fontFamily: o.font.mono, fontSize: 34, fontWeight: '800' },
  wideLabor: { color: o.color.textSecondary, fontSize: 15 },
  wideRoute: { color: o.color.text, fontFamily: o.font.mono, fontSize: 15, fontWeight: '600' },
  wideSteps: { gap: 8 },
  wideStep: { flexDirection: 'row', alignItems: 'center', gap: 8, padding: 8, borderRadius: 14, borderWidth: 2, borderColor: 'transparent', backgroundColor: o.color.surface },
  wideStepComplete: { backgroundColor: o.color.successSurface },
  wideStepCurrent: { backgroundColor: o.color.selected, borderColor: o.color.primary },
  wideStepCircle: { width: 40, height: 40, borderRadius: o.radius.pill, borderWidth: 2, borderColor: o.color.borderStrong, alignItems: 'center', justifyContent: 'center' },
  wideStepCircleComplete: { backgroundColor: o.color.success, borderColor: o.color.success },
  wideStepCircleCurrent: { backgroundColor: o.color.primary, borderColor: o.color.primary },
  wideStepNumber: { color: o.color.text, fontSize: 18, fontWeight: '700' },
  wideStepNumberActive: { color: o.color.onPrimary },
  wideStepCopy: { flex: 1, gap: 8 },
  wideStepInstruction: { color: o.color.text, fontSize: 15, fontWeight: '600' },
  wideStepState: { color: o.color.muted, fontSize: 13 },
  wideExceptions: { gap: 8 },
  wideExceptionRow: { flexDirection: 'row', gap: 8 },
  wideExceptionButton: { flex: 1, minHeight: 60, borderRadius: 12, borderWidth: 2, borderColor: o.color.criticalBorder, alignItems: 'center', justifyContent: 'center', padding: 8 },
  wideExceptionText: { color: o.color.critical, fontSize: 18, fontWeight: '700', textAlign: 'center' },
  wideRelease: { minHeight: 60, borderRadius: 12, borderWidth: 2, borderColor: o.color.borderStrong, alignItems: 'center', justifyContent: 'center', padding: 8 },
  wideReleaseText: { color: o.color.text, fontSize: 15, fontWeight: '700' },
  wideRight: { flex: 1, minWidth: 0, backgroundColor: o.color.surface, borderRadius: 18, paddingHorizontal: 24, paddingVertical: 26, gap: 16 },
  wideDestinationLabel: { color: o.color.muted, fontSize: 16, fontWeight: '600' },
  wideDestinationRow: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  wideDestination: { flex: 1, minWidth: 0, color: o.color.primary, fontFamily: o.font.mono, fontSize: 76, fontWeight: '700', letterSpacing: -3 },
  wideDestinationLong: { fontSize: 56 },
  wideReason: { flexShrink: 1, maxWidth: 260, color: o.color.textSecondary, fontSize: 16, textAlign: 'right' },
  widePattern: { flex: 0, minWidth: 0, backgroundColor: o.color.canvas, borderRadius: 12 },
  widePatternBody: { flex: 0, minHeight: 0, padding: 16, gap: 8 },
  wideRackRail: { minHeight: 76 },
  widePositionBox: { minHeight: 76 },
  widePatternEmpty: { flex: 0, minHeight: 0, padding: 16 },
  widePrimary: { minHeight: 76, borderRadius: 14, backgroundColor: o.color.primary, paddingHorizontal: 16, paddingVertical: 8, alignItems: 'center', justifyContent: 'center' },
  widePrimaryDisabled: { backgroundColor: o.color.disabled },
  widePrimaryText: { color: o.color.onPrimary, fontSize: 24, fontWeight: '800', textAlign: 'center' },
  widePrimaryTextDisabled: { color: o.color.disabledText },
  wideHelp: { color: o.color.muted, fontSize: 15, marginTop: -6 },
  screen: { flex: 1, backgroundColor: o.color.canvas },
  content: { gap: o.space[3], paddingBottom: o.space[8] },
  pausedContent: { gap: o.space[3], paddingBottom: o.space[8] },
  pausedHeader: { minHeight: 280, padding: o.space[6], alignItems: 'center', justifyContent: 'center', gap: o.space[3], borderWidth: 2, borderColor: o.color.warning, borderRadius: o.radius.panel, backgroundColor: o.color.warningSurface },
  pausedTitle: { color: o.color.text, fontSize: 38, lineHeight: 44, fontWeight: '900', textAlign: 'center' },
  pausedLead: { color: o.color.critical, fontSize: o.type.title, fontWeight: '900', textAlign: 'center' },
  pausedCopy: { maxWidth: 720, color: o.color.text, fontSize: o.type.body, lineHeight: 24, fontWeight: '700', textAlign: 'center' },
  pausedSummary: { flexDirection: 'row', flexWrap: 'wrap', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  pausedSync: { padding: o.space[4], borderWidth: 1, borderColor: o.color.primary, borderRadius: o.radius.panel, backgroundColor: o.color.selected },
  pausedSyncTitle: { color: o.color.primaryPressed, fontSize: o.type.body, fontWeight: '900' },
  pausedSyncCopy: { color: o.color.text, fontSize: o.type.small, lineHeight: 20, marginTop: o.space[1] },
  header: { minHeight: 64, paddingHorizontal: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[4], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  headerCompact: { alignItems: 'stretch', flexDirection: 'column', paddingVertical: o.space[3] },
  backButton: { minHeight: o.touch.minimum, flexDirection: 'row', alignItems: 'center', gap: o.space[3] },
  backIcon: { width: 42, color: o.color.onNavy, backgroundColor: o.color.navy, borderRadius: o.radius.control, fontSize: 40, lineHeight: 48, fontWeight: '500', textAlign: 'center' },
  headerTitle: { color: o.color.text, fontSize: o.type.heading, fontWeight: '900' },
  headerCopy: { color: o.color.muted, fontSize: o.type.small },
  headerStatus: { flexShrink: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: o.space[3] },
  headerManeuver: { maxWidth: 300, color: o.color.text, fontSize: o.type.body, fontWeight: '900', textAlign: 'right' },
  summary: { flexDirection: 'row', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  summaryCompact: { flexWrap: 'wrap' },
  summaryCell: { flex: 1, minWidth: 150, minHeight: 86, padding: o.space[3], justifyContent: 'center', borderRightWidth: 1, borderRightColor: o.color.border },
  summaryLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800', marginBottom: o.space[1] },
  summaryValue: { color: o.color.text, fontSize: o.type.body, lineHeight: 21, fontWeight: '900' },
  progressPanel: { minHeight: 78, padding: o.space[3], flexDirection: 'row', alignItems: 'center', gap: o.space[4], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  progressTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900' },
  progressRail: { flexDirection: 'row', alignItems: 'center' },
  progressItem: { flexDirection: 'row', alignItems: 'center' },
  progressDot: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center', backgroundColor: o.color.disabled },
  progressDotCurrent: { backgroundColor: o.color.primary },
  progressDotComplete: { backgroundColor: o.color.success },
  progressDotText: { color: o.color.disabledText, fontSize: o.type.small, fontWeight: '900' },
  progressDotTextActive: { color: o.color.onPrimary },
  progressLine: { width: 36, height: 3, backgroundColor: o.color.disabled },
  progressLineComplete: { backgroundColor: o.color.success },
  progressNext: { flex: 1, color: o.color.muted, fontSize: o.type.small, lineHeight: 20, textAlign: 'right' },
  instructionGrid: { flexDirection: 'row', gap: o.space[3] },
  instructionGridCompact: { flexDirection: 'column' },
  actionColumn: { flex: 1.75, minWidth: 0, gap: o.space[3] },
  actionPanel: { minHeight: 250, padding: o.space[6], alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderRadius: o.radius.panel },
  actionPanelCritical: { borderColor: o.color.critical, backgroundColor: o.color.criticalSurface },
  actionPanelSuccess: { borderColor: o.color.success, backgroundColor: o.color.successSurface },
  actionKicker: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 1.2 },
  actionVerb: { color: o.color.text, fontSize: 54, lineHeight: 62, fontWeight: '900', marginTop: o.space[2] },
  actionCopy: { maxWidth: 620, color: o.color.text, fontSize: o.type.body, lineHeight: 23, fontWeight: '700', textAlign: 'center', marginBottom: o.space[3] },
  locationCard: { padding: o.space[4], gap: o.space[2], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  locationLine: { minHeight: 38, flexDirection: 'row', gap: o.space[3] },
  locationLabel: { width: 76, color: o.color.muted, fontSize: o.type.small, fontWeight: '800' },
  locationValue: { flex: 1, color: o.color.text, fontSize: o.type.small, lineHeight: 20 },
  locationValueStrong: { color: o.color.primaryPressed, fontWeight: '900' },
  patternCard: { flex: 1, minWidth: 250, overflow: 'hidden', borderWidth: 1, borderColor: o.color.borderStrong, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  patternHeader: { minHeight: 52, paddingHorizontal: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[2], backgroundColor: o.color.navy },
  patternTitle: { color: o.color.onNavy, fontSize: o.type.body, fontWeight: '900' },
  patternCamera: { flex: 1, color: o.color.textSecondary, fontSize: o.type.small, textAlign: 'right' },
  patternBody: { flex: 1, minHeight: 300, padding: o.space[4], alignItems: 'center', justifyContent: 'space-between' },
  patternDepth: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 0.8 },
  rack: { width: '84%', flexDirection: 'row', alignItems: 'stretch', justifyContent: 'center' },
  rackRail: { width: 14, minHeight: 160, borderWidth: 2, borderColor: o.color.navy, backgroundColor: o.color.border },
  positionBox: { flex: 1, minHeight: 160, padding: o.space[3], alignItems: 'center', justifyContent: 'center', borderTopWidth: 8, borderBottomWidth: 8, borderColor: o.color.warning, backgroundColor: o.color.selected },
  positionBand: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900' },
  positionCode: { color: o.color.text, fontSize: o.type.heading, fontWeight: '900', textAlign: 'center', marginTop: o.space[2] },
  positionStatus: { color: o.color.primaryPressed, fontSize: o.type.caption, fontWeight: '900', marginTop: o.space[2] },
  patternEmpty: { flex: 1, minHeight: 260, padding: o.space[6], alignItems: 'center', justifyContent: 'center' },
  patternEmptyTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', textAlign: 'center' },
  patternEmptyCopy: { color: o.color.muted, fontSize: o.type.small, lineHeight: 20, textAlign: 'center', marginTop: o.space[2] },
  destinationWarning: { padding: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[3], borderWidth: 1, borderColor: o.color.warning, borderRadius: o.radius.panel, backgroundColor: o.color.warningSurface },
  destinationWarningCopy: { flex: 1 },
  destinationWarningTitle: { color: o.color.warning, fontSize: o.type.body, fontWeight: '900' },
  destinationWarningText: { color: o.color.text, fontSize: o.type.small, marginTop: o.space[1] },
  recalculateButton: { minHeight: o.touch.minimum, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.warning },
  recalculateText: { color: o.color.onPrimary, fontSize: o.type.small, fontWeight: '900' },
  leaseExpired: { padding: o.space[3], borderWidth: 1, borderColor: o.color.critical, borderRadius: o.radius.panel, backgroundColor: o.color.criticalSurface },
  leaseExpiredTitle: { color: o.color.critical, fontSize: o.type.body, fontWeight: '900' },
  leaseExpiredCopy: { color: o.color.text, fontSize: o.type.small, marginTop: o.space[1] },
  primaryButton: { minHeight: 72, paddingHorizontal: o.space[6], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: o.space[3], borderRadius: o.radius.control, backgroundColor: o.color.success },
  primaryButtonText: { color: o.color.onPrimary, fontSize: o.type.heading, fontWeight: '900' },
  primaryArrow: { color: o.color.onPrimary, fontSize: 38, lineHeight: 40 },
  disabled: { opacity: 0.42 },
  secondaryActions: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[3] },
  secondaryButton: { minHeight: o.touch.prominent, flex: 1, minWidth: 220, paddingHorizontal: o.space[4], flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: o.space[3], borderWidth: 1, borderColor: o.color.borderStrong, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  secondarySymbol: { width: 30, height: 30, borderWidth: 2, borderColor: o.color.text, borderRadius: 15, color: o.color.text, fontSize: 20, lineHeight: 26, fontWeight: '900', textAlign: 'center' },
  secondaryText: { color: o.color.text, fontSize: o.type.body, fontWeight: '900' },
  releaseButton: { minHeight: o.touch.prominent, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: o.color.critical, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  releaseText: { color: o.color.critical, fontSize: o.type.small, fontWeight: '900' },
  sequencePanel: { overflow: 'hidden', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  panelHeader: { minHeight: 52, paddingHorizontal: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[3], backgroundColor: o.color.navy },
  panelTitle: { color: o.color.onNavy, fontSize: o.type.body, fontWeight: '900' },
  panelMeta: { color: o.color.textSecondary, fontSize: o.type.small },
  sequenceRow: { flexDirection: 'row' },
  sequenceItem: { width: 260, minHeight: 150, padding: o.space[3], gap: o.space[2], borderRightWidth: 1, borderRightColor: o.color.border, backgroundColor: o.color.surfaceMuted },
  sequenceItemCurrent: { borderTopWidth: 5, borderTopColor: o.color.primary, backgroundColor: o.color.selected },
  sequenceItemComplete: { borderTopWidth: 5, borderTopColor: o.color.success, backgroundColor: o.color.successSurface },
  sequenceTopline: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[2] },
  sequenceNumber: { width: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center', backgroundColor: o.color.disabled },
  sequenceNumberCurrent: { backgroundColor: o.color.primary },
  sequenceNumberComplete: { backgroundColor: o.color.success },
  sequenceNumberText: { color: o.color.onPrimary, fontSize: o.type.small, fontWeight: '900' },
  sequenceState: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900' },
  sequenceFolio: { color: o.color.text, fontSize: o.type.body, fontWeight: '900' },
  sequenceRoute: { color: o.color.muted, fontSize: o.type.small, lineHeight: 19 },
  sequenceAction: { color: o.color.primaryPressed, fontSize: o.type.small, fontWeight: '900' },
  custodyPanel: { overflow: 'hidden', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  custodyRow: { minHeight: 66, padding: o.space[3], flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: o.space[3], borderBottomWidth: 1, borderBottomColor: o.color.border },
  custodyState: { paddingHorizontal: o.space[2], paddingVertical: o.space[1], borderWidth: 1, borderColor: o.color.warning, borderRadius: o.radius.control, backgroundColor: o.color.warningSurface },
  custodyStateText: { color: o.color.warning, fontSize: o.type.caption, fontWeight: '900' },
  custodyOrigin: { flex: 1, minWidth: 220, color: o.color.muted, fontSize: o.type.small },
  custodyEmpty: { minHeight: 92, padding: o.space[4], alignItems: 'center', justifyContent: 'center' },
  custodyEmptyText: { color: o.color.muted, fontSize: o.type.small, textAlign: 'center' },
  footerActions: { minHeight: 70, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: o.space[3] },
  footerBack: { minHeight: o.touch.minimum, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderRadius: o.radius.control, backgroundColor: o.color.navyRaised },
  footerBackText: { color: o.color.onNavy, fontSize: o.type.small, fontWeight: '900' },
});
