import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import * as ImagePicker from 'expo-image-picker';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Image, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { FieldHeader } from '../components/field/FieldHeader';
import { EntityCode, EstibaAlert, EstibaButton, EstibaField, EstibaMetric, EstibaPanel, EstibaSignal } from '../components/ui/EstibaPrimitives';
import {
  HEADER_GROUPS, OPTION_LABELS, PALLET_ORIENTATIONS, PALLET_STATE_LABELS, checkNotApplicable, orientationLabel, consolidationStorageKey, declaredPallets, fieldValue, fillNotApplicable,
  formatTemperature, hasDraftChanges, nextFreePosition, palletsForList, parseBoxes, parsePosition, parseTemperature, positionOccupant, sectionChanges,
  type ConsolidationCatalog, type ConsolidationControl, type ConsolidationDraft, type ConsolidationField, type ConsolidationLoad, type ConsolidationPallet,
  type ConsolidationSection,
} from '../domain/consolidation';
import type { AuthSession } from '../domain/estiba';
import { ApiError } from '../services/apiError';
import { createConsolidationApi, type PositionPayload } from '../services/consolidationApi';
import { estibaTokens } from '../theme/estibaTokens';
import { font } from '../theme/typography';

const colors = { ...estibaTokens.color, ...estibaTokens.colorDark };
const signal = estibaTokens.signalDark;
type Tab = 'pallets' | 'encabezado' | 'inspeccion' | 'cierre' | 'fotos';
const TABS: { key: Tab; label: string }[] = [
  { key: 'pallets', label: 'Pallets' }, { key: 'encabezado', label: 'Encabezado' }, { key: 'inspeccion', label: 'Inspección' },
  { key: 'cierre', label: 'Cierre' }, { key: 'fotos', label: 'Fotos' },
];
type Pending = { label: string; run: () => Promise<ConsolidationControl | null> };
const message = (e: unknown) => (e instanceof Error ? e.message : 'No se pudo completar el registro.');
const definitive = (e: unknown) => e instanceof ApiError && [403, 404, 409, 422].includes(e.status);

/**
 * Consolidación en el andén: llena la planilla POEMP R-24 de una carga. Leer un pallet que
 * espera en el andén lo sube al camión (igual que el camarero) y guarda su posición.
 */
