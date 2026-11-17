export const OFFICE_THEME_KEY = 'estiba_wms_office_theme';
export const OFFICE_ADMIN_THEME_KEY = 'estiba_wms_office_admin_theme';
export const OFFICE_MENU_KEY = 'estiba_wms_office_menu';
export const OFFICE_THEMES = Object.freeze(['light-professional', 'dark-industrial', 'dark-rose']);
export const DEFAULT_OFFICE_THEME = OFFICE_THEMES[0];

export function officeThemeStorageKey(identity) {
    return identity?.rol === 'administrador' ? OFFICE_ADMIN_THEME_KEY : OFFICE_THEME_KEY;
}

export function officeThemes(identity) {
    return identity?.rol === 'administrador' ? [...OFFICE_THEMES] : OFFICE_THEMES.slice(0, 2);
}

export function normalizeOfficeTheme(value, identity) {
    if (value === 'dark-rose' && identity?.rol !== 'administrador') return 'dark-industrial';
    return OFFICE_THEMES.includes(value) ? value : DEFAULT_OFFICE_THEME;
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
