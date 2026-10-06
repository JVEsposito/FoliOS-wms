<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaRecepcionFrutaEmbaladaTest extends TestCase
{
    public function test_oficina_expone_filtros_encabezado_y_captura_en_borrador(): void
    {
        $this->get('/oficina/recepcion-fruta-embalada')->assertOk()
            ->assertSee('Recepción de fruta embalada')->assertSee('receptionForm')->assertSee('palletList')
            ->assertSee('name="numero_guia"', false)->assertSee('name="desde"', false)->assertSee('name="hasta"', false)
            ->assertSee('name="cliente_id"', false)->assertSee('name="planta_origen_id"', false)->assertSee('name="estado"', false)
            ->assertSee('Los pallets todavía no ingresan al inventario.')->assertSee('Guardar borrador');
    }

    public function test_administracion_expone_plantas_y_umbrales_sin_inventar_una_temperatura(): void
    {
        $this->get('/oficina/administracion/fruta-embalada')->assertOk()
            ->assertSee('Plantas de origen')->assertSee('plantForm')->assertSee('thresholdForm')
            ->assertSee('name="codigo"', false)->assertSee('name="activa"', false)
            ->assertSee('name="especie"', false)->assertSee('name="temperatura_maxima_c"', false);
    }
}