export function ConsolidationScreen({ auth, baseUrl, onLogout }: { auth: AuthSession; baseUrl: string; onLogout: () => void }) {
  const api = useMemo(() => createConsolidationApi(baseUrl, auth.token), [baseUrl, auth.token]);
  const canRegister = auth.usuario.capacidades.puede_registrar_control_despacho === true;
  const [catalog, setCatalog] = useState<ConsolidationCatalog | null>(null);
  const [loads, setLoads] = useState<ConsolidationLoad[]>([]);
  const [control, setControl] = useState<ConsolidationControl | null>(null);
  const [tab, setTab] = useState<Tab>('pallets');
  const [draft, setDraft] = useState<ConsolidationDraft>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [pending, setPending] = useState<Pending | null>(null);
  const working = useRef(false);
  const alive = useRef(true);
  const blocked = busy || pending !== null;
  const editable = canRegister && control?.estado === 'borrador';
  const draftKey = control ? consolidationStorageKey(baseUrl, String(auth.usuario.id), control.id) : null;

  // Lectura de pallets.
  const folioInput = useRef<TextInput>(null);
  const [folio, setFolio] = useState('');
  const [position, setPosition] = useState('1');
  const [code, setCode] = useState('');
  const [temperature, setTemperature] = useState('');
  const [boxes, setBoxes] = useState('');
  const [sharePrompt, setSharePrompt] = useState<{ occupant: string; payload: PositionPayload } | null>(null);
  const [removeTarget, setRemoveTarget] = useState<ConsolidationPallet | null>(null);
  const [confirmClose, setConfirmClose] = useState(false);

  useEffect(() => {
    alive.current = true;
    return () => { alive.current = false; };
  }, []);

  const run = useCallback(async (action: () => Promise<void>) => {
    if (working.current) return;
    working.current = true; setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { if (alive.current) setError(message(e)); }
    finally { working.current = false; if (alive.current) setBusy(false); }
  }, []);

  /** Envía una escritura idempotente; si no hay respuesta queda pendiente con el mismo operacion_id. */
  async function send(label: string, operation: () => Promise<ConsolidationControl | null>, done?: (result: ConsolidationControl) => void) {
    const attempt: Pending = { label, run: operation };
    try {
      const result = await operation();
      if (!alive.current) return;
      setPending(null);
      if (result) { setControl(result); done?.(result); }
    } catch (e) {
      if (!alive.current) return;
      if (e instanceof ApiError && e.status === 409 && control) {
        // Otro equipo guardó antes: se carga lo vigente y se conserva el borrador local.
        setPending(null);
        setControl(await api.detail(control.id));
        throw new Error('Otro equipo guardó cambios en esta planilla. Revisa y vuelve a guardar.');
      }
      setPending(definitive(e) ? null : attempt);
      throw e;
    }
  }

  async function loadList() {
    const [catalogResult, loadResult] = await Promise.all([catalog ? Promise.resolve(catalog) : api.catalog(), api.loads()]);
    if (!alive.current) return;
    setCatalog(catalogResult); setLoads(loadResult);
  }
  useEffect(() => { void run(loadList); }, [api]);

  // Borrador local por planilla: sobrevive a un reinicio de la PDA.
  useEffect(() => {
    if (!draftKey) return;
    let active = true;
    void AsyncStorage.getItem(draftKey).then((raw) => {
      if (active && raw) { setDraft(JSON.parse(raw) as ConsolidationDraft); setNotice('Se recuperaron cambios sin guardar de este equipo.'); }
    }).catch(() => undefined);
    return () => { active = false; };
  }, [draftKey]);
  useEffect(() => {
    if (!draftKey) return;
    const timer = setTimeout(() => {
      const write = hasDraftChanges(draft) ? AsyncStorage.setItem(draftKey, JSON.stringify(draft)) : AsyncStorage.removeItem(draftKey);
      void write.catch(() => setError('No se pudo guardar el borrador en este equipo.'));
    }, 400);
    return () => clearTimeout(timer);
  }, [draft, draftKey]);

  useEffect(() => {
    if (control) setPosition((current) => (current === '' || control.pallets.some((p) => p.posicion?.posicion === Number(current)) ? String(nextFreePosition(control.pallets, catalog?.posicion_maxima)) : current));
  }, [control?.pallets]);

  async function openLoad(load: ConsolidationLoad) {
    const operation = Crypto.randomUUID();
    const result = load.control ? await api.detail(load.control.id) : await api.start(load.id, operation);
    if (!alive.current) return;
    setControl(result); setDraft({}); setTab('pallets'); setFolio(''); setCode(''); setTemperature(''); setBoxes('');
    setPosition(String(nextFreePosition(result.pallets, catalog?.posicion_maxima)));
    setTimeout(() => folioInput.current?.focus(), 200);
  }

  function backToList() {
    setControl(null); setDraft({}); setPending(null);
    void run(loadList);
  }

  function selectFolio(value: string) {
    setFolio(value);
    const known = control?.pallets.find((p) => p.numero_folio.toUpperCase() === value.trim().toUpperCase());
    if (!known) return;
    setBoxes(String(known.posicion?.cajas ?? known.cajas_folio ?? ''));
    if (known.posicion) {
      setPosition(String(known.posicion.posicion));
      setCode(known.posicion.codigo_pe ?? '');
      setTemperature(known.posicion.temperatura === null ? '' : String(known.posicion.temperatura).replace('.', ','));
    }
  }

  async function registerPosition(share = false, prepared?: PositionPayload) {
    if (!control || !catalog) return;
    const payload: PositionPayload = prepared ?? {
      operacion_id: Crypto.randomUUID(),
      numero_folio: folio.trim().toUpperCase(),
      posicion: parsePosition(position, catalog.posicion_maxima),
      codigo_pe: code || null,
      cajas: parseBoxes(boxes),
      temperatura: parseTemperature(temperature),
      compartir_posicion: false,
      generado_dispositivo_at: new Date().toISOString(),
    };
    if (!payload.numero_folio) throw new Error('Lee o digita el folio que subió.');
    const occupant = positionOccupant(control.pallets, payload.posicion, payload.numero_folio);
    if (occupant && !share) { setSharePrompt({ occupant, payload }); return; }
    const final = { ...payload, compartir_posicion: share };
    const wasWaiting = control.pallets.find((p) => p.numero_folio.toUpperCase() === final.numero_folio)?.estado_carga === 'en_anden';
    await send(`Pallet ${final.numero_folio}`, () => api.position(control.id, final), (result) => {
      // La orientación se mantiene: los pallets de una carga suelen ir todos igual.
      setFolio(''); setTemperature(''); setBoxes('');
      setPosition(String(nextFreePosition(result.pallets, catalog.posicion_maxima)));
      setNotice(`${final.numero_folio} en posición ${final.posicion}${wasWaiting ? ': quedó cargado en el camión' : ''}.`);
      setTimeout(() => folioInput.current?.focus(), 100);
    });
  }

  function setValue(section: ConsolidationSection, key: string, value: string) {
    if (!editable || blocked) return;
    setDraft((old) => ({ ...old, [section]: { ...(old[section] ?? {}), [key]: value } }));
  }
  const value = (section: ConsolidationSection, key: string) => (control ? fieldValue(control, draft, section, key) : '');

  async function saveSection(section: ConsolidationSection) {
    if (!control || !catalog) return;
    const fields = section === 'encabezado' ? catalog.encabezado : section === 'cierre' ? catalog.cierre : catalog.inspeccion.map((c) => ({ clave: c.clave }));
    let changes = sectionChanges(control, draft, section, fields);
    if (section === 'inspeccion') changes = { ...fillNotApplicable(catalog.inspeccion, value('encabezado', 'tipo_camion'), { ...control.inspeccion, ...changes }), ...changes };
    if (Object.keys(changes).length === 0) { setDraft((old) => ({ ...old, [section]: {} })); setNotice('No hay cambios que guardar.'); return; }
    const operation = Crypto.randomUUID();
    const version = control.version;
    await send(`Guardar ${section}`, () => api.save(control.id, { operacion_id: operation, version, [section]: changes }), () => {
      setDraft((old) => ({ ...old, [section]: {} }));
      setNotice('Cambios guardados.');
    });
  }

  async function closeSheet() {
    if (!control) return;
    if (hasDraftChanges(draft)) throw new Error('Guarda o descarta los cambios pendientes antes de cerrar la planilla.');
    const operation = Crypto.randomUUID();
    const version = control.version;
    await send('Cerrar planilla', () => api.close(control.id, operation, version), () => setNotice('Planilla R-24 cerrada. Ya puedes compartir el Excel.'));
  }

  async function removePosition(target: ConsolidationPallet) {
    if (!control || !target.posicion) return;
    const operation = Crypto.randomUUID();
    const positionId = target.posicion.id;
    await send(`Quitar ${target.numero_folio}`, () => api.removePosition(control.id, positionId, operation), () => setNotice(`${target.numero_folio} quedó fuera de la planilla; sigue cargado en el camión.`));
  }

  if (auth.usuario.capacidades.puede_consultar_control_despacho !== true) {
    return <View style={s.root}><View style={s.content}>
      <EstibaAlert title="Consolidación no está habilitada en este perfil." tone="warning" />
      <EstibaButton label="Cerrar sesión" onPress={onLogout} />
    </View></View>;
  }

  const chips = (options: string[], current: string, select: (v: string) => void, disabled = !editable) => <View style={s.chips}>
    {options.map((option) => <Pressable key={option} accessibilityRole="radio" accessibilityState={{ selected: current === option, disabled: disabled || blocked }}
      disabled={disabled || blocked} onPress={() => select(current === option ? '' : option)} style={[s.chip, current === option && s.chipOn, (disabled || blocked) && s.dim]}>
      <Text style={[s.chipText, current === option && s.chipTextOn]}>{OPTION_LABELS[option] ?? option}</Text>
    </Pressable>)}
  </View>;

  const field = (section: 'encabezado' | 'cierre', definition: ConsolidationField) => {
    const current = value(section, definition.clave);
    const suggested = control?.sugerido?.[section]?.[definition.clave];
    const hint = suggested !== undefined && suggested !== null && String(suggested) !== current ? `FoliOS sugiere: ${OPTION_LABELS[String(suggested)] ?? suggested}` : undefined;
    if (definition.tipo === 'opcion') {
      return <View key={definition.clave} style={s.gap}><Text style={s.label}>{definition.etiqueta}</Text>{chips(definition.opciones ?? [], current, (v) => setValue(section, definition.clave, v))}</View>;
    }
    return <EstibaField key={definition.clave} label={definition.etiqueta} value={current} hint={hint} editable={editable && !blocked}
      placeholder={definition.tipo === 'fecha' ? 'AAAA-MM-DD' : definition.tipo === 'hora' ? 'HH:MM' : undefined}
      keyboardType={definition.tipo === 'entero' ? 'number-pad' : 'default'} multiline={definition.tipo === 'nota'} maxLength={definition.tipo === 'nota' ? 1000 : 150}
      autoCapitalize={['patente_camion', 'patente_carro', 'contenedor', 'sello_planta', 'sello_naviera'].includes(definition.clave) ? 'characters' : 'sentences'}
      onChangeText={(v) => setValue(section, definition.clave, v)} />;
  };

  const sectionDirty = (section: ConsolidationSection) => Object.keys(draft[section] ?? {}).length > 0;
  const saveBar = (section: ConsolidationSection) => editable ? <View style={s.row}>
    <EstibaButton label="Guardar" disabled={blocked || !sectionDirty(section)} busy={busy} onPress={() => void run(() => saveSection(section))} style={s.grow} />
    {sectionDirty(section) ? <EstibaButton label="Descartar" variant="secondary" disabled={blocked} onPress={() => setDraft((old) => ({ ...old, [section]: {} }))} /> : null}
  </View> : null;

  return <KeyboardAvoidingView style={s.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
    <ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
      <FieldHeader area="frigorifico" auth={auth} busy={busy} title="Consolidación" subtitle={control ? `R-24 · carga ${control.carga.codigo}` : 'Planilla de control de despacho R-24'} onLogout={onLogout} />
      {error ? <EstibaAlert title="No se pudo completar" detail={error} tone="critical" live /> : null}
      {notice ? <EstibaAlert title={notice} tone="success" live /> : null}
      {pending ? <EstibaPanel title="Envío sin confirmar">
        <Text style={s.body}>{pending.label}: el servidor no respondió. Reintenta el mismo envío para conocer su resultado.</Text>
        <EstibaButton label="Reintentar envío" busy={busy} onPress={() => void run(() => send(pending.label, pending.run))} />
      </EstibaPanel> : null}

      {!control ? <EstibaPanel title="Cargas en andén" actions={<EstibaButton label="Actualizar" variant="secondary" disabled={busy} onPress={() => void run(loadList)} />}>
        {loads.length === 0 ? <Text style={s.body}>No hay cargas en el andén. La planilla se abre cuando llega el camión o el primer pallet.</Text> : null}
        {loads.map((load) => <Pressable key={load.id} accessibilityRole="button" disabled={blocked || (!load.control && !canRegister)}
          onPress={() => void run(() => openLoad(load))} style={[s.card, (!load.control && !canRegister) && s.dim]}>
          <View style={s.between}><EntityCode value={load.codigo} large />
            <EstibaSignal label={load.control ? (load.control.estado === 'cerrado' ? 'R-24 cerrada' : 'R-24 en curso') : 'Sin planilla'} tone={load.control?.estado === 'cerrado' ? 'success' : load.control ? 'warning' : 'neutral'} /></View>
          <Text style={s.body}>{[load.exportadora, load.contenedor, load.anden ? `Andén ${load.anden}` : null, load.patente].filter(Boolean).join(' · ') || 'Sin datos de embarque'}</Text>
          <Text style={s.muted}>{load.cargados}/{load.pallets} cargados · {load.en_anden} en andén{load.control ? ` · ${load.control.posicionados} con posición` : ''}</Text>
          <Text style={s.link}>{load.control ? 'Abrir planilla' : canRegister ? 'Iniciar planilla R-24' : 'Sin planilla'}</Text>
        </Pressable>)}
      </EstibaPanel> : null}

      {control && catalog ? <>
        <View style={s.between}>
          <EstibaButton label="← Cargas" variant="secondary" disabled={blocked} onPress={backToList} />
          <EstibaSignal label={control.estado === 'cerrado' ? `Cerrada · ${control.cerrado_por ?? ''}` : 'En curso'} tone={control.estado === 'cerrado' ? 'success' : 'warning'} />
        </View>
        <EstibaMetric label="Pallets cargados" value={control.totales.cargados} total={control.totales.pallets}
          detail={`${control.totales.posicionados} con posición · ${control.totales.en_anden} en andén · ${control.totales.en_camara} en cámara`} tone="info" />
        <View style={s.tabs} accessibilityRole="tablist">
          {TABS.map((item) => <Pressable key={item.key} accessibilityRole="tab" accessibilityState={{ selected: tab === item.key }} onPress={() => setTab(item.key)} style={[s.tab, tab === item.key && s.tabOn]}>
            <Text style={[s.tabText, tab === item.key && s.tabTextOn]}>{item.label}{(item.key === 'encabezado' || item.key === 'inspeccion' || item.key === 'cierre') && sectionDirty(item.key) ? ' •' : ''}</Text>
          </Pressable>)}
        </View>

        {tab === 'pallets' ? <>
          {editable ? <EstibaPanel title="Pallet que sube">
            <Text style={s.label}>Folio</Text>
            <TextInput ref={folioInput} accessibilityLabel="Folio que sube al camión" value={folio} editable={!blocked} autoCapitalize="characters" autoCorrect={false}
              blurOnSubmit={false} returnKeyType="next" placeholder="Lee o digita el folio" placeholderTextColor={colors.muted} style={s.scan}
              onChangeText={selectFolio} />
            <View style={s.row}>
              <EstibaField label="Posición" value={position} editable={!blocked} keyboardType="number-pad" maxLength={2} onChangeText={setPosition} style={s.small} />
              <EstibaField label="T° pulpa" value={temperature} editable={!blocked} keyboardType="numbers-and-punctuation" placeholder="°C" maxLength={6} onChangeText={setTemperature} style={s.small} />
              <EstibaField label="Cajas" value={boxes} editable={!blocked} keyboardType="number-pad" maxLength={4} onChangeText={setBoxes} style={s.small} />
            </View>
            <View style={s.gap}>
              <Text style={s.label}>P/E · orientación del pallet</Text>
              <View style={s.chips}>
                {PALLET_ORIENTATIONS.map((option) => <Pressable key={option.code} accessibilityRole="radio" accessibilityState={{ selected: code === option.code, disabled: blocked }}
                  disabled={blocked} onPress={() => setCode(code === option.code ? '' : option.code)} style={[s.chip, s.grow, code === option.code && s.chipOn, blocked && s.dim]}>
                  <Text style={[s.chipText, code === option.code && s.chipTextOn]}>{option.label}</Text>
                  <Text style={[s.chipHint, code === option.code && s.chipTextOn]}>{option.hint}</Text>
                </Pressable>)}
              </View>
            </View>
            <EstibaButton label="Subió · registrar posición" variant="confirm" busy={busy} disabled={blocked || !folio.trim()} onPress={() => void run(() => registerPosition())} />
            <Text style={s.muted}>Un pallet que espera en el andén queda cargado en el camión, igual que cuando lo lee el camarero. Si ya estaba cargado, solo se guarda su posición.</Text>
          </EstibaPanel> : null}
          <EstibaPanel title={`Pallets de la carga (${control.totales.pallets})`}>
            {palletsForList(control.pallets).map((pallet) => <Pressable key={pallet.asignacion_id} disabled={!editable || blocked} accessibilityRole="button"
              accessibilityHint="Carga el pallet en el formulario para corregir su posición" onPress={() => selectFolio(pallet.numero_folio)}
              onLongPress={() => pallet.posicion && editable ? setRemoveTarget(pallet) : undefined} style={[s.pallet, pallet.posicion ? s.palletPlaced : null]}>
              <Text style={s.positionBadge}>{pallet.posicion ? pallet.posicion.posicion : '—'}</Text>
              <View style={s.grow}>
                <EntityCode value={pallet.numero_folio} />
                <Text style={s.muted}>{[PALLET_STATE_LABELS[pallet.estado_carga] ?? pallet.estado_carga, pallet.especie, `${pallet.posicion?.cajas ?? pallet.cajas_folio} cajas`,
                  orientationLabel(pallet.posicion?.codigo_pe), pallet.posicion ? formatTemperature(pallet.posicion.temperatura) : null].filter(Boolean).join(' · ')}</Text>
              </View>
            </Pressable>)}
            {editable ? <Text style={s.muted}>Toca un pallet para corregirlo; mantén presionado para quitarlo de la planilla.</Text> : null}
          </EstibaPanel>
        </> : null}

        {tab === 'encabezado' ? <>
          {HEADER_GROUPS.map((group) => <EstibaPanel key={group.key} title={group.title}>
            {catalog.encabezado.filter((f) => f.grupo === group.key && (f.clave !== 'frigorifico' || value('encabezado', 'frigorifico_tipo') !== '')).map((f) => field('encabezado', f))}
          </EstibaPanel>)}
          {saveBar('encabezado')}
        </> : null}

        {tab === 'inspeccion' ? <EstibaPanel title="Inspección visual thermo / contenedor">
          {!value('encabezado', 'tipo_camion') ? <EstibaAlert title="Indica en Encabezado si es contenedor o thermo." detail="Los puntos del otro tipo se marcan «no aplica» al guardar." tone="info" /> : null}
          {catalog.inspeccion.map((check) => <View key={check.clave} style={s.gap}>
            <Text style={s.label}>{check.etiqueta}{checkNotApplicable(check, value('encabezado', 'tipo_camion')) ? ' · no aplica' : ''}</Text>
            {chips(['si', 'no', 'na'], value('inspeccion', check.clave), (v) => setValue('inspeccion', check.clave, v))}
          </View>)}
          {saveBar('inspeccion')}
        </EstibaPanel> : null}

        {tab === 'cierre' ? <>
          <EstibaPanel title="Totales y etiquetado">
            <Text style={s.body}>Cajas totales PCD (planilla): {control.totales.cajas_pcd}</Text>
            {catalog.cierre.filter((f) => !f.clave.startsWith('pallets_') && !['responsable', 'jefe_seccion'].includes(f.clave)).map((f) => field('cierre', f))}
          </EstibaPanel>
          <EstibaPanel title="Tipo y medida de pallets">
            {catalog.cierre.filter((f) => f.clave.startsWith('pallets_')).map((f) => field('cierre', f))}
            <Text style={s.body}>Total declarado: {declaredPallets((key) => value('cierre', key))} · con posición: {control.totales.posicionados}</Text>
          </EstibaPanel>
          <EstibaPanel title="Responsables">
            {catalog.cierre.filter((f) => ['responsable', 'jefe_seccion'].includes(f.clave)).map((f) => field('cierre', f))}
            <Text style={s.muted}>Si no indicas responsable, la planilla queda a nombre de quien la cierra.</Text>
          </EstibaPanel>
          {saveBar('cierre')}
          {control.estado === 'borrador' && control.faltantes_cierre.length ? <EstibaAlert title="Para cerrar la planilla falta" detail={control.faltantes_cierre.join('\n')} tone="warning" /> : null}
          {editable ? <EstibaButton label="Cerrar planilla R-24" variant="confirm" disabled={blocked || control.faltantes_cierre.length > 0 || hasDraftChanges(draft)} onPress={() => setConfirmClose(true)} /> : null}
          <EstibaButton label="Compartir Excel R-24" variant="secondary" disabled={blocked} onPress={() => void run(() => api.shareSheet(control.id, control.carga.codigo))} />
        </> : null}

        {tab === 'fotos' ? <PhotosPanel api={api} control={control} catalog={catalog} editable={editable} onChange={setControl} onError={setError} /> : null}
      </> : null}
    </ScrollView>

    <Modal transparent animationType="fade" visible={sharePrompt !== null} onRequestClose={() => setSharePrompt(null)}>
      <View style={s.backdrop}><View style={s.modal}>
        <Text style={s.modalTitle}>Posición {sharePrompt?.payload.posicion} ocupada</Text>
        <Text style={s.body}>Ya está el folio {sharePrompt?.occupant}. ¿Ambos pallets comparten la posición?</Text>
        <View style={s.row}>
          <EstibaButton label="Cambiar posición" variant="secondary" onPress={() => setSharePrompt(null)} style={s.grow} />
          <EstibaButton label="Comparten" onPress={() => { const prompt = sharePrompt; setSharePrompt(null); if (prompt) void run(() => registerPosition(true, prompt.payload)); }} style={s.grow} />
        </View>
      </View></View>
    </Modal>
    <Modal transparent animationType="fade" visible={removeTarget !== null} onRequestClose={() => setRemoveTarget(null)}>
      <View style={s.backdrop}><View style={s.modal}>
        <Text style={s.modalTitle}>Quitar {removeTarget?.numero_folio}</Text>
        <Text style={s.body}>Sale de la planilla R-24 pero sigue cargado en el camión. Para bajarlo del camión, el camarero lo registra con motivo.</Text>
        <View style={s.row}>
          <EstibaButton label="Cancelar" variant="secondary" onPress={() => setRemoveTarget(null)} style={s.grow} />
          <EstibaButton label="Quitar" variant="critical" onPress={() => { const target = removeTarget; setRemoveTarget(null); if (target) void run(() => removePosition(target)); }} style={s.grow} />
        </View>
      </View></View>
    </Modal>
    <Modal transparent animationType="fade" visible={confirmClose} onRequestClose={() => setConfirmClose(false)}>
      <View style={s.backdrop}><View style={s.modal}>
        <Text style={s.modalTitle}>Cerrar planilla R-24</Text>
        <Text style={s.body}>Queda firmada con {control?.totales.posicionados ?? 0} pallets y ya no se edita desde la PDA. Supervisión puede reabrirla con un motivo.</Text>
        <View style={s.row}>
          <EstibaButton label="Volver" variant="secondary" onPress={() => setConfirmClose(false)} style={s.grow} />
          <EstibaButton label="Cerrar planilla" variant="confirm" onPress={() => { setConfirmClose(false); void run(closeSheet); }} style={s.grow} />
        </View>
      </View></View>
    </Modal>
  </KeyboardAvoidingView>;
}

type PendingPhoto = { operationId: string; tipo: string; archivo: { uri: string; name: string; type: string }; error?: string };

function PhotosPanel({ api, control, catalog, editable, onChange, onError }: {
  api: ReturnType<typeof createConsolidationApi>; control: ConsolidationControl; catalog: ConsolidationCatalog; editable: boolean;
  onChange: (control: ConsolidationControl) => void; onError: (message: string) => void;
}) {
  const [thumbs, setThumbs] = useState<Record<string, string>>({});
  const [queue, setQueue] = useState<PendingPhoto[]>([]);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let active = true;
    for (const photo of control.fotos) {
      if (thumbs[photo.id]) continue;
      void api.thumbnail(photo.miniatura_url).then((uri) => { if (active && uri) setThumbs((old) => ({ ...old, [photo.id]: uri })); });
    }
    return () => { active = false; };
  }, [control.fotos]);

  async function upload(photo: PendingPhoto) {
    setBusy(true);
    try {
      await api.uploadPhoto(control.id, photo.tipo, photo.operationId, photo.archivo);
      setQueue((old) => old.filter((p) => p.operationId !== photo.operationId));
      onChange(await api.detail(control.id));
    } catch (e) {
      setQueue((old) => old.map((p) => (p.operationId === photo.operationId ? { ...p, error: message(e) } : p)));
    } finally { setBusy(false); }
  }
  async function pick(tipo: string, camera: boolean) {
    try {
      if (camera && !(await ImagePicker.requestCameraPermissionsAsync()).granted) throw new Error('Autoriza la cámara para fotografiar el despacho.');
      const options = { mediaTypes: ['images'] as ImagePicker.MediaType[], quality: 0.5, allowsEditing: false };
      const result = camera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
      if (result.canceled || !result.assets[0]) return;
      const asset = result.assets[0];
      const type = asset.mimeType ?? 'image/jpeg';
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(type)) throw new Error('Usa JPG, PNG o WebP.');
      if ((asset.fileSize ?? 0) > 5 * 1024 * 1024) throw new Error('La foto supera el máximo de 5 MiB.');
      const operationId = Crypto.randomUUID();
      const ext = type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg';
      const photo = { operationId, tipo, archivo: { uri: asset.uri, name: `${operationId}.${ext}`, type } };
      setQueue((old) => [...old, photo]);
      await upload(photo);
    } catch (e) { onError(message(e)); }
  }
  async function remove(photoId: string) {
    setBusy(true);
    try { await api.removePhoto(control.id, photoId); onChange(await api.detail(control.id)); }
    catch (e) { onError(message(e)); }
    finally { setBusy(false); }
  }

  return <>{catalog.tipos_foto.map((type) => {
    const photos = control.fotos.filter((photo) => photo.tipo === type.clave);
    const waiting = queue.filter((photo) => photo.tipo === type.clave);
    return <EstibaPanel key={type.clave} title={`${type.nombre} · ${photos.length}/${type.maximo}`}>
      <Text style={s.muted}>{type.obligatoria ? 'Obligatoria para cerrar la planilla.' : 'Opcional: el set fotográfico estándar se definirá más adelante.'}</Text>
      <View style={s.photos}>
        {photos.map((photo) => <View key={photo.id} style={s.gap}>
          {thumbs[photo.id] ? <Image source={{ uri: thumbs[photo.id] }} style={s.photo} /> : <View style={s.photo} />}
          {editable ? <Pressable disabled={busy} onPress={() => void remove(photo.id)}><Text style={s.link}>Quitar</Text></Pressable> : null}
        </View>)}
        {waiting.map((photo) => <View key={photo.operationId} style={s.gap}>
          <Image source={{ uri: photo.archivo.uri }} style={s.photo} />
          <Text style={photo.error ? s.error : s.muted}>{photo.error ?? 'Subiendo…'}</Text>
          {photo.error ? <Pressable disabled={busy} onPress={() => void upload(photo)}><Text style={s.link}>Reintentar</Text></Pressable> : null}
        </View>)}
      </View>
      {editable ? <View style={s.row}>
        <EstibaButton label="Tomar foto" disabled={busy || photos.length + waiting.length >= type.maximo} onPress={() => void pick(type.clave, true)} style={s.grow} />
        <EstibaButton label="Galería" variant="secondary" disabled={busy || photos.length + waiting.length >= type.maximo} onPress={() => void pick(type.clave, false)} style={s.grow} />
      </View> : null}
    </EstibaPanel>;
  })}</>;
}

