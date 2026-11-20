import { Image, StyleSheet, View } from 'react-native';

import { areas, type AreaKey } from '../../theme/areas';

export function FieldMark({ area, decorative = false }: { area: AreaKey; decorative?: boolean }) {
  const definition = areas[area];
  return (
    <View style={[styles.stub, { borderColor: definition.line, backgroundColor: definition.tint }]}>
      <Image
        accessible={!decorative}
        accessibilityLabel={decorative ? undefined : `FoliOS · ${definition.label}`}
        source={definition.gajo}
        resizeMode="contain"
        style={styles.gajo}
      />
    </View>
  );
}

export function FieldCorner({ area }: { area: AreaKey }) {
  return <View pointerEvents="none" aria-hidden style={[styles.corner, { borderTopColor: areas[area].bright }]} />;
}

const styles = StyleSheet.create({
  stub: { padding: 6, borderWidth: 1, borderRadius: 6, borderTopRightRadius: 2 },
  gajo: { width: 24, height: 24 },
  corner: { position: 'absolute', right: 0, top: 0, borderLeftWidth: 13, borderTopWidth: 13, borderLeftColor: 'transparent' },
});
