/** Los documentos separan cantidades; tara y propiedad se conservan por tipo en la recepción. */
export function guideTotals(guides) {
    const totals = {};
    for (const guide of guides) for (const item of guide.envases) totals[item.tipo_envase] = (totals[item.tipo_envase] || 0) + Number(item.cantidad || 0);
    return totals;
}
export function guideProblem(guides) {
    const numbers = guides.map((g) => g.numero_guia_despacho.trim().toUpperCase());
    if (numbers.some((n) => !n)) return 'Indica el número de cada guía del camión.';
    if (numbers.some((n) => n.length > 80)) return 'Cada número de guía admite hasta 80 caracteres.';
    if (new Set(numbers).size !== numbers.length) return 'No repitas una guía dentro del mismo camión.';
    if (guides.some((g) => !g.envases.length || g.envases.some((e) => !Number.isInteger(Number(e.cantidad)) || Number(e.cantidad) < 1 || Number(e.cantidad) > 100000))) return 'Cada guía debe declarar al menos un envase, con cantidades enteras positivas.';
    if (Object.values(guideTotals(guides)).some((n) => n > 100000)) return 'El total del camión admite hasta 100.000 envases por tipo.';
    return '';
}
export function createReceptionGuides({ form, panel, list, add, types, readContainers, writeTotals, escapeHtml }) {
    const primary = form.elements.numero_guia_despacho;
    let guides = [];
    function read() {
        return guides.map((_, index) => {
            const card = list.querySelector(`[data-guide="${index}"]`);
            return { numero_guia_despacho: card.querySelector('[data-guide-number]').value.trim().toUpperCase(),
                envases: [...card.querySelectorAll('[data-guide-container]')].map((input) => ({ tipo_envase: input.dataset.guideContainer, cantidad: Number(input.value || 0) })).filter((e) => e.cantidad !== 0) };
        });
    }
    function render() {
        list.innerHTML = guides.map((guide, index) => `<fieldset class="reception-guide" data-guide="${index}"><legend>Guía ${index + 1}</legend><div class="reception-guide__heading"><label class="field"><span>N° de guía *</span><input data-guide-number maxlength="80" value="${escapeHtml(guide.numero_guia_despacho)}" aria-label="Número de guía ${index + 1}" required></label><button class="secondary-button" data-remove-guide="${index}" type="button" aria-label="Quitar guía ${index + 1}">Quitar</button></div><div class="reception-guide__quantities">${types().map((t) => `<label class="field"><span>${escapeHtml(t.nombre)}</span><input data-guide-container="${escapeHtml(t.codigo)}" aria-label="${escapeHtml(t.nombre)} de guía ${index + 1}" type="number" min="0" max="100000" step="1" value="${guide.envases.find((e) => e.tipo_envase === t.codigo)?.cantidad || 0}"></label>`).join('')}</div></fieldset>`).join('');
        const multiple = guides.length > 1;
        panel.classList.toggle('is-hidden', !multiple);
        primary.closest('label').classList.toggle('is-hidden', multiple);
        primary.disabled = multiple; primary.required = !multiple;
        add.disabled = guides.length >= 20;
        if (multiple) writeTotals(guideTotals(read()), true);
    }
    add.addEventListener('click', () => {
        if (guides.length >= 20) return;
        guides = guides.length > 1 ? read() : [{ numero_guia_despacho: primary.value, envases: readContainers() }];
        guides.push({ numero_guia_despacho: '', envases: [] }); render();
        list.querySelector(`[data-guide="${guides.length - 1}"] [data-guide-number]`).focus();
    });
    list.addEventListener('input', () => { if (guides.length > 1) writeTotals(guideTotals(read()), true); });
    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-guide]');
        if (!button) return;
        guides = read().filter((_, index) => index !== Number(button.dataset.removeGuide));
        if (guides.length === 1) { primary.value = guides[0].numero_guia_despacho; writeTotals(guideTotals(guides), false); guides = []; }
        render();
    });
    return { reset(saved = []) { guides = saved.length > 1 ? saved : []; render(); },
        refresh() { if (guides.length > 1) guides = read(); render(); },
        multiple: () => guides.length > 1, payload: () => guides.length > 1 ? read() : null };
}
