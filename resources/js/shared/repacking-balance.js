export function weightUnits(value) {
    const text = String(value ?? '').trim();
    if (!/^\d+(?:\.\d{1,4})?$/.test(text)) return null;
    const [whole, fraction = ''] = text.split('.');
    const result = Number(whole) * 10000 + Number(fraction.padEnd(4, '0'));
    return Number.isSafeInteger(result) ? result : null;
}
export function repackingBalance(context, results, losses) {
    const rows = context.componentes.map((line) => {
        const input = weightUnits(line.kilos_entrada);
        let output = 0; let valid = input !== null;
        for (const result of results) {
            const format = context.formatos.find((f) => f.id === result.envase_validacion_id);
            const unit = format ? weightUnits(format.kilos_netos_por_caja) : null;
            const count = Number(result.cantidades[line.clave] || 0);
            valid = valid && unit !== null && Number.isInteger(count) && count >= 0 && count <= 100000;
            output += (unit || 0) * count;
        }
        const loss = weightUnits(losses[line.clave] || '0');
        valid = valid && loss !== null && Number.isSafeInteger(output);
        return { clave: line.clave, input: input || 0, output, loss: loss || 0, difference: (input || 0) - output - (loss || 0), valid };
    });
    return { rows, input: rows.reduce((n, r) => n + r.input, 0), output: rows.reduce((n, r) => n + r.output, 0),
        loss: rows.reduce((n, r) => n + r.loss, 0), balanced: rows.length > 0 && results.length > 0 && rows.every((r) => r.valid && r.difference === 0) };
}
