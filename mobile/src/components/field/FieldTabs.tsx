import { Pressable, StyleSheet, Text, View } from 'react-native';
import { areas, type AreaKey } from '../../theme/areas';
import { operatorTheme as o } from '../../theme/operatorTheme';
import { font } from '../../theme/typography';

type FieldTab<Key extends string> = {
  key: Key;
  label: string;
  count?: number;
  disabled?: boolean;
};

export function FieldTabs<Key extends string>({ area, options, current, busy = false, onSelect }: {
  area: AreaKey;
  options: readonly FieldTab<Key>[];
  current: Key;
  busy?: boolean;
  onSelect: (key: Key) => void;
}) {
  const accent = areas[area].bright;
  return (
    <View accessibilityRole="tablist" style={styles.rail}>
      {options.map(({ key, label, count, disabled }) => {
        const selected = key === current;
        const inactive = disabled || busy;
        return (
          <Pressable
            key={key}
            accessibilityRole="tab"
            accessibilityLabel={count === undefined ? label : `${label}, ${count}`}
            accessibilityState={{ selected, disabled: inactive }}
            disabled={inactive}
            onPress={() => onSelect(key)}
            style={({ pressed }) => [
              styles.tab,
              selected && { borderBottomColor: accent },
              pressed && styles.pressed,
              inactive && styles.disabled,
            ]}
          >
            <Text style={[styles.label, selected && styles.selected]}>{label}</Text>
            {count !== undefined ? <Text style={[styles.count, selected && { color: accent }]}>{count}</Text> : null}
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  rail: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    marginHorizontal: 10,
    paddingHorizontal: 5,
    borderWidth: 1,
    borderColor: o.color.border,
    borderRadius: o.radius.control,
    backgroundColor: o.color.surface,
  },
  tab: {
    flex: 1,
    minWidth: 89,
    minHeight: 48,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    paddingHorizontal: 7,
    paddingVertical: 5,
    borderBottomWidth: 3,
    borderBottomColor: 'transparent',
  },
  label: { flexShrink: 1, color: o.color.muted, fontSize: 13, fontFamily: font.semibold, textAlign: 'center' },
  selected: { color: o.color.text, fontFamily: font.bold },
  count: { paddingHorizontal: 4, paddingVertical: 2, borderWidth: 1, borderColor: o.color.border, borderRadius: 4, color: o.color.textSecondary, fontSize: 11, fontFamily: font.monoBold },
  pressed: { backgroundColor: o.color.surfaceMuted },
  disabled: { opacity: 0.5 },
});
