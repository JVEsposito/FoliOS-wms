// Comex: textos, formatos y filtros de la oficina de expedientes y su configuración. Funciones
// puras, sin DOM, para probarlas con node --test.

export const ALERTS = {
    salio_incompleto: { label: 'Salió incompleto', note: 'La carga salió sin documentos que se necesitaban antes.', tone: 'critico', order: 0 },
    urgente: { label: 'Urgente', note: 'Faltan documentos de antes de la salida y el embarque está encima.', tone: 'critico', order: 1 },
    pendiente: { label: 'Pendiente', note: 'Faltan documentos o datos del embarque.', tone: 'atencion', order: 2 },
    completo: { label: 'Completo', note: 'Todos los documentos exigidos están resueltos.', tone: 'ok', order: 3 },
    cancelado: { label: 'Cancelado', note: 'El embarque se canceló en el calendario.', tone: 'neutro', order: 4 },
    sin_seguimiento: { label: 'Sin seguimiento', note: 'Comex no sigue los embarques de este cliente.', tone: 'neutro', order: 5 },
};

export const DOCUMENT_STATES = {
    pendiente: 'Pendiente',
    en_tramite: 'En trámite',
    listo: 'Listo',
    no_aplica: 'No aplica',
};

export const TRANSPORTS = { maritimo: 'Marítimo', aereo: 'Aéreo', terrestre: 'Terrestre' };

export const MANAGEMENT = {
    servicio: { label: 'Servicio', note: 'La planta presta el servicio y sigue los documentos del exportador.' },
    propia: { label: 'Exportación propia', note: 'La empresa es la exportadora.' },
    sin_seguimiento: { label: 'Sin seguimiento', note: 'Comex no sigue sus embarques.' },
};

export const MOMENTS = { antes_salida: 'Antes de la salida', despues_salida: 'Después de la salida' };

export const REQUIREMENTS = { siempre: 'Siempre', paises: 'Solo en algunos destinos', opcional: 'Opcional' };

export function alertInfo(alert) {
    return ALERTS[alert] || { label: String(alert || ''), note: '', tone: 'neutro', order: 9 };
}

export function documentStateLabel(state) {
    return DOCUMENT_STATES[state] || String(state || '');
}

/** «por_confirmar» y cualquier valor desconocido se muestran como por confirmar. */
export function transportLabel(mode) {
    return TRANSPORTS[mode] || 'Por confirmar';
}

export function managementLabel(management) {
    return MANAGEMENT[management]?.label || String(management || '');
}

/** «1 documento», «3 documentos». */
export function countText(count, singular, pluralForm = `${singular}s`) {
    const number = Number(count) || 0;

    return `${number.toLocaleString('es-CL')} ${number === 1 ? singular : pluralForm}`;
}

/** «2026-11-04» → «04-11-2026». */
export function formatDay(value) {
    const [year, month, day] = String(value || '').slice(0, 10).split('-');

    return day ? `${day}-${month}-${year}` : '—';
}

