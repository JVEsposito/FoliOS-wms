<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaGerenciaTest extends TestCase
{
    public function test_el_panel_gerencial_presenta_indicadores_accionables_por_area(): void
    {
        $this->get('/oficina/gerencia')
            ->assertOk()
            ->assertSee('Panel gerencial')
            ->assertSee('Solo observación')
            ->assertSee('TEMPORADA DEL PANEL')
            ->assertSee('Kilos recepcionados')
            ->assertSee('Producto terminado')
            ->assertSee('Estado de los folios')
            ->assertSee('Kilos por cliente en la temporada')
            ->assertSee('Pallet · bulto completo')
            ->assertSee('Focos operacionales priorizados')
            ->assertSee('Cargas activas y avance operativo')
            ->assertSee('Validación de pallets de hoy')
            ->assertSee('Ocupación, cola y cumplimiento de tiempo')
            ->assertSee('Lotes y continuidad hacia proceso')
            ->assertSee('Existencia física y cuenta por cliente')
            ->assertSee('Entradas y salidas · últimos 7 días')
            ->assertSee('Saldos de cuenta por cliente')
            ->assertSee('/oficina/frigorifico/existencias', false)
            ->assertSee('/oficina/materiales/exportaciones', false)
            ->assertSee('/oficina/materia-prima/existencias', false)
            ->assertSee('cameraOccupancyChart', false)
            ->assertSee('materialStockChart', false)
            ->assertSee('weighbridgeReceptionChart', false)
            ->assertSee('containerFlowChart', false)
            ->assertSee('managementLoadList', false)
            ->assertSee('managementPrecoolingList', false)
            ->assertSee('refreshDashboardButton', false)
            ->assertSee('managementSeasonSelect', false)
            ->assertDontSee('<form id="create', false);
    }
}
