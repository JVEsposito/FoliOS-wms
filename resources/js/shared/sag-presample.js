/**
 * Presample USDA/SAG leído por el servidor (Claude + Tesseract).
 *
 * El 15 del «Nro. de Lote» es a la vez el N.º del presample y el lote: va al N.º del
 * diagrama y al Lote. El rango «LOTE DE … A …» va al Folio. Solo se completan campos
 * vacíos; si ya tienen otro valor, se avisa y no se cambian. Con problemas (cantidad,
 * orden, partida o pallets que no cuadran) no se aplica nada.
 */

/** «12358039 a 12358058», o «A a B y C a D» si el presample trae dos rangos. */
export function folioDeRangos(rangos = []) {
    return rangos.map(({ desde, hasta }) => (desde === hasta ? desde : `${desde} a ${hasta}`)).join(' y ');
}

/**
 * @param {{numero: string, lote: string, folio: string}} campos  valores actuales del diagrama
 * @param {object} lectura  respuesta de POST /api/sag/diagramas/leer-presample
 * @returns {{campos: {numero: string, lote: string, folio: string}, marcas: number[], avisos: string[]}}
 */
export function aplicarPresample(campos, lectura) {
    const actuales = { numero: campos.numero ?? '', lote: campos.lote ?? '', folio: campos.folio ?? '' };
    if (lectura.problemas?.length) return { campos: actuales, marcas: [], avisos: [] };

    const propuestos = { numero: lectura.lote ?? '', lote: lectura.lote ?? '', folio: folioDeRangos(lectura.rangos) };
    const nombres = { numero: 'El N.º del diagrama', lote: 'El lote', folio: 'El folio' };
    const resultado = { ...actuales };
    const avisos = [];
    for (const campo of Object.keys(propuestos)) {
        const nuevo = String(propuestos[campo]).trim();
        const actual = String(actuales[campo]).trim();
        if (!nuevo || nuevo === actual) continue;
        if (actual) avisos.push(`${nombres[campo]} es ${actual} y el presample dice ${nuevo}: no se cambió.`);
        else resultado[campo] = nuevo;
    }

    return { campos: resultado, marcas: (lectura.cajas ?? []).map((c) => c.numero), avisos };
}
