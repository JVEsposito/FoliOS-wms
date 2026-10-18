import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import test from 'node:test';

// Las tablets se usan dentro de cámaras de frío, con guantes delgados y reflejos; la PDA
// de validación tiene pantalla chica. Bajo 11 dp el texto deja de ser legible en esas
// condiciones. El mapa de posiciones conserva su densidad propia y la demo no se opera.
const EXCLUDED = new Set(['PositionMap.tsx', 'DemoDataScreen.tsx']);
const MINIMUM_FONT = 11;

async function sources() {
    const files = [];
    for (const directory of ['components', 'components/operator', 'components/validation', 'screens']) {
        const url = new URL(`../../mobile/src/${directory}/`, import.meta.url);
        for (const name of await readdir(url)) {
            if (name.endsWith('.tsx') && !EXCLUDED.has(name)) files.push({ name: `${directory}/${name}`, url: new URL(name, url) });
        }
    }
    return files;
}

test(`las pantallas operativas no usan textos menores a ${MINIMUM_FONT} dp`, async () => {
    const offenders = [];
    for (const file of await sources()) {
        const source = await readFile(file.url, 'utf8');
        for (const [, size] of source.matchAll(/fontSize: (\d+)\b/g)) {
            if (Number(size) < MINIMUM_FONT) offenders.push(`${file.name}: fontSize ${size}`);
        }
    }
    assert.deepEqual(offenders, []);
});

test('el ingreso se apila en una columna en la PDA vertical', async () => {
    const login = await readFile(new URL('../../mobile/src/screens/LoginScreen.tsx', import.meta.url), 'utf8');
    assert.match(login, /useWindowDimensions\(\)\.width < 720/);
    assert.match(login, /pageNarrow: \{ flexDirection: 'column' \}/);
});