/** Fecha y hora de un instante en la zona indicada (por omisión, la del navegador). */
export function formatMoment(value, timeZone) {
    const date = new Date(value);
    if (!value || Number.isNaN(date.getTime())) return '—';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('es-CL', {
        timeZone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map((part) => [part.type, part.value]));

    return `${parts.day}-${parts.month}-${parts.year} ${parts.hour}:${parts.minute}`;
}

/** Día del embarque relativo a hoy: «Hoy», «Mañana», «En 3 días», «Hace 2 días». */
export function relativeDay(date, today) {
    const days = Math.round((Date.parse(`${String(date).slice(0, 10)}T00:00:00Z`) - Date.parse(`${String(today).slice(0, 10)}T00:00:00Z`)) / 86400000);
    if (Number.isNaN(days)) return '';
    if (days === 0) return 'Hoy';
    if (days === 1) return 'Mañana';
    if (days === -1) return 'Ayer';

    return days > 0 ? `En ${days} días` : `Hace ${-days} días`;
}

/** «2026-11-04» más o menos días, en calendario (sin horas ni zonas). */
export function addDays(date, days) {
    const value = new Date(`${String(date).slice(0, 10)}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

/** Tamaño de un respaldo: «820 KB», «2,4 MB». */
export function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024)).toLocaleString('es-CL')} KB`;

    return `${(value / (1024 * 1024)).toLocaleString('es-CL', { maximumFractionDigits: 1 })} MB`;
}

/** Avance del expediente: «5 de 8 resueltos». */
export function progressText(summary) {
    const required = Number(summary?.exigidos || 0);
    if (!required) return 'Sin documentos exigidos';

    return `${Number(summary.resueltos || 0)} de ${required} resueltos`;
}

/** Porcentaje entero del avance, para la barra. */
export function progressPercent(summary) {
    const required = Number(summary?.exigidos || 0);

    return required ? Math.round((Number(summary.resueltos || 0) / required) * 100) : 100;
}

/** Lo que falta, en una línea: «Antes de la salida: DUS, Guía de despacho · 2 después». */
export function missingText(row) {
    const missing = row?.faltantes || [];
    const before = missing.filter((item) => item.momento === 'antes_salida').map((item) => item.nombre);
    const after = missing.length - before.length;
    const parts = [];
    if (before.length) parts.push(`Antes de la salida: ${before.length > 3 ? `${before.slice(0, 2).join(', ')} y ${before.length - 2} más` : before.join(', ')}`);
    if (after) parts.push(`${countText(after, 'documento')} para después`);
    if (row?.resumen?.por_definir) parts.push(`${countText(row.resumen.por_definir, 'documento')} por definir`);

    return parts.join(' · ') || 'Nada pendiente';
}

/** Texto plano para buscar un embarque por código, cliente, contenedor, nave, booking o destino. */
function searchable(row) {
    return [row.codigo, row.cliente_nombre, row.contenedor, row.nave_vuelo, row.carga?.codigo, ...(row.bookings || []), ...(row.destinos || []), ...(row.recibidores || [])]
        .filter(Boolean).join(' ').toLocaleLowerCase('es');
}

/**
 * Filtra y ordena las filas del panel. Por omisión oculta cancelados y clientes sin seguimiento,
 * y ordena por gravedad de la alerta y luego por fecha.
 */
export function filterRows(rows, { term = '', client = '', alert = '', all = false } = {}) {
    const query = term.trim().toLocaleLowerCase('es');

    return (rows || [])
        .filter((row) => all || alert === row.alerta || !['cancelado', 'sin_seguimiento'].includes(row.alerta))
        .filter((row) => !client || row.cliente_id === client)
        .filter((row) => !alert || row.alerta === alert)
        .filter((row) => !query || searchable(row).includes(query))
        .sort((a, b) => alertInfo(a.alerta).order - alertInfo(b.alerta).order
            || `${a.fecha_programada} ${a.hora_programada}`.localeCompare(`${b.fecha_programada} ${b.hora_programada}`)
            || a.codigo.localeCompare(b.codigo));
}

/** Clientes presentes en el panel, para el filtro. */
export function clientsOf(rows) {
    const clients = new Map();
    for (const row of rows || []) clients.set(row.cliente_id, row.cliente_nombre);

    return [...clients].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'es'));
}

/** Documentos del expediente en tres grupos: antes de la salida, después y el resto. */
export function groupDocuments(documents) {
    const groups = [
        { key: 'antes', title: 'Antes de la salida', note: 'Se necesitan para que la carga salga de la planta.', items: [] },
        { key: 'despues', title: 'Después de la salida', note: 'Se completan después del despacho o del zarpe.', items: [] },
        { key: 'otros', title: 'Opcionales y por definir', note: 'No se exigen, o falta un dato del embarque para saberlo.', items: [] },
    ];
    for (const item of documents || []) {
        if (item.exigencia !== 'exigido') groups[2].items.push(item);
        else groups[item.tipo.momento === 'antes_salida' ? 0 : 1].items.push(item);
    }

    return groups.filter((group) => group.items.length);
}

/** Por qué aparece un documento que no se exige. */
export function requirementNote(item) {
    if (item.exigencia === 'opcional') return 'Opcional: se registra si se tiene.';
    if (item.exigencia === 'por_definir') return item.motivo_exigencia || 'Falta un dato del embarque para saber si se exige.';
    if (item.exigencia === 'no_exigido') return item.motivo_exigencia || 'Ya no se exige.';

    return item.motivo_exigencia || '';
}

