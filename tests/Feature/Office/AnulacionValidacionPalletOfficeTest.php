<?php

namespace Tests\Feature\Office;

use Tests\TestCase;

class AnulacionValidacionPalletOfficeTest extends TestCase
{
    public function test_oficina_de_anulaciones_esta_disponible(): void
    {
        $this->get('/oficina/validacion/anulaciones')
            ->assertOk()
            ->assertSee('Anulaciones de pallets')
            ->assertSee('Pallets aún anulables')
            ->assertSee('Registro de anulaciones')
            ->assertSee('validationCorrectionDialog', false)
            ->assertSee('Composición por CSG')
            ->assertSee('+ Agregar línea (otro CSG, lote o proceso)');

        $script = file_get_contents(resource_path('js/office-validation-annulments.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('Corregir datos', $script);
        $this->assertStringContainsString('createValidationCorrection', $script);
        $this->assertStringContainsString('puede_corregir_validaciones_pallet', $script);
        // La corrección (compartida con Validación) pide el contexto del bulto y guarda la composición.
        $compartido = (string) file_get_contents(resource_path('js/shared/validation-correction.js'));
        $this->assertStringContainsString('/correccion', $compartido);
        $this->assertStringContainsString('/corregir', $compartido);
    }

    public function test_la_oficina_de_validacion_usa_la_misma_correccion(): void
    {
        $this->get('/oficina/validacion')
            ->assertOk()
            ->assertSee('validationCorrectionDialog', false)
            ->assertSee('Composición por CSG');
        $this->assertStringContainsString(
            'createValidationCorrection',
            (string) file_get_contents(resource_path('js/office-validation.js')),
        );
    }
}
