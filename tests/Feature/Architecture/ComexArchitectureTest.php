<?php

namespace Tests\Feature\Architecture;

use App\Comex\EsquemaComex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Comex «bebe» de la operación: la lee y no la toca. Estas reglas impiden que una ampliación
 * futura acople la operación (o Finanzas) a Comex o haga escribir a Comex fuera de su base.
 */
class ComexArchitectureTest extends TestCase
{
    public function test_ni_la_operacion_ni_otros_modulos_conocen_a_comex(): void
    {
        $acoplados = collect(File::allFiles(app_path()))
            ->reject(fn (SplFileInfo $archivo): bool => str_starts_with($archivo->getPathname(), app_path('Comex')))
            ->filter(fn (SplFileInfo $archivo): bool => str_contains($archivo->getContents(), 'App\\Comex'))
            ->map(fn (SplFileInfo $archivo): string => $archivo->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $acoplados, 'El código operativo y los demás módulos no deben depender de Comex.');
    }

    public function test_comex_no_depende_de_finanzas(): void
    {
        $acoplados = collect(File::allFiles(app_path('Comex')))
            ->filter(fn (SplFileInfo $archivo): bool => str_contains($archivo->getContents(), 'App\\Finanzas'))
            ->map(fn (SplFileInfo $archivo): string => $archivo->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $acoplados, 'Comex y Finanzas son módulos independientes.');
    }

    public function test_los_modelos_de_comex_viven_en_su_propia_base(): void
    {
        $modelos = collect(File::files(app_path('Comex/Models')))
            ->map(fn (SplFileInfo $archivo): string => 'App\\Comex\\Models\\'.$archivo->getFilenameWithoutExtension());

        $this->assertNotEmpty($modelos);
        foreach ($modelos as $clase) {
            /** @var Model $modelo */
            $modelo = new $clase;
            $this->assertSame(EsquemaComex::CONEXION, $modelo->getConnectionName(), $clase);
            $this->assertStringStartsWith('cx_', $modelo->getTable(), $clase);
        }
    }

    public function test_comex_lee_la_operacion_solo_por_el_lector(): void
    {
        $permitidos = ['LectorOperacion.php', 'EsquemaComex.php'];
        $infracciones = [];

        foreach (File::allFiles(app_path('Comex')) as $archivo) {
            $ruta = $archivo->getRelativePathname();
            $contenido = $archivo->getContents();

            if (preg_match_all('/^use (App\\\\Models\\\\[A-Za-z\\\\]+);/m', $contenido, $usos)) {
                foreach (array_diff($usos[1], ['App\\Models\\User']) as $uso) {
                    $infracciones[] = "{$ruta} usa {$uso}";
                }
            }

            if (! in_array($ruta, $permitidos, true) && preg_match('/\bDB::|Facades\\\\DB\b/', $contenido)) {
                $infracciones[] = "{$ruta} usa DB directamente";
            }
        }

        $this->assertSame([], $infracciones, 'Comex consulta la operación por LectorOperacion y escribe con sus modelos.');
    }

    public function test_la_lectura_de_la_operacion_no_escribe(): void
    {
        $escrituras = '/->(insert|insertOrIgnore|insertGetId|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach)\(/';

        foreach (['LectorOperacion.php', 'Expediente/EmbarquesOperacion.php'] as $archivo) {
            $this->assertDoesNotMatchRegularExpression($escrituras, File::get(app_path("Comex/{$archivo}")), $archivo);
        }
    }

    public function test_las_migraciones_de_comex_no_crean_llaves_hacia_la_operacion(): void
    {
        $migraciones = collect(File::files(database_path('migrations')));
        $deComex = $migraciones->filter(fn (SplFileInfo $archivo): bool => str_contains($archivo->getContents(), "\$connection = 'comex'"));

        $this->assertNotEmpty($deComex);
        foreach ($deComex as $archivo) {
            $contenido = $archivo->getContents();
            $this->assertStringNotContainsString('->constrained()', $contenido, $archivo->getFilename());
            preg_match_all("/->(?:constrained|on)\\('([^']+)'/", $contenido, $referencias);
            foreach ($referencias[1] as $tabla) {
                $this->assertStringStartsWith('cx_', $tabla, $archivo->getFilename());
            }
            preg_match_all("/(?:Schema::connection\\([^)]*\\)->|Schema::)(?:create|table)\\('([^']+)'/", $contenido, $tablas);
            $this->assertNotEmpty($tablas[1], $archivo->getFilename());
            foreach ($tablas[1] as $tabla) {
                $this->assertStringStartsWith('cx_', $tabla, $archivo->getFilename());
            }
        }

        // Y ninguna migración operativa apunta a una tabla de Comex.
        $operativas = $migraciones->diff($deComex)
            ->filter(fn (SplFileInfo $archivo): bool => Str::contains($archivo->getContents(), ["'cx_", '"cx_']))
            ->map(fn (SplFileInfo $archivo): string => $archivo->getFilename())
            ->values()
            ->all();
        $this->assertSame([], $operativas);
    }
}
