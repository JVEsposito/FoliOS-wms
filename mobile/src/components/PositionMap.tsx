import { useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { bandNumberingLabel, cameraDisplayName, orderBandsForCamera } from '../domain/cameras';
import { CameraPlan, OperationalBand, Position } from '../domain/estiba';
import { colors } from '../theme/colors';
import { operatorTheme as o } from '../theme/operatorTheme';
import { font } from '../theme/typography';

type PositionMapProps = {
  plan: CameraPlan;
  selectedPositionId: string | null;
  onSelectPosition: (position: Position) => void;
  highlightedFolioIds?: string[];
  suggestedFolioId?: string | null;
};

const CELL_HEIGHT = 60;
const CELL_GAP = 6;
const BAND_WIDTH = 128;
const BAND_HEADING_HEIGHT = 80;
const ALL_USES = 3;

/**
 * Plano de estiba de terreno. Cada dato aparece una vez: la banda en su cabecera, la
 * posición en la columna izquierda y el nivel en el selector; la celda solo dice qué hay.
 * Una celda libre va vacía. Ver docs/diseno/folios-v2/pr-13-tablet-revision.md.
 */
export function PositionMap({
  highlightedFolioIds = [],
  onSelectPosition,
  plan,
  selectedPositionId,
  suggestedFolioId = null,
}: PositionMapProps) {
  const levels = [...new Set(plan.posiciones.map((position) => position.nivel))].sort((a, b) => a - b);
  const bands = orderBandsForCamera(
    plan,
    [...new Set(plan.posiciones.map((position) => position.banda))],
  );
  const maxPosition = Math.max(1, ...plan.posiciones.map((position) => position.posicion));
  const positionNumbers = Array.from({ length: maxPosition }, (_, index) => index + 1);
  const [selectedLevel, setSelectedLevel] = useState(levels[0] ?? 1);
  const followedPosition = useRef<string | null>(null);

  useEffect(() => {
    if (!levels.includes(selectedLevel)) setSelectedLevel(levels[0] ?? 1);
  }, [levels, selectedLevel]);

  useEffect(() => {
    if (followedPosition.current === selectedPositionId) return;
    const selected = plan.posiciones.find((position) => position.id === selectedPositionId);
    followedPosition.current = selectedPositionId;
    if (selected && selected.nivel !== selectedLevel) setSelectedLevel(selected.nivel);
  }, [plan, selectedLevel, selectedPositionId]);

  const contentLabel = plan.contenido === 'materiales' ? 'Material' : plan.contenido === 'materia_prima' ? 'Lote MP' : 'Pallet';

  return (
    <View style={styles.panel}>
      <View style={styles.heading}>
        <View style={styles.headingCopy}>
          <Text style={styles.eyebrow}>PLANO DE ESTIBA · {plan.tipo.toUpperCase()}</Text>
          <View style={styles.titleRow}>
            <Text accessibilityRole="header" numberOfLines={1} style={styles.title}>{cameraDisplayName(plan)}</Text>
            <Text style={styles.version}>v{plan.version_plano}</Text>
          </View>
          <Text style={styles.subtitle}>
            {plan.posiciones.length} posiciones · Bandas verticales · {bandNumberingLabel(plan)} · P01 se ocupa primero
          </Text>
        </View>
        <View accessible accessibilityLabel={`Ocupación ${plan.ocupacion.porcentaje}%, ${plan.ocupacion.ocupadas} de ${plan.ocupacion.total}`} style={styles.occupancy}>
          <Text style={styles.occupancyValue}>{String(plan.ocupacion.porcentaje).replace('.', ',')}%</Text>
          <Text style={styles.occupancyDetail}>{plan.ocupacion.ocupadas} de {plan.ocupacion.total} ocupadas</Text>
        </View>
      </View>

      {plan.acceso.modo === 'solo_lectura' && (
        <View style={styles.lockBanner}>
          <View style={styles.lockDot} />
          <View style={styles.lockCopy}>
            <Text style={styles.lockTitle}>Cámara en modificación</Text>
            <Text style={styles.lockText}>
              {plan.acceso.sesion?.usuario.nombre ?? 'Otro operador'} trabaja desde{' '}
              {plan.acceso.sesion?.dispositivo.nombre ?? 'otra tablet'}. El plano está en consulta.
            </Text>
          </View>
        </View>
      )}

      <View style={styles.toolbar}>
        <View style={styles.legend}>
          <Legend color="transparent" border={colors.freeBorder} label="Libre" />
          <Legend color={colors.pallet} border={colors.palletBorder} label={contentLabel} />
          {plan.contenido === 'productos' && <Legend color={colors.saldo} border={colors.saldoBorder} label="Saldo" />}
          {plan.contenido === 'productos'
            && plan.posiciones.some((position) => position.folio?.carga_actual)
            && <Legend color={colors.cyanDark} border={colors.cyan} label="Asignado a carga" />}
          {plan.posiciones.some((position) => position.reservada)
            && <Legend color={colors.amberDark} border={colors.amber} label="Reservada" />}
          <Legend color={colors.blocked} border={colors.blockedBorder} label="Bloqueada" />
        </View>
        {levels.length > 1 ? (
          <View accessibilityRole="tablist" style={styles.levelPicker}>
            {levels.map((level) => (
              <Pressable
                accessibilityRole="tab"
                accessibilityState={{ selected: selectedLevel === level }}
                key={level}
                onPress={() => setSelectedLevel(level)}
                style={[styles.levelButton, selectedLevel === level && styles.levelButtonActive]}
              >
                <Text style={[styles.levelText, selectedLevel === level && styles.levelTextActive]}>Nivel {level}</Text>
              </Pressable>
            ))}
          </View>
        ) : (
          <Text style={styles.singleLevel}>Nivel {selectedLevel}</Text>
        )}
      </View>

      <Text style={styles.orientation}>↑ Fondo</Text>
      <View style={styles.grid}>
        <View style={styles.gutter}>
          <View style={styles.gutterHeading} />
          {positionNumbers.map((number) => (
            <View key={number} style={styles.gutterCell}>
              <Text style={styles.gutterText}>P{String(number).padStart(2, '0')}</Text>
            </View>
          ))}
        </View>
        <ScrollView horizontal showsHorizontalScrollIndicator style={styles.bandsScroll}>
          <View style={styles.bandRow}>
            {bands.map((band) => (
              <Band
                band={band}
                key={band}
                level={selectedLevel}
                onSelectPosition={onSelectPosition}
                plan={plan}
                positionNumbers={positionNumbers}
                selectedPositionId={selectedPositionId}
                highlightedFolioIds={highlightedFolioIds}
                suggestedFolioId={suggestedFolioId}
              />
            ))}
          </View>
        </ScrollView>
      </View>
      <Text style={styles.orientation}>↓ Entrada</Text>
    </View>
  );
}

type BandProps = PositionMapProps & {
  band: number;
  level: number;
  positionNumbers: number[];
};

function Band({
  band,
  highlightedFolioIds = [],
  level,
  onSelectPosition,
  plan,
  positionNumbers,
  selectedPositionId,
  suggestedFolioId = null,
}: BandProps) {
  const operationalBand = plan.bandas_operacionales?.find((candidate) => candidate.numero === band);
  const affinity = operationalBand ? operationalBandAffinityLabel(operationalBand) : null;
  const restrictedUses = operationalBand && operationalBand.usos_permitidos.length < ALL_USES
    ? operationalBand.usos_permitidos.map(operationalUseLabel).join(' · ')
    : null;
  const notable = operationalBand && ['bloqueada', 'en_vaciado', 'completa'].includes(operationalBand.estado);

  return (
    <View style={[
      styles.band,
      operationalBand?.estado === 'bloqueada' && styles.bandBlocked,
      operationalBand?.estado === 'en_vaciado' && styles.bandEmptying,
    ]}>
      <View style={styles.bandHeading}>
        <Text style={styles.bandTitle}>B{String(band).padStart(2, '0')}</Text>
        {operationalBand ? (
          <>
            <Text numberOfLines={1} style={[styles.bandState, notable && styles.bandStateNotable]}>
              {notable ? `${BAND_STATE[operationalBand.estado]} · ` : ''}{operationalBand.capacidad.disponibles}/{operationalBand.capacidad.efectiva} libres
            </Text>
            {affinity ? <Text numberOfLines={1} style={styles.bandAffinity}>{affinity}</Text> : null}
            {restrictedUses ? <Text numberOfLines={1} style={styles.bandUses}>{restrictedUses}</Text> : null}
          </>
        ) : null}
      </View>
      {positionNumbers.map((number) => {
        const position = plan.posiciones.find((candidate) => (
          candidate.nivel === level
          && candidate.banda === band
          && candidate.posicion === number
        ));

        if (!position) return <View key={number} style={styles.gap} />;

        return (
          <Cell
            highlighted={Boolean(position.folio && highlightedFolioIds?.includes(position.folio.id))}
            key={position.id}
            onPress={() => onSelectPosition(position)}
            position={position}
            selected={position.id === selectedPositionId}
            suggested={Boolean(suggestedFolioId) && position.folio?.id === suggestedFolioId}
          />
        );
      })}
    </View>
  );
}

function Cell({ highlighted, onPress, position, selected, suggested }: {
  highlighted: boolean;
  onPress: () => void;
  position: Position;
  selected: boolean;
  suggested: boolean;
}) {
  const blocked = position.estado !== 'activa';
  const occupied = position.ocupada;
  const reserved = position.reservada;
  const saldo = position.folio?.tipo_bulto === 'saldo';
  const unclosed = position.folio?.registro_sin_cerrar ?? null;
  const items = position.folios?.length ?? 0;
  const folio = position.folio?.numero_folio ?? null;
  const load = position.folio?.carga_actual && !unclosed ? position.folio.carga_actual.codigo : null;

  const detail = unclosed
    ? `Sin cerrar · ${unclosed.temporada.codigo ?? 'otra temporada'}`
    : position.folio?.material
      ? `${position.folio.material.item.codigo} · ${position.folio.material.cantidad_actual} ${position.folio.material.unidad_medida}`
      : position.folio?.variedad
        ?? (blocked
          ? 'No disponible'
          : reserved
            ? position.reserva_operacional?.responsable?.nombre ?? 'Tarea en ejecución'
            : null);

  return (
    <Pressable
      accessibilityLabel={`${position.etiqueta}, ${folio ?? (blocked ? 'bloqueada' : reserved ? 'reservada' : 'libre')}${unclosed ? `, registro sin cerrar de ${unclosed.temporada.codigo ?? 'otra temporada'}` : ''}`}
      accessibilityRole="button"
      onPress={onPress}
      style={({ pressed }) => [
        styles.cell,
        occupied && styles.occupied,
        reserved && styles.reserved,
        saldo && styles.saldo,
        blocked && styles.blocked,
        highlighted && styles.loadFolio,
        suggested && styles.suggested,
        unclosed && styles.unclosed,
        selected && styles.selected,
        pressed && styles.pressed,
      ]}
    >
      {items > 1 ? (
        <Text numberOfLines={1} style={styles.cellMain}>{items} ítems</Text>
      ) : folio ? (
        <FolioShort value={folio} />
      ) : reserved ? (
        <Text numberOfLines={1} style={styles.cellState}>Reservada</Text>
      ) : blocked ? (
        <Text numberOfLines={1} style={styles.cellStateBlocked}>Bloqueada</Text>
      ) : null}
      {load ? (
        // Durante la carga importa más a qué CAR va el pallet que su variedad.
        <Text numberOfLines={1} style={styles.loadCode}>{load}</Text>
      ) : detail ? (
        <Text numberOfLines={1} style={[styles.cellDetail, unclosed && styles.cellDetailWarning]}>{detail}</Text>
      ) : null}
      {saldo ? <Text accessibilityLabel="Saldo" style={styles.saldoMark}>S</Text> : null}
    </Pressable>
  );
}

function FolioShort({ value }: { value: string }) {
  const split = value.length > 6 ? value.length - 4 : 0;
  return (
    <Text numberOfLines={1} style={styles.cellFolio}>
      {split ? <Text style={styles.cellFolioHead}>{value.slice(0, split)}</Text> : null}
      {value.slice(split)}
    </Text>
  );
}

const BAND_STATE: Record<OperationalBand['estado'], string> = {
  libre: 'Libre',
  parcial: 'Parcial',
  completa: 'Completa',
  bloqueada: 'Bloqueada',
  en_vaciado: 'En vaciado',
};

function operationalUseLabel(use: OperationalBand['usos_permitidos'][number]) {
  return {
    transito_pt: 'Tránsito PT',
    inspeccion: 'Inspección',
    retenidos: 'Retenidos',
  }[use];
}

/** Solo se muestra cuando la banda tiene afinidad o bultos fuera del planificador. */
function operationalBandAffinityLabel(band: OperationalBand) {
  const affinity = band.afinidad;

  if (!affinity?.activa) {
    return affinity?.fuera_alcance ? `${affinity.fuera_alcance} fuera del planificador` : null;
  }

  const profile = [affinity.cliente?.valor, affinity.marca?.valor, affinity.formato?.valor]
    .filter(Boolean)
    .join(' › ') || 'Afinidad sin datos completos';

  return affinity.perfiles_diferentes > 1
    ? `${profile} · MIX ${affinity.perfiles_diferentes}`
    : profile;
}

function Legend({ color, label, border }: { color: string; label: string; border?: string }) {
  return (
    <View style={styles.legendItem}>
      <View style={[styles.swatch, { backgroundColor: color, borderColor: border ?? colors.border }]} />
      <Text style={styles.legendText}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    flex: 1,
    minWidth: 0,
    padding: o.space[4],
    gap: o.space[3],
    borderRadius: o.radius.panel,
    borderWidth: 1,
    borderColor: o.color.border,
    backgroundColor: o.color.surface,
  },
  heading: { flexDirection: 'row', justifyContent: 'space-between', gap: o.space[4] },
  headingCopy: { flex: 1, minWidth: 0, gap: 2 },
  eyebrow: { fontFamily: font.heavy, color: o.color.muted, fontSize: o.type.caption, letterSpacing: 1.2 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: o.space[2] },
  title: { fontFamily: font.heavy, flexShrink: 1, color: o.color.text, fontSize: o.type.heading },
  version: {
    fontFamily: font.monoBold,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 6,
    backgroundColor: o.color.surfaceMuted,
    color: o.color.muted,
    fontSize: o.type.caption,
  },
  subtitle: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.caption },
  occupancy: { alignItems: 'flex-end', justifyContent: 'center' },
  occupancyValue: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.title },
  occupancyDetail: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.caption },
  lockBanner: {
    padding: o.space[3],
    borderRadius: o.radius.control,
    borderWidth: 1,
    borderColor: o.color.warningBorder,
    backgroundColor: o.color.warningSurface,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[3],
  },
  lockDot: { width: 12, height: 12, borderRadius: o.radius.pill, backgroundColor: o.color.warning },
  lockCopy: { flex: 1 },
  lockTitle: { fontFamily: font.bold, color: o.color.warning, fontSize: o.type.body },
  lockText: { fontFamily: font.regular, marginTop: 2, color: o.color.textSecondary, fontSize: o.type.small },
  toolbar: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: o.space[3] },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: o.space[4] },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  swatch: { width: 16, height: 16, borderRadius: 4, borderWidth: 1 },
  legendText: { fontFamily: font.medium, color: o.color.textSecondary, fontSize: o.type.caption },
  levelPicker: { flexDirection: 'row', gap: 4, padding: 4, borderRadius: o.radius.control, borderWidth: 1, borderColor: o.color.border },
  levelButton: { minHeight: o.touch.minimum, paddingHorizontal: o.space[4], alignItems: 'center', justifyContent: 'center', borderRadius: 8 },
  levelButtonActive: { backgroundColor: o.color.surfaceMuted },
  levelText: { fontFamily: font.semibold, color: o.color.textSecondary, fontSize: o.type.small },
  levelTextActive: { fontFamily: font.heavy, color: o.color.primaryPressed },
  singleLevel: { fontFamily: font.semibold, color: o.color.muted, fontSize: o.type.small },
  orientation: { fontFamily: font.bold, color: o.color.muted, fontSize: o.type.caption, letterSpacing: 0.6 },
  grid: { flexDirection: 'row' },
  gutter: { width: 44, gap: CELL_GAP },
  gutterHeading: { height: BAND_HEADING_HEIGHT },
  gutterCell: { height: CELL_HEIGHT, justifyContent: 'center' },
  gutterText: { fontFamily: font.monoBold, color: o.color.muted, fontSize: o.type.caption },
  bandsScroll: { flex: 1 },
  bandRow: { flexDirection: 'row', gap: 8, paddingBottom: 4 },
  band: { width: BAND_WIDTH, gap: CELL_GAP },
  bandBlocked: { opacity: 0.85 },
  bandEmptying: {},
  bandHeading: {
    height: BAND_HEADING_HEIGHT,
    paddingHorizontal: o.space[2],
    paddingVertical: o.space[2],
    borderRadius: o.radius.control,
    backgroundColor: o.color.surfaceMuted,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
  },
  bandTitle: { fontFamily: font.heavy, color: o.color.text, fontSize: 18 },
  bandState: { fontFamily: font.medium, color: o.color.textSecondary, fontSize: 12 },
  bandStateNotable: { fontFamily: font.bold, color: o.color.warning },
  bandAffinity: { fontFamily: font.semibold, maxWidth: BAND_WIDTH - 12, color: o.color.primaryPressed, fontSize: 12 },
  bandUses: { fontFamily: font.regular, maxWidth: BAND_WIDTH - 12, color: o.color.muted, fontSize: 12 },
  gap: { height: CELL_HEIGHT },
  cell: {
    height: CELL_HEIGHT,
    paddingHorizontal: 8,
    paddingVertical: 6,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.freeBorder,
    backgroundColor: 'transparent',
    justifyContent: 'center',
    gap: 2,
  },
  occupied: { borderColor: colors.palletBorder, backgroundColor: colors.pallet },
  reserved: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  saldo: { borderColor: colors.saldoBorder, backgroundColor: colors.saldo },
  blocked: { borderColor: colors.blockedBorder, backgroundColor: colors.blocked, opacity: 0.85 },
  loadFolio: { borderWidth: 2, borderColor: colors.amber },
  suggested: { borderWidth: 3, borderColor: colors.green },
  unclosed: { borderStyle: 'dashed', borderWidth: 2, borderColor: colors.amber, opacity: 0.8 },
  selected: { borderWidth: 3, borderColor: colors.cyan },
  pressed: { opacity: 0.72 },
  cellFolio: { fontFamily: font.monoBold, color: colors.text, fontSize: 15 },
  cellFolioHead: { fontFamily: font.mono, color: colors.textSecondary, fontSize: 12 },
  cellMain: { fontFamily: font.bold, color: colors.text, fontSize: 14 },
  cellState: { fontFamily: font.bold, color: colors.amber, fontSize: 13 },
  cellStateBlocked: { fontFamily: font.bold, color: colors.text, fontSize: 13 },
  cellDetail: { fontFamily: font.regular, color: colors.textSecondary, fontSize: 12 },
  cellDetailWarning: { fontFamily: font.bold, color: colors.amber },
  loadCode: {
    fontFamily: font.monoBold,
    alignSelf: 'flex-start',
    maxWidth: BAND_WIDTH - 18,
    paddingHorizontal: 5,
    borderRadius: 4,
    backgroundColor: colors.cyanDark,
    color: colors.cyan,
    fontSize: 12,
  },
  saldoMark: { fontFamily: font.heavy, position: 'absolute', top: 4, right: 6, color: colors.text, fontSize: 12 },
});
