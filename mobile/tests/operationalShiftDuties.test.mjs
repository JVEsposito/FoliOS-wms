import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import React from 'react';
import { act, create } from 'react-test-renderer';
import ts from 'typescript';
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
function load(path) {
  const module = { exports:{} };
  const source = ts.transpileModule(readFileSync(path,'utf8'), { compilerOptions:{ jsx:ts.JsxEmit.ReactJSX, module:ts.ModuleKind.CommonJS, target:ts.ScriptTarget.ES2022 }, fileName:path }).outputText;
  const localRequire = (id) => id === 'react-native' ? { Text:'Text', View:'View', StyleSheet:{create:(v)=>v} }
    : id.startsWith('.') ? load(resolve(dirname(path),id+'.ts')) : require(id);
  vm.runInNewContext(source,{module,exports:module.exports,require:localRequire,console},{filename:path});
  return module.exports;
}
const { OperatorShiftDuties } = load(resolve(sourceRoot,'components/operator/OperatorShiftDuties.tsx'));
const context = () => ({ generado_at:'2026-10-24T12:00:00Z',turno:{nombre:'Turno mañana'},funciones:{principal_nombre:'Separación de cargas',apoyos:['preparacion_sag','recepcion_tuneles']},disponible:true,labor_actual:{titulo:'Carga B'},en_espera:[{id:'A',titulo:'Carga A',motivo:'2 pallets pendientes de recepción. Puedes continuar otra carga.'}] });
function text(node) { return node.findAllByType('Text').map((n)=>[n.props.children].flat().filter((v)=>typeof v==='string').join('')).join(' '); }
test('La tablet distingue función, continuidad y carga en espera sin bloquear la siguiente',async()=>{
  let tree; await act(async()=>{tree=create(React.createElement(OperatorShiftDuties,{context:context()}));});
  const rendered=text(tree.root);
  assert.match(rendered,/Turno mañana/);assert.match(rendered,/Principal: Separación de cargas/);
  assert.match(rendered,/Preparación SAG → Salidas de túnel/);assert.match(rendered,/Continuar: Carga B/);
  assert.match(rendered,/En espera: Carga A.*2 pallets pendientes/);
  await act(async()=>tree.unmount());
});
test('La indisponibilidad informa cómo terminar la maniobra y solicitar habilitación',async()=>{
  let tree;await act(async()=>{tree=create(React.createElement(OperatorShiftDuties,{context:{...context(),disponible:false}}));});
  assert.match(text(tree.root),/No disponible para nuevas labores.*Termina la maniobra tomada/);
  await act(async()=>tree.unmount());
});
test('Las versiones anteriores sin turno mantienen la portada sin información ficticia',async()=>{
  let tree;await act(async()=>{tree=create(React.createElement(OperatorShiftDuties,{context:null}));});
  assert.equal(tree.toJSON(),null);await act(async()=>tree.unmount());
});
