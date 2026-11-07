import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import ts from 'typescript';

// Datos sintéticos con las cantidades informadas en v442. No accede a la planta.
const cases = [
  { name: 'v442', articles: 76420, origins: 342, combinations: 523632 },
  { name: 'resta-40-por-ciento', articles: Math.ceil(76420 / .6), origins: Math.ceil(342 / .6), combinations: Math.ceil(523632 / (.6 * .6)) },
  { name: 'resta-50-por-ciento', articles: 76420 * 2, origins: 342 * 2, combinations: 523632 * 4 },
];
const mode = process.argv[2];
if (!['legacy', 'compact'].includes(mode)) {
  const jobs = [['legacy', cases[0]], ...cases.map((data) => ['compact', data])];
  for (const [representation, data] of jobs) {
    await new Promise((resolve, reject) => {
      const child = spawn(process.execPath, ['--expose-gc', '--max-old-space-size=512', fileURLToPath(import.meta.url), representation, JSON.stringify(data)], { stdio: ['ignore', 'pipe', 'pipe'] });
      let error = '';
      child.stdout.on('data', (chunk) => process.stdout.write(chunk));
      child.stderr.on('data', (chunk) => { error = (error + chunk).slice(-4000); });
      child.on('error', reject);
      child.on('exit', (code, signal) => {
        if (code === 0) resolve();
        else if (/heap out of memory/i.test(error)) { console.log(JSON.stringify({ representation, scenario: data.name, result: 'out-of-memory', heapLimitMiB: 512 })); resolve(); }
        else reject(new Error(`Benchmark ${representation}/${data.name}: ${code ?? signal}\n${error}`));
      });
    });
  }
} else {
  const shape = JSON.parse(process.argv[3]);
  const module = { exports: {} };
  const source = readFileSync(new URL('../src/domain/validationCatalogIndex.ts', import.meta.url), 'utf8');
  vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, { module, exports: module.exports });
  const { createCatalogCompatibilityBuilder, indexValidationCatalog, articlesForOrigins, catalogCombinationCount } = module.exports;
  const id = (tag, n) => `${tag}0000000-0000-4000-8000-${n.toString(16).padStart(12, '0')}`;
  global.gc();
  const started = performance.now();
  const catalog = {
    articulos: Array.from({ length: shape.articles }, (_, n) => ({ id: id('a', n), temporada_id: 'season', variedad_validacion_id: `var-${n % 100}`, especie: 'Cereza', variedad: `Variedad ${n % 100}`, calibre: `${n % 10}J`, envase: `Envase ${n % 30}`, codigo_externo: null, activo: true })),
    origenes: Array.from({ length: shape.origins }, (_, n) => ({ id: id('b', n), activo: true, cliente: `Cliente ${n % 10}`, marca: 'Marca', csg: String(100000 + n), variedad_ids: null })),
    combinaciones: [],
  };
  const builder = mode === 'compact' ? createCatalogCompatibilityBuilder(catalog) : null;
  let wireBytes = 0;
  for (let start = 0; start < shape.combinations; start += 1000) {
    const page = [];
    for (let n = start; n < Math.min(start + 1000, shape.combinations); n++) {
      const origin = n % shape.origins;
      const article = (Math.floor(n / shape.origins) + origin * 7) % shape.articles;
      page.push({ id: id('c', n), articulo_validacion_id: catalog.articulos[article].id, origen_validacion_id: catalog.origenes[origin].id, codigo_externo: null });
    }
    const raw = JSON.stringify(page); wireBytes += Buffer.byteLength(raw);
    // JSON.parse reproduce las cadenas independientes de una respuesta HTTP.
    const received = JSON.parse(raw);
    if (builder) builder.append(received); else catalog.combinaciones.push(...received);
  }
  if (builder) catalog.compatibilidad = builder.finish();
  const index = indexValidationCatalog(catalog);
  const samples = [];
  let result;
  for (let n = 0; n < 7; n++) {
    const start = performance.now(); result = articlesForOrigins(index, [catalog.origenes[0].id, catalog.origenes[1].id]); samples.push(performance.now() - start);
  }
  if (catalogCombinationCount(catalog) !== shape.combinations || !result.length) throw new Error('El benchmark perdió vínculos u opciones.');
  global.gc();
  const memory = process.memoryUsage();
  console.log(JSON.stringify({ simulation: true, engine: `Node ${process.version}`, representation: mode, scenario: shape.name, ...shape,
    result: 'passed', heapLimitMiB: 512, retainedHeapMiB: +(memory.heapUsed / 1048576).toFixed(2),
    arrayBuffersMiB: +(memory.arrayBuffers / 1048576).toFixed(2), retainedDataMiB: +((memory.heapUsed + memory.arrayBuffers) / 1048576).toFixed(2),
    peakRssMiB: +(process.resourceUsage().maxRSS / 1024).toFixed(2), elapsedMs: Math.round(performance.now() - started),
    selectionMedianMs: +samples.sort((a, b) => a - b)[3].toFixed(3),
    compatibilityMiB: catalog.compatibilidad ? +((catalog.compatibilidad.indices.byteLength + catalog.compatibilidad.offsets.byteLength) / 1048576).toFixed(3) : null,
    linksJsonMiB: +(wireBytes / 1048576).toFixed(2) }));
}
