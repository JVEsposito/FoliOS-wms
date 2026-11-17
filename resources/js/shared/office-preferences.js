export const OFFICE_THEME_KEY = 'estiba_wms_office_theme';
export const OFFICE_ADMIN_THEME_KEY = 'estiba_wms_office_admin_theme';
export const OFFICE_PERSONAL_THEME_KEY = 'estiba_wms_office_personal_theme';
export const OFFICE_MENU_KEY = 'estiba_wms_office_menu';
export const OFFICE_THEMES = Object.freeze(['light-professional', 'dark-industrial', 'dark-rose', 'dark-bit', 'dark-bit-neon']);
export const DEFAULT_OFFICE_THEME = OFFICE_THEMES[0];
// Temas que el servidor habilita a cuentas puntuales (identidad.temas_personales).
export const OFFICE_PERSONAL_THEMES = Object.freeze(['dark-bit', 'dark-bit-neon']);
export const OFFICE_THEME_COLORS = Object.freeze({
    'light-professional': '#102f43',
    'dark-industrial': '#0f1c24',
    'dark-rose': '#170e14',
    'dark-bit': '#1a1c2c',
    'dark-bit-neon': '#0b0820',
});

export function officePersonalThemes(identity) {
    const themes = Array.isArray(identity?.temas_personales) ? identity.temas_personales : [];
    return OFFICE_PERSONAL_THEMES.filter((theme) => themes.includes(theme));
}

export function officeThemeStorageKey(identity) {
    if (officePersonalThemes(identity).length) return OFFICE_PERSONAL_THEME_KEY;
    return identity?.rol === 'administrador' ? OFFICE_ADMIN_THEME_KEY : OFFICE_THEME_KEY;
}

export function officeThemes(identity) {
    return [
        'light-professional',
        'dark-industrial',
        ...(identity?.rol === 'administrador' ? ['dark-rose'] : []),
        ...officePersonalThemes(identity),
    ];
}

export function normalizeOfficeTheme(value, identity) {
    if (officeThemes(identity).includes(value)) return value;
    // Un tema oscuro que esta cuenta no tiene vuelve al oscuro común.
    return OFFICE_THEMES.includes(value) && value !== DEFAULT_OFFICE_THEME ? 'dark-industrial' : DEFAULT_OFFICE_THEME;
}

export function nextOfficeTheme(value, identity) {
    const themes = officeThemes(identity);
    return themes[(themes.indexOf(normalizeOfficeTheme(value, identity)) + 1) % themes.length];
}

export function isOfficeMenuCollapsed(value) {
    return value === 'collapsed';
}

export function officeMenuPreference(collapsed) {
    return collapsed ? 'collapsed' : 'expanded';
}
