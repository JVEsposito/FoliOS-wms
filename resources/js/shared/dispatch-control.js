/**
 * Presentación de la planilla R-24 (control de despacho) para oficina.
 * Lógica pura: se prueba sin navegador.
 */

/** Puntos de la inspección en que «sí» es un hallazgo, no una conformidad. */
export const INSPECCION_NEGATIVA = ['danos_visuales', 'objetos_olores'];

export const GRUPOS_ENCABEZADO = [
    ['operacion', 'Operación'],
    ['destino', 'Destino y embarque'],
    ['transporte', 'Transporte'],
    ['unidad', 'Contenedor o thermo'],
    ['termografos', 'Termógrafos'],
];

const OPCIONES = {
    agrorosario: 'Agrorosario', otra: 'Otra', contenedor: 'Contenedor', thermo: 'Thermo',
    si: 'Sí', no: 'No', na: 'No aplica', cumple: 'Cumple', no_cumple: 'No cumple',
};

export function textoValor(valor) {
    if (valor === null || valor === undefined || valor === '') return '—';
    if (typeof valor === 'string' && OPCIONES[valor]) return OPCIONES[valor];
    if (typeof valor === 'number') return new Intl.NumberFormat('es-CL').format(valor);
    if (typeof valor === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(valor)) return valor.split('-').reverse().join('-');
    return String(valor);
}

/** Tono de una respuesta de la inspección: «ok», «alerta», «neutro» o «pendiente». */
export function tonoInspeccion(clave, valor) {
    if (!valor) return 'pendiente';
    if (valor === 'na') return 'neutro';
    const conforme = INSPECCION_NEGATIVA.includes(clave) ? valor === 'no' : valor === 'si';
    return conforme ? 'ok' : 'alerta';
}

/** Encabezado agrupado como la planilla, solo con los campos que tienen valor. */
export function gruposEncabezado(campos, encabezado) {
    return GRUPOS_ENCABEZADO
        .map(([clave, titulo]) => ({
            clave,
            titulo,
            filas: campos
                .filter((campo) => campo.grupo === clave && encabezado?.[campo.clave] !== null && encabezado?.[campo.clave] !== undefined && encabezado?.[campo.clave] !== '')
                .map((campo) => ({ clave: campo.clave, etiqueta: campo.etiqueta, valor: textoValor(encabezado[campo.clave]) })),
        }))
        .filter((grupo) => grupo.filas.length > 0);
}

/** Pallets ordenados por posición; los que aún no tienen posición quedan al final. */
export function palletsOrdenados(pallets = []) {
    return [...pallets].sort((a, b) => {
        const pa = a.posicion?.posicion ?? Number.POSITIVE_INFINITY;
        const pb = b.posicion?.posicion ?? Number.POSITIVE_INFINITY;
        return pa === pb ? String(a.numero_folio).localeCompare(String(b.numero_folio)) : pa - pb;
    });
}

export function temperatura(valor) {
    return valor === null || valor === undefined ? '—' : `${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(valor)} °C`;
}

export const ESTADOS_PALLET = {
    pendiente: 'En cámara', con_incidencia: 'Con incidencia', en_anden: 'En andén', cargado: 'Cargado', despachado: 'Despachado',
    descartado: 'Descartado', reemplazado: 'Reemplazado', retornado: 'Retornado',
};
