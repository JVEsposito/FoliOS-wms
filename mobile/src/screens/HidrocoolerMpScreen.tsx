import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { AuthSession } from '../domain/estiba';
import { FinishHydroCycle, HydroFilters, HydroLot, HydroSummary, HydroTray, ReleaseHydroCycle, StartHydroCycle } from '../domain/hidrocoolerMp';
import { getHydroSummary, listHydroLots, startHydroCycle, finishHydroCycle, releaseHydroCycle, shareHydroRegister } from '../services/hidrocoolerMpApi';
import { colors } from '../theme/colors';

type Action = { kind: 'start' | 'finish' | 'release'; lot: HydroLot; operationId: string } | null;
type Props = { auth: AuthSession; baseUrl: string; onLogout: () => void };
type Form = Record<string, string>;
type Option = { value: string; label: string };

const EMPTY_FILTERS: HydroFilters = { buscar: '', equipo: '', turno: '', destino: '', desde: '', hasta: '' };
const TRAYS: { value: HydroTray; label: string }[] = [
  { value: 'pendientes', label: 'Pendientes' }, { value: 'en_curso', label: 'En curso' },
  { value: 'retenidos', label: 'Retenidos' }, { value: 'historial', label: 'Historial' },
];
const YES_NO: Option[] = [{ value: '1', label: 'Sí' }, { value: '0', label: 'No' }];
const WATER: Option[] = [{ value: 'conforme', label: 'Conforme' }, { value: 'no_conforme', label: 'No conforme' }];
const DESTINATIONS: Option[] = [{ value: 'camara', label: 'Cámara MP' }, { value: 'proceso', label: 'Directo a Fruta a proceso' }];
const numberFormat = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 });

function localDateTime(date = new Date()) {
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16).replace('T', ' ');
}
function dateLabel(value: string | null | undefined) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
function label(value: string | null | undefined) {
  return ({ camara: 'Cámara MP', proceso: 'Directo a proceso', conforme: 'Conforme', no_conforme: 'No conforme', sin_novedad: 'Sin novedad', filtrado: 'Filtrado', recambio: 'Recambio' } as Record<string, string>)[value ?? ''] ?? (value || '—');
}
function errorMessage(reason: unknown) { return reason instanceof Error ? reason.message : 'No fue posible completar la operación.'; }
function toNumber(value: string, name: string, min: number, max: number, integer = false) {
  const input = value.trim().replace(',', '.');
  if (!input || !/^-?\d+(\.\d{1,2})?$/.test(input)) throw new Error(`${name}: ingresa un número válido de hasta dos decimales.`);
  const parsed = Number(input);
  if (parsed < min || parsed > max || (integer && !Number.isInteger(parsed))) throw new Error(`${name}: debe estar entre ${min} y ${max}${integer ? ' y ser entero' : ''}.`);
  return parsed;
}
function optionalNumber(value: string, name: string, min: number, max: number) {
  return value.trim() ? toNumber(value, name, min, max) : null;
}
function toDate(value: string, name: string) {
  const input = value.trim();
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(input)) throw new Error(`${name}: usa el formato AAAA-MM-DD HH:mm.`);
  const date = new Date(input.replace(' ', 'T'));
  if (Number.isNaN(date.getTime()) || localDateTime(date) !== input || date.getTime() > Date.now()) {
    throw new Error(`${name}: ingresa una fecha y hora válida que no sea futura.`);
  }
  return date.toISOString();
}
function required(value: string, name: string, min = 1, max = 2000) {
  const text = value.trim();
  if (text.length < min || text.length > max) throw new Error(`${name}: debe tener entre ${min} y ${max} caracteres.`);
  return text;
}
function optional(value: string) { return value.trim() || null; }

