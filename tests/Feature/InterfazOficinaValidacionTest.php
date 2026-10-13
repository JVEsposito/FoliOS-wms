<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaValidacionTest extends TestCase
{
    public function test_la_oficina_de_validacion_se_encuentra_disponible(): void
    {
        $this->get('/oficina/validacion')
            ->assertOk()
            ->assertSee('Validación de pallets')
            ->assertDontSee('Importar planilla')
            ->assertDontSee('Configuración de solo lectura')
            ->assertDontSee('Guardar temporada')
            ->assertDontSee('Nueva temporada')
            ->assertDontSee('Combinaciones artículo–origen habilitadas')
            ->assertSee('validationHistoryBody', false)
            ->assertSee('Descargar RRPP-01')
            ->assertSee('Descargar planilla en blanco')
            ->assertSee('id="validationLines"', false)
            ->assertSee('id="dayValidatedCount"', false)
            ->assertSee('<th scope="col"><span class="office-visually-hidden">Acciones</span></th>', false)
            ->assertSee('data-validation-attention', false)
            ->assertSee('Validador y jornada')
            ->assertSee('downloadBlankValidationRegisterButton', false)
            ->assertSee('validationUserFilter', false)
            ->assertDontSee('importPreview', false)
            ->assertSee('data-active-domain="frigorifico"', false)
            ->assertSee('/oficina/frigorifico/camaras', false)
            ->assertSee('/oficina/frigorifico/despacho/cargas', false)
            ->assertSee('aria-live="assertive"', false);
    }

    public function test_los_maestros_administrativos_no_crean_temporadas(): void
    {
        $this->get('/oficina/administracion/maestros-temporada')
            ->assertOk()
            ->assertSee('Datos maestros de la temporada seleccionada')
            ->assertSee('Las temporadas se crean y activan en Accesos.')
            ->assertDontSee('catalogSeasonForm', false)
            ->assertDontSee('Crear temporada');
    }
}
