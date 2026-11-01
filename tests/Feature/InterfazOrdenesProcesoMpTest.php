<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOrdenesProcesoMpTest extends TestCase
{
    public function test_la_oficina_de_ordenes_de_proceso_esta_en_materia_prima(): void
    {
        $this->get('/oficina/materia-prima')
            ->assertOk()
            ->assertSee('Órdenes de Proceso')
            ->assertSee('/oficina/materia-prima/ordenes-proceso', escape: false);

        $this->get('/oficina/materia-prima/ordenes-proceso')
            ->assertOk()
            ->assertSee('Órdenes de proceso')
            ->assertSee('Lotes en secuencia')
            ->assertSee('Colación desde')
            ->assertSee('N° proceso')
            ->assertSee('Motivo del cambio')
            ->assertSee('Líneas de proceso');
    }

    public function test_la_interfaz_usa_las_rutas_y_capacidades_de_ordenes(): void
    {
        $interfaz = file_get_contents(resource_path('js/office-raw-material-process-orders.js'));

        foreach ([
            '/api/materia-prima/ordenes-proceso/catalogos',
            '/api/materia-prima/ordenes-proceso/lotes-programables',
            '/versiones',
            '/cancelar',
            '/api/materia-prima/lineas-proceso/',
            'puede_gestionar_ordenes_proceso',
            'puede_cancelar_ordenes_proceso',
            'puede_configurar_lineas_proceso',
        ] as $fragmento) {
            $this->assertStringContainsString($fragmento, $interfaz);
        }
    }
}
