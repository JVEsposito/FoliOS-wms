<?php

namespace Tests\Feature\Architecture;

use App\Finanzas\EsquemaFinanzas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Finanzas «bebe» de la operación: la lee y no la toca. Estas reglas impiden que una
 * ampliación futura acople la operación a Finanzas o haga escribir a Finanzas fuera de su base.
 */
class FinanzasArchitectureTest extends TestCase
{
    public function test_la_operacion_no_conoce_a_finanzas(): void
    {
        $acoplados = collect(File::allFiles(app_path()))
            ->reject(fn (SplFileInfo $archivo): bool => str_starts_with($archivo->getPathname(), app_path('Finanzas')))
            ->filter(fn (SplFileInfo $archivo): bool => str_contains($archivo->getContents(), 'App\\Finanzas'))
            ->map(fn (SplFileInfo $archivo): string => $archivo->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $acoplados, 'El código operativo no debe depender de Finanzas.');
    }

    public function test_los_modelos_de_finanzas_viven_en_su_propia_base(): void
    {
        $modelos = collect(File::files(app_path('Finanzas/Models')))
            ->map(fn (SplFileInfo $archivo): string => 'App\\Finanzas\\Models\\'.$archivo->getFilenameWithoutExtension());

        $this->assertNotEmpty($modelos);
        foreach ($modelos as $clase) {
            /** @var Model $modelo */
            $modelo = new $clase;
            $this->assertSame(EsquemaFinanzas::CONEXION, $modelo->getConnectionName(), $clase);
            $this->assertStringStartsWith('fin_', $modelo->getTable(), $clase);
        }
    }

    public function test_finanzas_lee_la_operacion_solo_por_el_lector(): void
    {
        $permitidos = ['Captura/LectorOperacion.php', 'Dotacion/LectorRrhh.php', 'EsquemaFinanzas.php'];
        $infracciones = [];

        foreach (File::allFiles(app_path('Finanzas')) as $archivo) {
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

        $this->assertSame([], $infracciones, 'Finanzas consulta la operación por LectorOperacion y escribe con sus modelos.');
    }

    public function test_las_fuentes_no_escriben(): void
    {
        $archivos = collect(File::allFiles(app_path('Finanzas/Captura')))
            ->reject(fn (SplFileInfo $archivo): bool => $archivo->getFilename() === 'ServicioCapturaHechos.php');
        $escrituras = '/->(insert|insertOrIgnore|insertGetId|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach)\(/';

        foreach ($archivos as $archivo) {
            $this->assertDoesNotMatchRegularExpression($escrituras, $archivo->getContents(), $archivo->getRelativePathname());
        }
    }

    public function test_las_migraciones_de_finanzas_no_crean_llaves_hacia_la_operacion(): void
    {
        $migraciones = collect(File::files(database_path('migrations')));
        $deFinanzas = $migraciones->filter(fn (SplFileInfo $archivo): bool => str_contains($archivo->getContents(), "\$connection = 'finanzas'"));

        $this->assertNotEmpty($deFinanzas);
        foreach ($deFinanzas as $archivo) {
            $contenido = $archivo->getContents();
            $this->assertStringNotContainsString('->constrained()', $contenido, $archivo->getFilename());
            preg_match_all("/->(?:constrained|on)\\('([^']+)'/", $contenido, $referencias);
            foreach ($referencias[1] as $tabla) {
                $this->assertStringStartsWith('fin_', $tabla, $archivo->getFilename());
            }
            preg_match_all("/Schema::(?:create|table)\\('([^']+)'/", $contenido, $tablas);
            foreach ($tablas[1] as $tabla) {
                $this->assertStringStartsWith('fin_', $tabla, $archivo->getFilename());
            }
        }

        // Y ninguna migración operativa apunta a una tabla de Finanzas.
        $operativas = $migraciones->diff($deFinanzas)
            ->filter(fn (SplFileInfo $archivo): bool => Str::contains($archivo->getContents(), ["'fin_", '"fin_']))
            ->map(fn (SplFileInfo $archivo): string => $archivo->getFilename())
            ->values()
            ->all();
        $this->assertSame([], $operativas);
    }
}