/** Lo que tiene registrado un documento, en una línea. */
export function documentDetail(item) {
    const parts = [];
    if (item.numero) parts.push(`N° ${item.numero}`);
    if (item.fecha_emision) parts.push(`emitido el ${formatDay(item.fecha_emision)}`);
    if (item.estado === 'no_aplica' && item.motivo_no_aplica) parts.push(item.motivo_no_aplica);
    if (item.faltantes?.length) parts.push(`falta ${item.faltantes.join(', ')}`);

    return parts.join(' · ');
}

const FIELD_NAMES = {
    estado: 'estado',
    numero: 'número',
    fecha_emision: 'fecha de emisión',
    observacion: 'observación',
    motivo_no_aplica: 'motivo',
};

function fieldValue(field, value) {
    if (value === null || value === undefined || value === '') return 'vacío';
    if (field === 'estado') return documentStateLabel(value).toLocaleLowerCase('es');
    if (field === 'fecha_emision') return formatDay(value);

    return `«${value}»`;
}

/** Una entrada del historial: «DUS: se registró como en trámite (número «123»)». */
export function historyText(event) {
    const document = event.documento || 'Documento';
    const changes = event.datos?.cambios || {};
    if (event.tipo === 'archivo_subido') return `${document}: se subió el respaldo ${event.datos?.nombre || ''}`.trim();
    if (event.tipo === 'archivo_reemplazado') return `${document}: se reemplazó el respaldo por ${event.datos?.nombre || ''}`.trim();
    if (event.tipo === 'registrado') {
        const extra = Object.entries(changes).filter(([field]) => field !== 'estado').map(([field, [, after]]) => `${FIELD_NAMES[field] || field} ${fieldValue(field, after)}`);

        return `${document}: se registró como ${documentStateLabel(event.estado_nuevo).toLocaleLowerCase('es')}${extra.length ? ` (${extra.join(', ')})` : ''}`;
    }
    const list = Object.entries(changes).map(([field, [before, after]]) => `${FIELD_NAMES[field] || field}: ${fieldValue(field, before)} → ${fieldValue(field, after)}`);

    return `${document}: ${list.join('; ') || 'sin cambios'}`;
}

/** Valida el formulario de un documento antes de enviarlo; devuelve el primer problema o ''. */
export function documentFormProblem(type, form, hasFile) {
    if (!DOCUMENT_STATES[form.estado] || form.estado === 'pendiente') return 'Elija el estado del documento.';
    if (form.estado === 'no_aplica' && String(form.motivo || '').trim().length < 5) return 'Indique por qué este documento no aplica al embarque.';
    if (form.estado !== 'listo') return '';
    if (type.pide_numero && !String(form.numero || '').trim()) return 'Para marcarlo listo, ingrese el número del documento.';
    if (type.pide_fecha && !form.fecha_emision) return 'Para marcarlo listo, ingrese la fecha de emisión.';
    if (type.pide_archivo && !hasFile) return 'Para marcarlo listo, suba el respaldo (PDF o imagen).';

    return '';
}

/** Cuándo se exige un tipo de documento, en la configuración. */
export function requirementText(type) {
    if (type.exigencia === 'paises') {
        const names = (type.paises || []).map((country) => country.nombre);
        if (!names.length) return 'Solo en algunos destinos (sin países elegidos)';

        return names.length > 3 ? `Solo en ${names.slice(0, 3).join(', ')} y ${countText(names.length - 3, 'país', 'países')} más` : `Solo en ${names.join(', ')}`;
    }

    return REQUIREMENTS[type.exigencia] || String(type.exigencia || '');
}

/** Modalidades y clientes donde aplica: una lista vacía es «todos». */
export function scopeText(type) {
    const transports = (type.transportes || []).length ? type.transportes.map(transportLabel).join(', ') : 'Todo transporte';
    const managements = (type.gestiones || []).length ? type.gestiones.map(managementLabel).join(', ') : 'todo cliente';

    return `${transports} · ${managements}`;
}

/** Qué pide al marcarlo listo: «Número, fecha y respaldo». */
export function asksText(type) {
    const asks = [type.pide_numero && 'número', type.pide_fecha && 'fecha', type.pide_archivo && 'respaldo'].filter(Boolean);
    if (!asks.length) return 'Solo el estado';
    const text = asks.length === 1 ? asks[0] : `${asks.slice(0, -1).join(', ')} y ${asks.at(-1)}`;

    return text.charAt(0).toUpperCase() + text.slice(1);
}
