import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import type { AuthSession } from '../domain/estiba';
import { blankSagForm, sagFormFromDocument, sagNames, sagPayload, sagStorageKey, type SagDispatch, type SagDocument, type SagForm, type SagKind, type SagLine, type SagPayload } from '../domain/sagCounterpart';
import { createSagCounterpartApi, type SagConfiguration, type SagPage } from '../services/sagCounterpartApi';
import { ApiError } from '../services/apiError';
import { EntityCode, EstibaAlert, EstibaButton, EstibaField, EstibaHeading, EstibaPanel, EstibaSignal } from '../components/ui/EstibaPrimitives';
import { estibaTokens } from '../theme/estibaTokens';

const colors = { ...estibaTokens.color, ...estibaTokens.colorDark };
const kinds = Object.keys(sagNames) as SagKind[];
const dispatchFields: Record<string, string> = { exportador: 'Exportador', rut_exportador: 'RUT exportador', agente: 'Agente', rut_agente: 'RUT agente',
  nave_vuelo: 'Nave / vuelo', puerto_embarque: 'Puerto de embarque', oficina_sag_puerto: 'Oficina SAG puerto', destino_pais: 'País destino', destino_ciudad: 'Ciudad / puerto destino', consignatario: 'Consignatario',
  contenedor: 'Contenedor', sello: 'Sello', ubicacion_sello: 'Ubicación del sello', patente: 'Patente', patente_trasera: 'Patente carro', guias: 'Números de guías',
  referencia_multipuerto: 'Referencia oficial Multipuerto', motivo_contingencia: 'Motivo de contingencia', detalle_tratamiento: 'Tratamiento: tipo, producto, dosis, duración, T° y recinto' };
const dispatchChoices: Record<string, { title: string; values: string[] }> = {
  modo: { title: 'Documento', values: ['multipuerto', 'contingencia'] }, tipo_produccion: { title: 'Tipo de producción', values: ['CONVENCIONAL', 'ORGÁNICO', 'EN CONVERSIÓN'] },
  transgenico: { title: 'Transgénico', values: ['SI', 'NO', 'NO APLICA'] }, tratamiento: { title: 'Tratamiento fitosanitario', values: ['sin_tratamiento', 'tratado', 'tratamiento_destino'] },
  nimf15: { title: 'NIMF 15 verificada', values: ['true', 'false'] }, inspeccionado: { title: 'Producto inspeccionado', values: ['true', 'false'] },
};
type Pending = { type: 'save'; data: SagPayload; documentId?: string } | { type: 'action'; documentId: string; action: string; data: { operacion_id: string; version: number; referencia: string; resuelto_por: string } };
const message = (e: unknown) => e instanceof Error ? e.message : 'No se pudo completar el registro.';
const text = (value: unknown) => value == null ? '' : String(value);

