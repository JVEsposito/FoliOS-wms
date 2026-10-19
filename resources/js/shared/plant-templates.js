// Planos base: la geometría real de una planta para no dibujarla a mano en el editor.
// Las coordenadas van de 0 a 10000 en cada eje y la proporción (ancho/alto) conserva la
// forma del dibujo. Cada recinto declara tipo y número; al cargarlo se enlaza con el
// recinto del catálogo que tenga ese número (CAM-07, «Cámara 7», T7…). Lo que no calce
// queda marcado para asignarlo a mano antes de guardar.

const RECINTO_TYPES = ['camara', 'tunel', 'anden', 'almacen'];

/**
 * Plano de instalaciones Agrorosario 2026-2027 (PLANOS_Y_FLUJOS_EMPRESA_26-27), norte
 * arriba. Las conexiones son las puertas del dibujo y las aberturas sin muro. Los túneles
 * se reparten con el mismo ancho y los andenes tienen el alto mínimo editable; los equipos
 * «hydro», el contenedor y los objetos sueltos del dibujo no se trasladan.
 */
export const AGROROSARIO_PLANT = Object.freeze({
    clave: 'agrorosario-2026-27',
    nombre: 'Planta Agrorosario',
    fuente: 'Plano de instalaciones 2026-2027',
    proporcion: 0.8,
    elementos: [
        { clave: 'romana', tipo: 'zona', categoria: 'otro', nombre: 'Romana', x: 4428, y: 105, ancho: 889, alto: 351 },
        { clave: 'porteria', tipo: 'zona', categoria: 'oficina', nombre: 'Portería', x: 9168, y: 3531, ancho: 782, alto: 366 },
        { clave: 'camara-7', tipo: 'camara', numero: 7, nombre: 'Cámara 7', x: 3018, y: 1085, ancho: 2178, alto: 938 },
        { clave: 'antecamara', tipo: 'pasillo', nombre: 'Antecámara norte', x: 5196, y: 1085, ancho: 762, alto: 938 },
        { clave: 'anden-4', tipo: 'anden', numero: 4, nombre: 'Andén 4', x: 5958, y: 1362, ancho: 1032, alto: 330 },
        { clave: 'anden-3', tipo: 'anden', numero: 3, nombre: 'Andén 3', x: 5958, y: 1692, ancho: 1032, alto: 329 },
        { clave: 'camara-6', tipo: 'camara', numero: 6, nombre: 'Cámara 6', x: 3030, y: 2023, ancho: 1105, alto: 879 },
        { clave: 'camara-5', tipo: 'camara', numero: 5, nombre: 'Cámara 5', x: 4135, y: 2023, ancho: 1289, alto: 879 },
        { clave: 'pasillo-and', tipo: 'pasillo', nombre: 'Pasillo andenes', x: 5424, y: 2023, ancho: 534, alto: 2253 },
        { clave: 'camara-4', tipo: 'camara', numero: 4, nombre: 'Cámara 4', x: 5958, y: 2023, ancho: 1733, alto: 879 },
        { clave: 'sag', tipo: 'zona', categoria: 'otro', nombre: 'SAG', x: 5958, y: 2902, ancho: 630, alto: 353 },
        { clave: 'frio', tipo: 'zona', categoria: 'otro', nombre: 'Sala de frío', x: 6588, y: 2902, ancho: 652, alto: 353 },
        { clave: 'anden-2', tipo: 'anden', numero: 2, nombre: 'Andén 2', x: 5958, y: 3255, ancho: 1032, alto: 308 },
        { clave: 'anden-1', tipo: 'anden', numero: 1, nombre: 'Andén 1', x: 5958, y: 3563, ancho: 1032, alto: 307 },
        { clave: 'sadema', tipo: 'zona', categoria: 'otro', nombre: 'Sadema', x: 4861, y: 3206, ancho: 563, alto: 656 },
        { clave: 'acceso-norte', tipo: 'pasillo', nombre: 'Acceso norte', x: 2315, y: 3279, ancho: 715, alto: 483 },
        { clave: 'fumigacion', tipo: 'zona', categoria: 'otro', nombre: 'Cámara de fumigación', x: 3030, y: 3453, ancho: 683, alto: 306 },
        { clave: 'recepcion', tipo: 'zona', categoria: 'otro', nombre: 'Recepción hidrocooler', x: 3038, y: 3862, ancho: 544, alto: 965 },
        { clave: 'tunel-6', tipo: 'tunel', numero: 6, nombre: 'Túnel 6', x: 3582, y: 3862, ancho: 306, alto: 965 },
        { clave: 'tunel-5', tipo: 'tunel', numero: 5, nombre: 'Túnel 5', x: 3888, y: 3862, ancho: 305, alto: 965 },
        { clave: 'tunel-4', tipo: 'tunel', numero: 4, nombre: 'Túnel 4', x: 4193, y: 3862, ancho: 306, alto: 965 },
        { clave: 'tunel-3', tipo: 'tunel', numero: 3, nombre: 'Túnel 3', x: 4499, y: 3862, ancho: 306, alto: 965 },
        { clave: 'tunel-2', tipo: 'tunel', numero: 2, nombre: 'Túnel 2', x: 4805, y: 3862, ancho: 305, alto: 965 },
        { clave: 'tunel-1', tipo: 'tunel', numero: 1, nombre: 'Túnel 1', x: 5110, y: 3862, ancho: 306, alto: 965 },
        { clave: 'pasillo-tun', tipo: 'pasillo', nombre: 'Pasillo túneles', x: 3030, y: 4827, ancho: 2386, alto: 290 },
        { clave: 'pasillo-pal', tipo: 'pasillo', nombre: 'Pasillo palletizaje', x: 5416, y: 4276, ancho: 552, alto: 638 },
        { clave: 'camara-3', tipo: 'camara', numero: 3, nombre: 'Cámara 3', x: 5968, y: 3870, ancho: 1767, alto: 1044 },
        { clave: 'palletizaje', tipo: 'zona', categoria: 'packing', nombre: 'Palletizaje', x: 5416, y: 4914, ancho: 2307, alto: 1122 },
        { clave: 'resguardo', tipo: 'zona', categoria: 'recepcion_mp', nombre: 'Zona resguardo', x: 115, y: 3762, ancho: 2915, alto: 1355 },
        { clave: 'acceso-c0', tipo: 'pasillo', nombre: 'Acceso cámara 0', x: 1010, y: 5117, ancho: 1463, alto: 231 },
        { clave: 'camara-0', tipo: 'camara', numero: 0, nombre: 'Cámara 0', x: 1010, y: 5348, ancho: 1344, alto: 624 },
        { clave: 'camara-2', tipo: 'camara', numero: 2, nombre: 'Cámara 2', x: 2473, y: 5117, ancho: 1729, alto: 855 },
        { clave: 'camara-1', tipo: 'camara', numero: 1, nombre: 'Cámara 1', x: 4202, y: 5117, ancho: 1214, alto: 855 },
        { clave: 'packing', tipo: 'zona', categoria: 'packing', nombre: 'Packing', x: 1004, y: 5972, ancho: 3606, alto: 2608 },
        { clave: 'paso-altillo', tipo: 'pasillo', nombre: 'Paso altillo', x: 4610, y: 6178, ancho: 758, alto: 1093 },
        { clave: 'inspeccion', tipo: 'zona', categoria: 'otro', nombre: 'Inspección SAG', x: 5368, y: 6036, ancho: 2236, alto: 1235 },
        { clave: 'altillo', tipo: 'zona', categoria: 'otro', nombre: 'Altillo', x: 4610, y: 7271, ancho: 1903, alto: 1309 },
        { clave: 'casetas-sag', tipo: 'zona', categoria: 'otro', nombre: 'Casetas SAG', x: 6513, y: 7271, ancho: 1091, alto: 1309 },
        { clave: 'oficinas', tipo: 'zona', categoria: 'oficina', nombre: 'Oficinas', x: 7604, y: 6036, ancho: 786, alto: 1251 },
        { clave: 'pasillo-mat', tipo: 'pasillo', nombre: 'Pasillo materiales', x: 818, y: 8580, ancho: 3297, alto: 263 },
        { clave: 'materiales', tipo: 'zona', categoria: 'materiales', nombre: 'Materiales', x: 818, y: 8843, ancho: 2612, alto: 1144 },
        { clave: 'bodega', tipo: 'zona', categoria: 'bodega', nombre: 'Bodega', x: 3430, y: 8843, ancho: 685, alto: 529 },
        { clave: 'bodega-quim', tipo: 'zona', categoria: 'bodega', nombre: 'Bodega químicos', x: 3430, y: 9372, ancho: 685, alto: 615 },
        { clave: 'pasillo-sur', tipo: 'pasillo', nombre: 'Pasillo sur', x: 4115, y: 8580, ancho: 1758, alto: 870 },
    ],
    conexiones: [
        ['camara-7', 'antecamara'], ['anden-4', 'antecamara'], ['anden-3', 'antecamara'], ['antecamara', 'pasillo-and'],
        ['camara-6', 'camara-5'], ['camara-5', 'pasillo-and'], ['camara-4', 'pasillo-and'], ['sag', 'pasillo-and'],
        ['sag', 'anden-2'], ['frio', 'anden-2'], ['anden-2', 'pasillo-and'], ['anden-1', 'pasillo-and'],
        ['sadema', 'pasillo-and'], ['pasillo-and', 'pasillo-pal'], ['pasillo-pal', 'camara-3'], ['pasillo-pal', 'palletizaje'],
        ['camara-3', 'palletizaje'], ['pasillo-tun', 'palletizaje'], ['tunel-1', 'pasillo-tun'], ['tunel-2', 'pasillo-tun'],
        ['tunel-3', 'pasillo-tun'], ['tunel-4', 'pasillo-tun'], ['tunel-5', 'pasillo-tun'], ['tunel-6', 'pasillo-tun'],
        ['recepcion', 'pasillo-tun'], ['camara-2', 'pasillo-tun'], ['camara-1', 'pasillo-tun'], ['camara-2', 'camara-1'],
        ['resguardo', 'pasillo-tun'], ['resguardo', 'acceso-norte'], ['acceso-norte', 'fumigacion'], ['resguardo', 'acceso-c0'],
        ['acceso-c0', 'camara-0'], ['camara-0', 'packing'], ['packing', 'pasillo-mat'], ['pasillo-mat', 'materiales'],
        ['materiales', 'bodega'], ['bodega', 'pasillo-sur'], ['pasillo-sur', 'altillo'], ['altillo', 'paso-altillo'],
        ['inspeccion', 'casetas-sag'], ['palletizaje', 'inspeccion'], ['inspeccion', 'oficinas'],
    ],
});

