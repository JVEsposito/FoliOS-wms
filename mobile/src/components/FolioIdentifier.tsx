import { StyleSheet, Text, type StyleProp, type TextStyle } from 'react-native';

import { operatorTheme as o } from '../theme/operatorTheme';
import { font } from '../theme/typography';

export function FolioIdentifier({ value, style, digits = 4, label }: {
  value?: string | null;
  style?: StyleProp<TextStyle>;
  digits?: number;
  label?: string;
}) {
  const code = value || '—';
  const split = code.length > digits && /^\d+$/.test(code.slice(-digits)) ? code.length - digits : 0;
  return (
    <Text accessibilityLabel={label ? `${label} ${code}` : code} selectable numberOfLines={1}
      adjustsFontSizeToFit minimumFontScale={0.5} style={[styles.code, style]}>
      {split ? <Text style={styles.prefix}>{code.slice(0, split)}</Text> : null}
      {code.slice(split)}
    </Text>
  );
}

const styles = StyleSheet.create({
  code: { color: o.color.text, fontFamily: font.monoBold, fontVariant: ['tabular-nums'] },
  prefix: { color: o.color.textSecondary, fontFamily: font.mono },
});
