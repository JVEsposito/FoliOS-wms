/**
 * Diagrama de presentación de pallets para inspección SAG/USDA.
 *
 * La numeración es continua entre pallets: la caja 1 es la primera de la corrida inferior
 * del pallet 1, cada corrida se numera de izquierda a derecha y de abajo hacia arriba, y
 * cada pallet parte en el número siguiente a la última caja del anterior. La misma regla
 * vive en App\Services\InspeccionSag\NumeracionDiagramaPallets, que es la que valida al guardar.
 */

export const LIMITES = Object.freeze({ pallets: 80, base: 50, corridas: 50, marcas: 3000 });
export const PALLETS_INICIALES = 16;

const entero = (valor) => {
    if (valor === null || valor === undefined || String(valor).trim() === '') return null;
    const numero = Number(String(valor).trim());
    return Number.isInteger(numero) ? numero : Number.NaN;
};

export function palletVacio() {
    return { folio: '', base: null, corridas: null, ultima: null, declaradas: null };
}

export function palletsIniciales(cantidad = PALLETS_INICIALES) {
    return Array.from({ length: cantidad }, palletVacio);
}

/**
 * Errores de un pallet, en el orden en que conviene corregirlos. Un error de «declaradas»
 * no le quita sus cajas al pallet: la numeración sigue, pero el diagrama no se puede emitir.
 */
export function erroresPallet(pallet) {
    const errores = [];
    const base = entero(pallet.base);
    const corridas = entero(pallet.corridas);
    const ultima = entero(pallet.ultima);
    const declaradas = entero(pallet.declaradas);
    if (base === null || corridas === null) errores.push({ campo: base === null ? 'base' : 'corridas', mensaje: 'Falta la base o las corridas.' });
    if (Number.isNaN(base) || (base !== null && (base < 1 || base > LIMITES.base))) errores.push({ campo: 'base', mensaje: `La base debe ser un entero entre 1 y ${LIMITES.base}.` });
    if (Number.isNaN(corridas) || (corridas !== null && (corridas < 1 || corridas > LIMITES.corridas))) errores.push({ campo: 'corridas', mensaje: `Las corridas deben ser un entero entre 1 y ${LIMITES.corridas}.` });
    if (Number.isNaN(ultima) || (ultima !== null && Number.isInteger(base) && (ultima < 1 || ultima >= base))) {
        errores.push({ campo: 'ultima', mensaje: 'La última corrida incompleta debe tener entre 1 caja y una menos que la base.' });
    }
    if (errores.length === 0 && declaradas !== null) {
        const total = totalPallet(pallet);
        if (Number.isNaN(declaradas) || declaradas !== total) errores.push({ campo: 'declaradas', mensaje: `Las cajas declaradas (${pallet.declaradas}) no coinciden con la configuración (${total}).` });
    }

    return errores;
}

/** Total de cajas del pallet, o null si la configuración no está completa o es inválida. */
export function totalPallet(pallet) {
    const base = entero(pallet.base);
    const corridas = entero(pallet.corridas);
    const ultima = entero(pallet.ultima);
    if (!Number.isInteger(base) || !Number.isInteger(corridas) || base < 1 || corridas < 1) return null;
    if (ultima === null) return base * corridas;
    if (!Number.isInteger(ultima) || ultima < 1 || ultima >= base) return null;

    return base * (corridas - 1) + ultima;
}

/**
 * Rangos de todos los pallets. Un pallet sin configurar no tiene cajas y su rango queda
 * vacío, pero los siguientes siguen la numeración sin saltos.
 */
export function numerar(pallets) {
    let siguiente = 1;
    const rangos = pallets.map((pallet, indice) => {
        const errores = erroresPallet(pallet);
        const total = errores.some((e) => e.campo !== 'declaradas') ? null : totalPallet(pallet);
        const rango = { indice, numero: indice + 1, total: total ?? 0, inicio: null, fin: null, errores, valido: errores.length === 0 };
        if (total) {
            rango.inicio = siguiente;
            rango.fin = siguiente + total - 1;
            siguiente = rango.fin + 1;
        }
        return rango;
    });

    return { rangos, total: siguiente - 1, valido: pallets.length > 0 && rangos.every((r) => r.valido) };
}

