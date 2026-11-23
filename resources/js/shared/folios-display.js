export function officeCode(value, escape, digits = 4) {
    const code = String(value ?? '—');
    if (code.length <= digits || !/^\d+$/.test(code.slice(-digits))) return escape(code);
    return `<span class="folios-code__prefix">${escape(code.slice(0, -digits))}</span><strong>${escape(code.slice(-digits))}</strong>`;
}

export function officePlate(value, escape) {
    return value ? `<span class="folios-patente">${escape(value)}</span>` : '—';
}

const stateGlyphs = { listo: '●', 'en-curso': '◐', pendiente: '○', atencion: '◆', bloqueado: '■' };

export function officeStatus(label, state, escape) {
    const variant = Object.hasOwn(stateGlyphs, state) ? state : 'pendiente';
    return `<span class="folios-estado" data-estado="${variant}"><span aria-hidden="true">${stateGlyphs[variant]}</span>${escape(label)}</span>`;
}

export function palletCells(total, filled, atDock = 0, remaining = 'pending') {
    const count = (value) => {
        const parsed = Math.trunc(Number(value));
        return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0;
    };
    const size = count(total);
    const done = Math.min(size, count(filled));
    const waiting = Math.min(size - done, count(atDock));
    const empty = remaining === 'free' ? 'free' : 'pending';
    const rest = size - done - waiting;
    const cell = (state) => `<i data-cell="${state}"></i>`;

    if (size > 120) {
        const segments = [['done', done], ['dock', waiting], [empty, rest]]
            .filter(([, count]) => count > 0)
            .map(([state, count]) => `<i data-cell="${state}" style="flex: ${count} 1 0"></i>`).join('');
        const summary = [`${done.toLocaleString('es-CL')} de ${size.toLocaleString('es-CL')}`];
        if (waiting) summary.push(`${waiting.toLocaleString('es-CL')} en espera`);
        return `<span class="folios-celdas-resumen"><span class="folios-pallet-cells folios-celdas-resumen__barra" aria-hidden="true">${segments}</span><small>${summary.join(' · ')}</small></span>`;
    }

    return `<span class="folios-pallet-cells" aria-hidden="true">${cell('done').repeat(done)}${cell('dock').repeat(waiting)}${cell(empty).repeat(rest)}</span>`;
}
