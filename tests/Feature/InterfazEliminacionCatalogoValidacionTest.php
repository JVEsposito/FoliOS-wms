<?php

namespace Tests\Feature;

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Tests\TestCase;

class InterfazEliminacionCatalogoValidacionTest extends TestCase
{
    public function test_catalogo_publica_controles_para_eliminar_y_revisar_elementos_retirados(): void
    {
        $this->get('/oficina/administracion/maestros-temporada')
            ->assertOk()
            ->assertSee('Maestros de temporada')
            ->assertSee('id="catalogToggleInactive"', false)
            ->assertSee('Mostrar eliminados')
            ->assertSee('se conserva su trazabilidad histórica');

        $javascript = file_get_contents(resource_path('js/office-validation-catalog.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('data-delete-type', $javascript);
        $this->assertStringContainsString("method: 'DELETE'", $javascript);
        $this->assertStringContainsString('¿Eliminar', $javascript);
    }

    public function test_catalogo_maestro_deja_validacion_y_se_ubica_en_administracion(): void
    {
        $this->get('/oficina/validacion/catalogo')
            ->assertRedirect('/oficina/administracion/maestros-temporada');

        $this->get('/oficina/validacion')
            ->assertOk()
            ->assertDontSee('Configurar catálogo')
            ->assertDontSee('Importar planilla')
            ->assertDontSee('id="validationAdmin"', false);

        $this->get('/oficina/administracion/maestros-temporada')
            ->assertOk()
            ->assertSee('aria-label="Oficinas de Administración"', false)
            ->assertSee('Importar planilla de temporada')
            ->assertSee('id="importForm"', false);
    }

    public function test_modulo_maestro_es_administrativo_y_conserva_compatibilidad_con_perfiles_anteriores(): void
    {
        $catalogo = app(CatalogoModulosAcceso::class);
        $macromodulos = collect($catalogo->macromodulos())->keyBy('clave');
        $frigorifico = collect($macromodulos['frigorifico']['modulos'])->pluck('clave');
        $administracion = collect($macromodulos['administracion']['modulos'])->pluck('clave');

        $this->assertNotContains(CatalogoModulosAcceso::OFICINA_CATALOGOS_VALIDACION_LEGADO, $frigorifico);
        $this->assertContains(CatalogoModulosAcceso::OFICINA_MAESTROS_TEMPORADA, $administracion);
        $this->assertContains(CatalogoModulosAcceso::OFICINA_CATALOGOS_VALIDACION_LEGADO, $catalogo->claves());

        $navigation = file_get_contents(resource_path('js/office-navigation.js'));
        $this->assertIsString($navigation);
        $this->assertStringContainsString("'administracion.maestros-temporada': ['frigorifico.catalogos']", $navigation);
    }
}
