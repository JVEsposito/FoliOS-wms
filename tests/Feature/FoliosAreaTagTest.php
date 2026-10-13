<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FoliosAreaTagTest extends TestCase
{
    public function test_la_etiqueta_de_area_lleva_su_gajo_y_escapa_el_texto(): void
    {
        $html = Blade::render('<x-folios.area-tag area="materiales">{{ $texto }}</x-folios.area-tag>', ['texto' => 'Recepción <b>']);

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query("//span[contains(@class,'folios-area-tag')][@data-area='materiales']")->length);
        $this->assertSame(1, $xpath->query("//*[local-name()='svg'][@data-area='materiales'][@aria-hidden='true']")->length);
        $this->assertSame(1, $xpath->query("//*[local-name()='path'][contains(@class,'is-area')]")->length);
        $this->assertStringContainsString('Recepción &lt;b&gt;', $html);
    }

    public function test_un_area_desconocida_cae_en_operacion(): void
    {
        $html = Blade::render('<x-folios.area-tag area="otra">Texto</x-folios.area-tag>');
        $this->assertStringContainsString('data-area="operacion"', $html);
        $this->assertStringContainsString('folios-gajo--center', $html);
    }

    public function test_el_catalogo_muestra_las_siete_areas(): void
    {
        $path = sys_get_temp_dir().'/folios-catalogo-'.uniqid().'.html';
        try {
            $this->artisan('ui:catalogo', ['--output' => $path])->assertSuccessful();
            $html = file_get_contents($path);
            $this->assertSame(7, substr_count($html, 'data-catalog-area='));
            $this->assertStringContainsString('.folios-area-tag', $html);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
