import { font } from './src/theme/typography';
import { useFonts } from 'expo-font';
import { StatusBar } from 'expo-status-bar';
import * as ScreenOrientation from 'expo-screen-orientation';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';

import { AuthSession, LoginPayload, TabletModule } from './src/domain/estiba';
import { PDA_MODULES, isDemoRuntime, isPdaBuild } from './src/config/appVariant';
import { initializeDemoDatabase } from './src/demo/demoDatabase';
import { DemoDataScreen } from './src/screens/DemoDataScreen';
import { LoginScreen } from './src/screens/LoginScreen';
import { FirstPasswordChangeScreen } from './src/screens/FirstPasswordChangeScreen';
import { MaterialReceptionScreen } from './src/screens/MaterialReceptionScreen';
import { PackedFruitReceptionScreen } from './src/screens/PackedFruitReceptionScreen';
import { SagCounterpartScreen } from './src/screens/SagCounterpartScreen';
import { ConsolidationScreen } from './src/screens/ConsolidationScreen';
import { GatehouseScreen } from './src/screens/GatehouseScreen';
import { FrutaProcesoScreen } from './src/screens/FrutaProcesoScreen';
import { HidrocoolerMpScreen } from './src/screens/HidrocoolerMpScreen';
import { OperationalWorkspaceScreen } from './src/screens/OperationalWorkspaceScreen';
import { PrefrioWorkspaceScreen } from './src/screens/PrefrioWorkspaceScreen';
import { RepalletizingScreen } from './src/screens/RepalletizingScreen';
import { ValidationWorkspaceScreen } from './src/screens/ValidationWorkspaceScreen';
import { ValidationMpScreen } from './src/screens/ValidationMpScreen';
import { loadApiBaseUrl, saveApiBaseUrl } from './src/services/apiConfiguration';
import { applyAvailableUpdate } from './src/services/appUpdates';
import { createEstibaApi } from './src/services/estibaApi';
import { areas, type AreaKey } from './src/theme/areas';
import { colors } from './src/theme/colors';
import { operatorTheme as o } from './src/theme/operatorTheme';
import { fontAssets } from './src/theme/fontAssets';
import { FieldCorner, FieldMark } from './src/components/field/FieldMark';
import { FieldNavigationProvider } from './src/components/field/FieldNavigation';

type MobileModule = TabletModule;

