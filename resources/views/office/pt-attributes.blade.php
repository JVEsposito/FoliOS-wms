<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Cambio de atributos PT</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-validation-correction.css', 'resources/css/office-pt-attributes.css', 'resources/js/office-pt-attributes.js'])
        @endif
        @include('office.partials.arranque')
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand">
                <div class="office-logo" aria-hidden="true">✎</div>
                <p class="eyebrow">FoliOS · ADMINISTRACIÓN</p>
                <h1 id="officeAccessTitle">Corrige un folio PT sin borrar su historia.</h1>
                <p>Cada cambio pide motivo y queda registrado con el antes y el después.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm" novalidate>
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar <span>→</span></button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="administracion" office="atributos-pt" context="ADMINISTRACIÓN & GERENCIA" icon="✎" />

            <section class="pta-workspace">
                <header class="pta-heading panel">
                    <div>
                        <p class="eyebrow">NIVEL ADMINISTRADOR</p>
                        <h1>Cambio de atributos PT</h1>
                        <p>Corrige producto, embalaje, calibre, cajas, CSG y estado de prefrío de un folio que aún no sale de la planta. Cada cambio pide motivo y queda en el historial del folio.</p>
                    </div>
                    <form class="pta-search" id="ptaSearch" role="search">
                        <label><span>Folio</span><input name="folio" maxlength="50" autocomplete="off" placeholder="Escanea o escribe el folio" required></label>
                        <button class="primary-button" type="submit">Buscar</button>
                    </form>
                </header>

                <p class="pta-empty panel" id="ptaEmpty">Busca un folio para ver sus atributos y cambiarlos.</p>

                <div class="pta-folio is-hidden" id="ptaFolio">
                    <section class="panel pta-summary" aria-labelledby="ptaFolioTitle">
                        <div class="pta-summary__head">
                            <div>
                                <p class="eyebrow" id="ptaFolioType">PALLET</p>
                                <h2 id="ptaFolioTitle">—</h2>
                                <p id="ptaFolioState">—</p>
                            </div>
                            <a class="secondary-button" id="ptaLabelLink" href="/oficina/validacion/etiquetas">Reimprimir etiqueta</a>
                        </div>
                        <div class="pta-notice" id="ptaNotice" role="status" hidden></div>
                        <dl class="pta-attributes" id="ptaAttributes"></dl>
                    </section>

                    <div class="pta-actions">
                        <section class="panel pta-card" aria-labelledby="ptaProductTitle">
                            <p class="eyebrow">PRODUCTO</p>
                            <h3 id="ptaProductTitle">Producto, embalaje, calibre, cajas y CSG</h3>
                            <p>Usa el catálogo de la temporada, igual que la validación. El folio conserva su cámara, su carga y su número.</p>
                            <p class="pta-card__blocked" id="ptaProductBlocked" hidden></p>
                            <button class="primary-button" id="ptaProductButton" type="button">Cambiar producto</button>
                        </section>

                        <section class="panel pta-card" aria-labelledby="ptaThermalTitle">
                            <p class="eyebrow">PREFRÍO</p>
                            <h3 id="ptaThermalTitle">Estado de prefrío</h3>
                            <form class="pta-thermal" id="ptaThermalForm" novalidate>
                                <fieldset class="pta-conditions" id="ptaConditions">
                                    <legend>Nuevo estado</legend>
                                </fieldset>
                                <p class="pta-thermal__effect" id="ptaThermalEffect" aria-live="polite"></p>
                                <label><span>Motivo del cambio *</span><textarea name="motivo" rows="3" maxlength="1000" placeholder="Qué pasó y por qué se corrige" required></textarea></label>
                                <p class="form-error" id="ptaThermalError" role="alert"></p>
                                <button class="primary-button" id="ptaThermalSubmit" type="submit">Cambiar estado de prefrío</button>
                            </form>
                            <p class="pta-card__blocked" id="ptaThermalBlocked" hidden></p>
                        </section>
                    </div>

                    <section class="panel pta-card pta-annul" aria-labelledby="ptaAnnulTitle">
                        <p class="eyebrow">ANULAR VALIDACIÓN</p>
                        <h3 id="ptaAnnulTitle">Anular una validación errónea</h3>
                        <p>Para un pallet que no existe, quedó duplicado o se validó con datos que no corresponden. El folio queda anulado con todo su historial, libera su posición y, si ya tuvo actividad, su número queda libre para validar el pallet correcto.</p>
                        <form class="pta-annul__form" id="ptaAnnulForm" novalidate>
                            <label><span>Categoría *</span><select name="motivo_categoria" required><option value="">Seleccionar</option></select></label>
                            <label><span>Motivo de la anulación *</span><textarea name="motivo" rows="3" maxlength="2000" placeholder="Qué pasó y cómo se comprobó" required></textarea></label>
                            <label class="pta-annul__confirm"><input type="checkbox" name="confirmar"><span id="ptaAnnulConfirmText">Confirmo que esta validación es errónea y debe anularse.</span></label>
                            <p class="form-error" id="ptaAnnulError" role="alert"></p>
                            <button class="danger-button" id="ptaAnnulSubmit" type="submit">Anular validación</button>
                        </form>
                        <p class="pta-card__blocked" id="ptaAnnulBlocked" hidden></p>
                    </section>

                    <section class="panel pta-history" aria-labelledby="ptaHistoryTitle">
                        <p class="eyebrow">TRAZABILIDAD</p>
                        <h3 id="ptaHistoryTitle">Cambios de administración</h3>
                        <div id="ptaHistory"></div>
                    </section>
                </div>
            </section>
        </main>

        @include('office.partials.validation-correction-dialog')

        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
