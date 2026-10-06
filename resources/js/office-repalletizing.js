const byId = (id) => document.getElementById(id);
const elements = {
    access: byId('officeAccess'), app: byId('officeApp'), login: byId('officeLoginForm'),
    loginError: byId('officeLoginError'), logout: byId('officeLogoutButton'),
    userName: byId('officeUserName'), userRole: byId('officeUserRole'), initials: byId('officeInitials'),
    reload: byId('reloadButton'), form: byId('repaForm'), sourceInput: byId('sourceFolioInput'),
    addSource: byId('addSourceButton'), sourceError: byId('sourceError'), repaError: byId('repaError'),
    sourceList: byId('sourceList'), sourceOverview: byId('sourceOverview'), sourceCount: byId('sourceCount'), keptField: byId('keptFolioField'),
    newField: byId('newFolioField'), hardRule: byId('hardRule'), mixWarnings: byId('mixWarnings'),
    previewFolio: byId('previewFolio'), previewType: byId('previewType'),
    previewQuantity: byId('previewQuantity'), previewThermal: byId('previewThermal'),
    specPreview: byId('specPreview'), clear: byId('clearButton'), history: byId('historyList'),
    historyFilter: byId('historyFilter'), loading: byId('officeLoading'),
    loadingText: byId('officeLoadingText'), toasts: byId('officeToasts'),
    consolidationFields: byId('consolidationFields'), transformFields: byId('transformFields'),
    secondNumber: byId('secondResultNumberField'), secondType: byId('secondResultTypeField'),
    secondTarget: byId('secondResultTargetField'), divisionEditor: byId('divisionEditor'),
    sourceInputLabel: byId('sourceInputLabel'),
    registerDate: byId('registerDate'), registerList: byId('registerList'),
    registerBlank: byId('registerBlank'), registerDay: byId('registerDay'),
    operationalDate: byId('operationalDateField'),
};
const keys = { token: 'estiba_wms_office_token', identity: 'estiba_wms_office_identity' };
const state = {
    token: localStorage.getItem(keys.token),
    identity: readJson(keys.identity),
    sources: [],
    expandedSources: new Set(),
    history: [],
};
const hardFields = [
    ['cliente', 'cliente'], ['especie', 'especie'], ['marca', 'marca'],
    ['condicion_termica', 'estado térmico'],
];
const mixFields = [
    ['variedad', 'Variedad'], ['calibre', 'Calibre'], ['envase', 'Envase'],
    ['categoria', 'Categoría'], ['csg', 'CSG'], ['predio', 'Predio'], ['cuartel', 'Cuartel'],
];

