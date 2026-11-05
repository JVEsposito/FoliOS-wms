import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { AuthSession } from '../domain/estiba';
import { EstibaApi } from '../services/estibaApi';
import { getEnvironmentalControlState } from '../services/environmentalControlApi';
import { OperatorHeader } from '../components/operator/OperatorHeader';
import { OperatorMenu } from '../components/operator/OperatorMenu';
import { operatorTheme as o } from '../theme/operatorTheme';
import { MaterialInventoryCountPanel } from '../components/MaterialInventoryCountPanel';
import { MaterialVerificationPanel } from '../components/MaterialVerificationPanel';
import { OperationalTaskInbox } from '../components/OperationalTaskInbox';
import { EnvironmentalControlScreen } from './EnvironmentalControlScreen';
import { OperationalScreen } from './OperationalScreen';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { sendOperatorPresence } from '../services/operatorPresenceApi';

type Props = {
  api: EstibaApi;
  auth: AuthSession;
  /** Vuelve al selector de módulos; solo existe cuando la persona tiene más de uno. */
  onChangeModule?: () => void;
  onLogout: () => void;
};

type WorkspaceView = 'labores' | 'ambiente' | 'operacion';

export function OperationalWorkspaceScreen({ api, auth, onChangeModule, onLogout }: Props) {
  const [view, setView] = useState<WorkspaceView>(api.mode === 'connected' ? 'labores' : 'operacion');
  const [environmentalDue, setEnvironmentalDue] = useState(0);
  const environmentalAvailable = api.mode === 'connected'
    && auth.usuario.capacidades.puede_consultar_control_ambiental === true;

  const updateEnvironmentalDue = useCallback((due: number) => setEnvironmentalDue(due), []);

  const presenceEnabled = api.mode === 'connected' && Boolean(api.baseUrl)
    && auth.usuario.capacidades.puede_operar_productos === true;
  const presence = useCallback(() => sendOperatorPresence(api.baseUrl!, auth.token), [api.baseUrl, auth.token]);
  useEffect(() => { if (presenceEnabled) void presence().catch(() => undefined); }, [presence, presenceEnabled]);
  useOperationalPolling(presence, { enabled: presenceEnabled, intervalMs: 15_000, maxBackoffMs: 30_000 });

  useEffect(() => {
    if (!environmentalAvailable || !api.baseUrl || view === 'ambiente') return;
    let active = true;
    const check = async () => {
      try {
        const response = await getEnvironmentalControlState(api.baseUrl!, auth.token);
        if (active) setEnvironmentalDue(response.camaras.filter((camera) => camera.requiere_control).length);
      } catch {
        // La pantalla Ambiente mostrará el detalle si el servidor no responde.
      }
    };
    void check();
    const timer = setInterval(() => void check(), 60_000);
    return () => { active = false; clearInterval(timer); };
  }, [api.baseUrl, auth.token, environmentalAvailable, view]);

  async function logoutFromTasks() {
    try {
      await api.logout(auth.token);
    } catch {
      // La sesión local se cierra aunque el servidor no responda.
    } finally {
      onLogout();
    }
  }

  return (
    <View style={styles.screen}>
      <OperatorHeader
        connected={api.mode === 'connected'}
        deviceName={auth.dispositivo.nombre}
        modeLabel={api.mode === 'connected' ? 'Conectado' : api.mode === 'demo' ? 'Modo demostración' : 'Sin configurar'}
        role={auth.usuario.rol}
        userName={auth.usuario.nombre}
        trailing={<OperatorMenu
          deviceName={auth.dispositivo.nombre}
          onChangeModule={onChangeModule}
          onLogout={() => void logoutFromTasks()}
          role={auth.usuario.rol}
          userName={auth.usuario.nombre}
        />}
        navigation={<View accessibilityRole="tablist" style={styles.buttons}>
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{ selected: view === 'labores' }}
            onPress={() => setView('labores')}
            style={[styles.button, view === 'labores' && styles.buttonActive]}
          >
            <Text style={[styles.buttonText, view === 'labores' && styles.buttonTextActive]}>Mi jornada</Text>
          </Pressable>
          {environmentalAvailable ? <Pressable
            accessibilityLabel={environmentalDue > 0
              ? `Ambiente: ${environmentalDue} ${environmentalDue === 1 ? 'cámara requiere' : 'cámaras requieren'} control ambiental`
              : 'Ambiente'}
            accessibilityRole="tab"
            accessibilityState={{ selected: view === 'ambiente' }}
            onPress={() => setView('ambiente')}
            style={[styles.button, view === 'ambiente' && styles.buttonActive]}
          >
            <Text style={[styles.buttonText, view === 'ambiente' && styles.buttonTextActive]}>Ambiente</Text>
            {environmentalDue > 0 ? (
              <View style={styles.badge}><Text style={styles.badgeText}>{environmentalDue}</Text></View>
            ) : null}
          </Pressable> : null}
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{ selected: view === 'operacion' }}
            onPress={() => setView('operacion')}
            style={[styles.button, view === 'operacion' && styles.buttonActive]}
          >
            <Text style={[styles.buttonText, view === 'operacion' && styles.buttonTextActive]}>Plano y operación</Text>
          </Pressable>
        </View>}
      />

      <View style={styles.content}>
        {view === 'labores' ? (
          auth.usuario.rol === 'camarero_materiales' && api.baseUrl
            ? <ScrollView contentContainerStyle={{ padding: 16 }}><MaterialVerificationPanel baseUrl={api.baseUrl} auth={auth} /><MaterialInventoryCountPanel baseUrl={api.baseUrl} auth={auth} /></ScrollView>
            : <OperationalTaskInbox api={api} auth={auth} />
        ) : view === 'ambiente' && api.baseUrl ? (
          <EnvironmentalControlScreen auth={auth} baseUrl={api.baseUrl} onDueChange={updateEnvironmentalDue} />
        ) : (
          <OperationalScreen api={api} auth={auth} onLogout={onLogout} />
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  buttons: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' },
  button: {
    minHeight: o.touch.minimum,
    flexDirection: 'row',
    alignItems: 'center',
    gap: o.space[2],
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: o.radius.control,
    backgroundColor: 'transparent',
  },
  buttonActive: { backgroundColor: o.color.surfaceMuted },
  buttonText: { color: o.color.textSecondary, fontSize: o.type.body, fontWeight: '600' },
  buttonTextActive: { color: o.color.primaryPressed, fontWeight: '800' },
  badge: { minWidth: 26, height: 26, paddingHorizontal: 8, borderRadius: o.radius.pill, alignItems: 'center', justifyContent: 'center', backgroundColor: o.color.warning },
  badgeText: { color: o.color.onPrimary, fontSize: o.type.caption, fontWeight: '900' },
  content: { flex: 1, minHeight: 0 },
});
