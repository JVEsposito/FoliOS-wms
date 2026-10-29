import * as Crypto from 'expo-crypto';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
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
  AvailableValidationPrefill,
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
  getValidationOperation,
  getValidationPrefill,
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
  replaceValidationOutboxItem,
  saveValidationCatalog,
  saveValidationSession,
  saveValidationWorkContext,
} from '../services/validationOfflineStore';
import { areas } from '../theme/areas';
import { operatorTheme as o } from '../theme/operatorTheme';
import { isPdaBuild } from '../config/appVariant';
import { ScanInput, ScanInputHandle } from '../components/ui/ScanInput';
import { LabelCell, LabelCounters, LabelRow, LabelSection, ResultTalon, SignalMark, StatusChip, TalonRow, type LabelTone } from '../components/validation/ValidationLabel';
import { articlesForOrigins, createOriginArticleSelector, indexValidationCatalog, productCompatibleWithOrigins } from '../domain/validationCatalogIndex';
import { PREFILL_FIELD_LABELS, prefillChanges } from '../domain/validationPrefill';

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

// Último pallet registrado: se muestra como talón junto al folio hasta la siguiente lectura.
type LastResult = {
  folio: string;
  result: ValidationResult;
  boxes: number;
  packageType: 'pallet' | 'saldo';
  client: string;
  brand: string;
  csgs: string[];
  at: string;
  delivery: 'confirmado' | 'en-equipo';
};

