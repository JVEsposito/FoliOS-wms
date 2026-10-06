import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import type { AuthSession } from '../domain/estiba';
import type { Reception, ReceptionHeader, ReceptionOptions, ReceptionPallet, ReceptionPayload } from '../domain/packedFruitReception';
import { palletPayload } from '../domain/packedFruitReception';
import type { ValidationCatalog } from '../domain/validation';
import { createOriginArticleSelector, indexValidationCatalog } from '../domain/validationCatalogIndex';
import { ApiError } from '../services/apiError';
import { createPackedFruitReceptionApi } from '../services/packedFruitReceptionApi';
import { colors } from '../theme/colors';

type Choice = { id: string; label: string };
type HeaderForm = { [K in keyof ReceptionHeader]: string };
type PalletForm = Omit<ReceptionPallet, 'cantidad_cajas' | 'temperatura_pulpa_c'> & { cantidad_cajas: string; temperatura_pulpa_c: string };
type PendingSave = { id?: string; payload: ReceptionPayload };
const dateLocal = (value = new Date().toISOString()) => { const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
const emptyPallet = (): PalletForm => ({ folio_origen: '', tipo_bulto: 'pallet', origen_validacion_id: '', articulo_validacion_id: '', especie: '', variedad: '', embalaje: '', calibre: '', csp: '', cantidad_cajas: '', fecha_proceso_origen: dateLocal().slice(0, 10), temperatura_pulpa_c: '', condicion_sag_id: null, condicion_sag_personalizada: false });
const headerKeys: (keyof ReceptionHeader)[] = ['temporada_id', 'cliente_id', 'planta_origen_id', 'numero_guia', 'servicio', 'turno', 'validador_id', 'recepcion_at', 'salida_at', 'chofer', 'rut_chofer', 'patente_delantera', 'patente_carro', 'llega_con_prefrio', 'condicion_sag_id', 'observacion'];
const toHeaderForm = (data: ReceptionHeader): HeaderForm => Object.fromEntries(headerKeys.map((key) => {
  const value = data[key];
  return [key, ['recepcion_at', 'salida_at'].includes(key) && value ? dateLocal(String(value)) : String(value ?? '')];
})) as HeaderForm;
const blankHeader = (seasonId: string, userId: number): HeaderForm => toHeaderForm({ temporada_id: seasonId, cliente_id: '', planta_origen_id: '', numero_guia: '', servicio: 'almacenaje', turno: 'A', validador_id: userId, recepcion_at: new Date().toISOString(), salida_at: null, chofer: '', rut_chofer: '', patente_delantera: '', patente_carro: '', llega_con_prefrio: false, condicion_sag_id: null, observacion: '' });
const asChoices = (rows: { id: string | number; nombre?: string; name?: string }[]): Choice[] => rows.map((r) => ({ id: String(r.id), label: r.nombre ?? r.name ?? String(r.id) }));
const distinct = (rows: { [key: string]: unknown }[], field: string): Choice[] => [...new Set(rows.map((r) => String(r[field])))].sort().map((s) => ({ id: s, label: s }));
const confirm = (title: string, text: string) => new Promise<boolean>((resolve) => Alert.alert(title, text, [{ text: 'Volver', style: 'cancel', onPress: () => resolve(false) }, { text: 'Continuar', onPress: () => resolve(true) }], { cancelable: true, onDismiss: () => resolve(false) }));

function Field({ label, value, onChange, numeric = false, disabled = false }: { label: string; value: string; onChange: (v: string) => void; numeric?: boolean; disabled?: boolean }) {
  return <View style={styles.field}><Text style={styles.label}>{label}</Text><TextInput accessibilityLabel={label} style={styles.input} value={value} onChangeText={onChange} editable={!disabled} keyboardType={numeric ? 'numbers-and-punctuation' : 'default'} autoCorrect={false} /></View>;
}
function Button({ label, onPress, disabled = false }: { label: string; onPress: () => void; disabled?: boolean }) {
  return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[styles.button, disabled && styles.disabled]}><Text style={styles.buttonText}>{label}</Text></Pressable>;
}

