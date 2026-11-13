import test from 'node:test';
import assert from 'node:assert/strict';
import {
    activeCitations,
    citationSegments,
    citationTrigger,
    decodeCitations,
    encodeCitations,
    insertCitation,
    plainCitations,
} from '../../resources/js/shared/communications-references.js';

const carga = { tipo: 'carga', id: '9b1d6c2e-0000-4000-8000-000000000007', etiqueta: 'CAR-2611-0007' };
const recepcion = { tipo: 'recepcion_material', id: '9b1d6c2e-0000-4000-8000-000000000012', etiqueta: 'Guía 127' };
const javier = { tipo: 'usuario', id: '5', etiqueta: 'Javier Esposito' };
const citations = (...items) => new Map(items.map((item) => [`${item.tipo}:${item.id}`, item]));

test('citas: «#» y «@» antes del cursor abren la búsqueda; dentro de una palabra no', () => {
    assert.deepEqual(citationTrigger('Revisen #CAR-26', 15), { sigil: '#', query: 'CAR-26', start: 8, end: 15, kind: 'record' });
    assert.deepEqual(citationTrigger('@jav', 4), { sigil: '@', query: 'jav', start: 0, end: 4, kind: 'user' });
    assert.equal(citationTrigger('correo@empresa.cl', 17), null);
    assert.equal(citationTrigger('canal#3', 7), null);
    assert.equal(citationTrigger('# CAR', 5), null, 'un espacio cierra la búsqueda');
    assert.deepEqual(citationTrigger('(#', 2).query, '');
});

test('citas: elegir una cita reemplaza lo escrito por la etiqueta y deja el cursor después', () => {
    const text = 'Revisen #car-26 hoy';
    const inserted = insertCitation(text, citationTrigger(text, 15), carga);
    assert.equal(inserted.text, 'Revisen #CAR-2611-0007 hoy');
    assert.equal(inserted.caret, 22);
    const atEnd = insertCitation('Hola @jav', citationTrigger('Hola @jav', 9), javier);
    assert.equal(atEnd.text, 'Hola @Javier Esposito ');
});

test('citas: al enviar, sólo las etiquetas intactas se convierten en fichas', () => {
    const map = citations(carga, recepcion, javier);
    const text = '#CAR-2611-0007, revisa #Guía 127 y avisa a @Javier Esposito. Otra: #CAR-2611-00071 y @Javier';
    assert.equal(encodeCitations(text, map),
        `[[carga:${carga.id}]], revisa [[recepcion_material:${recepcion.id}]] y avisa a [[usuario:5]]. Otra: #CAR-2611-00071 y @Javier`);
    assert.deepEqual(activeCitations('Sólo #CAR-2611-0007', map), [carga]);
    assert.equal(encodeCitations('sin citas', new Map()), 'sin citas');
});

test('citas: el texto recibido se divide en trozos seguros y se lee con etiquetas', () => {
    const text = `<b>Ojo</b> [[carga:${carga.id}]] y [[usuario:5]] [[folio:desconocido]]`;
    const segments = citationSegments(text, [carga, javier]);
    assert.deepEqual(segments.map((s) => s.type), ['text', 'citation', 'text', 'citation', 'text']);
    assert.equal(segments[0].value, '<b>Ojo</b> ');
    assert.equal(segments.at(-1).value, ' [[folio:desconocido]]');
    assert.equal(plainCitations(text, [carga, javier]), '<b>Ojo</b> #CAR-2611-0007 y @Javier Esposito [[folio:desconocido]]');
    const decoded = decodeCitations(`Mira [[carga:${carga.id}]]`, [carga]);
    assert.equal(decoded.text, 'Mira #CAR-2611-0007');
    assert.equal(encodeCitations(decoded.text, decoded.citations), `Mira [[carga:${carga.id}]]`);
});