class ApiError extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
}
function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}
function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
function uuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
function errorMessage(data, fallback) {
    return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback;
}
function setBusy(active, text = 'Procesando…') {
    elements.loadingText.textContent = text;
    elements.loading.classList.toggle('is-hidden', !active);
    elements.loading.setAttribute('aria-hidden', String(!active));
}
function toast(message, error = false) {
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    elements.toasts.append(item);
    window.setTimeout(() => item.remove(), 5000);
}
async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    if (options.body) headers.set('Content-Type', 'application/json');
    let response;
    try { response = await fetch(path, { ...options, headers }); }
    catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    const data = response.status === 204 ? null : await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/api/acceso-oficina') clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible completar la operación.'), response.status);
    }
    return data;
}
async function download(path) {
    const headers = new Headers({ Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    if (state.token) headers.set('Authorization', `Bearer ${state.token}`);
    let response;
    try { response = await fetch(path, { headers }); } catch { throw new ApiError('No fue posible conectar con Laravel.'); }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) clearSession();
        throw new ApiError(errorMessage(data, 'No fue posible generar el registro RRPL-01.'), response.status);
    }
    const blob = await response.blob();
    const match = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/i);
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = match?.[1] || 'RRPL-01.xlsx';
    document.body.append(anchor); anchor.click(); anchor.remove();
    URL.revokeObjectURL(url);
}
function persist(payload) {
    state.token = payload.token; state.identity = payload.usuario;
    localStorage.setItem(keys.token, payload.token);
    localStorage.setItem(keys.identity, JSON.stringify(payload.usuario));
}
function clearSession() {
    state.token = null; state.identity = null; state.sources = []; state.expandedSources.clear(); state.history = [];
    localStorage.removeItem(keys.token); localStorage.removeItem(keys.identity);
    elements.app.classList.add('is-hidden'); elements.access.classList.remove('is-hidden');
}
function can(key) {
    return state.identity?.[key] === true || state.identity?.capacidades?.[key] === true;
}
function showApp() {
    if (!can('puede_consultar_repaletizajes')) return false;
    elements.access.classList.add('is-hidden'); elements.app.classList.remove('is-hidden');
    const name = state.identity?.nombre || 'Usuario';
    elements.userName.textContent = name;
    elements.userRole.textContent = String(state.identity?.rol || 'Oficina').replaceAll('_', ' ');
    elements.initials.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2)
        .map((part) => part[0]).join('').toUpperCase();
    elements.form.querySelector('button[type="submit"]').disabled = !can('puede_registrar_repaletizajes');
    return true;
}
function formValue(name) { return elements.form.elements[name]?.value ?? ''; }
function updateOperationalDate() {
    const nightShift = formValue('turno') === 'B';
    elements.operationalDate.classList.toggle('is-hidden', !nightShift);
    if (!nightShift) elements.form.elements.fecha_operacional.value = 'hoy';
}
function mode() { return formValue('modalidad') || 'consolidacion'; }
function normalized(value) { return String(value ?? '').trim().toLocaleUpperCase('es-CL'); }
function common(field) {
    if (!state.sources.length) return '—';
    const values = [...new Set(state.sources.map((source) => normalized(source[field])))];
    return values.length === 1 ? state.sources[0]?.[field] ?? '—' : 'MIX';
}
function hardMismatch() {
    if (state.sources.length < 2) return [];
    return hardFields.filter(([field]) => (
        new Set(state.sources.map((source) => normalized(source[field]))).size > 1
    ));
}
function total() {
    return state.sources.reduce((sum, source) => sum + sourceTotal(source), 0);
}
function sourceTotal(source) {
    return (source.composicion || []).reduce((sum, line) => sum + Number(line.aporte || 0), 0);
}
function sourceCompositionCommon(source, field) {
    const lines = source.composicion || [];
    if (!lines.length) return '—';
    const values = [...new Set(lines.map((line) => normalized(line[field])).filter(Boolean))];
    if (!values.length) return '—';
    return values.length === 1 ? (lines[0]?.[field] || '—') : 'MIX';
}
function renderSourceCard(source, transform) {
    const lines = source.composicion || [];
    const singleLine = lines.length === 1 ? lines[0] : null;
    const expanded = state.expandedSources.has(source.id);
    const csg = sourceCompositionCommon(source, 'csg');
    const date = sourceCompositionCommon(source, 'fecha_embalaje');
    const predio = sourceCompositionCommon(source, 'predio');
    const contributed = sourceTotal(source);
    const remaining = Math.max(0, source.cantidad_cajas - contributed);
    const contribution = !transform && singleLine
        ? `<input class="source-amount-input" data-composition-source="${escapeHtml(source.id)}" data-composition-key="${escapeHtml(singleLine.clave)}" aria-label="Cajas aportadas por el folio ${escapeHtml(source.numero_folio)}" type="number" min="0" max="${singleLine.cantidad_cajas}" value="${singleLine.aporte}">`
        : `<span class="source-value">${transform ? source.cantidad_cajas : contributed}</span>`;
    const lineLabel = `${lines.length} ${lines.length === 1 ? 'composición' : 'composiciones'}`;

    return `<article class="source-card${expanded ? ' is-expanded' : ''}">
        <div class="source-card__row">
            <div class="source-identity"><strong>${escapeHtml(source.numero_folio)}</strong>
                <small>${escapeHtml(source.cliente)} · ${escapeHtml(source.especie)} · ${escapeHtml(source.marca)}</small>
                <small>Calibre ${escapeHtml(source.calibre)} · ${escapeHtml(source.condicion_termica)}</small>
            </div>
            <div class="source-batch"><span>LOTE / FECHA</span>
                <strong class="${csg === 'MIX' ? 'is-mix' : ''}">CSG ${escapeHtml(csg)}</strong>
                <small class="${date === 'MIX' ? 'is-mix' : ''}">${date === 'MIX' ? 'Fechas MIX' : `Fecha ${escapeHtml(date)}`} · ${lineLabel}</small>
            </div>
            <label>DISPONIBLE<span class="source-value">${source.cantidad_cajas}</span></label>
            <label>APORTA${contribution}</label>
            <label>QUEDA<span class="source-value">${remaining}</span></label>
            <button class="source-remove" data-remove="${escapeHtml(source.id)}" aria-label="Quitar folio ${escapeHtml(source.numero_folio)}" type="button">Quitar</button>
        </div>
        <div class="source-card__footer">
            <button class="source-details-toggle" data-toggle-composition="${escapeHtml(source.id)}" aria-expanded="${expanded}" type="button">
                <span aria-hidden="true">${expanded ? '▴' : '▾'}</span> ${expanded ? 'Ocultar' : 'Ver'} composición (${lines.length})
            </button>
            <span>${predio === 'MIX' ? 'Predios MIX' : escapeHtml(predio)}</span>
        </div>
        <div class="composition-lines${expanded ? '' : ' is-collapsed'}">
            ${lines.map((line) => `<label class="composition-line">
                <span><b>CSG ${escapeHtml(line.csg)}</b>${line.predio ? ` · ${escapeHtml(line.predio)}` : ''}<small>${line.fecha_embalaje ? `Fecha ${escapeHtml(line.fecha_embalaje)}` : 'Fecha no registrada'} · ${line.cantidad_cajas} cajas disponibles</small></span>
                ${transform ? `<b>${line.cantidad_cajas} cajas</b>` : (singleLine
                    ? `<b>Aporta ${line.aporte} de ${line.cantidad_cajas}</b>`
                    : `<input data-composition-source="${escapeHtml(source.id)}" data-composition-key="${escapeHtml(line.clave)}" type="number" min="0" max="${line.cantidad_cajas}" value="${line.aporte}">`)}
            </label>`).join('') || '<p class="empty-copy">Sin composición registrada.</p>'}
        </div>
    </article>`;
}
function resultComposition() {
    const grouped = new Map();
    state.sources.flatMap((source) => source.composicion || []).forEach((line) => {
        const amount = Number(line.aporte || 0);
        if (!amount) return;
        const key = `${line.csg || ''}|${line.predio || ''}|${line.fecha_embalaje || ''}`;
        const current = grouped.get(key) || { ...line, cantidad_cajas: 0 };
        current.cantidad_cajas += amount;
        grouped.set(key, current);
    });
    return [...grouped.values()];
}
function compositionCommon(field) {
    const lines = resultComposition();
    if (!lines.length) return '—';
    const values = [...new Set(lines.map((line) => normalized(line[field])))];
    return values.length === 1 ? (lines[0]?.[field] || '—') : 'MIX';
}
function resultNumber() {
    if (mode() !== 'consolidacion') {
        const first = normalized(formValue('resultado_1_numero'));
        const second = mode() === 'division' ? normalized(formValue('resultado_2_numero')) : '';
        return [first, second].filter(Boolean).join(' + ');
    }
    if (formValue('estrategia_folio') === 'conservar') {
        return state.sources.find((source) => source.id === formValue('folio_conservado_id'))?.numero_folio || '';
    }
    return normalized(formValue('numero_folio_resultante'));
}
function divisionTotal(source, output) {
    return (source?.composicion || []).reduce(
        (sum, line) => sum + Number(line[`salida${output}`] || 0),
        0,
    );
}
function refreshDivisionTotals() {
    const source = state.sources[0];
    if (!source) return;
    [1, 2].forEach((output) => {
        const totalElement = elements.divisionEditor.querySelector(`[data-division-total="${output}"]`);
        if (totalElement) totalElement.textContent = `${divisionTotal(source, output)} cajas`;
    });
    elements.previewQuantity.textContent = `${divisionTotal(source, 1)} + ${divisionTotal(source, 2)} cajas`;
}
function render() {
    updateOperationalDate();
    const sourceScrollTop = elements.sourceList.scrollTop;
    const activeComposition = document.activeElement?.matches('[data-composition-source]') ? {
        source: document.activeElement.dataset.compositionSource,
        key: document.activeElement.dataset.compositionKey,
    } : null;
    const modality = mode();
    const transform = modality !== 'consolidacion';
    const division = modality === 'division';
    const strategy = formValue('estrategia_folio');
    const type = formValue('tipo_resultado');
    const target = Number(formValue('cantidad_objetivo') || 0);
    elements.consolidationFields.classList.toggle('is-hidden', transform);
    elements.transformFields.classList.toggle('is-hidden', !transform);
    elements.secondNumber.classList.toggle('is-hidden', !division);
    elements.secondType.classList.toggle('is-hidden', !division);
    elements.secondTarget.classList.toggle('is-hidden', !division);
    elements.divisionEditor.classList.toggle('is-hidden', !division || !state.sources.length);
    elements.sourceInputLabel.textContent = transform ? 'Agregar pallet o saldo por folio' : 'Agregar saldo por folio';
    elements.keptField.classList.toggle('is-hidden', transform || strategy !== 'conservar');
    elements.newField.classList.toggle('is-hidden', transform || strategy !== 'nuevo');

    const select = elements.form.elements.folio_conservado_id;
    const selected = select.value;
    select.innerHTML = `<option value="">Seleccionar</option>${state.sources.map((source) => (
        `<option value="${escapeHtml(source.id)}">${escapeHtml(source.numero_folio)} · ${source.cantidad_cajas} cajas</option>`
    )).join('')}`;
    if (state.sources.some((source) => source.id === selected)) select.value = selected;
    else if (state.sources.length) select.value = state.sources[0].id;

    const contributed = total();
    const dates = new Set(state.sources.flatMap((source) => source.composicion || [])
        .map((line) => normalized(line.fecha_embalaje)).filter(Boolean));
    const compositions = state.sources.reduce((sum, source) => sum + (source.composicion || []).length, 0);
    const capacityText = modality === 'consolidacion' && type === 'pallet'
        ? `${contributed} / ${target || '—'}`
        : `${contributed}`;
    const capacityComplete = modality === 'consolidacion' && type === 'pallet'
        && target > 0 && contributed === target;
    const capacityOverview = modality === 'consolidacion' && type === 'pallet'
        ? `<span class="${capacityComplete ? 'is-complete' : ''}"><strong>${capacityText}</strong> capacidad</span>`
        : '';
    elements.sourceCount.textContent = `${state.sources.length} folio${state.sources.length === 1 ? '' : 's'}`;
    elements.sourceOverview.classList.toggle('is-hidden', !state.sources.length);
    elements.sourceOverview.innerHTML = state.sources.length ? `
        <span><strong>${state.sources.length}</strong> folios</span>
        <span><strong>${contributed}</strong> cajas aportadas</span>
        ${capacityOverview}
        <span><strong>${dates.size}</strong> fechas</span>
        <span><strong>${compositions}</strong> composiciones</span>` : '';
    elements.sourceList.classList.toggle('has-many', state.sources.length > 5);
    elements.sourceList.innerHTML = state.sources.length
        ? state.sources.map((source) => renderSourceCard(source, transform)).join('')
        : `<p class="empty-copy">${transform ? 'Agrega un pallet o saldo.' : 'Agrega al menos dos folios tipo saldo.'}</p>`;
    elements.sourceList.scrollTop = sourceScrollTop;
    if (activeComposition) {
        const input = [...elements.sourceList.querySelectorAll('[data-composition-source]')].find((candidate) => (
            candidate.dataset.compositionSource === activeComposition.source
            && candidate.dataset.compositionKey === activeComposition.key
        ));
        input?.focus({ preventScroll: true });
    }

    elements.divisionEditor.innerHTML = division && state.sources[0] ? `
        <article class="division-card">
            <header class="division-heading">
                <div><strong>Distribución exacta por composición</strong><small>Edita una salida y la otra se ajustará automáticamente.</small></div>
                <div class="division-totals">
                    <span><small>SALIDA 1</small><strong data-division-total="1">${divisionTotal(state.sources[0], 1)} cajas</strong></span>
                    <span><small>SALIDA 2</small><strong data-division-total="2">${divisionTotal(state.sources[0], 2)} cajas</strong></span>
                </div>
            </header>
            <div class="division-lines">${state.sources[0].composicion.map((line) => `<div class="division-line">
                <div class="division-line__identity">
                    <strong>CSG ${escapeHtml(line.csg)}</strong>
                    <small>${line.predio ? `${escapeHtml(line.predio)} · ` : ''}Fecha ${escapeHtml(line.fecha_embalaje || 'Sin fecha')} · ${line.cantidad_cajas} cajas</small>
                </div>
                <div class="division-line__outputs">
                    <label><span>SALIDA 1 · ${escapeHtml(normalized(formValue('resultado_1_numero')) || 'FOLIO 1')}</span><input data-division-output="1" data-composition-key="${escapeHtml(line.clave)}" type="number" min="0" max="${line.cantidad_cajas}" value="${line.salida1 ?? line.cantidad_cajas}"></label>
                    <label><span>SALIDA 2 · ${escapeHtml(normalized(formValue('resultado_2_numero')) || 'FOLIO 2')}</span><input data-division-output="2" data-composition-key="${escapeHtml(line.clave)}" type="number" min="0" max="${line.cantidad_cajas}" value="${line.salida2 ?? 0}"></label>
                </div>
                <span class="division-line__check" data-division-line-total>Total distribuido: ${Number(line.salida1 || 0) + Number(line.salida2 || 0)} de ${line.cantidad_cajas} cajas</span>
            </div>`).join('')}</div>
        </article>` : '';

    const mismatches = hardMismatch();
    elements.hardRule.classList.toggle('is-invalid', mismatches.length > 0);
    elements.hardRule.querySelector('strong').textContent = mismatches.length
        ? `INCOMPATIBLE: ${mismatches.map(([, label]) => label).join(', ')}`
        : 'Compatibilidad obligatoria';
    const mixes = mixFields.filter(([field]) => state.sources.length > 1 && (
        field === 'csg' || field === 'predio' ? compositionCommon(field) === 'MIX' : common(field) === 'MIX'
    ));
    if (compositionCommon('fecha_embalaje') === 'MIX') mixes.push(['fecha_embalaje', 'Fecha de embalaje']);
    elements.mixWarnings.classList.toggle('is-hidden', !mixes.length);
    elements.mixWarnings.innerHTML = mixes.map(([, label]) => (
        `<span>⚠ MIX ${escapeHtml(label.toUpperCase())}</span>`
    )).join('');

    elements.previewFolio.textContent = resultNumber() || 'Sin definir';
    elements.previewType.textContent = modality === 'cambio_folio' ? 'Cambio de folio' : (division ? 'Dos resultados' : (type === 'pallet' ? 'Pallet completo' : 'Saldo consolidado'));
    if (division && state.sources[0]) {
        elements.previewQuantity.textContent = `${divisionTotal(state.sources[0], 1)} + ${divisionTotal(state.sources[0], 2)} cajas`;
    } else {
        elements.previewQuantity.textContent = transform ? `${state.sources[0]?.cantidad_cajas || 0} cajas` : (type === 'pallet' ? `${total()} / ${target || '—'}` : `${total()} cajas`);
    }
    elements.previewThermal.textContent = state.sources.length ? common('condicion_termica') : '—';
    const specs = [
        ['Cliente', common('cliente')], ['Especie', common('especie')], ['Marca', common('marca')],
        ...mixFields.map(([field, label]) => [label, field === 'csg' || field === 'predio' ? compositionCommon(field) : common(field)]),
        ['Fecha de embalaje', compositionCommon('fecha_embalaje')],
    ];
    const composition = resultComposition();
    elements.specPreview.innerHTML = state.sources.length ? specs.map(([label, value]) => (
        `<div><span>${escapeHtml(label.toUpperCase())}</span><strong class="${value === 'MIX' ? 'is-mix' : ''}">${escapeHtml(value || '—')}</strong></div>`
    )).join('') + `<section class="composition-preview"><span>COMPOSICIÓN RESULTANTE</span>${composition.map((line) => `<p><strong>CSG ${escapeHtml(line.csg)}</strong><b>${escapeHtml(line.fecha_embalaje || 'Sin fecha')} · ${line.cantidad_cajas} cajas</b></p>`).join('') || '<p>Sin cajas asignadas.</p>'}</section>` : '';
}
async function addSource() {
    const number = normalized(elements.sourceInput.value);
    elements.sourceError.textContent = '';
    if (!number) { elements.sourceError.textContent = 'Escanea o escribe un folio.'; return; }
    if (state.sources.some((source) => source.numero_folio === number)) {
        elements.sourceError.textContent = 'Ese folio ya fue agregado.'; return;
    }
    if (mode() !== 'consolidacion' && state.sources.length) {
        elements.sourceError.textContent = 'El cambio y la división reciben exactamente un folio.'; return;
    }
    setBusy(true, 'Buscando saldo…');
    try {
        const source = await api(`/api/validacion/repaletizajes/folios/${encodeURIComponent(number)}`);
        if (!source.existe) throw new ApiError('El folio no existe.');
        const acceptedTypes = mode() === 'consolidacion' ? ['saldo'] : ['saldo', 'pallet'];
        if (!source.activo || !acceptedTypes.includes(source.tipo_bulto) || source.cantidad_cajas < 1) {
            throw new ApiError(mode() === 'consolidacion'
                ? 'El folio no es un saldo activo con cajas disponibles.'
                : 'El folio no es un pallet o saldo activo con cajas disponibles.');
        }
        if (!['pendiente_prefrio', 'prefrio_aprobado'].includes(source.condicion_termica)) {
            throw new ApiError('El folio posee un estado térmico transitorio o retenido.');
        }
        const target = Number(formValue('cantidad_objetivo') || 0);
        const remaining = Math.max(0, target - total());
        const amount = mode() !== 'consolidacion' ? source.cantidad_cajas : formValue('tipo_resultado') === 'pallet'
            ? Math.min(source.cantidad_cajas, remaining || source.cantidad_cajas)
            : source.cantidad_cajas;
        let remainingAmount = amount;
        const composition = (source.composicion || []).map((line) => {
            const contribution = Math.min(line.cantidad_cajas, remainingAmount);
            remainingAmount -= contribution;
            return { ...line, aporte: contribution, salida1: line.cantidad_cajas, salida2: 0 };
        });
        if (mode() === 'cambio_folio') {
            elements.form.elements.resultado_1_tipo.value = source.tipo_bulto;
            if (source.tipo_bulto === 'pallet') {
                elements.form.elements.resultado_1_objetivo.value = source.cantidad_cajas;
            }
        }
        state.sources.push({ ...source, composicion: composition });
        elements.sourceInput.value = '';
        render();
        if (elements.sourceList.classList.contains('has-many')) {
            elements.sourceList.scrollTop = elements.sourceList.scrollHeight;
        }
    } catch (error) { elements.sourceError.textContent = error.message; }
    finally { setBusy(false); }
}
function reset() {
    const shift = formValue('turno');
    const day = formValue('fecha_operacional');
    state.sources = []; state.expandedSources.clear(); elements.form.reset();
    elements.form.elements.cantidad_objetivo.value = 120;
    // El turno se mantiene entre repas consecutivas.
    if (shift) elements.form.elements.turno.value = shift;
    if (shift === 'B') elements.form.elements.fecha_operacional.value = day;
    updateOperationalDate();
    elements.sourceError.textContent = ''; elements.repaError.textContent = '';
    render();
}
async function submit() {
    elements.repaError.textContent = '';
    const modality = mode();
    if (modality !== 'consolidacion') { await submitTransformation(modality); return; }
    if (state.sources.length < 2) { elements.repaError.textContent = 'Agrega al menos dos saldos.'; return; }
    if (hardMismatch().length) {
        elements.repaError.textContent = 'Cliente, especie, marca y estado térmico deben ser idénticos.'; return;
    }
    if (state.sources.some((source) => sourceTotal(source) < 1)) {
        elements.repaError.textContent = 'Cada saldo debe aportar al menos una caja de su composición.'; return;
    }
    const type = formValue('tipo_resultado');
    const target = Number(formValue('cantidad_objetivo') || 0);
    if (type === 'pallet' && (!target || total() !== target)) {
        elements.repaError.textContent = `El pallet debe completar exactamente ${target || 'la capacidad indicada'} cajas.`; return;
    }
    if (type === 'saldo' && target && total() >= target) {
        elements.repaError.textContent = 'Un saldo debe quedar bajo la capacidad completa.'; return;
    }
    const strategy = formValue('estrategia_folio');
    const number = resultNumber();
    if (!number) { elements.repaError.textContent = 'Define el folio resultante.'; return; }
    const kept = strategy === 'conservar' ? formValue('folio_conservado_id') : null;
    if (kept) {
        const source = state.sources.find((item) => item.id === kept);
        if (source && sourceTotal(source) !== source.cantidad_cajas) {
            elements.repaError.textContent = 'El folio conservado debe aportar todas sus cajas.'; return;
        }
    }
    setBusy(true, 'Confirmando repaletizaje…');
    try {
        const response = await api('/api/validacion/repaletizajes', {
            method: 'POST',
            body: JSON.stringify({
                operacion_id: uuid(), modalidad: 'consolidacion', tipo_resultado: type, estrategia_folio: strategy,
                numero_folio_resultante: number, folio_conservado_id: kept,
                cantidad_objetivo: target || null,
                origenes: state.sources.map((source) => ({
                    folio_id: source.id,
                    cantidad_aportada: sourceTotal(source),
                    composicion: source.composicion.filter((line) => Number(line.aporte) > 0).map((line) => ({
                        clave: line.clave,
                        cantidad_aportada: Number(line.aporte),
                    })),
                })),
                observacion: String(formValue('observacion') || '').trim() || null,
                turno: formValue('turno') || 'A',
                fecha_operacional: formValue('turno') === 'B' ? formValue('fecha_operacional') : 'hoy',
            }),
        });
        toast(`${response.data.codigo}: ${response.data.folio_resultante.numero_folio} confirmado.`);
        reset(); await loadHistory();
    } catch (error) { elements.repaError.textContent = error.message; }
    finally { setBusy(false); }
}
async function submitTransformation(modality) {
    if (state.sources.length !== 1) { elements.repaError.textContent = 'Agrega exactamente un pallet o saldo.'; return; }
    const source = state.sources[0];
    const count = modality === 'division' ? 2 : 1;
    const numbers = Array.from({ length: count }, (_, index) => normalized(formValue(`resultado_${index + 1}_numero`)));
    if (numbers.some((number) => !number) || new Set(numbers).size !== numbers.length) {
        elements.repaError.textContent = 'Define folios nuevos y diferentes para cada resultado.'; return;
    }
    const results = Array.from({ length: count }, (_, index) => {
        const output = index + 1;
        const composition = modality === 'division' ? source.composicion
            .map((line) => ({ clave: line.clave, cantidad_cajas: Number(line[`salida${output}`] || 0) }))
            .filter((line) => line.cantidad_cajas > 0) : [];
        const quantity = modality === 'division'
            ? composition.reduce((sum, line) => sum + line.cantidad_cajas, 0)
            : source.cantidad_cajas;
        const type = formValue(`resultado_${output}_tipo`);
        const target = Number(formValue(`resultado_${output}_objetivo`) || 0);
        return {
            numero_folio: numbers[index], tipo_resultado: type,
            cantidad_objetivo: target || null, cantidad_resultante: quantity,
            ...(modality === 'division' ? { composicion: composition } : {}),
        };
    });
    if (results.some((result) => result.cantidad_resultante < 1)) {
        elements.repaError.textContent = 'Cada salida debe recibir al menos una caja.'; return;
    }
    if (modality === 'division' && source.composicion.some((line) => (
        Number(line.salida1 || 0) + Number(line.salida2 || 0) !== line.cantidad_cajas
    ))) {
        elements.repaError.textContent = 'Distribuye exactamente todas las cajas de cada CSG y fecha.'; return;
    }
    for (const result of results) {
        if (result.tipo_resultado === 'pallet' && (!result.cantidad_objetivo || result.cantidad_resultante !== result.cantidad_objetivo)) {
            elements.repaError.textContent = 'Cada resultado pallet debe completar exactamente su capacidad.'; return;
        }
        if (result.tipo_resultado === 'saldo' && result.cantidad_objetivo && result.cantidad_resultante >= result.cantidad_objetivo) {
            elements.repaError.textContent = 'Cada saldo debe quedar bajo la capacidad indicada.'; return;
        }
    }
    setBusy(true, modality === 'division' ? 'Dividiendo folio…' : 'Cambiando folio…');
    try {
        const response = await api('/api/validacion/repaletizajes', {
            method: 'POST', body: JSON.stringify({
                operacion_id: uuid(), modalidad: modality,
                origenes: [{ folio_id: source.id, cantidad_aportada: source.cantidad_cajas }],
                resultados: results,
                observacion: String(formValue('observacion') || '').trim() || null,
                turno: formValue('turno') || 'A',
                fecha_operacional: formValue('turno') === 'B' ? formValue('fecha_operacional') : 'hoy',
            }),
        });
        const folios = response.data.resultados.map((result) => result.folio.numero_folio).join(' + ');
        toast(`${response.data.codigo}: ${folios} confirmados.`);
        reset(); await loadHistory();
    } catch (error) { elements.repaError.textContent = error.message; }
    finally { setBusy(false); }
}
function renderHistory() {
    elements.history.innerHTML = state.history.length ? state.history.map((repa) => (
        `<article class="repa-history-card${repa.estado === 'anulado' ? ' is-void' : ''}">
            <header><h3>${escapeHtml(repa.codigo)} · ${(repa.resultados || []).map((result) => escapeHtml(result.folio?.numero_folio)).join(' + ') || escapeHtml(repa.folio_resultante?.numero_folio)}</h3><span>${escapeHtml(repa.estado)}</span></header>
            <p>${repa.tipo_resultado === 'pallet' ? 'Pallet completo' : (repa.tipo_resultado === 'division' ? 'División en dos folios' : 'Saldo')} · ${repa.cantidad_resultante} cajas · ${escapeHtml(repa.condicion_termica)}</p>
            ${repa.advertencias?.length ? `<p>⚠ ${repa.advertencias.map((item) => escapeHtml(item.campo)).join(' · ')}</p>` : ''}
            <details><summary>Composición (${repa.origenes.length})</summary>
                ${repa.origenes.map((origin) => `<div class="repa-origin"><span>${escapeHtml(origin.folio.numero_folio)}</span><strong>${origin.cajas_aportadas} cajas</strong></div>`).join('')}
                ${(repa.folio_resultante?.composicion || []).map((line) => `<div class="repa-origin"><span>CSG ${escapeHtml(line.csg)} · ${escapeHtml(line.fecha_embalaje || 'Sin fecha')}</span><strong>${line.cantidad_cajas} cajas</strong></div>`).join('')}
            </details>
            ${repa.estado === 'confirmado' && (can('puede_imprimir_etiquetas_pt') || can('puede_consultar_repaletizajes')) ? (repa.resultados || []).map((result) => result.folio?.activo ? `<a class="secondary-button" href="/oficina/validacion/etiquetas?folio=${encodeURIComponent(result.folio.numero_folio)}&tipo=planta&copias=4">Imprimir etiquetas · ${escapeHtml(result.folio.numero_folio)}</a>` : '').join('') : ''}
            ${can('puede_anular_repaletizajes') && repa.puede_anular ? `<button data-annul="${escapeHtml(repa.id)}" type="button">Anular repa</button>` : ''}
        </article>`
    )).join('') : '<p class="empty-copy">No existen repaletizajes para esta selección.</p>';
}
function registerDate() {
    return elements.registerDate.value;
}
function renderRegisters(sheets) {
    elements.registerDay.disabled = !sheets.length;
    elements.registerList.innerHTML = sheets.length ? sheets.map((sheet) => {
        const params = new URLSearchParams({ fecha: sheet.fecha });
        if (sheet.turno) params.set('turno', sheet.turno);
        if (sheet.tarjador?.id) params.set('user_id', String(sheet.tarjador.id));
        const voided = sheet.anuladas ? ` · ${sheet.anuladas} ${sheet.anuladas === 1 ? 'anulada' : 'anuladas'}` : '';
        return `<div class="repa-register-row">
            <div><strong>Turno ${escapeHtml(sheet.turno || '—')} · ${escapeHtml(sheet.tarjador?.nombre || 'Sin usuario')}</strong><span>${sheet.repas} ${sheet.repas === 1 ? 'repa' : 'repas'}${voided}</span></div>
            <button class="secondary-button" data-register="${escapeHtml(params.toString())}" type="button">Descargar</button>
        </div>`;
    }).join('') : '<p class="empty-copy">No existen repas registradas para esta fecha.</p>';
}
async function loadRegisters() {
    const date = registerDate();
    const response = await api(`/api/validacion/repaletizajes/registro/rrpl-01/planillas${date ? `?fecha=${encodeURIComponent(date)}` : ''}`);
    if (!date) elements.registerDate.value = response.fecha;
    renderRegisters(response.data || []);
}
async function downloadRegister(query) {
    setBusy(true, 'Generando registro RRPL-01…');
    try { await download(`/api/validacion/repaletizajes/registro/rrpl-01?${query}`); }
    catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}
