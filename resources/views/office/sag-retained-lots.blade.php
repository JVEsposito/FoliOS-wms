<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark">
    <title>FoliOS · Lotes retenidos SAG</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office.css', 'resources/css/office-sag-retained-lots.css', 'resources/js/office-sag-retained-lots.js'])
    @endif
    @include('office.partials.arranque')
</head>
<body>
    <section class="office-access" id="officeAccess">
        <div class="office-access__brand"><p class="eyebrow">FRIGORÍFICO · SAG</p><h1>Lotes retenidos</h1><p>Seguimiento por cliente y lote de recepción.</p></div>
        <form class="office-access__form" id="officeLoginForm"><h2>Ingresar</h2>
            <label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label>
            <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="primary-button">Ingresar</button><p id="officeLoginError" role="alert"></p>
        </form>
    </section>
    <main class="office-app is-hidden" id="officeApp" data-folios-workspace="registro">
        <x-office.navigation domain="frigorifico" office="lotes-retenidos-sag" context="FRIGORÍFICO · SAG" icon="SAG" />
        <section class="sl-workspace">
            <x-folios.cabecera-etiqueta area="frigorifico" seccion="Frigorífico · SAG" titulo="Lotes retenidos SAG"
                codigo="SAG · CONTROL DE LOTES" class="sl-heading">
                <button class="primary-button" id="slNew" type="button" disabled>Registrar lote</button>
            </x-folios.cabecera-etiqueta>
            <p class="sl-help">El lote puede movilizarse. La salida del camión se bloquea cuando su destino está restringido.</p>
            <p id="slMessage" role="status" hidden></p>
            <x-folios.bandeja area="frigorifico" titulo="Seguimiento de lotes" id="slRegisterTitle">
                <x-slot:resumen><span id="slResults" role="status"></span></x-slot:resumen>
                <form class="sl-grid" id="slFilters" data-folios-toolbar>
                    <label>Cliente<select name="cliente_id" data-clients><option value="">Todos los clientes</option></select></label>
                    <label>Número de lote<input name="lote" maxlength="100" placeholder="Lote de recepción"></label>
                    <label>Estado<select name="estado"><option value="">Todos</option><option value="con_observaciones">Con observaciones</option><option value="retenido">Retenido</option><option value="liberado_con_restricciones">Liberado con restricciones</option><option value="liberado">Liberado</option></select></label>
                    <button class="secondary-button">Buscar / actualizar</button>
                </form>
                <div class="sl-scroll" role="region" aria-label="Bandeja de lotes SAG" tabindex="0">
                    <table><thead><tr><th scope="col">Lote / Cliente</th><th scope="col">Estado</th><th scope="col">Restricción de destinos</th><th scope="col">Detección / Actualización</th><th scope="col">Detalle</th></tr></thead>
                        <tbody id="slRows"><tr><td colspan="5"><div class="folios-bandeja__estado">Consultando lotes…</div></td></tr></tbody>
                    </table>
                </div>
                <nav class="sl-actions" id="slPages" aria-label="Paginación de lotes SAG"></nav>
            </x-folios.bandeja>
            <section class="folios-ticket sl-editor is-hidden" id="slEditor" data-ticket-area="frigorifico" aria-labelledby="slTitle">
                <header class="sl-heading">
                    <div><p class="eyebrow" id="slVersion"></p><h2 id="slTitle" tabindex="-1">Registrar lote</h2><p id="slClient"></p></div>
                    <div class="sl-detail-state"><div id="slRecordState"></div><button class="secondary-button" type="button" id="slClose">Cerrar detalle</button></div>
                </header>
                <div class="folios-perforacion" aria-hidden="true"></div>
                <form id="slForm">
                    <div class="sl-grid">
                        <label>Cliente<select name="cliente_id" data-clients required><option value="">Seleccionar cliente</option></select></label>
                        <label>Lote de recepción<input name="numero_lote" maxlength="100" required></label>
                        <label>Fecha de detección<input name="fecha_deteccion" type="date" required></label>
                        <label>Estado<select name="estado"><option value="con_observaciones">Con observaciones</option><option value="retenido">Retenido</option><option value="liberado_con_restricciones">Liberado con restricciones</option><option value="liberado">Liberado</option></select></label>
                    </div>
                    <label>Detección y observaciones<textarea name="observacion" rows="3" minlength="5" maxlength="4000" required></textarea></label>
                    <label>N.º de informe / antecedentes<input name="referencia_informe" maxlength="250"></label>
                    <fieldset class="sl-destinations">
                        <legend>Destinos con despacho restringido</legend>
                        <label class="sl-check"><input type="checkbox" name="todos_destinos"> Todos los destinos</label>
                        <p class="sl-help">Marca los países o bloques a los que el lote no puede salir. Puedes confirmar, ampliar o liberar estas restricciones en la resolución.</p>
                        <div class="sl-grid"><label>Buscar país o bloque<input id="slDestinationSearch" type="search" placeholder="Ej.: Estados Unidos o Unión Europea"></label><div class="sl-destination-actions"><button class="secondary-button" type="button" id="slExpand">Editar países individualmente</button><button class="secondary-button" type="button" id="slClear">Quitar todos</button></div></div>
                        <p id="slSelected" role="status"></p><div class="sl-options" id="slDestinations"></div>
                    </fieldset>
                    <label>Resolución / motivo de la actualización<textarea name="resolucion" rows="3" minlength="5" maxlength="4000"></textarea></label>
                    <label class="sl-check"><input name="definitivo" type="checkbox"> Resultado definitivo para las restricciones indicadas</label>
                    <label>Instrucción de segregación física, si el resultado la exige<textarea name="instruccion_segregacion" rows="2" minlength="5" maxlength="2000" placeholder="Vacío si no se requiere segregación física"></textarea></label>
                    <p class="sl-help">Cada cambio conserva el responsable, fecha, versión, resolución y los folios identificados en ese momento.</p>
                    <p class="form-error" role="alert" id="slError"></p><button class="primary-button" id="slSave">Guardar seguimiento</button>
                </form>
                <section class="is-hidden" id="slEvidence">
                    <h3>Planillas y antecedentes</h3><div class="sl-actions"><button class="secondary-button" data-report="pdf">Descargar PDF</button><button class="secondary-button" data-report="xlsx">Descargar Excel</button></div>
                    <p id="slTotals"></p>
                    <div class="sl-scroll"><table><thead><tr><th>Folio</th><th>Cajas del lote / pallet</th><th>CSG referencial</th><th>Ubicación / estado</th><th>Carga</th></tr></thead><tbody id="slFolios"></tbody></table></div>
                    <h3>Historial de seguimiento y resoluciones</h3><ol id="slHistory" class="sl-history-list"></ol>
                </section>
            </section>
        </section>
    </main>
</body>
</html>
