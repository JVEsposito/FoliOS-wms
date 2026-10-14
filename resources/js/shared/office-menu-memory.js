/**
 * Menú recordado de Oficina. office-navigation.js guarda en la pestaña lo que el perfil no
 * habilita; office-arranque.js (script en línea de <head>) lo oculta en la próxima oficina
 * antes de pintar, con la misma validación de selectores.
 */
export const MENU_MEMORY_KEY = 'estiba_wms_office_menu';

export const MENU_ATTRIBUTES = Object.freeze([
    'data-office-key',
    'data-office-domain',
    'data-domain-key',
    'data-navigation-permissions',
    'data-navigation-module',
]);

const SAFE_VALUE = /^[a-z0-9_.,-]*$/;

/** Selector de atributos que identifica el mismo elemento del menú en cualquier oficina. */
export function menuSelector(element) {
    const parts = MENU_ATTRIBUTES
        .filter((name) => element.hasAttribute(name))
        .map((name) => [name, element.getAttribute(name)]);
    if (!parts.length || parts.some(([, value]) => !SAFE_VALUE.test(value))) return null;

    return parts.map(([name, value]) => `[${name}="${value}"]`).join('');
}

/** Lo que se guarda: ligado a la sesión, para no aplicarlo a otra cuenta en la pestaña. */
export function menuMemory(token, hiddenElements) {
    return {
        sesion: String(token).slice(-16),
        ocultos: [...new Set(hiddenElements.map(menuSelector).filter(Boolean))],
    };
}
