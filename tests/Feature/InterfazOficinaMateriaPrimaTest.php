<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaMateriaPrimaTest extends TestCase
{
    public function test_expone_el_modulo_madre_y_sus_accesos_operacionales(): void
    {
        $this->get('/oficina/materia-prima')
            ->assertOk()
            ->assertSee('Resumen de Materia Prima')
            ->assertSee('Digitación de Lotes')
            ->assertSee('Hidrocooler')
            ->assertSee('Fruta a Proceso')
            ->assertSee('/oficina/romana', escape: false)
            ->assertSee('/oficina/materia-prima/lotes', escape: false)
            ->assertSee('/oficina/materia-prima/fruta-a-proceso', escape: false)
            ->assertSee('/oficina/materia-prima/hidrocooler', escape: false)
            ->assertSee('/oficina/envases/cuenta-corriente', escape: false);

        $this->get('/oficina/materia-prima/lotes')
            ->assertOk()
            ->assertSee('Neto calculado')
            ->assertSee('¿El lote necesita hidrocooler?');

        $this->get('/oficina/materia-prima/hidrocooler')
            ->assertOk()
            ->assertSee('Un ciclo de Hidrocooler por cada lote.')
            ->assertSee('Temperatura inicial fruta')
            ->assertSee('Cantidad de bombas funcionando')
            ->assertSee('Cloro libre ppm')
            ->assertSee('Blanco Excel')
            ->assertSee('Registro PDF')
            ->assertSee('Directo a Fruta a proceso')
            ->assertSee('TIEMPO PROMEDIO HOY');
        $this->get('/oficina/materia-prima/fruta-a-proceso')
            ->assertOk()
            ->assertSee('Fruta a proceso')
            ->assertSee('Confirmar viaje')
            ->assertSee('Retornos de Packing')
            ->assertSee('Crear sublotes')
            ->assertSee('PENDIENTES DE UBICACIÓN')
            ->assertSee('Cantidad de bins')
            ->assertSee('Línea de proceso')
            ->assertSee('N° de orden');
        $this->get('/oficina/materia-prima/romana')
            ->assertRedirect('/oficina/romana');
        $this->get('/oficina/materia-prima/envases')
            ->assertRedirect('/oficina/envases/cuenta-corriente');
    }

    public function test_revalida_catalogos_sin_descargarlos_en_cada_refresco(): void
    {
        $interfaz = file_get_contents(base_path('resources/js/office-raw-material.js'));

        $this->assertStringContainsString(
            "headers.set('If-None-Match', state.catalogEtag)",
            $interfaz,
        );
        $this->assertStringContainsString(
            'if (response.status === 304)',
            $interfaz,
        );
        $this->assertStringContainsString('return loadCatalogs(true)', $interfaz);
        $this->assertStringContainsString(
            "state.catalogEtag = response.headers.get('ETag')",
            $interfaz,
        );
        $this->assertStringContainsString(
            'loadCatalogs(),',
            $interfaz,
        );
    }
}
