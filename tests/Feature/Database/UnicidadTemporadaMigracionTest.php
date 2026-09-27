<?php

namespace Tests\Feature\Database;

use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaGlobal;
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
        $this->desactivarTemporadasDePrueba();

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

    public function test_cache_fuera_de_transaccion_se_invalida_al_activar_y_no_afecta_lecturas_con_bloqueo(): void
    {
        $servicio = app(ServicioTemporadaActiva::class);
        $anterior = $servicio->obtener();
        $nueva = Temporada::create([
            'codigo' => 'NUEVA-CACHE',
            'nombre' => 'Nueva temporada',
            ...$this->vigenciaProductiva(),
            'activa' => false,
            'prefijo_documental' => 'NCACHE',
        ]);

        DB::transaction(function () use ($anterior, $nueva, $servicio): void {
            DB::table('temporadas')->where('id', $anterior->id)->update(['activa' => false]);
            DB::table('temporadas')->where('id', $nueva->id)->update(['activa' => true]);
            $this->assertSame($nueva->id, $servicio->obtener(bloquear: true)->id);
            DB::table('temporadas')->where('id', $nueva->id)->update(['activa' => false]);
            DB::table('temporadas')->where('id', $anterior->id)->update(['activa' => true]);
        });

        $this->assertSame($anterior->id, $servicio->obtener()->id);
        app(ServicioTemporadaGlobal::class)->activar($nueva);
        $this->assertSame($nueva->id, $servicio->obtener()->id);
    }
}
