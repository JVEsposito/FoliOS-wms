import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const app = await read('mobile/App.tsx');
const typography = await read('mobile/src/theme/typography.ts');
const fontAssets = await read('mobile/src/theme/fontAssets.ts');
const environmental = await read('mobile/src/screens/EnvironmentalControlScreen.tsx');
const map = await read('mobile/src/components/PositionMap.tsx');
const plan = await read('mobile/src/screens/OperationalScreen.tsx');
const cameraCard = await read('mobile/src/components/CameraCard.tsx');

const TABLET_FILES = [
    'mobile/src/components/operator/OperatorHeader.tsx',
    'mobile/src/components/operator/OperatorTaskHome.tsx',
    'mobile/src/components/operator/OperatorTaskExecution.tsx',
    'mobile/src/components/OperationalTaskInbox.tsx',
    'mobile/src/screens/OperationalWorkspaceScreen.tsx',
    'mobile/src/screens/OperationalScreen.tsx',
    'mobile/src/screens/EnvironmentalControlScreen.tsx',
    'mobile/src/components/PositionMap.tsx',
    'mobile/src/components/ActionPanel.tsx',
    'mobile/src/components/OperationModals.tsx',
];

test('Inter y JetBrains Mono se cargan antes de dibujar y sin bloquear si fallan', () => {
    assert.match(app, /const \[fontsLoaded, fontError\] = useFonts\(fontAssets\);/);
    assert.match(app, /const fontsSettled = fontsLoaded \|\| fontError !== null;/);
    assert.match(app, /!configurationLoaded \|\| !fontsSettled/);
    for (const family of ['Inter_400Regular', 'Inter_700Bold', 'Inter_800ExtraBold', 'JetBrainsMono_700Bold']) {
        assert.match(typography, new RegExp(`'${family}'`));
    }
    assert.match(fontAssets, /@expo-google-fonts\/inter\/700Bold\/Inter_700Bold\.ttf/);
    assert.match(fontAssets, /@expo-google-fonts\/jetbrains-mono\/700Bold\/JetBrainsMono_700Bold\.ttf/);
});

test('las pantallas de la tablet eligen la familia por peso y no usan fontWeight', async () => {
    for (const file of TABLET_FILES) {
        const source = await read(file);
        assert.doesNotMatch(source, /fontWeight\s*:/, `${file} no debe usar fontWeight con fuentes cargadas`);
        assert.match(source, /fontFamily: font\./, `${file} usa la tipografía de terreno`);
    }
});

test('Ambiente usa el tema oscuro de terreno, no los componentes claros de Oficina', () => {
    assert.doesNotMatch(environmental, /EstibaPrimitives|estibaTokens/);
    assert.match(environmental, /operatorTheme as o/);
    assert.match(environmental, /const pending = ordered\.filter\(\(camera\) => camera\.requiere_control\)/);
    assert.match(environmental, /showUpToDate \? \(/, 'las cámaras al día quedan plegadas');
    assert.match(environmental, /input: \{ fontFamily: font\.monoBold, minHeight: 64/);
    // El flujo de captura no cambia.
    assert.match(environmental, /saveEnvironmentalDraft\(auth\.usuario\.id, auth\.dispositivo\.id, exactAttempt\)/);
    assert.match(environmental, /Reintentar mismo envío/);
    assert.match(environmental, /Actualizar y descartar intento/);
});

test('el plano muestra cada dato una vez y con texto legible', () => {
    assert.match(map, /orderBandsForCamera/);
    assert.match(map, /styles\.gutterCell/, 'las posiciones van en una columna, no en cada celda');
    assert.doesNotMatch(map, /\{position\.etiqueta\}<\/Text>/, 'la etiqueta B-P-N queda solo en la lectura accesible');
    assert.doesNotMatch(map, /'Disponible'/);
    assert.doesNotMatch(map, /Sin afinidad · banda liberada/);
    for (const [, size] of map.matchAll(/fontSize: (\d+)\b/g)) assert.ok(Number(size) >= 12, `fontSize ${size} en el plano`);
    assert.match(map, /minHeight: o\.touch\.minimum, paddingHorizontal/, 'el selector de nivel es táctil');
});

test('la vista Plano no repite estado de conexión ni cierre de sesión', () => {
    assert.doesNotMatch(plan, />Salir</);
    assert.match(plan, /api\.mode === 'demo' \|\| connectionState === 'offline' \? <Status/);
    assert.match(plan, /onRegisterLogout\(\(\) => logoutRef\.current\(\)\)/);
    assert.match(cameraCard, /<Text numberOfLines=\{2\} style=\{styles\.title\}>\{displayName\}<\/Text>/);
});
