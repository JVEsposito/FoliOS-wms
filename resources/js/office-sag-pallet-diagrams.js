import {
    LIMITES, cargaDiagrama, clasificarNumeros, corridasPallet, erroresPallet, firmaEstructura, interpretarNumeros,
    laminaSvg, marcasPorPallet, numerar, palletVacio, palletsIniciales, tituloDiagrama, totalPallet, ubicarCaja,
} from './shared/sag-pallet-diagram.js';
import { aplicarPresample, folioDeRangos } from './shared/sag-presample.js';

const $ = (id) => document.getElementById(id);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const miles = (n) => new Intl.NumberFormat('es-CL').format(n);
const fechaHora = (value) => value ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' }).format(new Date(value)) : '—';
function operationId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

const url = '/api/sag/diagramas';
const form = $('pdForm');
const CAMPOS = ['tipo', 'numero', 'csp', 'folio', 'fecha', 'lote', 'especie', 'exportadora'];
const state = {
    token: localStorage.getItem('estiba_wms_office_token'), catalog: null, registro: null, historial: [],
    pallets: palletsIniciales(), marcas: new Set(), firma: '', dirty: false, busy: false,
    pendiente: null, descartar: null, zoom: null, listRevision: 0, ocrRevision: 0,
};

async function api(path = '', options = {}, { blob = false } = {}) {
    const response = await fetch(url + path, { ...options, headers: { Accept: blob ? 'application/json, application/pdf' : 'application/json', Authorization: `Bearer ${state.token}`, ...(typeof options.body === 'string' ? { 'Content-Type': 'application/json' } : {}) } });
    if (response.status === 401) {
        localStorage.removeItem('estiba_wms_office_token');
        $('officeAccess').classList.remove('is-hidden'); $('officeApp').classList.add('is-hidden');
    }
    if (blob && response.ok) return response.blob();
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'No se pudo completar la operación.');
    return data;
}
function message(text, tone = 'info', destino = 'pdMessage') {
    const estado = $(destino);
    estado.textContent = text;
    estado.dataset.tone = tone;
}
async function run(action) {
    try { await action(); } catch (e) { message(e.message, 'error'); }
}

function datos() { return Object.fromEntries(CAMPOS.map((name) => [name, form.elements.namedItem(name).value])); }
function numeracion() { return numerar(state.pallets); }
function marcasInexistentes(n = numeracion()) { return [...state.marcas].filter((m) => m > n.total).sort((a, b) => a - b); }
function cambio() { state.dirty = true; }

function renderTitle() {
    $('pdTitle').textContent = tituloDiagrama(datos());
    const r = state.registro;
    $('pdVersion').textContent = r
        ? `Guardado · versión ${r.version}${state.historial[0] ? ` · ${state.historial[0].usuario} · ${fechaHora(String(state.historial[0].fecha).replace(' ', 'T') + 'Z')}` : ''}${state.dirty ? ' · con cambios sin guardar' : ''}`
        : `Diagrama nuevo${state.dirty ? ', con cambios sin guardar' : ', sin guardar'}.`;
}

function renderPallets() {
    $('pdPallets').innerHTML = state.pallets.map((p, i) => `<tr data-index="${i}">
        <td><strong>Pallet ${i + 1}</strong></td>
        <td><input data-field="folio" maxlength="40" value="${esc(p.folio)}" placeholder="Opcional" aria-label="Folio del pallet ${i + 1}"></td>
        <td><input data-field="base" type="number" min="1" max="${LIMITES.base}" step="1" inputmode="numeric" value="${esc(p.base)}" aria-label="Cajas por base del pallet ${i + 1}"></td>
        <td><input data-field="corridas" type="number" min="1" max="${LIMITES.corridas}" step="1" inputmode="numeric" value="${esc(p.corridas)}" aria-label="Corridas del pallet ${i + 1}"></td>
        <td><input data-field="ultima" type="number" min="1" max="${LIMITES.base - 1}" step="1" inputmode="numeric" value="${esc(p.ultima)}" placeholder="Completa" aria-label="Cajas de la última corrida del pallet ${i + 1}"></td>
        <td><input data-field="declaradas" type="number" min="1" step="1" inputmode="numeric" value="${esc(p.declaradas)}" placeholder="Opcional" aria-label="Cajas declaradas del pallet ${i + 1}"></td>
        <td data-cell="total"></td><td data-cell="rango"></td><td data-cell="marcadas"></td>
        <td><div class="pd-row-actions"><button class="secondary-button" type="button" data-zoom="${i}">Ampliar</button><button class="secondary-button" type="button" data-remove="${i}"${state.pallets.length === 1 ? ' disabled' : ''}>Quitar</button></div></td>
    </tr>`).join('');
    renderComputed();
}

