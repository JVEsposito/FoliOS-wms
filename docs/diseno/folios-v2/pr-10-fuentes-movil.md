# PR 10 (opcional) · Archivo y Azeret Mono en la app

**Rama:** `diseno/pr10-fuentes-movil` · **Base:** PR 9 · **Entrega:** especificación
**Título del PR:** `feat(movil): fuentes Archivo y Azeret Mono en terreno`

## Antes de abrirlo: comprobar el módulo nativo

Con `runtimeVersion.policy = appVersion`, un módulo nativo nuevo exige subir la versión y
distribuir APK nueva. `expo-font` viene como dependencia de `expo`; hay que confirmar que el
APK instalado ya lo trae:

```bash
cd mobile && npx expo-modules-autolinking resolve --platform android | grep -i expo-font
```

- Si aparece: el cambio puede ir por OTA (fuentes como recursos del paquete).
- Si no aparece: agregar `expo-font` a `dependencies`, subir `expo.version` (p. ej. 1.6.0) y
  `android.versionCode`, y generar APK con `npm run build:apk` y `build:apk:pda`.

## Cambio

1. Fuentes estáticas (no variables; Android no interpola ejes): `Archivo_400Regular`,
   `Archivo_600SemiBold`, `Archivo_800ExtraBold`, `ArchivoNarrow_700Bold` y
   `AzeretMono_600SemiBold`, `AzeretMono_700Bold` (paquetes `@expo-google-fonts/archivo`,
   `@expo-google-fonts/archivo-narrow`, `@expo-google-fonts/azeret-mono`, o los `.ttf` en
   `mobile/assets/fonts/`).
2. `App.tsx`: `useFonts({...})`; mientras carga, la pantalla de arranque actual. Si
   `loadAsync` falla, seguir con las fuentes del sistema (no bloquear el inicio).
3. `operatorTheme.font`: `condensed: 'ArchivoNarrow_700Bold'`, `body: 'Archivo_400Regular'`,
   `bodyStrong: 'Archivo_600SemiBold'`, `heavy: 'Archivo_800ExtraBold'`,
   `mono: 'AzeretMono_700Bold'`, con respaldo a las del sistema cuando las fuentes no cargaron
   (exponer un `fontsReady` desde un contexto).
4. En React Native `fontWeight` no elige el archivo: donde hoy se usa peso 800/900 con la
   condensada o la mono, usar la familia del peso correspondiente y quitar `fontWeight`.

## Criterios de aceptación

- [ ] Arranque sin conexión con y sin fuentes cargadas.
- [ ] Fotos en tablet y PDA comparadas con las láminas (títulos angostos, folios en Azeret).
