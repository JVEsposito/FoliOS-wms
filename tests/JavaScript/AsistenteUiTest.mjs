import assert from 'node:assert/strict';
import test from 'node:test';
import { esquinaMasCercana, glifoEstado, renderEntendido, renderResultado, renderSugerencias } from '../../resources/js/shared/asistente-ui.js';

const escape = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

test('el gajo se acomoda en la esquina más cercana de cada cuadrante', () => {
    assert.equal(esquinaMasCercana(10, 10, 1000, 800), 'arriba-izquierda');
    assert.equal(esquinaMasCercana(990, 10, 1000, 800), 'arriba-derecha');
    assert.equal(esquinaMasCercana(10, 790, 1000, 800), 'abajo-izquierda');
    assert.equal(esquinaMasCercana(990, 790, 1000, 800), 'abajo-derecha');
});

test('los estados llevan glifo y nunca dependen del color', () => {
    assert.deepEqual(glifoEstado('Esperando destare'), { glifo: '◆', tono: 'atencion' });
    assert.deepEqual(glifoEstado('Cerrada'), { glifo: '●', tono: 'listo' });
    assert.deepEqual(glifoEstado('algo desconocido'), { glifo: '○', tono: 'pendiente' });
});

test('el resultado escapa el contenido de las filas', () => {
    const html = renderResultado({
        estado: 'respondida',
        titulo: 'Tiempos',
        resumen: 'Resumen',
        cifras: [{ etiqueta: 'Promedio', valor: 60, unidad: 'min' }],
        columnas: [{ clave: 'cliente', titulo: 'Cliente' }],
        filas: [{ cliente: '<script>alert(1)</script>' }],
        enlace: '/oficina/romana',
    }, escape);

    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(html.includes('href="/oficina/romana"'));
});

test('el código destaca el correlativo y la patente se dibuja como placa', () => {
    const html = renderResultado({
        estado: 'respondida',
        titulo: 'Tiempos',
        resumen: '',
        columnas: [{ clave: 'codigo', titulo: 'Recepción', tipo: 'codigo' }, { clave: 'patente', titulo: 'Patente', tipo: 'patente' }],
        filas: [{ codigo: 'REC-2611-0108', patente: 'ABCD12' }],
    }, escape);

    assert.ok(html.includes('<span class="folios-code__prefix">REC-2611-</span><strong>0108</strong>'));
    assert.ok(html.includes('<span class="folios-asistente__placa">ABCD12</span>'));
});

test('sin datos muestra el resumen que explica por qué', () => {
    const html = renderResultado({ estado: 'sin_datos', titulo: 'Kilos', resumen: 'No hay recepciones cerradas el 22-11-2026.' }, escape);

    assert.ok(html.includes('data-estado="sin_datos"'));
    assert.ok(html.includes('No hay recepciones cerradas el 22-11-2026.'));
    assert.ok(!html.includes('<table>'));
});

test('tres sugerencias producen tres botones', () => {
    const html = renderSugerencias([
        { clave: 'a', titulo: 'Uno', descripcion: 'x' },
        { clave: 'b', titulo: 'Dos', descripcion: 'y' },
        { clave: 'c', titulo: 'Tres', descripcion: 'z' },
    ], escape);

    assert.equal(html.match(/data-asistente-sugerencia=/g).length, 3);
});

test('sin sugerencias lo dice en vez de quedar en blanco', () => {
    const html = renderSugerencias([], escape);

    assert.ok(html.includes('No tengo una consulta para esa pregunta.'));
    assert.ok(!html.includes('data-asistente-sugerencia='));
});

test('la línea Entendí muestra consulta, fichas, Corregir y lo que no se aplicó', () => {
    const html = renderEntendido({
        consulta: 'Repaletizajes',
        fichas: ['Jornada 21-11-2026'],
        no_aplicados: ['No filtra por cliente (Exportadora <Andina>): incluye todos los clientes.'],
    }, escape);

    assert.ok(html.includes('<strong>Repaletizajes</strong>'));
    assert.ok(html.includes('Jornada 21-11-2026'));
    assert.ok(html.includes('data-asistente-corregir'));
    assert.ok(html.includes('Exportadora &lt;Andina&gt;'));
    assert.ok(html.includes('data-tono="atencion"'));
    assert.equal(renderEntendido(null, escape), '');
});
