import { sagEscape as esc } from './office-sag-counterpart.js';

export const dispatchLabels = {
    exportador: 'Exportador', rut_exportador: 'RUT exportador', agente: 'Agente', rut_agente: 'RUT agente',
    nave_vuelo: 'Nave / vuelo', puerto_embarque: 'Puerto de embarque', oficina_sag_puerto: 'Oficina SAG de puerto',
    destino_pais: 'País destino', destino_ciudad: 'Ciudad / puerto destino', consignatario: 'Consignatario',
    contenedor: 'Contenedor', sello: 'Sello', ubicacion_sello: 'Ubicación del sello', patente: 'Patente', patente_trasera: 'Patente carro',
    guias: 'Números de guías', referencia_multipuerto: 'Referencia oficial Multipuerto',
    motivo_contingencia: 'Motivo de contingencia', detalle_tratamiento: 'Tratamiento: tipo, producto, dosis, duración, T° y recinto',
};
export const dispatchOptions = {
    modo: ['multipuerto', 'contingencia'], tipo_produccion: ['CONVENCIONAL', 'ORGÁNICO', 'EN CONVERSIÓN'],
    transgenico: ['SI', 'NO', 'NO APLICA'], tratamiento: ['sin_tratamiento', 'tratado', 'tratamiento_destino'], nimf15: ['true', 'false'], inspeccionado: ['true', 'false'],
};
export const optionLabels = { modo: 'Documento', tipo_produccion: 'Tipo de producción', transgenico: 'Transgénico', tratamiento: 'Tratamiento fitosanitario', nimf15: 'NIMF 15 verificada', inspeccionado: 'Producto inspeccionado' };
export function dispatchFields(source, values, instruction = '') {
    return `<p>El correlativo local identifica esta preparación. La referencia oficial se conserva por separado. En contingencia registra el motivo.</p><label>Instructivo<select id="scDispatchInstruction"><option value="">Seleccionar</option>${(source?.instructivos || []).map((i) => `<option value="${esc(i.id)}" ${i.id === instruction ? 'selected' : ''}>${esc(i.numero)} · ${esc(i.destino_pais)}</option>`).join('')}</select></label><div class="sc-grid">${Object.entries(dispatchOptions).map(([key, options]) => `<label>${optionLabels[key]}<select data-dispatch="${key}"><option value="">Sin completar</option>${options.map((o) => `<option value="${o}" ${String(values[key] ?? '') === o ? 'selected' : ''}>${o === 'true' ? 'Sí' : o === 'false' ? 'No' : o.replaceAll('_', ' ')}</option>`).join('')}</select></label>`).join('')}${Object.entries(dispatchLabels).map(([key, label]) => `<label>${label}<input data-dispatch="${key}" value="${esc(values[key] ?? '')}" maxlength="${key === 'detalle_tratamiento' || key === 'motivo_contingencia' ? 1000 : 250}"></label>`).join('')}</div>`;
}
export function dispatchRows(rows) {
    return `<p>Marca los pallets incluidos en este instructivo. Cada línea de un pallet mixto conserva su origen y cantidad.</p><table><thead><tr><th>Incluir</th><th>Folio</th><th>Especie / variedad</th><th>Cajas</th><th>Kilos</th><th>Provincia</th><th>Comuna</th><th>CSG</th><th>CSP</th></tr></thead><tbody>${rows.map((l, i) => `<tr><td><input type="checkbox" data-select-folio="${esc(l.folio_id)}" ${l.incluido ? 'checked' : ''} aria-label="Incluir ${esc(l.folio)}"></td><td>${esc(l.folio)}</td><td>${esc(l.especie)} / ${esc(l.variedad)}</td><td>${esc(l.cantidad)}</td>${['kilos', 'provincia', 'comuna', 'csg', 'csp'].map((key) => `<td><input aria-label="${key} ${esc(l.folio)}" data-line="${i}" data-field="${key}" value="${esc(l[key] ?? '')}" ${key === 'kilos' ? 'type="number" min="0.0001" step="0.0001"' : 'maxlength="150"'}></td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