export function HidrocoolerMpScreen({ auth, baseUrl, onLogout }: Props) {
  const canConsult = auth.usuario.capacidades.puede_consultar_hidrocooler_materia_prima === true;
  const canOperate = auth.usuario.capacidades.puede_operar_hidrocooler_materia_prima === true;
  const canRelease = auth.usuario.capacidades.puede_supervisar_lotes_materia_prima === true;
  const [tray, setTray] = useState<HydroTray>('pendientes');
  const [filters, setFilters] = useState<HydroFilters>(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState<HydroFilters>(EMPTY_FILTERS);
  const [summary, setSummary] = useState<HydroSummary | null>(null);
  const [lots, setLots] = useState<HydroLot[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [busy, setBusy] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [action, setAction] = useState<Action>(null);
  const [form, setForm] = useState<Form>({});
  const [expanded, setExpanded] = useState<string | null>(null);

  useEffect(() => { if (canConsult) void load(1); }, [baseUrl, auth.token, tray, appliedFilters, canConsult]);

  const canFetchMore = page < lastPage;
  const filterDescription = useMemo(() => [appliedFilters.buscar, appliedFilters.equipo, appliedFilters.turno ? `Turno ${appliedFilters.turno}` : '', appliedFilters.destino ? label(appliedFilters.destino) : ''].filter(Boolean).join(' · '), [appliedFilters]);

  async function load(nextPage = 1) {
    setBusy(true); setError('');
    try {
      const [nextSummary, result] = await Promise.all([
        getHydroSummary(baseUrl, auth.token),
        listHydroLots(baseUrl, auth.token, tray, appliedFilters, nextPage),
      ]);
      setSummary(nextSummary);
      setLots((current) => nextPage === 1 ? result.data : [...current, ...result.data]);
      setPage(result.meta.current_page); setLastPage(result.meta.last_page);
    } catch (reason) { setError(errorMessage(reason)); }
    finally { setBusy(false); }
  }

  function openAction(kind: NonNullable<Action>['kind'], lot: HydroLot) {
    setError(''); setNotice('');
    setAction({ kind, lot, operationId: Crypto.randomUUID() });
    setForm(kind === 'start'
      ? { inicio_at: localDateTime(), equipo: '', turno: '', cantidad_bombas_funcionando: '', temperatura_inicial_c: '', temperatura_objetivo_c: '', temperatura_agua_inicial_c: '', cloro_libre_ppm: '', ph_agua: '', control_inicial_conforme: '', condicion_visual_agua: '', dosificador_operativo: '', manejo_agua: '', observacion_inicio: '' }
      : kind === 'finish'
        ? { termino_at: localDateTime(), temperatura_c: '', temperatura_agua_final_c: '', cloro_libre_final_ppm: '', ph_agua_final: '', control_final_conforme: '', condicion_visual_agua_final: '', dosificador_operativo_final: '', destino_salida: 'camara', observacion: '', accion_correctiva: '' }
        : { temperatura_verificacion_c: '', cloro_libre_verificacion_ppm: '', ph_agua_verificacion: '', control_verificacion_conforme: '', evaluacion_producto: '', verificacion_liberacion: '' });
  }

  function value(key: string) { return form[key] ?? ''; }
  function change(key: string, next: string) { setForm((current) => ({ ...current, [key]: next })); }

  async function submit() {
    if (!action || saving) return;
    try {
      setError('');
      if (action.kind === 'start') {
        if (!canOperate) throw new Error('Tu cuenta no puede iniciar ciclos.');
        const payload: StartHydroCycle = {
          operacion_id: action.operationId,
          equipo: required(value('equipo'), 'Equipo', 1, 100),
          turno: required(value('turno'), 'Turno') as 'A' | 'B',
          cantidad_bombas_funcionando: toNumber(value('cantidad_bombas_funcionando'), 'Bombas', 1, 20, true),
          inicio_at: toDate(value('inicio_at'), 'Inicio'),
          temperatura_inicial_c: toNumber(value('temperatura_inicial_c'), 'Temperatura inicial fruta', -20, 50),
          temperatura_objetivo_c: toNumber(value('temperatura_objetivo_c'), 'Temperatura objetivo fruta', -20, 50),
          temperatura_agua_inicial_c: optionalNumber(value('temperatura_agua_inicial_c'), 'Temperatura inicial agua', -20, 50),
          cloro_libre_ppm: toNumber(value('cloro_libre_ppm'), 'Cloro libre inicial', 0, 500),
          ph_agua: toNumber(value('ph_agua'), 'pH inicial', 0, 14),
          control_inicial_conforme: required(value('control_inicial_conforme'), 'Control inicial') === '1',
          condicion_visual_agua: required(value('condicion_visual_agua'), 'Condición visual') as StartHydroCycle['condicion_visual_agua'],
          dosificador_operativo: required(value('dosificador_operativo'), 'Dosificador') === '1',
          manejo_agua: required(value('manejo_agua'), 'Control del agua') as StartHydroCycle['manejo_agua'],
          observacion_inicio: optional(value('observacion_inicio')),
        };
        if (payload.observacion_inicio && payload.observacion_inicio.length > 2000) throw new Error('Observación: máximo 2000 caracteres.');
        setSaving(true);
        await startHydroCycle(baseUrl, auth.token, action.lot.id, payload);
        setNotice('Ciclo iniciado y lote bloqueado en Hidrocooler.');
      } else if (action.kind === 'finish') {
        if (!canOperate) throw new Error('Tu cuenta no puede finalizar ciclos.');
        const payload: FinishHydroCycle = {
          operacion_id: action.operationId,
          termino_at: toDate(value('termino_at'), 'Término'),
          temperatura_c: toNumber(value('temperatura_c'), 'Temperatura final fruta', -20, 50),
          temperatura_agua_final_c: optionalNumber(value('temperatura_agua_final_c'), 'Temperatura final agua', -20, 50),
          cloro_libre_final_ppm: toNumber(value('cloro_libre_final_ppm'), 'Cloro libre final', 0, 500),
          ph_agua_final: toNumber(value('ph_agua_final'), 'pH final', 0, 14),
          condicion_visual_agua_final: required(value('condicion_visual_agua_final'), 'Condición visual final') as FinishHydroCycle['condicion_visual_agua_final'],
          dosificador_operativo_final: required(value('dosificador_operativo_final'), 'Dosificador final') === '1',
          control_final_conforme: required(value('control_final_conforme'), 'Control final') === '1',
          destino_salida: required(value('destino_salida'), 'Destino') as FinishHydroCycle['destino_salida'],
          observacion: optional(value('observacion')),
          accion_correctiva: optional(value('accion_correctiva')),
        };
        if (new Date(payload.termino_at).getTime() < new Date(action.lot.hidrocooler?.inicio_at ?? '').getTime()) throw new Error('El término debe ser posterior al inicio del ciclo.');
        if ((payload.observacion?.length ?? 0) > 2000 || (payload.accion_correctiva?.length ?? 0) > 2000) throw new Error('Las observaciones y la acción correctiva admiten hasta 2000 caracteres.');
        setSaving(true);
        const updated = await finishHydroCycle(baseUrl, auth.token, action.lot.id, payload);
        setNotice(updated.estado === 'hidrocooler_retenido' ? 'Ciclo terminado: lote retenido para evaluación de supervisión.' : 'Ciclo terminado; destino de salida registrado.');
      } else {
        if (!canRelease) throw new Error('Solo supervisión puede liberar lotes retenidos.');
        if (value('control_verificacion_conforme') !== '1') throw new Error('Confirma que el control del agua cumple el procedimiento vigente.');
        const payload: ReleaseHydroCycle = {
          operacion_id: action.operationId,
          temperatura_verificacion_c: toNumber(value('temperatura_verificacion_c'), 'Temperatura verificada', -20, 50),
          cloro_libre_verificacion_ppm: toNumber(value('cloro_libre_verificacion_ppm'), 'Cloro libre verificado', 0, 500),
          ph_agua_verificacion: toNumber(value('ph_agua_verificacion'), 'pH verificado', 0, 14),
          control_verificacion_conforme: true,
          evaluacion_producto: required(value('evaluacion_producto'), 'Evaluación del producto', 10),
          verificacion_liberacion: required(value('verificacion_liberacion'), 'Acción correctiva y verificación', 10),
        };
        setSaving(true);
        await releaseHydroCycle(baseUrl, auth.token, action.lot.id, payload);
        setNotice('Lote liberado por supervisión con evaluación registrada.');
      }
      setAction(null); await load(1);
    } catch (reason) { setError(errorMessage(reason)); }
    finally { setSaving(false); }
  }

  async function exportRegister(format: 'pdf' | 'xlsx', blank: boolean) {
    if (saving) return;
    setSaving(true); setError('');
    try { await shareHydroRegister(baseUrl, auth.token, format, blank, appliedFilters); }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setSaving(false); }
  }

  if (!canConsult) return <View style={styles.center}><Text style={styles.error}>Tu cuenta no tiene acceso a Hidrocooler MP.</Text><Button title="Salir" onPress={onLogout} /></View>;
  if (!baseUrl) return <View style={styles.center}><Text style={styles.error}>Conecta la tablet al servidor para operar Hidrocooler.</Text><Button title="Salir" onPress={onLogout} /></View>;

  return (
    <View style={styles.root}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.header}>
          <View><Text style={styles.eyebrow}>MATERIA PRIMA · CONTROL DE ENFRIAMIENTO</Text><Text style={styles.title}>Hidrocooler</Text><Text style={styles.muted}>{summary?.temporada ? `${summary.temporada.nombre} · ${summary.temporada.codigo}` : 'Sin temporada activa'}</Text></View>
          <View style={styles.row}><Button title="Actualizar" onPress={() => void load(1)} /><Button title="Salir" onPress={onLogout} /></View>
        </View>
        {summary ? <View style={styles.row}>
          <Stat title="Pendientes" value={summary.pendientes} /><Stat title="En curso" value={summary.en_curso} />
          <Stat title="Retenidos" value={summary.retenidos} /><Stat title="Kilos en curso" value={numberFormat.format(summary.kilos_en_curso)} />
          <Stat title="Completados hoy" value={summary.completados_hoy} /><Stat title="Promedio hoy" value={`${summary.duracion_promedio_hoy} min`} />
        </View> : null}
        <View style={styles.section}><Text style={styles.sectionTitle}>Registros y planillas</Text><View style={styles.row}>
          <Button title="Registro Excel" onPress={() => void exportRegister('xlsx', false)} />
          <Button title="Registro PDF" onPress={() => void exportRegister('pdf', false)} />
          <Button title="Blanco Excel" onPress={() => void exportRegister('xlsx', true)} />
          <Button title="Blanco PDF" onPress={() => void exportRegister('pdf', true)} />
        </View></View>
        <View style={styles.row}>{TRAYS.map((item) => <Button key={item.value} title={item.label} active={tray === item.value} onPress={() => { setTray(item.value); setNotice(''); }} />)}</View>
        <View style={styles.section}><Text style={styles.sectionTitle}>Filtros</Text>
          <View style={styles.row}>
            <Field label="Lote, recepción, cliente, ciclo o equipo" value={filters.buscar} onChange={(v) => setFilters((f) => ({ ...f, buscar: v }))} />
            <Field label="Equipo" value={filters.equipo} onChange={(v) => setFilters((f) => ({ ...f, equipo: v }))} />
            <Choice label="Turno" value={filters.turno} options={[{ value: '', label: 'Todos' }, { value: 'A', label: 'A' }, { value: 'B', label: 'B' }]} onChange={(v) => setFilters((f) => ({ ...f, turno: v as HydroFilters['turno'] }))} />
            <Choice label="Destino" value={filters.destino} options={[{ value: '', label: 'Todos' }, ...DESTINATIONS]} onChange={(v) => setFilters((f) => ({ ...f, destino: v as HydroFilters['destino'] }))} />
            <Field label="Desde · AAAA-MM-DD" value={filters.desde} onChange={(v) => setFilters((f) => ({ ...f, desde: v }))} />
            <Field label="Hasta · AAAA-MM-DD" value={filters.hasta} onChange={(v) => setFilters((f) => ({ ...f, hasta: v }))} />
          </View><Button title="Aplicar filtros" active onPress={() => { setAppliedFilters({ ...filters }); setNotice(''); }} />
          {filterDescription ? <Text style={styles.muted}>{filterDescription}</Text> : null}
        </View>
        {error && !action ? <Text style={styles.error}>{error}</Text> : null}
        {notice ? <Text style={styles.notice}>{notice}</Text> : null}
        {busy ? <ActivityIndicator color={colors.cyan} /> : null}
        {!busy && !lots.length ? <Text style={styles.muted}>No hay lotes en esta bandeja con los filtros seleccionados.</Text> : null}
        {lots.map((lot) => <View style={styles.lot} key={lot.id}>
          <View style={styles.lotHeader}><View style={styles.grow}><Text style={styles.lotTitle}>{lot.numero_lote}</Text><Text style={styles.muted}>{lot.cliente?.nombre ?? 'Sin cliente'} · {lot.recepcion?.numero_recepcion ?? 'Sin recepción'}</Text></View><Text style={styles.badge}>{lot.estado === 'hidrocooler_retenido' ? 'Retenido' : lot.hidrocooler?.termino_at ? label(lot.hidrocooler.destino_salida) : lot.estado === 'hidrocooler_en_curso' ? 'En curso' : 'Pendiente'}</Text></View>
          <Text style={styles.fact}>{[lot.trazabilidad.especie, lot.trazabilidad.variedad, lot.trazabilidad.cuartel].filter(Boolean).join(' · ') || 'Producto sin detalle'} · CSG {lot.trazabilidad.csg || '—'}</Text>
          <Text style={styles.fact}>{lot.hidrocooler?.codigo ?? 'Ciclo por iniciar'} · {lot.hidrocooler?.equipo ?? 'Sin equipo'} · {lot.hidrocooler?.turno ? `Turno ${lot.hidrocooler.turno}` : 'Sin turno'}</Text>
          <Text style={styles.fact}>{lot.hidrocooler?.cantidad_envases ?? lot.envases.cantidad_primarios} {lot.envases.primario} · {numberFormat.format(lot.hidrocooler?.kilos_netos ?? lot.pesos.kilos_netos_confirmados)} kg · {lot.hidrocooler?.operador ?? 'Sin operador'}</Text>
          <Text style={styles.fact}>Fruta: {lot.hidrocooler?.temperatura_inicial_c ?? '—'} °C → objetivo {lot.hidrocooler?.temperatura_objetivo_c ?? '—'} °C → final {lot.hidrocooler?.temperatura_c ?? '—'} °C</Text>
          <Text style={styles.muted}>Inicio {dateLabel(lot.hidrocooler?.inicio_at ?? lot.confirmado_at)} · término {dateLabel(lot.hidrocooler?.termino_at)}</Text>
          {lot.hidrocooler?.motivo_retencion ? <Text style={styles.error}>Desviación: {lot.hidrocooler.motivo_retencion}</Text> : null}
          <View style={styles.row}>
            <Button title={expanded === lot.id ? 'Ocultar controles' : 'Ver controles'} onPress={() => setExpanded(expanded === lot.id ? null : lot.id)} />
            {canOperate && lot.estado === 'pendiente_hidrocooler' ? <Button title="Iniciar ciclo" active onPress={() => openAction('start', lot)} /> : null}
            {canOperate && lot.estado === 'hidrocooler_en_curso' ? <Button title="Finalizar ciclo" active onPress={() => openAction('finish', lot)} /> : null}
            {canRelease && lot.estado === 'hidrocooler_retenido' ? <Button title="Evaluar y liberar" active onPress={() => openAction('release', lot)} /> : null}
          </View>
          {expanded === lot.id && lot.hidrocooler ? <CycleDetails lot={lot} /> : null}
        </View>)}
        {canFetchMore ? <Button title="Cargar más lotes" onPress={() => void load(page + 1)} /> : null}
      </ScrollView>
      <Modal visible={Boolean(action)} animationType="slide" onRequestClose={() => { if (!saving) setAction(null); }}>
        {action ? <View style={styles.root}><ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.eyebrow}>{action.kind === 'start' ? 'INICIO TRAZABLE' : action.kind === 'finish' ? 'CIERRE Y DESTINO' : 'DISPOSICIÓN DE PRODUCTO'}</Text>
          <Text style={styles.title}>{action.kind === 'start' ? 'Iniciar' : action.kind === 'finish' ? 'Finalizar' : 'Evaluar'} {action.lot.numero_lote}</Text>
          <Text style={styles.muted}>{action.lot.cliente?.nombre} · {action.lot.envases.cantidad_primarios} {action.lot.envases.primario} · {numberFormat.format(action.lot.pesos.kilos_netos_confirmados)} kg</Text>
          {action.kind === 'start' ? <>
            <Text style={styles.notice}>El peso y la composición del lote quedarán registrados al iniciar el ciclo.</Text>
            <Field label="Equipo / Hidrocooler *" value={value('equipo')} onChange={(v) => change('equipo', v)} />
            {summary?.equipos?.length ? <View style={styles.row}>{summary.equipos.map((item) => <Button key={item} title={item} active={value('equipo') === item} onPress={() => change('equipo', item)} />)}</View> : null}
            <Choice label="Turno *" value={value('turno')} options={[{ value: 'A', label: 'Turno A' }, { value: 'B', label: 'Turno B' }]} onChange={(v) => change('turno', v)} />
            <Field label="Bombas funcionando (1–20) *" value={value('cantidad_bombas_funcionando')} onChange={(v) => change('cantidad_bombas_funcionando', v)} numeric />
            <Text style={styles.fact}>Operador: {auth.usuario.nombre}</Text>
            <Field label="Inicio · AAAA-MM-DD HH:mm *" value={value('inicio_at')} onChange={(v) => change('inicio_at', v)} />
            <Field label="Temperatura inicial fruta °C *" value={value('temperatura_inicial_c')} onChange={(v) => change('temperatura_inicial_c', v)} numeric />
            <Field label="Temperatura objetivo fruta °C *" value={value('temperatura_objetivo_c')} onChange={(v) => change('temperatura_objetivo_c', v)} numeric />
            <Field label="Temperatura inicial agua °C" value={value('temperatura_agua_inicial_c')} onChange={(v) => change('temperatura_agua_inicial_c', v)} numeric />
            <Field label="Cloro libre ppm *" value={value('cloro_libre_ppm')} onChange={(v) => change('cloro_libre_ppm', v)} numeric />
            <Field label="pH del agua *" value={value('ph_agua')} onChange={(v) => change('ph_agua', v)} numeric />
            <Choice label="Cloro y pH conformes *" value={value('control_inicial_conforme')} options={YES_NO} onChange={(v) => change('control_inicial_conforme', v)} />
            <Choice label="Condición visual del agua *" value={value('condicion_visual_agua')} options={WATER} onChange={(v) => change('condicion_visual_agua', v)} />
            <Choice label="Dosificador operativo *" value={value('dosificador_operativo')} options={YES_NO} onChange={(v) => change('dosificador_operativo', v)} />
            <Choice label="Control del agua *" value={value('manejo_agua')} options={[{ value: 'sin_novedad', label: 'Sin novedad' }, { value: 'filtrado', label: 'Filtrado' }, { value: 'recambio', label: 'Recambio' }]} onChange={(v) => change('manejo_agua', v)} />
            <Field label="Observación de inicio" value={value('observacion_inicio')} onChange={(v) => change('observacion_inicio', v)} multiline />
          </> : action.kind === 'finish' ? <>
            <Text style={styles.muted}>{action.lot.hidrocooler?.codigo} · inicio {dateLabel(action.lot.hidrocooler?.inicio_at)}</Text>
            <Field label="Término · AAAA-MM-DD HH:mm *" value={value('termino_at')} onChange={(v) => change('termino_at', v)} />
            <Field label="Temperatura final fruta °C *" value={value('temperatura_c')} onChange={(v) => change('temperatura_c', v)} numeric />
            <Field label="Temperatura final agua °C" value={value('temperatura_agua_final_c')} onChange={(v) => change('temperatura_agua_final_c', v)} numeric />
            <Field label="Cloro libre final ppm *" value={value('cloro_libre_final_ppm')} onChange={(v) => change('cloro_libre_final_ppm', v)} numeric />
            <Field label="pH final del agua *" value={value('ph_agua_final')} onChange={(v) => change('ph_agua_final', v)} numeric />
            <Choice label="Cloro y pH finales conformes *" value={value('control_final_conforme')} options={YES_NO} onChange={(v) => change('control_final_conforme', v)} />
            <Choice label="Condición visual final del agua *" value={value('condicion_visual_agua_final')} options={WATER} onChange={(v) => change('condicion_visual_agua_final', v)} />
            <Choice label="Dosificador al término *" value={value('dosificador_operativo_final')} options={YES_NO} onChange={(v) => change('dosificador_operativo_final', v)} />
            <Choice label="Destino después del Hidrocooler *" value={value('destino_salida')} options={DESTINATIONS} onChange={(v) => change('destino_salida', v)} />
            <Field label="Observación de término" value={value('observacion')} onChange={(v) => change('observacion', v)} multiline />
            <Field label="Acción correctiva aplicada" value={value('accion_correctiva')} onChange={(v) => change('accion_correctiva', v)} multiline />
            <Text style={styles.notice}>Un control fuera de norma o temperatura sobre el objetivo retendrá el lote para evaluación de supervisión.</Text>
          </> : <>
            <Text style={styles.error}>Retenido: {action.lot.hidrocooler?.motivo_retencion}</Text>
            <Text style={styles.fact}>Destino al liberar: {label(action.lot.hidrocooler?.destino_salida)}</Text>
            <Field label="Temperatura de fruta verificada °C *" value={value('temperatura_verificacion_c')} onChange={(v) => change('temperatura_verificacion_c', v)} numeric />
            <Field label="Cloro libre verificado ppm *" value={value('cloro_libre_verificacion_ppm')} onChange={(v) => change('cloro_libre_verificacion_ppm', v)} numeric />
            <Field label="pH verificado *" value={value('ph_agua_verificacion')} onChange={(v) => change('ph_agua_verificacion', v)} numeric />
            <Choice label="Control del agua conforme al procedimiento *" value={value('control_verificacion_conforme')} options={[{ value: '1', label: 'Sí, verificado conforme' }]} onChange={(v) => change('control_verificacion_conforme', v)} />
            <Field label="Evaluación del producto afectado (mínimo 10 caracteres) *" value={value('evaluacion_producto')} onChange={(v) => change('evaluacion_producto', v)} multiline />
            <Field label="Acción correctiva y verificación (mínimo 10 caracteres) *" value={value('verificacion_liberacion')} onChange={(v) => change('verificacion_liberacion', v)} multiline />
          </>}
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <View style={styles.row}>
            <Button title="Cancelar" disabled={saving} onPress={() => { setAction(null); setError(''); }} />
            <Button title={saving ? 'Guardando…' : action.kind === 'start' ? 'Iniciar ciclo' : action.kind === 'finish' ? 'Finalizar ciclo' : 'Liberar bajo supervisión'} active disabled={saving} onPress={() => void submit()} />
          </View>
        </ScrollView></View> : null}
      </Modal>
    </View>
  );
}