/** Lo que depende de la configuración, sin rehacer los campos que se están escribiendo. */
function renderComputed() {
    const n = numeracion();
    const { porPallet } = marcasPorPallet([...state.marcas], n, state.pallets);
    for (const row of $('pdPallets').rows) {
        const i = Number(row.dataset.index);
        const rango = n.rangos[i];
        const errores = rango.errores;
        const vacio = state.pallets[i].base === null && state.pallets[i].corridas === null;
        row.classList.toggle('is-invalid', errores.length > 0 && !vacio);
        for (const input of row.querySelectorAll('input[data-field]')) {
            input.setAttribute('aria-invalid', String(!vacio && errores.some((e) => e.campo === input.dataset.field)));
        }
        row.querySelector('[data-cell="total"]').textContent = rango.total ? miles(rango.total) : '—';
        row.querySelector('[data-cell="rango"]').innerHTML = vacio ? '<small>Sin configurar</small>'
            : `${rango.total ? `${rango.inicio}–${rango.fin}` : '—'}${errores.length ? `<small class="pd-error">${esc(errores[0].mensaje)}</small>` : ''}`;
        row.querySelector('[data-cell="marcadas"]').textContent = porPallet[i].length ? String(porPallet[i].length) : '—';
        row.querySelector('[data-zoom]').disabled = !rango.total;
    }
    const configurados = n.rangos.filter((r) => r.total).length;
    $('pdTotals').textContent = `${state.pallets.length} pallets · ${miles(n.total)} cajas${n.total ? ` · numeración 1–${miles(n.total)}` : ''} · ${state.marcas.size} marcadas${configurados < state.pallets.length ? ` · ${state.pallets.length - configurados} sin configurar o con errores` : ''}`;
    renderTitle();
    renderDiagram(n);
    renderSummary(n);
    renderWarning(n);
}

function palletHtml(i, n) {
    const p = state.pallets[i];
    const rango = n.rangos[i];
    const corridas = corridasPallet(p, rango);
    const marcadas = [...state.marcas].filter((m) => m >= rango.inicio && m <= rango.fin).length;
    const filas = corridas.map((corrida) => `<div class="pd-pallet__row">${corrida.map((numero) => {
        const on = state.marcas.has(numero);
        return `<button type="button" class="pd-box" data-box="${numero}" aria-pressed="${on}" aria-label="Caja ${numero}${on ? ', marcada' : ''}">${numero}</button>`;
    }).join('')}</div>`).join('');

    return `<article class="pd-pallet" data-pallet="${i}">
        <header><strong>PALLET ${i + 1}${p.folio ? ` · ${esc(p.folio)}` : ''}</strong>
            <small>Base ${p.base} × ${corridas.length} corridas · ${rango.total} cajas</small>
            <small>Cajas ${rango.inicio}–${rango.fin}${marcadas ? ` · ${marcadas} marcadas` : ''}</small>
            <button class="pd-zoom-link" type="button" data-zoom="${i}">Ampliar</button></header>
        <div class="pd-pallet__grid">${filas}</div><div class="pd-pallet__floor" style="width: calc(${p.base} * var(--pd-cell) + 8px)"></div>
    </article>`;
}

function renderDiagram(n = numeracion()) {
    $('pdDiagram').innerHTML = n.rangos.filter((r) => r.total).map((r) => palletHtml(r.indice, n)).join('');
    $('pdDiagram').classList.toggle('is-plain', !$('pdShowNumbers').checked);
    if (state.zoom !== null && $('pdZoom').open) renderZoom(n);
}