export function PackedFruitReceptionScreen({ auth, baseUrl, onLogout }: { auth: AuthSession; baseUrl: string; onLogout: () => void }) {
  const api = useMemo(() => createPackedFruitReceptionApi(baseUrl, auth.token), [baseUrl, auth.token]);
  const storageKey = `rfe.pending:${baseUrl}:${auth.usuario.id}:${auth.dispositivo.id}`;
  const [options, setOptions] = useState<ReceptionOptions | null>(null);
  const [catalog, setCatalog] = useState<ValidationCatalog | null>(null);
  const [list, setList] = useState<Reception[]>([]);
  const [page, setPage] = useState(1); const [lastPage, setLastPage] = useState(1);
  const [editing, setEditing] = useState(false); const [document, setDocument] = useState<Reception | null>(null);
  const [header, setHeader] = useState<HeaderForm>(() => blankHeader('', Number(auth.usuario.id)));
  const [pallets, setPallets] = useState<ReceptionPallet[]>([]);
  const [pallet, setPallet] = useState<PalletForm | null>(null); const [palletIndex, setPalletIndex] = useState<number | null>(null);
  const [pending, setPending] = useState<PendingSave | null>(null);
  const [busy, setBusy] = useState(false); const inFlight = useRef(false);
  const [notice, setNotice] = useState(''); const [error, setError] = useState(''); const [dirty, setDirty] = useState(false);
  const [picker, setPicker] = useState<{ title: string; rows: Choice[]; choose: (id: string) => void } | null>(null);
  const [search, setSearch] = useState(''); const folioInput = useRef<TextInput>(null); const scanVersion = useRef(0);
  const index = useMemo(() => catalog ? indexValidationCatalog(catalog) : null, [catalog]);
  const selectArticles = useMemo(() => index ? createOriginArticleSelector(index) : () => [], [index]);
  const origins = useMemo(() => {
    const ids = options?.clientes.find((c) => c.id === header.cliente_id)?.catalogo_validacion_ids ?? [];
    return catalog?.origenes.filter((o) => o.activo && o.cliente_validacion_id && ids.includes(o.cliente_validacion_id)) ?? [];
  }, [options, catalog, header.cliente_id]);
  const articles = useMemo(() => selectArticles(pallet?.origen_validacion_id ? [pallet.origen_validacion_id] : []), [selectArticles, pallet?.origen_validacion_id]);
  // El filtro comercial depende de sus selecciones; escribir cajas o temperaturas no recorre el catálogo.
  const commercial = useMemo(() => {
    const species = articles.filter((a) => !pallet?.especie || a.especie === pallet.especie);
    const varieties = species.filter((a) => !pallet?.variedad || a.variedad === pallet.variedad);
    const packages = varieties.filter((a) => !pallet?.embalaje || a.envase === pallet.embalaje);
    return { species: distinct(articles, 'especie'), varieties: distinct(species, 'variedad'), packages: distinct(varieties, 'envase'), calibers: distinct(packages, 'calibre'), article: packages.find((a) => a.calibre === pallet?.calibre) };
  }, [articles, pallet?.especie, pallet?.variedad, pallet?.embalaje, pallet?.calibre]);
  const pickerRows = useMemo(() => picker?.rows.filter((r) => r.label.toLocaleLowerCase().includes(search.toLocaleLowerCase())) ?? [], [picker, search]);
  const editable = !!auth.usuario.capacidades.puede_gestionar_recepciones_fruta_embalada && (!document || document.editable);
  const locked = busy || !!pending || !editable;
  const sagChoices = useMemo(() => [{ id: '', label: 'Sin condición SAG' }, ...asChoices(options?.condiciones_sag ?? [])], [options]);

  async function load(nextPage = page) {
    setBusy(true); setError('');
    try {
      const [o, c, rows] = await Promise.all([api.options(), api.catalog(), api.list(nextPage)]);
      setOptions(o); setCatalog(c); setList(rows.data); setPage(rows.current_page); setLastPage(rows.last_page);
    } catch (e) { setError(e instanceof Error ? e.message : 'No fue posible cargar las recepciones.'); } finally { setBusy(false); }
  }
  useEffect(() => {
    let current = true;
    void (async () => {
      await load(1);
      const stored = await AsyncStorage.getItem(storageKey);
      if (!current || !stored) return;
      const p = JSON.parse(stored) as PendingSave;
      setPending(p); setHeader(toHeaderForm(p.payload)); setPallets(p.payload.pallets); setEditing(true);
      setNotice('Hay un guardado pendiente de confirmar. Reintenta el mismo envío para evitar duplicados.');
    })().catch((e) => { if (current) setError(e.message); });
    return () => { current = false; scanVersion.current++; };
  }, [api, storageKey]);

  function pick(title: string, rows: Choice[], choose: (id: string) => void) { setSearch(''); setPicker({ title, rows, choose }); }
  function headerField(key: keyof HeaderForm, value: string) { setHeader((h) => ({ ...h, [key]: value })); setDirty(true); }
  function palletField(key: keyof PalletForm, value: string) {
    setPallet((p) => {
      if (!p) return p;
      const next = { ...p, [key]: value };
      const order: (keyof PalletForm)[] = ['origen_validacion_id', 'especie', 'variedad', 'embalaje', 'calibre'];
      if (order.includes(key)) { for (const field of order.slice(order.indexOf(key) + 1)) Object.assign(next, { [field]: '' }); next.articulo_validacion_id = ''; }
      return next;
    }); setDirty(true);
  }
  async function start(id?: string) {
    if (pending || busy || (dirty && !await confirm('Cambios sin guardar', '¿Descartar los cambios y abrir otra recepción?'))) return;
    try {
      const d = id ? await api.detail(id) : null;
      setDocument(d); setHeader(d ? toHeaderForm(d) : blankHeader(options?.temporada?.id ?? '', Number(auth.usuario.id)));
      setPallets(d?.pallets ?? []); setPallet(null); setPalletIndex(null); setEditing(true); setDirty(false); setError(''); setNotice(''); scanVersion.current++;
    } catch (e) { setError((e as Error).message); }
  }
  async function scanFolio() {
    const folio = pallet?.folio_origen.trim().toUpperCase(); if (!folio) return;
    const revision = ++scanVersion.current;
    try {
      const check = await api.checkFolio(folio);
      if (revision !== scanVersion.current) return;
      const repeated = check.repetido || pallets.some((p, i) => i !== palletIndex && p.folio_origen.trim().toUpperCase() === folio);
      setPallet((p) => p && p.folio_origen.trim().toUpperCase() === folio ? { ...p, folio_origen: folio, folio_repetido: repeated } : p);
      if (repeated) setNotice('Se asignará folio interno al aceptar');
    } catch (e) { setError((e as Error).message); }
  }
  function editPallet(p?: ReceptionPallet, i: number | null = null) {
    scanVersion.current++; setPalletIndex(i); setPallet(p ? { ...p, cantidad_cajas: String(p.cantidad_cajas), temperatura_pulpa_c: String(p.temperatura_pulpa_c) } : emptyPallet()); setError('');
  }
  async function addPallet() {
    if (!pallet || busy) return;
    if (!pallet.folio_origen.trim() || !commercial.article || !pallet.especie || !pallet.variedad || !pallet.embalaje || !pallet.calibre || !pallet.cantidad_cajas || !Number.isInteger(Number(pallet.cantidad_cajas)) || Number(pallet.cantidad_cajas) < 1 || !/^\d{4}-\d{2}-\d{2}$/.test(pallet.fecha_proceso_origen) || !pallet.temperatura_pulpa_c.trim() || !Number.isFinite(Number(pallet.temperatura_pulpa_c))) { setError('Completa el folio, CSG, producto, cajas, fecha de proceso y T° de pulpa.'); return; }
    setBusy(true);
    try {
      const result = await api.checkFolio(pallet.folio_origen);
      const p: ReceptionPallet = { ...pallet, articulo_validacion_id: commercial.article.id, csg: origins.find((o) => o.id === pallet.origen_validacion_id)?.csg, cantidad_cajas: Number(pallet.cantidad_cajas), temperatura_pulpa_c: Number(pallet.temperatura_pulpa_c), folio_origen: pallet.folio_origen.trim().toUpperCase(), folio_repetido: result.repetido || pallets.some((o, i) => i !== palletIndex && o.folio_origen.trim().toUpperCase() === pallet.folio_origen.trim().toUpperCase()) };
      setPallets((prev) => palletIndex === null ? [...prev, p] : prev.map((o, i) => i === palletIndex ? p : o));
      setPallet(null); setPalletIndex(null); setDirty(true); setError(''); setNotice(p.folio_repetido ? 'Se asignará folio interno al aceptar' : 'Pallet agregado a la captura. Guarda el borrador para registrarlo.');
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  async function send(p: PendingSave) {
    if (inFlight.current) return;
    inFlight.current = true; setBusy(true); setError('');
    try {
      const result = await api.save(p.payload, p.id);
      // Si falla limpiar el respaldo local, se conserva el envío para repetirlo idempotentemente.
      await AsyncStorage.removeItem(storageKey);
      setPending(null); setDocument(result); setHeader(toHeaderForm(result)); setPallets(result.pallets); setDirty(false); setNotice('Borrador guardado.');
    } catch (e) {
      if (e instanceof ApiError && e.status >= 400 && e.status < 500) { await AsyncStorage.removeItem(storageKey); setPending(null); }
      setError((e as Error).message);
    } finally { inFlight.current = false; setBusy(false); }
  }
  async function save() {
    if (inFlight.current || busy || pending) return;
    if (pallet) { setError('Agrega o cancela el pallet que estás capturando antes de guardar.'); return; }
    const required: (keyof HeaderForm)[] = ['cliente_id', 'planta_origen_id', 'numero_guia', 'turno', 'validador_id', 'recepcion_at', 'chofer', 'patente_delantera'];
    if (required.some((k) => !header[k]?.trim()) || Number.isNaN(Date.parse(header.recepcion_at)) || (header.salida_at && Number.isNaN(Date.parse(header.salida_at)))) { setError('Completa los campos obligatorios del camión y sus fechas.'); return; }
    setBusy(true); setError('');
    try {
      const review = await api.reviewGuide(header, document?.id);
      if (review.duplicada && !await confirm('Guía ya recibida', `${review.mensaje}\n¿Guardar de todas formas?`)) return;
      const payload: ReceptionPayload = { ...header, temporada_id: document?.temporada_id ?? options?.temporada?.id ?? '', servicio: header.servicio as 'almacenaje' | 'prefrio', validador_id: Number(header.validador_id), llega_con_prefrio: header.llega_con_prefrio === 'true', recepcion_at: new Date(header.recepcion_at).toISOString(), salida_at: header.salida_at ? new Date(header.salida_at).toISOString() : null, condicion_sag_id: header.condicion_sag_id || null, operacion_id: Crypto.randomUUID(), ...(document ? { version_conocida: document.version } : {}), confirmar_guia_duplicada: review.duplicada, pallets: pallets.map(palletPayload) };
      const p = { id: document?.id, payload }; await AsyncStorage.setItem(storageKey, JSON.stringify(p)); setPending(p); await send(p);
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  function chooseField(label: string, value: string, rows: Choice[], choose: (id: string) => void, disabled = locked) {
    return <View style={styles.field}><Text style={styles.label}>{label}</Text><Pressable accessibilityRole="button" accessibilityLabel={label} disabled={disabled} onPress={() => pick(label, rows, choose)} style={[styles.input, disabled && styles.disabled]}><Text style={styles.text}>{rows.find((r) => r.id === value)?.label ?? (value || 'Seleccionar')}</Text></Pressable></View>;
  }
  const headerView = editing ? <View>
    <Text style={styles.title}>{document ? `Guía ${document.numero_guia} · ${document.estado}` : 'Nueva recepción'}</Text>
    <Text style={styles.note}>Temporada: {options?.temporada?.nombre ?? 'Sin temporada activa'}</Text>
    <Text style={styles.warning}>Borrador: todavía no crea folios ni inventario.</Text>
    {chooseField('Cliente *', header.cliente_id, asChoices(options?.clientes ?? []), (id) => {
      if (pallets.length || pallet) { Alert.alert('Cliente de la recepción', 'Quita los pallets antes de cambiar el cliente.'); return; } headerField('cliente_id', id);
    })}
    {chooseField('Planta de origen *', header.planta_origen_id, asChoices(options?.plantas_origen ?? []), (id) => headerField('planta_origen_id', id))}
    <Field label="N° de guía *" value={header.numero_guia} disabled={locked} onChange={(v) => headerField('numero_guia', v)} />
    {chooseField('Servicio *', header.servicio, [{ id: 'almacenaje', label: 'Almacenaje' }, { id: 'prefrio', label: 'Prefrío' }], (id) => headerField('servicio', id))}
    <Field label="Turno *" value={header.turno} disabled={locked} onChange={(v) => headerField('turno', v)} />
    {chooseField('Validador *', header.validador_id, asChoices(options?.validadores ?? []), (id) => headerField('validador_id', id))}
    <Field label="Recepción * (AAAA-MM-DDTHH:mm, hora local)" value={header.recepcion_at} disabled={locked} onChange={(v) => headerField('recepcion_at', v)} />
    <Field label="Salida (AAAA-MM-DDTHH:mm, hora local)" value={header.salida_at} disabled={locked} onChange={(v) => headerField('salida_at', v)} />
    <Field label="Chofer *" value={header.chofer} disabled={locked} onChange={(v) => headerField('chofer', v)} />
    <Field label="RUT del chofer" value={header.rut_chofer} disabled={locked} onChange={(v) => headerField('rut_chofer', v)} />
    <Field label="Patente delantera *" value={header.patente_delantera} disabled={locked} onChange={(v) => headerField('patente_delantera', v)} />
    <Field label="Patente del carro" value={header.patente_carro} disabled={locked} onChange={(v) => headerField('patente_carro', v)} />
    {chooseField('Llega con prefrío *', header.llega_con_prefrio, [{ id: 'false', label: 'No' }, { id: 'true', label: 'Sí' }], (id) => headerField('llega_con_prefrio', id))}
    {chooseField('Condición SAG para todos', header.condicion_sag_id, sagChoices, (id) => headerField('condicion_sag_id', id))}
    <Field label="Observación" value={header.observacion} disabled={locked} onChange={(v) => headerField('observacion', v)} />
    <Text style={styles.title}>Pallets: {pallets.length} · {pallets.reduce((s, p) => s + p.cantidad_cajas, 0)} cajas</Text>
    {!pallet ? <Button label="Escanear / agregar pallet" disabled={locked || !header.cliente_id || pallets.length >= 500} onPress={() => editPallet()} /> : <View style={styles.card}>
      <Text style={styles.title}>{palletIndex === null ? 'Nuevo pallet' : `Editar pallet ${palletIndex + 1}`}</Text>
      <Text style={styles.label}>Folio de origen *</Text><TextInput ref={folioInput} autoFocus accessibilityLabel="Folio de origen" style={styles.input} value={pallet.folio_origen} autoCorrect={false} autoCapitalize="characters" editable={!locked} returnKeyType="next" onChangeText={(v) => { scanVersion.current++; palletField('folio_origen', v); }} onSubmitEditing={() => void scanFolio()} onBlur={() => void scanFolio()} />
      {pallet.folio_repetido && <Text style={styles.warning}>Se asignará folio interno al aceptar</Text>}
      {chooseField('CSG *', pallet.origen_validacion_id, origins.map((o) => ({ id: o.id, label: `${o.csg} · ${o.predio ?? ''} · ${o.marca}` })), (id) => palletField('origen_validacion_id', id))}
      {chooseField('Especie *', pallet.especie ?? '', commercial.species, (id) => palletField('especie', id))}
      {chooseField('Variedad *', pallet.variedad ?? '', commercial.varieties, (id) => palletField('variedad', id))}
      {chooseField('Embalaje *', pallet.embalaje ?? '', commercial.packages, (id) => palletField('embalaje', id))}
      {chooseField('Calibre *', pallet.calibre ?? '', commercial.calibers, (id) => palletField('calibre', id))}
      <Field label="Cajas *" value={pallet.cantidad_cajas} numeric disabled={locked} onChange={(v) => palletField('cantidad_cajas', v)} />
      {chooseField('Tipo de bulto', pallet.tipo_bulto, [{ id: 'pallet', label: 'Pallet' }, { id: 'saldo', label: 'Saldo' }], (id) => palletField('tipo_bulto', id))}
      <Field label="Fecha de proceso de origen * (AAAA-MM-DD)" value={pallet.fecha_proceso_origen} disabled={locked} onChange={(v) => palletField('fecha_proceso_origen', v)} />
      <Field label="T° de pulpa * (°C)" value={pallet.temperatura_pulpa_c} numeric disabled={locked} onChange={(v) => palletField('temperatura_pulpa_c', v.replace(',', '.'))} />
      <Field label="CSP" value={pallet.csp ?? ''} disabled={locked} onChange={(v) => palletField('csp', v)} />
      {chooseField('Condición SAG del pallet', pallet.condicion_sag_personalizada ? pallet.condicion_sag_id ?? '' : 'heredar', [{ id: 'heredar', label: 'Aplicar condición del encabezado' }, ...sagChoices], (id) => { setPallet((p) => p ? { ...p, condicion_sag_personalizada: id !== 'heredar', condicion_sag_id: id === 'heredar' ? null : id || null } : p); setDirty(true); })}
      <Button label="Agregar a la captura" onPress={() => void addPallet()} disabled={locked} />
      <Button label="Cancelar captura del pallet" disabled={locked} onPress={() => { scanVersion.current++; setPallet(null); setPalletIndex(null); }} />
    </View>}
  </View> : <View><Text style={styles.title}>Recepción de fruta embalada</Text><Text style={styles.note}>Borradores de la temporada activa.</Text><Button label="Nueva recepción" disabled={busy || !options?.temporada || !editable} onPress={() => void start()} /><Button label="Actualizar" disabled={busy} onPress={() => void load()} /></View>;

  return <View style={styles.screen}>
    <View style={styles.toolbar}><Text style={styles.text}>Frío · Fruta embalada</Text><Button label="Salir" disabled={busy} onPress={() => { if (dirty && !pending) Alert.alert('Cambios sin guardar', '¿Cerrar sesión y descartar la captura?', [{ text: 'Volver', style: 'cancel' }, { text: 'Salir', onPress: onLogout }]); else onLogout(); }} /></View>
    {busy && <ActivityIndicator color={colors.cyan} />}
    {!!notice && <Text style={styles.warning} accessibilityLiveRegion="polite">{notice}</Text>}
    {!!error && <Text style={styles.error} accessibilityRole="alert">{error}</Text>}
    <FlatList<ReceptionPallet | Reception> data={editing ? pallets : list} keyExtractor={(item, i) => item.id ?? `pallet-${i}`} keyboardShouldPersistTaps="handled" contentContainerStyle={styles.content} ListHeaderComponent={headerView} renderItem={({ item, index: i }) => editing ? <View style={styles.card}>
      <Text style={styles.title}>{i + 1}. {(item as ReceptionPallet).folio_origen}</Text><Text style={styles.text}>{(item as ReceptionPallet).csg} · {(item as ReceptionPallet).especie} · {(item as ReceptionPallet).variedad}</Text><Text style={styles.note}>{(item as ReceptionPallet).embalaje} · {(item as ReceptionPallet).calibre} · {(item as ReceptionPallet).cantidad_cajas} cajas · {(item as ReceptionPallet).temperatura_pulpa_c} °C</Text>
      {(item as ReceptionPallet).folio_repetido && <Text style={styles.warning}>Se asignará folio interno al aceptar</Text>}
      <Text style={styles.note}>SAG: {(item as ReceptionPallet).condicion_sag_personalizada ? sagChoices.find((s) => s.id === ((item as ReceptionPallet).condicion_sag_id ?? ''))?.label : sagChoices.find((s) => s.id === header.condicion_sag_id)?.label}</Text>
      <Button label="Editar pallet" disabled={locked || !!pallet} onPress={() => editPallet(item as ReceptionPallet, i)} /><Button label="Quitar pallet" disabled={locked || !!pallet} onPress={() => { setPallets((p) => p.filter((_, n) => n !== i)); setDirty(true); }} />
    </View> : <View style={styles.card}><Text style={styles.title}>Guía {(item as Reception).numero_guia}</Text><Text style={styles.text}>{(item as Reception).cliente?.nombre} · {(item as Reception).planta_origen?.nombre}</Text><Text style={styles.note}>{(item as Reception).pallets_count} pallets · {new Date((item as Reception).recepcion_at).toLocaleString()}</Text><Button label="Abrir recepción" disabled={busy} onPress={() => void start(item.id)} /></View>} ListFooterComponent={editing ? <View>
      {pending ? <Button label="Reintentar el mismo guardado" disabled={busy} onPress={() => void send(pending)} /> : <Button label="Guardar borrador" disabled={locked || !!pallet} onPress={() => void save()} />}
      <Button label="Volver al listado" disabled={busy || !!pending} onPress={() => { void (async () => { if (dirty && !await confirm('Cambios sin guardar', '¿Descartar los cambios y volver al listado?')) return; setEditing(false); setDirty(false); setDocument(null); setPallet(null); await load(1); })(); }} />
    </View> : <View style={styles.toolbar}><Button label="Anterior" disabled={busy || page <= 1} onPress={() => void load(page - 1)} /><Text style={styles.text}>{page} / {lastPage}</Text><Button label="Siguiente" disabled={busy || page >= lastPage} onPress={() => void load(page + 1)} /></View>} />
    <Modal visible={!!picker} transparent animationType="slide" onRequestClose={() => setPicker(null)}><View style={styles.modal}><View style={styles.modalBody}><Text style={styles.title}>{picker?.title}</Text><TextInput accessibilityLabel="Buscar opción" placeholder="Buscar" placeholderTextColor={colors.muted} style={styles.input} value={search} onChangeText={setSearch} autoCorrect={false} /><FlatList data={pickerRows} keyExtractor={(r) => r.id} keyboardShouldPersistTaps="handled" initialNumToRender={15} maxToRenderPerBatch={15} renderItem={({ item }) => <Button label={item.label} onPress={() => { picker?.choose(item.id); setPicker(null); }} />} ListEmptyComponent={<Text style={styles.note}>No hay opciones habilitadas para esta selección.</Text>} /><Button label="Cerrar" onPress={() => setPicker(null)} /></View></View></Modal>
  </View>;
}
const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background }, content: { padding: 14, paddingBottom: 40 }, toolbar: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 10, gap: 8 },
  title: { color: colors.text, fontSize: 20, fontWeight: '700', marginVertical: 8 }, text: { color: colors.text, fontSize: 15 }, note: { color: colors.muted, marginVertical: 6 }, label: { color: colors.muted, marginBottom: 6 },
  field: { marginVertical: 7 }, input: { padding: 12, minHeight: 46, backgroundColor: colors.panel, color: colors.text, borderWidth: 1, borderColor: colors.border, borderRadius: 8 },
  button: { padding: 12, minHeight: 46, marginVertical: 5, borderRadius: 8, backgroundColor: colors.cyanDark }, buttonText: { color: colors.text, fontWeight: '600' }, disabled: { opacity: 0.45 },
  card: { padding: 14, marginVertical: 10, backgroundColor: colors.panelStrong, borderRadius: 10 }, warning: { color: colors.amber, margin: 10 }, error: { color: colors.red, padding: 10 },
  modal: { flex: 1, backgroundColor: '#000000aa', justifyContent: 'center', padding: 16 }, modalBody: { flex: 1, maxHeight: '90%', backgroundColor: colors.background, borderRadius: 14, padding: 16 },
});
