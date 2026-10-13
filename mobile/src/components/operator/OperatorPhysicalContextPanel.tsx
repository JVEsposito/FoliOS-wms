import { ScrollView, StyleSheet, Text, View } from 'react-native';

import type { OperationalTask } from '../../domain/operationalTasks';
import {
  buildOperatorPalletFacts,
  buildOperatorPhysicalContext,
  type OperatorPhysicalItem,
} from '../../domain/operatorPhysicalContext';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { OperatorEntityCode, OperatorStatusBadge } from './OperatorPrimitives';

type Props = {
  task: OperationalTask;
};

export function OperatorPhysicalContextPanel({ task }: Props) {
  const facts = buildOperatorPalletFacts(task);
  const physical = buildOperatorPhysicalContext(task);
  const locationLabel = physical.camera
    ? `${physical.camera}${physical.band === null ? '' : ` · Banda ${String(physical.band).padStart(2, '0')}`}`
    : 'Sin ubicación física publicada';

  return (
    <View style={styles.shell}>
      <View style={styles.header}>
        <View>
          <Text style={styles.eyebrow}>CONTEXTO FÍSICO REAL</Text>
          <Text style={styles.title}>Qué pallet muevo y qué debo despejar</Text>
        </View>
        <Text style={styles.location}>{locationLabel}</Text>
      </View>

      <View style={styles.body}>
        <View style={styles.factsPanel}>
          <Text style={styles.sectionEyebrow}>DATOS PUBLICADOS DEL PALLET</Text>
          <OperatorEntityCode prominent value={task.folio.numero_folio} />
          {facts.length ? (
            <View style={styles.factGrid}>
              {facts.map((fact) => (
                <View key={fact.key} style={styles.factCell}>
                  <Text style={styles.factLabel}>{fact.label}</Text>
                  <Text style={styles.factValue}>{fact.value}</Text>
                </View>
              ))}
            </View>
          ) : (
            <View style={styles.factEmpty}>
              <Text style={styles.factEmptyTitle}>Sin atributos complementarios publicados</Text>
              <Text style={styles.factEmptyCopy}>
                La pantalla no inventa producto, variedad, lote ni cliente cuando la tarea no los entrega.
              </Text>
            </View>
          )}

          <View style={styles.positionGrid}>
            <Metric label="Banda" value={physical.band === null ? '—' : String(physical.band).padStart(2, '0')} />
            <Metric label="Posición" value={physical.position === null ? '—' : String(physical.position).padStart(2, '0')} />
            <Metric label="Nivel" value={physical.level === null ? '—' : String(physical.level)} />
            {physical.resultingDepth !== null ? (
              <Metric label="Profundidad resultante" value={String(physical.resultingDepth)} />
            ) : null}
          </View>
        </View>

        <View style={styles.maneuverPanel}>
          <View style={styles.maneuverHeading}>
            <View>
              <Text style={styles.sectionEyebrow}>LECTURA DE LA MANIOBRA</Text>
              <Text style={styles.maneuverTitle}>Acceso físico y bloqueadores</Text>
            </View>
            <Text style={styles.orientation}>↑ FONDO · ENTRADA ↓</Text>
          </View>

          {physical.blockers.length ? (
            <>
              <Text style={styles.blockerLead}>
                {physical.blockers.length === 1
                  ? 'Hay 1 pallet que debe liberar el acceso antes del objetivo.'
                  : `Hay ${physical.blockers.length} pallets que deben liberar el acceso antes del objetivo.`}
              </Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                <View style={styles.itemRow}>
                  {physical.blockers.map((item) => (
                    <PhysicalItemCard item={item} key={item.id} kind="blocker" />
                  ))}
                </View>
              </ScrollView>
            </>
          ) : (
            <View style={styles.directAccess}>
              <OperatorStatusBadge label="ACCESO DIRECTO" tone="success" />
              <Text style={styles.directAccessCopy}>
                La maniobra no publica extracciones temporales previas para llegar al pallet objetivo.
              </Text>
            </View>
          )}

          {physical.target ? (
            <View style={styles.targetSection}>
              <Text style={styles.targetLabel}>PALLET OBJETIVO DE LA MANIOBRA</Text>
              <PhysicalItemCard item={physical.target} kind="target" />
            </View>
          ) : null}

          {physical.returns.length ? (
            <View style={styles.returnSection}>
              <Text style={styles.returnTitle}>Retornos previstos después del movimiento objetivo</Text>
              {physical.returns.map((item) => (
                <View key={item.id} style={styles.returnRow}>
                  <OperatorEntityCode value={item.folio} />
                  <Text style={styles.returnCopy}>{item.destination}</Text>
                </View>
              ))}
            </View>
          ) : null}
        </View>
      </View>
    </View>
  );
}