function renderSummary(n) {
    const { porPallet } = marcasPorPallet([...state.marcas], n, state.pallets);
    $('pdSummary').innerHTML = n.rangos.map((r, i) => {
        const p = state.pallets[i];
        const marcas = porPallet[i].map((m) => `${m.numero} (c${m.corrida} p${m.posicion})`).join(', ');
        return `<tr><td><strong>Pallet ${i + 1}</strong></td><td>${esc(p.folio || '—')}</td><td>${r.total ? `${p.base} × ${p.corridas}${p.ultima ? `<small>Última corrida: ${p.ultima} cajas</small>` : ''}` : '—'}</td><td>${r.total ? miles(r.total) : '—'}</td><td>${r.total ? `${r.inicio}–${r.fin}` : '—'}</td><td>${marcas || '—'}</td></tr>`;
    }).join('');
    $('pdSummaryTotal').innerHTML = `<tr><td colspan="3">Total</td><td>${miles(n.total)}</td><td>${n.total ? `1–${n.total}` : '—'}</td><td>${state.marcas.size} cajas marcadas</td></tr>`;
}

function renderWarning(n) {
    const fuera = marcasInexistentes(n);
    const cambio = state.marcas.size > 0 && state.firma && firmaEstructura(state.pallets) !== state.firma;
    const textos = [];
    if (cambio) textos.push('Cambiaste la estructura de los pallets: las cajas marcadas conservan su número, pero su posición en el diagrama puede haber cambiado. Revísalas antes de presentar el diagrama.');
    if (fuera.length) textos.push(`${fuera.length === 1 ? 'La caja marcada' : 'Las cajas marcadas'} ${fuera.slice(0, 12).join(', ')}${fuera.length > 12 ? '…' : ''} ya no ${fuera.length === 1 ? 'existe' : 'existen'} con esta configuración.`);
    $('pdWarning').classList.toggle('is-hidden', textos.length === 0);
    $('pdWarningText').textContent = textos.join(' ');
    $('pdDropMissing').classList.toggle('is-hidden', fuera.length === 0);
    $('pdWarningOk').classList.toggle('is-hidden', !cambio);
}

function renderZoom(n = numeracion()) {
    const r = n.rangos[state.zoom];
    if (!r?.total) { $('pdZoom').close(); return; }
    $('pdZoomTitle').textContent = `Pallet ${state.zoom + 1} · cajas ${r.inicio}–${r.fin}`;
    $('pdZoomBody').innerHTML = palletHtml(state.zoom, n);
    $('pdZoom').classList.toggle('is-plain', !$('pdShowNumbers').checked);
    const configurados = n.rangos.filter((x) => x.total).map((x) => x.indice);
    $('pdZoomPrev').disabled = configurados.indexOf(state.zoom) <= 0;
    $('pdZoomNext').disabled = configurados.indexOf(state.zoom) === configurados.length - 1;
}

function openZoom(i) {
    state.zoom = i; renderZoom();
    if (!$('pdZoom').open) $('pdZoom').showModal();
}

function toggleBox(numero) {
    if (state.marcas.has(numero)) state.marcas.delete(numero); else state.marcas.add(numero);
    cambio(); renderComputed();
}

/** Estado completo del editor: nuevo, o un diagrama abierto desde el servidor. */
function cargar(detalle = null) {
    const r = detalle?.registro ?? null;
    state.registro = r ? { id: r.id, version: r.version } : null;
    state.historial = detalle?.historial ?? [];
    form.reset();
    if (r) {
        for (const name of CAMPOS) form.elements.namedItem(name).value = r[name] ?? '';
        state.pallets = r.pallets.map((p) => ({ ...palletVacio(), ...p, folio: p.folio ?? '' }));
        state.marcas = new Set(r.cajas_marcadas);
    } else {
        form.elements.namedItem('csp').value = state.catalog.csp ?? '';
        form.elements.namedItem('fecha').value = state.catalog.fecha_planta;
        state.pallets = palletsIniciales();
        state.marcas = new Set();
    }
    state.firma = firmaEstructura(state.pallets);
    state.dirty = false; state.pendiente = null; state.descartar = null;
    $('pdMarkResult').textContent = ''; $('pdQuick').reset();
    message('', 'info', 'pdLabelsStatus');
    message('', 'info', 'pdExportStatus');
    state.ocrRevision += 1;
    $('pdMarkInput').value = '';
    $('pdOcrForm').reset();
    $('pdOcrStatus').textContent = '';
    renderPallets();
}

/** Abrir otro diagrama o empezar uno nuevo pide confirmar con un segundo clic si hay cambios. */
function confirmarDescarte(clave) {
    if (!state.dirty || state.descartar === clave) return true;
    state.descartar = clave;
    message('Hay cambios sin guardar. Vuelve a pulsar para descartarlos.', 'error');
    setTimeout(() => { if (state.descartar === clave) state.descartar = null; }, 8000);
    return false;
}

