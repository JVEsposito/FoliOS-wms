<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Despacho comercial</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite([
                'resources/css/office.css',
                'resources/css/office-raw-material-commercial-dispatch.css',
                'resources/js/office-raw-material-commercial-dispatch.js',
            ])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess" aria-labelledby="officeAccessTitle">
            <div class="office-access__brand commercial-access-brand">
                <div class="office-logo" aria-hidden="true">⇥</div>
                <p class="eyebrow">FoliOS · MATERIA PRIMA</p>
                <h1 id="officeAccessTitle">Despachos de retornos de Packing</h1>
                <p>Selecciona bins regularizados y registra el número de guía emitida en el Portal SII.</p>
            </div>
            <form class="office-access__form" id="officeLoginForm">
                <div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar al módulo</h2></div>
                <label><span>Correo electrónico</span><input name="email" type="email" autocomplete="username" required></label>
                <label><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
                <p class="form-error" id="officeLoginError" role="alert"></p>
                <button class="primary-button" type="submit">Entrar al módulo</button>
            </form>
        </section>

        <main class="office-app is-hidden" id="officeApp">
            <x-office.navigation domain="materia-prima" office="despacho-comercial" context="MATERIA PRIMA" icon="⇥" />
            <section class="commercial-workspace" id="officeContent">
                <header class="commercial-heading">
                    <div><p class="eyebrow">SALIDAS DE RETORNOS DE PACKING</p><h1>Despacho Comercial</h1><p>Precalibre, comercial, descarte y demás clasificaciones. Solo bins con folio y kilos definitivos.</p></div>
                    <button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button>
                </header>

                <section class="commercial-notice">Emite la guía electrónica en el Portal SII desde tu PC. Registra aquí únicamente su número antes de confirmar la salida. El comprobante impreso de FoliOS es interno y no reemplaza la guía SII.</section>

                <div class="commercial-grid">
                    <section class="commercial-panel" id="creationPanel">
                        <div class="commercial-panel__heading"><p class="eyebrow">PREPARACIÓN</p><h2>Nuevo despacho</h2><p>Los bins seleccionados se reservan mientras el despacho esté en borrador.</p></div>
                        <form id="createDispatchForm" class="commercial-form">
                            <label>Destinatario *<input name="destinatario" maxlength="180" placeholder="Nombre o razón social" required></label>
                            <label>Observación <textarea name="observacion" maxlength="2000" placeholder="Opcional"></textarea></label>
                            <label>Buscar bin disponible <input id="binSearch" type="search" placeholder="Folio, clasificación, lote u orden" autocomplete="off"></label>
                            <div class="commercial-bin-list" id="availableBins" aria-label="Bins disponibles"></div>
                            <p id="selectedSummary" class="commercial-summary" aria-live="polite">Selecciona al menos un bin.</p>
                            <p class="form-error" id="createError" role="alert"></p>
                            <button type="submit" class="primary-button">Crear borrador y reservar bins</button>
                        </form>
                    </section>

                    <section class="commercial-panel">
                        <div class="commercial-panel__heading"><p class="eyebrow">TRAZABILIDAD</p><h2>Despachos de la temporada</h2><p>Los confirmados conservan su número de guía SII y el detalle de cada bin.</p></div>
                        <div class="commercial-history-actions"><button id="downloadCsvButton" class="secondary-button" type="button">Descargar planilla CSV</button></div>
                        <div id="dispatchList" class="commercial-dispatch-list"></div>
                    </section>
                </div>
            </section>
        </main>

        <dialog id="confirmDialog" class="commercial-dialog">
            <form id="confirmForm">
                <div><p class="eyebrow">CONFIRMACIÓN DE SALIDA</p><h2 id="confirmTitle">Vincular guía SII</h2><p>Ingresa el número de la guía electrónica que ya emitiste en el Portal SII.</p></div>
                <label>Número de guía SII *<input name="numero_guia_sii" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="40" required autocomplete="off"></label>
                <p id="confirmError" class="form-error" role="alert"></p>
                <div class="commercial-dialog-actions"><button id="cancelConfirmButton" class="secondary-button" type="button">Volver</button><button class="primary-button" type="submit">Confirmar salida</button></div>
            </form>
        </dialog>
        <section id="printRecord" class="commercial-print" aria-hidden="true"></section>
        <div class="loading is-hidden" id="officeLoading" aria-hidden="true"><span></span><strong id="officeLoadingText">Procesando…</strong></div>
        <div class="toast-region" id="officeToasts" aria-live="polite"></div>
    </body>
</html>
