import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppState, FlatList, KeyboardAvoidingView, Modal, Platform, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import type { AuthSession } from '../domain/estiba';
import {
  blankGatehouseDraft, gatehouseDraftFromPayload, gatehousePayload, gatehousePendingKey,
  gatehouseStatus, parseGatehousePending,
  type GatehouseClient, type GatehouseDetail, type GatehouseDraft, type GatehousePage, type GatehousePending,
} from '../domain/gatehouse';
import { ApiError } from '../services/apiError';
import { createGatehouseApi } from '../services/gatehouseApi';
import {
  EntityCode, EstibaAlert, EstibaButton, EstibaEmpty, EstibaField, EstibaHeading,
  EstibaPanel, EstibaSignal,
} from '../components/ui/EstibaPrimitives';
import { estibaTokens } from '../theme/estibaTokens';

const t = { ...estibaTokens, color: { ...estibaTokens.color, ...estibaTokens.colorDark } };
const errorText = (error: unknown) => error instanceof Error ? error.message : 'No se pudo completar la operación.';
const chileTime = (value: string) => new Date(value).toLocaleString('es-CL', { timeZone: 'America/Santiago' });
type Notice = { title: string; detail: string; tone: 'success' | 'warning' | 'critical' };

export function GatehouseScreen({ auth, baseUrl, onLogout }: {
  auth: AuthSession; baseUrl: string; onLogout: () => void;
}) {
  const api = useMemo(() => createGatehouseApi(baseUrl, auth.token), [baseUrl, auth.token]);
  const key = useMemo(() => gatehousePendingKey(baseUrl, String(auth.usuario.id), auth.dispositivo.id),
    [baseUrl, auth.usuario.id, auth.dispositivo.id]);
  const canRead = auth.usuario.capacidades.puede_consultar_porteria === true;
  const canWrite = auth.usuario.capacidades.puede_registrar_porteria === true;
  const wide = useWindowDimensions().width >= 720;
  const [tab, setTab] = useState<'nuevo' | 'bitacora'>(canWrite ? 'nuevo' : 'bitacora');
  const [draft, setDraft] = useState<GatehouseDraft>(blankGatehouseDraft);
  const [clients, setClients] = useState<GatehouseClient[]>([]);
  const [catalogError, setCatalogError] = useState('');
  const [picker, setPicker] = useState(false);
  const [clientSearch, setClientSearch] = useState('');
  const [pending, setPending] = useState<GatehousePending | null>(null);
  const pendingRef = useRef<GatehousePending | null>(null);
  const [recovered, setRecovered] = useState(false);
  const [recoveryError, setRecoveryError] = useState('');
  const [busy, setBusy] = useState(false);
  const sending = useRef(false);
  const alive = useRef(true);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [page, setPage] = useState<GatehousePage | null>(null);
  const [listBusy, setListBusy] = useState(false);
  const [listError, setListError] = useState('');
  const listGeneration = useRef(0);
  const filters = useRef({ fecha: '', buscar: '' });
  const [date, setDate] = useState('');
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<GatehouseDetail | null>(null);
  const [detailBusy, setDetailBusy] = useState(false);
  const blocked = busy || pending !== null || !recovered;
  const visibleClients = useMemo(() => {
    const query = clientSearch.trim().toLocaleLowerCase();
    return query ? clients.filter((client) => client.nombre.toLocaleLowerCase().includes(query)) : clients;
  }, [clients, clientSearch]);

  const refreshCatalog = useCallback(async () => {
    if (!canRead) return;
    try {
      const response = await api.catalog();
      if (alive.current) { setClients(response.clientes); setCatalogError(''); }
    } catch (error) { if (alive.current) setCatalogError(errorText(error)); }
  }, [api, canRead]);

  const refreshList = useCallback(async (requestedPage = 1) => {
    if (!canRead) return;
    const generation = ++listGeneration.current;
    setListBusy(true);
    try {
      const response = await api.list({ ...filters.current, page: requestedPage });
      if (alive.current && generation === listGeneration.current) { setPage(response); setListError(''); }
    } catch (error) {
      if (alive.current && generation === listGeneration.current) setListError(errorText(error));
    } finally {
      if (alive.current && generation === listGeneration.current) setListBusy(false);
    }
  }, [api, canRead]);

  const recover = useCallback(async () => {
    setRecovered(false);
    try {
      const restored = parseGatehousePending(await AsyncStorage.getItem(key));
      if (!alive.current) return;
      pendingRef.current = restored;
      setPending(restored);
      if (restored) setDraft(gatehouseDraftFromPayload(restored.payload));
      setRecoveryError(''); setRecovered(true);
    } catch (error) { if (alive.current) setRecoveryError(errorText(error)); }
  }, [key]);

  useEffect(() => {
    alive.current = true;
    void recover(); void refreshCatalog(); void refreshList();
    const listener = AppState.addEventListener('change', (state) => {
      if (state === 'active') { void refreshList(); void refreshCatalog(); }
    });
    return () => { alive.current = false; listGeneration.current++; listener.remove(); };
  }, [recover, refreshCatalog, refreshList]);

  function edit<K extends keyof GatehouseDraft>(field: K, value: GatehouseDraft[K]) {
    if (!blocked) setDraft((previous) => ({ ...previous, [field]: value }));
  }

  async function submit() {
    if (sending.current || !canWrite || !recovered) return;
    const retrying = pendingRef.current !== null;
    sending.current = true; setBusy(true); setNotice(null);
    try {
      let attempt = pendingRef.current;
      if (!attempt) {
        attempt = { payload: gatehousePayload(draft, Crypto.randomUUID()) };
        await AsyncStorage.setItem(key, JSON.stringify(attempt));
        pendingRef.current = attempt;
        if (!alive.current) return;
        setPending(attempt);
      }
      const entry = await api.register(attempt.payload);
      try {
        await AsyncStorage.removeItem(key);
      } catch {
        if (alive.current) setNotice({ title: 'Ingreso confirmado: ' + entry.codigo,
          detail: 'No se pudo liberar el envío guardado en el equipo. Reintenta el mismo ingreso antes de capturar otro camión.', tone: 'warning' });
        return;
      }
      pendingRef.current = null;
      if (!alive.current) return;
      setPending(null); setDraft(blankGatehouseDraft());
      setNotice({ title: 'Ingreso confirmado: ' + entry.codigo,
        detail: entry.patente + ' · ' + entry.cliente_nombre + ' · ' + chileTime(entry.ingreso_at) + '. Romana ya puede consultar esta llegada.', tone: 'success' });
      void refreshList();
    } catch (error) {
      if (error instanceof ApiError && error.status === 422 && !retrying && pendingRef.current) {
        try {
          await AsyncStorage.removeItem(key);
          pendingRef.current = null;
          if (alive.current) setPending(null);
        } catch { /* Se conserva el intento hasta poder liberar la bandeja de forma segura. */ }
        void refreshCatalog();
      }
      if (alive.current) setNotice({ title: 'No se confirmó el ingreso', detail: errorText(error), tone: 'critical' });
    } finally {
      sending.current = false;
      if (alive.current) setBusy(false);
    }
  }

  async function showDetail(id: number) {
    setDetailBusy(true);
    try {
      const response = await api.detail(id);
      if (alive.current) setDetail(response);
    } catch (error) {
      if (alive.current) setNotice({ title: 'No se pudo abrir el ingreso', detail: errorText(error), tone: 'critical' });
    } finally { if (alive.current) setDetailBusy(false); }
  }

  function applyFilters() {
    if (date.trim() && !/^\d{4}-\d{2}-\d{2}$/.test(date.trim())) {
      setListError('Escribe la fecha como AAAA-MM-DD o déjala vacía para consultar hoy en Chile.');
      return;
    }
    filters.current = { fecha: date.trim(), buscar: search.trim() };
    void refreshList();
  }

  if (!canRead) return <View style={s.content}>
    <EstibaAlert title="Portería no está habilitada" detail="Solicita revisar el perfil y vuelve a iniciar sesión." tone="critical" />
    <EstibaButton label="Cerrar sesión" onPress={onLogout} />
  </View>;

  const selectedClient = clients.find((client) => client.id === draft.cliente_id);
  return <KeyboardAvoidingView style={s.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
    <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.content}>
      <View style={s.workspace}>
        <EstibaHeading title="Portería" eyebrow="FoliOS · LLEGADAS"
          description={auth.usuario.nombre}
          actions={<EstibaButton label="Cerrar sesión" variant="secondary" disabled={busy} onPress={onLogout} />} />
        <View style={s.actions}>
          {canWrite ? <EstibaButton label="Nuevo ingreso" variant={tab === 'nuevo' ? 'primary' : 'secondary'} onPress={() => setTab('nuevo')} /> : null}
          <EstibaButton label="Bitácora" variant={tab === 'bitacora' ? 'primary' : 'secondary'} onPress={() => setTab('bitacora')} />
        </View>
        {notice ? <EstibaAlert {...notice} live /> : null}
        {recoveryError ? <EstibaAlert title="Revisa el envío guardado" detail={recoveryError} tone="critical" live /> : null}
        {!recovered ? <EstibaButton label="Revisar envío guardado" variant="secondary" onPress={() => void recover()} /> : null}
        {pending ? <EstibaAlert title="Hay un ingreso pendiente de confirmar"
          detail="La captura está bloqueada. Reintenta el mismo ingreso para comprobar si quedó registrado; el número de intento se conserva incluso si cierras la aplicación." tone="warning" live /> : null}
        {tab === 'nuevo' && canWrite ? <>
          <EstibaPanel title="Cliente y llegada">
            <Text style={s.muted}>La fecha y hora se registran en el servidor al confirmar.</Text>
            {catalogError ? <EstibaAlert title="No se pudo actualizar el catálogo" detail={catalogError} tone="warning" /> : null}
            <View style={s.actions}>
              <EstibaButton label="Cliente del catálogo" variant={!draft.otro ? 'primary' : 'secondary'} disabled={blocked} onPress={() => edit('otro', false)} />
              <EstibaButton label="Otro" variant={draft.otro ? 'primary' : 'secondary'} disabled={blocked} onPress={() => edit('otro', true)} />
            </View>
            {draft.otro
              ? <EstibaField label="Cliente / servicio (Otro) *" value={draft.cliente_otro} maxLength={180} editable={!blocked} onChangeText={(value) => edit('cliente_otro', value)} />
              : <EstibaButton label={selectedClient?.nombre ?? (draft.cliente_id ? 'Cliente guardado en el envío' : 'Seleccionar cliente')}
                  accessibilityLabel="Seleccionar cliente" variant="secondary" disabled={blocked}
                  onPress={() => { setClientSearch(''); setPicker(true); }} />}
            <EstibaButton label="Actualizar clientes" variant="secondary" disabled={busy} onPress={() => void refreshCatalog()} />
            <Text style={s.body}>Motivo de llegada *</Text>
            <View style={s.actions}>
              {([['carga', 'Con carga'], ['vacio', 'Vacío'], ['servicio', 'Servicio']] as const).map(([value, label]) =>
                <EstibaButton key={value} label={label} variant={draft.motivo === value ? 'primary' : 'secondary'} disabled={blocked} onPress={() => edit('motivo', value)} />)}
            </View>
          </EstibaPanel>
          <EstibaPanel title="Transporte y conductor">
            <View style={[s.grid, !wide && s.gridNarrow]}>
              <View style={[s.cell, !wide && s.cellNarrow]}><EstibaField label="Patente *" value={draft.patente} autoCapitalize="characters" maxLength={16} editable={!blocked} onChangeText={(value) => edit('patente', value)} /></View>
              <View style={[s.cell, !wide && s.cellNarrow]}><EstibaField label="Patente del carro" value={draft.patente_carro} autoCapitalize="characters" maxLength={16} editable={!blocked} onChangeText={(value) => edit('patente_carro', value)} /></View>
              <View style={[s.cell, !wide && s.cellNarrow]}><EstibaField label="Tipo de camión *" value={draft.tipo_camion} maxLength={80} editable={!blocked} onChangeText={(value) => edit('tipo_camion', value)} /></View>
              <View style={[s.cell, !wide && s.cellNarrow]}><EstibaField label="Conductor *" value={draft.nombre_conductor} maxLength={180} editable={!blocked} onChangeText={(value) => edit('nombre_conductor', value)} /></View>
            </View>
          </EstibaPanel>
          <EstibaPanel title="Guías del mismo cliente">
            <Text style={s.muted}>{draft.motivo === 'carga' ? 'Agrega al menos una guía. Conserva sus ceros iniciales.' : 'Vacíos y servicios pueden registrarse sin guía.'}</Text>
            {draft.guias.map((guide, index) => <View key={index} style={s.guide}>
              <View style={s.grow}><EstibaField label={'Guía ' + (index + 1)} value={guide} maxLength={50} autoCapitalize="none" editable={!blocked}
                onChangeText={(value) => edit('guias', draft.guias.map((current, position) => position === index ? value : current))} /></View>
              <EstibaButton label="Quitar" accessibilityLabel={'Quitar guía ' + (index + 1)} variant="secondary" disabled={blocked}
                onPress={() => edit('guias', draft.guias.filter((_, position) => position !== index))} />
            </View>)}
            <EstibaButton label="Agregar guía" variant="secondary" disabled={blocked || draft.guias.length >= 30} onPress={() => edit('guias', [...draft.guias, ''])} />
          </EstibaPanel>
          <EstibaPanel title="Cantidad y observación (opcionales)">
            <EstibaField label="Cantidad" value={draft.cantidad} keyboardType="number-pad" maxLength={7} editable={!blocked} onChangeText={(value) => edit('cantidad', value)} />
            <View style={s.actions}>
              {([['', 'Sin unidad'], ['bins', 'Bins'], ['bultos', 'Bultos']] as const).map(([value, label]) =>
                <EstibaButton key={value || 'ninguna'} label={label} variant={draft.unidad === value ? 'primary' : 'secondary'} disabled={blocked} onPress={() => edit('unidad', value)} />)}
            </View>
            <EstibaField label="Observación" multiline value={draft.observacion} maxLength={500} editable={!blocked} onChangeText={(value) => edit('observacion', value)} />
          </EstibaPanel>
          <EstibaButton label={pending ? 'Reintentar el mismo ingreso' : 'Registrar llegada'}
            busy={busy} disabled={!recovered} variant="confirm" onPress={() => void submit()} />
        </> : null}
        {tab === 'bitacora' ? <EstibaPanel title="Bitácora de llegadas">
          <EstibaField label="Fecha en Chile (AAAA-MM-DD)" placeholder="Vacío: hoy en Chile" value={date} onChangeText={setDate} maxLength={10} />
          <EstibaField label="Buscar patente, cliente, conductor o guía exacta" value={search} maxLength={100} onChangeText={setSearch} />
          <EstibaButton label="Aplicar filtros" variant="secondary" busy={listBusy} onPress={applyFilters} />
          <EstibaButton label="Ver hoy" variant="secondary" disabled={listBusy} onPress={() => {
            filters.current = { fecha: '', buscar: '' }; setDate(''); setSearch(''); void refreshList();
          }} />
          {listError ? <EstibaAlert title="Bitácora sin actualizar" detail={listError} tone="warning" /> : null}
          {page ? <Text style={s.muted}>{page.fecha + ' · ' + page.total + ' ingresos · página ' + page.pagina + ' de ' + page.ultima_pagina}</Text> : null}
          {!page && listBusy ? <Text style={s.muted}>Cargando bitácora…</Text> : null}
          {page?.data.map((entry) => <View key={entry.id} style={s.entry}>
            <EntityCode value={entry.codigo} />
            <Text style={s.body}>{entry.patente + ' · ' + entry.cliente_nombre}</Text>
            <Text style={s.muted}>{chileTime(entry.ingreso_at) + ' · ' + entry.nombre_conductor}</Text>
            <Text style={s.muted}>{entry.guias.length ? 'Guías: ' + entry.guias.slice(0, 3).join(', ') + (entry.guias.length > 3 ? '…' : '') : 'Sin guía'}</Text>
            <EstibaSignal label={gatehouseStatus(entry)} tone={entry.anulado_at ? 'critical' : entry.revisado_at ? 'success' : 'warning'} />
            <EstibaButton label={'Ver ' + entry.codigo} variant="secondary" disabled={detailBusy} onPress={() => void showDetail(entry.id)} />
          </View>)}
          {page && !page.data.length && !listError ? <EstibaEmpty title="Sin ingresos para esta consulta" description="Prueba otra fecha o búsqueda." /> : null}
          <View style={s.actions}>
            <EstibaButton label="Anterior" variant="secondary" disabled={listBusy || !page || page.pagina <= 1} onPress={() => void refreshList((page?.pagina ?? 1) - 1)} />
            <EstibaButton label="Siguiente" variant="secondary" disabled={listBusy || !page || page.pagina >= page.ultima_pagina} onPress={() => void refreshList((page?.pagina ?? 1) + 1)} />
          </View>
        </EstibaPanel> : null}
      </View>
    </ScrollView>
    <Modal visible={picker} transparent animationType="fade" onRequestClose={() => setPicker(false)}>
      <KeyboardAvoidingView style={s.overlay} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={s.dialog} accessibilityViewIsModal>
          <EstibaHeading title="Cliente del catálogo" />
          <EstibaField label="Buscar cliente" value={clientSearch} onChangeText={setClientSearch} autoCapitalize="none" />
          <FlatList data={visibleClients} keyExtractor={(item) => item.id} keyboardShouldPersistTaps="handled"
            initialNumToRender={12} maxToRenderPerBatch={12} windowSize={5}
            renderItem={({ item }) => <EstibaButton label={item.nombre} variant="secondary" onPress={() => { edit('cliente_id', item.id); setPicker(false); }} />}
            ListEmptyComponent={<Text style={s.muted}>No hay clientes para esta búsqueda. Actualiza el catálogo o utiliza Otro.</Text>} />
          <EstibaButton label="Cerrar clientes" variant="secondary" onPress={() => setPicker(false)} />
        </View>
      </KeyboardAvoidingView>
    </Modal>
    <Modal visible={detail !== null} transparent animationType="fade" onRequestClose={() => setDetail(null)}>
      <View style={s.overlay}>
        <View style={s.dialog} accessibilityViewIsModal>
          <ScrollView contentContainerStyle={s.detailContent}>
            {detail ? <>
              <EstibaHeading title={detail.data.codigo} />
              <EstibaSignal label={gatehouseStatus(detail.data)} tone={detail.data.anulado_at ? 'critical' : 'info'} />
              <Text style={s.body}>{detail.data.cliente_nombre + ' · ' + detail.data.patente}</Text>
              <Text style={s.body}>{'Motivo: ' + ({ carga: 'Con carga', vacio: 'Vacío', servicio: 'Servicio' }[detail.data.motivo])}</Text>
              <Text style={s.body}>{'Conductor: ' + detail.data.nombre_conductor}</Text>
              <Text style={s.body}>{'Camión: ' + detail.data.tipo_camion + ' · Carro: ' + (detail.data.patente_carro ?? 'Sin registro')}</Text>
              <Text style={s.body}>{'Llegada: ' + chileTime(detail.data.ingreso_at)}</Text>
              <Text style={s.body}>{'Guardia: ' + detail.data.creado_por_nombre}</Text>
              <Text style={s.body}>{'Guías: ' + (detail.data.guias.join(', ') || 'Sin guía')}</Text>
              <Text style={s.body}>{detail.data.cantidad === null ? 'Cantidad: sin registro' : detail.data.cantidad + ' ' + detail.data.unidad}</Text>
              {detail.data.observacion ? <Text style={s.body}>{'Observación: ' + detail.data.observacion}</Text> : null}
              {detail.data.revisado_at ? <Text style={s.body}>{'Revisó: ' + detail.data.revisado_por_nombre + ' · ' + chileTime(detail.data.revisado_at)}</Text> : null}
              {detail.data.anulado_at ? <EstibaAlert title="Ingreso anulado" detail={detail.data.motivo_anulacion ?? ''} tone="critical" /> : null}
              <Text accessibilityRole="header" style={s.body}>Historial</Text>
              {detail.eventos.map((event, index) => <Text key={index} style={s.muted}>{event.accion + ' · ' + event.user_nombre + ' · ' + chileTime(event.created_at)}</Text>)}
            </> : null}
          </ScrollView>
          <EstibaButton label="Cerrar detalle" variant="secondary" onPress={() => setDetail(null)} />
        </View>
      </View>
    </Modal>
  </KeyboardAvoidingView>;
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: t.color.canvas },
  content: { padding: t.space[4], flexGrow: 1 },
  workspace: { width: '100%', maxWidth: 960, alignSelf: 'center', gap: t.space[4] },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: t.space[3] },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: t.space[4] },
  gridNarrow: { flexDirection: 'column' },
  cell: { flexGrow: 1, flexBasis: '44%', minWidth: 0 },
  cellNarrow: { flexGrow: 0, flexBasis: 'auto', width: '100%' },
  grow: { flex: 1, minWidth: 0 },
  guide: { flexDirection: 'row', alignItems: 'flex-end', gap: t.space[2] },
  body: { color: t.color.text, fontSize: t.fontSize.body, lineHeight: t.fontSize.body * t.lineHeight.body },
  muted: { color: t.color.muted, fontSize: t.fontSize.small, lineHeight: t.fontSize.small * t.lineHeight.body },
  entry: { gap: t.space[2], paddingVertical: t.space[3], borderBottomWidth: 1, borderColor: t.color.border },
  overlay: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: t.space[4], backgroundColor: 'rgba(0,0,0,0.7)' },
  dialog: { width: '100%', maxWidth: 640, height: '85%', padding: t.space[4], gap: t.space[3], borderRadius: t.radius.panel, backgroundColor: t.color.surface },
  detailContent: { gap: t.space[3], paddingBottom: t.space[4] },
});