export const PLANT_TEMPLATES = Object.freeze([AGROROSARIO_PLANT]);

/** Número final del código o del nombre: «CAM-07», «Cámara 7» y «T7» dan 7. */
export function recintoNumber(item) {
    for (const text of [item?.codigo, item?.nombre]) {
        const match = String(text ?? '').trim().match(/(\d+)$/);
        if (match) return Number(match[1]);
    }
    return null;
}

function candidatesFor(catalog, element, used) {
    const own = catalog.filter((item) => item.tipo === element.tipo
        && !used.has(`${item.tipo}:${item.id}`)
        && recintoNumber(item) === element.numero);
    // Una cámara de materiales con el mismo número no reemplaza a la de producto.
    if (element.tipo !== 'camara') return own;
    const product = own.filter((item) => item.contenido !== 'materiales');
    return product.length ? product : own;
}

/**
 * Elementos y conexiones listos para el editor a partir de un plano base. Devuelve también
 * qué recintos quedaron enlazados, cuáles no tienen un único recinto con su número y qué
 * recintos del catálogo no aparecen en el dibujo.
 */
export function buildPlantFromTemplate(template, catalog = [], { uuid = () => globalThis.crypto.randomUUID() } = {}) {
    const used = new Set();
    const ids = new Map();
    const assigned = [];
    const missing = [];

    const elementos = template.elementos.map((element) => {
        const id = uuid();
        ids.set(element.clave, id);
        let referencia = null;
        let nombre = element.nombre;

        if (RECINTO_TYPES.includes(element.tipo)) {
            const candidates = candidatesFor(catalog, element, used);
            if (candidates.length === 1) {
                const [item] = candidates;
                used.add(`${item.tipo}:${item.id}`);
                referencia = item.id;
                nombre = item.nombre || item.codigo || element.nombre;
                assigned.push({ plano: element.nombre, recinto: item.codigo || item.nombre });
            } else {
                missing.push({
                    nombre: element.nombre,
                    motivo: candidates.length ? 'Hay más de un recinto con ese número' : 'Sin recinto con ese número',
                });
            }
        }

        return {
            id,
            tipo: element.tipo,
            referencia_id: referencia,
            nombre,
            categoria: element.tipo === 'zona' ? element.categoria : null,
            x: element.x,
            y: element.y,
            ancho: element.ancho,
            alto: element.alto,
            rotacion: 0,
        };
    });

    const conexiones = template.conexiones.map(([from, to]) => ({ id: uuid(), desde: ids.get(from), hacia: ids.get(to) }));
    const unplaced = catalog
        .filter((item) => RECINTO_TYPES.includes(item.tipo) && !used.has(`${item.tipo}:${item.id}`))
        .filter((item) => !(item.tipo === 'camara' && item.contenido === 'materiales'));

    return { proporcion: template.proporcion, elementos, conexiones, asignados: assigned, faltantes: missing, sinUbicar: unplaced };
}

/** Recintos del borrador que todavía no apuntan a uno del catálogo. */
export function unassignedPlaces(elements = []) {
    return elements.filter((element) => RECINTO_TYPES.includes(element.tipo) && !element.referencia_id);
}