/** Comprueba la continuidad: sin saltos, sin repetidos y con el total igual a la suma. */
export function verificarContinuidad(numeracion) {
    let esperado = 1;
    for (const rango of numeracion.rangos) {
        if (!rango.total) continue;
        if (rango.inicio !== esperado || rango.fin - rango.inicio + 1 !== rango.total) return false;
        esperado = rango.fin + 1;
    }

    return esperado - 1 === numeracion.total && numeracion.rangos.reduce((suma, r) => suma + r.total, 0) === numeracion.total;
}

/** Ubicación física de una caja: pallet, corrida (1 = inferior) y posición (1 = izquierda). */
export function ubicarCaja(numero, numeracion, pallets) {
    if (!Number.isInteger(numero) || numero < 1 || numero > numeracion.total) return null;
    const rango = numeracion.rangos.find((r) => r.total && numero >= r.inicio && numero <= r.fin);
    if (!rango) return null;
    const base = entero(pallets[rango.indice].base);
    const desplazamiento = numero - rango.inicio;

    return { pallet: rango.indice, corrida: Math.floor(desplazamiento / base) + 1, posicion: (desplazamiento % base) + 1 };
}

/**
 * Corridas de un pallet de arriba hacia abajo, como se dibujan. La última corrida
 * incompleta queda arriba, con sus cajas desde la izquierda.
 */
export function corridasPallet(pallet, rango) {
    if (!rango.total) return [];
    const base = entero(pallet.base);
    const corridas = [];
    for (let inicio = rango.inicio; inicio <= rango.fin; inicio += base) {
        corridas.push(Array.from({ length: Math.min(base, rango.fin - inicio + 1) }, (_, i) => inicio + i));
    }

    return corridas.reverse();
}

/**
 * Interpreta «5, 82, 145, 206 y 411». Acepta comas, punto y coma, espacios, saltos de línea,
 * la «y» y rangos «10-15» o «10 a 15». Lo que no es un número entero va en no_reconocidos.
 */
export function interpretarNumeros(texto) {
    const numeros = [];
    const noReconocidos = [];
    const normalizado = String(texto ?? '')
        .replace(/(\d)\s*(?:-|–|—|\ba\b)\s*(\d)/giu, '$1-$2')
        .split(/[\s,;]+|\by\b/iu)
        .map((t) => t.trim())
        .filter(Boolean);
    for (const token of normalizado) {
        const rango = token.match(/^(\d{1,6})-(\d{1,6})$/u);
        if (rango) {
            const [desde, hasta] = [Number(rango[1]), Number(rango[2])].sort((a, b) => a - b);
            if (hasta - desde > LIMITES.marcas) { noReconocidos.push(token); continue; }
            for (let n = desde; n <= hasta; n += 1) numeros.push(n);
        } else if (/^\d{1,6}$/u.test(token)) {
            numeros.push(Number(token));
        } else {
            noReconocidos.push(token);
        }
    }

    return { numeros: [...new Set(numeros)], no_reconocidos: noReconocidos };
}

/** Separa los números que existen en la numeración de los que no. */
export function clasificarNumeros(numeros, numeracion) {
    const validos = [];
    const inexistentes = [];
    for (const numero of numeros) (numero >= 1 && numero <= numeracion.total ? validos : inexistentes).push(numero);

    return { validos, inexistentes };
}

export function ordenarMarcas(marcas) {
    return [...new Set(marcas)].filter(Number.isInteger).sort((a, b) => a - b);
}

/** Cajas marcadas de cada pallet, con su corrida y posición. */
export function marcasPorPallet(marcas, numeracion, pallets) {
    const porPallet = numeracion.rangos.map(() => []);
    const fuera = [];
    for (const numero of ordenarMarcas(marcas)) {
        const ubicacion = ubicarCaja(numero, numeracion, pallets);
        if (ubicacion) porPallet[ubicacion.pallet].push({ numero, corrida: ubicacion.corrida, posicion: ubicacion.posicion });
        else fuera.push(numero);
    }

    return { porPallet, fuera };
}

