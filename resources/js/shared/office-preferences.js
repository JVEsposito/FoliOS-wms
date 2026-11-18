export const OFFICE_THEME_KEY = 'estiba_wms_office_theme';
export const OFFICE_ADMIN_THEME_KEY = 'estiba_wms_office_admin_theme';
export const OFFICE_PERSONAL_THEME_KEY = 'estiba_wms_office_personal_theme';
export const OFFICE_MENU_KEY = 'estiba_wms_office_menu';
const STANDARD_OFFICE_THEMES = ['light-professional', 'dark-industrial'];
const ADMIN_OFFICE_THEMES = ['dark-princess', 'light-garden'];
export const DEFAULT_OFFICE_THEME = STANDARD_OFFICE_THEMES[0];
// Temas que el servidor habilita a cuentas puntuales (identidad.temas_personales).
export const OFFICE_PERSONAL_THEMES = Object.freeze(['dark-bit', 'dark-bit-neon']);
export const OFFICE_THEMES = Object.freeze([...STANDARD_OFFICE_THEMES, ...ADMIN_OFFICE_THEMES, ...OFFICE_PERSONAL_THEMES]);
export const OFFICE_THEME_COLORS = Object.freeze({
    'light-professional': '#102f43',
    'dark-industrial': '#0f1c24',
    'dark-princess': '#18142e',
    'light-garden': '#fff3f7',
    'dark-bit': '#1a1c2c',
    'dark-bit-neon': '#0b0820',
});

export function officePersonalThemes(identity) {
    const themes = Array.isArray(identity?.temas_personales) ? identity.temas_personales : [];
    return OFFICE_PERSONAL_THEMES.filter((theme) => themes.includes(theme));
}

export function officeThemeStorageKey(identity) {
    // El Administrador conserva su clave aunque también tenga temas personales.
    if (identity?.rol === 'administrador') return OFFICE_ADMIN_THEME_KEY;
    return officePersonalThemes(identity).length ? OFFICE_PERSONAL_THEME_KEY : OFFICE_THEME_KEY;
}

export function officeThemes(identity) {
    return [
        ...STANDARD_OFFICE_THEMES,
        ...(identity?.rol === 'administrador' ? ADMIN_OFFICE_THEMES : []),
        ...officePersonalThemes(identity),
    ];
}

export function normalizeOfficeTheme(value, identity) {
    // Compatibilidad con la preferencia guardada antes de reemplazar el modo rosado.
    if (value === 'dark-rose') value = 'dark-princess';
    if (officeThemes(identity).includes(value)) return value;
    return OFFICE_THEMES.includes(value) && officeThemeColorScheme(value) === 'dark' ? 'dark-industrial' : DEFAULT_OFFICE_THEME;
}

export function officeThemeColorScheme(theme) {
    return theme.startsWith('dark-') ? 'dark' : 'light';
}
export function isOfficeMenuCollapsed(value) {
    return value === 'collapsed';
}

export function officeMenuPreference(collapsed) {
    return collapsed ? 'collapsed' : 'expanded';
}
