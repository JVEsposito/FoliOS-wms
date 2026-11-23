import { officeCode } from './folios-display.js';

// Presentación del asistente de Oficina (docs/ASISTENTE_OFICINA.md). Funciones puras: no
// tocan el DOM ni la red, así que se prueban con node --test.

export const ESQUINAS = Object.freeze(['arriba-izquierda', 'arriba-derecha', 'abajo-izquierda', 'abajo-derecha']);

export function esquinaMasCercana(x, y, ancho, alto) {
    const vertical = y < alto / 2 ? 'arriba' : 'abajo';
    const horizontal = x < ancho / 2 ? 'izquierda' : 'derecha';
    return `${vertical}-${horizontal}`;
}

// Estado → glifo de Gajos vivos: la forma y el texto dicen cómo está, nunca el color del área.
const GLIFOS = Object.freeze({
    cerrada: ['●', 'listo'],
    respondida: ['●', 'listo'],
    'esperando destare': ['◆', 'atencion'],
    revisar: ['◆', 'atencion'],
    bloqueado: ['■', 'bloqueado'],
});

export function glifoEstado(texto) {
    const [glifo, tono] = GLIFOS[String(texto ?? '').trim().toLowerCase()] ?? ['○', 'pendiente'];
    return { glifo, tono };
}

const numero = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 3 });

function celda(columna, valor, escape) {
    if (valor === null || valor === undefined || valor === '') return '—';
    switch (columna.tipo) {
        case 'codigo':
        case 'folio':
            return `<span class="folios-code">${officeCode(valor, escape)}</span>`;
        case 'patente':
            return `<span class="folios-asistente__placa">${escape(String(valor))}</span>`;
        case 'numero':
            return `<span class="folios-asistente__numero">${escape(numero.format(Number(valor)))}</span>`;
        case 'estado': {
            const { glifo, tono } = glifoEstado(valor);
            return `<span class="folios-asistente__estado" data-tono="${tono}"><span aria-hidden="true">${glifo}</span> ${escape(String(valor))}</span>`;
        }
        default:
            return escape(String(valor));
    }
}

export function renderResultado(resultado, escape) {
    if (!resultado) return '';
    const cifras = (resultado.cifras ?? []).map((cifra) => `
        <div class="folios-asistente__cifra">
            <dt>${escape(cifra.etiqueta)}</dt>
            <dd><strong>${escape(typeof cifra.valor === 'number' ? numero.format(cifra.valor) : String(cifra.valor))}</strong>${cifra.unidad ? ` <span>${escape(cifra.unidad)}</span>` : ''}</dd>
        </div>`).join('');
    const columnas = resultado.columnas ?? [];
    const filas = resultado.filas ?? [];
    const tabla = columnas.length && filas.length ? `
        <div class="folios-asistente__tabla" tabindex="0" role="region" aria-label="Detalle de ${escape(resultado.titulo)}">
            <table>
                <thead><tr>${columnas.map((c) => `<th scope="col"${c.tipo === 'numero' ? ' data-numero' : ''}>${escape(c.titulo)}</th>`).join('')}</tr></thead>
                <tbody>${filas.map((fila) => `<tr>${columnas.map((c) => `<td${c.tipo === 'numero' ? ' data-numero' : ''}>${celda(c, fila[c.clave], escape)}</td>`).join('')}</tr>`).join('')}</tbody>
            </table>
        </div>` : '';
    const enlace = resultado.enlace
        ? `<a class="folios-asistente__enlace" href="${escape(resultado.enlace)}">Abrir en la oficina</a>`
        : '';

    return `
        <article class="folios-asistente__resultado" data-estado="${escape(resultado.estado)}">
            <h3>${escape(resultado.titulo)}</h3>
            <p>${escape(resultado.resumen)}</p>
            ${cifras ? `<dl class="folios-asistente__cifras">${cifras}</dl>` : ''}
            ${tabla}
            ${enlace}
        </article>`;
}

// Línea «Entendí»: qué consulta corrió, con qué periodo y filtros. Lo reconocido que la
// consulta no aplica se avisa aparte; una cifra nunca se lee como filtrada si no lo está.
export function renderEntendido(entendido, escape) {
    if (!entendido?.consulta) return '';
    const fichas = (entendido.fichas ?? []).map((ficha) => `<span class="folios-asistente__ficha">${escape(ficha)}</span>`).join('');
    const avisos = (entendido.no_aplicados ?? []).map((aviso) => `
        <li><span aria-hidden="true">◆</span> ${escape(aviso)}</li>`).join('');

    return `
        <div class="folios-asistente__entendido">
            <p><span class="folios-asistente__entendi">Entendí:</span> <strong>${escape(entendido.consulta)}</strong>${fichas}
                <button type="button" class="folios-asistente__corregir" data-asistente-corregir>Corregir</button></p>
            ${avisos ? `<ul class="folios-asistente__no-aplicados" data-tono="atencion">${avisos}</ul>` : ''}
        </div>`;
}

const MENSAJE_SUGERENCIAS = 'No estoy seguro de qué consulta quieres. ¿Es alguna de estas?';
const MENSAJE_SIN_CONSULTA = 'No tengo una consulta para esa pregunta. Puedes buscarla en FoliOS o ver lo que sé responder.';

export function renderSugerencias(sugerencias, escape, mensaje) {
    if (!sugerencias?.length) {
        return `<div class="folios-asistente__sugerencias"><p>${escape(mensaje || MENSAJE_SIN_CONSULTA)}</p></div>`;
    }
    return `
        <div class="folios-asistente__sugerencias">
            <p>${escape(mensaje || MENSAJE_SUGERENCIAS)}</p>
            <ul>${sugerencias.map((s, indice) => `
                <li><button type="button" data-asistente-sugerencia="${indice}">
                    <strong>${escape(s.titulo)}</strong><span>${escape(s.descripcion ?? '')}</span>
                </button></li>`).join('')}
            </ul>
        </div>`;
}
