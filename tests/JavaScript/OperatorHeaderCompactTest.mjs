import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const workspace = await read('mobile/src/screens/OperationalWorkspaceScreen.tsx');
const header = await read('mobile/src/components/operator/OperatorHeader.tsx');
const menu = await read('mobile/src/components/operator/OperatorMenu.tsx');
const app = await read('mobile/App.tsx');

test('la cabecera del camarero es una sola franja sin banner ambiental crítico', () => {
    assert.doesNotMatch(workspace, /environmentalPrompt/);
    assert.doesNotMatch(workspace, /Revisar ahora/);
    assert.match(workspace, />Mi jornada</);
    assert.match(workspace, />Plano y operación</);
    assert.match(workspace, /environmentalDue > 0 \? \(\s*<View style=\{styles\.badge\}>/);
    assert.match(workspace, /badge: \{[^}]*backgroundColor: o\.color\.warning/);
    assert.match(workspace, /control ambiental/, 'el contador conserva una etiqueta accesible');
});

test('identidad, cambio de módulo y cierre de sesión viven en el menú del operador', () => {
    assert.match(workspace, /trailing=\{<OperatorMenu/);
    assert.match(workspace, /onChangeModule=\{onChangeModule\}/);
    assert.match(workspace, /onLogout=\{\(\) => void logoutFromMenu\(\)\}/);
    // En «Plano y operación» el menú usa el cierre que revisa estibas abiertas.
    assert.match(workspace, /if \(view === 'operacion' && planLogout\.current\)/);
    assert.match(workspace, /onRegisterLogout=\{registerPlanLogout\}/);
    assert.doesNotMatch(workspace, />Salir</);
    assert.match(header, /\{trailing \?\? <Text/);
    assert.match(menu, /Cambiar módulo/);
    assert.match(menu, /Cerrar sesión/);
    assert.match(menu, /\{onChangeModule \? \(/, 'Cambiar módulo solo aparece con más de un módulo');
    assert.match(menu, /minHeight: o\.touch\.prominent/);
});

test('la franja de módulo activo no se repite sobre el espacio del camarero', () => {
    assert.match(app, /!usesOperatorWorkspace\(activeModule\)/);
    assert.match(app, /module === 'operacion' \|\| module === 'operacion_materiales'/);
    assert.match(app, /onChangeModule=\{modules\.length > 1 \? \(\) => setActiveModule\(null\) : undefined\}/);
});