function CycleDetails({ lot }: { lot: HydroLot }) {
  const cycle = lot.hidrocooler;
  if (!cycle) return null;
  return <View style={styles.details}>
    <Text style={styles.fact}>Operador {cycle.operador} · {cycle.cantidad_bombas_funcionando} bombas · duración {cycle.duracion_minutos ?? '—'} min</Text>
    <Text style={styles.fact}>Inicio: agua {cycle.temperatura_agua_inicial_c ?? '—'} °C · cloro {cycle.cloro_libre_ppm} ppm · pH {cycle.ph_agua} · visual {label(cycle.condicion_visual_agua)} · dosificador {cycle.dosificador_operativo ? 'sí' : 'no'} · control {cycle.control_inicial_conforme ? 'conforme' : 'no conforme'} · {label(cycle.manejo_agua)}</Text>
    {cycle.termino_at ? <Text style={styles.fact}>Término: agua {cycle.temperatura_agua_final_c ?? '—'} °C · cloro {cycle.cloro_libre_final_ppm ?? '—'} ppm · pH {cycle.ph_agua_final ?? '—'} · visual {label(cycle.condicion_visual_agua_final)} · dosificador {cycle.dosificador_operativo_final ? 'sí' : 'no'} · control {cycle.control_final_conforme ? 'conforme' : 'no conforme'}</Text> : null}
    {cycle.observacion_inicio ? <Text style={styles.fact}>Observación inicial: {cycle.observacion_inicio}</Text> : null}
    {cycle.observacion ? <Text style={styles.fact}>Observación final: {cycle.observacion}</Text> : null}
    {cycle.accion_correctiva ? <Text style={styles.fact}>Acción correctiva: {cycle.accion_correctiva}</Text> : null}
    {cycle.liberado_at ? <Text style={styles.fact}>Liberado por {cycle.liberado_por} · {dateLabel(cycle.liberado_at)} · evaluación: {cycle.evaluacion_producto} · verificación: {cycle.verificacion_liberacion}</Text> : null}
  </View>;
}

