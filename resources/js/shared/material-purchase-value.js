export function purchasePrice(value) {
    const text = String(value ?? '').trim().replace(',', '.');
    if (text === '') return null;
    if (!/^\d{1,9}(?:\.\d{1,6})?$/.test(text)) throw new Error('Usa un precio no negativo, hasta 9 enteros y 6 decimales, sin separador de miles.');
    const [whole, fraction = ''] = text.split('.');
    return `${BigInt(whole)}.${fraction.padEnd(6, '0')}`;
}

export function purchaseTotal(quantity, price, currency) {
    if (price === null) return null;
    const decimals = { CLP: 0, USD: 2, EUR: 2 }[currency];
    if (decimals === undefined) throw new Error('Moneda no admitida.');
    const [whole, fraction = ''] = String(quantity).split('.');
    if (!/^\d+$/.test(whole) || !/^\d{0,3}$/.test(fraction)) throw new Error('Cantidad aceptada inválida.');
    const q = BigInt(whole + fraction.padEnd(3, '0'));
    const p = BigInt(purchasePrice(price).replace('.', ''));
    const divisor = 10n ** BigInt(9 - decimals);
    const rounded = (q * p + divisor / 2n) / divisor;
    const text = rounded.toString().padStart(decimals + 1, '0');
    return decimals ? `${text.slice(0, -decimals)}.${text.slice(-decimals)}` : text;
}

export function purchaseAmount(value) {
    if (value === null || value === undefined) return 'Pendiente';
    const [whole, fraction = ''] = String(value).split('.');
    const part = fraction.replace(/0+$/, '');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (part ? `,${part}` : '');
}
