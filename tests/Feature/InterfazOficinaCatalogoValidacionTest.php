<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaCatalogoValidacionTest extends TestCase
{
    public function test_publica_los_maestros_jerarquicos_de_temporada(): void
    {
        $this->get('/oficina/administracion/maestros-temporada')
            ->assertOk()
            ->assertSee('Maestros de temporada')
            ->assertSee('id="catalogLoadError" role="alert"', false)
            ->assertSee('id="projectionCombinationCount">—</strong>', false)
            ->assertSee('Clientes')
            ->assertSee('Marcas')
            ->assertSee('Categorías')
            ->assertSee('Especies')
            ->assertSee('Variedades')
            ->assertSee('Calibres')
            ->assertSee('Envases')
            ->assertSee('CSG')
            ->assertSee('Registros activos generados')
            ->assertSeeInOrder([
                'id="packageForm"',
                'Envase *',
                'Código externo',
                'Cliente *',
                'name="cliente_validacion_id"',
            ], false)
            ->assertSeeInOrder([
                'id="speciesForm"',
                'maxlength="100"',
                'id="varietyForm"',
                'maxlength="100"',
            ], false);
        $this->get('/oficina/administracion/maestros-temporada')
            ->assertSee('id="categoryForm"', false)
            ->assertSee('Guardar categoría');
    }

    public function test_validacion_no_publica_la_administracion_del_maestro(): void
    {
        $this->get('/oficina/validacion')
            ->assertOk()
            ->assertDontSee('/oficina/validacion/catalogo', false)
            ->assertDontSee('Configurar catálogo')
            ->assertDontSee('Importar planilla');
    }
}

