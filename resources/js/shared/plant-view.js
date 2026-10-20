// Modelo visual del plano operacional. Funciones puras: reciben el snapshot de
// Operación ahora y un elemento del plano, y devuelven qué debe mostrarse. El
// color de estado (verde, ámbar, rojo) se reserva para valores; el tipo de
// recinto se distingue por forma, etiqueta e icono.

import { cameraDisplayName } from './camera-display.js';

export const TUNNEL_STATE_LABELS = {
    borrador: 'Borrador',
    cargando: 'Cargando',
    listo_para_iniciar: 'Listo',
    en_proceso: 'En ciclo',
    pendiente_verificacion: 'Verificación',
    disponible: 'En espera',
    bloqueado_otra_temporada: 'Temporada anterior',
    mantenimiento: 'Mantención',
    fuera_servicio: 'Fuera de servicio',
    inactivo: 'Inactivo',
};

const OUT_OF_SERVICE_TUNNEL = ['mantenimiento', 'fuera_servicio', 'inactivo'];

export const ZONE_LABELS = {
    no_operativo: 'No operativo',
    repa: 'REPA',
    recepcion_mp: 'Recepción MP',
    materiales: 'Materiales',
    packing: 'Packing',
    bodega: 'Bodega',
    pasillo: 'Pasillo',
    patio: 'Patio',
    oficina: 'Oficina',
    muelle: 'Muelle',
    otro: 'Área',
};

export const KIND_LABELS = {
    camara: 'Cámara',
    tunel: 'Túnel',
    anden: 'Andén',
    almacen: 'Bodega',
    zona: 'Área',
    pasillo: 'Pasillo',
};

export function tunnelStateLabel(tunnel) {
    const state = tunnel?.estado_operacional;
    return TUNNEL_STATE_LABELS[state] || String(state || '').replaceAll('_', ' ');
}

export function tunnelStateTone(tunnel) {
    if (!tunnel?.operable || OUT_OF_SERVICE_TUNNEL.includes(tunnel.estado_operacional)) return 'neutral';
    if (tunnel.estado_operacional === 'bloqueado_otra_temporada') return 'critical';
    if (tunnel.proceso_activo?.objetivo_excedido) return 'critical';
    if (tunnel.estado_operacional === 'pendiente_verificacion') return 'warning';
    if (tunnel.estado_operacional === 'en_proceso') return 'success';
    if (tunnel.estado_operacional === 'disponible') return 'neutral';
    return 'info';
}

function roundPercent(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.round(Math.max(0, Math.min(100, parsed))) : 0;
}

function occupancyTone(level) {
    return { critica: 'critical', advertencia: 'warning', normal: 'success' }[level] || 'neutral';
}

export function crewByCamera(operators = []) {
    const crews = new Map();
    for (const operator of operators) {
        const cameraId = operator?.ubicacion_actual?.camara?.id;
        if (!cameraId) continue;
        const crew = crews.get(cameraId) || { personas: [], equipos: [] };
        if (operator.usuario?.nombre && !crew.personas.includes(operator.usuario.nombre)) {
            crew.personas.push(operator.usuario.nombre);
        }
        const device = operator.dispositivo?.codigo || operator.dispositivo?.nombre;
        if (device && !crew.equipos.includes(device)) crew.equipos.push(device);
        crews.set(cameraId, crew);
    }
    return crews;
}

export function buildPlantIndex(snapshot = {}) {
    const catalog = new Map((snapshot.planta?.catalogo || [])
        .map((item) => [`${item.tipo}:${item.id}`, item]));
    return {
        catalog,
        cameras: new Map((snapshot.camaras || []).map((camera) => [camera.id, camera])),
        tunnels: new Map((snapshot.prefrio?.tuneles || []).map((tunnel) => [tunnel.id, tunnel])),
        crews: crewByCamera(snapshot.camareros || []),
        indicators: snapshot.planta?.indicadores || {},
    };
}

function base(element, catalogItem, kind) {
    return {
        id: element.id,
        kind,
        tag: kind === 'zona' ? (ZONE_LABELS[element.categoria] || 'Área') : KIND_LABELS[kind],
        code: catalogItem?.codigo || element.nombre,
        name: catalogItem?.nombre || element.nombre,
        value: null,
        valueLabel: null,
        detail: null,
        tone: 'neutral',
        fill: false,
        meter: null,
        state: 'operativo',
        crew: null,
        alerts: [],
        glyph: null,
    };
}

function cameraModel(element, catalogItem, index) {
    const model = base(element, catalogItem, 'camara');
    if (!catalogItem) return missing(model);
    if (catalogItem.estado === 'fuera_servicio') return outOfService(model, 'Fuera de servicio');

    const camera = index.cameras.get(element.referencia_id);
    const percentValue = roundPercent(camera?.ocupacion_porcentaje);
    const tone = occupancyTone(camera?.nivel_ocupacion);
    model.value = `${percentValue}%`;
    model.valueLabel = 'ocupación';
    model.tone = tone;
    model.fill = tone === 'critical';
    model.meter = { value: percentValue, tone };
    model.detail = camera ? `${camera.ocupadas ?? 0} de ${camera.capacidad_operativa ?? 0} posiciones` : null;
    const crew = index.crews.get(element.referencia_id);
    if (crew) model.crew = { personas: crew.personas, equipos: crew.equipos };
    if (camera?.control_ambiental?.requiere_control) {
        model.alerts.push(camera.control_ambiental.estado === 'vencido'
            ? 'Control ambiental vencido'
            : 'Control ambiental pendiente');
    }
    return model;
}

