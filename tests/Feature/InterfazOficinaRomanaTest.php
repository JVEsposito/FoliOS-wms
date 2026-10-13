<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaRomanaTest extends TestCase
{
    public function test_la_oficina_presenta_el_flujo_completo_de_pesaje(): void
    {
        $this->get('/oficina/romana')
            ->assertOk()
            ->assertSee('Control de Romana')
            ->assertSee('Registrar ingreso')
            ->assertSee('Tipo de camión')
            ->assertSee('Camión termo')
            ->assertSee('Camión plano')
            ->assertSee('PENDIENTES DE CIERRE')
            ->assertSee('PESAJE DE ENVASES')
            ->assertSee('Configuración del pesaje acumulativo')
            ->assertSee('Registrar tanda de envases')
            ->assertSee('Tara por envase')
            ->assertSee('Se va con los mismos envases')
            ->assertSee('Se va con más o menos envases')
            ->assertSee('Se va vacío')
            ->assertSee('N° de guía de salida')
            ->assertSee('Envases y tara unitaria por tipo')
            ->assertSee('Peso bruto')
            ->assertSee('Fecha de ingreso')
            ->assertSee('containerEntryDateField', false)
            ->assertSee('Peso tara')
            ->assertSee('Registro de pesaje (RPR-01)')
            ->assertSee('Planilla de pesaje en blanco')
            ->assertSee('downloadBlankWeighingFormButton', false)
            ->assertSee('Motivo de la corrección administrativa')
            ->assertSee('editReceptionButton', false)
            ->assertSee('administrativeCorrectionField', false)
            ->assertSee('administrativeTareField', false)
            ->assertSee('receptionDialogDescription', false)
            ->assertSee('outboundContainerTares', false)
            ->assertSee('receptionForm', false)
            ->assertSee('tareForm', false)
            ->assertSee('containerWeighingForm', false)
            ->assertSee('/oficina/gerencia', false)
            ->assertSee('/oficina/prefrio', false);
    }

    public function test_folios_v2_lista_recepciones_con_avance_y_marca_pendientes(): void
    {
        $html = $this->get('/oficina/romana')->assertOk()->getContent();

        $this->assertStringContainsString('<th scope="col">Recepción</th><th scope="col">Cliente y guía</th><th scope="col">Transporte y declaración</th><th scope="col" class="rec-weight">Peso</th>', $html);
        $this->assertStringContainsString('data-weighbridge-attention="exit"', $html);

        $script = file_get_contents(resource_path('js/office-weighbridge.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString("import { receptionSteps } from './shared/weighbridge-steps.js';", $script);
        $this->assertStringContainsString('class="rec-track" data-reception-id=', $script);
        $this->assertStringContainsString('aria-current="step"', $script);
    }
}
