import { Pressable, StyleSheet, Text, View } from 'react-native';

import { CameraPlan, Position } from '../domain/estiba';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';

type ActionPanelProps = {
  busy: boolean;
  canOperate: boolean;
  canOpenSession: boolean;
  canDispatchMaterial: boolean;
  compact?: boolean;
  plan: CameraPlan;
  selectedPosition: Position | null;
  onLocate: () => void;
  onAssignCamera: () => void;
  onMove: () => void;
  onSelectFolio: (folioId: string) => void;
  onDispatchMaterial: () => void;
  onRefresh: () => void;
  onToggleSession: () => void;
};

export function ActionPanel({
  busy,
  canOperate,
  canOpenSession,
  canDispatchMaterial,
  compact = false,
  onLocate,
  onAssignCamera,
  onMove,
  onSelectFolio,
  onDispatchMaterial,
  onRefresh,
  onToggleSession,
  plan,
  selectedPosition,
}: ActionPanelProps) {
  const editing = plan.acceso.modo === 'edicion';
  const readOnly = plan.acceso.modo === 'solo_lectura';
  const selectedSaldo = selectedPosition?.folio?.tipo_bulto === 'saldo';
  const selectedMaterial = selectedPosition?.folio?.tipo_bulto === 'material';
  const locateDisabled = busy || !canOperate || !selectedPosition || selectedPosition.reservada || (selectedPosition.ocupada && plan.contenido !== 'materiales') || selectedPosition.estado !== 'activa';
  const moveDisabled = busy || !canOperate || !selectedPosition?.ocupada || selectedPosition.estado !== 'activa';

  return (
    <View style={[styles.panel, compact && styles.panelCompact]}>
      <View>
        <Text style={styles.eyebrow}>OPERACIÓN</Text>
        <Text style={styles.title}>Acciones rápidas</Text>
      </View>

      <View style={styles.selection}>
        <Text style={styles.selectionLabel}>POSICIÓN SELECCIONADA</Text>
        <Text style={styles.selectionValue}>{selectedPosition?.etiqueta ?? 'Ninguna'}</Text>
        <Text style={styles.selectionDetail}>
          {!selectedPosition
            ? 'Toca una posición del plano'
            : selectedPosition.estado !== 'activa'
              ? 'Posición no disponible'
              : selectedPosition.ocupada
                ? `${selectedPosition.folios?.length || 1} ítem${(selectedPosition.folios?.length || 1) === 1 ? '' : 's'} en el bulto`
                : selectedPosition.reservada
                  ? `Reservada · ${selectedPosition.reserva_operacional?.responsable?.nombre ?? 'tarea en ejecución'}`
                  : 'Libre para ubicación'}
        </Text>
      </View>

      {(selectedPosition?.folios?.length ?? 0) > 1 && (
        <View style={styles.folioSelector}>
          <Text style={styles.selectionLabel}>LÍNEAS DEL BULTO · MISMO CLIENTE</Text>
          {(selectedPosition?.folios ?? []).map((folio) => (
            <Pressable
              key={folio.id}
              onPress={() => onSelectFolio(folio.id)}
              style={[styles.folioChoice, selectedPosition?.folio?.id === folio.id && styles.folioChoiceActive]}
            >
              <Text style={styles.folioChoiceCode}>{folio.material?.item.codigo ?? folio.numero_folio}</Text>
              <Text style={styles.folioChoiceClient}>{folio.material?.item.cliente.nombre ?? ''}</Text>
            </Pressable>
          ))}
        </View>
      )}

      {selectedPosition?.folio && (
        <View style={[styles.folioCard, selectedSaldo && styles.folioCardSaldo]}>
          <View style={styles.folioTop}>
            <Text style={[styles.folioType, selectedSaldo && styles.folioTypeSaldo]}>{selectedPosition.folio.tipo_bulto.toUpperCase()}</Text>
            <View style={[styles.folioDot, selectedSaldo && styles.folioDotSaldo]} />
          </View>
          <Text style={styles.folioNumber}>{selectedPosition.folio.numero_folio}</Text>
          {selectedMaterial ? (
            <>
              <Detail label="Cliente" value={selectedPosition.folio.material?.item.cliente ? `${selectedPosition.folio.material.item.cliente.codigo} · ${selectedPosition.folio.material.item.cliente.nombre}` : undefined} />
              <Detail label="Ítem" value={selectedPosition.folio.material ? `${selectedPosition.folio.material.item.codigo} · ${selectedPosition.folio.material.item.nombre}` : undefined} />
              <Detail label="Cantidad" value={`${selectedPosition.folio.material?.cantidad_actual ?? '0'} ${selectedPosition.folio.material?.unidad_medida ?? ''}`} />
              <Detail label="Reservado FIFO" value={`${selectedPosition.folio.material?.cantidad_reservada ?? '0'} ${selectedPosition.folio.material?.unidad_medida ?? ''}`} />
              <Detail label="Disponible libre" value={`${selectedPosition.folio.material?.cantidad_disponible ?? '0'} ${selectedPosition.folio.material?.unidad_medida ?? ''}`} />
            </>
          ) : (
            <>
              <Detail label="Variedad" value={selectedPosition.folio.variedad} />
              <Detail label="Calibre" value={selectedPosition.folio.calibre} />
              <Detail label="Condición" value={selectedPosition.folio.condicion_sag?.codigo} />
            </>
          )}
        </View>
      )}

      <View style={[styles.actions, compact && styles.actionsCompact]}>
        <ActionButton
          compact={compact}
          disabled={locateDisabled}
          icon="＋"
          label={plan.contenido === 'materiales' && selectedPosition?.ocupada ? 'Agregar ítem al bulto' : 'Ubicar folio'}
          onPress={onLocate}
          primary
          subtitle={plan.contenido === 'materiales' && selectedPosition?.ocupada ? 'Mismo cliente, nueva línea trazable' : 'Registrar un bulto nuevo'}
        />
        {plan.contenido === 'materiales' && (
          <ActionButton
            compact={compact}
            disabled={busy || !canOperate}
            icon="▣"
            label="Asignar solo a cámara"
            onPress={onAssignCamera}
            subtitle="Disponible sin ocupar posición"
          />
        )}
        {plan.contenido === 'materiales' && canDispatchMaterial && (
          <ActionButton
            compact={compact}
            disabled={busy || !canOperate || !selectedMaterial}
            icon="⇥"
            label="Despachar material"
            onPress={onDispatchMaterial}
            subtitle="Retirar una cantidad"
          />
        )}
        <ActionButton
          compact={compact}
          disabled={moveDisabled}
          icon="⇄"
          label="Mover folio"
          onPress={onMove}
          subtitle="Reubicar o cambiar cámara"
        />
        <ActionButton
          compact={compact}
          disabled={busy || readOnly || (!editing && !canOpenSession)}
          icon="⌁"
          label={editing ? 'Cerrar estiba' : readOnly ? 'Cámara en uso' : 'Abrir estiba'}
          onPress={onToggleSession}
          subtitle={editing ? 'Liberar la cámara' : readOnly ? 'Edición bloqueada' : canOpenSession ? 'Iniciar sesión de edición' : 'Perfil de solo consulta'}
        />
        <ActionButton
          compact={compact}
          disabled={busy}
          icon="↻"
          label="Actualizar plano"
          onPress={onRefresh}
          subtitle="Traer cambios del servidor"
        />
      </View>

      <Text style={styles.note}>
        {readOnly
          ? 'Puedes consultar el plano. La edición se habilitará cuando se cierre la otra sesión.'
          : editing
            ? selectedPosition ? 'Confirma cada movimiento antes de continuar.' : 'Sesión activa: selecciona una posición.'
            : 'Abre la estiba para habilitar movimientos.'}
      </Text>
    </View>
  );
}

