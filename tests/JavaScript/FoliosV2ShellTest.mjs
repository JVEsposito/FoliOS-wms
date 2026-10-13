import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const shell = readFileSync(new URL('../../resources/css/office-shell.css', import.meta.url), 'utf8');
const gajo = readFileSync(new URL('../../resources/views/components/folios/gajo.blade.php', import.meta.url), 'utf8');
const components = readFileSync(new URL('../../resources/css/folios-components.css', import.meta.url), 'utf8');

test('FoliOS v2: la barra de contexto lleva la regla de 3 px del área activa', () => {
    assert.match(shell, /\.estiba-office-header::after\s*\{[^}]*height:\s*3px;[^}]*background:\s*var\(--area-ink/);
    assert.match(shell, /--office-sidebar-width:\s*248px/);
});

test('FoliOS v2: cada hoja del gajo toma el color de su área', () => {
    for (const area of ['materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion']) {
        assert.match(components, new RegExp(`\\.folios-gajo\\[data-area="${area}"\\]\\s*\\{\\s*--gajo-bright:\\s*var\\(--area-${area}-bright\\b[^;]*;\\s*--gajo-ink:\\s*var\\(--area-${area}-ink\\b`));
        assert.match(gajo, new RegExp(`'${area}' => 'M16,16 `));
    }
});

test('FoliOS v2: el shell no usa colores fijos de texto sobre la barra clara', () => {
    const header = shell.slice(shell.indexOf('/* Barra de contexto'), shell.indexOf('/* Menú lateral'));
    assert.doesNotMatch(header.replace(/\.estiba-office-brand[\s\S]*?\}/g, '').replace(/\.estiba-office-menu[\s\S]*?\}/g, ''), /color:\s*#fff/i);
});
