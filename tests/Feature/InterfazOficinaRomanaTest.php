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
            ->assertSee('El camión sale sin los envases')
            ->assertSee('Tara de todos los envases declarados')
            ->assertSee('Peso bruto')
            ->assertSee('Fecha de ingreso')
            ->assertSee('containerEntryDateField', false)
            ->assertSee('Peso tara')
            ->assertSee('Aviso de Recibo PDF')
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
}
