import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';

import { AuthSession } from '../domain/estiba';
import { useOperationalPolling } from '../hooks/useOperationalPolling';
import {
  RegisterValidationPayload,
  ValidationAttempt,
  ValidationCatalog,
  ValidationLine,
  ValidationOutboxItem,
  ValidationResult,
  ValidationSessionSnapshot,
  ValidationShift,
} from '../domain/validation';
import { ApiError } from '../services/apiError';
import {
  getMyValidationSession,
  getValidationCatalog,
  listRecentValidations,
  listValidationsByFolio,
  registerValidation,
} from '../services/validationApi';
import {
  enqueueValidation,
  loadCachedValidationCatalog,
  loadCachedValidationSession,
  loadValidationOutbox,
  loadValidationWorkContext,
  markValidationOutboxItem,
  removeValidationFromOutbox,
  retryValidationOutboxItem,
  saveValidationCatalog,
  saveValidationSession,
  saveValidationWorkContext,
} from '../services/validationOfflineStore';
import { colors } from '../theme/colors';
import { isPdaBuild } from '../config/appVariant';
import { ScanInput, ScanInputHandle } from '../components/ui/ScanInput';

type ValidationScreenProps = {
  auth: AuthSession;
  baseUrl: string | null;
  onLogout: () => void;
};

type Option = { value: string; label: string; search?: string };

type ObservationDraft = {
  result: Exclude<ValidationResult, 'aprobado'>;
  reason: string;
  note: string;
};

type FolioReview = {
  numero_folio: string;
  status: 'nuevo' | ValidationResult;
  attempt: ValidationAttempt | null;
};

// Cada línea del bulto: CSG, cajas y, cuando la etiqueta los trae, lote MP y proceso de packing.
type OriginDraft = { key: string; originId: string; boxes: string; lot: string; process: string };