/** Firma de la estructura: si cambia, las marcas pueden haber cambiado de posición. */
export function firmaEstructura(pallets) {
    return pallets.map((p) => [entero(p.base), entero(p.corridas), entero(p.ultima)].join('x')).join('|');
}

/** «Temporada 2026-2027» se deja tal cual; un código como «TEMP-2627» se antecede con la palabra. */
export function etiquetaTemporada(nombre) {
    const texto = String(nombre ?? '').trim();
    if (!texto) return '';

    return /^temporada/iu.test(texto) ? texto : `Temporada ${texto}`;
}

export function tituloDiagrama(datos) {
    const tipo = datos.tipo === 'sag' ? 'SAG' : 'USDA';
    const numero = String(datos.numero ?? '').trim();

    return `DIAGRAMA ${tipo}${numero ? ` N.º ${numero}` : ''}`;
}

/** Datos que se guardan o se envían para el PDF, con los tipos que espera la API. */
export function cargaDiagrama(datos, pallets, marcas) {
    const texto = (valor) => String(valor ?? '').trim() || null;
    const numeroONull = (valor) => {
        const numero = entero(valor);
        return Number.isInteger(numero) ? numero : null;
    };

    return {
        tipo: datos.tipo === 'sag' ? 'sag' : 'usda',
        numero: texto(datos.numero),
        csp: texto(datos.csp),
        folio: texto(datos.folio),
        fecha: texto(datos.fecha),
        lote: texto(datos.lote),
        especie: texto(datos.especie),
        exportadora: texto(datos.exportadora),
        pallets: pallets.map((p) => ({ folio: texto(p.folio), base: numeroONull(p.base), corridas: numeroONull(p.corridas), ultima: numeroONull(p.ultima), declaradas: numeroONull(p.declaradas) })),
        cajas_marcadas: ordenarMarcas(marcas),
    };
}

/**
 * Distribuye los pallets en filas de un ancho dado; dentro de una fila los pallets se
 * apoyan en la misma línea de piso para que se vean las diferencias de altura.
 */
export function distribuir(pallets, numeracion, { ancho, celda, separacion, rotulo, minimo = 120 }) {
    const filas = [];
    let fila = null;
    numeracion.rangos.forEach((rango, indice) => {
        if (!rango.total) return;
        const base = entero(pallets[indice].base);
        const anchoPallet = Math.max(base * celda, minimo);
        const altoPallet = Math.ceil(rango.total / base) * celda + rotulo;
        if (!fila || (fila.ancho + separacion + anchoPallet > ancho && fila.pallets.length)) {
            fila = { pallets: [], ancho: -separacion, alto: 0 };
            filas.push(fila);
        }
        fila.pallets.push({ indice, x: fila.ancho + separacion, ancho: anchoPallet, alto: altoPallet });
        fila.ancho += separacion + anchoPallet;
        fila.alto = Math.max(fila.alto, altoPallet);
    });

    return filas;
}

const xml = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/**
 * Lámina completa en SVG para exportar a PNG. Usa fuentes del sistema para que el
 * rasterizado no dependa de fuentes web.
 */
