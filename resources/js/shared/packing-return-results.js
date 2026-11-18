/** Código de planta y clasificación, conservando nombres específicos del retorno. */
export function packingReturnResultLabel(type, name = null) {
    const title = name || type?.nombre || 'Pendiente';
    return type?.prefijo_sublote ? `${type.prefijo_sublote} · ${title}` : title;
}