export function ValidationScreen({ auth, baseUrl, onLogout }: ValidationScreenProps) {
  const { height, width } = useWindowDimensions();
  const compact = width < 700 || width < height;
  // PDA de mano (≈360 dp de ancho): se condensan encabezados y el contexto de
  // jornada para que el folio y la decisión queden a pocos desplazamientos.
  const tight = isPdaBuild || width < 420;
  const [editingContext, setEditingContext] = useState(false);
  const folioInput = useRef<ScanInputHandle>(null);
  const page = useRef<ScrollView>(null);
  const flushing = useRef(false);
  const synchronizing = useRef(false);
  const resuming = useRef(false);
  const recoveryWorkContext = useRef<{ line: ValidationLine | null; shift: ValidationShift | null } | null>(null);
  const [catalog, setCatalog] = useState<ValidationCatalog | null>(null);
  const catalogRef = useRef<ValidationCatalog | null>(null);
  const catalogNeedsSave = useRef(false);
  const [catalogVerified, setCatalogVerified] = useState(false);
  const [catalogProblem, setCatalogProblem] = useState('');
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
  const [originError, setOriginError] = useState('');
  const [editingOutbox, setEditingOutbox] = useState<ValidationOutboxItem | null>(null);
  const [observation, setObservation] = useState<ObservationDraft | null>(null);
  const [lastResult, setLastResult] = useState<LastResult | null>(null);
  // Un problema al decidir se muestra junto a los botones, donde está el operador.
  const [decisionError, setDecisionError] = useState('');
  // Datos de planta con que partió el folio: el validador confirma o corrige lo que no coincide.
  const [prefill, setPrefill] = useState<AvailableValidationPrefill | null>(null);

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

  const catalogIndex = useMemo(() => catalog ? indexValidationCatalog(catalog) : null, [catalog]);
  const selectOriginArticles = useMemo(() => catalogIndex ? createOriginArticleSelector(catalogIndex) : null, [catalogIndex]);
  // La identidad de esta clave cambia solo al seleccionar/quitar CSG, no al digitar cajas, lote o proceso.
  const originSelectionKey = originDrafts.map((draft) => draft.originId).join('|');
  const selectedOriginIds = useMemo(() => originSelectionKey.split('|').filter(Boolean), [originSelectionKey]);
  const eligibleArticles = useMemo(
    () => selectOriginArticles?.(selectedOriginIds) ?? [],
    [selectOriginArticles, selectedOriginIds],
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
    () => uniqueOptions(eligibleArticles.map((item) => item.especie)),
    [eligibleArticles],
  );
  const varietyOptions = useMemo(
    () => uniqueOptions(eligibleArticles.filter((item) => !species || item.especie === species).map((item) => item.variedad)),
    [eligibleArticles, species],
  );
  const caliberOptions = useMemo(
    () => uniqueOptions(eligibleArticles.filter((item) => (!species || item.especie === species) && (!variety || item.variedad === variety)).map((item) => item.calibre)),
    [eligibleArticles, species, variety],
  );
  const packageOptions = useMemo(
    () => uniqueOptions(eligibleArticles.filter((item) => (!species || item.especie === species) && (!variety || item.variedad === variety) && (!caliber || item.calibre === caliber)).map((item) => item.envase)),
    [eligibleArticles, species, variety, caliber],
  );
  const selectedArticle = useMemo(
    () => eligibleArticles.find((item) => item.especie === species && item.variedad === variety && item.calibre === caliber && item.envase === packageName) ?? null,
    [eligibleArticles, species, variety, caliber, packageName],
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
    () => originDrafts.map((draft) => {
      const origin = catalogIndex?.originById.get(draft.originId);
      return origin?.cliente === client && origin.marca === brand ? origin : null;
    }),
    [catalogIndex, client, brand, originDrafts],
  );
  const selectedCombinations = useMemo(
    () => selectedOrigins.map((origin) => Boolean(origin && selectedArticle && catalogIndex?.articlesByOrigin.get(origin.id)?.has(selectedArticle.id)
      && (origin.variedad_ids === null || (selectedArticle.variedad_validacion_id !== null && origin.variedad_ids.includes(selectedArticle.variedad_validacion_id))))),
    [catalogIndex, selectedArticle, selectedOrigins],
  );
  const compositionBoxes = originDrafts.reduce((sum, draft) => sum + Number(draft.boxes || 0), 0);
  const prefillDiff = useMemo(() => prefill ? prefillChanges(prefill, {
    packageType, boxes, packingDate, articleId: selectedArticle?.id ?? null, categoryId, drafts: originDrafts,
  }) : [], [prefill, packageType, boxes, packingDate, selectedArticle, categoryId, originDrafts]);
  const selectedCombination = originDrafts.length > 0
    && selectedOrigins.every(Boolean)
    && selectedCombinations.every(Boolean);

  function resetProduct() {
    setSpecies(''); setVariety(''); setCaliber(''); setPackageName(''); setCategoryId('');
    setOriginError('');
  }

  function updateOriginDrafts(nextDrafts: OriginDraft[]) {
    const nextOriginIds = nextDrafts.map((draft) => draft.originId).filter(Boolean);
    const choosingOrigin = nextDrafts.some((draft) => draft.originId
      && !originDrafts.some((current) => current.key === draft.key && current.originId === draft.originId));
    // Comprueba el conjunto antes de reemplazar la captura: una intersección
    // vacía dejaría sin opciones todos los selectores de producto.
    if (choosingOrigin && catalogIndex && nextOriginIds.length > 1
      && !articlesForOrigins(catalogIndex, nextOriginIds).length) {
      const csgs = [...new Set(nextOriginIds.map((id) => catalogIndex.originById.get(id)?.csg ?? id))];
      setOriginError(`Los CSG ${csgs.join(' + ')} no tienen un producto habilitado en común. Selecciona otro CSG o revisa sus variedades y combinaciones en el catálogo.`);
      return;
    }
    if (originError && error === originError) setError('');
    setOriginError('');
    setOriginDrafts(nextDrafts);
    if (catalogIndex && !productCompatibleWithOrigins(
      catalogIndex,
      nextOriginIds,
      { especie: species, variedad: variety, calibre: caliber, envase: packageName },
    )) resetProduct();
  }

  useEffect(() => {
    void initialize();
  }, []);

  useOperationalPolling(
    synchronize,
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
      const local = await Promise.allSettled([
        loadCachedValidationCatalog(userId, deviceId, baseUrl),
        loadCachedValidationSession(userId, deviceId, sessionCacheId),
        loadValidationOutbox(userId, deviceId),
        loadValidationWorkContext(userId, deviceId),
      ]);
      const cached = local[0].status === 'fulfilled' ? local[0].value : null;
      const cachedSession = local[1].status === 'fulfilled' ? local[1].value : null;
      const queued = local[2].status === 'fulfilled' ? local[2].value : [];
      const savedContext = local[3].status === 'fulfilled' ? local[3].value : null;
      if (cached) { catalogRef.current = cached; setCatalog(cached); }
      if (cachedSession) setValidationSession(cachedSession);
      setOutbox(queued);
      if (savedContext) {
        setLine(savedContext.linea_proceso);
        setShift(savedContext.turno);
      }
      if (local[2].status === 'rejected') setError('No se pudo leer la bandeja local. Sus registros se conservan; reintenta la sincronización antes de capturar.');
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
    knownCatalog = catalogRef.current,
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
      // El historial y la bandeja no condicionan la instalación del catálogo.
      try {
        const loaded = await getValidationCatalog(baseUrl, auth.token, knownCatalog);
        const activeCatalog = loaded ?? knownCatalog;
        if (!activeCatalog) throw new Error('No existe un catálogo disponible para validación. Reintenta desde el indicador de sincronización.');
        catalogRef.current = activeCatalog;
        setCatalog(activeCatalog);
        setOnline(true);
        setCatalogVerified(true);
        setCatalogProblem('');
        setLastSync(new Date().toISOString());
        if (loaded) catalogNeedsSave.current = true;
        if (catalogNeedsSave.current) {
          try {
            await saveValidationCatalog(userId, deviceId, activeCatalog, baseUrl);
            catalogNeedsSave.current = false;
          }
          catch (reason) {
            setCatalogProblem(`Catálogo v${activeCatalog.temporada.version_catalogo} descargado, pero no pudo guardarse en el equipo. La copia anterior y las capturas se conservan. Reintenta. ${messageFrom(reason)}`);
          }
        }
        if (notify || (knownCatalog && knownCatalog.temporada.version_catalogo !== activeCatalog.temporada.version_catalogo)) {
          setNotice(`Catálogo v${activeCatalog.temporada.version_catalogo} recibido. Si cambió un envase, revisa la selección de la captura en curso; no se sustituyó por otro.`);
        }
      } catch (reason) {
        if (reason instanceof ApiError && reason.status === 401) {
          Alert.alert('Sesión vencida', 'Vuelve a iniciar el turno.');
          onLogout();
          return;
        }
        setCatalogVerified(false);
        setOnline(!(reason instanceof ApiError && reason.status === 0));
        setCatalogProblem(`${messageFrom(reason)} ${knownCatalog ? `Se conserva v${knownCatalog.temporada.version_catalogo}; su vigencia no está confirmada.` : 'Esta PDA aún no tiene un catálogo válido.'}`);
      }
      try { await flushOutbox(); }
      catch (reason) { setError(`No se pudo sincronizar la bandeja local. Se conservan sus capturas. ${messageFrom(reason)}`); }
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

  function handleFolioChange(value: string) {
    if (editingOutbox) return;
    setFolio(value);
    if (folioReview && normalizeFolio(value) !== folioReview.numero_folio) {
      setFolioReview(null);
      setObservation(null);
      setPrefill(null);
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
    setOriginError('');
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
    if (editingOutbox) return;
    const normalized = normalizeFolio(requestedFolio ?? folio);
    if (!normalized) {
      setError('Escanea o ingresa el folio.');
      return;
    }

    setFolio(normalized);
    setError('');
    setNotice('');
    setLastResult(null);
    setPrefill(null);

    const localItem = outbox.find((item) => normalizeFolio(item.payload.numero_folio) === normalized);
    if (localItem) {
      if (localItem.status !== 'pendiente') {
        await resumeItem(localItem);
        return;
      }
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
        // Con conexión, la PDA parte con lo que la planta informa del pallet.
        const source = baseUrl ? await loadPrefill(baseUrl, normalized) : null;
        setNotice(source?.available
          ? `Datos de planta cargados para ${normalized}. Revisa el pallet y corrige solo lo que no coincida.`
          : `${normalized} no posee validaciones anteriores en la temporada activa.${source?.reason ? ` ${source.reason}` : ''}`);
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

  /** Sin datos de planta se valida como siempre: el aviso explica por qué. */
  async function loadPrefill(url: string, normalized: string): Promise<{ available: boolean; reason: string | null }> {
    try {
      const data = await getValidationPrefill(url, auth.token, normalized);
      if (!data.disponible) return { available: false, reason: data.motivo };
      return { available: applyPrefill(data), reason: null };
    } catch {
      return { available: false, reason: null };
    }
  }

  function applyPrefill(data: AvailableValidationPrefill): boolean {
    if (!catalog || !catalogIndex) return false;
    const origins = data.composicion
      .map((line) => line.origen_validacion_id ? catalogIndex.originById.get(line.origen_validacion_id) ?? null : null);
    const first = origins.find((origin) => origin?.activo) ?? null;
    const prefillClient = first?.cliente
      ?? catalog.origenes.find((origin) => origin.activo && origin.cliente === data.origen.cliente)?.cliente ?? '';
    const prefillBrand = first?.marca
      ?? catalog.origenes.find((origin) => origin.activo && origin.cliente === prefillClient && origin.marca === data.origen.marca)?.marca ?? '';
    const article = catalog.articulos.find((item) => item.id === data.articulo_validacion_id && item.activo) ?? null;
    const category = catalog.categorias.find((item) => item.id === data.categoria_validacion_id && item.activo) ?? null;

    setPackageType(data.tipo_bulto);
    setBoxes(String(data.cantidad_cajas));
    setPackingDate(data.fecha_embalaje ?? todayLocal());
    setClient(prefillClient);
    setBrand(prefillBrand);
    setOriginDrafts(data.composicion.length ? data.composicion.map((line, index) => {
      const origin = origins[index];
      return {
        key: newOriginDraft().key,
        originId: origin?.activo && origin.cliente === prefillClient && origin.marca === prefillBrand ? origin.id : '',
        boxes: String(line.cantidad_cajas),
        lot: line.lote_materia_prima ?? '',
        process: line.proceso_packing ?? '',
      };
    }) : [newOriginDraft()]);
    setSpecies(article?.especie ?? ''); setVariety(article?.variedad ?? '');
    setCaliber(article?.calibre ?? ''); setPackageName(article?.envase ?? '');
    setCategoryId(category?.id ?? '');
    setOriginError('');
    setPrefill(data);
    return true;
  }

  function validateForm(result?: ValidationResult) {
    if (!catalog) return 'No existe un catálogo sincronizado en esta PDA.';
    if (!line || !shift) return 'Selecciona la línea de proceso y el turno antes de validar.';
    if (!folio.trim()) return 'Escanea o ingresa el folio.';
    if (terminalDecision) return 'El folio ya posee una decisión final y no admite una nueva validación.';
    if (originError) return originError;
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
    if (outbox.some((item) => item.id !== editingOutbox?.id
      && normalizeFolio(item.payload.numero_folio) === normalizeFolio(folio))) {
      return 'El folio posee una validación local pendiente o con error. Sincronízala antes de registrar otro intento.';
    }
    return null;
  }

  async function submit(result: ValidationResult, reason?: string, note?: string) {
    const problem = validateForm(result);
    if (problem) {
      setError(problem);
      setDecisionError(problem);
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
      // Constancia de que el validador partió con los datos de planta y de qué corrigió.
      ...(prefill
        ? { precarga_origen: { huella: prefill.huella, modificados: prefillDiff } }
        : editingOutbox?.payload.precarga_origen ? { precarga_origen: editingOutbox.payload.precarga_origen } : {}),
    };

    const registered: Omit<LastResult, 'delivery'> = {
      folio: payload.numero_folio,
      result,
      boxes: payload.cantidad_cajas,
      packageType,
      client,
      brand,
      csgs: selectedOrigins.flatMap((origin) => origin ? [origin.csg] : []),
      at: payload.generado_dispositivo_at,
    };

    setBusy(true);
    setError('');
    setNotice('');
    setLastResult(null);
    try {
      let items = editingOutbox
        ? await replaceValidationOutboxItem(userId, deviceId, editingOutbox.id, payload)
        : await enqueueValidation(userId, deviceId, payload);
      setOutbox(items);
      finishRecovery();
      if (!baseUrl) {
        setLastResult({ ...registered, delivery: 'en-equipo' });
      } else {
        try {
          const attempt = await registerValidation(baseUrl, auth.token, payload);
          items = await removeValidationFromOutbox(userId, deviceId, payload.operacion_id);
          setOutbox(items);
          setRecent((current) => [attempt, ...current.filter((item) => item.id !== attempt.id)].slice(0, 10));
          try { await refreshSession(); } catch { /* conserva actualización optimista */ }
          setOnline(true);
          setLastResult({ ...registered, delivery: 'confirmado' });
        } catch (reasonCaught) {
          if (reasonCaught instanceof ApiError && reasonCaught.status === 0) {
            setOnline(false);
            setLastResult({ ...registered, delivery: 'en-equipo' });
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
      setPrefill(null);
      setBoxes('');
      setPackingDate(todayLocal());
      setOriginDrafts([newOriginDraft()]);
      setOriginError('');
      setObservation(null);
      // Vuelve arriba: el talón queda junto al folio y el lector, listo para el siguiente.
      page.current?.scrollTo({ y: 0, animated: true });
      setTimeout(() => folioInput.current?.focus(), 180);
    } catch (reasonCaught) {
      setError(messageFrom(reasonCaught));
    } finally {
      setBusy(false);
    }
  }

  async function resumeItem(item: ValidationOutboxItem) {
    if (resuming.current || editingOutbox) return;
    if (!baseUrl) {
      setError('Conecta la PDA para comprobar el envío antes de corregirlo. La captura sigue guardada.');
      return;
    }
    resuming.current = true;
    setBusy(true);
    setError('');
    setNotice('');
    setLastResult(null);
    try {
      const items = await loadValidationOutbox(userId, deviceId);
      const current = items.find((candidate) => candidate.id === item.id);
      setOutbox(items);
      if (!current || current.status === 'pendiente') {
        setError('La captura cambió. Espera la sincronización y vuelve a revisar la bandeja.');
        return;
      }
      const operation = await getValidationOperation(baseUrl, auth.token, current.id);
      if (operation.operacion_id !== current.id) throw new Error('El servidor no pudo comprobar este envío. La captura sigue guardada.');
      if (operation.validacion) {
        const attempt = operation.validacion;
        if (normalizeFolio(attempt.numero_folio) !== normalizeFolio(current.payload.numero_folio)) {
          throw new Error('El identificador corresponde a otro folio. Requiere revisión de supervisión.');
        }
        setOutbox(await removeValidationFromOutbox(userId, deviceId, current.id));
        setRecent((previous) => [attempt, ...previous.filter((candidate) => candidate.id !== attempt.id)].slice(0, 10));
        setFolio(attempt.numero_folio);
        if (attempt.estado === 'aceptada') applyAttempt(attempt);
        else setError('El envío quedó registrado como conflicto en el servidor. Consulta el historial con supervisión.');
        try { await refreshSession(); } catch { /* el registro ya fue confirmado */ }
        setNotice(`${attempt.numero_folio}: el envío ya estaba registrado en el servidor. Se actualizó la bandeja local.`);
        return;
      }
      if (!['sin_registro', 'rechazada', 'fallida'].includes(operation.estado)) {
        throw new Error('El servidor todavía no confirma que el envío pueda corregirse. La captura sigue guardada.');
      }
      const payload = current.payload;
      if (!catalog || payload.temporada_id !== catalog.temporada.id) {
        throw new Error('La captura pertenece a otra temporada o falta sincronizar el catálogo. Requiere revisión de supervisión.');
      }
      const attempts = await listValidationsByFolio(baseUrl, auth.token, payload.numero_folio);
      const final = attempts.find((attempt) => attempt.estado === 'aceptada'
        && (attempt.resultado === 'aprobado' || attempt.resultado === 'rechazado'));
      if (final) {
        const message = `El folio ${payload.numero_folio} ya tiene una decisión final en el servidor. Requiere revisión de supervisión.`;
        setOutbox(await markValidationOutboxItem(userId, deviceId, current.id, 'conflicto', message));
        setFolio(final.numero_folio);
        applyAttempt(final);
        setError(message);
        return;
      }
      const article = catalog.articulos.find((candidate) => candidate.id === payload.articulo_validacion_id);
      const origin = catalog.origenes.find((candidate) => candidate.id === payload.origen_validacion_id);
      const composition = payload.composicion?.length ? payload.composicion : [{
        origen_validacion_id: payload.origen_validacion_id, cantidad_cajas: payload.cantidad_cajas,
      }];
      setFolio(payload.numero_folio);
      setFolioReview({ numero_folio: payload.numero_folio, status: 'nuevo', attempt: null });
      setPackageType(payload.tipo_bulto);
      setBoxes(String(payload.cantidad_cajas));
      recoveryWorkContext.current = { line, shift };
      setLine(payload.linea_proceso);
      setShift(payload.turno);
      setClient(origin?.cliente ?? '');
      setBrand(origin?.marca ?? '');
      setSpecies(article?.especie ?? ''); setVariety(article?.variedad ?? '');
      setCaliber(article?.calibre ?? ''); setPackageName(article?.envase ?? '');
      setCategoryId(payload.categoria_validacion_id);
      setPackingDate(payload.fecha_embalaje ?? payload.generado_dispositivo_at.slice(0, 10));
      setOriginDrafts(composition.map((entry) => ({
        key: newOriginDraft().key, originId: entry.origen_validacion_id,
        boxes: String(entry.cantidad_cajas),
        lot: 'lote_materia_prima' in entry ? entry.lote_materia_prima ?? '' : '',
        process: 'proceso_packing' in entry ? entry.proceso_packing ?? '' : '',
      })));
      setOriginError('');
      setObservation(null);
      setEditingOutbox(current);
      setError(operation.mensaje ?? current.message ?? '');
      setNotice(`${payload.numero_folio} recuperado. Revisa los datos y registra la decisión para enviarlo de nuevo.`);
    } catch (reasonCaught) {
      setError(messageFrom(reasonCaught));
    } finally {
      resuming.current = false;
      setBusy(false);
    }
  }

  function finishRecovery() {
    if (recoveryWorkContext.current) {
      setLine(recoveryWorkContext.current.line);
      setShift(recoveryWorkContext.current.shift);
      recoveryWorkContext.current = null;
    }
    setEditingOutbox(null);
  }

  function cancelRecovery() {
    finishRecovery();
    setFolio(''); setFolioReview(null); setPrefill(null); setBoxes('');
    setPackingDate(todayLocal());
    setClient(''); setBrand(''); resetProduct();
    setOriginDrafts([newOriginDraft()]); setObservation(null);
    setError('');
    setNotice('La captura original sigue guardada en la bandeja. Puedes retomarla más adelante.');
  }

  function openObservation(result: Exclude<ValidationResult, 'aprobado'>) {
    setObservation({
      result,
      reason: observedAttempt?.motivo ?? editingOutbox?.payload.motivo ?? '',
      note: observedAttempt?.observacion ?? editingOutbox?.payload.observacion ?? '',
    });
  }

  async function updateWorkContext(nextLine: ValidationLine | null, nextShift: ValidationShift | null) {
    setLine(nextLine);
    setShift(nextShift);
    if (nextLine && nextShift) setEditingContext(false);
    if (nextLine && nextShift && !editingOutbox) {
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
    return <View style={styles.boot}><ActivityIndicator color={o.color.primary} size="large" /><Text style={styles.bootText}>Preparando catálogo de validación…</Text></View>;
  }

  const device = isPdaBuild ? 'PDA' : 'tablet';
  const wide = !compact;
  const pendingOutbox = outbox.filter((item) => item.status === 'pendiente');
  const declaredBoxes = Number(boxes || 0);
  const sumTone: LabelTone = !declaredBoxes && !compositionBoxes
    ? 'neutral'
    : compositionBoxes === declaredBoxes ? 'success' : 'warning';
  const sumLabel = !declaredBoxes && !compositionBoxes
    ? 'Sin cajas ingresadas'
    : `${compositionBoxes} de ${declaredBoxes} cajas`;
  const reviewOutbox = outbox.filter((item) => item.status !== 'pendiente');
  const syncTone: LabelTone = syncing ? 'neutral' : !catalog ? 'critical'
    : !online || !catalogVerified || catalogProblem || pendingOutbox.length || reviewOutbox.length ? 'warning' : 'success';
  const syncLabel = syncing
    ? 'Sincronizando…'
    : !catalog ? 'Sin catálogo · reintentar'
      : !online
      ? (pendingOutbox.length ? `Sin conexión · ${pendingOutbox.length} en la ${device}` : 'Sin conexión')
      : !catalogVerified ? 'Catálogo sin actualizar'
        : catalogProblem ? 'Catálogo sin respaldo'
          : pendingOutbox.length ? `En línea · ${pendingOutbox.length} por enviar`
            : reviewOutbox.length ? `${reviewOutbox.length} por revisar` : 'Catálogo al día';
  const headerMeta = [
    auth.dispositivo.codigo,
    wide ? `sesión desde ${formatDateTime(sessionStartedAt)}` : null,
    catalog ? `catálogo v${catalog.temporada.version_catalogo}` : 'sin catálogo',
    lastSync ? `sincronizado ${formatTime(lastSync)}` : null,
  ].filter(Boolean).join(' · ');
  const ready = Boolean(selectedCombination && selectedCategory);
  const decisionDisabled = busy || terminalDecision || !catalog;
  const serverRecords = validationSession?.resumen.registros_realizados ?? 0;
  const noCsgYet = !originDrafts.some((draft) => draft.originId);

  const approveButton = (
    <Pressable
      key="aprobar"
      disabled={decisionDisabled}
      onPress={() => void submit('aprobado')}
      style={[styles.decisionButton, styles.approveButton, wide && styles.approveButtonWide, decisionDisabled && styles.decisionButtonDisabled]}
    >
      <Text style={[styles.decisionMark, styles.approveText, decisionDisabled && styles.decisionTextDisabled]}>✓</Text>
      <Text style={[styles.decisionText, styles.approveText, decisionDisabled && styles.decisionTextDisabled]}>{observedAttempt ? 'Aprobar corrección' : 'Aprobar'}</Text>
    </Pressable>
  );
  const observeButton = (
    <Pressable
      key="observar"
      disabled={decisionDisabled}
      onPress={() => openObservation('observado')}
      style={[styles.decisionButton, styles.observeButton, decisionDisabled && styles.decisionButtonDisabled]}
    >
      <SignalMark size={12} tone={decisionDisabled ? 'neutral' : 'warning'} />
      <Text style={[styles.decisionText, styles.observeText, decisionDisabled && styles.decisionTextDisabled]}>{observedAttempt ? 'Mantener observado' : 'Observar'}</Text>
    </Pressable>
  );
  const rejectButton = canReject ? (
    <Pressable
      key="rechazar"
      disabled={decisionDisabled}
      onPress={() => openObservation('rechazado')}
      style={[styles.decisionButton, styles.rejectButton, decisionDisabled && styles.decisionButtonDisabled]}
    >
      <SignalMark size={12} tone={decisionDisabled ? 'neutral' : 'critical'} />
      <Text style={[styles.decisionText, styles.rejectText, decisionDisabled && styles.decisionTextDisabled]}>Rechazar</Text>
    </Pressable>
  ) : null;

  const workContext = line && shift && !editingContext ? (
    <Pressable accessibilityLabel="Cambiar línea y turno" onPress={() => setEditingContext(true)} style={styles.jornada}>
      <View style={styles.jornadaCopy}>
        <Text style={styles.jornadaLabel}>Jornada · se conserva entre pallets</Text>
        <Text style={styles.jornadaValue}>Línea {line} · Turno {shift}</Text>
      </View>
      <Text style={styles.linkText}>Cambiar</Text>
    </Pressable>
  ) : (
    <View style={styles.jornadaEditor}>
      <View style={styles.jornadaEditorHead}>
        <Text style={styles.jornadaEditorTitle}>Selecciona dónde estás validando</Text>
        <Text style={styles.jornadaLabel}>Se conserva para los siguientes pallets</Text>
      </View>
      <View style={[styles.jornadaGroups, compact && styles.jornadaGroupsCompact]}>
        <View style={styles.jornadaGroup}>
          <Text style={styles.cellLabel}>Línea de proceso</Text>
          <View style={styles.segmented}>
            {([1, 2, 3] as ValidationLine[]).map((value) => (
              <Pressable accessibilityLabel={`Línea ${value}`} key={value} onPress={() => void updateWorkContext(value, shift)} style={[styles.segment, line === value && styles.segmentActive]}>
                <Text style={[styles.segmentText, line === value && styles.segmentTextActive]}>{value}</Text>
              </Pressable>
            ))}
          </View>
        </View>
        <View style={styles.jornadaGroup}>
          <Text style={styles.cellLabel}>Turno</Text>
          <View style={styles.segmented}>
            {(['A', 'B'] as ValidationShift[]).map((value) => (
              <Pressable accessibilityLabel={`Turno ${value}`} key={value} onPress={() => void updateWorkContext(line, value)} style={[styles.segment, shift === value && styles.segmentActive]}>
                <Text style={[styles.segmentText, shift === value && styles.segmentTextActive]}>{value}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      </View>
    </View>
  );

  const boxesCell = (
    <LabelCell label="Cajas del bulto">
      <TextInput
        accessibilityLabel="Cajas del bulto"
        editable={!terminalDecision}
        keyboardType="number-pad"
        onChangeText={(value) => { const clean = value.replace(/[^0-9]/g, ''); setBoxes(clean); setOriginDrafts((current) => current.length === 1 ? [{ ...current[0], boxes: clean }] : current); }}
        placeholder="0"
        placeholderTextColor={o.color.muted}
        style={[styles.cellInput, styles.cellInputNumber, terminalDecision && styles.disabled]}
        value={boxes}
      />
    </LabelCell>
  );

  const label = (
    <View style={styles.label}>
      <View style={styles.folioStrip}>
        <Text style={styles.cellLabel}>Folio</Text>
        <ScanInput
          ref={folioInput}
          autoFocus={isPdaBuild}
          disabled={busy || Boolean(editingOutbox)}
          inputStyle={[styles.folioInput, compact && styles.folioInputCompact]}
          onChangeText={handleFolioChange}
          onSubmit={(code) => void inspectFolio(code)}
          placeholder={tight ? 'Pistolea el folio' : 'Pistolea la etiqueta'}
          submitLabel={tight ? 'OK' : 'Consultar'}
          value={folio}
        />
        {folioReview ? <FolioReviewCard review={folioReview} /> : null}
        {prefill && folioReview?.status === 'nuevo' ? <PrefillCard changes={prefillDiff} prefill={prefill} /> : null}
        {editingOutbox ? (
          <View style={styles.correction}>
            <View style={styles.correctionCopy}>
              <Text style={styles.correctionTitle}>Corrigiendo envío · {editingOutbox.payload.numero_folio}</Text>
              <Text style={styles.correctionText}>La captura original sigue guardada hasta que registres la corrección.</Text>
            </View>
            <Pressable disabled={busy} onPress={cancelRecovery} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancelar corrección</Text></Pressable>
          </View>
        ) : null}
      </View>

      <LabelSection index={1} title="Bulto">
        <LabelRow last={wide}>
          <LabelCell divider={wide} flex={1.6} label="Tipo de bulto">
            <View style={styles.segmented}>
              {(['pallet', 'saldo'] as const).map((type) => (
                <Pressable
                  accessibilityState={{ selected: packageType === type }}
                  disabled={terminalDecision}
                  key={type}
                  onPress={() => setPackageType(type)}
                  style={[styles.segment, type === 'pallet' && styles.segmentWide, packageType === type && styles.segmentActive, terminalDecision && styles.disabled]}
                >
                  <Text numberOfLines={1} style={[styles.segmentText, packageType === type && styles.segmentTextActive]}>{packageType === type ? '✓ ' : ''}{type === 'pallet' ? 'Pallet completo' : 'Saldo'}</Text>
                </Pressable>
              ))}
            </View>
          </LabelCell>
          {wide ? boxesCell : null}
        </LabelRow>
        {wide ? null : <LabelRow last>{boxesCell}</LabelRow>}
      </LabelSection>

      <LabelSection index={2} title="Origen comercial" hint={wide ? 'Una sola fecha por bulto validado en línea' : undefined}>
        {wide ? (
          <LabelRow last>
            <SelectField divider disabled={terminalDecision} label="Cliente" options={clientOptions} value={client} onChange={(value) => { setClient(value); setBrand(''); setOriginDrafts([newOriginDraft()]); resetProduct(); }} />
            <SelectField divider disabled={terminalDecision || !client} emptyLabel={client ? 'Seleccionar' : 'Primero el cliente'} label="Marca" options={brandOptions} value={brand} onChange={(value) => { setBrand(value); setOriginDrafts([newOriginDraft()]); resetProduct(); }} />
            <LabelCell label="Fecha de embalaje">
              <TextInput accessibilityLabel="Fecha de embalaje" editable={!terminalDecision} onChangeText={setPackingDate} placeholder="AAAA-MM-DD" placeholderTextColor={o.color.muted} style={[styles.cellInput, styles.cellInputCode, terminalDecision && styles.disabled]} value={packingDate} />
            </LabelCell>
          </LabelRow>
        ) : (
          <>
            <LabelRow>
              <SelectField disabled={terminalDecision} label="Cliente" options={clientOptions} value={client} onChange={(value) => { setClient(value); setBrand(''); setOriginDrafts([newOriginDraft()]); resetProduct(); }} />
            </LabelRow>
            <LabelRow last>
              <SelectField divider disabled={terminalDecision || !client} emptyLabel={client ? 'Seleccionar' : 'Primero el cliente'} label="Marca" options={brandOptions} value={brand} onChange={(value) => { setBrand(value); setOriginDrafts([newOriginDraft()]); resetProduct(); }} />
              <LabelCell hint="Una por bulto" label="Fecha de embalaje">
                <TextInput accessibilityLabel="Fecha de embalaje" editable={!terminalDecision} onChangeText={setPackingDate} placeholder="AAAA-MM-DD" placeholderTextColor={o.color.muted} style={[styles.cellInput, styles.cellInputCode, terminalDecision && styles.disabled]} value={packingDate} />
              </LabelCell>
            </LabelRow>
          </>
        )}
      </LabelSection>

      <LabelSection
        aside={<StatusChip small label={sumLabel} tone={sumTone} />}
        hint={wide ? 'La suma debe coincidir con las cajas del bulto' : undefined}
        index={3}
        title="Composición por CSG"
      >
        {wide ? (
          <View style={styles.compositionHead}>
            <Text style={[styles.compositionHeadText, { flex: 1.5 }]}>CSG / Predio</Text>
            <Text style={[styles.compositionHeadText, { flex: 0.6 }]}>Cajas</Text>
            <Text style={[styles.compositionHeadText, { flex: 1.1 }]}>Lote MP</Text>
            <Text style={[styles.compositionHeadText, { flex: 1.1 }]}>Proceso packing</Text>
            <View style={styles.compositionAction} />
          </View>
        ) : null}
        {originDrafts.map((draft, index) => {
          const csg = (
            <SelectField
              disabled={terminalDecision || !brand}
              divider={wide}
              emptyLabel={brand ? 'Seleccionar' : 'Primero la marca'}
              flex={wide ? 1.5 : 1}
              hideLabel={wide}
              label={`CSG / Predio ${index + 1}`}
              options={csgOptions}
              searchable
              value={draft.originId}
              onChange={(value) => updateOriginDrafts(originDrafts.map((item) => item.key === draft.key ? { ...item, originId: value } : item))}
            />
          );
          const remove = originDrafts.length > 1 ? (
            <Pressable
              accessibilityLabel={`Quitar la línea ${index + 1}`}
              disabled={terminalDecision}
              onPress={() => updateOriginDrafts(originDrafts.filter((item) => item.key !== draft.key))}
              style={styles.removeButton}
            >
              <Text style={styles.removeText}>Quitar</Text>
            </Pressable>
          ) : null;
          const boxesInput = <TextInput accessibilityLabel={`Cajas de la línea ${index + 1}`} editable={!terminalDecision} keyboardType="number-pad" onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, boxes: value.replace(/[^0-9]/g, '') } : item))} placeholder="0" placeholderTextColor={o.color.muted} style={[styles.cellInput, styles.cellInputNumberSmall]} value={draft.boxes} />;
          const lotInput = <TextInput accessibilityLabel={`Lote MP de la línea ${index + 1}`} autoCapitalize="characters" autoCorrect={false} editable={!terminalDecision} onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, lot: value } : item))} placeholder="Según etiqueta" placeholderTextColor={o.color.muted} style={[styles.cellInput, styles.cellInputCode, compact && styles.cellInputCodeCompact]} value={draft.lot} />;
          const processInput = <TextInput accessibilityLabel={`Proceso packing de la línea ${index + 1}`} autoCapitalize="characters" autoCorrect={false} editable={!terminalDecision} onChangeText={(value) => setOriginDrafts((current) => current.map((item) => item.key === draft.key ? { ...item, process: value } : item))} placeholder="Según etiqueta" placeholderTextColor={o.color.muted} style={[styles.cellInput, styles.cellInputCode, compact && styles.cellInputCodeCompact]} value={draft.process} />;
          return wide ? (
            <View key={draft.key} style={styles.compositionRow}>
              {csg}
              <View style={[styles.compositionCell, { flex: 0.6 }]}>{boxesInput}</View>
              <View style={[styles.compositionCell, { flex: 1.1 }]}>{lotInput}</View>
              <View style={[styles.compositionCell, { flex: 1.1 }]}>{processInput}</View>
              <View style={styles.compositionAction}>{remove}</View>
            </View>
          ) : (
            <View key={draft.key} style={styles.compositionBlock}>
              <View style={styles.compositionBlockTop}>
                {csg}
                <LabelCell label="Cajas" width={86}>{boxesInput}</LabelCell>
                {remove}
              </View>
              <View style={styles.compositionBlockBottom}>
                <LabelCell divider label="Lote MP">{lotInput}</LabelCell>
                <LabelCell label="Proceso packing">{processInput}</LabelCell>
              </View>
            </View>
          );
        })}
        <View style={styles.compositionFoot}>
          <Pressable disabled={terminalDecision || !brand} onPress={() => updateOriginDrafts([...originDrafts, newOriginDraft()])} style={[styles.addButton, (terminalDecision || !brand) && styles.disabled]}>
            <Text style={styles.linkText}>+ Agregar línea (otro CSG, lote o proceso)</Text>
          </Pressable>
          {originError ? <Text accessibilityRole="alert" style={styles.originError}>{originError}</Text> : null}
        </View>
      </LabelSection>

      <LabelSection hint={noCsgYet && wide ? 'Elige el CSG para filtrar las variedades' : undefined} index={4} title="Producto">
        {noCsgYet && !wide ? <Text style={styles.sectionNote}>Elige el CSG para filtrar las variedades.</Text> : null}
        {wide ? (
          <LabelRow last>
            <SelectField divider disabled={terminalDecision || !selectedOriginIds.length} label="Especie" options={speciesOptions} value={species} onChange={(value) => { setSpecies(value); setVariety(''); setCaliber(''); setPackageName(''); }} />
            <SelectField divider disabled={terminalDecision || !species || noCsgYet} label="Variedad" options={varietyOptions} value={variety} onChange={(value) => { setVariety(value); setCaliber(''); setPackageName(''); }} />
            <SelectField divider disabled={terminalDecision || !variety} label="Calibre" options={caliberOptions} value={caliber} onChange={(value) => { setCaliber(value); setPackageName(''); }} />
            <SelectField disabled={terminalDecision || !caliber} label="Envase" options={packageOptions} value={packageName} onChange={setPackageName} />
          </LabelRow>
        ) : (
          <>
            <LabelRow>
              <SelectField divider disabled={terminalDecision || !selectedOriginIds.length} label="Especie" options={speciesOptions} value={species} onChange={(value) => { setSpecies(value); setVariety(''); setCaliber(''); setPackageName(''); }} />
              <SelectField disabled={terminalDecision || !species || noCsgYet} label="Variedad" options={varietyOptions} value={variety} onChange={(value) => { setVariety(value); setCaliber(''); setPackageName(''); }} />
            </LabelRow>
            <LabelRow last>
              <SelectField divider disabled={terminalDecision || !variety} label="Calibre" options={caliberOptions} value={caliber} onChange={(value) => { setCaliber(value); setPackageName(''); }} />
              <SelectField disabled={terminalDecision || !caliber} label="Envase" options={packageOptions} value={packageName} onChange={setPackageName} />
            </LabelRow>
          </>
        )}
      </LabelSection>

      <LabelSection index={5} title="Categoría">
        <LabelRow last>
          <SelectField disabled={terminalDecision || !packageName} label="Categoría" options={categoryOptions} searchable value={categoryId} onChange={setCategoryId} />
        </LabelRow>
      </LabelSection>
    </View>
  );

  const decision = (
    <View style={styles.decision}>
      {error && error === decisionError ? (
        <Pressable accessibilityRole="alert" onPress={() => setError('')} style={[styles.banner, styles.bannerError]}>
          <SignalMark size={12} tone="critical" />
          <Text style={styles.bannerText}>{error}</Text>
          <Text style={styles.close}>×</Text>
        </Pressable>
      ) : null}
      <View style={styles.decisionSummary}>
        <StatusChip label={ready ? 'Combinación habilitada' : 'Faltan datos obligatorios'} tone={ready ? 'success' : 'neutral'} />
        <View style={styles.decisionSummaryCopy}>
          <Text style={styles.summaryText}>{selectedCategory ? `Categoría ${selectedCategory.nombre}` : 'Categoría pendiente'} · {selectedArticle ? `${selectedArticle.especie} ${selectedArticle.variedad} · ${selectedArticle.calibre} · ${selectedArticle.envase}` : 'artículo pendiente'}</Text>
          <Text style={styles.summaryText}>{selectedOrigins.every(Boolean) ? `${client} · ${brand} · ${selectedOrigins.map((origin) => `CSG ${origin?.csg}`).join(' + ')} · ${packingDate}` : 'Origen pendiente'}</Text>
        </View>
      </View>
      <View style={[styles.decisionButtons, compact && styles.decisionButtonsCompact]}>
        {compact ? [approveButton, observeButton, rejectButton] : [observeButton, rejectButton, approveButton]}
      </View>
    </View>
  );

  const resultTalon = lastResult ? (
    <ResultTalon
      footer={lastResult.delivery === 'confirmado'
        ? `Confirmado por el servidor${lastResult.result === 'aprobado' ? ' · queda pendiente de prefrío' : ''}`
        : `Guardado en la ${device} · se enviará al sincronizar`}
      footerTone={lastResult.delivery === 'confirmado' ? 'success' : 'warning'}
      folio={lastResult.folio}
      onDismiss={() => setLastResult(null)}
      rows={[
        { label: 'Cliente', value: lastResult.client },
        { label: 'Marca', value: lastResult.brand },
        { label: 'Bulto', value: `${lastResult.boxes} cj · ${packageLabel(lastResult.packageType)}` },
        { label: 'CSG', value: lastResult.csgs.join(' · '), mono: true },
      ]}
      time={formatTime(lastResult.at)}
      title={resultLabel(lastResult.result)}
      tone={resultTone(lastResult.result)}
    />
  ) : null;

  const pendingTray = previousSessionOutbox.length ? (
    <View style={styles.tray}>
      <View style={styles.trayHead}>
        <SignalMark size={12} tone="warning" />
        <Text style={styles.trayTitle}>{previousSessionOutbox.length === 1 ? 'Pendiente de una sesión anterior' : 'Pendientes de una sesión anterior'}</Text>
      </View>
      <Text style={styles.trayText}>
        {previousSessionOutbox.length === 1
          ? `1 operación quedó guardada en esta ${device} y no se suma a la sesión actual.`
          : `${previousSessionOutbox.length} operaciones quedaron guardadas en esta ${device} y no se suman a la sesión actual.`}
      </Text>
      {previousSessionOutbox.map((item) => (
        <View key={item.id} style={styles.trayItem}>
          <View style={styles.trayItemCopy}>
            <Text style={styles.trayFolio}>{item.payload.numero_folio}</Text>
            <Text style={styles.trayDetail}>{statusLabel(item.status)} · {formatTime(item.payload.generado_dispositivo_at)}</Text>
            {item.message ? <Text style={styles.trayMessage}>{item.message}</Text> : null}
          </View>
          {item.status !== 'pendiente' ? (
            <Pressable disabled={busy || syncing || Boolean(editingOutbox)} onPress={() => void resumeItem(item)} style={styles.trayAction}>
              <Text style={styles.trayActionText}>Retomar</Text>
            </Pressable>
          ) : null}
        </View>
      ))}
    </View>
  ) : null;

  const session = (
    <View style={styles.session}>
      <View style={styles.sessionHead}>
        <View style={styles.sessionHeadCopy}>
          <Text style={styles.sessionTitle}>Esta sesión</Text>
          <Text numberOfLines={1} style={styles.sessionMeta}>{compact ? `${validationSession?.resumen.folios_trabajados ?? 0} folios · ` : ''}{auth.usuario.nombre} · desde {formatDateTime(sessionStartedAt)}</Text>
        </View>
        <Pressable onPress={() => setSessionExpanded((current) => !current)} style={styles.linkButton}>
          <Text style={styles.linkText}>{sessionExpanded ? 'Contraer' : 'Ver todos'}</Text>
        </Pressable>
      </View>
      <LabelCounters compact={compact} items={[
        ...(compact ? [] : [{ label: 'Folios', value: validationSession?.resumen.folios_trabajados ?? 0, tone: 'neutral' as const }]),
        { label: 'Aprobados', value: validationSession?.resumen.aprobados ?? 0, tone: 'success' },
        { label: 'Observados', value: validationSession?.resumen.observados ?? 0, tone: 'warning' },
        { label: 'Rechazados', value: validationSession?.resumen.rechazados ?? 0, tone: 'critical' },
        { label: 'Conflictos', value: validationSession?.resumen.conflictos ?? 0, tone: 'critical' },
      ]} />
      <Text style={styles.sessionBalance}>
        Intentos {serverRecords} · por enviar {pendingSessionOutbox.length} · sin confirmar {unconfirmedSessionOutbox.length} · total capturado {serverRecords + unconfirmedSessionOutbox.length}
      </Text>
      <View style={styles.sessionListHead}>
        <Text style={styles.sessionListTitle}>Pallets trabajados</Text>
        <Text style={styles.sessionListCount}>{validationSession?.meta.total ?? 0} en servidor · {currentSessionOutbox.length} {currentSessionOutbox.length === 1 ? 'local' : 'locales'}</Text>
      </View>

      {currentSessionOutbox.map((item) => (
        <TalonRow
          aside={(
            <>
              <StatusChip small label={statusLabel(item.status)} tone={item.status === 'pendiente' ? 'warning' : 'critical'} />
              {item.status !== 'pendiente' ? (
                <Pressable disabled={busy || syncing || Boolean(editingOutbox)} onPress={() => void resumeItem(item)} style={styles.trayAction}>
                  <Text style={styles.trayActionText}>Retomar</Text>
                </Pressable>
              ) : null}
            </>
          )}
          detail={`${item.payload.cantidad_cajas} cj · ${packageLabel(item.payload.tipo_bulto)} · línea ${item.payload.linea_proceso}, turno ${item.payload.turno} · guardado en la ${device}`}
          folio={item.payload.numero_folio}
          key={item.id}
          message={item.message}
          time={formatTime(item.payload.generado_dispositivo_at)}
          tone="warning"
        />
      ))}

      {visibleSessionAttempts.map((item) => (
        <TalonRow
          aside={<StatusChip small label={item.estado === 'conflicto' ? 'Conflicto' : resultLabel(item.resultado)} tone={item.estado === 'conflicto' ? 'critical' : resultTone(item.resultado)} />}
          detail={`${item.cantidad_cajas} cj · ${packageLabel(item.tipo_bulto)} · línea ${item.linea_proceso ?? '—'}, turno ${item.turno ?? '—'}${item.numero_intento > 1 ? ` · intento ${item.numero_intento}` : ''}`}
          disabled={busy || Boolean(editingOutbox)}
          folio={item.numero_folio}
          key={item.id}
          onPress={() => { setFolio(item.numero_folio); void inspectFolio(item.numero_folio); }}
          time={formatTime(item.recibido_servidor_at)}
        />
      ))}

      {!currentSessionOutbox.length && !visibleSessionAttempts.length
        ? <Text style={styles.empty}>Todavía no hay pallets validados en esta sesión.</Text>
        : null}

      {sessionExpanded && validationSession && validationSession.meta.current_page < validationSession.meta.last_page ? (
        <Pressable disabled={loadingMoreSession} onPress={() => void loadMoreSession()} style={[styles.linkButton, styles.loadMore, loadingMoreSession && styles.disabled]}>
          <Text style={styles.linkText}>{loadingMoreSession ? 'Cargando…' : 'Cargar más registros'}</Text>
        </Pressable>
      ) : null}
    </View>
  );

  const syncButton = (
    <Pressable
      accessibilityLabel={`Sincronizar. Estado: ${syncLabel}`}
      disabled={syncing}
      onPress={() => void synchronize({ notify: true })}
      style={styles.syncButton}
    >
      <StatusChip label={syncLabel} small={compact} tone={syncTone} />
    </Pressable>
  );

  return (
    <View style={styles.screen}>
      <ScrollView ref={page} contentContainerStyle={[styles.page, compact && styles.pageCompact]} keyboardShouldPersistTaps="handled">
        <View style={[styles.header, compact && styles.headerCompact]}>
          <Image accessibilityLabel="Frigorífico" source={areas.frigorifico.gajo} style={[styles.headerGajo, compact && styles.headerGajoCompact]} />
          <View style={styles.headerCopy}>
            <Text style={styles.headerArea}>Frigorífico</Text>
            <Text numberOfLines={1} style={[styles.headerTitle, compact && styles.headerTitleCompact]}>Validación PT</Text>
            {wide ? <Text numberOfLines={1} style={styles.headerMeta}>{headerMeta}</Text> : null}
          </View>
          {wide ? syncButton : null}
          <Pressable onPress={logout} style={styles.logout}><Text style={styles.logoutText}>Salir</Text></Pressable>
        </View>
        {compact ? (
          <View style={styles.headerMetaRow}>
            <Text numberOfLines={2} style={styles.headerMetaCompact}>{headerMeta}</Text>
            {syncButton}
          </View>
        ) : null}

        {catalogProblem ? (
          <View accessibilityRole="alert" style={[styles.banner, styles.bannerError]}>
            <SignalMark size={12} tone="warning" />
            <Text style={styles.bannerText}>{catalogProblem}</Text>
          </View>
        ) : null}
        {error && error !== decisionError ? (
          <Pressable accessibilityRole="alert" onPress={() => setError('')} style={[styles.banner, styles.bannerError]}>
            <SignalMark size={12} tone="critical" />
            <Text style={styles.bannerText}>{error}</Text>
            <Text style={styles.close}>×</Text>
          </Pressable>
        ) : null}
        {notice ? (
          <Pressable onPress={() => setNotice('')} style={[styles.banner, styles.bannerNotice]}>
            <SignalMark size={12} tone="success" />
            <Text style={styles.bannerText}>{notice}</Text>
            <Text style={styles.close}>×</Text>
          </Pressable>
        ) : null}

        <View style={[styles.columns, compact && styles.columnsCompact]}>
          <View style={[styles.mainColumn, compact && styles.columnCompact]}>
            {compact ? resultTalon : null}
            {compact ? pendingTray : null}
            {workContext}
            {label}
            {decision}
          </View>
          <View style={[styles.sideColumn, compact && styles.columnCompact]}>
            {compact ? null : resultTalon}
            {compact ? null : pendingTray}
            {session}
          </View>
        </View>
      </ScrollView>

      {busy ? <View pointerEvents="none" style={styles.busy}><ActivityIndicator color={o.color.primary} size="large" /><Text style={styles.busyText}>Procesando…</Text></View> : null}
      <ObservationModal catalog={catalog} draft={observation} onCancel={() => setObservation(null)} onConfirm={(draft) => void submit(draft.result, draft.reason, draft.note)} />
    </View>
  );
}

function FolioReviewCard({ review }: { review: FolioReview }) {
  const attempt = review.attempt;
  const tone: LabelTone = review.status === 'nuevo'
    ? 'neutral'
    : review.status === 'observado' ? 'warning' : review.status === 'aprobado' ? 'success' : 'critical';
  const title = review.status === 'nuevo'
    ? 'Folio sin validaciones previas'
    : review.status === 'observado'
      ? 'Folio observado · corrección pendiente'
      : review.status === 'aprobado'
        ? 'Folio ya aprobado'
        : 'Folio rechazado definitivamente';
  const detail = review.status === 'nuevo'
    ? 'Puedes completar y registrar su primera validación.'
    : review.status === 'observado'
      ? 'La información del intento anterior fue recuperada. Corrige únicamente lo necesario y registra una nueva decisión.'
      : 'Los datos se muestran como consulta y no admiten una nueva decisión desde esta pantalla.';

  return (
    <View style={styles.review}>
      <StatusChip label={title} tone={tone} />
      {attempt ? <Text style={styles.reviewMeta}>Intento {attempt.numero_intento} · {attempt.usuario.nombre} · {formatTime(attempt.recibido_servidor_at)}</Text> : null}
      {attempt?.motivo ? <Text style={styles.reviewText}>Motivo: {reasonLabel(attempt.motivo)}</Text> : null}
      {attempt?.observacion ? <Text style={styles.reviewText}>Observación: {attempt.observacion}</Text> : null}
      <Text style={styles.reviewDetail}>{detail}</Text>
    </View>
  );
}

/** Lo que trajo la planta y lo que el validador corrigió: confirma el pallet físico. */
function PrefillCard({ prefill, changes }: { prefill: AvailableValidationPrefill; changes: string[] }) {
  // La composición ya queda cargada abajo, línea por línea: aquí basta el resumen.
  const csgs = [...new Set(prefill.composicion.map((line) => line.csg ?? 'sin CSG'))];
  return (
    <View style={styles.review}>
      <StatusChip label="Datos de planta" tone={prefill.desactualizada ? 'warning' : 'neutral'} />
      <Text style={styles.reviewMeta}>
        {prefill.cantidad_cajas} cajas · {packageLabel(prefill.tipo_bulto)}{prefill.leido_at ? ` · leído ${formatDateTime(prefill.leido_at)}` : ''}
      </Text>
      {prefill.desactualizada ? <Text style={styles.reviewText}>La lectura de planta está desactualizada: revisa con más cuidado.</Text> : null}
      <Text style={styles.reviewText}>
        {prefill.composicion.length} {prefill.composicion.length === 1 ? 'línea' : 'líneas'} · CSG {csgs.join(', ')}
      </Text>
      {prefill.pendientes.map((item) => <Text key={item} style={styles.reviewText}>Completar: {item}</Text>)}
      <Text style={styles.reviewDetail}>
        {changes.length
          ? `Corregido respecto de planta: ${changes.map((field) => PREFILL_FIELD_LABELS[field as keyof typeof PREFILL_FIELD_LABELS] ?? field).join(', ')}.`
          : 'Confirma que el pallet coincide o corrige lo que no.'}
      </Text>
    </View>
  );
}

/**
 * Selector con forma de celda de etiqueta: rótulo arriba, valor grande y ⌄. `boxed` lo
 * dibuja con borde para usarlo fuera de la etiqueta (diálogo de motivo).
 */
function SelectField({ label, options, value, onChange, disabled = false, searchable = false, divider = false, flex = 1, hideLabel = false, boxed = false, emptyLabel = 'Seleccionar' }: {
  label: string;
  options: Option[];
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  searchable?: boolean;
  divider?: boolean;
  flex?: number;
  hideLabel?: boolean;
  boxed?: boolean;
  emptyLabel?: string;
}) {
  const [visible, setVisible] = useState(false);
  const [query, setQuery] = useState('');
  const selected = options.find((option) => option.value === value);
  const filtered = useMemo(() => visible
    ? options.filter((option) => `${option.label} ${option.search ?? ''}`.toLowerCase().includes(query.trim().toLowerCase()))
    : [], [visible, options, query]);

  return <>
    <View style={[boxed ? styles.selectBoxed : styles.selectCell, !boxed && { flex }, divider && styles.selectDivider]}>
      {hideLabel ? null : <Text numberOfLines={1} style={styles.cellLabel}>{label}</Text>}
      <Pressable
        accessibilityLabel={`${label}: ${selected?.label ?? emptyLabel}`}
        disabled={disabled}
        onPress={() => setVisible(true)}
        style={[styles.selectButton, boxed && styles.selectButtonBoxed, disabled && styles.disabled]}
      >
        <Text numberOfLines={1} style={[styles.selectText, !selected && styles.selectPlaceholder]}>{selected?.label ?? emptyLabel}</Text>
        <Text style={styles.chevron}>⌄</Text>
      </Pressable>
    </View>
    <Modal animationType="fade" transparent visible={visible} onRequestClose={() => setVisible(false)}>
      <View style={styles.modalBackdrop}>
        <View style={styles.selectorModal}>
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>{label}</Text>
            <Pressable accessibilityLabel="Cerrar" onPress={() => setVisible(false)}><Text style={styles.modalClose}>×</Text></Pressable>
          </View>
          {searchable || options.length > 8 ? <TextInput autoFocus onChangeText={setQuery} placeholder={`Buscar ${label.toLowerCase()}`} placeholderTextColor={o.color.muted} style={styles.searchInput} value={query} /> : null}
          <FlatList
            data={filtered}
            initialNumToRender={16}
            keyExtractor={(option) => option.value}
            keyboardShouldPersistTaps="handled"
            ListEmptyComponent={<Text style={styles.empty}>Sin opciones coincidentes.</Text>}
            maxToRenderPerBatch={24}
            renderItem={({ item: option }) => (
              <Pressable onPress={() => { onChange(option.value); setQuery(''); setVisible(false); }} style={[styles.option, option.value === value && styles.optionSelected]}>
                <Text style={styles.optionText}>{option.label}</Text>
              </Pressable>
            )}
            style={styles.optionList}
            windowSize={5}
          />
        </View>
      </View>
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
  const rejecting = draft.result === 'rechazado';

  return (
    <Modal animationType="slide" transparent visible onRequestClose={onCancel}>
      <View style={[styles.modalBackdrop, compact && styles.modalBackdropCompact]}>
        <View style={[styles.observationModal, compact && styles.observationModalCompact]}>
          <ScrollView contentContainerStyle={styles.observationContent} keyboardShouldPersistTaps="handled">
            <View style={styles.modalHeader}>
              <View style={styles.observationHead}>
                <StatusChip label={rejecting ? 'Rechazo definitivo' : 'Pallet observado'} tone={rejecting ? 'critical' : 'warning'} />
                <Text style={styles.modalTitle}>Registra el motivo</Text>
              </View>
              <Pressable accessibilityLabel="Cerrar" onPress={onCancel}><Text style={styles.modalClose}>×</Text></Pressable>
            </View>
            <SelectField boxed label="Motivo" options={reasonOptions} searchable value={reason} onChange={setReason} />
            <Text style={[styles.cellLabel, styles.noteLabel]}>{reason === 'otro' ? 'Observación (obligatoria)' : 'Observación'}</Text>
            <TextInput multiline onChangeText={setNote} placeholder="Describe el problema detectado o la corrección requerida" placeholderTextColor={o.color.muted} style={styles.noteInput} value={note} />
            <View style={[styles.modalActions, compact && styles.modalActionsCompact]}>
              <Pressable onPress={onCancel} style={[styles.secondaryButton, compact && styles.modalButtonCompact]}><Text style={styles.secondaryButtonText}>Cancelar</Text></Pressable>
              <Pressable disabled={invalid} onPress={() => onConfirm({ ...draft, reason, note })} style={[styles.confirmButton, rejecting ? styles.confirmReject : styles.confirmObserve, compact && styles.modalButtonCompact, invalid && styles.disabled]}>
                <Text style={[styles.decisionText, rejecting ? styles.rejectText : styles.observeText]}>Confirmar {draft.result}</Text>
              </Pressable>
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
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
// Hora de 24 h armada a mano: cabe en el talón (14:05, no 2:05 p. m.) y no depende de
// cómo resuelva Intl cada motor (Hermes en el equipo, V8 en las pruebas).
function twoDigits(value: number) { return String(value).padStart(2, '0'); }
function formatTime(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : `${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`; }
function formatDateTime(value: string | null) { const date = value ? new Date(value) : null; return !date || Number.isNaN(date.getTime()) ? 'sin hora registrada' : `${twoDigits(date.getDate())}/${twoDigits(date.getMonth() + 1)} ${formatTime(value as string)}`; }
function messageFrom(reason: unknown) {
  if (reason instanceof Error) return reason.message;
  if (reason && typeof reason === 'object' && 'message' in reason && typeof reason.message === 'string') return reason.message;
  return 'Ocurrió un error inesperado.';
}
function statusLabel(status: ValidationOutboxItem['status']) { return status === 'pendiente' ? 'Pendiente' : status === 'conflicto' ? 'Conflicto' : 'Requiere revisión'; }
function reasonLabel(value: string) { return value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
function packageLabel(value: 'pallet' | 'saldo') { return value === 'saldo' ? 'saldo' : 'pallet completo'; }
function resultLabel(value: ValidationResult) { return value === 'aprobado' ? 'Aprobado' : value === 'observado' ? 'Observado' : 'Rechazado'; }
function resultTone(value: ValidationResult): LabelTone { return value === 'aprobado' ? 'success' : value === 'observado' ? 'warning' : 'critical'; }

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: o.color.canvas },
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, backgroundColor: o.color.canvas },
  bootText: { color: o.color.muted, fontSize: 15, fontWeight: '700' },
  page: { padding: 20, gap: 16, paddingBottom: 48 },
  pageCompact: { padding: 10, gap: 10, paddingBottom: 32 },
  disabled: { opacity: 0.38 },

  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    marginHorizontal: -20,
    marginTop: -20,
    paddingHorizontal: 20,
    paddingVertical: 12,
    backgroundColor: o.color.navy,
    borderBottomWidth: 3,
    borderBottomColor: areas.frigorifico.bright,
  },
  headerCompact: { gap: 8, marginHorizontal: -10, marginTop: -10, paddingHorizontal: 10, paddingVertical: 8 },
  headerGajo: { width: 36, height: 36 },
  headerGajoCompact: { width: 28, height: 28 },
  headerCopy: { flex: 1, minWidth: 0, gap: 2 },
  headerArea: { color: areas.frigorifico.bright, fontSize: 12, fontWeight: '700' },
  headerTitle: { color: o.color.text, fontFamily: o.font.condensed, fontSize: 26, fontWeight: '800' },
  headerTitleCompact: { fontSize: 20 },
  headerMeta: { color: o.color.muted, fontSize: 14 },
  headerMetaRow: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 2 },
  headerMetaCompact: { flex: 1, color: o.color.muted, fontSize: 12 },
  syncButton: { minHeight: 44, justifyContent: 'center' },
  logout: { minHeight: 44, minWidth: 44, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12, borderRadius: o.radius.control, borderWidth: 1.5, borderColor: o.color.borderStrong },
  logoutText: { color: o.color.text, fontSize: 14, fontWeight: '700' },

  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingLeft: 14, borderRadius: 12, borderWidth: 1 },
  bannerError: { borderColor: o.color.criticalBorder, backgroundColor: o.color.criticalSurface },
  bannerNotice: { borderColor: o.color.successBorder, backgroundColor: o.color.successSurface },
  bannerText: { flex: 1, paddingVertical: 12, color: o.color.text, fontSize: 15, fontWeight: '600' },
  close: { minHeight: 44, minWidth: 44, textAlign: 'center', textAlignVertical: 'center', color: o.color.text, fontSize: 22, fontWeight: '700' },

  columns: { flexDirection: 'row', alignItems: 'flex-start', gap: 20 },
  columnsCompact: { flexDirection: 'column', alignItems: 'stretch', gap: 12 },
  mainColumn: { flex: 1.65, minWidth: 0, gap: 14 },
  sideColumn: { flex: 1, minWidth: 0, gap: 14 },
  columnCompact: { flexGrow: 0, flexBasis: 'auto', width: '100%' },

  jornada: { minHeight: 60, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, borderRadius: 12, borderWidth: 1, borderColor: o.color.border, backgroundColor: o.color.surface },
  jornadaCopy: { flex: 1, gap: 4 },
  jornadaLabel: { color: o.color.muted, fontSize: 13 },
  jornadaValue: { color: o.color.text, fontSize: 18, fontWeight: '800' },
  jornadaEditor: { gap: 12, padding: 16, borderRadius: 12, borderWidth: 1.5, borderColor: o.color.primary, backgroundColor: o.color.surface },
  jornadaEditorHead: { gap: 3 },
  jornadaEditorTitle: { color: o.color.text, fontSize: 17, fontWeight: '800' },
  jornadaGroups: { flexDirection: 'row', gap: 16 },
  jornadaGroupsCompact: { flexDirection: 'column', gap: 10 },
  jornadaGroup: { flex: 1, gap: 6 },

  segmented: { flexDirection: 'row', gap: 8 },
  segment: { flex: 1, minHeight: 50, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8, borderRadius: 12, borderWidth: 1, borderColor: o.color.borderStrong, backgroundColor: 'transparent' },
  segmentWide: { flex: 1.6 },
  segmentActive: { borderWidth: 2, borderColor: o.color.primary, backgroundColor: o.color.selected },
  segmentText: { color: o.color.textSecondary, fontSize: 16, fontWeight: '600' },
  segmentTextActive: { color: o.color.text, fontWeight: '800' },

  label: { overflow: 'hidden', borderRadius: 12, borderWidth: 1, borderColor: o.color.border, backgroundColor: o.color.surface },
  folioStrip: { gap: 8, padding: 14, borderBottomWidth: 2, borderBottomColor: o.color.border },
  folioInput: { minHeight: 64, borderWidth: 2, borderColor: o.color.primary, backgroundColor: o.color.canvas, fontFamily: o.font.mono, fontSize: 26, fontWeight: '700', letterSpacing: -0.5 },
  folioInputCompact: { minHeight: 54, fontSize: 17, letterSpacing: -0.3 },
  cellLabel: { color: o.color.muted, fontSize: 13, fontWeight: '600' },
  cellInput: { minHeight: 44, paddingHorizontal: 0, paddingVertical: 4, borderBottomWidth: 1, borderBottomColor: o.color.border, color: o.color.text, fontSize: 16 },
  cellInputNumber: { fontFamily: o.font.mono, fontSize: 28, fontWeight: '700', letterSpacing: -1 },
  cellInputNumberSmall: { fontFamily: o.font.mono, fontSize: 20, fontWeight: '700' },
  cellInputCode: { fontFamily: o.font.mono, fontSize: 18, fontWeight: '700', letterSpacing: -0.5 },
  cellInputCodeCompact: { fontSize: 15, letterSpacing: -0.3 },
  sectionNote: { paddingHorizontal: 16, paddingTop: 10, color: o.color.muted, fontSize: 13 },

  selectCell: { minWidth: 0, gap: 6, paddingHorizontal: 16, paddingVertical: 10 },
  selectDivider: { borderRightWidth: 1, borderRightColor: o.color.disabled },
  selectBoxed: { gap: 6 },
  selectButton: { minHeight: 44, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, borderBottomWidth: 1, borderBottomColor: o.color.border },
  selectButtonBoxed: { minHeight: 52, paddingHorizontal: 14, borderRadius: 12, borderWidth: 1.5, borderColor: o.color.borderStrong, backgroundColor: o.color.canvas },
  selectText: { flex: 1, color: o.color.text, fontSize: 17, fontWeight: '700' },
  selectPlaceholder: { color: o.color.muted, fontWeight: '600' },
  chevron: { color: o.color.muted, fontSize: 20, fontWeight: '700' },

  compositionHead: { minHeight: 34, flexDirection: 'row', alignItems: 'center', borderBottomWidth: 2, borderBottomColor: o.color.borderStrong },
  compositionHeadText: { paddingHorizontal: 12, color: o.color.muted, fontSize: 12, fontWeight: '600' },
  compositionRow: { flexDirection: 'row', alignItems: 'stretch', borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  compositionCell: { minWidth: 0, justifyContent: 'center', paddingHorizontal: 12, paddingVertical: 8, borderRightWidth: 1, borderRightColor: o.color.disabled },
  compositionAction: { width: 72, alignItems: 'center', justifyContent: 'center' },
  compositionBlock: { borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  compositionBlockTop: { flexDirection: 'row', alignItems: 'stretch' },
  compositionBlockBottom: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: o.color.disabled },
  compositionFoot: { gap: 8, paddingHorizontal: 8, paddingVertical: 6 },
  removeButton: { minHeight: 44, minWidth: 64, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10 },
  removeText: { color: o.color.critical, fontSize: 14, fontWeight: '700' },
  addButton: { minHeight: 44, alignSelf: 'flex-start', justifyContent: 'center', paddingHorizontal: 8 },
  originError: { paddingHorizontal: 8, paddingBottom: 6, color: o.color.critical, fontSize: 14, fontWeight: '700' },

  review: { gap: 6, paddingTop: 4 },
  reviewMeta: { color: o.color.muted, fontSize: 14, fontWeight: '600' },
  reviewText: { color: o.color.text, fontSize: 14 },
  reviewDetail: { color: o.color.textSecondary, fontSize: 14 },
  correction: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 12, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: o.color.warningBorder, backgroundColor: o.color.warningSurface },
  correctionCopy: { flex: 1, minWidth: 200, gap: 3 },
  correctionTitle: { color: o.color.warning, fontSize: 15, fontWeight: '800' },
  correctionText: { color: o.color.text, fontSize: 14 },

  decision: { gap: 12 },
  decisionSummary: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 10 },
  decisionSummaryCopy: { flex: 1, minWidth: 220, gap: 2 },
  summaryText: { color: o.color.muted, fontSize: 13 },
  decisionButtons: { flexDirection: 'row', gap: 12 },
  decisionButtonsCompact: { flexDirection: 'column', gap: 10 },
  decisionButton: { flex: 1, minHeight: 60, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, paddingHorizontal: 16, borderRadius: 14, borderWidth: 2 },
  decisionButtonDisabled: { borderColor: o.color.disabled, backgroundColor: o.color.disabled },
  decisionMark: { fontSize: 20, fontWeight: '900' },
  decisionText: { fontSize: 18, fontWeight: '800' },
  decisionTextDisabled: { color: o.color.disabledText },
  approveButton: { minHeight: 68, borderColor: o.color.primary, backgroundColor: o.color.primary },
  approveButtonWide: { flex: 1.7 },
  approveText: { color: o.color.onPrimary },
  observeButton: { borderColor: o.color.warningBorder, backgroundColor: o.color.warningSurface },
  observeText: { color: o.color.warning },
  rejectButton: { borderColor: o.color.criticalBorder, backgroundColor: 'transparent' },
  rejectText: { color: o.color.critical },

  tray: { gap: 10, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: o.color.warningBorder, backgroundColor: o.color.warningSurface },
  trayHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  trayTitle: { flex: 1, color: o.color.warning, fontSize: 16, fontWeight: '800' },
  trayText: { color: o.color.text, fontSize: 14 },
  trayItem: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 8, paddingLeft: 14, paddingRight: 8, borderRadius: 10, borderWidth: 1, borderColor: o.color.warningBorder, backgroundColor: o.color.canvas },
  trayItemCopy: { flex: 1, minWidth: 0, gap: 3 },
  trayFolio: { color: o.color.text, fontFamily: o.font.mono, fontSize: 20, fontWeight: '700', letterSpacing: -0.5 },
  trayDetail: { color: o.color.textSecondary, fontSize: 13 },
  trayMessage: { color: o.color.critical, fontSize: 13 },
  trayAction: { minHeight: 48, minWidth: 44, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14, borderRadius: 12, borderWidth: 2, borderColor: o.color.warning },
  trayActionText: { color: o.color.warning, fontSize: 15, fontWeight: '800' },

  session: { overflow: 'hidden', borderRadius: 12, borderWidth: 1, borderColor: o.color.border, backgroundColor: o.color.surface },
  sessionHead: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingLeft: 16, paddingRight: 6, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  sessionHeadCopy: { flex: 1, minWidth: 0, gap: 3 },
  sessionTitle: { color: o.color.text, fontFamily: o.font.condensed, fontSize: 22, fontWeight: '800' },
  sessionMeta: { color: o.color.muted, fontSize: 13 },
  sessionBalance: { paddingHorizontal: 16, paddingVertical: 8, color: o.color.muted, fontSize: 13, borderTopWidth: 1, borderTopColor: o.color.disabled, borderBottomWidth: 2, borderBottomColor: o.color.border },
  sessionListHead: { minHeight: 36, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, paddingHorizontal: 16, borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  sessionListTitle: { color: o.color.textSecondary, fontSize: 13, fontWeight: '700' },
  sessionListCount: { flexShrink: 1, color: o.color.muted, fontSize: 13, textAlign: 'right' },
  linkButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 10 },
  linkText: { color: o.color.primary, fontSize: 15, fontWeight: '700' },
  loadMore: { alignSelf: 'center' },
  empty: { paddingHorizontal: 16, paddingVertical: 28, color: o.color.muted, fontSize: 15, textAlign: 'center' },

  secondaryButton: { minHeight: 48, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 16, borderRadius: 12, borderWidth: 2, borderColor: o.color.borderStrong },
  secondaryButtonText: { color: o.color.text, fontSize: 15, fontWeight: '700' },

  busy: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center', gap: 10, backgroundColor: 'rgba(8,12,16,.72)' },
  busyText: { color: o.color.text, fontSize: 15, fontWeight: '800' },
  modalBackdrop: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 20, backgroundColor: 'rgba(0,0,0,.72)' },
  modalBackdropCompact: { padding: 10 },
  selectorModal: { width: '100%', maxWidth: 620, maxHeight: '78%', padding: 18, borderRadius: 16, borderWidth: 1, borderColor: o.color.border, backgroundColor: o.color.surfaceMuted },
  observationModal: { width: '100%', maxWidth: 680, padding: 20, borderRadius: 16, borderWidth: 1, borderColor: o.color.border, backgroundColor: o.color.surfaceMuted },
  observationModalCompact: { maxHeight: '94%', padding: 14 },
  observationContent: { flexGrow: 1 },
  observationHead: { flex: 1, gap: 8 },
  modalHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 14 },
  modalTitle: { color: o.color.text, fontSize: 20, fontWeight: '800' },
  modalClose: { minHeight: 44, minWidth: 44, textAlign: 'center', textAlignVertical: 'center', color: o.color.text, fontSize: 28, fontWeight: '700', paddingHorizontal: 7 },
  searchInput: { minHeight: 48, paddingHorizontal: 12, borderRadius: 10, borderWidth: 1.5, borderColor: o.color.borderStrong, color: o.color.text, backgroundColor: o.color.canvas, fontSize: 16, marginBottom: 10 },
  optionList: { maxHeight: 420 },
  option: { minHeight: 52, justifyContent: 'center', paddingHorizontal: 14, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: o.color.disabled },
  optionSelected: { backgroundColor: o.color.selected },
  optionText: { color: o.color.text, fontSize: 16, fontWeight: '700' },
  noteLabel: { marginTop: 14, marginBottom: 6 },
  noteInput: { minHeight: 110, padding: 13, borderRadius: 12, borderWidth: 1.5, borderColor: o.color.borderStrong, color: o.color.text, backgroundColor: o.color.canvas, fontSize: 16, textAlignVertical: 'top' },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10, marginTop: 16 },
  modalActionsCompact: { flexDirection: 'column-reverse' },
  modalButtonCompact: { width: '100%' },
  confirmButton: { minHeight: 52, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18, borderRadius: 12, borderWidth: 2 },
  confirmObserve: { borderColor: o.color.warningBorder, backgroundColor: o.color.warningSurface },
  confirmReject: { borderColor: o.color.criticalBorder, backgroundColor: o.color.criticalSurface },
});
