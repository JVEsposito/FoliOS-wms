<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavegacionOficinasPorDominioTest extends TestCase
{
    public function test_oficina_lleva_a_la_entrada_unica_de_folios(): void
    {
        $this->get('/oficina')->assertRedirect('/FoliOS');

        $script = file_get_contents(resource_path('js/office-navigation.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('officeHomeHref(identity)', $script);
        $this->assertStringNotContainsString('roleHomes', $script);
    }

    public function test_toda_oficina_ofrece_busqueda_global_y_logo_hacia_el_inicio(): void
    {
        $this->get('/oficina/romana')
            ->assertOk()
            ->assertSee('class="estiba-office-search" action="/oficina/consultas/busqueda"', false)
            ->assertSee('data-navigation-permissions="puede_consultar_oficina_consultas"', false)
            ->assertSee('id="officeGlobalSearch"', false)
            ->assertSee('data-office-home', false);

        $script = file_get_contents(resource_path('js/office-queries.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('function runSearchFromUrl()', $script);
        $this->assertStringContainsString('query-search-limit', $script);
    }

    public function test_materia_prima_muestra_solo_sus_oficinas_secundarias(): void
    {
        $this->get('/oficina/materia-prima')
            ->assertOk()
            ->assertSee('data-active-domain="materia-prima"', false)
            ->assertSee('Romana')
            ->assertSee('Digitación de Lotes')
            ->assertSee('Fruta a Proceso')
            ->assertSee('Cuenta Envases')
            ->assertSee('Despacho Envases')
            ->assertDontSee('data-office-key="despacho-estatus"', false);
    }

    public function test_frigorifico_muestra_sus_oficinas_y_no_la_configuracion_administrativa(): void
    {
        $this->get('/oficina/frigorifico/camaras')
            ->assertOk()
            ->assertSee('data-active-domain="frigorifico"', false)
            ->assertSee('Validación')
            ->assertDontSee('Catálogos PT')
            ->assertSee('Prefrío')
            ->assertSee('Estatus operativo')
            ->assertSee('Calendario de embarques')
            ->assertSee('data-navigation-permissions="ambito_camaras_productos"', false)
            ->assertDontSee('Configuración de cámaras');
    }

    public function test_configuracion_de_camaras_pertenece_a_administracion(): void
    {
        $this->get('/oficina/administracion/camaras')
            ->assertOk()
            ->assertSee('data-active-domain="administracion"', false)
            ->assertSee('Configuración de cámaras')
            ->assertSee('Maestros de temporada')
            ->assertSee('data-camera-mode="configuracion"', false);

        $this->get('/oficina/camaras')
            ->assertRedirect('/oficina/frigorifico/camaras');
    }

    public function test_maestros_de_temporada_y_despacho_de_envases_tienen_seleccion_independiente(): void
    {
        $this->get('/oficina/administracion/maestros-temporada')
            ->assertOk()
            ->assertSee('data-active-office="maestros-temporada"', false)
            ->assertSee('data-active-domain="administracion"', false);

        $this->get('/oficina/envases/despachos')
            ->assertOk()
            ->assertSee('data-active-office="despacho-envases"', false);
    }

    public function test_consultas_es_un_macromodulo_independiente(): void
    {
        $this->get('/oficina/consultas')
            ->assertOk()
            ->assertSee('data-active-domain="consultas"', false)
            ->assertSee('data-lobby-domain="consultas"', false)
            ->assertSee('Búsqueda Operacional')
            ->assertSee('Productores SAG / CSG')
            ->assertSee('Productores Verificados')
            ->assertDontSee('Digitación de Lotes');

        $this->get('/oficina/consultas/busqueda')
            ->assertOk()
            ->assertSee('data-queries-section="busqueda"', false);

        $this->get('/oficina/consultas/sag')
            ->assertOk()
            ->assertSee('data-active-office="sag"', false)
            ->assertSee('data-queries-section="sag"', false);

        $this->get('/oficina/consultas/productores')
            ->assertOk()
            ->assertSee('data-active-office="productores"', false)
            ->assertSee('data-queries-section="productores"', false);
    }

    public function test_cada_macromodulo_publica_sus_destinos_y_no_un_enlace_fijo_inaccesible(): void
    {
        $this->get('/oficina/frigorifico/despacho/cargas')
            ->assertOk()
            ->assertSee('data-navigation-targets=', false)
            ->assertSee('&quot;href&quot;:&quot;/oficina/romana&quot;', false)
            ->assertSee('&quot;module&quot;:&quot;materia-prima.romana&quot;', false)
            ->assertSee('&quot;permissions&quot;:[&quot;puede_consultar_romana&quot;]', false)
            ->assertSee('&quot;href&quot;:&quot;/oficina/materiales&quot;', false)
            ->assertDontSee('puede_consultar_materia_prima,puede_consultar_cargas', false);

        $script = file_get_contents(resource_path('js/office-navigation.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('firstAccessibleTarget', $script);
        $this->assertStringContainsString('hasModule', $script);
        $this->assertStringContainsString('navigationModule', $script);
        $this->assertStringContainsString('ambito_camaras_productos', $script);
        $this->assertStringContainsString('window.location.replace', $script);
    }
}
