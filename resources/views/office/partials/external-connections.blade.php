<section class="admin-panel panel" id="administration-panel-external-connections" data-office-panel-group="administration" data-office-panel-id="external-connections" role="tabpanel" aria-labelledby="administration-tab-external-connections" hidden>
    <div class="admin-panel__heading">
        <div><p class="eyebrow">INTEGRACIONES</p><h2>Conexiones externas</h2></div>
        <span id="externalConnectionsSummary">Sin cargar</span>
    </div>
    <p class="admin-form__hint">Configura el acceso MySQL del sistema de origen. La prueba usa los datos guardados y se realiza desde el servidor de FoliOS, que debe tener acceso a esa red.</p>
    <form class="admin-form" id="externalConnectionForm" autocomplete="off" hidden>
        <input name="id" type="hidden">
        <input name="version_conocida" type="hidden">
        <div class="admin-form__grid">
            <label class="field"><span>Nombre de la conexión *</span><input name="nombre" maxlength="100" required></label>
            <label class="field"><span>Servidor o IP *</span><input name="host" maxlength="253" autocapitalize="none" spellcheck="false" required></label>
            <label class="field"><span>Puerto *</span><input name="puerto" type="number" min="1" max="65535" required></label>
            <label class="field"><span>Base de datos *</span><input name="base_datos" maxlength="64" autocapitalize="none" spellcheck="false" required></label>
            <label class="field"><span>Usuario *</span><input name="usuario" maxlength="128" autocapitalize="none" spellcheck="false" required></label>
            <label class="field"><span>Contraseña *</span><input name="password" type="password" maxlength="1024" autocomplete="new-password" aria-describedby="externalConnectionPasswordHint" required><small id="externalConnectionPasswordHint">Se guarda cifrada y no se vuelve a mostrar.</small></label>
        </div>
        <label class="admin-check"><input name="activa" type="checkbox" checked><span>Conexión activa</span></label>
        <p class="form-error" id="externalConnectionError" role="alert"></p>
        <p class="admin-form__hint" id="externalConnectionStatus" role="status"></p>
        <div class="admin-form__actions">
            <button class="secondary-button" id="newExternalConnection" type="button">Nueva conexión</button>
            <button class="primary-button" type="submit">Guardar conexión</button>
        </div>
    </form>
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead><tr><th>Conexión</th><th>Estado</th><th>Última prueba</th><th>Modificada por</th><th>Acciones</th></tr></thead>
            <tbody id="externalConnectionsBody"></tbody>
        </table>
    </div>
    <section id="originExplorer" class="origin-explorer" hidden aria-labelledby="originTitle">
        <h3 id="originTitle">Explorar origen</h3>
        <p class="admin-form__hint">Seleccione una tabla o vista y sus columnas para comprobar los datos reales. La muestra usa hasta diez filas y se consulta desde el servidor de FoliOS.</p>
        <div class="origin-explorer__grid">
            <div><label class="field"><span>Buscar en el árbol</span><input id="originSearch" type="search" placeholder="Nombre de tabla o vista"></label><label class="field"><span>Tablas y vistas disponibles</span><select id="originObjects" size="12"></select></label></div>
            <div><h4>Columnas de la fuente</h4><div id="originColumns" class="origin-columns"></div></div>
        </div>
        <h4>Filtros de igualdad (opcionales)</h4>
        <div id="originFilters"></div>
        <div class="admin-form__actions"><button id="originFilterAdd" type="button" class="secondary-button">Agregar filtro</button><button id="originPreview" type="button" class="primary-button">Ver muestra</button></div>
        <p id="originStatus" class="admin-form__hint" role="status"></p>
        <div id="originSample" class="admin-table-scroll"></div>
    </section>
</section>