function PhysicalItemCard({ item, kind }: { item: OperatorPhysicalItem; kind: 'blocker' | 'target' }) {
  const state = item.state === 'complete'
    ? { label: 'COMPLETADO', tone: 'success' as const }
    : item.state === 'current'
      ? { label: 'EN CURSO', tone: 'warning' as const }
      : { label: 'PENDIENTE', tone: 'info' as const };

  return (
    <View style={[styles.itemCard, kind === 'target' && styles.targetCard]}>
      <View style={styles.itemTopline}>
        <Text style={styles.itemStep}>PASO {item.number}</Text>
        <OperatorStatusBadge label={state.label} tone={state.tone} />
      </View>
      <Text style={styles.itemKind}>{kind === 'blocker' ? 'BLOQUEADOR' : 'OBJETIVO'}</Text>
      <OperatorEntityCode value={item.folio} />
      <Text style={styles.itemRouteLabel}>Origen</Text>
      <Text style={styles.itemRoute}>{item.origin}</Text>
      <Text style={styles.itemRouteLabel}>Destino</Text>
      <Text style={styles.itemRouteStrong}>{item.destination}</Text>
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

const styles = StyleSheet.create({
  shell: { overflow: 'hidden', borderWidth: 1, borderColor: o.color.borderStrong, borderRadius: o.radius.panel, backgroundColor: o.color.surface },
  header: { minHeight: 68, paddingHorizontal: o.space[4], paddingVertical: o.space[3], flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: o.space[3], backgroundColor: o.color.navy },
  eyebrow: { color: o.color.textSecondary, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 1 },
  title: { color: o.color.onNavy, fontSize: o.type.heading, fontWeight: '900', marginTop: o.space[1] },
  location: { color: o.color.textSecondary, fontSize: o.type.small, fontWeight: '800' },
  body: { flexDirection: 'row', flexWrap: 'wrap' },
  factsPanel: { flex: 0.9, minWidth: 280, padding: o.space[4], gap: o.space[3], borderRightWidth: 1, borderRightColor: o.color.border, backgroundColor: o.color.surface },
  sectionEyebrow: { color: o.color.primaryPressed, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 0.8 },
  factGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[2] },
  factCell: { minWidth: 126, flexGrow: 1, flexBasis: 126, padding: o.space[2], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.control, backgroundColor: o.color.surfaceMuted },
  factLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800' },
  factValue: { color: o.color.text, fontSize: o.type.small, fontWeight: '900', marginTop: o.space[1] },
  factEmpty: { padding: o.space[3], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.control, backgroundColor: o.color.surfaceMuted },
  factEmptyTitle: { color: o.color.text, fontSize: o.type.small, fontWeight: '900' },
  factEmptyCopy: { color: o.color.muted, fontSize: o.type.caption, lineHeight: 18, marginTop: o.space[1] },
  positionGrid: { flexDirection: 'row', flexWrap: 'wrap', borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.control, backgroundColor: o.color.surfaceMuted },
  metric: { minWidth: 92, flex: 1, padding: o.space[2], borderRightWidth: 1, borderRightColor: o.color.border },
  metricLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800' },
  metricValue: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', marginTop: o.space[1] },
  maneuverPanel: { flex: 1.6, minWidth: 360, padding: o.space[4], gap: o.space[3], backgroundColor: o.color.surfaceMuted },
  maneuverHeading: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: o.space[3] },
  maneuverTitle: { color: o.color.text, fontSize: o.type.body, fontWeight: '900', marginTop: o.space[1] },
  orientation: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900' },
  blockerLead: { color: o.color.text, fontSize: o.type.small, lineHeight: 20, fontWeight: '700' },
  itemRow: { flexDirection: 'row', gap: o.space[3], paddingBottom: o.space[1] },
  itemCard: { width: 260, minHeight: 214, padding: o.space[3], gap: o.space[2], borderWidth: 2, borderColor: o.color.warning, borderRadius: o.radius.control, backgroundColor: o.color.warningSurface },
  targetCard: { width: '100%', minHeight: 0, borderColor: o.color.primary, backgroundColor: o.color.selected },
  itemTopline: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: o.space[2] },
  itemStep: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '900' },
  itemKind: { color: o.color.text, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 0.7 },
  itemRouteLabel: { color: o.color.muted, fontSize: o.type.caption, fontWeight: '800', marginTop: o.space[1] },
  itemRoute: { color: o.color.text, fontSize: o.type.small, lineHeight: 19 },
  itemRouteStrong: { color: o.color.primaryPressed, fontSize: o.type.small, lineHeight: 19, fontWeight: '900' },
  directAccess: { minHeight: 82, padding: o.space[3], flexDirection: 'row', alignItems: 'center', gap: o.space[3], borderWidth: 1, borderColor: o.color.success, borderRadius: o.radius.control, backgroundColor: o.color.successSurface },
  directAccessCopy: { flex: 1, color: o.color.text, fontSize: o.type.small, lineHeight: 20 },
  targetSection: { gap: o.space[2] },
  targetLabel: { color: o.color.primaryPressed, fontSize: o.type.caption, fontWeight: '900', letterSpacing: 0.8 },
  returnSection: { padding: o.space[3], gap: o.space[2], borderWidth: 1, borderColor: o.color.border, borderRadius: o.radius.control, backgroundColor: o.color.surface },
  returnTitle: { color: o.color.text, fontSize: o.type.small, fontWeight: '900' },
  returnRow: { minHeight: 42, flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: o.space[3], borderTopWidth: 1, borderTopColor: o.color.border, paddingTop: o.space[2] },
  returnCopy: { flex: 1, minWidth: 180, color: o.color.muted, fontSize: o.type.small },
});
