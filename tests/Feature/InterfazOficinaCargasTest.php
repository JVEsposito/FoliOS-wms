<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InterfazOficinaCargasTest extends TestCase
{
    public function test_la_oficina_de_cargas_se_encuentra_disponible(): void
    {
        $respuesta = $this->get('/oficina/frigorifico/despacho/cargas')
            ->assertOk()
            ->assertSee('Órdenes de carga')
            ->assertSee('Ingresar a cargas')
            ->assertSee('Crear primera orden')
            ->assertSee('data-active-domain="frigorifico"', false)
            ->assertSee('/oficina/frigorifico/camaras', false)
            ->assertDontSee('data-office-key="accesos"', false)
            ->assertSee('aria-labelledby="folioAddTitle"', false)
            ->assertSee('availableFolioSelectPage', false)
            ->assertSee('availableFolioPagination', false)
            ->assertSee('loadInstructionSelect', false)
            ->assertSee('Instructivos de esta orden')
            ->assertSee('Variedad / calibre')
            ->assertSee('Andén previsto')
            ->assertSee('Concentración de la carga')
            ->assertSee('Incidencias de la carga')
            ->assertSee('Resolver incidencia')
            ->assertSee('Confirmar salida del camión')
            ->assertSee('Registrar salida directa desde Prefrío')
            ->assertSee('directDispatchButton', false)
            ->assertSee('directDispatchDialog', false)
            ->assertSee('directFoliosInput', false)
            ->assertSee('name="ocurrido_at"', false)
            ->assertSee('loadIncidentFilter', false)
            ->assertSee('incidentResolutionDialog', false)
            ->assertSee('closeDispatchDialog', false)
            ->assertSee('aria-live="assertive"', false);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/cargas.html'), $respuesta->getContent());
        }
    }

    public function test_la_oficina_operacional_de_camaras_enlaza_el_modulo_de_cargas(): void
    {
        $this->get('/oficina/frigorifico/camaras')
            ->assertOk()
            ->assertSee('data-active-domain="frigorifico"', false)
            ->assertSee('/oficina/frigorifico/despacho/cargas', false)
            ->assertDontSee('data-office-key="accesos"', false)
            ->assertSee('Consulta la disponibilidad')
            ->assertDontSee('Cargas · próximamente');
    }

    public function test_la_ruta_historica_de_cargas_conserva_compatibilidad(): void
    {
        $this->get('/oficina/cargas')
            ->assertRedirect('/oficina/frigorifico/despacho/cargas')
            ->assertStatus(301);
    }
}