/** Antes de guardar o emitir: pallets completos, declaradas cuadradas y sin marcas inexistentes. */
function listoParaEmitir() {
    const n = numeracion();
    const conError = n.rangos.find((r) => !r.valido);
    if (conError) {
        const vacio = state.pallets[conError.indice].base === null && state.pallets[conError.indice].corridas === null;
        throw new Error(`Pallet ${conError.numero}: ${vacio ? 'falta configurar su base y sus corridas, o quítalo.' : conError.errores[0].mensaje}`);
    }
    const fuera = marcasInexistentes(n);
    if (fuera.length) throw new Error(`Quita las marcas de cajas que no existen: ${fuera.slice(0, 12).join(', ')}.`);

    return n;
}

async function guardar() {
    if (state.busy) return;
    listoParaEmitir();
    const payload = cargaDiagrama(datos(), state.pallets, [...state.marcas]);
    if (!payload.numero) { form.elements.namedItem('numero').focus(); throw new Error('Indica el N.º del diagrama para guardarlo.'); }
    if (state.registro) payload.version = state.registro.version;
    const firma = JSON.stringify(payload);
    // Un reintento del mismo contenido reutiliza la operación: el servidor no lo duplica.
    if (state.pendiente?.firma !== firma) state.pendiente = { firma, id: operationId() };
    payload.operacion_id = state.pendiente.id;
    state.busy = true; $('pdSave').disabled = true;
    try {
        const detalle = await api(state.registro ? `/${state.registro.id}` : '', { method: state.registro ? 'PUT' : 'POST', body: JSON.stringify(payload) });
        cargar(detalle);
        message(`Diagrama ${detalle.registro.tipo.toUpperCase()} N.º ${detalle.registro.numero} guardado (versión ${detalle.registro.version}).`);
        if (!$('pdSaved').classList.contains('is-hidden')) await listar();
    } finally {
        state.busy = false; $('pdSave').disabled = !state.catalog.puede_gestionar;
    }
}

async function listar(page = 1) {
    const revision = ++state.listRevision;
    const query = new URLSearchParams(new FormData($('pdSavedFilters'))); query.set('page', page);
    const data = await api(`?${query}`);
    if (revision !== state.listRevision) return;
    $('pdSavedRows').innerHTML = data.data.map((d) => `<tr><td><strong>${esc(`${d.tipo.toUpperCase()} N.º ${d.numero}`)}</strong><small>${esc(d.folio ? `Folio ${d.folio}` : 'Sin folio')}</small></td><td>${esc(d.fecha ? d.fecha.split('-').reverse().join('-') : '—')}<small>${esc(d.lote ? `Lote ${d.lote}` : 'Sin lote')}</small></td><td>${esc(d.exportadora || '—')}<small>${esc(d.especie || '')}</small></td><td>${d.total_pallets} / ${miles(d.total_cajas)}<small>${d.total_marcadas} marcadas</small></td><td>${esc(fechaHora(d.updated_at))}<small>Versión ${d.version}${d.actualizado_por ? ` · ${esc(d.actualizado_por)}` : ''}</small></td><td><button class="secondary-button" type="button" data-open="${esc(d.id)}">Abrir</button></td></tr>`).join('') || '<tr><td colspan="6">No hay diagramas guardados en esta temporada para esta búsqueda.</td></tr>';
    $('pdSavedPages').replaceChildren();
    for (const [text, target] of [['Anterior', page - 1], ['Siguiente', page + 1]]) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary-button'; button.textContent = text;
        button.disabled = target < 1 || target > data.last_page; button.onclick = () => run(() => listar(target)); $('pdSavedPages').append(button);
    }
}

