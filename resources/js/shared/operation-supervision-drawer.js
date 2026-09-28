import { cameraReferenceLabel } from './camera-display.js';

const DECISION_LABELS = {
    en_ejecucion: 'En ejecución',
    seleccionada: 'Seleccionada',
    alternativa: 'Alternativa',
    excluida_conflicto: 'Excluida por conflicto',
    fuera_frontera: 'Fuera de frontera',
    fuera_rollout: 'Fuera de rollout',
    fuera_planificador: 'Fuera del planificador',
};

const STATUS_LABELS = {
    pendiente: 'Pendiente',
    asumida: 'Asumida',
    en_proceso: 'En proceso',
    bloqueada: 'Bloqueada',
    completada: 'Completada',
    cancelada: 'Cancelada',
    en_ejecucion: 'En ejecución',
    pausada_discrepancia: 'Pausada por discrepancia',
    pausada_supervision: 'Pausada por supervisión',
};

const CONFLICT_LABELS = {
    folio: 'Pallet compartido con otra maniobra',
    posicion: 'Posición física compartida con otra maniobra',
    banda: 'Banda reservada por otra maniobra',
};

const ACTION_LABELS = {
    pausar: 'Pausar maniobra',
    reanudar: 'Reanudar maniobra',
    repriorizar: 'Cambiar prioridad',
    resolver_discrepancia: 'Resolver discrepancia',
};

const RESTRICTION_LABELS = {
    estado_no_pendiente: 'La maniobra ya no está pendiente.',
    no_pausada_por_supervision: 'La maniobra no fue pausada por supervisión.',
    estado_no_repriorizable: 'El estado actual no permite cambiar la prioridad.',
    sin_discrepancia_abierta: 'No existe una discrepancia abierta.',
    prefijo_fisico_iniciado: 'La ejecución física ya comenzó.',
    custodia_temporal_activa: 'Existen pallets bajo custodia temporal.',
    tarea_asumida: 'La tarea ya fue tomada por un camarero.',
    reserva_activa: 'La maniobra mantiene una reserva activa.',
};

function text(value, fallback = 'Sin información') {
    const normalized = String(value ?? '').trim();
    return normalized || fallback;
}

