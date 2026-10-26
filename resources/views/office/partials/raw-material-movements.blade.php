<details class="panel" id="movimientos">
    <summary class="raw-material-panel-heading"><h2>Registro de movimientos en cámaras MP</h2></summary>
    <p id="movementScope">Ingresos, traslados, salidas a Packing y correcciones de la temporada activa. Cada fila conserva el origen y destino registrados.</p>
    <form class="lot-filters" id="movementFilters">
        <input name="lote_id" type="hidden">
        <label>Lote, CSG o cliente<input name="buscar" maxlength="100" placeholder="Buscar movimientos"></label>
        <label>Cámara<select name="camara_id"><option value="">Todas las cámaras</option></select></label>
        <label>Movimiento<select name="tipo"><option value="">Todos</option><option value="camara_asignada">Ingreso a cámara</option><option value="camara_trasladada">Traslado entre cámaras</option><option value="fruta_entregada_proceso">Salida a Packing</option><option value="entrega_fruta_proceso_anulada">Anulación de salida</option><option value="sublote_packing_ubicado">Ingreso desde Packing</option></select></label>
        <label>Desde<input name="desde" type="date"></label>
        <label>Hasta<input name="hasta" type="date"></label>
        <button class="secondary-button" type="submit">Buscar</button>
        <button class="secondary-button" type="button" id="movementClear">Ver todos</button>
        <button class="secondary-button" type="button" id="movementExport">Descargar Excel</button>
    </form>
    <p id="movementStatus" role="status" aria-live="polite">Abre el registro para consultar los movimientos.</p>
    <div class="raw-material-table-scroll">
        <table class="raw-material-table">
            <thead><tr><th>Fecha de planta / responsable</th><th>Lote / cliente</th><th>Movimiento</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Motivo / observación</th></tr></thead>
            <tbody id="movementRows"></tbody>
        </table>
    </div>
    <div class="dialog-actions"><button class="secondary-button" id="movementPrevious" type="button" disabled>Anterior</button><button class="secondary-button" id="movementNext" type="button" disabled>Siguiente</button></div>
</details>