export function ValidationScreen({ auth, baseUrl, onLogout }: ValidationScreenProps) {
  const { height, width } = useWindowDimensions();
  const compact = width < 700 || width < height;
  // PDA de mano (≈360 dp de ancho): se condensan encabezados y el contexto de
  // jornada para que el folio y la decisión queden a pocos desplazamientos.
  const tight = isPdaBuild || width < 420;
  const [editingContext, setEditingContext] = useState(false);
  const folioInput = useRef<ScanInputHandle>(null);
  const flushing = useRef(false);
  const synchronizing = useRef(false);
  const [catalog, setCatalog] = useState<ValidationCatalog | null>(null);
  const [outbox, setOutbox] = useState<ValidationOutboxItem[]>([]);
  const [recent, setRecent] = useState<ValidationAttempt[]>([]);
  const [validationSession, setValidationSession] = useState<ValidationSessionSnapshot | null>(null);
  const [sessionExpanded, setSessionExpanded] = useState(false);
  const [loadingMoreSession, setLoadingMoreSession] = useState(false);
  const [busy, setBusy] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [online, setOnline] = useState(Boolean(baseUrl));
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [lastSync, setLastSync] = useState<string | null>(null);
  const [folio, setFolio] = useState('');
  const [line, setLine] = useState<ValidationLine | null>(null);
  const [shift, setShift] = useState<ValidationShift | null>(null);
  const [folioReview, setFolioReview] = useState<FolioReview | null>(null);
  const [boxes, setBoxes] = useState('');
  const [packageType, setPackageType] = useState<'pallet' | 'saldo'>('pallet');
  const [species, setSpecies] = useState('');
  const [variety, setVariety] = useState('');
  const [caliber, setCaliber] = useState('');
  const [packageName, setPackageName] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [client, setClient] = useState('');
  const [brand, setBrand] = useState('');
  const [packingDate, setPackingDate] = useState(todayLocal());
  const [originDrafts, setOriginDrafts] = useState<OriginDraft[]>([newOriginDraft()]);
  const [observation, setObservation] = useState<ObservationDraft | null>(null);

  const userId = auth.usuario.id;
  const deviceId = auth.dispositivo.id;
  const canReject = auth.usuario.capacidades.puede_rechazar_pallets;
  const sessionCacheId = auth.sesion?.id ?? 'actual';
  const sessionStartedAt = auth.sesion?.iniciada_at ?? validationSession?.sesion.iniciada_at ?? null;
  const currentSessionOutbox = useMemo(
    () => outbox.filter((item) => !sessionStartedAt
      || new Date(item.payload.generado_dispositivo_at).getTime() >= new Date(sessionStartedAt).getTime()),
    [outbox, sessionStartedAt],
  );
  const pendingSessionOutbox = currentSessionOutbox
    .filter((item) => item.status === 'pendiente');
  const unconfirmedSessionOutbox = currentSessionOutbox
    .filter((item) => item.status !== 'conflicto');
  const previousSessionOutbox = outbox
    .filter((item) => !currentSessionOutbox.some((current) => current.id === item.id));
  const visibleSessionAttempts = validationSession?.data.slice(
    0,
    sessionExpanded ? validationSession.data.length : Math.max(0, 6 - currentSessionOutbox.length),
  ) ?? [];
  const terminalDecision = folioReview?.status === 'aprobado' || folioReview?.status === 'rechazado';
  const observedAttempt = folioReview?.status === 'observado' ? folioReview.attempt : null;

  const activeArticles = useMemo(
    () => catalog?.articulos.filter((item) => item.activo) ?? [],
    [catalog],
  );
  const categoryOptions = useMemo(
    () => (catalog?.categorias ?? [])
      .filter((item) => item.activo)
      .map((item) => ({ value: item.id, label: item.nombre, search: item.codigo_externo ?? '' })),
    [catalog],
  );
  const selectedCategory = useMemo(
    () => catalog?.categorias.find((item) => item.id === categoryId && item.activo) ?? null,
    [catalog, categoryId],
  );
  const speciesOptions = useMemo(
    () => uniqueOptions(activeArticles.map((item) => item.especie)),
    [activeArticles],
  );
  const varietyOptions = useMemo(
    () => {
      const originIds = originDrafts.map((draft) => draft.originId).filter(Boolean);
      return uniqueOptions(activeArticles.filter((item) => (!species || item.especie === species)
        && originIds.every((id) => catalog?.combinaciones.some((combination) => combination.articulo_validacion_id === item.id && combination.origen_validacion_id === id))).map((item) => item.variedad));
    },
    [activeArticles, species, originDrafts, catalog],
  );
  const caliberOptions = useMemo(
    () => uniqueOptions(activeArticles.filter((item) => (!species || item.especie === species) && (!variety || item.variedad === variety)).map((item) => item.calibre)),
    [activeArticles, species, variety],
  );
  const packageOptions = useMemo(
    () => uniqueOptions(activeArticles.filter((item) => (!species || item.especie === species) && (!variety || item.variedad === variety) && (!caliber || item.calibre === caliber)).map((item) => item.envase)),
    [activeArticles, species, variety, caliber],
  );
  const selectedArticle = useMemo(
    () => activeArticles.find((item) => item.especie === species && item.variedad === variety && item.calibre === caliber && item.envase === packageName) ?? null,
    [activeArticles, species, variety, caliber, packageName],
  );

  const eligibleOrigins = useMemo(() => catalog?.origenes.filter((item) => item.activo) ?? [], [catalog]);
  const clientOptions = useMemo(
    () => uniqueOptions(eligibleOrigins.map((item) => item.cliente)),
    [eligibleOrigins],
  );
  const brandOptions = useMemo(
    () => uniqueOptions(eligibleOrigins.filter((item) => !client || item.cliente === client).map((item) => item.marca)),
    [eligibleOrigins, client],
  );
  const csgOptions = useMemo(
    () => eligibleOrigins
      .filter((item) => (!client || item.cliente === client) && (!brand || item.marca === brand))
      .map((item) => ({
        value: item.id,
        label: `${item.csg}${item.predio ? ` — ${item.predio}` : ''}`,
        search: `${item.csg} ${item.predio ?? ''}`,
      })),
    [eligibleOrigins, client, brand],
  );
  const selectedOrigins = useMemo(
    () => originDrafts.map((draft) => eligibleOrigins.find((item) => (
      item.id === draft.originId && item.cliente === client && item.marca === brand
    )) ?? null),
    [eligibleOrigins, client, brand, originDrafts],
  );
  const selectedCombinations = useMemo(
    () => selectedOrigins.map((origin) => catalog?.combinaciones.find((item) => (
      item.articulo_validacion_id === selectedArticle?.id
      && item.origen_validacion_id === origin?.id
    )) ?? null),
    [catalog, selectedArticle, selectedOrigins],
  );
  const compositionBoxes = originDrafts.reduce((sum, draft) => sum + Number(draft.boxes || 0), 0);
  const selectedCombination = originDrafts.length > 0
    && selectedOrigins.every(Boolean)
    && selectedCombinations.every(Boolean);

  useEffect(() => {
    void initialize();
  }, []);

  useOperationalPolling(
    flushOutbox,
    {
      intervalMs: 30000,
      enabled: Boolean(baseUrl),
      onResume: synchronize,
    },
  );

  async function initialize() {
    setBusy(true);
    setError('');
    try {
      const [cached, cachedSession, queued, savedContext] = await Promise.all([
        loadCachedValidationCatalog(userId, deviceId),
        loadCachedValidationSession(userId, deviceId, sessionCacheId),
        loadValidationOutbox(userId, deviceId),
        loadValidationWorkContext(userId, deviceId),
      ]);
      if (cached) setCatalog(cached);
      if (cachedSession) setValidationSession(cachedSession);
      setOutbox(queued);
      if (savedContext) {
        setLine(savedContext.linea_proceso);
        setShift(savedContext.turno);
      }
      await synchronize({ knownCatalog: cached });
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
      setTimeout(() => folioInput.current?.focus(), 250);
    }
  }

  async function refreshSession(page = 1, append = false) {
    if (!baseUrl) return null;

    const snapshot = await getMyValidationSession(baseUrl, auth.token, page);
    const combined = append && validationSession
      ? {
        ...snapshot,
        data: [
          ...validationSession.data,
          ...snapshot.data.filter((item) => !validationSession.data.some((current) => current.id === item.id)),
        ],
      }
      : snapshot;

    setValidationSession(combined);
    await saveValidationSession(userId, deviceId, sessionCacheId, combined);
    setOnline(true);
    return combined;
  }

  async function loadMoreSession() {
    if (!validationSession || validationSession.meta.current_page >= validationSession.meta.last_page) return;

    setLoadingMoreSession(true);
    try {
      await refreshSession(validationSession.meta.current_page + 1, true);
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setLoadingMoreSession(false);
    }
  }

  async function synchronize({
    notify = false,
    knownCatalog = catalog,
  }: {
    notify?: boolean;
    knownCatalog?: ValidationCatalog | null;
  } = {}) {
    if (!baseUrl) {
      setOnline(false);
      if (notify) setError('Configura el servidor antes de sincronizar.');
      return;
    }
    if (synchronizing.current) return;
    synchronizing.current = true;
    setSyncing(true);

    try {
      const [loaded, latest] = await Promise.all([
        getValidationCatalog(baseUrl, auth.token, knownCatalog),
        listRecentValidations(baseUrl, auth.token),
      ]);
      const activeCatalog = loaded ?? knownCatalog;
      if (!activeCatalog) {
        throw new Error('No existe un catálogo disponible para validación.');
      }
      if (loaded) {
        await saveValidationCatalog(userId, deviceId, loaded);
        setCatalog(loaded);
      } else if (knownCatalog) {
        setCatalog(knownCatalog);
      }
      setRecent(latest);
      setOnline(true);
      setLastSync(new Date().toISOString());
      await flushOutbox();
      if (notify) {
        setError('');
        setNotice(
          `Catálogo v${activeCatalog.temporada.version_catalogo} y sesión actualizados.`,
        );
      }
    } catch (reason) {
      if (reason instanceof ApiError && reason.status === 401) {
        Alert.alert('Sesión vencida', 'Vuelve a iniciar el turno.');
        onLogout();
        return;
      }
      setOnline(!(reason instanceof ApiError && reason.status === 0));
      if ((!knownCatalog && !catalog) || notify) setError(messageFrom(reason));
    } finally {
      synchronizing.current = false;
      setSyncing(false);
    }
  }

  async function flushOutbox() {
    if (!baseUrl || flushing.current) return;
    flushing.current = true;
    try {
      let items = await loadValidationOutbox(userId, deviceId);
      for (const item of items.filter((candidate) => candidate.status === 'pendiente')) {
        try {
          await registerValidation(baseUrl, auth.token, item.payload);
          items = await removeValidationFromOutbox(userId, deviceId, item.id);
          setOnline(true);
        } catch (reason) {
          if (reason instanceof ApiError && reason.status === 0) {
            setOnline(false);
            break;
          }
          if (reason instanceof ApiError && reason.status === 409) {
            items = await markValidationOutboxItem(userId, deviceId, item.id, 'conflicto', reason.message);
            continue;
          }
          items = await markValidationOutboxItem(userId, deviceId, item.id, 'error', messageFrom(reason));
        }
      }
      setOutbox(items);
      try {
        const [latest] = await Promise.all([
          listRecentValidations(baseUrl, auth.token),
          refreshSession(),
        ]);
        setRecent(latest);
      } catch {
        // Conserva la última sesión visible si la red cae después de enviar la bandeja.
      }
    } finally {
      flushing.current = false;
    }
  }

  function clearOrigin() {
    setClient(''); setBrand(''); setOriginDrafts([newOriginDraft()]);
  }

  function handleFolioChange(value: string) {
    setFolio(value);
    if (folioReview && normalizeFolio(value) !== folioReview.numero_folio) {
      setFolioReview(null);
      setObservation(null);
    }
  }

  function applyAttempt(attempt: ValidationAttempt): boolean {
    if (!catalog) {
      setError('No existe un catálogo sincronizado para recuperar el folio.');
      return false;
    }
    if (attempt.temporada_id !== catalog.temporada.id) {
      setError('El folio pertenece a otra temporada y no puede resolverse con el catálogo activo.');
      return false;
    }

    const article = catalog.articulos.find((item) => item.id === attempt.articulo_validacion_id && item.activo);
    const origin = catalog.origenes.find((item) => item.id === attempt.origen_validacion_id && item.activo);
    const category = catalog.categorias.find((item) => item.id === attempt.categoria_validacion_id && item.activo);
    const combination = catalog.combinaciones.find((item) => (
      item.articulo_validacion_id === article?.id
      && item.origen_validacion_id === origin?.id
    ));

    if (!article || !origin || !category || !combination) {
      setError('La información guardada del folio ya no está habilitada en el catálogo activo. Requiere revisión de supervisión.');
      return false;
    }

    setPackageType(attempt.tipo_bulto);
    setBoxes(String(attempt.cantidad_cajas));
    setCategoryId(category.id);
    setSpecies(article.especie);
    setVariety(article.variedad);
    setCaliber(article.calibre);
    setPackageName(article.envase);
    setClient(origin.cliente);
    setBrand(origin.marca);
    const savedComposition = attempt.catalogo.composicion?.length
      ? attempt.catalogo.composicion
      : [{
        origen_validacion_id: origin.id,
        cantidad_cajas: attempt.cantidad_cajas,
      }];
    if (savedComposition.some((line) => !catalog.origenes.some((item) => (
      item.id === line.origen_validacion_id && item.activo
    )))) {
      setError('Uno de los CSG guardados ya no está habilitado en el catálogo activo. Requiere revisión de supervisión.');
      return false;
    }
    setOriginDrafts(savedComposition.map((line) => ({
      key: newOriginDraft().key,
      originId: line.origen_validacion_id,
      boxes: String(line.cantidad_cajas),
      lot: 'lote_materia_prima' in line ? line.lote_materia_prima ?? '' : '',
      process: 'proceso_packing' in line ? line.proceso_packing ?? '' : '',
    })));
    setPackingDate(attempt.catalogo.fecha_embalaje ?? todayLocal());
    setObservation(null);
    setFolioReview({
      numero_folio: attempt.numero_folio,
      status: attempt.resultado,
      attempt,
    });
    return true;
  }

  async function inspectFolio(requestedFolio?: string) {
    const normalized = normalizeFolio(requestedFolio ?? folio);
    if (!normalized) {
      setError('Escanea o ingresa el folio.');
      return;
    }

    setFolio(normalized);
    setError('');
    setNotice('');

    const localItem = outbox.find((item) => normalizeFolio(item.payload.numero_folio) === normalized);
    if (localItem) {
      setFolioReview(null);
      setError(`El folio ${normalized} posee una validación local ${statusLabel(localItem.status).toLowerCase()}. Sincronízala antes de registrar otro intento.`);
      return;
    }

    const cachedAttempts = recent.filter((item) => (
      item.numero_folio === normalized && item.estado === 'aceptada'
    ));

    setBusy(true);
    try {
      let attempts: ValidationAttempt[];
      if (!baseUrl) {
        attempts = cachedAttempts;
      } else {
        try {
          attempts = await listValidationsByFolio(baseUrl, auth.token, normalized);
          setOnline(true);
        } catch (reason) {
          if (reason instanceof ApiError && reason.status === 401) {
            Alert.alert('Sesión vencida', 'Vuelve a iniciar el turno.');
            onLogout();
            return;
          }
          if (reason instanceof ApiError && reason.status === 0 && cachedAttempts.length) {
            setOnline(false);
            attempts = cachedAttempts;
          } else {
            throw reason;
          }
        }
      }

      const accepted = attempts.find((item) => item.estado === 'aceptada') ?? null;
      if (!accepted) {
        if (!baseUrl && cachedAttempts.length === 0) {
          setError('Conecta la PDA para comprobar si el folio posee validaciones anteriores.');
          setFolioReview(null);
          return;
        }
        setFolioReview({ numero_folio: normalized, status: 'nuevo', attempt: null });
        setNotice(`${normalized} no posee validaciones anteriores en la temporada activa.`);
        return;
      }

      if (!applyAttempt(accepted)) return;
      if (accepted.resultado === 'observado') {
        setNotice(`${normalized} recuperado desde el intento ${accepted.numero_intento}. Corrige los datos y registra una nueva decisión.`);
      } else if (accepted.resultado === 'aprobado') {
        setNotice(`${normalized} ya fue aprobado en el intento ${accepted.numero_intento}.`);
      } else {
        setNotice(`${normalized} posee un rechazo definitivo en el intento ${accepted.numero_intento}.`);
      }
    } catch (reason) {
      setError(messageFrom(reason));
    } finally {
      setBusy(false);
    }
  }

  function validateForm(result?: ValidationResult) {
    if (!catalog) return 'No existe un catálogo sincronizado en esta PDA.';
    if (!line || !shift) return 'Selecciona la línea de proceso y el turno antes de validar.';
    if (!folio.trim()) return 'Escanea o ingresa el folio.';
    if (terminalDecision) return 'El folio ya posee una decisión final y no admite una nueva validación.';
    if (!Number.isInteger(Number(boxes)) || Number(boxes) < 1) return 'Ingresa una cantidad válida de cajas.';
    if (!selectedCategory) return 'Selecciona una categoría.';
    if (!selectedArticle) return 'Completa especie, variedad, calibre y envase.';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(packingDate)) return 'Ingresa una fecha de embalaje válida.';
    if (selectedOrigins.some((origin) => !origin)) return 'Completa todos los CSG del bulto.';
    if (new Set(originDrafts.map(compositionKey)).size !== originDrafts.length) return 'No repitas la misma combinación de CSG, lote y proceso.';
    if (!selectedCombination) return 'Una combinación artículo–CSG no está habilitada.';
    if (originDrafts.some((draft) => !Number.isInteger(Number(draft.boxes)) || Number(draft.boxes) < 1)) return 'Ingresa las cajas de cada CSG.';
    // Lote MP y proceso de packing son obligatorios para trazar el pallet (salvo un rechazo).
    if (result !== 'rechazado' && originDrafts.some((draft) => !normalizeCode(draft.lot) || !normalizeCode(draft.process))) {
      return 'Ingresa el lote MP y el proceso de packing de cada línea, según la etiqueta.';
    }
    if (compositionBoxes !== Number(boxes)) return `La composición por CSG suma ${compositionBoxes} cajas y el bulto declara ${boxes}.`;
    if (outbox.some((item) => normalizeFolio(item.payload.numero_folio) === normalizeFolio(folio))) {
      return 'El folio posee una validación local pendiente o con error. Sincronízala antes de registrar otro intento.';
    }
    return null;
  }

  async function submit(result: ValidationResult, reason?: string, note?: string) {
    const problem = validateForm(result);
    if (problem) {
      setError(problem);
      return;
    }
    const primaryOrigin = selectedOrigins[0];
    if (!catalog || !selectedArticle || !primaryOrigin || !selectedCategory || !line || !shift) return;

    const payload: RegisterValidationPayload = {
      operacion_id: Crypto.randomUUID(),
      numero_folio: normalizeFolio(folio),
      tipo_bulto: packageType,
      cantidad_cajas: Number(boxes),
      linea_proceso: line,
      turno: shift,
      temporada_id: catalog.temporada.id,
      catalogo_version: catalog.temporada.version_catalogo,
      articulo_validacion_id: selectedArticle.id,
      origen_validacion_id: primaryOrigin.id,
      fecha_embalaje: packingDate,
      composicion: originDrafts.map((draft) => ({
        origen_validacion_id: draft.originId,
        cantidad_cajas: Number(draft.boxes),
        ...(normalizeCode(draft.lot) ? { lote_materia_prima: normalizeCode(draft.lot) } : {}),
        ...(normalizeCode(draft.process) ? { proceso_packing: normalizeCode(draft.process) } : {}),
      })),
      categoria_validacion_id: selectedCategory.id,
      resultado: result,
      ...(reason ? { motivo: reason } : {}),
      ...(note?.trim() ? { observacion: note.trim() } : {}),
      generado_dispositivo_at: new Date().toISOString(),
    };

    setBusy(true);
    setError('');
    setNotice('');
    try {
      let items = await enqueueValidation(userId, deviceId, payload);
      setOutbox(items);
      if (!baseUrl) {
        setNotice(`${payload.numero_folio} quedó en la bandeja de salida.`);
      } else {
        try {
          const attempt = await registerValidation(baseUrl, auth.token, payload);
          items = await removeValidationFromOutbox(userId, deviceId, payload.operacion_id);
          setOutbox(items);
          setRecent((current) => [attempt, ...current.filter((item) => item.id !== attempt.id)].slice(0, 10));
          try { await refreshSession(); } catch { /* conserva actualización optimista */ }
          setOnline(true);
          setNotice(result === 'aprobado'
            ? `${payload.numero_folio} aprobado y creado como pendiente de prefrío.`
            : `${payload.numero_folio} registrado como ${result}.`);
        } catch (reasonCaught) {
          if (reasonCaught instanceof ApiError && reasonCaught.status === 0) {
            setOnline(false);
            setNotice(`${payload.numero_folio} quedó guardado localmente para envío automático.`);
          } else if (reasonCaught instanceof ApiError && reasonCaught.status === 409) {
            items = await markValidationOutboxItem(userId, deviceId, payload.operacion_id, 'conflicto', reasonCaught.message);
            setOutbox(items);
            try { await refreshSession(); } catch { /* el conflicto permanece visible en la bandeja */ }
            setError(`Conflicto en ${payload.numero_folio}: ${reasonCaught.message}`);
          } else {
            items = await markValidationOutboxItem(userId, deviceId, payload.operacion_id, 'error', messageFrom(reasonCaught));
            setOutbox(items);
            setError(messageFrom(reasonCaught));
          }
        }
      }

      setFolio('');
      setFolioReview(null);
      setBoxes('');
      setPackingDate(todayLocal());
      setOriginDrafts([newOriginDraft()]);
      setObservation(null);
      setTimeout(() => folioInput.current?.focus(), 180);
    } finally {
      setBusy(false);
    }
  }

  async function retryItem(item: ValidationOutboxItem) {
    setOutbox(await retryValidationOutboxItem(userId, deviceId, item.id));
    await flushOutbox();
  }

  function openObservation(result: Exclude<ValidationResult, 'aprobado'>) {
    setObservation({
      result,
      reason: observedAttempt?.motivo ?? '',
      note: observedAttempt?.observacion ?? '',
    });
  }

  async function updateWorkContext(nextLine: ValidationLine | null, nextShift: ValidationShift | null) {
    setLine(nextLine);
    setShift(nextShift);
    if (nextLine && nextShift) setEditingContext(false);
    if (nextLine && nextShift) {
      await saveValidationWorkContext(userId, deviceId, {
        linea_proceso: nextLine,
        turno: nextShift,
      });
    }
  }

  function logout() {
    if (outbox.some((item) => item.status === 'pendiente')) {
      Alert.alert(
        'Validaciones pendientes',
        'La bandeja permanece guardada en esta PDA. ¿Deseas cerrar la sesión igualmente?',
        [
          { text: 'Volver', style: 'cancel' },
          { text: 'Cerrar sesión', style: 'destructive', onPress: onLogout },
        ],
      );
      return;
    }
    onLogout();
  }

  if (!catalog && busy) {
    return <View style={styles.boot}><ActivityIndicator color={colors.cyan} size="large" /><Text style={styles.muted}>Preparando catálogo de validación…</Text></View>;
  }

  return (
    <View style={styles.screen}>
      <ScrollView contentContainerStyle={[styles.page, compact && styles.pageCompact]} keyboardShouldPersistTaps="handled">
        <View style={[styles.topbar, compact && styles.topbarCompact, tight && styles.topbarTight]}>
          <View>{tight ? null : <Text style={styles.eyebrow}>FoliOS · TERRENO</Text>}<Text style={[styles.title, compact && styles.titleCompact, tight && styles.titleTight]}>{tight ? 'Validación PT' : 'Validación de pallets'}</Text></View>
          <View style={[styles.topbarRight, compact && styles.topbarRightCompact, tight && styles.topbarRightTight]}>
            <View style={[styles.connection, online ? styles.connectionOnline : styles.connectionOffline]}><Text style={styles.connectionText}>{online ? 'API conectada' : 'Modo desconectado'}</Text></View>
            <Pressable onPress={logout} style={styles.logout}><Text style={styles.logoutText}>Salir</Text></Pressable>
          </View>
        </View>

        <View style={[styles.statusStrip, compact && styles.statusStripCompact]}>
          <View style={styles.statusCopy}>
            <Text numberOfLines={tight ? 1 : undefined} style={[styles.statusText, tight && styles.statusTextTight]}>{catalog ? `${catalog.temporada.nombre} · catálogo v${catalog.temporada.version_catalogo}` : 'Sin catálogo'}</Text>
            <Text numberOfLines={tight ? 2 : undefined} style={[styles.statusText, tight && styles.statusTextTight]}>{line && shift ? `Línea ${line} · Turno ${shift}` : 'Jornada sin configurar'} · {pendingSessionOutbox.length} {tight ? 'pendientes' : 'pendientes de esta sesión'} · {lastSync ? `${tight ? 'sync' : 'última sincronización'} ${formatTime(lastSync)}` : (tight ? 'sin sync' : 'sin sincronización reciente')}</Text>
          </View>
          <Pressable
            disabled={syncing}
            onPress={() => void synchronize({ notify: true })}
            style={[styles.syncButton, styles.syncButtonTop, syncing && styles.disabled]}
          >
            <Text style={styles.syncButtonText}>{syncing ? 'Sincronizando…' : '↻ Sincronizar'}</Text>
          </Pressable>
        </View>

        {error ? <Pressable onPress={() => setError('')} style={styles.errorBanner}><Text style={styles.errorBannerText}>{error}</Text><Text style={styles.close}>×</Text></Pressable> : null}
        {notice ? <Pressable onPress={() => setNotice('')} style={styles.noticeBanner}><Text style={styles.noticeBannerText}>{notice}</Text><Text style={styles.close}>×</Text></Pressable> : null}

        <View style={[styles.mainGrid, compact && styles.mainGridCompact]}>
          <View style={[styles.formPanel, compact && styles.panelCompact]}>
            {tight ? null : <><Text style={styles.sectionEyebrow}>CAPTURA RÁPIDA</Text>
            <Text style={styles.sectionTitle}>Escanea y valida</Text></>}

            {tight && line && shift && !editingContext ? (
              <Pressable accessibilityLabel="Cambiar línea y turno" onPress={() => setEditingContext(true)} style={styles.workContextSummary}>
                <Text style={styles.workContextSummaryText}>Línea {line} · Turno {shift}</Text>
                <Text style={styles.workContextSummaryAction}>Cambiar</Text>
              </Pressable>
            ) : (
            <View style={[styles.workContext, tight && styles.workContextTight]}>
              <View style={styles.workContextHeader}>
                <View><Text style={styles.sectionEyebrow}>CONTEXTO DE JORNADA</Text><Text style={styles.workContextTitle}>Selecciona dónde estás validando</Text></View>
                {tight ? null : <Text style={styles.workContextHint}>Se conserva para los siguientes pallets</Text>}
              </View>
              <View style={[styles.workContextGrid, compact && styles.workContextGridCompact]}>
                <View style={styles.workContextGroup}>
                  <Text style={styles.label}>Línea de proceso *</Text>
                  <View style={styles.contextOptions}>
                    {([1, 2, 3] as ValidationLine[]).map((value) => <Pressable key={value} onPress={() => void updateWorkContext(value, shift)} style={[styles.contextButton, line === value && styles.contextButtonActive]}><Text style={[styles.contextButtonText, line === value && styles.contextButtonTextActive]}>{value}</Text></Pressable>)}
                  </View>
                </View>
                <View style={styles.workContextGroup}>
                  <Text style={styles.label}>Turno *</Text>
                  <View style={styles.contextOptions}>
                    {(['A', 'B'] as ValidationShift[]).map((value) => <Pressable key={value} onPress={() => void updateWorkContext(line, value)} style={[styles.contextButton, shift === value && styles.contextButtonActive]}><Text style={[styles.contextButtonText, shift === value && styles.contextButtonTextActive]}>{value}</Text></Pressable>)}
                  </View>
                </View>
              </View>
            </View>

            )}

            <Text style={styles.label}>Folio *</Text>
            <ScanInput
              ref={folioInput}
              autoFocus={isPdaBuild}
              disabled={busy}
              onChangeText={handleFolioChange}
              onSubmit={(code) => void inspectFolio(code)}
              placeholder={tight ? 'Pistolea el folio' : 'Escanear código de barras'}
              submitLabel={tight ? 'OK' : 'CONSULTAR'}
              value={folio}
            />

            {folioReview ? <FolioReviewCard review={folioReview} /> : null}

            <View style={[styles.typeRow, compact && styles.typeRowCompact]}>
              {(['pallet', 'saldo'] as const).map((type) => (
                <Pressable disabled={terminalDecision} key={type} onPress={() => setPackageType(type)} style={[styles.typeButton, compact && styles.typeButtonCompact, packageType === type && styles.typeButtonActive, terminalDecision && styles.disabled]}>
                  <Text style={[styles.typeButtonText, packageType === type && styles.typeButtonTextActive]}>{type === 'pallet' ? 'PALLET COMPLETO' : 'SALDO'}</Text>
                </Pressable>
              ))}
              <View style={[styles.boxField, compact && styles.boxFieldCompact]}><Text style={styles.label}>Cajas *</Text><TextInput editable={!terminalDecision} keyboardType="number-pad" onChangeText={(value) => { const clean = value.replace(/[^0-9]/g, ''); setBoxes(clean); setOriginDrafts((current) => current.length === 1 ? [{ ...current[0], boxes: clean }] : current); }} placeholder="0" placeholderTextColor={colors.muted} style={[styles.boxInput, terminalDecision && styles.disabled]} value={boxes} /></View>
            </View>

            <Text style={styles.groupTitle}>Categoría</Text>
            <View style={[styles.fieldGrid, compact && styles.fieldGridCompact]}>
              <View style={styles.wideField}><SelectField compact={compact} disabled={terminalDecision} label="Categoría" options={categoryOptions} searchable value={categoryId} onChange={setCategoryId} /></View>
            </View>

            <Text style={styles.groupTitle}>Artículo</Text>
            <View style={[styles.fieldGrid, compact && styles.fieldGridCompact, tight && styles.fieldGridPair]}>
              <SelectField compact={compact} half={tight} disabled={terminalDecision} label="Especie" options={speciesOptions} value={species} onChange={(value) => { setSpecies(value); setVariety(''); setCaliber(''); setPackageName(''); clearOrigin(); }} />
              <SelectField compact={compact} half={tight} disabled={terminalDecision || !species} label="Variedad" options={varietyOptions} value={variety} onChange={(value) => { setVariety(value); setCaliber(''); setPackageName(''); clearOrigin(); }} />
              <SelectField compact={compact} half={tight} disabled={terminalDecision || !variety} label="Calibre" options={caliberOptions} value={caliber} onChange={(value) => { setCaliber(value); setPackageName(''); clearOrigin(); }} />
              <SelectField compact={compact} half={tight} disabled={terminalDecision || !caliber} label="Envase" options={packageOptions} value={packageName} onChange={(value) => { setPackageName(value); clearOrigin(); }} />
            </View>

            <Text style={styles.groupTitle}>Origen comercial</Text>
            <View style={[styles.fieldGrid, compact && styles.fieldGridCompact, tight && styles.fieldGridPair]}>
              <SelectField compact={compact} half={tight} disabled={terminalDecision} label="Cliente" options={clientOptions} value={client} onChange={(value) => { setClient(value); setBrand(''); setOriginDrafts([newOriginDraft()]); }} />
              <SelectField compact={compact} half={tight} disabled={terminalDecision || !client} label="Marca" options={brandOptions} value={brand} onChange={(value) => { setBrand(value); setOriginDrafts([newOriginDraft()]); }} />
              <View style={styles.packingDateField}>
                <Text style={styles.label}>Fecha de embalaje *</Text>
                <TextInput
                  editable={!terminalDecision}
                  onChangeText={setPackingDate}
                  placeholder="AAAA-MM-DD"
                  placeholderTextColor={colors.muted}
                  style={[styles.boxInput, terminalDecision && styles.disabled]}
                  value={packingDate}
                />
                <Text style={styles.fieldHint}>Una sola fecha por bulto validado en línea.</Text>
              </View>
            </View>

            <View style={styles.compositionHeader}>
              <View style={styles.compositionHeaderCopy}><Text style={styles.label}>Composición por CSG *</Text><Text style={styles.fieldHint}>La suma debe coincidir con las cajas del bulto.</Text></View>
              <Text style={[styles.compositionTotal, compositionBoxes === Number(boxes) ? styles.compositionTotalOk : styles.compositionTotalPending]}>{compositionBoxes}/{boxes || 0} cajas</Text>
            </View>
            {originDrafts.map((draft, index) => (
              <View key={draft.key} style={[styles.originCompositionRow, compact && styles.originCompositionRowCompact]}>
                <View style={styles.originCompositionSelect}><SelectField compact={compact} disabled={terminalDecision || !brand} label={`CSG / Predio ${index + 1}`} options={csgOptions} searchable value={draft.originId} onChange={(value) => { setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, originId: value } : item)); if (selectedArticle && !catalog?.combinaciones.some((item) => item.articulo_validacion_id === selectedArticle.id && item.origen_validacion_id === value)) { setVariety(''); setCaliber(''); setPackageName(''); } }} /></View>
                <View style={styles.originBoxes}><Text style={styles.label}>Cajas *</Text><TextInput editable={!terminalDecision} keyboardType="number-pad" onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, boxes: value.replace(/[^0-9]/g, '') } : item))} placeholder="0" placeholderTextColor={colors.muted} style={styles.boxInput} value={draft.boxes} /></View>
                {originDrafts.length > 1 ? <Pressable disabled={terminalDecision} onPress={() => setOriginDrafts((current) => current.filter((item) => item.key !== draft.key))} style={styles.removeOrigin}><Text style={styles.removeOriginText}>Quitar</Text></Pressable> : null}
                <View style={styles.traceField}><Text style={styles.label}>Lote MP *</Text><TextInput autoCapitalize="characters" autoCorrect={false} editable={!terminalDecision} onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, lot: value } : item))} placeholder="Según etiqueta" placeholderTextColor={colors.muted} style={styles.traceInput} value={draft.lot} /></View>
                <View style={styles.traceField}><Text style={styles.label}>Proceso packing *</Text><TextInput autoCapitalize="characters" autoCorrect={false} editable={!terminalDecision} onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, process: value } : item))} placeholder="Según etiqueta" placeholderTextColor={colors.muted} style={styles.traceInput} value={draft.process} /></View>
              </View>
            ))}
            <Pressable disabled={terminalDecision || !brand} onPress={() => setOriginDrafts((current) => [...current, newOriginDraft()])} style={[styles.addOrigin, (terminalDecision || !brand) && styles.disabled]}><Text style={styles.addOriginText}>+ Agregar línea (otro CSG, lote o proceso)</Text></Pressable>

            <View style={styles.selectionSummary}>
              <Text style={styles.selectionSummaryTitle}>{selectedCombination && selectedCategory ? 'Combinación habilitada' : 'Completa los datos obligatorios'}</Text>
              <Text style={styles.selectionSummaryText}>{selectedCategory ? `Categoría: ${selectedCategory.nombre}` : 'Categoría pendiente'}</Text>
              <Text style={styles.selectionSummaryText}>{selectedArticle ? `${selectedArticle.especie} · ${selectedArticle.variedad} · ${selectedArticle.calibre} · ${selectedArticle.envase}` : 'Artículo pendiente'}</Text>
              <Text style={styles.selectionSummaryText}>{selectedOrigins.every(Boolean) ? `${client} · ${brand} · ${selectedOrigins.map((origin) => `CSG ${origin?.csg}`).join(' + ')} · fecha ${packingDate}` : 'Origen pendiente'}</Text>
            </View>

            <View style={[styles.resultActions, compact && styles.resultActionsCompact]}>
              <Pressable disabled={busy || terminalDecision} onPress={() => void submit('aprobado')} style={[styles.resultButton, compact && styles.resultButtonCompact, styles.approveButton, (busy || terminalDecision) && styles.disabled]}><Text style={styles.resultIcon}>✓</Text><Text style={styles.resultButtonText}>{observedAttempt ? 'APROBAR CORRECCIÓN' : 'APROBAR'}</Text></Pressable>
              <Pressable disabled={busy || terminalDecision} onPress={() => openObservation('observado')} style={[styles.resultButton, compact && styles.resultButtonCompact, styles.observeButton, (busy || terminalDecision) && styles.disabled]}><Text style={styles.resultIcon}>!</Text><Text style={styles.resultButtonText}>{observedAttempt ? 'MANTENER OBSERVADO' : 'OBSERVAR'}</Text></Pressable>
              {canReject ? <Pressable disabled={busy || terminalDecision} onPress={() => openObservation('rechazado')} style={[styles.resultButton, compact && styles.resultButtonCompact, styles.rejectButton, (busy || terminalDecision) && styles.disabled]}><Text style={styles.resultIcon}>×</Text><Text style={styles.resultButtonText}>RECHAZAR</Text></Pressable> : null}
            </View>
          </View>

          <View style={[styles.sidePanel, compact && styles.panelCompact]}>
            <View style={styles.sessionHeader}>
              <View>
                <Text style={styles.sectionEyebrow}>MI SESIÓN DE VALIDACIÓN</Text>
                <Text style={styles.sideTitle}>{auth.usuario.nombre}</Text>
                <Text style={styles.sessionStarted}>
                  Desde {formatDateTime(sessionStartedAt)} · {auth.dispositivo.codigo}
                </Text>
              </View>
              <Pressable onPress={() => setSessionExpanded((current) => !current)} style={styles.sessionToggle}>
                <Text style={styles.sessionToggleText}>{sessionExpanded ? 'Contraer' : 'Ver todos'}</Text>
              </Pressable>
            </View>

            <View style={styles.sessionMetrics}>
              <SessionMetric label="Folios" value={validationSession?.resumen.folios_trabajados ?? 0} tight={tight} />
              <SessionMetric label="Intentos" value={validationSession?.resumen.registros_realizados ?? 0} tight={tight} />
              <SessionMetric label="Aprobados" value={validationSession?.resumen.aprobados ?? 0} tone="positive" tight={tight} />
              <SessionMetric label="Observados" value={validationSession?.resumen.observados ?? 0} tone="warning" tight={tight} />
              <SessionMetric label="Rechazados" value={validationSession?.resumen.rechazados ?? 0} tone="critical" tight={tight} />
              <SessionMetric label="Conflictos" value={validationSession?.resumen.conflictos ?? 0} tone="critical" tight={tight} />
              <SessionMetric label="Pendientes PDA" value={pendingSessionOutbox.length} tone="warning" tight={tight} />
            </View>

            <View style={styles.sessionBalance}>
              <Text style={styles.sessionBalanceText}>
                Servidor: {validationSession?.resumen.registros_realizados ?? 0} · Sin confirmar: {unconfirmedSessionOutbox.length}
              </Text>
              <Text style={styles.sessionTotal}>
                Total capturado: {(validationSession?.resumen.registros_realizados ?? 0) + unconfirmedSessionOutbox.length}
              </Text>
            </View>

            <View style={styles.sessionListHeader}>
              <Text style={styles.sectionEyebrow}>PALLETS TRABAJADOS</Text>
              <Text style={styles.sessionListCount}>
                {validationSession?.meta.total ?? 0} en servidor · {currentSessionOutbox.length} locales
              </Text>
            </View>

            {currentSessionOutbox.map((item) => (
              <View key={item.id} style={[styles.sessionItem, styles.sessionItemLocal]}>
                <View style={styles.queueContent}>
                  <Text style={styles.queueFolio}>{item.payload.numero_folio}</Text>
                  <Text style={styles.queueDetail}>
                    {formatTime(item.payload.generado_dispositivo_at)} · {item.payload.tipo_bulto} · {item.payload.cantidad_cajas} cajas · Línea {item.payload.linea_proceso} · Turno {item.payload.turno}
                  </Text>
                  {item.message ? <Text style={styles.queueError}>{item.message}</Text> : null}
                </View>
                <View style={styles.sessionItemActions}>
                  <Text style={[styles.resultBadge, item.status === 'pendiente' ? styles.badgeObserved : styles.badgeRejected]}>
                    {statusLabel(item.status)}
                  </Text>
                  {item.status !== 'pendiente' ? <Pressable onPress={() => void retryItem(item)} style={styles.retryButton}><Text style={styles.retryText}>Reintentar</Text></Pressable> : null}
                </View>
              </View>
            ))}

            {visibleSessionAttempts.map((item) => (
              <Pressable
                key={item.id}
                onPress={() => { setFolio(item.numero_folio); void inspectFolio(item.numero_folio); }}
                style={styles.sessionItem}
              >
                <View style={styles.queueContent}>
                  <Text style={styles.queueFolio}>{item.numero_folio}</Text>
                  <Text style={styles.queueDetail}>
                    {formatTime(item.recibido_servidor_at)} · intento {item.numero_intento} · {item.tipo_bulto} · {item.cantidad_cajas} cajas
                  </Text>
                  <Text style={styles.queueDetail}>
                    Línea {item.linea_proceso ?? '—'} · Turno {item.turno ?? '—'} · confirmado en servidor
                  </Text>
                </View>
                <Text style={[
                  styles.resultBadge,
                  item.resultado === 'aprobado'
                    ? styles.badgeApproved
                    : item.resultado === 'observado'
                      ? styles.badgeObserved
                      : styles.badgeRejected,
                ]}>
                  {item.estado === 'conflicto' ? 'conflicto' : item.resultado}
                </Text>
              </Pressable>
            ))}

            {!currentSessionOutbox.length && !visibleSessionAttempts.length
              ? <Text style={styles.empty}>Aún no existen pallets trabajados en esta sesión.</Text>
              : null}

            {sessionExpanded && validationSession && validationSession.meta.current_page < validationSession.meta.last_page
              ? (
                <Pressable disabled={loadingMoreSession} onPress={() => void loadMoreSession()} style={[styles.loadMoreButton, loadingMoreSession && styles.disabled]}>
                  <Text style={styles.loadMoreText}>{loadingMoreSession ? 'Cargando…' : 'Cargar más registros'}</Text>
                </Pressable>
              )
              : null}

            {previousSessionOutbox.length ? (
              <View style={styles.previousQueue}>
                <Text style={styles.previousQueueTitle}>BANDEJA DE UNA SESIÓN ANTERIOR</Text>
                <Text style={styles.previousQueueText}>
                  {previousSessionOutbox.length} operaciones siguen guardadas en esta PDA y no se suman a la sesión actual.
                </Text>
                {previousSessionOutbox.map((item) => (
                  <View key={item.id} style={styles.queueItem}>
                    <View style={styles.queueContent}>
                      <Text style={styles.queueFolio}>{item.payload.numero_folio}</Text>
                      <Text style={styles.queueDetail}>{statusLabel(item.status)} · {formatTime(item.payload.generado_dispositivo_at)}</Text>
                    </View>
                    {item.status !== 'pendiente' ? <Pressable onPress={() => void retryItem(item)} style={styles.retryButton}><Text style={styles.retryText}>Reintentar</Text></Pressable> : null}
                  </View>
                ))}
              </View>
            ) : null}
          </View>
        </View>
      </ScrollView>

      {busy ? <View pointerEvents="none" style={styles.busy}><ActivityIndicator color={colors.cyan} size="large" /><Text style={styles.busyText}>Procesando…</Text></View> : null}
      <ObservationModal catalog={catalog} draft={observation} onCancel={() => setObservation(null)} onConfirm={(draft) => void submit(draft.result, draft.reason, draft.note)} />
    </View>
  );
}

