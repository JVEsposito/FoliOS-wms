// Citas del chat y del correo interno: «#» cita un registro de FoliOS y «@» menciona a una
// persona. El texto viaja con fichas [[tipo:id]]; el servidor valida cada una y fija su etiqueta.

export const CITATION_TOKEN = /\[\[([a-z_]{2,30}):([A-Za-z0-9-]{1,36})\]\]/g;
const TRIGGER = /(^|[\s([{¿¡"'])([#@])([^\s#@[\]]{0,40})$/u;
const BOUNDARY_AFTER = '(?=$|[\\s.,;:!?)\\]}"\'…-])';

export function citationLabel(reference) {
    return `${reference.tipo === 'usuario' ? '@' : '#'}${reference.etiqueta}`;
}

/** Detecta «#texto» o «@texto» justo antes del cursor. */
export function citationTrigger(text, caret) {
    const before = String(text ?? '').slice(0, caret);
    const match = before.match(TRIGGER);
    if (!match) return null;
    const [, , sigil, query] = match;
    return { sigil, query, start: caret - query.length - 1, end: caret, kind: sigil === '@' ? 'user' : 'record' };
}

/** Reemplaza el disparador por la etiqueta visible y deja un espacio para seguir escribiendo. */
export function insertCitation(text, trigger, reference) {
    const value = String(text ?? '');
    const label = citationLabel(reference);
    const after = value.slice(trigger.end);
    const spacer = /^\s/.test(after) ? '' : ' ';
    const next = `${value.slice(0, trigger.start)}${label}${spacer}${after}`;
    return { text: next, caret: trigger.start + label.length + spacer.length };
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Convierte las etiquetas visibles en fichas. Sólo cuentan las citas elegidas de la lista
 * que siguen intactas en el texto; una etiqueta editada a mano queda como texto.
 */
export function encodeCitations(text, citations) {
    let value = String(text ?? '');
    const entries = [...(citations?.values?.() ?? [])]
        .map((reference) => ({ reference, label: citationLabel(reference) }))
        .sort((a, b) => b.label.length - a.label.length);
    for (const { reference, label } of entries) {
        const pattern = new RegExp(`(?<=^|[\\s([{¿¡"'])${escapeRegExp(label)}${BOUNDARY_AFTER}`, 'gu');
        value = value.replace(pattern, `[[${reference.tipo}:${reference.id}]]`);
    }
    return value;
}

/** Citas elegidas que todavía aparecen en el texto. */
export function activeCitations(text, citations) {
    const encoded = encodeCitations(text, citations);
    return [...(citations?.values?.() ?? [])].filter((reference) => encoded.includes(`[[${reference.tipo}:${reference.id}]]`));
}

/** Divide el texto recibido en trozos de texto y citas, para pintarlos sin usar HTML ajeno. */
export function citationSegments(text, references = []) {
    const value = String(text ?? '');
    const byKey = new Map(references.map((reference) => [`${reference.tipo}:${reference.id}`, reference]));
    const segments = [];
    let last = 0;
    for (const match of value.matchAll(CITATION_TOKEN)) {
        const reference = byKey.get(`${match[1]}:${match[2]}`);
        if (!reference) continue;
        if (match.index > last) segments.push({ type: 'text', value: value.slice(last, match.index) });
        segments.push({ type: 'citation', reference });
        last = match.index + match[0].length;
    }
    if (last < value.length) segments.push({ type: 'text', value: value.slice(last) });
    return segments;
}

/** Texto legible de un mensaje con citas, para el borrador de una respuesta o un extracto. */
export function plainCitations(text, references = []) {
    return citationSegments(text, references).map((segment) => segment.type === 'text' ? segment.value : citationLabel(segment.reference)).join('');
}

/** Recupera un texto con fichas como texto visible y su mapa de citas, para editarlo. */
export function decodeCitations(text, references = []) {
    const citations = new Map();
    const value = citationSegments(text, references).map((segment) => {
        if (segment.type === 'text') return segment.value;
        citations.set(`${segment.reference.tipo}:${segment.reference.id}`, segment.reference);
        return citationLabel(segment.reference);
    }).join('');
    return { text: value, citations };
}
