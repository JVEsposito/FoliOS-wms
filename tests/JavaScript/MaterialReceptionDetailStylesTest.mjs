import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const script = readFileSync(new URL('../../resources/js/office-material-receptions.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/office-materials.css', import.meta.url), 'utf8');

test('las líneas del expediente no reutilizan la clase del editor de líneas del diálogo', () => {
    // `.material-reception-line` es el editor de cada línea dentro del diálogo de recepción.
    assert.match(script, /<article class="material-reception-detail-line">/);
    assert.doesNotMatch(styles, /\.office-app \.material-reception-line\b/);
    assert.match(styles, /\.office-app \.material-reception-detail-line \{ display: grid;/);
});

test('Recepciones usa una sola pista y ordena el panel de fotos del expediente', () => {
    assert.match(styles, /\[data-materials-section="recepciones"\] \.materials-operation-grid \{ grid-template-columns: minmax\(0, 1fr\); \}/);
    assert.match(styles, /\.material-reception-photos--inline > :not\(section, h3\) \{ grid-column: 1 \/ -1;/);
});
