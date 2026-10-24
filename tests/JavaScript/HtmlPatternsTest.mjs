import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

function views(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);
        return entry.isDirectory() ? views(path) : [path];
    });
}

test('HTML input patterns compile with the Unicode sets flag used by browsers', () => {
    for (const path of views(fileURLToPath(new URL('../../resources/views/', import.meta.url)))) {
        for (const [, pattern] of readFileSync(path, 'utf8').matchAll(/\bpattern="([^"]*)"/g)) {
            assert.doesNotThrow(() => new RegExp(pattern, 'v'), `${path}: ${pattern}`);
        }
    }
});

test('warehouse and pallet codes still accept hyphens and reject invalid characters', () => {
    const html = readFileSync(new URL('../../resources/views/office/material-capacity.blade.php', import.meta.url), 'utf8');
    const patterns = [...html.matchAll(/\bpattern="([^"]*)"/g)];
    assert.equal(patterns.length, 2);
    for (const [, pattern] of patterns) {
        const expression = new RegExp(`^(?:${pattern})$`, 'v');
        for (const code of ['BOD-NORTE', 'P-2', 'A_01', 'b2']) assert.ok(expression.test(code), code);
        for (const code of ['-BOD', '_BOD', 'BOD NORTE', 'A/B', 'A@B', '']) assert.equal(expression.test(code), false, code);
    }
});
