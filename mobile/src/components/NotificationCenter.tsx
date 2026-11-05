import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { OPERATIONAL_POLL_INTERVAL_MS } from '../config/polling';
import { AuthSession, OperationalNotification } from '../domain/estiba';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import { EstibaApi } from '../services/estibaApi';
import { colors } from '../theme/colors';
import { font } from '../theme/typography';

type Props = {
  api: EstibaApi;
  auth: AuthSession;
  onFailure: (reason: unknown) => void;
  onOpenLoads?: (loadId?: string) => void;
  onOpenMaterialDispatches?: () => void;
  onSuccess: () => void;
  onUnreadChanged?: (unread: number) => void;
};

export function NotificationCenter({
  api,
  auth,
  onFailure,
  onOpenLoads,
  onOpenMaterialDispatches,
  onSuccess,
  onUnreadChanged,
}: Props) {
  const [items, setItems] = useState<OperationalNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [lastSync, setLastSync] = useState<string | null>(null);
  const visibleRef = useRef(false);
  const summaryInFlight = useRef(false);
  const feedInFlight = useRef(false);

  useEffect(() => {
    void refreshSummary();
  }, []);

  useOperationalPolling(
    () => visibleRef.current ? refreshFeed(true) : refreshSummary(),
    { intervalMs: OPERATIONAL_POLL_INTERVAL_MS },
  );

  async function refreshSummary() {
    if (summaryInFlight.current) return;
    summaryInFlight.current = true;
    try {
      const summary = await api.getOperationalNotificationSummary(auth.token);
      setUnread(summary.unread);
      onUnreadChanged?.(summary.unread);
      setLastSync(summary.syncedAt);
      onSuccess();
    } catch (reason) {
      onFailure(reason);
    } finally {
      summaryInFlight.current = false;
    }
  }

  async function refreshFeed(quiet: boolean) {
    if (feedInFlight.current) return;
    feedInFlight.current = true;
    if (!quiet) setBusy(true);
    try {
      const feed = await api.listOperationalNotifications(auth.token);
      setItems(feed.items);
      setUnread(feed.unread);
      onUnreadChanged?.(feed.unread);
      setLastSync(feed.syncedAt);
      onSuccess();
    } catch (reason) {
      onFailure(reason);
    } finally {
      feedInFlight.current = false;
      if (!quiet) setBusy(false);
    }
  }

  function open() {
    visibleRef.current = true;
    setVisible(true);
    void refreshFeed(false);
  }

  function close() {
    visibleRef.current = false;
    setVisible(false);
  }

  async function read(notification: OperationalNotification) {
    if (notification.leida_at) return;
    setBusy(true);
    try {
      const updated = await api.readOperationalNotification(auth.token, notification.id);
      replace(updated);
      setUnread((current) => {
        const next = Math.max(0, current - 1);
        onUnreadChanged?.(next);
        return next;
      });
    } catch (reason) {
      onFailure(reason);
    } finally {
      setBusy(false);
    }
  }

  async function confirm(notification: OperationalNotification) {
    setBusy(true);
    try {
      const updated = await api.confirmOperationalNotification(auth.token, notification.id);
      replace(updated);
      if (!notification.leida_at) setUnread((current) => {
        const next = Math.max(0, current - 1);
        onUnreadChanged?.(next);
        return next;
      });
    } catch (reason) {
      onFailure(reason);
    } finally {
      setBusy(false);
    }
  }

  function replace(updated: OperationalNotification) {
    setItems((current) => current.map((item) => item.id === updated.id ? updated : item));
  }

  return (
    <>
      <Pressable
        accessibilityLabel={`${unread} notificaciones sin leer`}
        onPress={open}
        style={styles.trigger}
      >
        <Text style={styles.bell}>●</Text>
        <Text style={styles.triggerText}>Alertas</Text>
        {unread > 0 && (
          <View style={styles.badge}><Text style={styles.badgeText}>{unread > 99 ? '99+' : unread}</Text></View>
        )}
      </Pressable>

      <Modal animationType="fade" onRequestClose={close} transparent visible={visible}>
        <View style={styles.backdrop}>
          <View style={styles.modal}>
            <View style={styles.header}>
              <View>
                <Text style={styles.eyebrow}>CENTRO OPERACIONAL</Text>
                <Text style={styles.title}>Notificaciones</Text>
                <Text style={styles.subtitle}>
                  {unread} sin leer{lastSync ? ` · sincronizado ${formatTime(lastSync)}` : ''}
                </Text>
              </View>
              <View style={styles.headerActions}>
                <Pressable onPress={() => void refreshFeed(false)} style={styles.secondaryButton}>
                  <Text style={styles.secondaryText}>Actualizar</Text>
                </Pressable>
                <Pressable onPress={close} style={styles.closeButton}>
                  <Text style={styles.closeText}>×</Text>
                </Pressable>
              </View>
            </View>

            <ScrollView contentContainerStyle={styles.list} nestedScrollEnabled>
              {items.map((notification) => (
                <Pressable
                  key={notification.id}
                  onPress={() => void read(notification)}
                  style={[
                    styles.notification,
                    !notification.leida_at && styles.notificationUnread,
                    notification.severidad === 'critica' && styles.notificationCritical,
                  ]}
                >
                  <View style={[styles.severity, { backgroundColor: severityColor(notification.severidad) }]} />
                  <View style={styles.copy}>
                    <View style={styles.notificationTop}>
                      <Text style={styles.notificationTitle}>{notification.titulo}</Text>
                      <Text style={styles.time}>{formatTime(notification.created_at)}</Text>
                    </View>
                    <Text style={styles.message}>{notification.mensaje}</Text>
                    <View style={styles.metaRow}>
                      {notification.carga && onOpenLoads && (
                        <Pressable
                          onPress={() => {
                            void read(notification);
                            close();
                            onOpenLoads(notification.tipo === 'carga_retornada' ? undefined : notification.carga?.id);
                          }}
                          style={styles.loadLink}
                        >
                          <Text style={styles.loadLinkText}>{notification.tipo === 'carga_retornada' ? 'Ver reingresos a cámara' : `Abrir ${notification.carga.codigo}`}</Text>
                        </Pressable>
                      )}
                      {notification.despacho_material && onOpenMaterialDispatches && (
                        <Pressable
                          onPress={() => {
                            void read(notification);
                            close();
                            onOpenMaterialDispatches();
                          }}
                          style={styles.loadLink}
                        >
                          <Text style={styles.loadLinkText}>
                            Abrir {notification.despacho_material.codigo}
                          </Text>
                        </Pressable>
                      )}
                      {notification.confirmada_at ? (
                        <Text style={styles.confirmed}>CONFIRMADA</Text>
                      ) : (
                        <Pressable onPress={() => void confirm(notification)} style={styles.confirmButton}>
                          <Text style={styles.confirmText}>Confirmar recepción</Text>
                        </Pressable>
                      )}
                    </View>
                  </View>
                </Pressable>
              ))}
              {!items.length && !busy && (
                <View style={styles.empty}>
                  <Text style={styles.emptyTitle}>Sin notificaciones</Text>
                  <Text style={styles.emptyText}>Las novedades de cargas y materiales aparecerán aquí.</Text>
                </View>
              )}
            </ScrollView>

            {busy && (
              <View pointerEvents="none" style={styles.busy}>
                <ActivityIndicator color={colors.cyan} />
              </View>
            )}
          </View>
        </View>
      </Modal>
    </>
  );
}

