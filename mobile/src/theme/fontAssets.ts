import { font } from './typography';

// Archivos estáticos (no variables): Android no interpola ejes de una fuente variable.
export const fontAssets = {
  [font.regular]: require('@expo-google-fonts/inter/400Regular/Inter_400Regular.ttf'),
  [font.medium]: require('@expo-google-fonts/inter/500Medium/Inter_500Medium.ttf'),
  [font.semibold]: require('@expo-google-fonts/inter/600SemiBold/Inter_600SemiBold.ttf'),
  [font.bold]: require('@expo-google-fonts/inter/700Bold/Inter_700Bold.ttf'),
  [font.heavy]: require('@expo-google-fonts/inter/800ExtraBold/Inter_800ExtraBold.ttf'),
  [font.mono]: require('@expo-google-fonts/jetbrains-mono/500Medium/JetBrainsMono_500Medium.ttf'),
  [font.monoBold]: require('@expo-google-fonts/jetbrains-mono/700Bold/JetBrainsMono_700Bold.ttf'),
};