export function laminaSvg(datos, pallets, marcas, { temporada = '', mostrarNumeros = true, ancho = 2200 } = {}) {
    const numeracion = numerar(pallets);
    const celda = 30;
    const separacion = 36;
    const rotulo = 66;
    const margen = 40;
    const encabezado = 150;
    // El rótulo de un pallet angosto no debe pisar al siguiente.
    const filas = distribuir(pallets, numeracion, { ancho: ancho - margen * 2, celda, separacion, rotulo, minimo: 220 });
    const marcadas = new Set(ordenarMarcas(marcas));
    const { porPallet } = marcasPorPallet(marcas, numeracion, pallets);
    const altoFilas = filas.reduce((suma, fila) => suma + fila.alto + separacion, 0);
    const alto = margen + encabezado + altoFilas + 70;
    const fuente = 'Helvetica, Arial, sans-serif';
    const partes = [`<svg xmlns="http://www.w3.org/2000/svg" width="${ancho}" height="${alto}" viewBox="0 0 ${ancho} ${alto}" font-family="${fuente}">`,
        `<rect width="100%" height="100%" fill="#ffffff"/>`,
        `<text x="${margen}" y="${margen + 30}" font-size="32" font-weight="700" fill="#102f43">${xml(tituloDiagrama(datos))}</text>`,
        `<text x="${margen}" y="${margen + 60}" font-size="17" fill="#5c6873">${xml(['Presentación de pallets para inspección', datos.csp ? `CSP ${String(datos.csp).trim()}` : '', etiquetaTemporada(temporada)].filter(Boolean).join(' · '))}</text>`];
    const fichas = [['Folio', datos.folio], ['Fecha', datos.fecha ? datos.fecha.split('-').reverse().join('-') : ''], ['Lote', datos.lote], ['Especie', datos.especie], ['Exportadora', datos.exportadora],
        ['Pallets / cajas', `${numeracion.rangos.filter((r) => r.total).length} / ${numeracion.total}`], ['Cajas marcadas', String(marcadas.size)]];
    const anchoFicha = (ancho - margen * 2) / fichas.length;
    fichas.forEach(([etiqueta, valor], i) => {
        const x = margen + i * anchoFicha;
        partes.push(`<rect x="${x}" y="${margen + 78}" width="${anchoFicha - 8}" height="52" fill="#f1f4f6" stroke="#c7d1d9"/>`,
            `<text x="${x + 10}" y="${margen + 96}" font-size="12" font-weight="700" fill="#5c6873">${xml(etiqueta.toUpperCase())}</text>`,
            `<text x="${x + 10}" y="${margen + 119}" font-size="17" fill="#13202a">${xml(valor || '—')}</text>`);
    });
    let y = margen + encabezado;
    for (const fila of filas) {
        const piso = y + fila.alto;
        for (const ubicado of fila.pallets) {
            const pallet = pallets[ubicado.indice];
            const rango = numeracion.rangos[ubicado.indice];
            const corridas = corridasPallet(pallet, rango);
            const base = entero(pallet.base);
            const x0 = margen + ubicado.x;
            const top = piso - corridas.length * celda;
            const titulo = `PALLET ${rango.numero}${pallet.folio ? ` · ${pallet.folio}` : ''}`;
            const marcadasPallet = porPallet[ubicado.indice].length;
            partes.push(`<text x="${x0}" y="${top - 44}" font-size="15" font-weight="700" fill="#102f43">${xml(titulo)}</text>`,
                `<text x="${x0}" y="${top - 27}" font-size="12" fill="#5c6873">${xml(`Base ${base} × ${corridas.length} corridas · ${rango.total} cajas`)}</text>`,
                `<text x="${x0}" y="${top - 11}" font-size="12" fill="#5c6873"${marcadasPallet ? ' font-weight="700"' : ''}>${xml(`Cajas ${rango.inicio}–${rango.fin}${marcadasPallet ? ` · ${marcadasPallet} marcadas` : ''}`)}</text>`);
            corridas.forEach((corrida, filaIndice) => {
                corrida.forEach((numero, columna) => {
                    const cx = x0 + columna * celda;
                    const cy = top + filaIndice * celda;
                    const marcada = marcadas.has(numero);
                    partes.push(`<rect x="${cx}" y="${cy}" width="${celda}" height="${celda}" fill="${marcada ? '#ffd21f' : '#ffffff'}" stroke="${marcada ? '#7a5a00' : '#6b7a86'}" stroke-width="${marcada ? 2 : 1}"/>`);
                    if (mostrarNumeros) partes.push(`<text x="${cx + celda / 2}" y="${cy + celda / 2 + 4.5}" font-size="${numero > 999 ? 11 : 12.5}" text-anchor="middle" font-weight="${marcada ? 700 : 400}" fill="#13202a">${numero}</text>`);
                });
            });
            partes.push(`<line x1="${x0 - 4}" y1="${piso + 3}" x2="${x0 + base * celda + 4}" y2="${piso + 3}" stroke="#102f43" stroke-width="4"/>`);
        }
        y = piso + separacion;
    }
    partes.push(`<text x="${margen}" y="${alto - 30}" font-size="13" fill="#5c6873">Diagrama de presentación interno generado por FoliOS. No constituye aprobación del SAG ni del USDA.</text>`, '</svg>');

    return { svg: partes.join(''), ancho, alto };
}
