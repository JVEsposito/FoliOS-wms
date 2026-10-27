const STATUS_LABELS = {
    coincide: 'Coincide',
    difiere: 'Difiere',
    informacion_insuficiente: 'Información insuficiente',
    sin_ciclo_actual: 'Sin ciclo vigente',
    sin_ciclo_anterior: 'Sin ciclo anterior',
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
    orden: 'Orden',
    decision: 'Decisión',
    peso_prioridad: 'Peso de prioridad',
    peso_objetivo: 'Peso del objetivo',
    beneficio_neto: 'Beneficio neto',
    puntaje: 'Puntaje',
    factor_decisivo: 'Factor decisivo',
};

function text(value, fallback = 'Sin información') {
    const normalized = String(value ?? '').trim();
    return normalized || fallback;
}

function number(value) {
    return new Intl.NumberFormat('es-CL').format(Number(value || 0));
}

function humanize(value, fallback = 'Sin información') {
    return text(value, fallback)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
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

function decision(value) {
    return DECISION_LABELS[value] || humanize(value);
}

function factor(value) {
    const labels = {
        fuera_planificador: 'Flujo independiente',
        pausa_supervision: 'Pausa de supervisión',
        realidad_fisica_iniciada: 'Realidad física iniciada',
        fuera_rollout: 'Fuera del rollout dirigido',
        objetivo_pausado: 'Objetivo pausado',
        conflicto_recursos: 'Conflicto de recursos',
        cupo_disponible: 'Cupo de ejecución disponible',
        alternativa_sin_reserva: 'Alternativa sin reserva',
        frontera_completa: 'Frontera operacional completa',
    };

    return labels[value] || humanize(value);
}

function state(value = {}) {
    return {
        order: number(value.orden),
        decision: decision(value.decision),
        priorityWeight: number(value.peso_prioridad),
        objectiveWeight: number(value.peso_objetivo),
        netBenefit: number(value.beneficio_neto),
        score: number(value.puntaje),
        factor: factor(value.factor_decisivo),
    };
}

function differenceValue(field, value) {
    if (field === 'decision') return decision(value);
    if (field === 'factor_decisivo') return factor(value);
    return number(value);
}

export function buildCycleReplay(replay = {}) {
    const summary = replay.resumen || {};
    const checks = Array.isArray(replay.verificaciones) ? replay.verificaciones : [];

    return {
        available: Boolean(replay.disponible),
        status: text(replay.estado, 'informacion_insuficiente'),
        statusLabel: STATUS_LABELS[replay.estado] || humanize(replay.estado),
        detail: text(replay.detalle, 'No existe información suficiente para reproducir este ciclo.'),
        reference: replay.referencia === 'anterior' ? 'Ciclo anterior' : 'Ciclo vigente',
        cycle: replay.ciclo ? {
            generatedAt: dateTime(replay.ciclo.generado_at),
            rules: text(replay.ciclo.reglas, 'Reglas no informadas'),
            capacity: number(replay.ciclo.capacidad_ejecucion),
            frontier: number(replay.ciclo.frontera_max),
        } : null,
        summary: {
            decisions: number(summary.decisiones),
            matches: number(summary.coinciden),
            differences: number(summary.difieren),
            insufficient: number(summary.insuficientes),
        },
        checks: checks.map((check) => ({
            status: check.estado === 'difiere' ? 'difiere' : 'coincide',
            title: text(check.maniobra?.titulo, 'Maniobra sin título'),
            objective: text(check.maniobra?.objetivo, 'Sin objetivo informado'),
            folio: text(check.maniobra?.folio, 'Sin folio'),
            route: text(check.maniobra?.ruta, 'Sin ruta física informada'),
            persisted: state(check.persistido),
            reproduced: state(check.reproducido),
            differences: (Array.isArray(check.diferencias) ? check.diferencias : []).map((item) => ({
                field: text(item.campo, ''),
                label: FIELD_LABELS[item.campo] || humanize(item.campo),
                persisted: differenceValue(item.campo, item.persistido),
                reproduced: differenceValue(item.campo, item.reproducido),
            })).filter((item) => item.field),
        })),
        truncated: Boolean(replay.truncada),
        limit: number(replay.limite),
    };
}

function renderState(label, value) {
    return `<section><span>${escapeHtml(label)}</span><strong>#${escapeHtml(value.order)} · ${escapeHtml(value.decision)}</strong><small>Prioridad ${escapeHtml(value.priorityWeight)} · objetivo ${escapeHtml(value.objectiveWeight)} · neto ${escapeHtml(value.netBenefit)}</small><em>${escapeHtml(value.factor)} · puntaje ${escapeHtml(value.score)}</em></section>`;
}

function renderCheck(check) {
    const differences = check.differences.length
        ? `<ul>${check.differences.map((item) => `<li><strong>${escapeHtml(item.label)}</strong><span>${escapeHtml(item.persisted)}</span><b aria-hidden="true">→</b><span>${escapeHtml(item.reproduced)}</span></li>`).join('')}</ul>`
        : '<p>La decisión, el orden y todos los componentes calculados coinciden.</p>';

    return `<article class="operation-cycle-replay__check" data-status="${escapeHtml(check.status)}">
        <header><div><span>${check.status === 'coincide' ? 'REPRODUCCIÓN COINCIDENTE' : 'DIFERENCIA ENCONTRADA'}</span><h3>${escapeHtml(check.title)}</h3></div><strong>${escapeHtml(check.folio)}</strong></header>
        <p>${escapeHtml(check.objective)} · ${escapeHtml(check.route)}</p>
        <div class="operation-cycle-comparison__states">
            ${renderState('Resultado persistido', check.persisted)}
            ${renderState('Resultado reproducido', check.reproduced)}
        </div>
        <div class="operation-cycle-comparison__differences"><h4>Verificación determinista</h4>${differences}</div>
    </article>`;
}

export function renderCycleReplay(model) {
    const checks = model.checks.length
        ? model.checks.map(renderCheck).join('')
        : `<section class="operation-cycle-comparison__empty" data-reason="${escapeHtml(model.status)}"><strong>${escapeHtml(model.statusLabel)}</strong><p>${escapeHtml(model.detail)}</p></section>`;

    return `<div class="operation-cycle-replay__toolbar">
        <button type="button" data-cycle-comparison-return>← Volver a la comparación</button>
        <span>Replay aislado · no modifica la operación</span>
    </div>
    <section class="operation-cycle-replay__result" data-status="${escapeHtml(model.status)}">
        <div><span>${escapeHtml(model.reference)}</span><strong>${escapeHtml(model.statusLabel)}</strong><small>${escapeHtml(model.detail)}</small></div>
        <dl>
            <div><dt>Evaluado</dt><dd>${escapeHtml(model.cycle?.generatedAt || 'Sin fecha')}</dd></div>
            <div><dt>Reglas</dt><dd>${escapeHtml(model.cycle?.rules || 'No disponibles')}</dd></div>
            <div><dt>Capacidad</dt><dd>${escapeHtml(model.cycle?.capacity || '0')} / ${escapeHtml(model.cycle?.frontier || '0')}</dd></div>
        </dl>
    </section>
    <dl class="operation-cycle-comparison__summary">
        <div data-tone="info"><dt>Decisiones</dt><dd>${escapeHtml(model.summary.decisions)}</dd></div>
        <div data-tone="success"><dt>Coinciden</dt><dd>${escapeHtml(model.summary.matches)}</dd></div>
        <div data-tone="critical"><dt>Difieren</dt><dd>${escapeHtml(model.summary.differences)}</dd></div>
        <div data-tone="warning"><dt>Insuficientes</dt><dd>${escapeHtml(model.summary.insufficient)}</dd></div>
    </dl>
    ${model.truncated ? `<p class="operation-cycle-comparison__warning">Se muestran las primeras ${escapeHtml(model.limit)} verificaciones; el resumen considera el ciclo completo.</p>` : ''}
    <div class="operation-cycle-replay__checks">${checks}</div>`;
}