function SessionMetric({
  label,
  value,
  tone = 'neutral',
  tight = false,
}: {
  label: string;
  value: number;
  tone?: 'neutral' | 'positive' | 'warning' | 'critical';
  tight?: boolean;
}) {
  return (
    <View style={[
      styles.sessionMetric,
      tight && styles.sessionMetricTight,
      tone === 'positive' && styles.sessionMetricPositive,
      tone === 'warning' && styles.sessionMetricWarning,
      tone === 'critical' && styles.sessionMetricCritical,
    ]}>
      <Text numberOfLines={1} style={[styles.sessionMetricLabel, tight && styles.sessionMetricLabelTight]}>{label}</Text>
      <Text style={[styles.sessionMetricValue, tight && styles.sessionMetricValueTight]}>{value}</Text>
    </View>
  );
}

function FolioReviewCard({ review }: { review: FolioReview }) {
  const attempt = review.attempt;
  const title = review.status === 'nuevo'
    ? 'FOLIO SIN VALIDACIONES PREVIAS'
    : review.status === 'observado'
      ? 'FOLIO OBSERVADO · CORRECCIÓN PENDIENTE'
      : review.status === 'aprobado'
        ? 'FOLIO YA APROBADO'
        : 'FOLIO RECHAZADO DEFINITIVAMENTE';
  const detail = review.status === 'nuevo'
    ? 'Puedes completar y registrar su primera validación.'
    : review.status === 'observado'
      ? 'La información del intento anterior fue recuperada. Corrige únicamente lo necesario y registra una nueva decisión.'
      : 'Los datos se muestran como consulta y no admiten una nueva decisión desde esta pantalla.';

  return (
    <View style={[
      styles.reviewCard,
      review.status === 'observado' && styles.reviewObserved,
      review.status === 'aprobado' && styles.reviewApproved,
      review.status === 'rechazado' && styles.reviewRejected,
    ]}>
      <Text style={styles.reviewTitle}>{title}</Text>
      {attempt ? <Text style={styles.reviewMeta}>Intento {attempt.numero_intento} · {attempt.usuario.nombre} · {formatTime(attempt.recibido_servidor_at)}</Text> : null}
      {attempt?.motivo ? <Text style={styles.reviewText}>Motivo: {reasonLabel(attempt.motivo)}</Text> : null}
      {attempt?.observacion ? <Text style={styles.reviewText}>Observación: {attempt.observacion}</Text> : null}
      <Text style={styles.reviewDetail}>{detail}</Text>
    </View>
  );
}