async function loadHistory() {
    const query = new URLSearchParams({ per_page: '50' });
    if (elements.historyFilter.value.trim()) query.set('folio', elements.historyFilter.value.trim());
    const response = await api(`/api/validacion/repaletizajes?${query}`);
    state.history = response.data || []; renderHistory();
    await loadRegisters().catch((error) => toast(error.message, true));
}
async function annul(id) {
    const reason = window.prompt('Motivo de anulación (mínimo 5 caracteres):');
    if (reason === null) return;
    if (reason.trim().length < 5) { toast('El motivo es demasiado breve.', true); return; }
    setBusy(true, 'Anulando repa…');
    try {
        await api(`/api/validacion/repaletizajes/${id}/anular`, {
            method: 'POST', body: JSON.stringify({ operacion_id: uuid(), motivo: reason.trim() }),
        });
        toast('Repaletizaje anulado y cantidades restauradas.'); await loadHistory();
    } catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
}

elements.login.addEventListener('submit', async (event) => {
    event.preventDefault(); elements.loginError.textContent = ''; setBusy(true, 'Validando acceso…');
    try {
        const data = new FormData(elements.login);
        const payload = await api('/api/acceso-oficina', {
            method: 'POST', body: JSON.stringify({ email: data.get('email'), password: data.get('password') }),
        });
        persist(payload);
        if (!showApp()) { clearSession(); throw new ApiError('Tu perfil no tiene acceso a repaletizajes.', 403); }
        await loadHistory();
    } catch (error) { elements.loginError.textContent = error.message; }
    finally { setBusy(false); }
});
elements.logout.addEventListener('click', async () => {
    try { await api('/api/acceso-oficina', { method: 'DELETE' }); } catch {}
    clearSession();
});
elements.addSource.addEventListener('click', () => void addSource());
elements.sourceInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); void addSource(); }
});
elements.form.addEventListener('change', render);
elements.form.elements.modalidad.addEventListener('change', () => {
    state.sources = [];
    state.expandedSources.clear();
    elements.sourceError.textContent = '';
    elements.repaError.textContent = '';
    render();
});
elements.form.addEventListener('input', (event) => {
    if (event.target.matches('[data-division-output]')) return;
    if (!event.target.matches('[data-composition-source]')) { render(); return; }
    const source = state.sources.find((item) => item.id === event.target.dataset.compositionSource);
    const line = source?.composicion.find((item) => item.clave === event.target.dataset.compositionKey);
    if (line) line.aporte = Math.min(line.cantidad_cajas, Math.max(0, Number(event.target.value || 0)));
    render();
});
elements.sourceList.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove]');
    if (remove) {
        state.sources = state.sources.filter((source) => source.id !== remove.dataset.remove);
        state.expandedSources.delete(remove.dataset.remove);
        render(); return;
    }
    const toggle = event.target.closest('[data-toggle-composition]');
    if (!toggle) return;
    const id = toggle.dataset.toggleComposition;
    if (state.expandedSources.has(id)) state.expandedSources.delete(id);
    else state.expandedSources.add(id);
    render();
});
elements.divisionEditor.addEventListener('input', (event) => {
    if (!event.target.matches('[data-division-output]') || !state.sources[0]) return;
    const line = state.sources[0].composicion.find((item) => item.clave === event.target.dataset.compositionKey);
    if (!line) return;
    const output = Number(event.target.dataset.divisionOutput);
    const otherOutput = output === 1 ? 2 : 1;
    const value = Math.min(line.cantidad_cajas, Math.max(0, Number(event.target.value || 0)));
    line[`salida${output}`] = value;
    line[`salida${otherOutput}`] = line.cantidad_cajas - value;
    event.target.value = String(value);
    const counterpart = [...elements.divisionEditor.querySelectorAll('[data-division-output]')].find((input) => (
        input.dataset.compositionKey === line.clave
        && Number(input.dataset.divisionOutput) === otherOutput
    ));
    if (counterpart) counterpart.value = String(line[`salida${otherOutput}`]);
    const lineTotal = event.target.closest('.division-line')?.querySelector('[data-division-line-total]');
    if (lineTotal) lineTotal.textContent = `Total distribuido: ${line.cantidad_cajas} de ${line.cantidad_cajas} cajas ✓`;
    refreshDivisionTotals();
});
elements.form.addEventListener('submit', (event) => { event.preventDefault(); void submit(); });
elements.clear.addEventListener('click', reset);
elements.reload.addEventListener('click', () => void loadHistory());
elements.historyFilter.addEventListener('change', () => void loadHistory());
elements.registerDate.addEventListener('change', () => void loadRegisters().catch((error) => toast(error.message, true)));
elements.registerList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-register]');
    if (button) void downloadRegister(button.dataset.register);
});
elements.registerDay.addEventListener('click', () => void downloadRegister(new URLSearchParams({ fecha: registerDate() }).toString()));
elements.registerBlank.addEventListener('click', async () => {
    setBusy(true, 'Preparando RRPL-01 en blanco…');
    try { await download('/api/validacion/repaletizajes/registro/rrpl-01/en-blanco'); }
    catch (error) { toast(error.message, true); }
    finally { setBusy(false); }
});
elements.history.addEventListener('click', (event) => {
    const button = event.target.closest('[data-annul]'); if (button) void annul(button.dataset.annul);
});

render();
if (state.token && state.identity && showApp()) void loadHistory(); else clearSession();
