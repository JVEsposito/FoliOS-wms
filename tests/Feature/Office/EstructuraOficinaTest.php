<?php

namespace Tests\Feature\Office;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EstructuraOficinaTest extends TestCase
{
    public function test_cabecera_conserva_identidad_y_navegacion_en_los_cinco_dominios(): void
    {
        foreach (['/oficina/materia-prima', '/oficina/frigorifico', '/oficina/materiales', '/oficina/materiales/items', '/oficina/administracion', '/oficina/consultas'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
                File::ensureDirectoryExists(storage_path('app/ui/oficina'));
                File::put(storage_path('app/ui/oficina/'.basename($path).'.html'), $html);
            }
            $dom = new DOMDocument;
            $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $this->assertSame(5, $xpath->query('//*[@data-domain-key]')->length, $path);
            foreach (['officeUserName', 'officeUserRole', 'officeInitials', 'officeLogoutButton', 'officeSidebar'] as $id) {
                $this->assertSame(1, $xpath->query('//*[@id="'.$id.'"]')->length, $path.' '.$id);
            }
            $this->assertSame(1, $xpath->query('//*[@data-office-context-refresh]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-context-status]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-menu]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-theme-toggle]')->length, $path);
            $this->assertSame(0, $xpath->query('//*[@id="officeThemeSelector"]')->length, $path);
            $this->assertSame(1, $xpath->query('//a[@aria-current="page"]')->length, $path);
            $this->assertSame(0, $xpath->query('//*[@data-office-shell]//button[not(@type)]')->length, $path);
        }
    }
}
