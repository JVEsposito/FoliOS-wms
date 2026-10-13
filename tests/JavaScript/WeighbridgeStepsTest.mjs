import assert from 'node:assert/strict';
import test from 'node:test';
import { receptionSteps } from '../../resources/js/shared/weighbridge-steps.js';

const states = (reception) => receptionSteps(reception).map((step) => `${step.state}:${step.label}`);
const fruit = (estado, validacion = 'pendiente') => ({ tipo_recepcion: 'fruta_con_envases', estado, estado_validacion_mp: validacion, pesaje_envases: null });

test('fruta con envases: ingreso, validación MP, destare y cierre', () => {
    assert.deepEqual(states(fruit('en_bascula_ingreso')), ['now:Confirmar ingreso', 'next:Validación MP', 'next:Destare', 'next:Cierre']);
    assert.deepEqual(states(fruit('en_bascula_salida', 'pendiente')), ['done:Ingreso', 'now:Validación MP pendiente', 'next:Destare', 'next:Cierre']);
    assert.deepEqual(states(fruit('en_bascula_salida', 'en_curso')), ['done:Ingreso', 'now:Validación MP en curso', 'next:Destare', 'next:Cierre']);
    assert.deepEqual(states(fruit('en_bascula_salida', 'validada')), ['done:Ingreso', 'done:Validación MP', 'now:Destare pendiente', 'next:Cierre']);
    assert.deepEqual(states(fruit('cerrado', 'validada')), ['done:Ingreso', 'done:Validación MP', 'done:Destare', 'done:Cerrada']);
});

test('pesaje acumulativo: el paso actual muestra el avance de tandas', () => {
    const weighing = { tipo_recepcion: 'fruta_pesaje_envases', estado: 'en_pesaje_envases', estado_validacion_mp: 'pendiente', pesaje_envases: { cantidad_pesada: 3, cantidad_declarada: 5 } };
    assert.deepEqual(states(weighing), ['done:Ingreso', 'now:Pesaje de envases 3/5', 'next:Cierre']);
    assert.deepEqual(states({ ...weighing, estado: 'cerrado' }), ['done:Ingreso', 'done:Pesaje de envases', 'done:Cerrada']);
});

test('solo envases: ingreso documental y cierre', () => {
    const containers = { tipo_recepcion: 'solo_envases', estado: 'en_bascula_ingreso', pesaje_envases: null };
    assert.deepEqual(states(containers), ['now:Confirmar ingreso', 'next:Listo para cerrar']);
    assert.deepEqual(states({ ...containers, estado: 'en_bascula_salida' }), ['done:Ingreso documental', 'now:Listo para cerrar']);
    assert.deepEqual(states({ ...containers, estado: 'cerrado' }), ['done:Ingreso documental', 'done:Cerrada']);
});

test('siempre hay como máximo un paso actual', () => {
    for (const estado of ['en_bascula_ingreso', 'en_bascula_salida', 'cerrado']) {
        for (const validacion of ['pendiente', 'en_curso', 'validada']) {
            assert.ok(receptionSteps(fruit(estado, validacion)).filter((step) => step.state === 'now').length <= 1);
        }
    }
});
