<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07151e">
        <meta name="color-scheme" content="light dark">
        <title>FoliOS · Cuenta corriente de envases</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/office.css', 'resources/css/office-container-accounts.css', 'resources/js/office-container-accounts.js'])
        @endif
    </head>
    <body>
        <section class="office-access" id="officeAccess">
            <div class="office-access__brand"><div class="office-logo">⇄</div><p class="eyebrow">FoliOS · ENVASES</p><h1>Consulta la cuenta corriente con trazabilidad a guía, cliente y hora.</h1><p>Lo declarado queda pendiente hasta Validación MP; lo validado confirma el movimiento y nunca reemplaza la evidencia de Romana.</p></div>
            <form class="office-access__form" id="officeLoginForm"><div><p class="eyebrow">ACCESO DE OFICINA</p><h2>Ingresar a cuenta corriente</h2></div><label><span>Correo electrónico</span><input name="email" type="email" required></label><label><span>Contraseña</span><input name="password" type="password" required></label><p class="form-error" id="officeLoginError"></p><button class="primary-button" type="submit">Ingresar <span>→</span></button></form>
        </section>
        <main class="office-app is-hidden" id="officeApp">
            
            <x-office.navigation domain="materia-prima" office="envases" context="MATERIA PRIMA" icon="▣" />

            <section class="accounts-workspace">
                <header class="accounts-heading"><div><p class="eyebrow">CONTROL Y CONCILIACIÓN</p><h1>Cuenta corriente de envases</h1><p>La operación muestra la temporada activa; selecciona otra temporada sólo para consultar su historial.</p></div><button class="secondary-button" id="reloadButton" type="button">↻ Actualizar</button></header>
                <div class="accounts-kpis"><article><span>MOVIMIENTOS CONFIRMADOS</span><strong id="confirmedCount">0</strong></article><article><span>ENVASES RESERVADOS</span><strong id="reservedCount">0</strong></article><article><span>PENDIENTES DE VALIDACIÓN</span><strong id="pendingCount">0</strong></article><article><span>OBSERVADOS</span><strong id="observedCount">0</strong></article><article><span>ÚLTIMA SINCRONIZACIÓN</span><strong id="syncTime">—</strong></article></div>
                <section class="panel accounts-filters"><form id="filtersForm"><input name="buscar" placeholder="Guía, recepción o cliente"><select name="cliente_id"><option value="">Todos los clientes</option></select><select name="temporada_id"><option value="">Temporada activa</option></select><select name="tipo_envase"><option value="">Todos los envases</option></select><select name="estado_revision"><option value="">Toda revisión</option><option value="pendiente">Pendiente</option><option value="revisado">Revisado</option><option value="observado">Observado</option></select><input name="desde" type="date"><input name="hasta" type="date"><button class="primary-button" type="submit">Aplicar filtros</button></form></section>

                <x-office.panel-switcher
                    id="container-accounts"
                    label="Vistas de cuenta corriente"
                    default="balances"
                    :panels="[
                        'balances' => ['label' => 'Saldos', 'icon' => '∑'],
                        'pending' => ['label' => 'Pendientes', 'icon' => '◷'],
                        'reservations' => ['label' => 'Reservas', 'icon' => '◇'],
                        'movements' => ['label' => 'Movimientos', 'icon' => '⇄'],
                    ]"
                />

                <div class="accounts-grid office-panel-workspace">
                    <section class="panel" id="container-accounts-panel-balances" data-office-panel-group="container-accounts" data-office-panel-id="balances" role="tabpanel" aria-labelledby="container-accounts-tab-balances"><div class="panel-heading"><div><p class="eyebrow">SALDOS</p><h2>Por cliente y tipo</h2></div></div><div class="balance-list" id="balanceList"></div></section>
                    <section class="panel" id="container-accounts-panel-pending" data-office-panel-group="container-accounts" data-office-panel-id="pending" role="tabpanel" aria-labelledby="container-accounts-tab-pending"><div class="panel-heading"><div><p class="eyebrow">EN VERDE</p><h2>Pendiente de Validación MP</h2></div></div><div class="pending-list" id="pendingList"></div></section>
                </div>
                <section class="panel" id="container-accounts-panel-reservations" data-office-panel-group="container-accounts" data-office-panel-id="reservations" role="tabpanel" aria-labelledby="container-accounts-tab-reservations"><div class="panel-heading"><div><p class="eyebrow">RESERVAS OPERACIONALES</p><h2>Borradores de despacho</h2></div><small>No modifican el saldo hasta confirmar la salida.</small></div><div class="reservation-list" id="reservationList"></div></section>
                <section class="panel movements-panel" id="container-accounts-panel-movements" data-office-panel-group="container-accounts" data-office-panel-id="movements" role="tabpanel" aria-labelledby="container-accounts-tab-movements"><div class="panel-heading"><div><p class="eyebrow">KARDEX DOCUMENTAL</p><h2>Movimientos confirmados</h2></div><small>Ingreso y salida conservan fecha y hora exactas.</small></div><div class="accounts-table-scroll"><table><thead><tr><th>Fecha y hora</th><th>Cliente / documento</th><th>Envase</th><th>Cuenta</th><th>Propiedad</th><th>Revisión</th><th>Acción</th></tr></thead><tbody id="movementsBody"></tbody></table></div></section>
            </section>
        </main>
        <dialog class="review-dialog" id="reviewDialog" aria-labelledby="reviewDialogTitle">
            <form id="reviewForm" method="dialog">
                <input name="movimiento_id" type="hidden">
                <header class="review-dialog__header"><p class="eyebrow">CHEQUEO DOCUMENTAL</p><h2 id="reviewDialogTitle">Revisar movimiento</h2></header>
                <label><span>Resultado</span><select name="estado" required><option value="revisado">Revisado</option><option value="observado">Observado</option></select></label>
                <label><span>Nota</span><textarea name="nota" maxlength="2000"></textarea></label>
                <p class="form-error" id="reviewError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" value="cancel">Cancelar</button><button class="primary-button" value="default">Guardar chequeo</button></div>
            </form>
        </dialog>
        <dialog class="review-dialog property-dialog" id="propertyDialog" aria-labelledby="propertyDialogTitle" aria-describedby="propertyHelp">
            <form id="propertyForm" method="dialog">
                <input name="movimiento_id" type="hidden">
                <header class="review-dialog__header">
                    <p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p>
                    <h2 id="propertyDialogTitle">Corregir propiedad de la recepción</h2>
                </header>
                <div class="property-dialog__context">
                    <span>RECEPCIÓN Y CAMBIO PROPUESTO</span>
                    <strong id="propertySummary"></strong>
                </div>
                <p class="property-dialog__help" id="propertyHelp">La corrección se aplicará a todos los envases de esta recepción. El ingreso original y los ajustes quedarán visibles en el historial.</p>
                <label><span>Propiedad correcta</span><select name="concepto_envases" required><option value="arriendo">Arrendada</option><option value="compra">Propia</option></select></label>
                <label><span>Motivo de la corrección</span><textarea name="motivo" minlength="10" maxlength="2000" required placeholder="Explica por qué se ingresó una propiedad equivocada"></textarea></label>
                <p class="form-error" id="propertyError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" type="button" id="propertyCancel">Cancelar</button><button class="primary-button" type="submit">Confirmar corrección</button></div>
            </form>
        </dialog>
        <dialog class="review-dialog property-dialog" id="quantityDialog" aria-labelledby="quantityDialogTitle" aria-describedby="quantityHelp">
            <form id="quantityForm" method="dialog">
                <input name="movimiento_id" type="hidden">
                <header class="review-dialog__header">
                    <p class="eyebrow">CORRECCIÓN ADMINISTRATIVA</p>
                    <h2 id="quantityDialogTitle">Corregir cantidad de envases</h2>
                </header>
                <div class="property-dialog__context">
                    <span>RECEPCIÓN Y AJUSTE PROPUESTO</span>
                    <strong id="quantitySummary"></strong>
                </div>
                <p class="property-dialog__help" id="quantityHelp">Disponible para ingresos de solo envases sin guías ni movimientos vinculados. El ingreso original y el ajuste quedarán visibles en el historial.</p>
                <label><span>Cantidad correcta</span><input name="cantidad_correcta" type="number" min="1" max="100000" step="1" required placeholder="Ej.: 104"></label>
                <label><span>Motivo de la corrección</span><textarea name="motivo" minlength="10" maxlength="2000" required placeholder="Explica por qué la cantidad registrada era incorrecta"></textarea></label>
                <p class="form-error" id="quantityError" role="alert"></p>
                <div class="dialog-actions"><button class="secondary-button" type="button" id="quantityCancel">Cancelar</button><button class="primary-button" type="submit">Confirmar corrección</button></div>
            </form>
        </dialog>
        <div class="loading is-hidden" id="officeLoading"><span></span><strong id="officeLoadingText">Procesando…</strong></div><div class="toast-region" id="officeToasts"></div>
    </body>
</html>
