import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DEFAULT_OFFICE_THEME,
    isOfficeMenuCollapsed,
    normalizeOfficeTheme,
    OFFICE_THEME_COLORS,
    OFFICE_THEMES,
    officeThemeColorScheme,
    officeThemes,
    officeThemeStorageKey,
    officeMenuPreference,
} from '../../resources/js/shared/office-preferences.js';

test('las cuentas sin rol Administrador ven los temas claro y oscuro', () => {
    assert.equal(normalizeOfficeTheme('dark-industrial'), 'dark-industrial');
    assert.equal(normalizeOfficeTheme('light-professional'), 'light-professional');
    assert.equal(normalizeOfficeTheme('light-warm'), DEFAULT_OFFICE_THEME);
    assert.equal(normalizeOfficeTheme(null), DEFAULT_OFFICE_THEME);
    const supervisor = { rol: 'supervisor_frio', puede_administrar_accesos: true };
    assert.equal(normalizeOfficeTheme('dark-rose', supervisor), 'dark-industrial');
    assert.equal(normalizeOfficeTheme('dark-princess', supervisor), 'dark-industrial');
    assert.equal(normalizeOfficeTheme('light-garden', supervisor), 'light-professional');
    assert.deepEqual(officeThemes(supervisor), ['light-professional', 'dark-industrial']);
});

test('el Administrador puede elegir Estelar o Jardín y recuperar su preferencia anterior', () => {
    const admin = { rol: 'administrador' };
    assert.deepEqual(officeThemes(admin), ['light-professional', 'dark-industrial', 'dark-princess', 'light-garden']);
    assert.equal(normalizeOfficeTheme('dark-rose', admin), 'dark-princess');
    assert.equal(normalizeOfficeTheme('light-garden', admin), 'light-garden');
    assert.equal(officeThemeColorScheme('dark-princess'), 'dark');
    assert.equal(officeThemeColorScheme('light-garden'), 'light');
    assert.notEqual(officeThemeStorageKey(admin), officeThemeStorageKey({ rol: 'supervisor_frio' }));
});

test('los modos bit y neón solo aparecen en las cuentas que el servidor habilita y guardan su elección aparte', () => {
    const personal = { rol: 'supervisor_frio', temas_personales: ['dark-bit'] };
    assert.deepEqual(officeThemes(personal), ['light-professional', 'dark-industrial', 'dark-bit']);
    assert.equal(normalizeOfficeTheme('dark-rose', personal), 'dark-industrial');
    const adminPersonal = { rol: 'administrador', temas_personales: ['dark-bit', 'dark-bit-neon', 'tema-desconocido'] };
    assert.deepEqual(officeThemes(adminPersonal), ['light-professional', 'dark-industrial', 'dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon']);
    assert.equal(normalizeOfficeTheme('dark-bit-neon', personal), 'dark-industrial');
    // El Administrador conserva su clave y la cuenta habilitada por correo usa la personal.
    assert.equal(officeThemeStorageKey(adminPersonal), officeThemeStorageKey({ rol: 'administrador' }));
    assert.notEqual(officeThemeStorageKey(personal), officeThemeStorageKey({ rol: 'supervisor_frio' }));
    assert.notEqual(officeThemeStorageKey(personal), officeThemeStorageKey(adminPersonal));
    for (const other of [{ rol: 'administrador' }, { rol: 'consulta' }, null]) {
        assert.equal(normalizeOfficeTheme('dark-bit', other), 'dark-industrial');
        assert.equal(normalizeOfficeTheme('dark-bit-neon', other), 'dark-industrial');
        assert.equal(officeThemes(other).some((theme) => theme.startsWith('dark-bit')), false);
    }
    assert.deepEqual(Object.keys(OFFICE_THEME_COLORS), [...OFFICE_THEMES]);
});

test('serializa el estado persistente del menú lateral', () => {
    assert.equal(officeMenuPreference(true), 'collapsed');
    assert.equal(officeMenuPreference(false), 'expanded');
    assert.equal(isOfficeMenuCollapsed('collapsed'), true);
    assert.equal(isOfficeMenuCollapsed('expanded'), false);
});

test('la configuración del perfil prevalece y los temas locales requieren metadatos autorizados', () => {
    const perfil = { rol: 'administrador', temas_personales: ['dark-bit'], temas_oficina: [] };
    assert.deepEqual(officeThemes(perfil), ['light-professional', 'dark-industrial']);
    const asignado = { rol: 'consulta', temas_oficina: ['light-garden', 'dark-princess', 'personal-prueba'] };
    assert.deepEqual(officeThemes(asignado), ['light-professional', 'dark-industrial', 'dark-princess', 'light-garden']);
    assert.equal(officeThemeStorageKey(asignado), 'estiba_wms_office_personal_theme');
    assert.equal(normalizeOfficeTheme('dark-rose', asignado), 'dark-princess');
    assert.equal(normalizeOfficeTheme('dark-bit', asignado), 'dark-industrial');
    assert.equal(normalizeOfficeTheme('personal-prueba', asignado), 'dark-industrial');
    asignado.temas_oficina_personales = { 'personal-prueba': { nombre: 'Prueba' } };
    assert.deepEqual(officeThemes(asignado), ['light-professional', 'dark-industrial', 'dark-princess', 'light-garden', 'personal-prueba']);
    assert.equal(normalizeOfficeTheme('personal-prueba', asignado), 'personal-prueba');
});
