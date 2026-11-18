/*
 * Arranque síncrono de Oficina. Va en línea dentro de <head> (office/partials/arranque)
 * para decidir antes del primer pintado, cuando los módulos de Vite aún no se ejecutan:
 *
 * - Tema: aplica el guardado, para que la página no aparezca un instante con el otro.
 * - Sesión: con sesión en el equipo, la oficina se pinta directamente, sin su acceso
 *   propio (office.css oculta .office-access y muestra #officeApp). Sin sesión, no pinta
 *   nada y va a /FoliOS, que vuelve a esta oficina al iniciar sesión.
 * - Menú: oculta de inmediato lo que el perfil no habilitó en la oficina anterior.
 *
 * Es un script clásico, sin import: no puede depender de los módulos que carga Vite.
 * Las claves y reglas son las de shared/office-preferences.js y shared/folios-entry.js.
 */
(function (window) {
    var document = window.document;
    var root = document.documentElement;
    var script = document.currentScript;

    function read(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function forget(key) {
        try {
            if (window.localStorage.getItem(key) !== null) window.localStorage.removeItem(key);
        } catch (error) { /* Almacenamiento no disponible. */ }
    }

    var soloTema = script && script.hasAttribute('data-solo-tema');
    var token = read('estiba_wms_office_token');
    var storedIdentity = read('estiba_wms_office_identity');
    var identity = null;
    try { identity = JSON.parse(storedIdentity || 'null'); } catch (error) { /* identidad ilegible */ }
    var themeIdentity = token && !soloTema ? identity : null;
    var administrator = themeIdentity?.rol === 'administrador';
    var themeColors = {
        'light-professional': '#102f43',
        'dark-industrial': '#0f1c24',
        'dark-princess': '#18142e',
        'light-garden': '#fff3f7',
        'dark-bit': '#1a1c2c',
        'dark-bit-neon': '#0b0820',
    };
    var themes = Object.keys(themeColors);
    // Claro y oscuro siempre; los demás, los que el perfil de acceso habilita (identidad.temas_oficina).
    // Sin esa lista: los del Administrador y los habilitados por correo (identidad.temas_personales).
    var additional = Array.isArray(themeIdentity?.temas_oficina)
        ? themeIdentity.temas_oficina
        : [
            ...(administrator ? ['dark-princess', 'light-garden'] : []),
            ...['dark-bit', 'dark-bit-neon'].filter(function (item) {
                return Array.isArray(themeIdentity?.temas_personales) && themeIdentity.temas_personales.includes(item);
            }),
        ];
    var personalThemes = themeIdentity?.temas_oficina_personales || {};
    var allowed = [...themes, ...Object.keys(personalThemes)].filter(function (item, index) { return index < 2 || additional.includes(item); });
    var commonKey = 'estiba_wms_office_theme';
    var preferenceKey = administrator ? 'estiba_wms_office_admin_theme' : allowed.length > 2 ? 'estiba_wms_office_personal_theme' : commonKey;
    var theme = read(preferenceKey) || read(commonKey);
    var personalColor = null;
    if (theme === 'dark-rose') theme = 'dark-princess';
    if (!allowed.includes(theme)) {
        theme = /^personal-[a-z0-9-]+$/.test(theme) || (themes.includes(theme) && theme.startsWith('dark-')) ? 'dark-industrial' : 'light-professional';
    } else if (Object.hasOwn(personalThemes, theme)) {
        // Un paquete personal se pinta sobre su base clara u oscura. La copia que esta sesión ya
        // descargó se aplica antes de pintar; si no, se ve la base hasta que llega la hoja.
        personalColor = applyRememberedPersonalTheme(theme, token) ? personalThemes[theme].color : null;
        theme = personalThemes[theme].claro ? 'light-professional' : 'dark-industrial';
    }
    // Sin sesión se descarta la copia del paquete personal de la sesión anterior.
    if (!token) forget('estiba_wms_office_personal_css');
    root.setAttribute('data-office-theme', theme);
    var scheme = document.querySelector('meta[name="color-scheme"]');
    if (scheme) scheme.setAttribute('content', theme.startsWith('dark-') ? 'dark' : 'light');
    var color = document.querySelector('meta[name="theme-color"]');
    if (color) color.setAttribute('content', personalColor || themeColors[theme]);

    // La entrada /FoliOS solo toma el tema: ella misma decide qué mostrar.
    if (soloTema) return;

    // Una cuenta de cliente solo usa su portal: las oficinas internas la devuelven ahí.
    if (token && identity?.es_cliente === true) {
        window.location.replace('/oficina/clientes');
        return;
    }
    if (token && storedIdentity) {
        root.setAttribute('data-office-session', 'activa');
        hideRememberedMenu(token);
        return;
    }

    root.setAttribute('data-office-session', 'ausente');
    var path = window.location.pathname;
    var back = /^\/oficina(?:\/[a-z0-9-]+)*$/.test(path)
        ? path + window.location.search + window.location.hash
        : '';
    window.location.replace('/FoliOS' + (back ? '?volver=' + encodeURIComponent(back) : ''));

    // office-navigation.js guarda la última hoja personal descargada, atada a la sesión. Va al
    // final de <head> (office/partials/arranque la mueve detrás de los estilos de Oficina), porque
    // sus reglas tienen la misma especificidad que las del tema base y deben ganarle.
    function applyRememberedPersonalTheme(personalTheme, currentToken) {
        var remembered = null;
        try {
            remembered = JSON.parse(read('estiba_wms_office_personal_css') || 'null');
        } catch (error) {
            return false;
        }
        if (!remembered || remembered.sesion !== currentToken.slice(-16) || remembered.tema !== personalTheme
            || typeof remembered.css !== 'string') return false;

        var style = document.createElement('style');
        style.id = 'officePersonalTheme';
        style.textContent = remembered.css;
        document.head.appendChild(style);
        root.setAttribute('data-office-personal-theme', personalTheme);
        return true;
    }

    // El menú se arma igual en todas las oficinas y office-navigation.js oculta lo que el
    // perfil no habilita. Esa lista se recuerda en la pestaña y se aplica aquí, para que un
    // perfil acotado no vea un instante todas las áreas al cambiar de oficina.
    function hideRememberedMenu(currentToken) {
        var remembered = null;
        try {
            remembered = JSON.parse(window.sessionStorage.getItem('estiba_wms_office_menu') || 'null');
        } catch (error) {
            return;
        }
        if (!remembered || remembered.sesion !== currentToken.slice(-16) || !remembered.ocultos) return;

        var selectors = [];
        for (var index = 0; index < remembered.ocultos.length; index += 1) {
            var selector = remembered.ocultos[index];
            if (typeof selector === 'string' && /^(\[data-[a-z-]+="[a-z0-9_.,-]*"\])+$/.test(selector)) {
                selectors.push(selector);
            }
        }
        if (!selectors.length) return;

        var style = document.createElement('style');
        style.id = 'officeMenuRemembered';
        style.textContent = selectors.join(',') + '{display:none!important}';
        document.head.appendChild(style);
    }
}(window));
