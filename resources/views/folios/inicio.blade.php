<!DOCTYPE html>
<html lang="es">
    @php
        $planta = config('oficina.planta');
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-corporate.css', 'resources/css/folios-inicio.css', 'resources/js/folios-inicio.js'])
        @endif
    </head>
    <body>
        <section class="office-access folios-inicio" id="foliosInicio" data-inicio-estado="cargando" aria-labelledby="foliosInicioTitle">
            <div class="office-access__brand">
                <x-folios-logo surface="dark" class="folios-inicio__logo" />
                <p class="eyebrow">{{ $planta ? 'FOLIOS · '.mb_strtoupper($planta) : 'FOLIOS · PLATAFORMA OPERACIONAL' }}</p>
                <h1 id="foliosInicioTitle">Un solo acceso para toda la planta.</h1>
                <p>Ingresa con tu cuenta y FoliOS te lleva directo a las oficinas habilitadas para tu perfil.</p>
                <div class="feature-row" aria-label="Áreas de FoliOS">
                    <span>Materia Prima</span><span>Frigorífico</span><span>Materiales</span><span>Consultas</span><span>Gerencia y Administración</span>
                </div>
            </div>

            <div class="office-access__form" data-inicio-panel="cargando" role="status">
                <div><p class="eyebrow">FOLIOS</p><h2>Abriendo tu inicio…</h2><p>Revisando tu sesión en este equipo.</p></div>
            </div>

            <form class="office-access__form" id="foliosLoginForm" data-inicio-panel="acceso" novalidate hidden>
                <div><p class="eyebrow">ACCESO</p><h2>Iniciar sesión</h2><p>Usa tus credenciales habituales de FoliOS.</p></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="foliosLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Ingresar <span aria-hidden="true">→</span></button>
                <p class="folios-inicio__terreno">¿Trabajas en cámara, validación o recepción? Ingresa desde la app FoliOS de tu tablet o PDA.</p>
            </form>

            <div class="office-access__form" data-inicio-panel="sin-oficinas" hidden>
                <div>
                    <p class="eyebrow">SIN OFICINAS WEB</p>
                    <h2><span id="foliosSinOficinasNombre">Tu cuenta</span> no tiene oficinas habilitadas</h2>
                    <p>Tu perfil de acceso no incluye pantallas de oficina. Solicita su revisión a un administrador; si trabajas en terreno, usa la app FoliOS de tu tablet o PDA.</p>
                </div>
                <button class="secondary-button" id="foliosLogoutButton" type="button">Cerrar sesión</button>
            </div>

            <div class="office-access__form" data-inicio-panel="error" hidden>
                <div><p class="eyebrow">SIN CONEXIÓN</p><h2>No fue posible abrir tu inicio</h2><p id="foliosErrorText"></p></div>
                <button class="primary-button" id="foliosRetryButton" type="button">Reintentar</button>
            </div>
        </section>
    </body>
</html>
