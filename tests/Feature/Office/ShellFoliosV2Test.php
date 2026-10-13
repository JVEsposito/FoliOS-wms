<?php

namespace Tests\Feature\Office;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class ShellFoliosV2Test extends TestCase
{
    private function xpath(string $path): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadHTML($this->get($path)->assertOk()->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    public function test_el_menu_muestra_las_seis_areas_del_gajo_en_orden_y_operacion_ahora_al_centro(): void
    {
        $xpath = $this->xpath('/oficina/romana');

        $areas = [];
        foreach ($xpath->query('//a[@data-domain-key]') as $link) {
            $areas[] = [$link->getAttribute('data-domain-key'), trim($link->textContent)];
        }
        $this->assertSame([
            ['materia-prima', 'Materia Prima'],
            ['frigorifico', 'Frigorífico'],
            ['materiales', 'Materiales'],
            ['consultas', 'Consultas'],
            ['gerencia', 'Gerencia'],
            ['administracion', 'Administración'],
        ], $areas);

        foreach (['materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion'] as $area) {
            $this->assertSame(1, $xpath->query("//a[@data-domain-key='{$area}']/*[local-name()='svg'][@data-area='{$area}']")->length, $area);
        }
        $this->assertSame(1, $xpath->query("//a[@data-office-key='operacion-ahora'][@data-office-domain='gerencia']/*[local-name()='svg'][@data-area='operacion']")->length);
        $this->assertSame(1, $xpath->query("//*[@data-office-shell][@data-folios-area='materia-prima']")->length);
        // Las oficinas del área activa quedan anidadas bajo su área.
        $this->assertSame(1, $xpath->query("//a[@data-domain-key='materia-prima']/following-sibling::nav[1]//a[@data-office-key='romana'][@aria-current='page']")->length);
    }

    public function test_panel_gerencial_y_operacion_ahora_pertenecen_a_gerencia(): void
    {
        $this->get('/oficina/gerencia')
            ->assertOk()
            ->assertSee('data-active-domain="gerencia"', false)
            ->assertSee('data-folios-area="gerencia"', false)
            ->assertSee('aria-label="Oficinas de Gerencia"', false);

        $this->get('/oficina/operacion-ahora')
            ->assertOk()
            ->assertSee('data-active-office="operacion-ahora"', false)
            ->assertSee('data-folios-area="operacion"', false);

        $this->get('/oficina/accesos')
            ->assertOk()
            ->assertSee('data-active-domain="administracion"', false)
            ->assertDontSee('data-office-key="panel"', false);
    }

    public function test_el_rotulo_de_servidor_es_opcional(): void
    {
        config(['app.server_label' => null]);
        $this->get('/oficina/romana')->assertOk()->assertDontSee('data-office-server', false);

        config(['app.server_label' => 'Servidor oficial']);
        $this->get('/oficina/romana')->assertOk()->assertSee('data-office-server>Servidor oficial</span>', false);
    }

    public function test_la_identidad_vive_al_pie_del_menu_lateral(): void
    {
        $xpath = $this->xpath('/oficina/validacion');

        foreach (['officeInitials', 'officeUserName', 'officeUserRole', 'officeLogoutButton'] as $id) {
            $this->assertSame(1, $xpath->query("//aside[@id='officeSidebar']//*[@id='{$id}']")->length, $id);
        }
        $this->assertSame(1, $xpath->query("//header[@data-office-shell-header]//*[@data-office-theme-toggle]")->length);
        $this->assertSame(1, $xpath->query("//header[@data-office-shell-header]//*[@data-office-context-refresh]")->length);
    }
}