function Button({ title, onPress, active = false, disabled = false }: { title: string; onPress: () => void; active?: boolean; disabled?: boolean }) {
  return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[styles.button, active && styles.buttonActive, disabled && styles.disabled]}><Text style={[styles.buttonText, active && styles.buttonTextActive]}>{title}</Text></Pressable>;
}
function Stat({ title, value }: { title: string; value: string | number }) {
  return <View style={styles.stat}><Text style={styles.eyebrow}>{title}</Text><Text style={styles.statValue}>{value}</Text></View>;
}
function Field({ label: title, value, onChange, numeric = false, multiline = false }: { label: string; value: string; onChange: (value: string) => void; numeric?: boolean; multiline?: boolean }) {
  return <View style={styles.field}><Text style={styles.fieldLabel}>{title}</Text><TextInput style={[styles.input, multiline && styles.multiline]} value={value} onChangeText={onChange} placeholderTextColor={colors.muted} keyboardType={numeric ? 'decimal-pad' : 'default'} multiline={multiline} autoCapitalize="none" /></View>;
}
function Choice({ label: title, value, options, onChange }: { label: string; value: string; options: Option[]; onChange: (value: string) => void }) {
  return <View style={styles.field}><Text style={styles.fieldLabel}>{title}</Text><View style={styles.row}>{options.map((option) => <Button key={option.value} title={option.label} active={value === option.value} onPress={() => onChange(option.value)} />)}</View></View>;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.background },
  content: { padding: 18, gap: 14, paddingBottom: 48 },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 16, backgroundColor: colors.background },
  header: { flexDirection: 'row', justifyContent: 'space-between', flexWrap: 'wrap', gap: 16 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center' },
  grow: { flex: 1 },
  eyebrow: { color: colors.cyan, fontSize: 10, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.7 },
  title: { color: colors.text, fontSize: 26, fontWeight: '900' },
  muted: { color: colors.muted, fontSize: 12, lineHeight: 18 },
  error: { color: colors.red, fontSize: 13, lineHeight: 19, fontWeight: '700' },
  notice: { color: colors.green, fontSize: 13, lineHeight: 19 },
  section: { backgroundColor: colors.panel, borderColor: colors.border, borderWidth: 1, borderRadius: 12, padding: 14, gap: 10 },
  sectionTitle: { color: colors.text, fontSize: 16, fontWeight: '800' },
  stat: { flexGrow: 1, minWidth: 140, padding: 12, backgroundColor: colors.panelStrong, borderRadius: 9 },
  statValue: { color: colors.text, fontSize: 19, fontWeight: '900', marginTop: 4 },
  lot: { padding: 15, borderWidth: 1, borderColor: colors.border, borderRadius: 12, backgroundColor: colors.panel, gap: 7 },
  lotHeader: { flexDirection: 'row', justifyContent: 'space-between', gap: 12, alignItems: 'center' },
  lotTitle: { color: colors.text, fontSize: 19, fontWeight: '800' },
  badge: { color: colors.cyan, fontSize: 12, fontWeight: '800' },
  fact: { color: colors.text, fontSize: 13, lineHeight: 20 },
  details: { padding: 12, backgroundColor: colors.backgroundDeep, gap: 6, borderRadius: 8 },
  button: { borderColor: colors.cyanDark, borderWidth: 1, borderRadius: 8, paddingVertical: 9, paddingHorizontal: 13, backgroundColor: colors.panelStrong },
  buttonActive: { backgroundColor: colors.cyan, borderColor: colors.cyan },
  disabled: { opacity: 0.5 },
  buttonText: { color: colors.text, fontWeight: '700', fontSize: 12 },
  buttonTextActive: { color: colors.accentText },
  field: { minWidth: 200, flexGrow: 1, gap: 5 },
  fieldLabel: { color: colors.muted, fontSize: 12, fontWeight: '700' },
  input: { color: colors.text, backgroundColor: colors.panelStrong, borderColor: colors.border, borderWidth: 1, borderRadius: 8, padding: 11, minHeight: 44 },
  multiline: { minHeight: 82, textAlignVertical: 'top' },
});