function tunnelModel(element, catalogItem, index) {
    const model = base(element, catalogItem, 'tunel');
    if (!catalogItem) return missing(model);

    const tunnel = index.tunnels.get(element.referencia_id);
    if (!tunnel) return missing(model);
    if (!tunnel.operable || OUT_OF_SERVICE_TUNNEL.includes(tunnel.estado_operacional)) {
        return outOfService(model, tunnelStateLabel(tunnel));
    }

    const foreign = tunnel.proceso_otra_temporada;
    if (foreign) {
        model.value = 'Bloqueado';
        model.valueLabel = 'sin cerrar';
        model.tone = 'critical';
        model.fill = true;
        model.detail = tunnelStateLabel(tunnel);
        model.alerts.push(`Proceso ${foreign.codigo} de la temporada ${foreign.temporada_codigo || 'anterior'} sin cerrar`);
        return model;
    }

    const percentValue = roundPercent(tunnel.ocupacion_porcentaje);
    const process = tunnel.proceso_activo;
    model.value = `${percentValue}%`;
    model.valueLabel = 'carga';
    model.tone = tunnelStateTone(tunnel);
    model.fill = model.tone === 'critical';
    model.meter = { value: percentValue, tone: model.tone };
    const progress = process?.avance_tiempo_objetivo_porcentaje;
    model.detail = progress !== null && progress !== undefined
        ? `${tunnelStateLabel(tunnel)} · ciclo ${roundPercent(progress)}%`
        : tunnelStateLabel(tunnel);
    if (process?.objetivo_excedido) model.alerts.push('Ciclo sobre el tiempo objetivo');
    return model;
}

function dockModel(element, catalogItem) {
    const model = base(element, catalogItem, 'anden');
    if (!catalogItem) return missing(model);
    model.glyph = catalogItem.ocupado ? 'truck' : 'dock';
    model.tone = catalogItem.ocupado ? 'warning' : 'success';
    model.value = catalogItem.ocupado ? (catalogItem.patente || catalogItem.carga_codigo || 'Ocupado') : 'Libre';
    model.detail = catalogItem.ocupado && catalogItem.carga_codigo ? catalogItem.carga_codigo : null;
    return model;
}

function warehouseModel(element, catalogItem) {
    const model = base(element, catalogItem, 'almacen');
    if (!catalogItem) return missing(model);
    model.glyph = 'racks';
    return model;
}

function repaModel(model, indicator) {
    model.glyph = 'repa';
    if (!indicator) return model;
    const pending = Number(indicator.pallets_pendientes) || 0;
    const maximum = Math.max(1, Number(indicator.maximo) || 1);
    const tone = { urgente: 'critical', alta: 'warning' }[indicator.prioridad] || 'success';
    model.value = `${pending} / ${maximum}`;
    model.valueLabel = 'pallets en buffer';
    model.tone = tone;
    model.meter = { value: roundPercent(pending / maximum * 100), tone };
    return model;
}

function rawMaterialModel(model, indicator) {
    model.glyph = 'bins';
    if (!indicator) return model;
    const waiting = (Number(indicator.pendientes_validacion) || 0) + (Number(indicator.en_validacion) || 0);
    model.value = String(waiting);
    model.valueLabel = waiting === 1 ? 'recepción por validar' : 'recepciones por validar';
    model.detail = `${Number(indicator.en_romana) || 0} en Romana · ${Number(indicator.en_validacion) || 0} en validación`;
    model.tone = waiting > 0 ? 'info' : 'neutral';
    return model;
}

function zoneModel(element, index) {
    const model = base(element, null, 'zona');
    model.code = element.nombre;
    model.name = element.nombre;
    model.zone = element.categoria || 'otro';
    if (model.zone === 'repa') return repaModel(model, index.indicators.repa);
    if (model.zone === 'recepcion_mp') return rawMaterialModel(model, index.indicators.recepcion_mp);
    if (model.zone === 'materiales') model.glyph = 'racks';
    if (model.zone === 'no_operativo') model.state = 'no_operativo';
    return model;
}

function corridorModel(element) {
    const model = base(element, null, 'pasillo');
    model.code = element.nombre;
    model.name = element.nombre;
    model.state = 'circulacion';
    return model;
}

function missing(model) {
    model.state = 'sin_referencia';
    model.tone = 'warning';
    model.detail = 'Referencia no disponible';
    return model;
}

function outOfService(model, label) {
    model.state = 'mantencion';
    model.tone = 'critical';
    model.glyph = 'wrench';
    model.detail = label;
    return model;
}

export function plantNodeModel(element, index) {
    const catalogItem = element.tipo === 'zona' ? null : index.catalog.get(`${element.tipo}:${element.referencia_id}`);
    switch (element.tipo) {
        case 'camara': return cameraModel(element, catalogItem, index);
        case 'tunel': return tunnelModel(element, catalogItem, index);
        case 'anden': return dockModel(element, catalogItem);
        case 'almacen': return warehouseModel(element, catalogItem);
        case 'pasillo': return corridorModel(element);
        default: return zoneModel(element, index);
    }
}

export function plantNodeTitle(model) {
    return model.kind === 'camara' ? cameraDisplayName({ nombre: model.name }) : model.code;
}

export function plantNodeLabel(model) {
    return [
        model.kind === 'camara' ? plantNodeTitle(model) : `${model.tag} ${model.code}`,
        model.kind !== 'camara' && model.name !== model.code ? model.name : null,
        model.value ? `${model.value}${model.valueLabel ? ` ${model.valueLabel}` : ''}` : null,
        model.detail,
        model.crew?.personas?.length ? `${model.crew.personas.length} personas` : null,
        model.crew?.equipos?.length ? `${model.crew.equipos.length} equipos móviles` : null,
        ...model.alerts,
    ].filter(Boolean).join('. ');
}
