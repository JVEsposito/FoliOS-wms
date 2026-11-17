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

    var soloTema = script && script.hasAttribute('data-solo-tema');
    var token = read('estiba_wms_office_token');
    var storedIdentity = read('estiba_wms_office_identity');
    var identity = null;
    try { identity = JSON.parse(storedIdentity || 'null'); } catch (error) { /* identidad ilegible */ }
    var administrator = token && !soloTema && identity?.rol === 'administrador';
    // Temas personales: solo para las cuentas que el servidor habilita (identidad.temas_personales).
    var personales = token && !soloTema && Array.isArray(identity?.temas_personales) ? identity.temas_personales : [];
    var personal = personales.indexOf('dark-bit') !== -1 || personales.indexOf('dark-bit-neon') !== -1;
    var theme = (personal && read('estiba_wms_office_personal_theme'))
        || (administrator && read('estiba_wms_office_admin_theme'))
        || read('estiba_wms_office_theme');
    if (theme === 'dark-rose' && !administrator) theme = 'dark-industrial';
    if ((theme === 'dark-bit' || theme === 'dark-bit-neon') && personales.indexOf(theme) === -1) theme = 'dark-industrial';
    if (['light-professional', 'dark-industrial', 'dark-rose', 'dark-bit', 'dark-bit-neon'].indexOf(theme) === -1) theme = 'light-professional';
    var dark = theme !== 'light-professional';
    root.setAttribute('data-office-theme', theme);
    var scheme = document.querySelector('meta[name="color-scheme"]');
    if (scheme) scheme.setAttribute('content', dark ? 'dark' : 'light');
    var color = document.querySelector('meta[name="theme-color"]');
    if (color) color.setAttribute('content', theme === 'dark-bit' ? '#1a1c2c' : theme === 'dark-bit-neon' ? '#0b0820' : theme === 'dark-rose' ? '#170e14' : dark ? '#0f1c24' : '#102f43');

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
