import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';

import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

type Props = {
  deviceName: string;
  onChangeModule?: () => void;
  onLogout: () => void;
  role: string;
  userName: string;
};

/**
 * Menú del operador en la cabecera de terreno: concentra lo que no se usa en cada
 * maniobra (identidad, tablet, cambiar módulo y cerrar sesión) para que la cabecera
 * solo muestre navegación y estado. Ver docs/diseno/folios-v2/pr-11-tablet-cabecera.md.
 */
export function OperatorMenu({ deviceName, onChangeModule, onLogout, role, userName }: Props) {
  const [open, setOpen] = useState(false);

  function choose(action: () => void) {
    setOpen(false);
    action();
  }

  return (
    <>
      <Pressable
        accessibilityLabel={`Menú de ${userName}`}
        accessibilityRole="button"
        accessibilityState={{ expanded: open }}
        onPress={() => setOpen(true)}
        style={styles.trigger}
      >
        <View style={styles.initials}><Text style={styles.initialsText}>{initials(userName)}</Text></View>
        <Text style={styles.chevron}>▾</Text>
      </Pressable>

      {open ? (
        <Modal animationType="fade" onRequestClose={() => setOpen(false)} transparent visible>
          <Pressable accessibilityLabel="Cerrar menú" onPress={() => setOpen(false)} style={styles.backdrop}>
            <Pressable accessibilityRole="menu" onPress={() => undefined} style={styles.panel}>
              <View style={styles.identity}>
                <View style={styles.initialsLarge}><Text style={styles.initialsLargeText}>{initials(userName)}</Text></View>
                <View style={styles.identityText}>
                  <Text numberOfLines={1} style={styles.name}>{userName}</Text>
                  <Text numberOfLines={1} style={styles.meta}>{humanRole(role)} · {deviceName}</Text>
                </View>
              </View>
              {onChangeModule ? (
                <Pressable accessibilityRole="menuitem" onPress={() => choose(onChangeModule)} style={styles.item}>
                  <Text style={styles.itemText}>Cambiar módulo</Text>
                </Pressable>
              ) : null}
              <View style={styles.divider} />
              <Pressable accessibilityRole="menuitem" onPress={() => choose(onLogout)} style={styles.item}>
                <Text style={[styles.itemText, styles.logoutText]}>Cerrar sesión</Text>
              </Pressable>
            </Pressable>
          </Pressable>
        </Modal>
      ) : null}
    </>
  );
}

function initials(name: string) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const letters = parts.length > 1 ? parts[0][0] + parts[1][0] : (parts[0] ?? '?').slice(0, 2);
  return letters.toUpperCase();
}

function humanRole(role: string) {
  const normalized = role.trim().replaceAll('_', ' ');
  return normalized ? normalized.replace(/^./, (letter) => letter.toUpperCase()) : 'Camarero';
}

const styles = StyleSheet.create({
  trigger: {
    minHeight: o.touch.minimum,
    paddingLeft: 6,
    paddingRight: o.space[3],
    borderRadius: o.radius.pill,
    borderWidth: 1,
    borderColor: o.color.border,
    backgroundColor: o.color.surface,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[2],
  },
  initials: { width: 38, height: 38, borderRadius: o.radius.pill, backgroundColor: o.color.surfaceMuted, alignItems: 'center', justifyContent: 'center' },
  initialsText: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.small },
  chevron: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.body },
  backdrop: { flex: 1, backgroundColor: 'rgba(5,12,16,0.55)', alignItems: 'flex-end', paddingTop: 80, paddingHorizontal: o.space[4] },
  panel: {
    width: 380,
    maxWidth: '100%',
    padding: o.space[2],
    borderRadius: o.radius.panel,
    borderWidth: 1,
    borderColor: o.color.border,
    backgroundColor: o.color.surface,
    shadowColor: o.color.shadow,
    shadowOpacity: 0.35,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 8,
  },
  identity: { flexDirection: 'row', alignItems: 'center', gap: o.space[3], padding: o.space[4], borderBottomWidth: 1, borderBottomColor: o.color.border },
  initialsLarge: { width: 52, height: 52, borderRadius: o.radius.pill, backgroundColor: o.color.surfaceMuted, alignItems: 'center', justifyContent: 'center' },
  initialsLargeText: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.heading },
  identityText: { flex: 1, minWidth: 0 },
  name: { fontFamily: font.heavy, color: o.color.text, fontSize: o.type.body },
  meta: { fontFamily: font.regular, color: o.color.muted, fontSize: o.type.small, marginTop: 2 },
  item: { minHeight: o.touch.prominent, justifyContent: 'center', paddingHorizontal: o.space[4], borderRadius: o.radius.control },
  itemText: { fontFamily: font.semibold, color: o.color.text, fontSize: o.type.body },
  logoutText: { fontFamily: font.heavy, color: o.color.critical },
  divider: { height: 1, marginHorizontal: o.space[2], marginVertical: o.space[1], backgroundColor: o.color.border },
});
