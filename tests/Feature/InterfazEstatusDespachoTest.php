<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazEstatusDespachoTest extends TestCase
{
    public function test_publica_el_estatus_operativo_como_submodulo_de_despacho(): void
    {
        $this->get('/oficina/frigorifico/despacho/estatus')
            ->assertOk()
            ->assertSee('Estatus operativo')
            ->assertSee('DESPACHO · INFORMACIÓN EN VIVO')
            ->assertSee('Concentración')
            ->assertSee('Separación')
            ->assertSee('Carguío')
            ->assertSee('Pallets leídos al subir al camión')
            ->assertSee('Ubicaciones')
            ->assertSee('Folios de la carga')
            ->assertSee('data-active-domain="frigorifico"', false)
            ->assertSee('data-active-office="despacho-estatus"', false)
            ->assertSee('/oficina/frigorifico/despacho/cargas', false)
            ->assertSee('/oficina/frigorifico/despacho/calendario', false);
    }

    public function test_cliente_consume_solo_cargas_publicadas_y_refresca_sin_superponer_consultas(): void
    {
        $script = file_get_contents(resource_path('js/office-load-status.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("request('/api/cargas/pendientes'", $script);
        $this->assertStringContainsString("'If-None-Match'", $script);
        $this->assertStringContainsString('createOperationalPoller', $script);
        $this->assertStringContainsString('intervalMs: 15_000', $script);
        $this->assertStringContainsString('!state.loading', $script);
        $this->assertStringContainsString('load.camion_en_anden', $script);
        $this->assertStringNotContainsString("method: 'POST', body: JSON.stringify({ version_esperada", $script);
    }
}