function humanize(value, fallback = 'Sin información') {
    const normalized = text(value, fallback);
    return normalized
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

function number(value) {
    return new Intl.NumberFormat('es-CL').format(Number(value || 0));
}

function conflictLabel(conflict) {
    const type = String(conflict?.recurso || '').split(':')[0];
    return CONFLICT_LABELS[type] || 'Recurso operacional compartido con otra maniobra';
}

function buildExplanation(explanation, fallbackReason) {
    if (!explanation || typeof explanation !== 'object') {
        return {
            available: false,
            summary: fallbackReason,
            factor: 'Ciclo anterior sin explicación persistida',
            rules: '',
            formula: '',
            components: [],
            capacity: {},
            restrictions: [],
            requiredResources: [],
            conflictResources: [],
            snapshot: { maneuver: null, objective: null, steps: [] },
        };
    }

    const components = explanation.componentes || {};
    const priority = components.prioridad || {};
    const objective = components.objetivo || {};
    const benefit = components.beneficio || {};
    const resources = explanation.recursos || {};
    const snapshot = explanation.snapshot || {};

    return {
        available: true,
        summary: text(explanation.resumen, fallbackReason),
        factor: text(explanation.factor_decisivo?.etiqueta, 'Criterio operacional'),
        rules: text(explanation.reglas, ''),
        formula: text(explanation.formula, ''),
        physicalReality: Boolean(components.realidad_fisica),
        components: [
            {
                key: 'prioridad',
                label: 'Prioridad',
                value: humanize(priority.valor),
                weight: number(priority.peso),
                contribution: number(priority.aporte),
            },
            {
                key: 'objetivo',
                label: 'Objetivo dominante',
                value: text(objective.titulo || humanize(objective.tipo, ''), 'Sin objetivo'),
                weight: number(objective.peso),
                contribution: number(objective.aporte),
            },
            {
                key: 'beneficio',
                label: 'Beneficio neto',
                value: number(benefit.neto),
                weight: 'Directo',
                contribution: number(benefit.aporte),
            },
        ],
        benefit: {
            estimated: number(benefit.estimado),
            movementCost: number(benefit.costo_movimientos),
            risk: number(benefit.riesgo_operacional),
            net: number(benefit.neto),
            score: number(components.puntaje),
        },
        capacity: explanation.capacidad || {},
        restrictions: (Array.isArray(explanation.restricciones) ? explanation.restricciones : [])
            .map((restriction) => text(restriction?.detalle, ''))
            .filter(Boolean),
        requiredResources: [...new Set(
            (Array.isArray(resources.requeridos) ? resources.requeridos : [])
                .map((resource) => text(resource?.nombre, ''))
                .filter(Boolean),
        )],
        conflictResources: (Array.isArray(resources.conflictos) ? resources.conflictos : [])
            .map((conflict) => ({
                name: text(conflict?.nombre, 'Recurso operacional'),
                blockingManeuver: text(conflict?.maniobra_titulo, 'Otra maniobra prioritaria'),
            })),
        snapshot: {
            maneuver: snapshot.maniobra ? {
                title: text(snapshot.maniobra.titulo, 'Maniobra sin título'),
                status: STATUS_LABELS[snapshot.maniobra.estado] || humanize(snapshot.maniobra.estado),
                priority: humanize(snapshot.maniobra.prioridad),
                version: number(snapshot.maniobra.version),
            } : null,
            objective: snapshot.objetivo ? {
                title: text(snapshot.objetivo.titulo, 'Objetivo sin título'),
                type: humanize(snapshot.objetivo.tipo),
                status: humanize(snapshot.objetivo.estado),
                version: number(snapshot.objetivo.version),
            } : null,
            steps: (Array.isArray(snapshot.pasos) ? snapshot.pasos : []).map((step) => ({
                sequence: number(step.secuencia),
                status: STATUS_LABELS[step.estado] || humanize(step.estado),
                instruction: text(step.instruccion || humanize(step.tipo_movimiento, ''), 'Sin instrucción'),
                folio: text(step.folio, 'Sin folio'),
                route: `${text(step.origen, 'Inicio')} → ${text(step.destino, 'Sin destino')}`,
            })),
        },
    };
}

export function operationLocationLabel(location, fallback = 'Sin ubicación') {
    const camera = location?.camara || location;
    const cameraName = camera ? cameraReferenceLabel(camera) : '';
    const position = text(location?.posicion?.etiqueta, '');
    const parts = [cameraName, position].filter(Boolean);

    return parts.length ? parts.join(' · ') : fallback;
}

export function buildManeuverSupervisionModel(decision = {}) {
    const steps = Array.isArray(decision.pasos) && decision.pasos.length
        ? decision.pasos
        : (decision.paso_actual ? [decision.paso_actual] : []);
    const current = decision.paso_actual || steps.find((step) => !['completada', 'cancelada'].includes(step.estado)) || null;
    const conflicts = [...new Set((Array.isArray(decision.conflictos) ? decision.conflictos : []).map(conflictLabel))];

    return {
        version: Number(decision.version || decision.acciones_autorizadas?.version_requerida || 0),
        title: text(decision.titulo, 'Maniobra sin título'),
        decision: DECISION_LABELS[decision.decision] || humanize(decision.decision),
        decisionTone: {
            en_ejecucion: 'success',
            seleccionada: 'info',
            excluida_conflicto: 'critical',
            fuera_frontera: 'warning',
            fuera_rollout: 'warning',
        }[decision.decision] || 'neutral',
        status: STATUS_LABELS[decision.estado] || humanize(decision.estado),
        priority: humanize(decision.prioridad),
        reason: text(decision.motivo, 'El ciclo no informó una razón adicional.'),
        objective: text(decision.objetivo?.titulo || humanize(decision.objetivo?.tipo, ''), 'Sin objetivo informado'),
        score: number(decision.puntaje),
        netBenefit: number(decision.beneficio_neto),
        movementCost: number(decision.costo_movimientos),
        operationalRisk: number(decision.riesgo_operacional),
        progress: {
            completed: number(decision.progreso?.pasos_completados),
            total: number(decision.progreso?.pasos_total ?? steps.length),
            percent: Math.max(0, Math.min(100, Number(decision.progreso?.porcentaje || 0))),
        },
        assignment: {
            responsible: text(decision.responsable?.nombre, 'Sin camarero asignado'),
            device: text(decision.dispositivo?.nombre || decision.dispositivo?.codigo, 'Sin tablet asignada'),
        },
        currentStep: current ? {
            sequence: number(current.secuencia),
            status: STATUS_LABELS[current.estado] || humanize(current.estado),
            instruction: text(current.instruccion || humanize(current.tipo_movimiento, ''), 'Sin instrucción'),
            folio: text(current.folio?.numero_folio, 'Sin folio'),
            route: `${operationLocationLabel(current.origen, 'Inicio')} → ${operationLocationLabel(current.destino, 'Sin destino')}`,
        } : null,
        conflicts,
        explanation: buildExplanation(
            decision.explicacion,
            text(decision.motivo, 'El ciclo no informó una razón adicional.'),
        ),
        actions: {
            allowed: Array.isArray(decision.acciones_autorizadas?.permitidas)
                ? decision.acciones_autorizadas.permitidas
                : [],
            restrictions: decision.acciones_autorizadas?.restricciones || {},
        },
        steps: steps.map((step, index) => ({
            sequence: number(step.secuencia || index + 1),
            status: STATUS_LABELS[step.estado] || humanize(step.estado),
            instruction: text(step.instruccion || humanize(step.tipo_movimiento, ''), 'Sin instrucción'),
            folio: text(step.folio?.numero_folio, 'Sin folio'),
            route: `${operationLocationLabel(step.origen, 'Inicio')} → ${operationLocationLabel(step.destino, 'Sin destino')}`,
            current: Boolean(current && step.id === current.id),
        })),
    };
}

function renderExplanation(model) {
    const explanation = model.explanation;
    if (!explanation.available) {
        return `<section class="operation-supervision__explanation" data-empty="true">
            <header><div><h4>Por qué se tomó esta decisión</h4><p>${escapeHtml(explanation.summary)}</p></div><strong>${escapeHtml(explanation.factor)}</strong></header>
        </section>`;
    }

    const components = explanation.components.map((component) => `<article data-component="${escapeHtml(component.key)}">
        <span>${escapeHtml(component.label)}</span><strong>${escapeHtml(component.value)}</strong>
        <small>Peso ${escapeHtml(component.weight)} · aporte ${escapeHtml(component.contribution)}</small>
    </article>`).join('');
    const capacity = explanation.capacity;
    const restrictions = explanation.restrictions.length
        ? `<ul>${explanation.restrictions.map((restriction) => `<li>${escapeHtml(restriction)}</li>`).join('')}</ul>`
        : '<p>No se registraron restricciones determinantes.</p>';
    const resources = explanation.requiredResources.length
        ? explanation.requiredResources.map((resource) => `<li>${escapeHtml(resource)}</li>`).join('')
        : '<li>No se registraron recursos físicos.</li>';
    const conflicts = explanation.conflictResources.length
        ? `<ul>${explanation.conflictResources.map((conflict) => `<li><strong>${escapeHtml(conflict.name)}</strong><span>Comprometido por ${escapeHtml(conflict.blockingManeuver)}</span></li>`).join('')}</ul>`
        : '<p>No existen recursos en conflicto.</p>';
    const snapshotSteps = explanation.snapshot.steps.length
        ? `<ol>${explanation.snapshot.steps.map((step) => `<li><span>${escapeHtml(step.sequence)}</span><div><strong>${escapeHtml(step.instruction)}</strong><small>${escapeHtml(step.folio)} · ${escapeHtml(step.route)} · ${escapeHtml(step.status)}</small></div></li>`).join('')}</ol>`
        : '<p>Sin pasos en el snapshot de la decisión.</p>';

    return `<section class="operation-supervision__explanation">
        <header><div><h4>Por qué se tomó esta decisión</h4><p>${escapeHtml(explanation.summary)}</p></div><span>Factor decisivo</span><strong>${escapeHtml(explanation.factor)}</strong></header>
        <div class="operation-supervision__calculation">${components}</div>
        <dl class="operation-supervision__capacity">
            ${renderMetric('Capacidad', number(capacity.capacidad_ejecucion))}
            ${renderMetric('Ocupantes físicos', number(capacity.ocupantes_fisicos))}
            ${renderMetric('Cupos disponibles', number(capacity.cupos_disponibles))}
            ${renderMetric('Frontera máxima', number(capacity.frontera_max))}
        </dl>
        <details><summary>Restricciones y recursos evaluados</summary><div class="operation-supervision__evidence">
            <section><h5>Condición determinante</h5>${restrictions}</section>
            <section><h5>Recursos requeridos</h5><ul>${resources}</ul></section>
            <section><h5>Conflictos específicos</h5>${conflicts}</section>
        </div></details>
        <details><summary>Evidencia conservada del ciclo</summary><div class="operation-supervision__snapshot">
            <p><strong>${escapeHtml(explanation.snapshot.maneuver?.title || model.title)}</strong> · ${escapeHtml(explanation.snapshot.objective?.title || model.objective)}</p>
            ${snapshotSteps}
        </div></details>
        <footer><span>Criterio ${escapeHtml(explanation.rules)}</span><small>${escapeHtml(explanation.formula)}</small></footer>
    </section>`;
}

export function buildManeuverInterventionRequest(
    decision,
    action,
    { reason, priority } = {},
    operationId,
) {
    const maneuverId = text(decision?.maniobra_id, '');
    const version = Number(decision?.version || decision?.acciones_autorizadas?.version_requerida || 0);
    const allowed = Array.isArray(decision?.acciones_autorizadas?.permitidas)
        ? decision.acciones_autorizadas.permitidas
        : [];
    const normalizedReason = text(reason, '');

    if (!maneuverId || !version) throw new Error('La maniobra no posee una versión vigente.');
    if (!allowed.includes(action)) throw new Error('El servidor ya no autoriza esta intervención.');
    if (normalizedReason.length < 3) throw new Error('Ingresa un motivo de al menos 3 caracteres.');
    if (!operationId) throw new Error('No fue posible identificar la intervención.');

    const endpoints = {
        pausar: { suffix: 'pausar', method: 'POST' },
        reanudar: { suffix: 'reanudar', method: 'POST' },
        repriorizar: { suffix: 'prioridad', method: 'PATCH' },
    };
    const endpoint = endpoints[action];
    if (!endpoint) throw new Error('La intervención seleccionada no es válida.');

    const body = {
        operacion_id: operationId,
        version_maniobra: version,
        motivo: normalizedReason,
    };
    if (action === 'repriorizar') {
        if (!['normal', 'alta', 'urgente', 'critica'].includes(priority)) {
            throw new Error('Selecciona una prioridad válida.');
        }
        body.prioridad = priority;
    }

    return {
        path: `/api/intervenciones-planificador/maniobras/${encodeURIComponent(maneuverId)}/${endpoint.suffix}`,
        method: endpoint.method,
        body,
    };
}

function renderActions(model, canIntervene) {
    if (!canIntervene) {
        return `<footer><strong>Consulta de supervisión</strong><span>Tu perfil puede revisar la decisión, pero no intervenir la maniobra.</span></footer>`;
    }

    const allowed = model.actions.allowed;
    const primaryActions = ['pausar', 'reanudar', 'repriorizar']
        .filter((action) => allowed.includes(action));
    const discrepancyAllowed = allowed.includes('resolver_discrepancia');
    const restrictions = Object.entries(model.actions.restrictions)
        .filter(([action, reason]) => reason && ACTION_LABELS[action])
        .map(([action, reason]) => `<li><strong>${escapeHtml(ACTION_LABELS[action])}:</strong> ${escapeHtml(RESTRICTION_LABELS[reason] || humanize(reason))}</li>`)
        .join('');

    if (!primaryActions.length && !discrepancyAllowed) {
        return `<section class="operation-supervision__actions" data-empty="true">
            <div><h4>Intervenciones seguras</h4><p>No hay acciones habilitadas para el estado físico actual.</p></div>
            ${restrictions ? `<ul class="operation-supervision__restrictions">${restrictions}</ul>` : ''}
        </section>`;
    }

    const buttons = primaryActions
        .map((action) => `<button type="button" data-supervision-action="${escapeHtml(action)}">${escapeHtml(ACTION_LABELS[action])}</button>`)
        .join('');
    const discrepancy = discrepancyAllowed
        ? '<a href="/oficina/frigorifico/discrepancias">Resolver discrepancia →</a>'
        : '';

    return `<section class="operation-supervision__actions">
        <div><h4>Intervenciones seguras</h4><p>El servidor habilita únicamente acciones que no alteran el prefijo físico.</p></div>
        <div class="operation-supervision__action-buttons">${buttons}${discrepancy}</div>
        <form class="operation-supervision__confirm" data-supervision-form hidden>
            <strong data-supervision-confirm-title>Confirmar intervención</strong>
            <p>El motivo, usuario y cambios quedarán registrados en la auditoría operacional.</p>
            <label data-supervision-priority hidden>Prioridad
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                    <option value="critica">Crítica</option>
                </select>
            </label>
            <label>Motivo
                <textarea name="reason" minlength="3" maxlength="500" required placeholder="Explica por qué se realiza esta intervención"></textarea>
            </label>
            <div><button type="button" data-supervision-cancel>Cancelar</button><button type="submit" data-supervision-confirm>Confirmar y registrar</button></div>
        </form>
    </section>`;
}

function renderMetric(label, value) {
    return `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`;
}

function renderDrawer(model, canIntervene = false) {
    const current = model.currentStep
        ? `<section class="operation-supervision__current">
            <p>PASO ACTUAL · ${escapeHtml(model.currentStep.sequence)} DE ${escapeHtml(model.progress.total)}</p>
            <strong>${escapeHtml(model.currentStep.instruction)}</strong>
            <span>${escapeHtml(model.currentStep.folio)} · ${escapeHtml(model.currentStep.route)}</span>
            <small>${escapeHtml(model.currentStep.status)}</small>
        </section>`
        : `<section class="operation-supervision__current" data-empty="true">
            <p>PASO ACTUAL</p><strong>Sin movimiento activo</strong>
            <span>La maniobra no posee un paso pendiente de ejecución.</span>
        </section>`;

    const conflicts = model.conflicts.length
        ? `<ul>${model.conflicts.map((conflict) => `<li>${escapeHtml(conflict)}</li>`).join('')}</ul>`
        : '<p class="operation-supervision__empty">No se informan conflictos para esta decisión.</p>';

    const steps = model.steps.length
        ? `<ol class="operation-supervision__steps">${model.steps.map((step) => `<li data-current="${step.current ? 'true' : 'false'}">
            <span>${escapeHtml(step.sequence)}</span>
            <div><strong>${escapeHtml(step.instruction)}</strong><small>${escapeHtml(step.folio)} · ${escapeHtml(step.route)}</small></div>
            <em>${escapeHtml(step.status)}</em>
        </li>`).join('')}</ol>`
        : '<p class="operation-supervision__empty">No existen pasos publicados para esta maniobra.</p>';

    return `<div class="operation-supervision__identity">
        <span data-tone="${escapeHtml(model.decisionTone)}">${escapeHtml(model.decision)}</span>
        <h3>${escapeHtml(model.title)}</h3>
        <p>${escapeHtml(model.objective)}</p>
    </div>
    <dl class="operation-supervision__metrics">
        ${renderMetric('Estado', model.status)}
        ${renderMetric('Prioridad', model.priority)}
        ${renderMetric('Progreso', `${model.progress.completed}/${model.progress.total} · ${model.progress.percent}%`)}
        ${renderMetric('Beneficio neto', model.netBenefit)}
    </dl>
    ${current}
    ${renderExplanation(model)}
    <div class="operation-supervision__grid">
        <section><h4>Razón operacional</h4><p>${escapeHtml(model.reason)}</p></section>
        <section><h4>Asignación vigente</h4><p><strong>${escapeHtml(model.assignment.responsible)}</strong><br>${escapeHtml(model.assignment.device)}</p></section>
        <section><h4>Resumen numérico</h4><dl>
            ${renderMetric('Puntaje', model.score)}
            ${renderMetric('Movimientos', model.movementCost)}
            ${renderMetric('Riesgo', model.operationalRisk)}
        </dl></section>
        <section><h4>Conflictos informados</h4>${conflicts}</section>
    </div>
    <section class="operation-supervision__sequence"><h4>Secuencia física completa</h4>${steps}</section>
    ${renderActions(model, canIntervene)}`;
}

export function createManeuverSupervisionDrawer({
    dialog,
    content,
    closeButton,
    onClosed = () => {},
    canIntervene = () => false,
    onAction = async () => false,
}) {
    if (!dialog || !content || !closeButton) {
        return { open() {}, update() {}, close() {} };
    }

    let currentDecision = null;
    const update = (decision) => {
        currentDecision = decision;
        content.innerHTML = renderDrawer(
            buildManeuverSupervisionModel(decision),
            Boolean(canIntervene()),
        );
    };
    const close = () => {
        if (dialog.open) dialog.close();
    };

    closeButton.addEventListener('click', close);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) close();
    });
    dialog.addEventListener('close', onClosed);
    content.addEventListener('click', (event) => {
        const actionButton = event.target.closest('[data-supervision-action]');
        if (actionButton) {
            const form = content.querySelector('[data-supervision-form]');
            if (!form) return;
            const action = actionButton.dataset.supervisionAction;
            form.dataset.action = action;
            form.hidden = false;
            form.querySelector('[data-supervision-confirm-title]').textContent = ACTION_LABELS[action];
            form.querySelector('[data-supervision-priority]').hidden = action !== 'repriorizar';
            form.querySelector('[name="reason"]').focus();
            return;
        }

        if (event.target.closest('[data-supervision-cancel]')) {
            const form = content.querySelector('[data-supervision-form]');
            if (form) {
                form.hidden = true;
                form.reset();
            }
        }
    });
    content.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-supervision-form]');
        if (!form || !currentDecision) return;
        event.preventDefault();
        if (!form.reportValidity()) return;

        const controls = [...form.querySelectorAll('button, textarea, select')];
        controls.forEach((control) => { control.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        try {
            await onAction({
                action: form.dataset.action,
                reason: form.elements.reason.value,
                priority: form.elements.priority.value,
                decision: currentDecision,
            });
        } finally {
            controls.forEach((control) => { control.disabled = false; });
            form.setAttribute('aria-busy', 'false');
        }
    });

    return {
        open(decision) {
            update(decision);
            if (!dialog.open) dialog.showModal();
        },
        update,
        close,
    };
}