export function SagCounterpartScreen({ auth, baseUrl, onLogout }: { auth: AuthSession; baseUrl: string; onLogout: () => void }) {
  const api = useMemo(() => createSagCounterpartApi(baseUrl, auth.token), [baseUrl, auth.token]);
  const key = useMemo(() => sagStorageKey(baseUrl, String(auth.usuario.id), auth.dispositivo.id), [baseUrl, auth.usuario.id, auth.dispositivo.id]);
  const canRead = auth.usuario.capacidades.puede_consultar_contraparte_sag === true;
  const canWrite = auth.usuario.capacidades.puede_gestionar_contraparte_sag === true;
  const [config, setConfig] = useState<SagConfiguration | null>(null);
  const [page, setPage] = useState<SagPage | null>(null);
  const [typeFilter, setTypeFilter] = useState<SagKind | ''>('');
  const [form, setForm] = useState<SagForm>(blankSagForm);
  const [editing, setEditing] = useState(false);
  const [doc, setDoc] = useState<SagDocument | null>(null);
  const [folio, setFolio] = useState('');
  const [reference, setReference] = useState(''); const [resolvedBy, setResolvedBy] = useState('');
  const [notice, setNotice] = useState(''); const [error, setError] = useState('');
  const [busy, setBusy] = useState(false); const working = useRef(false);
  const [pending, setPending] = useState<Pending | null>(null); const pendingRef = useRef<Pending | null>(null);
  const [recovered, setRecovered] = useState(false); const alive = useRef(true);
  const listGeneration = useRef(0);
  const blocked = busy || !recovered || pending !== null;

  async function run(action: () => Promise<void>) {
    if (working.current) return;
    working.current = true; setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { if (alive.current) setError(message(e)); }
    finally { working.current = false; if (alive.current) setBusy(false); }
  }
  async function loadPage(number = 1, type = typeFilter) {
    const generation = ++listGeneration.current; const result = await api.list(number, type);
    if (alive.current && generation === listGeneration.current) setPage(result);
  }
  useEffect(() => {
    alive.current = true;
    void (async () => {
      try {
        const raw = await AsyncStorage.getItem(key);
        if (raw) {
          const stored = JSON.parse(raw) as { form?: SagForm; pending?: Pending | null };
          if (!stored.form || !kinds.includes(stored.form.tipo) || !Array.isArray(stored.form.lineas)) throw new Error('No se pudo leer la captura guardada en este equipo.');
          if (alive.current) { setForm(stored.form); setEditing(true); pendingRef.current = stored.pending || null; setPending(stored.pending || null); setNotice('Captura recuperada de este equipo.'); }
        }
        if (alive.current) setRecovered(true);
      } catch (e) { if (alive.current) setError(message(e)); }
      try { if (canRead) { const configuration = await api.configuration(); if (alive.current) setConfig(configuration); await loadPage(1, ''); } }
      catch (e) { if (alive.current) setError(message(e)); }
    })();
    return () => { alive.current = false; listGeneration.current++; };
  }, [api, key, canRead]);
  useEffect(() => {
    if (!recovered || pendingRef.current || !editing) return;
    const timer = setTimeout(() => { if (!pendingRef.current) void AsyncStorage.setItem(key, JSON.stringify({ form, pending: null })).catch(() => setError('No se pudo guardar la captura en este equipo.')); }, 500);
    return () => clearTimeout(timer);
  }, [form, recovered, editing, key]);

  function change<K extends keyof SagForm>(field: K, value: SagForm[K]) { if (!blocked) setForm((old) => ({ ...old, [field]: value })); }
  function line(index: number, field: keyof SagLine, value: string) {
    if (blocked) return;
    setForm((old) => ({ ...old, lineas: old.lineas.map((l, i) => i !== index ? l : { ...l, [field]: field === 'cantidad' ? Number(value) : field === 'kilos' ? (value === '' ? null : Number(value)) : value }) }));
  }
  function instruction(id: string) {
    const i = form.fuente?.instructivos?.find((r) => r.id === id);
    change('instructivo', id);
    if (i) setForm((old) => ({ ...old, despacho: { ...old.despacho, destino_pais: i.destino_pais, destino_ciudad: i.destino_ciudad, consignatario: i.recibidor, referencia_multipuerto: i.planilla_sag || '' } }));
  }
  async function source() {
    const result = await api.source(form.tipo, form.referencia.trim());
    if (!alive.current) return;
    const i = result.instructivos?.length === 1 ? result.instructivos[0] : null;
    const despacho: SagDispatch = form.tipo === 'despacho' ? { ...result.transporte, modo: 'multipuerto', ...(i ? { destino_pais: i.destino_pais, destino_ciudad: i.destino_ciudad, consignatario: i.recibidor, referencia_multipuerto: i.planilla_sag || '' } : {}) } : {};
    setForm((old) => ({ ...old, fuente: result, fecha: result.fecha_actividad, lineas: (result.originales || result.lineas || []).map((l) => ({ ...l, incluido: Boolean(i) })), despacho, instructivo: i?.id || '' }));
  }
  async function send(attempt: Pending) {
    const previous = pendingRef.current; pendingRef.current = attempt;
    try { await AsyncStorage.setItem(key, JSON.stringify({ form, pending: attempt })); } catch (e) { pendingRef.current = previous; throw e; }
    setPending(attempt);
    try {
      const result = attempt.type === 'save' ? await api.save(attempt.data, attempt.documentId) : await api.act(attempt.documentId, attempt.action, attempt.data);
      await AsyncStorage.removeItem(key); pendingRef.current = null;
      if (!alive.current) return;
      setPending(null); setDoc(result); setEditing(false); setForm(blankSagForm()); setNotice('Registro guardado.');
      try { setDoc(await api.detail(result.id)); await loadPage(); } catch (e) { if (alive.current) setError('El registro quedó guardado. ' + message(e)); }
    } catch (e) {
      if (e instanceof ApiError && [409, 422, 403].includes(e.status)) {
        await AsyncStorage.setItem(key, JSON.stringify({ form, pending: null })); pendingRef.current = null; setPending(null);
      }
      throw e;
    }
  }
  async function addFolio() {
    const found = await api.folio(folio.trim());
    if (!found.con_condicion) throw new Error('Este folio no tiene condición SAG.');
    if (form.lineas.some((l) => l.folio_id === found.folio_id)) throw new Error('El folio ya está agregado.');
    if (alive.current) { setForm((old) => ({ ...old, lineas: [...old.lineas, { ...found, causal: '' }] })); setFolio(''); }
  }
  const choices = (title: string, items: { value: string; label: string }[], value: string, select: (v: string) => void) => <View style={s.gap}><Text style={s.label}>{title}</Text><View style={s.wrap}>{items.map((i) => <EstibaButton key={i.value} label={`${value === i.value ? '✓ ' : ''}${i.label}`} variant="secondary" disabled={blocked} onPress={() => select(i.value)} />)}</View></View>;

  if (!canRead) return <View style={s.root}><EstibaAlert title="Contraparte SAG no está habilitado en este perfil." /><EstibaButton label="Cerrar sesión" onPress={onLogout} /></View>;
  return <KeyboardAvoidingView style={s.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
    <ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
      <EstibaHeading title="Contraparte SAG" description={auth.usuario.nombre} actions={<EstibaButton label="Cerrar sesión" variant="secondary" onPress={onLogout} />} />
      {error ? <EstibaAlert title="No se pudo completar" detail={error} tone="critical" live /> : null}
      {notice ? <EstibaAlert title={notice} tone="success" live /> : null}
      {!recovered ? <EstibaButton label="Recuperar captura" variant="secondary" onPress={() => void run(async () => { const raw = await AsyncStorage.getItem(key); if (raw) { const data = JSON.parse(raw); setForm(data.form); setPending(data.pending); pendingRef.current = data.pending; setEditing(true); } setRecovered(true); })} /> : null}
      {pending ? <EstibaPanel title="Envío pendiente"><Text style={s.body}>Reintenta este mismo registro para conocer su resultado.</Text><EstibaButton label="Reintentar envío" busy={busy} onPress={() => void run(() => send(pending))} /></EstibaPanel> : null}
      <View style={s.wrap}>
        <EstibaButton label="Documentos" variant="secondary" disabled={blocked} onPress={() => { setEditing(false); setDoc(null); void run(() => loadPage()); }} />
        {canWrite ? <EstibaButton label="Continuar captura" variant="secondary" disabled={blocked} onPress={() => { setEditing(true); setDoc(null); }} /> : null}
        {canWrite ? <EstibaButton label="Nuevo documento" disabled={blocked} onPress={() => { setForm(blankSagForm()); setEditing(true); setDoc(null); }} /> : null}
      </View>
      {editing && canWrite ? <EstibaPanel title={form.documento ? 'Editar borrador' : 'Captura SAG'}>
        {!form.documento ? choices('Tipo', kinds.map((k) => ({ value: k, label: sagNames[k] })), form.tipo, (k) => setForm((old) => ({ ...blankSagForm(), tipo: k as SagKind, responsable: old.responsable }))) : <Text style={s.label}>{sagNames[form.tipo]}</Text>}
        <EstibaField label="Fecha de actividad" value={form.fecha} placeholder="AAAA-MM-DD" editable={!blocked} onChangeText={(v) => change('fecha', v)} />
        {choices('Responsable autorizado', (config?.responsables || []).filter((r) => r.activo).map((r) => ({ value: r.id, label: r.nombre })), form.responsable, (v) => change('responsable', v))}
        {form.tipo !== 'anulacion' ? <View style={s.gap}><EstibaField label={form.tipo === 'despacho' ? 'Código de embarque' : form.tipo === 'cambio_mercado' ? 'Código de lote SAG' : 'Código REPA'} value={form.referencia} editable={!blocked} onChangeText={(v) => change('referencia', v)} onSubmitEditing={() => void run(source)} /><EstibaButton label="Cargar origen" disabled={blocked} onPress={() => void run(source)} /></View>
          : <View style={s.gap}><EstibaField label="Escanear folio" value={folio} editable={!blocked} autoCapitalize="none" onChangeText={setFolio} onSubmitEditing={() => void run(addFolio)} /><EstibaButton label="Agregar folio" disabled={blocked} onPress={() => void run(addFolio)} /></View>}
        {form.tipo === 'cambio_mercado' ? <View style={s.gap}><EstibaField label="Programa" value={form.programa} editable={!blocked} onChangeText={(v) => change('programa', v)} /><EstibaField label="Motivo" value={form.motivo} editable={!blocked} onChangeText={(v) => change('motivo', v)} /></View> : null}
        {form.tipo === 'despacho' ? <View style={s.gap}>
          {choices('Instructivo', (form.fuente?.instructivos || []).map((i) => ({ value: i.id, label: `${i.numero} · ${i.destino_pais}` })), form.instructivo, instruction)}
          {Object.entries(dispatchChoices).map(([key, group]) => <View key={key}>{choices(group.title, group.values.map((v) => ({ value: v, label: v === 'true' ? 'Sí' : v === 'false' ? 'No' : v.replaceAll('_', ' ') })), text(form.despacho[key]), (v) => change('despacho', { ...form.despacho, [key]: ['nimf15', 'inspeccionado'].includes(key) ? v === 'true' : v }))}</View>)}
          {Object.entries(dispatchFields).map(([key, label]) => <EstibaField key={key} label={label} value={text(form.despacho[key])} editable={!blocked} maxLength={1000} onChangeText={(v) => change('despacho', { ...form.despacho, [key]: v })} />)}
        </View> : null}
        {form.lineas.map((l, i) => <EstibaPanel key={l.linea_id || l.folio_id} title={`${i + 1} · ${l.especie} ${l.variedad || ''}`}>
          <EntityCode value={l.folio} /><Text style={s.body}>{l.cantidad} cajas{l.nuevo_destino ? ` · Nuevo destino: ${l.nuevo_destino}` : ''}</Text>
          {form.tipo === 'despacho' ? <EstibaButton label={l.incluido ? 'Incluido ✓' : 'Incluir pallet'} variant="secondary" disabled={blocked} onPress={() => setForm((old) => ({ ...old, lineas: old.lineas.map((r) => r.folio_id === l.folio_id ? { ...r, incluido: !l.incluido } : r) }))} /> : null}
          {form.tipo === 'anulacion' ? <EstibaField label="Cajas a documentar" value={text(l.cantidad)} editable={!blocked} keyboardType="number-pad" onChangeText={(v) => line(i, 'cantidad', v)} /> : null}
          {form.tipo !== 'despacho' ? <View style={s.gap}><EstibaField label="Última actividad" value={l.tipo_actividad || ''} editable={!blocked} onChangeText={(v) => line(i, 'tipo_actividad', v)} /><EstibaField label="Número de actividad" value={l.numero_actividad || ''} editable={!blocked} onChangeText={(v) => line(i, 'numero_actividad', v)} /></View> : null}
          {form.tipo === 'anulacion' ? <View style={s.gap}><EstibaField label="Causal" value={l.causal || ''} editable={!blocked} onChangeText={(v) => line(i, 'causal', v)} /><EstibaButton label="Quitar folio" variant="secondary" disabled={blocked} onPress={() => change('lineas', form.lineas.filter((_, n) => n !== i))} /></View> : null}
          {['cambio_mercado', 'despacho'].includes(form.tipo) ? <EstibaField label="Kilos netos" value={text(l.kilos)} editable={!blocked} keyboardType="decimal-pad" onChangeText={(v) => line(i, 'kilos', v.replace(',', '.'))} /> : null}
          {form.tipo === 'cambio_mercado' ? <EstibaField label="Destino anteriormente aprobado" value={l.mercados_anteriores || ''} editable={!blocked} onChangeText={(v) => line(i, 'mercados_anteriores', v)} /> : null}
          {form.tipo === 'despacho' ? (['provincia', 'comuna', 'csg', 'csp'] as const).map((k) => <EstibaField key={k} label={k.toUpperCase()} value={l[k] || ''} editable={!blocked} onChangeText={(v) => line(i, k, v)} />) : null}
        </EstibaPanel>)}
        {form.fuente?.resultados ? <Text style={s.body}>Resultado: {form.fuente.resultados.map((r) => `${r.folio} (${r.cantidad} cajas)`).join(', ')}</Text> : null}
        {form.tipo !== 'despacho' ? <View style={s.gap}><EstibaField label="Certificados que anula / devuelve" value={form.certificados} editable={!blocked} keyboardType="number-pad" onChangeText={(v) => change('certificados', v)} /><EstibaField label="Certificados devueltos" value={form.devueltos} editable={!blocked} keyboardType="number-pad" onChangeText={(v) => change('devueltos', v)} /><EstibaField label="Certificados asignados" value={form.asignados} editable={!blocked} keyboardType="number-pad" onChangeText={(v) => change('asignados', v)} /></View> : null}
        <EstibaField label="Observaciones" value={form.observaciones} editable={!blocked} multiline maxLength={2000} onChangeText={(v) => change('observaciones', v)} />
        <EstibaButton label="Guardar borrador" disabled={blocked} busy={busy} onPress={() => void run(() => send({ type: 'save', data: sagPayload(form, Crypto.randomUUID()), documentId: form.documento?.id }))} />
      </EstibaPanel> : null}
      {doc && !editing ? <EstibaPanel title={`${sagNames[doc.tipo]} ${doc.numero ?? 'Borrador'}`}>
        <EstibaSignal label={doc.estado} /><Text style={s.body}>{doc.fecha_actividad.slice(0, 10)} · {doc.encabezado.observaciones || ''}</Text>
        {(doc.detalle.originales || doc.detalle.lineas || []).map((l, i) => <Text key={`${l.folio_id}-${i}`} style={s.body}>{l.folio} · {l.cantidad} cajas</Text>)}
        <View style={s.wrap}>{(['pdf', 'xlsx'] as const).map((format) => <EstibaButton key={format} label={format.toUpperCase()} variant="secondary" disabled={blocked} onPress={() => void run(() => api.share(doc.id, format))} />)}</View>
        {canWrite ? <View style={s.gap}>
          {doc.estado === 'borrador' ? <EstibaButton label="Editar borrador" disabled={blocked} variant="secondary" onPress={() => { setForm(sagFormFromDocument(doc)); setEditing(true); }} /> : null}
          <EstibaField label="Referencia de envío, recepción o resolución" value={reference} editable={!blocked} onChangeText={setReference} />
          <EstibaField label="Nombre y cargo de quien resolvió" value={resolvedBy} editable={!blocked} onChangeText={setResolvedBy} />
          <View style={s.wrap}>{(doc.estado === 'borrador' ? ['emitir', 'cancelar'] : doc.estado === 'emitido' ? ['enviar', 'aprobar', 'rechazar'] : doc.estado === 'enviado' ? ['aprobar', 'rechazar'] : []).map((a) => <EstibaButton key={a} label={({ emitir: 'Emitir documento', cancelar: 'Cancelar borrador', enviar: 'Registrar envío', aprobar: 'Registrar aprobación recibida', rechazar: 'Registrar rechazo recibido' } as Record<string, string>)[a]} disabled={blocked} variant={a === 'emitir' ? 'primary' : 'secondary'} onPress={() => void run(() => send({ type: 'action', documentId: doc.id, action: a, data: { operacion_id: Crypto.randomUUID(), version: doc.version, referencia: reference, resuelto_por: resolvedBy } }))} />)}</View>
        </View> : null}
        {(doc.historial || []).map((e, i) => <Text key={i} style={s.body}>{e.created_at} · {e.tipo} · {e.datos.referencia || ''} {e.datos.resuelto_por || ''}</Text>)}
      </EstibaPanel> : null}
      {!editing && !doc ? <EstibaPanel title="Documentos de la temporada">
        {choices('Filtrar tipo', [{ value: '', label: 'Todos' }, ...kinds.map((k) => ({ value: k, label: sagNames[k] }))], typeFilter, (k) => { setTypeFilter(k as SagKind | ''); void run(() => loadPage(1, k as SagKind | '')); })}
        {(page?.data || []).map((d) => <EstibaButton key={d.id} label={`${sagNames[d.tipo]} ${d.numero ?? 'Borrador'} · ${d.estado} · ${d.fecha_actividad.slice(0, 10)}`} disabled={blocked} variant="secondary" onPress={() => void run(async () => { const detail = await api.detail(d.id); if (alive.current) { setDoc(detail); setReference(''); setResolvedBy(''); } })} />)}
        {page?.data.length === 0 ? <Text style={s.body}>No hay documentos con este filtro.</Text> : null}
        <View style={s.wrap}><EstibaButton label="Anterior" variant="secondary" disabled={blocked || !page || page.current_page <= 1} onPress={() => void run(() => loadPage(page!.current_page - 1))} /><Text style={s.body}>{page?.current_page || 1} / {page?.last_page || 1}</Text><EstibaButton label="Siguiente" variant="secondary" disabled={blocked || !page || page.current_page >= page.last_page} onPress={() => void run(() => loadPage(page!.current_page + 1))} /></View>
        <EstibaButton label="Actualizar" variant="secondary" disabled={blocked} onPress={() => void run(async () => { setConfig(await api.configuration()); await loadPage(); })} />
      </EstibaPanel> : null}
    </ScrollView>
  </KeyboardAvoidingView>;
}

const s = StyleSheet.create({ root: { flex: 1, backgroundColor: colors.canvas }, content: { padding: 16, gap: 16 }, gap: { gap: 10 }, wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, alignItems: 'center' }, body: { color: colors.text, fontSize: 15 }, label: { color: colors.text, fontSize: 15, fontWeight: '700' } });