function nombreArchivo(extension) {
    const d = cargaDiagrama(datos(), state.pallets, []);
    return `Diagrama-${d.tipo.toUpperCase()}${d.numero ? `-${d.numero}` : ''}`.replace(/[^A-Za-z0-9_-]+/g, '-') + extension;
}
function descargar(blob, nombre) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob); link.download = nombre;
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 60000);
}
async function pdf() {
    const papel = $('pdPaper').value;
    return api('/pdf', { method: 'POST', body: JSON.stringify({ ...cargaDiagrama(datos(), state.pallets, [...state.marcas]), papel, mostrar_numeros: $('pdShowNumbers').checked }) }, { blob: true });
}
async function etiquetas() {
    if (!state.marcas.size || !datos().exportadora.trim()) throw new Error('Marca las cajas de la muestra e ingresa la exportadora.');
    const cuerpo = cargaDiagrama(datos(), state.pallets, [...state.marcas]);
    const nombre = nombreArchivo('-adhesivos.pdf');
    const blob = await api('/etiquetas', {
        method: 'POST',
        body: JSON.stringify({ ...cuerpo, formato: $('pdLabelFormat').value }),
    }, { blob: true });
    descargar(blob, nombre);
    return 'Adhesivos descargados. Imprime al 100 % de escala.';
}
async function png() {
    const { svg, ancho, alto } = laminaSvg(datos(), state.pallets, [...state.marcas], { temporada: state.catalog.temporada.nombre, mostrarNumeros: $('pdShowNumbers').checked });
    const escala = Math.min(2, 16000 / alto);
    const imagen = new Image();
    const fuente = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    try {
        await new Promise((resolve, reject) => { imagen.onload = resolve; imagen.onerror = () => reject(new Error('No se pudo preparar la imagen.')); imagen.src = fuente; });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(ancho * escala); canvas.height = Math.round(alto * escala);
        const ctx = canvas.getContext('2d'); ctx.scale(escala, escala); ctx.drawImage(imagen, 0, 0);
        return await new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('No se pudo generar el PNG.'))), 'image/png'));
    } finally { URL.revokeObjectURL(fuente); }
}
/** Imprime el mismo PDF, ya con el papel elegido; si el navegador no lo permite, lo abre aparte. */
async function imprimir() {
    const papel = $('pdPaper').value;
    const blob = await pdf();
    const fuente = URL.createObjectURL(blob);
    document.querySelector('.pd-print-frame')?.remove();
    const frame = document.createElement('iframe');
    frame.className = 'pd-print-frame'; frame.title = 'Impresión del diagrama'; frame.src = fuente;
    frame.onload = () => {
        try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch { window.open(fuente, '_blank', 'noopener'); }
    };
    document.body.append(frame);
    return `Se abrió la impresión en ${papel.toUpperCase()} horizontal.`;
}

async function exportar(tipo) {
    if (state.busy) return;
    const destino = tipo === 'etiquetas' ? 'pdLabelsStatus' : 'pdExportStatus';
    const botones = document.querySelectorAll('.pd-workspace [data-export]');
    state.busy = true;
    botones.forEach((boton) => { boton.disabled = true; });
    message('Preparando el documento…', 'info', destino);
    try {
        listoParaEmitir();
        let aviso;
        if (tipo === 'etiquetas') {
            aviso = await etiquetas();
        } else if (tipo === 'print') {
            aviso = await imprimir();
        } else {
            const nombre = nombreArchivo(`.${tipo}`);
            const blob = tipo === 'pdf' ? await pdf() : await png();
            descargar(blob, nombre);
            aviso = `${tipo.toUpperCase()} descargado.`;
        }
        message(aviso, 'info', destino);
    } catch (error) {
        message(error.message, 'error', destino);
    } finally {
        state.busy = false;
        botones.forEach((boton) => { boton.disabled = false; });
    }
}

function aplicarRapido(alcance) {
    const quick = Object.fromEntries(new FormData($('pdQuick')));
    const prueba = { base: quick.base, corridas: quick.corridas, ultima: quick.ultima || null };
    const errores = erroresPallet(prueba);
    if (errores.length) throw new Error(errores[0].mensaje);
    let aplicados = 0;
    state.pallets = state.pallets.map((p) => {
        if (alcance === 'vacios' && (p.base !== null || p.corridas !== null)) return p;
        aplicados += 1;
        return { ...p, base: Number(prueba.base), corridas: Number(prueba.corridas), ultima: prueba.ultima === null ? null : Number(prueba.ultima) };
    });
    cambio(); renderPallets();
    message(aplicados ? `Configuración aplicada a ${aplicados} pallet${aplicados === 1 ? '' : 's'}: ${totalPallet(prueba)} cajas cada uno.` : 'No había pallets sin configurar.');
}

