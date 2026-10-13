import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const root = new URL('../', import.meta.url);
const tokens = JSON.parse(await readFile(new URL('design/estiba.tokens.json', root), 'utf8'));
const kebab = (value) => value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

// Claves de nivel superior que son overrides de modo oscuro, no tokens propios:
// se excluyen del árbol claro y se aplanan aparte, reutilizando el nombre de su
// contraparte clara (colorDark -> color, signalDark -> signal, areaDark -> area).
const DARK_KEYS = { colorDark: 'color', signalDark: 'signal', areaDark: 'area' };

function flatten(value, path, entries) {
    for (const [key, item] of Object.entries(value)) {
        const next = [...path, key];
        if (typeof item === 'object' && item !== null) {
            flatten(item, next, entries);
            continue;
        }
        const unit = ['fontWeight', 'lineHeight'].includes(next[0]) ? '' : 'px';
        const rendered = next[0] === 'fontSize'
            ? `${item / 16}rem`
            : typeof item === 'number' ? `${item}${unit}` : item;
        entries.push(`    --eui-${next.map(kebab).join('-')}: ${rendered};`);
    }
}

const entries = [];
const darkEntries = [];

for (const [key, value] of Object.entries(tokens)) {
    if (key in DARK_KEYS) continue;
    flatten(value, [key], entries);
}

for (const [darkKey, lightAlias] of Object.entries(DARK_KEYS)) {
    if (tokens[darkKey]) flatten(tokens[darkKey], [lightAlias], darkEntries);
}

const outputs = new Map([
    ['resources/css/estiba-tokens.css', [
        '/* Generado desde design/estiba.tokens.json. Ejecutar npm run design:tokens. */',
        '.estiba-ui {', ...entries, '}', '',
        '/* Modo oscuro: sobreescrituras aplicadas dentro del shell de Oficina real cuando */',
        '/* office-preferences.js fija data-office-theme="dark-industrial" en <html>. El   */',
        '/* selector de atributo va en :where() a propósito: sin eso, esta regla (0,3,0)   */',
        '/* le gana en especificidad a los puentes .operation-now/.discrepancies-shell     */',
        '/* (0,1,0 y 0,2,0) que remapean --eui-color-* a los tokens de Oficina, y en modo   */',
        '/* oscuro pisaría esos puentes sin importar el orden de carga. :where() deja la    */',
        '/* especificidad efectiva en la de .estiba-ui sola (0,1,0), empatada con esos      */',
        '/* puentes, y el desempate por orden de aparición sí favorece al puente porque     */',
        '/* office-operation-now.css/office-corporate.css se cargan después.               */',
        ':where(:root[data-office-theme="dark-industrial"]) .estiba-ui {', ...darkEntries, '}', '',
    ].join('\n')],
    ['mobile/src/theme/estibaTokens.ts', [
        '// Generado desde design/estiba.tokens.json. Ejecutar npm run design:tokens.',
        `export const estibaTokens = ${JSON.stringify(tokens, null, 2)} as const;`,
        '',
        'export type EstibaTone = keyof typeof estibaTokens.signal;',
        '',
    ].join('\n')],
]);

for (const [path, expected] of outputs) {
    const url = new URL(path, root);
    if (process.argv.includes('--check')) {
        const current = await readFile(url, 'utf8').catch(() => '');
        if (current !== expected) {
            process.stderr.write(`Tokens desactualizados: ${path}. Ejecutar npm run design:tokens.\n`);
            process.exitCode = 1;
        }
    } else {
        await writeFile(url, expected);
        process.stdout.write(`Generado ${fileURLToPath(url)}\n`);
    }
}
