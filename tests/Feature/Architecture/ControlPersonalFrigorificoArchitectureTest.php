<?php

namespace Tests\Feature\Architecture;

use App\Models\AusenciaFrigorifico;
use App\Models\JornadaFrigorifico;
use App\Models\PersonaFrigorifico;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El control de personal de Frigorífico es el registro propio del supervisor para contrastar a fin de mes
 * con lo que informa RRHH. Por decisión de negocio no conversa con RRHH en ningún sentido: no lee ni escribe
 * datos de RRHH, Finanzas o COMEX, y esos módulos tampoco leen sus tablas.
 */
class ControlPersonalFrigorificoArchitectureTest extends TestCase
{
    private const TABLAS = ['frigorifico_personas', 'frigorifico_jornadas', 'frigorifico_ausencias', 'frigorifico_control_personal_eventos'];

    /** @return list<string> */
    private function archivosDelControl(): array
    {
        return [
            ...array_map(fn ($a) => $a->getPathname(), File::allFiles(app_path('Services/Frigorifico'))),
            app_path('Http/Controllers/Api/ControlPersonalFrigorificoController.php'),
            app_path('Models/PersonaFrigorifico.php'), app_path('Models/JornadaFrigorifico.php'), app_path('Models/AusenciaFrigorifico.php'),
            resource_path('js/office-staff-control.js'), resource_path('views/office/staff-control.blade.php'),
        ];
    }

    public function test_el_control_no_lee_ni_escribe_rrhh_finanzas_ni_comex(): void
    {
        foreach ($this->archivosDelControl() as $archivo) {
            $contenido = File::get($archivo);
            foreach (['App\\Rrhh', 'App\\Finanzas', 'App\\Comex', 'App\\Gestion', '/api/rrhh', '/api/finanzas', 'operacion_lectura'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $contenido, basename($archivo).' no debe referirse a '.$prohibido);
            }
            $this->assertDoesNotMatchRegularExpression('/(connection|DB::connection)\s*\(/', $contenido, basename($archivo).' debe usar solo la base operativa.');
            $this->assertDoesNotMatchRegularExpression('/\b(rh|fin|cx)_[a-z_]+/', $contenido, basename($archivo).' no debe usar tablas de RRHH, Finanzas o COMEX.');
        }
        foreach ([PersonaFrigorifico::class, JornadaFrigorifico::class, AusenciaFrigorifico::class] as $modelo) {
            $this->assertNull((new $modelo)->getConnectionName(), $modelo.' debe vivir en la base operativa.');
        }
    }

    public function test_rrhh_finanzas_comex_y_gestion_no_leen_el_control_del_supervisor(): void
    {
        foreach (['Rrhh', 'Finanzas', 'Comex', 'Gestion'] as $modulo) {
            if (! File::isDirectory(app_path($modulo))) {
                continue;
            }
            foreach (File::allFiles(app_path($modulo)) as $archivo) {
                $contenido = $archivo->getContents();
                foreach ([...self::TABLAS, 'PersonaFrigorifico', 'JornadaFrigorifico', 'AusenciaFrigorifico', 'Services\\Frigorifico\\ControlPersonal', 'control-personal'] as $prohibido) {
                    $this->assertStringNotContainsString($prohibido, $contenido, $modulo.'/'.$archivo->getRelativePathname().' no debe leer '.$prohibido);
                }
            }
        }
        foreach (['rrhh', 'finanzas', 'comex'] as $prefijo) {
            foreach (File::glob(resource_path("js/*{$prefijo}*.js")) as $archivo) {
                $this->assertStringNotContainsString('control-personal', File::get($archivo), basename($archivo));
            }
        }
    }
}
