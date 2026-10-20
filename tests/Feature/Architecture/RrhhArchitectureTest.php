<?php

namespace Tests\Feature\Architecture;

use App\Gestion\ModulosGestion;
use App\Rrhh\EsquemaRrhh;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RrhhArchitectureTest extends TestCase
{
    public function test_rrhh_se_registra_como_modulo_de_gestion(): void
    {
        $esquemas = array_map(fn (object $e): string => $e::class, app(ModulosGestion::class)->esquemas());
        $this->assertContains(EsquemaRrhh::class, $esquemas);
    }

    public function test_rrhh_tiene_base_propia_y_no_depende_de_finanzas_ni_de_modelos_operativos(): void
    {
        foreach (File::files(app_path('Rrhh/Models')) as $archivo) {
            $clase = 'App\\Rrhh\\Models\\'.$archivo->getFilenameWithoutExtension();
            $modelo = new $clase;
            $this->assertSame('rrhh', $modelo->getConnectionName());
            $this->assertStringStartsWith('rh_', $modelo->getTable());
        }
        foreach (File::allFiles(app_path('Rrhh')) as $archivo) {
            $contenido = $archivo->getContents();
            $this->assertStringNotContainsString('App\\Finanzas', $contenido, $archivo->getRelativePathname());
            preg_match_all('/^use (App\\\\Models\\\\[A-Za-z\\\\]+);/m', $contenido, $usos);
            $this->assertSame([], array_values(array_diff($usos[1], ['App\\Models\\User'])), $archivo->getRelativePathname());
            if ($archivo->getRelativePathname() !== 'LectorOperacion.php') {
                $this->assertStringNotContainsString('DB::', $contenido, $archivo->getRelativePathname());
            }
        }
    }
}
