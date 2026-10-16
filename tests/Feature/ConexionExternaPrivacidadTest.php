<?php

namespace Tests\Feature;

use App\Providers\TelescopeServiceProvider;
use Illuminate\Http\Request;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

class ConexionExternaPrivacidadTest extends TestCase
{
    public function test_telescope_descarta_las_entradas_de_la_seccion_incluso_en_local(): void
    {
        $filtros = Telescope::$filterUsing;
        $this->app->instance('env', 'local');
        $this->app->instance('request', Request::create('/api/administracion/conexiones-externas/fixture/probar', 'POST'));
        try {
            (new TelescopeServiceProvider($this->app))->register();
            $filtro = Telescope::$filterUsing[array_key_last(Telescope::$filterUsing)];
            $this->assertFalse($filtro(IncomingEntry::make(['uri' => 'fixture'])));
            $this->app->instance('request', Request::create('/api/otro'));
            $this->assertTrue($filtro(IncomingEntry::make(['uri' => 'fixture'])));
        } finally {
            Telescope::$filterUsing = $filtros;
        }
    }

    public function test_el_formulario_no_precarga_datos_de_una_instalacion(): void
    {
        $html = $this->get('/oficina/accesos')->assertOk()->getContent();
        $dom = new \DOMDocument;
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        foreach (['nombre', 'host', 'puerto', 'usuario', 'password', 'base_datos'] as $campo) {
            $inputs = $xpath->query('//*[@id="externalConnectionForm"]//input[@name="'.$campo.'"]');
            $this->assertSame(1, $inputs->length);
            $this->assertSame('', $inputs->item(0)->getAttribute('value'));
        }
    }
}