const s = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.canvas },
  content: { padding: 14, gap: 14, paddingBottom: 40 },
  gap: { gap: 8 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'flex-end' },
  between: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  grow: { flexGrow: 1, flexShrink: 1 },
  small: { minWidth: 64 },
  body: { fontFamily: font.regular, color: colors.text, fontSize: 15 },
  muted: { fontFamily: font.regular, color: colors.muted, fontSize: 13 },
  label: { fontFamily: font.bold, color: colors.text, fontSize: 15 },
  link: { fontFamily: font.heavy, color: colors.primary, fontSize: 14, paddingVertical: 4 },
  error: { fontFamily: font.regular, color: signal.critical.text, fontSize: 13 },
  dim: { opacity: 0.45 },
  card: { gap: 6, padding: 12, borderWidth: 1, borderColor: colors.border, borderRadius: 10, backgroundColor: colors.surface },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 44, minWidth: 64, paddingHorizontal: 14, justifyContent: 'center', alignItems: 'center', borderWidth: 1, borderColor: colors.inputBorder, borderRadius: 8, backgroundColor: colors.surface },
  chipOn: { borderColor: colors.primary, backgroundColor: colors.primary },
  chipText: { fontFamily: font.bold, color: colors.text, fontSize: 15 },
  chipTextOn: { color: colors.onPrimary },
  chipHint: { fontFamily: font.regular, color: colors.muted, fontSize: 12 },
  tabs: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  tab: { paddingHorizontal: 12, paddingVertical: 10, borderRadius: 8, borderWidth: 1, borderColor: colors.border },
  tabOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  tabText: { fontFamily: font.bold, color: colors.text, fontSize: 14 },
  tabTextOn: { color: colors.onPrimary },
  scan: { fontFamily: font.monoBold, minHeight: 56, paddingHorizontal: 12, borderWidth: 2, borderColor: colors.primary, borderRadius: 8, color: colors.text, backgroundColor: colors.surface, fontSize: 20 },
  pallet: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.border },
  palletPlaced: { opacity: 1 },
  positionBadge: { minWidth: 40, textAlign: 'center', fontFamily: font.heavy, color: colors.text, fontSize: 18 },
  photos: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  photo: { width: 96, height: 96, borderRadius: 6, backgroundColor: colors.border },
  backdrop: { flex: 1, padding: 20, backgroundColor: 'rgba(0,0,0,0.75)', justifyContent: 'center' },
  modal: { gap: 14, padding: 18, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  modalTitle: { fontFamily: font.heavy, color: colors.text, fontSize: 19 },
});
