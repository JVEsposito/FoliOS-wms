const CHANGE_LABELS = {
    incorporada: 'Entró al ciclo',
    retirada: 'Salió del ciclo',
    modificada: 'Cambió su decisión',
};

const DECISION_LABELS = {
    en_ejecucion: 'En ejecución',
    seleccionada: 'Seleccionada',
    alternativa: 'Alternativa',
    excluida_conflicto: 'Excluida por conflicto',
    fuera_frontera: 'Fuera de frontera',
    fuera_rollout: 'Fuera de rollout',
    fuera_planificador: 'Fuera del planificador',
    sin_destino: 'Sin destino posible',
};

const FIELD_LABELS = {
    decision: 'Decisión',
    orden: 'Orden',
    prioridad: 'Prioridad',
    puntaje: 'Puntaje',
    beneficio_neto: 'Beneficio neto',
    factor_etiqueta: 'Factor decisivo',
    objetivo: 'Objetivo',
    folio: 'Folio',
    ruta: 'Ruta física',
};

function text(value, fallback = 'Sin información') {
    const normalized = String(value ?? '').trim();
    return normalized || fallback;
}

function humanize(value, fallback = 'Sin información') {
    return text(value, fallback)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function number(value) {
    return new Intl.NumberFormat('es-CL').format(Number(value || 0));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

function dateTime(value) {
    if (!value) return 'Sin fecha';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return 'Sin fecha';

    return new Intl.DateTimeFormat('es-CL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(parsed);
}

function decisionLabel(value) {
    return DECISION_LABELS[value] || humanize(value);
}

function formatDifferenceValue(field, value) {
    if (value === null || value === undefined || value === '') return 'Sin información';
    if (field === 'decision') return decisionLabel(value);
    if (field === 'prioridad') return humanize(value);
    if (['orden', 'puntaje', 'beneficio_neto'].includes(field)) return number(value);

    return text(value);
}

function normalizeState(state) {
    if (!state || typeof state !== 'object') return null;

    return {
        order: number(state.orden),
        decision: decisionLabel(state.decision),
        priority: humanize(state.prioridad),
        score: number(state.puntaje),
        netBenefit: number(state.beneficio_neto),
        factor: text(state.factor_decisivo, 'Sin factor persistido'),
    };
}

export function buildCycleComparison(comparison = {}) {
    const summary = comparison.resumen || {};
    const changes = Array.isArray(comparison.cambios) ? comparison.cambios : [];

    return {
        available: Boolean(comparison.disponible),
        reason: text(comparison.motivo, ''),
        detail: text(comparison.detalle, 'No existe información suficiente para comparar.'),
        current: comparison.actual ? {
            generatedAt: dateTime(comparison.actual.generado_at),
            capacity: number(comparison.actual.capacidad_ejecucion),
            frontier: number(comparison.actual.frontera_max),
        } : null,
        previous: comparison.anterior ? {
            generatedAt: dateTime(comparison.anterior.generado_at),
            capacity: number(comparison.anterior.capacidad_ejecucion),
            frontier: number(comparison.anterior.frontera_max),
        } : null,
        summary: {
            added: number(summary.incorporadas),
            removed: number(summary.retiradas),
            modified: number(summary.modificadas),
            unchanged: number(summary.sin_cambios),
            totalChanges: number(summary.total_cambios),
        },
        changes: changes.map((change) => ({
            type: text(change.tipo, 'modificada'),
            label: CHANGE_LABELS[change.tipo] || humanize(change.tipo),
            title: text(change.maniobra?.titulo, 'Maniobra sin título'),
            objective: text(change.maniobra?.objetivo, 'Sin objetivo informado'),
            folio: text(change.maniobra?.folio, 'Sin folio'),
            route: text(change.maniobra?.ruta, 'Sin ruta física informada'),
            before: normalizeState(change.anterior),
            after: normalizeState(change.actual),
            differences: (Array.isArray(change.diferencias) ? change.diferencias : [])
                .map((difference) => ({
                    field: text(difference?.campo, ''),
                    label: FIELD_LABELS[difference?.campo] || humanize(difference?.campo),
                    before: formatDifferenceValue(difference?.campo, difference?.anterior),
                    after: formatDifferenceValue(difference?.campo, difference?.actual),
                }))
                .filter((difference) => difference.field),
            previousReason: text(change.razon_anterior, ''),
            currentReason: text(change.razon_actual, ''),
        })),
        truncated: Boolean(comparison.truncada),
        limit: number(comparison.limite),
    };
}

function renderState(label, state) {
    if (!state) {
        return `<section data-empty="true"><span>${escapeHtml(label)}</span><strong>No pertenecía al ciclo</strong></section>`;
    }

    return `<section><span>${escapeHtml(label)}</span><strong>#${escapeHtml(state.order)} · ${escapeHtml(state.decision)}</strong><small>Prioridad ${escapeHtml(state.priority)} · puntaje ${escapeHtml(state.score)} · neto ${escapeHtml(state.netBenefit)}</small><em>${escapeHtml(state.factor)}</em></section>`;
}

function renderChange(change) {
    const differences = change.differences.length
        ? `<ul>${change.differences.map((difference) => `<li><strong>${escapeHtml(difference.label)}</strong><span>${escapeHtml(difference.before)}</span><b aria-hidden="true">→</b><span>${escapeHtml(difference.after)}</span></li>`).join('')}</ul>`
        : `<p>${change.type === 'incorporada' ? 'La maniobra no figuraba en el ciclo anterior.' : 'La maniobra dejó de pertenecer a la frontera vigente.'}</p>`;
    const reason = change.currentReason || change.previousReason;

    return `<article class="operation-cycle-comparison__change" data-change="${escapeHtml(change.type)}">
        <header><div><span>${escapeHtml(change.label)}</span><h3>${escapeHtml(change.title)}</h3></div><strong>${escapeHtml(change.folio)}</strong></header>
        <p>${escapeHtml(change.objective)} · ${escapeHtml(change.route)}</p>
        <div class="operation-cycle-comparison__states">
            ${renderState('Ciclo anterior', change.before)}
            ${renderState('Ciclo actual', change.after)}
        </div>
        <div class="operation-cycle-comparison__differences"><h4>Diferencias verificadas</h4>${differences}</div>
        ${reason ? `<footer><span>Razón operacional</span><strong>${escapeHtml(reason)}</strong></footer>` : ''}
    </article>`;
}

function renderReplayActions(model) {
    if (!model.current && !model.previous) return '';

    return `<div class="operation-cycle-comparison__actions" aria-label="Reproducción histórica">
        ${model.previous ? '<button type="button" data-cycle-replay="anterior">Verificar ciclo anterior</button>' : ''}
        ${model.current ? '<button type="button" data-cycle-replay="actual">Verificar ciclo vigente</button>' : ''}
        <span>El replay usa exclusivamente la evidencia conservada y no modifica la operación.</span>
    </div>`;
}

export function renderCycleComparison(model) {
    if (!model.available) {
        return `${renderReplayActions(model)}<section class="operation-cycle-comparison__empty" data-reason="${escapeHtml(model.reason)}"><strong>Comparación todavía no disponible</strong><p>${escapeHtml(model.detail)}</p></section>`;
    }

    const changes = model.changes.length
        ? model.changes.map(renderChange).join('')
        : '<section class="operation-cycle-comparison__empty"><strong>Sin cambios operacionales</strong><p>Las decisiones, el orden y los factores se conservaron respecto del ciclo anterior.</p></section>';

    return `${renderReplayActions(model)}
    <div class="operation-cycle-comparison__overview">
        <section><span>Ciclo anterior</span><strong>${escapeHtml(model.previous?.generatedAt || 'Sin fecha')}</strong><small>Capacidad ${escapeHtml(model.previous?.capacity || '0')} · frontera ${escapeHtml(model.previous?.frontier || '0')}</small></section>
        <b aria-hidden="true">→</b>
        <section><span>Ciclo vigente</span><strong>${escapeHtml(model.current?.generatedAt || 'Sin fecha')}</strong><small>Capacidad ${escapeHtml(model.current?.capacity || '0')} · frontera ${escapeHtml(model.current?.frontier || '0')}</small></section>
    </div>
    <dl class="operation-cycle-comparison__summary">
        <div data-tone="info"><dt>Entraron</dt><dd>${escapeHtml(model.summary.added)}</dd></div>
        <div data-tone="critical"><dt>Salieron</dt><dd>${escapeHtml(model.summary.removed)}</dd></div>
        <div data-tone="warning"><dt>Cambiaron</dt><dd>${escapeHtml(model.summary.modified)}</dd></div>
        <div data-tone="success"><dt>Sin cambios</dt><dd>${escapeHtml(model.summary.unchanged)}</dd></div>
    </dl>
    <p class="operation-cycle-comparison__detail">${escapeHtml(model.detail)}</p>
    ${model.truncated ? `<p class="operation-cycle-comparison__warning">Se muestran los primeros ${escapeHtml(model.limit)} cambios. El resumen considera el ciclo completo.</p>` : ''}
    <div class="operation-cycle-comparison__changes">${changes}</div>`;
}