function Detail({ label, value }: { label: string; value?: string | null }) {
  return (
    <View style={styles.detailRow}>
      <Text style={styles.detailLabel}>{label}</Text>
      <Text style={styles.detailValue}>{value || '—'}</Text>
    </View>
  );
}

type ActionButtonProps = {
  compact?: boolean;
  disabled: boolean;
  icon: string;
  label: string;
  onPress: () => void;
  primary?: boolean;
  subtitle: string;
};

function ActionButton({ compact, disabled, icon, label, onPress, primary, subtitle }: ActionButtonProps) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.actionButton,
        compact && styles.actionButtonCompact,
        primary && styles.actionPrimary,
        disabled && styles.actionDisabled,
        pressed && styles.actionPressed,
      ]}
    >
      <Text style={[styles.actionIcon, primary && styles.actionPrimaryText]}>{icon}</Text>
      <View style={styles.actionCopy}>
        <Text style={[styles.actionLabel, primary && styles.actionPrimaryText]}>{label}</Text>
        <Text style={[styles.actionSubtitle, primary && styles.actionPrimarySubtitle]}>{subtitle}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  panel: {
    width: 255,
    padding: 16,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panel,
    gap: 12,
  },
  panelCompact: { width: '100%' },
  eyebrow: { fontFamily: font.heavy, color: colors.cyan, fontSize: 13, letterSpacing: 1.2 },
  title: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 18 },
  selection: {
    padding: 12,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  selectionLabel: { fontFamily: font.heavy, color: colors.muted, fontSize: 12 },
  selectionValue: { fontFamily: font.heavy, marginTop: 5, color: colors.text, fontSize: 18 },
  selectionDetail: { fontFamily: font.regular, marginTop: 2, color: colors.muted, fontSize: 13 },
  folioSelector: { gap: 6 },
  folioChoice: {
    padding: 8,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.background,
  },
  folioChoiceActive: { borderColor: colors.cyan },
  folioChoiceCode: { fontFamily: font.heavy, color: colors.text, fontSize: 13 },
  folioChoiceClient: { fontFamily: font.regular, marginTop: 2, color: colors.muted, fontSize: 12 },
  folioCard: {
    padding: 12,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: colors.palletBorder,
    backgroundColor: colors.panelStrong,
  },
  folioCardSaldo: { borderColor: colors.saldoBorder },
  folioTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  folioType: { fontFamily: font.heavy, color: colors.palletBorder, fontSize: 12 },
  folioTypeSaldo: { color: colors.saldoBorder },
  folioDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.palletBorder },
  folioDotSaldo: { backgroundColor: colors.saldoBorder },
  folioNumber: { fontFamily: font.heavy, marginVertical: 8, color: colors.text, fontSize: 18 },
  detailRow: { marginTop: 3, flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  detailLabel: { fontFamily: font.regular, color: colors.muted, fontSize: 12 },
  detailValue: { fontFamily: font.heavy, color: colors.text, fontSize: 12 },
  actions: { gap: 8 },
  actionsCompact: { flexDirection: 'row', flexWrap: 'wrap' },
  actionButton: {
    minHeight: 60,
    paddingHorizontal: 11,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.panelStrong,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  actionButtonCompact: { minWidth: 220, flex: 1 },
  actionPrimary: { borderColor: colors.cyan, backgroundColor: colors.cyan },
  actionDisabled: { opacity: 0.38 },
  actionPressed: { opacity: 0.72 },
  actionIcon: { fontFamily: font.regular, width: 28, color: colors.cyan, fontSize: 25, textAlign: 'center' },
  actionCopy: { flex: 1 },
  actionLabel: { fontFamily: font.heavy, color: colors.text, fontSize: 14 },
  actionSubtitle: { fontFamily: font.regular, marginTop: 2, color: colors.muted, fontSize: 12 },
  actionPrimaryText: { color: colors.accentText },
  actionPrimarySubtitle: { color: '#5D4A28' },
  note: { fontFamily: font.regular, color: colors.muted, fontSize: 12, lineHeight: 16 },
});
