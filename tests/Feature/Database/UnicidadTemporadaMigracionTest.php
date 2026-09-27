<?php

namespace Tests\Feature\Database;

use App\Models\Temporada;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UnicidadTemporadaMigracionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
        } finally {
            parent::tearDown();
        }
    }

    public function test_migracion_rechaza_duplicados_sin_corregirlos_y_luego_instala_el_indice(): void
    {
        $migracion = require database_path('migrations/2026_09_27_120000_restringir_temporada_activa_unica.php');
        $migracion->down();

        $uno = Temporada::create(['codigo' => 'ACTIVA-UNO', 'nombre' => 'Uno', 'activa' => true]);
        $dos = Temporada::create(['codigo' => 'ACTIVA-DOS', 'nombre' => 'Dos', 'activa' => true]);

        try {
            $migracion->up();
            $this->fail('La migración debía informar las temporadas duplicadas.');
        } catch (RuntimeException $excepcion) {
            $this->assertStringContainsString('ACTIVA-DOS', $excepcion->getMessage());
            $this->assertStringContainsString('ACTIVA-UNO', $excepcion->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('temporadas', 'activa_unica'));
        $this->assertSame(2, Temporada::query()->where('activa', true)->count());

        DB::table('temporadas')->where('id', $dos->id)->update(['activa' => false]);
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('temporadas', 'activa_unica'));
        $this->assertSame($uno->id, Temporada::query()->where('activa', true)->value('id'));
    }
}
