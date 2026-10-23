<fieldset class="material-reception-transport" id="materialReceptionTransport">
    <legend>Conductor y transporte del viaje</legend>
    <p>Busca por RUT o patente para reutilizar datos. Los registros nuevos se guardarán al guardar la recepción. Una empresa sin RUT queda descrita solo en este viaje.</p>
    <div class="materials-form__grid">
        <label><span>RUT del conductor</span><input name="conductor_rut" maxlength="15" autocomplete="off" placeholder="12.345.678-5"></label>
        <button class="secondary-button" type="button" data-transport-lookup="conductor">Buscar conductor</button>
        <label><span>Nombre del conductor</span><input name="conductor_nombre" maxlength="150"></label>
        <label><span>Teléfono del conductor</span><input name="conductor_telefono" type="tel" maxlength="40"></label>
        <p class="materials-wide" data-transport-status="conductor" role="status"></p>
        <label class="materials-wide"><span><input name="conductor_actualizar" type="checkbox"> Actualizar los datos guardados del conductor</span></label>
        <button class="secondary-button materials-wide is-hidden" type="button" id="useMaterialTransportLastTrip">Usar transporte del último viaje</button>
        <label><span>RUT del transportista</span><input name="empresa_rut" maxlength="15" autocomplete="off"></label>
        <button class="secondary-button" type="button" data-transport-lookup="empresa">Buscar transportista</button>
        <label><span>Empresa transportista</span><input name="transportista" maxlength="150"></label>
        <label><span>Teléfono del transportista</span><input name="empresa_telefono" type="tel" maxlength="40"></label>
        <p class="materials-wide" data-transport-status="empresa" role="status"></p>
        <label class="materials-wide"><span><input name="empresa_actualizar" type="checkbox"> Actualizar los datos guardados del transportista</span></label>
        <label><span>Patente del camión</span><input name="patente" maxlength="20" autocomplete="off"></label>
        <button class="secondary-button" type="button" data-transport-lookup="vehiculo">Buscar vehículo</button>
        <label><span>Descripción del vehículo</span><input name="vehiculo_descripcion" maxlength="150" placeholder="Marca, modelo o tipo de camión"></label>
        <label><span>Patente del carro</span><input name="patente_carro" maxlength="20"></label>
        <p class="materials-wide" data-transport-status="vehiculo" role="status"></p>
        <label class="materials-wide"><span><input name="vehiculo_actualizar" type="checkbox"> Actualizar la descripción guardada del vehículo</span></label>
    </div>
    <p>Las actualizaciones del directorio no cambian viajes anteriores. Revisa el camión y el transportista en cada ingreso.</p>
</fieldset>