async function enter() {
    state.catalog = await api('/catalogo');
    $('pdSpecies').innerHTML = state.catalog.especies.map((e) => `<option value="${esc(e)}">`).join('');
    $('pdExporters').innerHTML = state.catalog.exportadoras.map((e) => `<option value="${esc(e)}">`).join('');
    $('pdSeason').textContent = `Temporada activa · ${state.catalog.temporada.nombre}`;
    $('pdSavedSeason').textContent = state.catalog.temporada.nombre;
    $('pdSave').disabled = !state.catalog.puede_gestionar;
    if (!state.catalog.puede_gestionar) $('pdSave').title = 'Tu perfil puede consultar y exportar diagramas, pero no guardarlos.';
    $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden');
    cargar();
}

$('officeLoginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const response = await fetch('/api/acceso-oficina', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(event.target))) });
        const data = await response.json(); if (!response.ok) throw new Error(data.message);
        state.token = data.token; localStorage.setItem('estiba_wms_office_token', state.token); localStorage.setItem('estiba_wms_office_identity', JSON.stringify(data.usuario)); await enter();
    } catch (e) { $('officeLoginError').textContent = e.message; }
});
form.addEventListener('input', () => { cambio(); renderTitle(); });
$('pdPallets').addEventListener('input', (e) => {
    const field = e.target.dataset.field; if (!field) return;
    const i = Number(e.target.closest('tr').dataset.index);
    const value = e.target.value.trim();
    state.pallets[i] = { ...state.pallets[i], [field]: field === 'folio' ? e.target.value : (value === '' ? null : value) };
    cambio(); renderComputed();
});
$('pdPallets').addEventListener('click', (e) => {
    const remove = e.target.closest('[data-remove]');
    if (remove && state.pallets.length > 1) {
        state.pallets.splice(Number(remove.dataset.remove), 1);
        cambio(); renderPallets(); message(`Pallet ${Number(remove.dataset.remove) + 1} quitado; la numeración de los siguientes se recalculó.`);
    }
    const zoom = e.target.closest('[data-zoom]');
    if (zoom) openZoom(Number(zoom.dataset.zoom));
});
$('pdAddPallet').onclick = () => {
    if (state.pallets.length >= LIMITES.pallets) { message(`El diagrama admite hasta ${LIMITES.pallets} pallets.`, 'error'); return; }
    // El pallet nuevo parte sin configurar: no se supone su base ni sus corridas.
    state.pallets.push(palletVacio());
    cambio(); renderPallets();
    $('pdPallets').rows[state.pallets.length - 1].querySelector('[data-field="folio"]').focus();
};
$('pdQuick').addEventListener('click', (e) => { const alcance = e.target.closest('[data-apply]')?.dataset.apply; if (alcance) run(async () => aplicarRapido(alcance)); });
$('pdMarkForm').onsubmit = (e) => {
    e.preventDefault();
    const { numeros, no_reconocidos: noReconocidos } = interpretarNumeros($('pdMarkInput').value);
    const n = numeracion();
    const { validos, inexistentes } = clasificarNumeros(numeros, n);
    const nuevos = validos.filter((m) => !state.marcas.has(m));
    if (state.marcas.size + nuevos.length > LIMITES.marcas) { $('pdMarkResult').textContent = `El diagrama admite hasta ${LIMITES.marcas} cajas marcadas.`; return; }
    nuevos.forEach((m) => state.marcas.add(m));
    const partes = [];
    if (nuevos.length) partes.push(`${nuevos.length} ${nuevos.length === 1 ? 'caja marcada' : 'cajas marcadas'}: ${nuevos.map((m) => `${m} (pallet ${ubicarCaja(m, n, state.pallets).pallet + 1})`).join(', ')}.`);
    if (validos.length > nuevos.length) partes.push(`${validos.length - nuevos.length} ya estaban marcadas.`);
    if (inexistentes.length) partes.push(`No existen en el diagrama: ${inexistentes.join(', ')} (la numeración va de 1 a ${n.total || 0}).`);
    if (noReconocidos.length) partes.push(`No se reconocieron: ${noReconocidos.join(', ')}.`);
    $('pdMarkResult').textContent = partes.join(' ') || 'Escribe uno o más números de caja.';
    if (nuevos.length) { $('pdMarkInput').value = inexistentes.length || noReconocidos.length ? $('pdMarkInput').value : ''; cambio(); renderComputed(); }
};
$('pdOcrForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formulario = event.currentTarget;
    const archivo = new FormData(formulario);
    const revision = ++state.ocrRevision;
    const boton = formulario.querySelector('button');
    const estado = $('pdOcrStatus');
    const entrada = $('pdMarkInput');
    boton.disabled = true;
    estado.textContent = 'Leyendo la captura…';
    try {
        const { numeros } = await api('/leer-captura', { method: 'POST', body: archivo });
        if (revision !== state.ocrRevision) return;
        if (!numeros.length) {
            estado.textContent = 'No se encontraron números. Recorta la lista o escríbelos en el campo.';
            return;
        }
        entrada.value = [entrada.value.trim(), numeros.join(', ')].filter(Boolean).join(', ');
        const fuera = clasificarNumeros(numeros, numeracion()).inexistentes.length;
        estado.textContent = `Se leyeron ${numeros.length} números${fuera ? ` (${fuera} fuera del diagrama actual)` : ''}. Revisa la lista antes de pulsar Marcar.`;
        entrada.focus();
    } catch (error) {
        if (revision === state.ocrRevision) estado.textContent = error.message;
    } finally {
        boton.disabled = false;
    }
});
function ajustarCantidadPalletsPresample(cantidad) {
    const actual = state.pallets.length;
    if (!Number.isInteger(cantidad) || cantidad < 1 || cantidad === actual) return '';
    if (cantidad > LIMITES.pallets) {
        return `El presample indica ${cantidad} pallets. El diagrama admite hasta ${LIMITES.pallets}.`;
    }

    const conDatos = state.pallets.slice(cantidad).some((p) =>
        p.folio || p.base !== null || p.corridas !== null || p.ultima !== null || p.declaradas !== null);
    if (state.registro || conDatos) {
        return `El presample indica ${cantidad} pallets y el diagrama tiene ${actual}. Revisa la cantidad en la sección Pallets.`;
    }

    if (cantidad > actual) state.pallets.push(...palletsIniciales(cantidad - actual));
    else state.pallets.length = cantidad;
    cambio();
    renderPallets();

    return `El diagrama quedó con ${cantidad} pallets según el presample. Completa la base y las corridas de los que falten.`;
}