export default function App() {
  const [baseUrl, setBaseUrl] = useState<string | null>(null);
  const [configurationLoaded, setConfigurationLoaded] = useState(false);
  const [auth, setAuth] = useState<AuthSession | null>(null);
  const [activeModule, setActiveModule] = useState<MobileModule | null>(null);
  const api = useMemo(() => createEstibaApi(baseUrl), [baseUrl]);
  // Inter y JetBrains Mono van dentro del paquete; si fallan se sigue con la del sistema.
  const [fontsLoaded, fontError] = useFonts(fontAssets);
  const fontsSettled = fontsLoaded || fontError !== null;

  useEffect(() => {
    async function prepareApplication() {
      try {
        if (isDemoRuntime) await initializeDemoDatabase();
        setBaseUrl(await loadApiBaseUrl());
      } catch {
        setBaseUrl(null);
      } finally {
        setConfigurationLoaded(true);
      }

      if (!isDemoRuntime) void applyAvailableUpdate();
    }

    void prepareApplication();
  }, []);

  useEffect(() => {
    // La PDA es un equipo de mano: siempre vertical, incluso en el login.
    const orientation = isPdaBuild || activeModule === 'validacion' || activeModule === 'validacion_mp' || activeModule === 'repaletizaje' || activeModule === 'fruta_proceso' || activeModule === 'recepcion_fruta_embalada' || activeModule === 'porteria' || activeModule === 'contraparte_sag' || activeModule === 'consolidacion'
      ? ScreenOrientation.OrientationLock.PORTRAIT_UP
      : activeModule
        ? ScreenOrientation.OrientationLock.LANDSCAPE
        : ScreenOrientation.OrientationLock.DEFAULT;

    void ScreenOrientation.lockAsync(orientation).catch(() => {
      // Algunos equipos administrados pueden impedir que la aplicación cambie
      // la orientación. La interfaz responsiva sigue siendo utilizable.
    });
  }, [activeModule]);

  async function login(payload: LoginPayload) {
    const session = await api.login(payload);
    setAuth(session);
    setActiveModule(session.usuario.debe_cambiar_password ? null : defaultModule(session));
  }

  async function changePassword(currentPassword: string, newPassword: string) {
    if (!auth) return;
    await api.changePassword(auth.token, currentPassword, newPassword);
    setAuth({ ...auth, usuario: { ...auth.usuario, debe_cambiar_password: false } });
    setActiveModule(defaultModule(auth));
  }

  async function configureServer(value: string) {
    const configuredUrl = await saveApiBaseUrl(value);
    setAuth(null);
    setActiveModule(null);
    setBaseUrl(configuredUrl);
  }

  function clearSession() {
    setAuth(null);
    setActiveModule(null);
  }

  async function logoutPersistentModule() {
    if (auth) {
      try {
        await api.logout(auth.token);
      } catch {
        // Las bandejas locales permanecen guardadas aunque el servidor no responda.
      }
    }
    clearSession();
  }

  const modules = auth ? availableModules(auth) : [];

  return (
    <SafeAreaProvider>
      <SafeAreaView edges={['top', 'right', 'bottom', 'left']} style={styles.app}>
        <StatusBar style="light" />
        {!configurationLoaded || !fontsSettled ? (
          <View style={styles.boot}>
            <ActivityIndicator color={colors.cyan} size="large" />
            <Text style={styles.bootText}>Preparando FoliOS…</Text>
          </View>
        ) : auth?.usuario.debe_cambiar_password ? (
          <FirstPasswordChangeScreen
            userName={auth.usuario.nombre}
            onChangePassword={changePassword}
            onLogout={() => void logoutPersistentModule()}
          />
        ) : auth ? (
          <FieldNavigationProvider auth={auth} onChangeModule={modules.length > 1 && activeModule ? () => setActiveModule(null) : undefined}>
          <View style={styles.workspace}>
            {!activeModule ? (
              <ModuleSelection
                modules={modules}
                onSelect={setActiveModule}
                userName={auth.usuario.nombre}
              />
            ) : activeModule === 'demo_administracion' ? (
              <DemoDataScreen onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'validacion' ? (
              <ValidationWorkspaceScreen
                auth={auth}
                baseUrl={api.baseUrl}
                onLogout={() => void logoutPersistentModule()}
              />
            ) : activeModule === 'prefrio' ? (
              <PrefrioWorkspaceScreen
                auth={auth}
                baseUrl={api.baseUrl}
                onLogout={() => void logoutPersistentModule()}
              />
            ) : activeModule === 'validacion_mp' ? (
              <ValidationMpScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'repaletizaje' ? (
              api.baseUrl ? (
                <RepalletizingScreen auth={auth} baseUrl={api.baseUrl} onLogout={() => void logoutPersistentModule()} standalone />
              ) : (
                <View style={styles.boot}>
                  <Text style={styles.bootText}>Repaletizaje requiere conexión con el servidor.</Text>
                </View>
              )
            ) : activeModule === 'contraparte_sag' ? (
              <SagCounterpartScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'consolidacion' ? (
              <ConsolidationScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'porteria' ? (
              <GatehouseScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'recepcion_fruta_embalada' ? (
              <PackedFruitReceptionScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'fruta_proceso' ? (
              <FrutaProcesoScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'hidrocooler_mp' ? (
              <HidrocoolerMpScreen auth={auth} baseUrl={api.baseUrl ?? ''} onLogout={() => void logoutPersistentModule()} />
            ) : activeModule === 'recepcion_materiales' ? (
              <MaterialReceptionScreen
                auth={auth}
                baseUrl={api.baseUrl ?? ''}
                onLogout={() => void logoutPersistentModule()}
              />
            ) : (
              <OperationalWorkspaceScreen
                api={api}
                auth={auth}
                onChangeModule={modules.length > 1 ? () => setActiveModule(null) : undefined}
                onLogout={clearSession}
              />
            )}
          </View>
          </FieldNavigationProvider>
        ) : (
          <LoginScreen
            baseUrl={api.baseUrl}
            configurationError={api.configurationError}
            mode={api.mode}
            onLogin={login}
            onSaveBaseUrl={configureServer}
          />
        )}
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

function availableModules(auth: AuthSession): MobileModule[] {
  const supported: MobileModule[] = [
    'contraparte_sag',
    'consolidacion',
    'porteria',
    'demo_administracion',
    'operacion',
    'operacion_materiales',
    'recepcion_materiales',
    'validacion',
    'validacion_mp',
    'repaletizaje',
    'recepcion_fruta_embalada',
    'fruta_proceso',
    'hidrocooler_mp',
    'prefrio',
  ];

  return supported
    .filter((module) => !isPdaBuild || (PDA_MODULES as readonly string[]).includes(module))
    .filter((module) => auth.usuario.modulos_tablet?.includes(module));
}

function defaultModule(auth: AuthSession): MobileModule | null {
  const modules = availableModules(auth);
  return modules.length === 1 ? modules[0] : null;
}

const moduleCards: ReadonlyArray<{ id: MobileModule; area: AreaKey; title: string; description: string }> = [
  { id: 'contraparte_sag', area: 'frigorifico', title: 'Contraparte SAG', description: 'Captura y seguimiento de inspecciones y documentos.' },
  { id: 'consolidacion', area: 'frigorifico', title: 'Consolidación', description: 'Registra posición, inspección y fotos de cada pallet que sube al camión.' },
  { id: 'porteria', area: 'materia-prima', title: 'Portería', description: 'Registra la llegada del camión y sus guías.' },
  { id: 'demo_administracion', area: 'administracion', title: 'Administración Demo', description: 'Gestiona los maestros y escenarios locales.' },
  { id: 'validacion', area: 'frigorifico', title: 'Validación PT', description: 'Escanea pallets, registra la decisión y sincroniza las capturas.' },
  { id: 'validacion_mp', area: 'materia-prima', title: 'Validación MP', description: 'Recibe correlativos de Romana y prepara las segregaciones.' },
  { id: 'repaletizaje', area: 'frigorifico', title: 'Repaletizaje', description: 'Consolida saldos y registra el RRPL-01.' },
  { id: 'recepcion_fruta_embalada', area: 'frigorifico', title: 'Recepción de fruta embalada', description: 'Registra camiones y pallets externos en borrador.' },
  { id: 'fruta_proceso', area: 'materia-prima', title: 'Fruta a proceso', description: 'Entrega bins por viaje desde cámara hacia Packing.' },
  { id: 'hidrocooler_mp', area: 'materia-prima', title: 'Hidrocooler MP', description: 'Opera los ciclos de enfriamiento inicial.' },
  { id: 'prefrio', area: 'frigorifico', title: 'Prefrío', description: 'Carga folios a túneles y opera cada ciclo térmico.' },
  { id: 'operacion_materiales', area: 'materiales', title: 'Cámara de materiales', description: 'Opera posiciones y movimientos de materiales.' },
  { id: 'recepcion_materiales', area: 'materiales', title: 'Recepción de materiales', description: 'Registra guías, separa bultos y confirma folios.' },
  { id: 'operacion', area: 'frigorifico', title: 'Operación frigorífico', description: 'Consulta cámaras y realiza las labores y cargas del turno.' },
];

function ModuleSelection({ modules, onSelect, userName }: { modules: MobileModule[]; onSelect: (module: MobileModule) => void; userName: string }) {
  const { width } = useWindowDimensions();
  const compact = isPdaBuild || width < 720;
  const cards = moduleCards.filter((card) => modules.includes(card.id));
  return (
    <ScrollView contentContainerStyle={[styles.selector, compact && styles.selectorCompact]}>
      <View style={styles.selectorHeader}>
        <FieldMark area="operacion" />
        <View style={styles.selectorHeading}>
          <Text accessibilityRole="header" style={styles.selectorTitle}>Área de trabajo</Text>
          <Text style={styles.selectorCopy}>{userName}</Text>
        </View>
      </View>
      <View style={[styles.selectorCards, compact && styles.selectorCardsCompact]}>
        {cards.map(({ id, area, title, description }) => (
          <Pressable
            key={id}
            accessibilityRole="button"
            accessibilityLabel={title}
            accessibilityHint={description}
            onPress={() => onSelect(id)}
            style={({ pressed }) => [styles.selectorCard, compact && styles.selectorCardCompact,
              { borderLeftColor: areas[area].bright }, pressed && styles.selectorCardPressed]}
          >
            <FieldCorner area={area} />
            <FieldMark area={area} decorative />
            <View style={styles.selectorCardLabel}>
              <Text style={styles.selectorCardTitle}>{title}</Text>
              <Text style={styles.selectorCardCopy}>{description}</Text>
            </View>
            <Text aria-hidden style={styles.selectorArrow}>→</Text>
          </Pressable>
        ))}
      </View>
      {!cards.length ? (
        <Text style={styles.noModule}>
          {isPdaBuild
            ? 'Tu perfil no tiene un módulo habilitado para esta PDA. Solicita revisar los accesos del equipo.'
            : 'El perfil no posee un módulo móvil habilitado.'}
        </Text>
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  app: { flex: 1, backgroundColor: colors.background },
  workspace: { flex: 1 },
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  bootText: { color: colors.muted, fontSize: 12, fontFamily: font.heavy },
  selector: { flexGrow: 1, alignItems: 'center', padding: 28, backgroundColor: colors.background },
  selectorCompact: { padding: 14 },
  selectorHeader: {
    width: '100%', maxWidth: 1150, flexDirection: 'row', alignItems: 'center', gap: 12, padding: 16,
    borderBottomColor: colors.border, borderBottomWidth: 1,
  },
  selectorHeading: { flex: 1, minWidth: 0 },
  selectorTitle: { color: colors.text, fontSize: o.type.title, fontFamily: font.heavy },
  selectorCopy: { color: colors.muted, marginTop: 4 },
  selectorCards: { width: '100%', maxWidth: 1150, flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 16 },
  selectorCardsCompact: { flexDirection: 'column', gap: 8 },
  selectorCard: {
    flexGrow: 1, flexBasis: 250, minWidth: 0, minHeight: 100, flexDirection: 'row', alignItems: 'center', gap: 10,
    padding: 14, borderWidth: 1, borderLeftWidth: 3, borderColor: colors.border,
    borderRadius: o.radius.panel, borderTopRightRadius: 2, backgroundColor: colors.panel,
  },
  selectorCardCompact: { flexBasis: 'auto', flexGrow: 0, minHeight: o.touch.minimum, padding: 10 },
  selectorCardPressed: { backgroundColor: colors.panelStrong },
  selectorCardLabel: { flex: 1, minWidth: 0, gap: 3 },
  selectorCardTitle: { color: colors.text, fontSize: o.type.body, fontFamily: font.heavy },
  selectorCardCopy: { color: colors.textSecondary, fontSize: o.type.caption, lineHeight: 18 },
  selectorArrow: { color: colors.cyan, fontSize: o.type.heading, fontFamily: font.bold },
  noModule: { color: colors.red, marginTop: 24, fontFamily: font.heavy, textAlign: 'center' },
});
