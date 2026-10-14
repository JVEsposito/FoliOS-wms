<?php

namespace Tests\Feature\Office;

use App\Services\Autorizacion\CatalogoOficinas;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Al cambiar de oficina no debe verse un instante el acceso propio de la oficina, el otro
 * tema ni el menú sin estilo: todo eso se decide en <head>, antes del primer pintado.
 */
class ArranqueOficinaTest extends TestCase
{
    public function test_cada_oficina_decide_tema_sesion_y_menu_en_la_cabecera(): void
    {
        $rutas = collect(app(CatalogoOficinas::class)->opcionesInicio())->pluck('ruta')->unique();
        $this->assertGreaterThan(40, $rutas->count());

        foreach ($rutas as $ruta) {
            $html = $this->get($ruta)->assertOk()->getContent();
            $finCabecera = mb_strpos($html, '</head>');
            $this->assertNotFalse($finCabecera, $ruta);
            $cabecera = mb_substr($html, 0, $finCabecera);
            $cuerpo = mb_substr($html, $finCabecera);

            $this->assertStringContainsString("setAttribute('data-office-session', 'activa')", $cabecera, $ruta);
            $this->assertStringContainsString("setAttribute('data-office-theme', theme)", $cabecera, $ruta);
            $this->assertStringContainsString('estiba_wms_office_menu', $cabecera, $ruta);
            // Los estilos del menú van en <head>; en <body> el menú se alcanzaba a pintar sin estilo.
            $this->assertDoesNotMatchRegularExpression('/<link[^>]+(rel="stylesheet"|as="style")/', $cuerpo, $ruta);
            $this->assertStringContainsString("getElementById('officeUserName').textContent = name", $cuerpo, $ruta);
        }
    }

    public function test_toda_vista_con_menu_incluye_el_arranque_antes_de_cerrar_la_cabecera(): void
    {
        $vistas = collect(File::files(resource_path('views/office')))
            ->filter(fn ($archivo): bool => str_contains($archivo->getContents(), '<x-office.navigation'));
        $this->assertGreaterThan(30, $vistas->count());

        foreach ($vistas as $vista) {
            $contenido = $vista->getContents();
            $inclusion = mb_strpos($contenido, "@include('office.partials.arranque')");
            $this->assertNotFalse($inclusion, $vista->getFilename());
            $this->assertLessThan(mb_strpos($contenido, '</head>'), $inclusion, $vista->getFilename());
        }

        $menu = File::get(resource_path('views/components/office/navigation.blade.php'));
        $this->assertStringNotContainsString('@vite', $menu);
    }

    public function test_la_entrada_unica_solo_toma_el_tema(): void
    {
        $html = $this->get('/FoliOS')->assertOk()->getContent();

        $this->assertStringContainsString('<script data-solo-tema>', $html);
    }
}
