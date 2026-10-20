import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

import { operatorTaskPurpose } from '../src/domain/operatorTaskPurpose.ts';
import { isFinishedLoad, operatorLoadCounts, operatorLoadDate } from '../src/domain/operatorLoads.ts';

test('el retiro de un bloqueador explica el despacho principal y los otros objetivos', () => {
  const context = operatorTaskPurpose({
    plan: { id: 'secondary', tipo: 'reordenamiento_camara', titulo: 'Ordenar cámara' },
    tipo_movimiento: 'retiro', tipo_paso_maniobra: 'extraccion_temporal',
    maniobra: { objetivos: [
      { id: 'secondary', tipo: 'reordenamiento_camara', titulo: 'Ordenar cámara', es_principal: false },
      { id: 'main', tipo: 'despacho_directo', titulo: 'Despacho CAR-42 → Andén 2', motivo: 'Camión confirmado en andén.', es_principal: true },
    ] },
  });
  assert.equal(context.label, 'Despacho de carga');
  assert.match(context.goal, /CAR-42/);
  assert.match(context.step, /temporal.*despejar/);
  assert.equal(context.reason, 'Camión confirmado en andén.');
  assert.deepEqual(context.otherGoals, ['Ordenar cámara']);
});

test('distingue preparación de carga, separación de retenido e inspección aun sin maniobra', () => {
  const labels = ['concentracion_carga', 'segregacion_retenido', 'preparacion_inspeccion'].map((tipo) => operatorTaskPurpose({
    plan: { id: tipo, tipo, titulo: 'Objetivo conocido', motivo: 'Motivo de la operación' },
    maniobra: null, tipo_movimiento: 'traslado_entre_camaras',
  }));
  assert.equal(new Set(labels.map((context) => context.label)).size, 3);
  assert.match(labels[0].label, /carga/);
  assert.match(labels[1].label, /retenido/);
  assert.match(labels[2].label, /SAG \/ USDA/);
  assert.ok(labels.every((context) => context.goal === 'Objetivo conocido' && context.reason === 'Motivo de la operación'));
});

test('una carga cerrada conserva el conteo despachado sin convertirlo en pendiente o en andén', () => {
  const load = { estado: 'cerrada', total_folios: 2, folios: [{ estado_carga: 'despachado' }, { estado_carga: 'despachado' }] };
  assert.equal(isFinishedLoad(load), true);
  assert.equal(isFinishedLoad({ estado: 'separacion_completa' }), false);
  assert.deepEqual(operatorLoadCounts(load), { total: 2, dock: 0, shipped: 2, pending: 0 });
  assert.match(operatorLoadDate('2026-10-21T05:30:00Z', 'America/Santiago'), /2:30|02:30/);
});

test('la consulta de cargas usa GET condicional y separa la caché entre usuarios', async () => {
  const calls = [];
  const feed = { data: [], meta: { turno: { nombre: '22:00–06:00' } } };
  const responses = [new Response(JSON.stringify(feed), { headers: { ETag: 'first' } }), new Response(null, { status: 304 }), new Response(JSON.stringify(feed), { headers: { ETag: 'second' } })];
  const source = readFileSync(new URL('../src/services/operationalTasksApi.ts', import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const module = { exports: {} };
  vm.runInNewContext(code, { module, exports: module.exports, Headers, URLSearchParams,
    require: (id) => {
      if (id === './apiError') return { ApiError: class extends Error {} };
      if (id === './httpClient') return { fetchWithTimeout: async (url, init) => { calls.push({ url, init }); return responses.shift(); } };
      throw new Error(`Import inesperado ${id}`);
    },
  });
  const api = new module.exports.OperationalTasksApi('https://wms.example');
  assert.equal((await api.loadStatus('operator-a')).meta.turno.nombre, '22:00–06:00');
  assert.equal(await api.loadStatus('operator-a', true), null);
  await api.loadStatus('operator-b', true);
  assert.ok(calls.every(({ url, init }) => url.endsWith('/api/cargas/operacion-turno') && !init.body && (!init.method || init.method === 'GET')));
  assert.equal(calls[1].init.headers.get('If-None-Match'), 'first');
  assert.equal(calls[2].init.headers.get('If-None-Match'), null);
});