function SelectField({ label, options, value, onChange, compact = false, half = false, disabled = false, searchable = false }: { label: string; options: Option[]; value: string; onChange: (value: string) => void; compact?: boolean; half?: boolean; disabled?: boolean; searchable?: boolean }) {
  const [visible, setVisible] = useState(false);
  const [query, setQuery] = useState('');
  const selected = options.find((option) => option.value === value);
  const filtered = options.filter((option) => `${option.label} ${option.search ?? ''}`.toLowerCase().includes(query.trim().toLowerCase()));

  return <>
    <View style={[styles.selectField, compact && styles.selectFieldCompact, half && styles.selectFieldHalf]}><Text numberOfLines={1} style={styles.label}>{label} *</Text><Pressable disabled={disabled} onPress={() => setVisible(true)} style={[styles.selectButton, disabled && styles.disabled]}><Text numberOfLines={1} style={[styles.selectText, !selected && styles.placeholder]}>{selected?.label ?? 'Seleccionar'}</Text><Text style={styles.chevron}>⌄</Text></Pressable></View>
    <Modal animationType="fade" transparent visible={visible} onRequestClose={() => setVisible(false)}>
      <View style={styles.modalBackdrop}><View style={styles.selectorModal}><View style={styles.modalHeader}><Text style={styles.modalTitle}>{label}</Text><Pressable onPress={() => setVisible(false)}><Text style={styles.modalClose}>×</Text></Pressable></View>{searchable || options.length > 8 ? <TextInput autoFocus onChangeText={setQuery} placeholder={`Buscar ${label.toLowerCase()}`} placeholderTextColor={colors.muted} style={styles.searchInput} value={query} /> : null}<ScrollView keyboardShouldPersistTaps="handled" style={styles.optionList}>{filtered.map((option) => <Pressable key={option.value} onPress={() => { onChange(option.value); setQuery(''); setVisible(false); }} style={[styles.option, option.value === value && styles.optionSelected]}><Text style={styles.optionText}>{option.label}</Text></Pressable>)}{!filtered.length ? <Text style={styles.empty}>Sin opciones coincidentes.</Text> : null}</ScrollView></View></View>
    </Modal>
  </>;
}

