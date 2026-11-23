import assert from 'node:assert/strict';
import test from 'node:test';
import { escapeHtml, percent, renderGate, usd } from '../../resources/js/shared/assistant-settings-ui.js';

test('formatea montos y porcentajes en español de Chile', () => {
    assert.equal(usd(20), 'US$ 20,00');
    assert.equal(usd(0.000093, 4), 'US$ 0,0001');
    assert.equal(percent(0.1667), '16,7 %');
    assert.equal(escapeHtml('<b>"x"</b>'), '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
});

test('el informe de la compuerta muestra la decisión y escapa las preguntas reales', () => {
    const html = renderGate({
        decision: 'conectar_haiku',
        motivo: 'El 16,7 % sigue sin consulta.',
        registro: { total: 60, usuarios: 4, proporcion_sugerencias: 0.2 },
        reinterpretado: { proporcion_sugerencias: 0.167 },
        banco: { correctas: 22, falsas: 0 },
        sin_respuesta: [{ texto: '<script>clima</script>', veces: 10 }],
    });

    assert.ok(html.includes('data-decision="conectar_haiku"'));
    assert.ok(html.includes('<strong>Conectar Haiku.</strong>'));
    assert.ok(html.includes('22 correctas · 0 falsas'));
    assert.ok(html.includes('&lt;script&gt;clima&lt;/script&gt;'));
    assert.ok(!html.includes('<script>'));
});
