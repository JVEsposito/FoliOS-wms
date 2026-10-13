import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('la familia oficial de marca incluye variantes claras y para fondo oscuro', () => {
    const assets = [
        'folios-symbol.svg',
        'folios-symbol-on-dark.svg',
        'folios-lockup-horizontal.svg',
        'folios-lockup-horizontal-on-dark.svg',
        'folios-lockup-stacked.svg',
        'folios-lockup-stacked-on-dark.svg',
    ];

    for (const asset of assets) {
        const path = `public/brand/folios/${asset}`;
        const source = read(path);
        assert.match(source, /<title>FoliOS<\/title>/, asset);
        assert.match(source, /viewBox="0 0 /, asset);
        assert.ok(statSync(new URL(path, root)).size > 300, asset);
    }
});

test('las superficies principales consumen la marca FoliOS', () => {
    const officeNavigation = read('resources/views/components/office/navigation.blade.php');
    const cameraOperation = read('resources/views/welcome.blade.php');
    const mobileLogin = read('mobile/src/screens/LoginScreen.tsx');
    const operatorHeader = read('mobile/src/components/operator/OperatorHeader.tsx');
    const operationalScreen = read('mobile/src/screens/OperationalScreen.tsx');

    assert.match(officeNavigation, /<x-folios-logo surface="dark"/);
    assert.equal((cameraOperation.match(/<x-folios-logo/g) ?? []).length, 2);
    assert.match(mobileLogin, /folios-lockup-horizontal-on-dark\.png/);
    // FoliOS v2: en terreno el encabezado lleva el gajo del área (símbolo del logo).
    assert.match(operatorHeader, /source=\{definition\.gajo\}/);
    assert.doesNotMatch(operationalScreen, /folios-lockup-horizontal-on-dark\.png/);
});

test('las superficies heredadas usan el frost oficial como acento', () => {
    assert.match(read('resources/css/app.css'), /--cyan:\s*#5fd3e6/);
    assert.match(read('mobile/src/theme/colors.ts'), /cyan:\s*'#5FD3E6'/);
    assert.match(read('mobile/src/theme/colors.ts'), /cyanDark:\s*'#0F5C6E'/);
});

test('el cambio visible conserva identificadores técnicos compatibles', () => {
    const config = JSON.parse(read('mobile/app.json')).expo;
    assert.equal(config.name, 'FoliOS');
    assert.match(config.version, /^\d+\.\d+\.\d+$/);
    assert.equal(config.runtimeVersion.policy, 'appVersion');
    assert.equal(config.slug, 'estiba-wms-camaras');
    assert.equal(config.android.package, 'cl.estiba.wms.camaras');
    assert.ok(Number.isInteger(config.android.versionCode) && config.android.versionCode >= 5);

    const visibleSources = [
        'resources/views/welcome.blade.php',
        'resources/views/components/office/navigation.blade.php',
        'mobile/App.tsx',
        'mobile/src/screens/LoginScreen.tsx',
        'mobile/src/components/operator/OperatorHeader.tsx',
    ].map(read).join('\n');

    assert.doesNotMatch(visibleSources, /Estiba WMS|ESTIBA WMS/);
});