function ObservationModal({ catalog, draft, onCancel, onConfirm }: { catalog: ValidationCatalog | null; draft: ObservationDraft | null; onCancel: () => void; onConfirm: (draft: ObservationDraft) => void }) {
  const { height, width } = useWindowDimensions();
  const compact = width < 700 || width < height;
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  useEffect(() => { setReason(draft?.reason ?? ''); setNote(draft?.note ?? ''); }, [draft]);
  if (!draft) return null;
  const reasonOptions = (catalog?.motivos ?? []).map((item) => ({ value: item, label: reasonLabel(item) }));
  const invalid = !reason || (reason === 'otro' && !note.trim());

  return <Modal animationType="slide" transparent visible onRequestClose={onCancel}><View style={[styles.modalBackdrop, compact && styles.modalBackdropCompact]}><View style={[styles.observationModal, compact && styles.observationModalCompact]}><ScrollView contentContainerStyle={styles.observationContent} keyboardShouldPersistTaps="handled"><View style={styles.modalHeader}><View><Text style={styles.sectionEyebrow}>{draft.result === 'rechazado' ? 'RECHAZO DEFINITIVO' : 'PALLET OBSERVADO'}</Text><Text style={styles.modalTitle}>Registra el motivo</Text></View><Pressable onPress={onCancel}><Text style={styles.modalClose}>×</Text></Pressable></View><SelectField compact={compact} label="Motivo" options={reasonOptions} searchable value={reason} onChange={setReason} /><Text style={styles.label}>Observación {reason === 'otro' ? '*' : ''}</Text><TextInput multiline onChangeText={setNote} placeholder="Describe el problema detectado o la corrección requerida" placeholderTextColor={colors.muted} style={styles.noteInput} value={note} /><View style={[styles.modalActions, compact && styles.modalActionsCompact]}><Pressable onPress={onCancel} style={[styles.cancelButton, compact && styles.modalButtonCompact]}><Text style={styles.cancelText}>Cancelar</Text></Pressable><Pressable disabled={invalid} onPress={() => onConfirm({ ...draft, reason, note })} style={[styles.confirmObservation, compact && styles.modalButtonCompact, draft.result === 'rechazado' && styles.rejectButton, invalid && styles.disabled]}><Text style={styles.resultButtonText}>Confirmar {draft.result}</Text></Pressable></View></ScrollView></View></View></Modal>;
}

