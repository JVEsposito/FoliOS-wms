// Estados PT del panel gerencial. Cada estado separa pallets (bultos completos) de saldos
// (bultos parciales que esperan consolidarse), para no sumarlos como si fueran lo mismo.
export const PRODUCT_STATES = [
    { key: 'disponibles_despacho', label: 'Disponibles para despacho', tone: 'ok' },
    { key: 'comprometidos_carga', label: 'Comprometidos en carga', tone: 'info' },
    { key: 'pendientes_prefrio', label: 'Pendientes de prefrío', tone: 'watch' },
    { key: 'pendientes_ubicacion', label: 'Sin ubicación', tone: 'attention' },
    { key: 'bloqueados', label: 'Bloqueados', tone: 'alert' },
    { key: 'otros', label: 'Otros', tone: 'other', hint: 'Disponibles fuera de una cámara PT activa' },
];

const MINIMUM_VISIBLE_WIDTH = 1.5;

const count = (value) => Math.max(0, Number(value) || 0);

function visibleWidth(value, max) {
    if (!value || !max) return 0;
    return Math.max(MINIMUM_VISIBLE_WIDTH, (value / max) * 100);
}

function stateValues(products, key) {
    const detail = (products.estados || []).find((state) => state.clave === key);
    if (detail) return { pallets: count(detail.pallets), saldos: count(detail.saldos), cajas: count(detail.cajas) };

    // Respuesta anterior sin desglose: el conteo se muestra completo, sin inventar la división.
    return { pallets: count(products[key]), saldos: 0, cajas: 0 };
}

export function productStateRows(products = {}) {
    const rows = PRODUCT_STATES.map((state) => {
        const values = stateValues(products, state.key);
        return { ...state, ...values, total: values.pallets + values.saldos };
    });
    const max = Math.max(0, ...rows.map((row) => row.total));
    const total = count(products.total_activos);

    return {
        empty: total === 0,
        rows: rows.map((row) => ({
            ...row,
            palletWidth: visibleWidth(row.pallets, max),
            saldoWidth: visibleWidth(row.saldos, max),
            percent: total ? Math.round((row.total / total) * 1000) / 10 : 0,
        })),
        totals: {
            pallets: rows.reduce((sum, row) => sum + row.pallets, 0),
            saldos: rows.reduce((sum, row) => sum + row.saldos, 0),
            cajas: rows.reduce((sum, row) => sum + row.cajas, 0),
        },
    };
}

// Barra apilada pallets + saldos de un tramo o grupo, a escala del mayor de la lista.
export function bulkWidths(pallets, saldos, max) {
    return { pallet: visibleWidth(count(pallets), max), saldo: visibleWidth(count(saldos), max) };
}
