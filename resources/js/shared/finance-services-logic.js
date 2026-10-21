import { formatDay, validityText } from './finance-tariffs-logic.js';

export function serviceRules(service) {
    if (!service) return '';
    const application = service.aplicacion === 'declarado' ? 'Cargo por acuerdo o declaración' : service.base ? 'Concepto base del proceso'
        : (service.aplicacion === 'reemplaza_base' ? 'Reemplaza al concepto base' : 'Cobro adicional al concepto base');
    const days = service.por_dia
        ? ` · días ${service.dia_desde || 1} a ${service.dia_hasta || 'sin límite'} de esta estadía del folio`
        : '';
    return `${application}${days}`;
}

export function serviceValidity(service) {
    return service.base ? 'Sin límite de vigencia' : validityText(service.vigente_desde, service.vigente_hasta);
}

export function servicesForPrice(services, today, editingKey = null) {
    return (services || []).filter((service) => service.clave === editingKey
        || !service.vigente_hasta || service.vigente_hasta >= today);
}

export function serviceEventText(event) {
    if (event.accion === 'finalizado') return `Término: ${event.datos?.antes ? formatDay(event.datos.antes) : 'sin término'} → ${formatDay(event.datos?.despues)}`;
    return `${event.datos?.nombre || 'Concepto creado'} · ${serviceRules(event.datos)} · ${serviceValidity(event.datos)}`;
}