function uniqueOptions(values: string[]): Option[] {
  return [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')).map((value) => ({ value, label: value }));
}
function newOriginDraft(): OriginDraft { return { key: `${Date.now()}-${Math.random()}`, originId: '', boxes: '', lot: '', process: '' }; }
function normalizeCode(value: string) { return value.trim().toUpperCase(); }
function compositionKey(draft: OriginDraft) { return `${draft.originId}|${normalizeCode(draft.lot)}|${normalizeCode(draft.process)}`; }
function todayLocal(): string {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}
function normalizeFolio(value: string) { return value.trim().toUpperCase(); }
function formatTime(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('es-CL', { hour: '2-digit', minute: '2-digit' }).format(date); }
function formatDateTime(value: string | null) { const date = value ? new Date(value) : null; return !date || Number.isNaN(date.getTime()) ? 'sin hora registrada' : new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date); }
function messageFrom(reason: unknown) { return reason instanceof Error ? reason.message : 'Ocurrió un error inesperado.'; }
function statusLabel(status: ValidationOutboxItem['status']) { return status === 'pendiente' ? 'Pendiente' : status === 'conflicto' ? 'Conflicto' : 'Requiere revisión'; }
function reasonLabel(value: string) { return value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  page: { padding: 18, gap: 14, paddingBottom: 46 },
  pageCompact: { padding: 10, gap: 10, paddingBottom: 32 },
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, backgroundColor: colors.background },
  muted: { color: colors.muted, fontWeight: '700' },
  topbar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: 16, borderWidth: 1, borderColor: colors.border, borderRadius: 16, backgroundColor: colors.panel },
  topbarCompact: { flexDirection: 'column', alignItems: 'stretch', padding: 13 },
  topbarRight: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  topbarRightCompact: { justifyContent: 'space-between' },
  eyebrow: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 1.3 },
  title: { color: colors.text, fontSize: 23, fontWeight: '900', marginTop: 3 },
  titleCompact: { fontSize: 20 },
  connection: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, borderWidth: 1 },
  connectionOnline: { borderColor: colors.green, backgroundColor: colors.greenDark },
  connectionOffline: { borderColor: colors.red, backgroundColor: colors.blocked },
  connectionText: { color: colors.text, fontSize: 14, fontWeight: '900' },
  logout: { paddingHorizontal: 13, paddingVertical: 9, borderRadius: 10, borderWidth: 1, borderColor: colors.border },
  logoutText: { color: colors.muted, fontWeight: '800' },
  statusStrip: { flexDirection: 'row', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', paddingHorizontal: 8 },
  statusStripCompact: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 },
  statusCopy: { flex: 1, gap: 3 },
  statusText: { color: colors.muted, fontSize: 14, fontWeight: '800' },
  errorBanner: { flexDirection: 'row', justifyContent: 'space-between', gap: 12, padding: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.red, backgroundColor: colors.blocked },
  errorBannerText: { flex: 1, color: colors.text, fontWeight: '800' },
  noticeBanner: { flexDirection: 'row', justifyContent: 'space-between', gap: 12, padding: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.green, backgroundColor: colors.greenDark },
  noticeBannerText: { flex: 1, color: colors.text, fontWeight: '800' },
  close: { color: colors.text, fontSize: 20, fontWeight: '900' },
  mainGrid: { flexDirection: 'row', alignItems: 'flex-start', gap: 16 },
  mainGridCompact: { flexDirection: 'column', gap: 10 },
  formPanel: { flex: 1.65, padding: 20, borderRadius: 17, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  sidePanel: { flex: 1, padding: 18, borderRadius: 17, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panel },
  panelCompact: { width: '100%', flexGrow: 0, flexBasis: 'auto', padding: 14 },
  sectionEyebrow: { color: colors.cyan, fontSize: 14, fontWeight: '900', letterSpacing: 1.2 },
  sectionTitle: { color: colors.text, fontSize: 22, fontWeight: '900', marginTop: 4, marginBottom: 17 },
  workContext: { marginBottom: 18, padding: 14, borderRadius: 13, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  workContextHeader: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 12 },
  workContextTitle: { color: colors.text, fontSize: 14, fontWeight: '900', marginTop: 3 },
  workContextHint: { maxWidth: 210, color: colors.muted, fontSize: 14, fontWeight: '700', textAlign: 'right' },
  workContextGrid: { flexDirection: 'row', gap: 14 },
  workContextGridCompact: { flexDirection: 'column', gap: 10 },
  workContextGroup: { flex: 1 },
  contextOptions: { flexDirection: 'row', gap: 8 },
  contextButton: { flex: 1, minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  contextButtonActive: { borderColor: colors.cyan, backgroundColor: colors.cyan },
  contextButtonText: { color: colors.muted, fontSize: 14, fontWeight: '900' },
  contextButtonTextActive: { color: colors.backgroundDeep },
  label: { color: colors.muted, fontSize: 14, fontWeight: '900', letterSpacing: .7, textTransform: 'uppercase', marginBottom: 6 },
  folioRow: { flexDirection: 'row', alignItems: 'stretch', gap: 9 },
  folioRowCompact: { flexDirection: 'column' },
  folioInput: { flex: 1, minHeight: 60, paddingHorizontal: 16, borderRadius: 13, borderWidth: 2, borderColor: colors.cyan, color: colors.text, backgroundColor: colors.backgroundDeep, fontSize: 23, fontWeight: '900', letterSpacing: 1.2 },
  lookupButton: { minWidth: 126, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14, borderRadius: 13, borderWidth: 1, borderColor: colors.cyan, backgroundColor: colors.selected },
  lookupButtonText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  reviewCard: { marginTop: 12, padding: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  reviewObserved: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  reviewApproved: { borderColor: colors.green, backgroundColor: colors.greenDark },
  reviewRejected: { borderColor: colors.red, backgroundColor: colors.blocked },
  reviewTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  reviewMeta: { color: colors.muted, fontSize: 14, fontWeight: '800', marginTop: 4 },
  reviewText: { color: colors.text, fontSize: 12, marginTop: 5 },
  reviewDetail: { color: colors.text, fontSize: 14, fontWeight: '700', marginTop: 8 },
  typeRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 9, marginTop: 12 },
  typeRowCompact: { flexWrap: 'wrap' },
  typeButton: { minHeight: 49, justifyContent: 'center', paddingHorizontal: 14, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  typeButtonCompact: { flexGrow: 1, flexBasis: '46%', alignItems: 'center', paddingHorizontal: 8 },
  typeButtonActive: { borderColor: colors.cyan, backgroundColor: colors.selected },
  typeButtonText: { color: colors.muted, fontSize: 14, fontWeight: '900' },
  typeButtonTextActive: { color: colors.cyan },
  boxField: { flex: 1, minWidth: 110 },
  boxFieldCompact: { flexBasis: '100%' },
  boxInput: { minHeight: 49, paddingHorizontal: 13, borderRadius: 11, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.backgroundDeep, fontSize: 18, fontWeight: '900' },
  groupTitle: { color: colors.text, fontSize: 15, fontWeight: '900', marginTop: 20, marginBottom: 9 },
  fieldHint: { color: colors.muted, fontSize: 13, marginTop: 4 },
  packingDateField: { flex: 1, minWidth: 190 },
  compositionHeader: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginTop: 14, marginBottom: 8 },
  compositionHeaderCopy: { flexShrink: 1, flexBasis: 180, flexGrow: 1 },
  compositionTotal: { flexShrink: 0, paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, overflow: 'hidden', fontSize: 14, fontWeight: '900' },
  titleTight: { fontSize: 18, marginTop: 0 },
  topbarTight: { flexDirection: 'row', alignItems: 'center', padding: 10, borderRadius: 12 },
  topbarRightTight: { gap: 6 },
  statusTextTight: { fontSize: 11 },
  workContextTight: { marginBottom: 12, padding: 10 },
  workContextSummary: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, paddingHorizontal: 12, paddingVertical: 9, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  workContextSummaryText: { color: colors.text, fontSize: 14, fontWeight: '900' },
  workContextSummaryAction: { color: colors.cyan, fontSize: 12, fontWeight: '900' },
  compositionTotalOk: { color: colors.green, backgroundColor: colors.greenDark },
  compositionTotalPending: { color: colors.amber, backgroundColor: colors.amberDark },
  originCompositionRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-end', gap: 9, marginBottom: 8, padding: 9, borderWidth: 1, borderColor: colors.border, borderRadius: 11, backgroundColor: colors.backgroundDeep },
  originCompositionRowCompact: { flexWrap: 'wrap' },
  originCompositionSelect: { flex: 1, minWidth: 220 },
  originBoxes: { width: 105 },
  traceField: { flex: 1, minWidth: 150 },
  traceInput: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 9, backgroundColor: colors.backgroundDeep, color: colors.text, paddingHorizontal: 11, fontSize: 15 },
  removeOrigin: { minHeight: 48, justifyContent: 'center', paddingHorizontal: 10 },
  removeOriginText: { color: colors.red, fontSize: 14, fontWeight: '900' },
  addOrigin: { alignItems: 'center', padding: 11, borderWidth: 1, borderColor: colors.cyanDark, borderRadius: 10, borderStyle: 'dashed' },
  addOriginText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  fieldGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
  fieldGridCompact: { flexDirection: 'column', flexWrap: 'nowrap' },
  selectField: { flex: 1, minWidth: 145 },
  selectFieldCompact: { width: '100%', flexGrow: 0, flexBasis: 'auto' },
  selectFieldHalf: { width: 'auto', flexGrow: 1, flexBasis: '45%', minWidth: 0 },
  fieldGridPair: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  wideField: { flexBasis: '100%' },
  selectButton: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, paddingHorizontal: 12, borderRadius: 11, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  selectText: { flex: 1, color: colors.text, fontWeight: '800' },
  placeholder: { color: colors.muted },
  chevron: { color: colors.cyan, fontSize: 19, fontWeight: '900' },
  disabled: { opacity: .38 },
  selectionSummary: { marginTop: 17, padding: 13, borderRadius: 12, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  selectionSummaryTitle: { color: colors.cyan, fontWeight: '900' },
  selectionSummaryText: { color: colors.text, fontSize: 12, marginTop: 4 },
  resultActions: { flexDirection: 'row', gap: 10, marginTop: 18 },
  resultActionsCompact: { flexDirection: 'column' },
  resultButton: { flex: 1, minHeight: 68, alignItems: 'center', justifyContent: 'center', borderRadius: 13, borderWidth: 1 },
  resultButtonCompact: { width: '100%', flexGrow: 0, flexBasis: 'auto', minHeight: 58, flexDirection: 'row', gap: 9 },
  approveButton: { borderColor: colors.green, backgroundColor: colors.greenDark },
  observeButton: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  rejectButton: { borderColor: colors.red, backgroundColor: colors.blocked },
  resultIcon: { color: colors.text, fontSize: 21, fontWeight: '900' },
  resultButtonText: { color: colors.text, fontSize: 12, fontWeight: '900', textTransform: 'uppercase' },
  sideHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginBottom: 13 },
  sideTitle: { color: colors.text, fontSize: 18, fontWeight: '900', marginTop: 3 },
  sessionHeader: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10, marginBottom: 13 },
  sessionStarted: { color: colors.muted, fontSize: 14, fontWeight: '700', marginTop: 4 },
  sessionToggle: { paddingHorizontal: 10, paddingVertical: 7, borderRadius: 9, borderWidth: 1, borderColor: colors.cyanDark },
  sessionToggleText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  sessionMetrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  sessionMetric: { flexGrow: 1, flexBasis: '29%', minWidth: 88, padding: 10, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.backgroundDeep },
  sessionMetricTight: { flexBasis: '46%', minWidth: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 7, paddingHorizontal: 9 },
  sessionMetricLabelTight: { flexShrink: 1, fontSize: 11, letterSpacing: .2, marginRight: 6 },
  sessionMetricValueTight: { fontSize: 17, marginTop: 0 },
  sessionMetricPositive: { borderColor: colors.green, backgroundColor: colors.greenDark },
  sessionMetricWarning: { borderColor: colors.amber, backgroundColor: colors.amberDark },
  sessionMetricCritical: { borderColor: colors.red, backgroundColor: colors.blocked },
  sessionMetricLabel: { color: colors.muted, fontSize: 12, fontWeight: '900', letterSpacing: .5, textTransform: 'uppercase' },
  sessionMetricValue: { color: colors.text, fontSize: 20, fontWeight: '900', marginTop: 3 },
  sessionBalance: { marginTop: 9, padding: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.cyanDark, backgroundColor: colors.selected },
  sessionBalanceText: { color: colors.muted, fontSize: 14, fontWeight: '800' },
  sessionTotal: { color: colors.cyan, fontSize: 13, fontWeight: '900', marginTop: 3 },
  sessionListHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginTop: 20, marginBottom: 5 },
  sessionListCount: { color: colors.muted, fontSize: 13, fontWeight: '800', textAlign: 'right' },
  sessionItem: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  sessionItemLocal: { paddingHorizontal: 9, borderRadius: 9, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark, marginBottom: 5 },
  sessionItemActions: { alignItems: 'flex-end', gap: 6 },
  loadMoreButton: { alignItems: 'center', marginTop: 10, padding: 11, borderRadius: 9, borderWidth: 1, borderColor: colors.cyanDark },
  loadMoreText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  previousQueue: { marginTop: 18, padding: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark },
  previousQueueTitle: { color: colors.amber, fontSize: 13, fontWeight: '900', letterSpacing: .6 },
  previousQueueText: { color: colors.text, fontSize: 14, marginTop: 4, marginBottom: 4 },
  syncButton: { paddingHorizontal: 11, paddingVertical: 8, borderRadius: 9, borderWidth: 1, borderColor: colors.cyanDark },
  syncButtonTop: { alignSelf: 'center' },
  syncButtonText: { color: colors.cyan, fontSize: 14, fontWeight: '900' },
  queueItem: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  queueContent: { flex: 1 },
  queueFolio: { color: colors.text, fontSize: 14, fontWeight: '900' },
  queueDetail: { color: colors.muted, fontSize: 14, marginTop: 3 },
  queueError: { maxWidth: 260, color: colors.red, fontSize: 14, marginTop: 4 },
  retryButton: { paddingHorizontal: 9, paddingVertical: 7, borderRadius: 8, borderWidth: 1, borderColor: colors.amber },
  retryText: { color: colors.amber, fontSize: 14, fontWeight: '900' },
  empty: { color: colors.muted, paddingVertical: 18, textAlign: 'center' },
  recentEyebrow: { marginTop: 25, marginBottom: 8 },
  recentItem: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  resultBadge: { paddingHorizontal: 8, paddingVertical: 5, borderRadius: 999, overflow: 'hidden', color: colors.text, fontSize: 13, fontWeight: '900', textTransform: 'uppercase' },
  badgeApproved: { backgroundColor: colors.greenDark },
  badgeObserved: { backgroundColor: colors.amberDark },
  badgeRejected: { backgroundColor: colors.blocked },
  busy: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center', gap: 10, backgroundColor: 'rgba(8,12,16,.72)' },
  busyText: { color: colors.text, fontWeight: '900' },
  modalBackdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 20, backgroundColor: 'rgba(0,0,0,.72)' },
  modalBackdropCompact: { padding: 10 },
  selectorModal: { width: '100%', maxWidth: 620, maxHeight: '78%', padding: 18, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panelStrong },
  observationModal: { width: '100%', maxWidth: 680, padding: 20, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.panelStrong },
  observationModalCompact: { maxHeight: '94%', padding: 14 },
  observationContent: { flexGrow: 1 },
  modalHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 13 },
  modalTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  modalClose: { color: colors.text, fontSize: 28, fontWeight: '900', paddingHorizontal: 7 },
  searchInput: { minHeight: 46, paddingHorizontal: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.backgroundDeep, marginBottom: 10 },
  optionList: { maxHeight: 420 },
  option: { padding: 14, borderBottomWidth: 1, borderBottomColor: colors.borderSoft },
  optionSelected: { backgroundColor: colors.selected },
  optionText: { color: colors.text, fontWeight: '800' },
  noteInput: { minHeight: 110, padding: 13, borderRadius: 11, borderWidth: 1, borderColor: colors.border, color: colors.text, backgroundColor: colors.backgroundDeep, textAlignVertical: 'top' },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10, marginTop: 16 },
  modalActionsCompact: { flexDirection: 'column-reverse' },
  modalButtonCompact: { width: '100%', alignItems: 'center' },
  cancelButton: { paddingHorizontal: 17, paddingVertical: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.border },
  cancelText: { color: colors.muted, fontWeight: '900' },
  confirmObservation: { paddingHorizontal: 18, paddingVertical: 13, borderRadius: 10, borderWidth: 1, borderColor: colors.amber, backgroundColor: colors.amberDark },
});