// Presample USDA/SAG: se lee en el servidor, se revisa aquí y solo se aplica con el botón.
function renderPresample(lectura) {
    const caja = $('pdPresample');
    const porRevisar = lectura.cajas.filter((c) => c.estado === 'revisar').length;
    const lista = (items, clase = '') => (items.length ? `<ul class="${clase}">${items.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>` : '');
    const resumen = [
        lectura.lote ? `Lote y presample ${esc(lectura.lote)}` : 'Sin N.º de lote',
        lectura.rangos.length ? `Folio ${esc(folioDeRangos(lectura.rangos))}` : '',
        lectura.pallets ? `${esc(lectura.pallets)} pallets` : '',
        lectura.partida ? `${miles(lectura.partida)} cajas en la partida` : '',
        `${lectura.cajas.length} cajas${lectura.muestra ? ` de una muestra de ${lectura.muestra}` : ''}`,
        porRevisar ? `${porRevisar} por revisar en el papel` : 'todas confirmadas por las dos lecturas',
    ].filter(Boolean).join(' · ');
    caja.innerHTML = `<strong>${resumen}</strong>
        ${lista(lectura.problemas, 'pd-presample__problemas')}${lista(lectura.avisos)}
        <div class="pd-presample__cajas">${lectura.cajas.map((c) => `<span class="${c.estado === 'revisar' ? 'is-review' : ''}" title="${c.estado === 'revisar' ? 'Revisar en el papel' : 'Confirmada'}">${c.numero}</span>`).join('')}</div>
        <div class="pd-actions"><button class="primary-button" type="button" id="pdApplyPresample" ${lectura.problemas.length || !lectura.cajas.length ? 'disabled' : ''}>Aplicar al diagrama</button></div>`;
    caja.classList.remove('is-hidden');
    $('pdApplyPresample').onclick = () => {
        const { campos, marcas, avisos } = aplicarPresample(datos(), lectura);
        for (const [name, value] of Object.entries(campos)) {
            const campo = form.elements.namedItem(name);
            if (campo.value === value) continue;
            if (campo.maxLength >= 0 && value.length > campo.maxLength) {
                avisos.push(`El valor leído para «${campo.labels[0].textContent.trim()}» supera ${campo.maxLength} caracteres. Ingresa una referencia en Datos del diagrama.`);
                continue;
            }
            campo.value = value;
            campo.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const ajuste = ajustarCantidadPalletsPresample(lectura.pallets);
        const entrada = $('pdMarkInput');
        entrada.value = [entrada.value.trim(), marcas.join(', ')].filter(Boolean).join(', ');
        $('pdMarkResult').textContent = [`Se agregaron ${marcas.length} cajas al campo. Revisa las amarillas y pulsa Marcar.`, ajuste, ...avisos].filter(Boolean).join(' ');
        caja.classList.add('is-hidden');
        entrada.focus();
    };
}
$('pdPresampleForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formulario = event.currentTarget;
    const boton = formulario.querySelector('button');
    const caja = $('pdPresample');
    boton.disabled = true;
    caja.classList.remove('is-hidden');
    caja.textContent = 'Leyendo el presample… puede tardar unos segundos.';
    try {
        renderPresample(await api('/leer-presample', { method: 'POST', body: new FormData(formulario) }));
    } catch (error) {
        caja.textContent = error.message;
    } finally {
        boton.disabled = false;
    }
});
$('pdClearMarks').onclick = () => {
    if (!state.marcas.size) return;
    const cantidad = state.marcas.size;
    state.marcas = new Set(); cambio(); renderComputed();
    $('pdMarkResult').textContent = `Se quitaron ${cantidad} marcas.`;
};
$('pdDiagram').addEventListener('click', (e) => {
    const box = e.target.closest('[data-box]'); if (box) { toggleBox(Number(box.dataset.box)); return; }
    const zoom = e.target.closest('[data-zoom]'); if (zoom) openZoom(Number(zoom.dataset.zoom));
});
$('pdZoomBody').addEventListener('click', (e) => {
    const box = e.target.closest('[data-box]'); if (!box) return;
    toggleBox(Number(box.dataset.box));
    $('pdZoomBody').querySelector(`[data-box="${box.dataset.box}"]`)?.focus();
});
const moverZoom = (paso) => {
    const configurados = numeracion().rangos.filter((r) => r.total).map((r) => r.indice);
    const siguiente = configurados[configurados.indexOf(state.zoom) + paso];
    if (siguiente !== undefined) { state.zoom = siguiente; renderZoom(); }
};
$('pdZoomPrev').onclick = () => moverZoom(-1);
$('pdZoomNext').onclick = () => moverZoom(1);
$('pdZoomClose').onclick = () => $('pdZoom').close();
$('pdZoom').addEventListener('close', () => { state.zoom = null; });
$('pdShowNumbers').onchange = () => renderDiagram();
$('pdWarningOk').onclick = () => { state.firma = firmaEstructura(state.pallets); renderWarning(numeracion()); };
$('pdDropMissing').onclick = () => {
    const fuera = marcasInexistentes();
    fuera.forEach((m) => state.marcas.delete(m));
    cambio(); renderComputed();
    $('pdMarkResult').textContent = `Se quitaron las marcas de cajas inexistentes: ${fuera.join(', ')}.`;
};
$('pdSave').onclick = () => run(guardar);
$('pdNew').onclick = () => { if (confirmarDescarte('nuevo')) { cargar(); message('Diagrama nuevo con 16 pallets sin configurar.'); } };
$('pdOpenSaved').onclick = () => {
    const panel = $('pdSaved'); const abrir = panel.classList.contains('is-hidden');
    panel.classList.toggle('is-hidden', !abrir); $('pdOpenSaved').setAttribute('aria-expanded', String(abrir));
    if (abrir) run(() => listar());
};
$('pdSavedFilters').onsubmit = (e) => { e.preventDefault(); run(() => listar()); };
$('pdSavedRows').onclick = (e) => {
    const id = e.target.closest('[data-open]')?.dataset.open;
    if (!id || state.busy || !confirmarDescarte(id)) return;
    run(async () => {
        const detalle = await api(`/${id}`); cargar(detalle);
        message(`Diagrama ${detalle.registro.tipo.toUpperCase()} N.º ${detalle.registro.numero} abierto (versión ${detalle.registro.version}).`);
    });
};
document.querySelector('.pd-workspace').addEventListener('click', (e) => {
    const tipo = e.target.closest('[data-export]')?.dataset.export;
    if (tipo) void exportar(tipo);
});
window.addEventListener('beforeunload', (e) => { if (state.dirty) e.preventDefault(); });

if (state.token) run(enter);
