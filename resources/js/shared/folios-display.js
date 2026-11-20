export function officeCode(value, escape, digits = 4) {
    const code = String(value ?? '—');
    if (code.length <= digits || !/^\d+$/.test(code.slice(-digits))) return escape(code);
    return `<span class="folios-code__prefix">${escape(code.slice(0, -digits))}</span><strong>${escape(code.slice(-digits))}</strong>`;
}

export function palletCells(total, filled, atDock = 0, remaining = 'pending') {
    const count = (value) => {
        const parsed = Math.trunc(Number(value));
        return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0;
    };
    const size = count(total);
    const done = Math.min(size, count(filled));
    const waiting = Math.min(size - done, count(atDock));
    const cell = (state) => `<i data-cell="${state}"></i>`;

    return `<span class="folios-pallet-cells" aria-hidden="true">${cell('done').repeat(done)}${cell('dock').repeat(waiting)}${cell(remaining).repeat(size - done - waiting)}</span>`;
}
