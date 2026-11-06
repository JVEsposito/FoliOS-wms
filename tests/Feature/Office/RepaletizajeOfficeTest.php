<?php

namespace Tests\Feature\Office;

use Tests\TestCase;

class RepaletizajeOfficeTest extends TestCase
{
    public function test_la_oficina_de_repaletizajes_es_visible(): void
    {
        $this->get('/oficina/validacion/repaletizajes')
            ->assertOk()
            ->assertSee('Repaletizajes')
            ->assertSee('PALLETS Y SALDOS')
            ->assertSee('id="sourceOverview"', false);
    }

    public function test_la_lista_de_folios_es_compacta_y_despliega_su_composicion(): void
    {
        $script = file_get_contents(resource_path('js/office-repalletizing.js'));
        $styles = file_get_contents(resource_path('css/office-repalletizing.css'));

        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertStringContainsString('class="source-card__row"', $script);
        $this->assertStringContainsString('data-toggle-composition=', $script);
        $this->assertStringContainsString('state.expandedSources.has(id)', $script);
        $this->assertStringContainsString('.source-list.has-many', $styles);
        $this->assertStringContainsString('.source-card .composition-lines.is-collapsed', $styles);
        $this->assertStringContainsString('position: sticky;', $styles);
    }
}