function severityColor(severity: OperationalNotification['severidad']) {
  return {
    informativa: colors.blue,
    advertencia: colors.amber,
    critica: colors.red,
    exito: colors.green,
  }[severity];
}

function formatTime(value: string) {
  return new Date(value).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  trigger: { minHeight: 32, paddingHorizontal: 9, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, flexDirection: 'row', alignItems: 'center', gap: 5 },
  bell: { fontFamily: font.regular, color: colors.amber, fontSize: 13 },
  triggerText: { fontFamily: font.heavy, color: colors.text, fontSize: 12 },
  badge: { minWidth: 18, height: 18, paddingHorizontal: 4, borderRadius: 9, backgroundColor: colors.red, alignItems: 'center', justifyContent: 'center' },
  badgeText: { fontFamily: font.heavy, color: '#fff', fontSize: 11 },
  backdrop: { flex: 1, padding: 20, backgroundColor: 'rgba(0,0,0,0.78)', alignItems: 'center', justifyContent: 'center' },
  modal: { width: '100%', maxWidth: 820, maxHeight: '88%', padding: 18, borderRadius: 18, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel, position: 'relative' },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  eyebrow: { fontFamily: font.heavy, color: colors.cyan, fontSize: 12, letterSpacing: 1.2 },
  title: { fontFamily: font.heavy, marginTop: 3, color: colors.text, fontSize: 21 },
  subtitle: { fontFamily: font.regular, marginTop: 3, color: colors.muted, fontSize: 13 },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  secondaryButton: { paddingHorizontal: 12, paddingVertical: 9, borderRadius: 8, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background },
  secondaryText: { fontFamily: font.heavy, color: colors.text, fontSize: 12 },
  closeButton: { width: 34, height: 34, borderRadius: 9, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  closeText: { fontFamily: font.regular, color: colors.text, fontSize: 18 },
  list: { paddingTop: 14, gap: 8 },
  notification: { padding: 11, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.background, flexDirection: 'row', gap: 9 },
  notificationUnread: { borderColor: colors.cyan, backgroundColor: colors.selected },
  notificationCritical: { borderLeftColor: colors.red, borderLeftWidth: 4 },
  severity: { width: 7, height: 7, marginTop: 4, borderRadius: 4 },
  copy: { flex: 1, minWidth: 0 },
  notificationTop: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  notificationTitle: { fontFamily: font.heavy, flex: 1, color: colors.text, fontSize: 14 },
  time: { fontFamily: font.regular, color: colors.muted, fontSize: 12 },
  message: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 13, lineHeight: 17 },
  metaRow: { marginTop: 8, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 },
  loadLink: { paddingHorizontal: 9, paddingVertical: 6, borderRadius: 7, backgroundColor: colors.cyan },
  loadLinkText: { fontFamily: font.heavy, color: colors.accentText, fontSize: 11 },
  confirmButton: { paddingHorizontal: 9, paddingVertical: 6, borderRadius: 7, borderWidth: 1, borderColor: colors.green },
  confirmText: { fontFamily: font.heavy, color: colors.green, fontSize: 11 },
  confirmed: { fontFamily: font.heavy, color: colors.green, fontSize: 11 },
  empty: { minHeight: 180, alignItems: 'center', justifyContent: 'center' },
  emptyTitle: { fontFamily: font.heavy, color: colors.text, fontSize: 14 },
  emptyText: { fontFamily: font.regular, marginTop: 4, color: colors.muted, fontSize: 13 },
  busy: { ...StyleSheet.absoluteFill, borderRadius: 18, backgroundColor: 'rgba(5,8,11,0.45)', alignItems: 'center', justifyContent: 'center' },
});
