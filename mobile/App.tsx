import { StatusBar } from 'expo-status-bar';
import * as ScreenOrientation from 'expo-screen-orientation';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';

import { AuthSession, LoginPayload, TabletModule } from './src/domain/estiba';
import { PDA_MODULES, isDemoRuntime, isPdaBuild } from './src/config/appVariant';
import { initializeDemoDatabase } from './src/demo/demoDatabase';
import { DemoDataScreen } from './src/screens/DemoDataScreen';
import { LoginScreen } from './src/screens/LoginScreen';
import { FirstPasswordChangeScreen } from './src/screens/FirstPasswordChangeScreen';
import { MaterialReceptionScreen } from './src/screens/MaterialReceptionScreen';
import { PackedFruitReceptionScreen } from './src/screens/PackedFruitReceptionScreen';
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
import { colors } from './src/theme/colors';

type MobileModule = TabletModule;

export default function App() {
  const [baseUrl, setBaseUrl] = useState<string | null>(null);
  const [configurationLoaded, setConfigurationLoaded] = useState(false);
  const [auth, setAuth] = useState<AuthSession | null>(null);
  const [activeModule, setActiveModule] = useState<MobileModule | null>(null);
  const api = useMemo(() => createEstibaApi(baseUrl), [baseUrl]);

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
    const orientation = isPdaBuild || activeModule === 'validacion' || activeModule === 'validacion_mp' || activeModule === 'repaletizaje' || activeModule === 'fruta_proceso' || activeModule === 'recepcion_fruta_embalada'
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
        {!configurationLoaded ? (
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
          <View style={styles.workspace}>
            {modules.length > 1 && activeModule ? (
              <View style={[styles.moduleStrip, isPdaBuild && styles.moduleStripPda]}>
                <Text numberOfLines={1} style={styles.moduleStripText}>{isPdaBuild ? moduleLabel(activeModule) : `Módulo activo: ${moduleLabel(activeModule)}`}</Text>
                <Pressable onPress={() => setActiveModule(null)} style={styles.changeModule}>
                  <Text style={styles.changeModuleText}>{isPdaBuild ? 'Cambiar' : 'Cambiar módulo'}</Text>
                </Pressable>
              </View>
            ) : null}
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
              <OperationalWorkspaceScreen api={api} auth={auth} onLogout={clearSession} />
            )}
          </View>
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

function moduleLabel(module: MobileModule) {
  return module === 'demo_administracion'
    ? 'Administración Demo'
    : module === 'validacion'
    ? 'Validación'
    : module === 'validacion_mp'
      ? 'Validación MP'
      : module === 'repaletizaje'
        ? 'Repaletizaje'
      : module === 'recepcion_fruta_embalada'
        ? 'Recepción de fruta embalada'
      : module === 'fruta_proceso'
        ? 'Fruta a proceso'
      : module === 'hidrocooler_mp'
        ? 'Hidrocooler MP'
      : module === 'prefrio'
        ? 'Prefrío'
        : module === 'operacion_materiales'
          ? 'Cámara y operación de materiales'
          : module === 'recepcion_materiales'
            ? 'Recepción de materiales'
            : 'Operación frigorífico';
}

function ModuleSelection({ modules, onSelect, userName }: { modules: MobileModule[]; onSelect: (module: MobileModule) => void; userName: string }) {
  return (
    <View style={[styles.selector, isPdaBuild && styles.selectorPda]}>
      <Text style={styles.selectorEyebrow}>FoliOS · TURNO</Text>
      <Text style={[styles.selectorTitle, isPdaBuild && styles.selectorTitlePda]}>Selecciona el área de trabajo</Text>
      <Text style={styles.selectorCopy}>{userName}, tu perfil posee acceso a más de un módulo.</Text>
      <View style={[styles.selectorCards, isPdaBuild && styles.selectorCardsPda]}>
        {modules.includes('demo_administracion') ? (
          <Pressable onPress={() => onSelect('demo_administracion')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>◎</Text>
            <Text style={styles.selectorCardTitle}>Administración Demo</Text>
            <Text style={styles.selectorCardCopy}>Gestionar maestros, clientes, folios y cargas CAR locales; preparar y restaurar escenarios.</Text>
          </Pressable>
        ) : null}
        {modules.includes('validacion') ? (
          <Pressable onPress={() => onSelect('validacion')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>✓</Text>
            <Text style={styles.selectorCardTitle}>Validación</Text>
            <Text style={styles.selectorCardCopy}>Escanear pallets, aprobar, observar y sincronizar capturas.</Text>
          </Pressable>
        ) : null}
        {modules.includes('validacion_mp') ? (
          <Pressable onPress={() => onSelect('validacion_mp')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>⌁</Text>
            <Text style={styles.selectorCardTitle}>Validación MP</Text>
            <Text style={styles.selectorCardCopy}>Recibir correlativos de Romana, contar envases y preparar segregaciones.</Text>
          </Pressable>
        ) : null}
        {modules.includes('repaletizaje') ? (
          <Pressable onPress={() => onSelect('repaletizaje')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>⇄</Text>
            <Text style={styles.selectorCardTitle}>Repaletizaje</Text>
            <Text style={styles.selectorCardCopy}>Consolidar saldos en cámara; cada repa llena el registro RRPL-01.</Text>
          </Pressable>
        ) : null}
        {modules.includes('recepcion_fruta_embalada') ? (
          <Pressable onPress={() => onSelect('recepcion_fruta_embalada')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>↘</Text>
            <Text style={styles.selectorCardTitle}>Recepción de fruta embalada</Text>
            <Text style={styles.selectorCardCopy}>Capturar camiones y pallets externos en borrador.</Text>
          </Pressable>
        ) : null}
        {modules.includes('fruta_proceso') ? (
          <Pressable onPress={() => onSelect('fruta_proceso')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>→</Text>
            <Text style={styles.selectorCardTitle}>Fruta a proceso</Text>
            <Text style={styles.selectorCardCopy}>Entregar bins por viaje físico desde cámara hacia Packing.</Text>
          </Pressable>
        ) : null}
        {modules.includes('hidrocooler_mp') ? (
          <Pressable onPress={() => onSelect('hidrocooler_mp')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>≈</Text>
            <Text style={styles.selectorCardTitle}>Hidrocooler MP</Text>
            <Text style={styles.selectorCardCopy}>Iniciar, finalizar y revisar ciclos de enfriamiento de materia prima.</Text>
          </Pressable>
        ) : null}
        {modules.includes('prefrio') ? (
          <Pressable onPress={() => onSelect('prefrio')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>◫</Text>
            <Text style={styles.selectorCardTitle}>Prefrío</Text>
            <Text style={styles.selectorCardCopy}>Consultar folios pendientes, cargarlos a túneles y operar procesos térmicos.</Text>
          </Pressable>
        ) : null}
        {modules.includes('operacion_materiales') ? (
          <Pressable onPress={() => onSelect('operacion_materiales')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>▦</Text>
            <Text style={styles.selectorCardTitle}>Cámara de materiales</Text>
            <Text style={styles.selectorCardCopy}>Consultar y operar cámaras, posiciones y movimientos de materiales.</Text>
          </Pressable>
        ) : null}
        {modules.includes('recepcion_materiales') ? (
          <Pressable onPress={() => onSelect('recepcion_materiales')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>▦</Text>
            <Text style={styles.selectorCardTitle}>Recepción materiales</Text>
            <Text style={styles.selectorCardCopy}>Registrar guías, separar bultos, confirmar folios y revisar pendientes.</Text>
          </Pressable>
        ) : null}
        {modules.includes('operacion') ? (
          <Pressable onPress={() => onSelect('operacion')} style={[styles.selectorCard, isPdaBuild && styles.selectorCardPda]}>
            <Text style={styles.selectorIcon}>❄</Text>
            <Text style={styles.selectorCardTitle}>Operación frigorífico</Text>
            <Text style={styles.selectorCardCopy}>Cámaras de producto terminado, cargas y despachos.</Text>
          </Pressable>
        ) : null}
      </View>
      {!modules.length ? (
        <Text style={styles.noModule}>
          {isPdaBuild
            ? 'Esta PDA solo opera Validación PT, Validación MP y Repaletizaje, y tu perfil no tiene ninguno de ellos. Usa la tablet para los demás módulos.'
            : 'El perfil no posee un módulo móvil habilitado.'}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  app: { flex: 1, backgroundColor: colors.background },
  workspace: { flex: 1 },
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  bootText: { color: colors.muted, fontSize: 12, fontWeight: '800' },
  moduleStrip: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingHorizontal: 18, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.backgroundDeep },
  moduleStripText: { color: colors.muted, fontSize: 10, fontWeight: '900', textTransform: 'uppercase' },
  changeModule: { paddingHorizontal: 11, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: colors.cyanDark },
  changeModuleText: { color: colors.cyan, fontSize: 10, fontWeight: '900' },
  selector: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: colors.background },
  selectorEyebrow: { color: colors.cyan, fontSize: 11, fontWeight: '900', letterSpacing: 1.4 },
  selectorTitle: { color: colors.text, fontSize: 28, fontWeight: '900', marginTop: 7, textAlign: 'center' },
  selectorCopy: { color: colors.muted, marginTop: 8, textAlign: 'center' },
  selectorCards: { width: '100%', maxWidth: 1300, flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: 16, marginTop: 28 },
  selectorCard: { flexGrow: 1, flexBasis: 220, maxWidth: 300, minHeight: 210, justifyContent: 'center', padding: 24, borderRadius: 18, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.panel },
  selectorIcon: { color: colors.cyan, fontSize: 34, fontWeight: '900' },
  selectorCardTitle: { color: colors.text, fontSize: 21, fontWeight: '900', marginTop: 14 },
  selectorCardCopy: { color: colors.muted, lineHeight: 20, marginTop: 7 },
  noModule: { color: colors.red, marginTop: 24, fontWeight: '800', textAlign: 'center' },
  moduleStripPda: { paddingHorizontal: 10, paddingVertical: 5 },
  selectorPda: { justifyContent: 'flex-start', padding: 14, paddingTop: 24 },
  selectorTitlePda: { fontSize: 21 },
  selectorCardsPda: { flexDirection: 'column', gap: 10, marginTop: 18 },
  selectorCardPda: { flexBasis: 'auto', flexGrow: 0, maxWidth: '100%', minHeight: 0, padding: 16, borderRadius: 14 },
});
