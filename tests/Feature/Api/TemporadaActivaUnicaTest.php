<?php

namespace Tests\Feature\Api;

use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemporadaActivaUnicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardar_nueva_y_editar_la_actual_conserva_una_sola_activa(): void
    {
        $anterior = $this->temporada('ANTERIOR');
        $usuario = User::factory()->create();
        $temporadas = app(ServicioTemporadaGlobal::class);

        $nueva = $temporadas->guardar([
            'codigo' => 'ACTUAL',
            'nombre' => 'Temporada actual',
            ...$this->vigenciaProductiva(),
            'activa' => true,
        ], usuarioId: $usuario->id);

        $this->assertFalse($anterior->refresh()->activa);
        $this->assertTrue($nueva->activa);
        $this->assertSame(1, Temporada::query()->where('activa', true)->count());

        $editada = $temporadas->guardar([
            'codigo' => $nueva->codigo,
            'nombre' => 'Temporada actualizada',
            'fecha_inicio' => $nueva->fecha_inicio->toDateString(),
            'fecha_fin' => $nueva->fecha_fin->toDateString(),
            'activa' => true,
        ], temporada: $nueva, usuarioId: $usuario->id);

        $this->assertSame('Temporada actualizada', $editada->nombre);
        $this->assertTrue($editada->activa);
        $this->assertSame(1, Temporada::query()->where('activa', true)->count());
    }

    public function test_activar_otra_temporada_invalida_el_cache_en_la_misma_peticion(): void
    {
        $anterior = $this->temporada('ANTERIOR');
        $nueva = $this->temporada('SIGUIENTE', false);
        $servicio = app(ServicioTemporadaActiva::class);
        $this->assertSame($anterior->id, $servicio->obtener()->id);

        app(ServicioTemporadaGlobal::class)->activar($nueva);

        $this->assertSame($nueva->id, $servicio->obtener()->id);
        $this->assertSame(1, Temporada::query()->where('activa', true)->count());
    }

    public function test_una_lectura_con_bloqueo_no_reutiliza_el_cache_y_folio_adopta_la_temporada_activa(): void
    {
        $anterior = $this->temporada('ANTERIOR');
        $nueva = $this->temporada('SIGUIENTE', false);
        $servicio = app(ServicioTemporadaActiva::class);
        $this->assertSame($anterior->id, $servicio->obtener()->id);

        DB::transaction(function () use ($anterior, $nueva, $servicio): void {
            $anterior->update(['activa' => false]);
            $nueva->update(['activa' => true]);
            $this->assertSame($nueva->id, $servicio->obtener(bloquear: true)->id);
        });
        $servicio->olvidar();

        $folio = Folio::create([
            'numero_folio' => 'PAL-TEMP-UNICA',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);
        $this->assertSame($nueva->id, $folio->temporada_id);
    }

    public function test_mysql_impide_insertar_una_segunda_temporada_activa(): void
    {
        $this->temporada('ANTERIOR');

        $this->expectException(QueryException::class);
        Temporada::create([
            'codigo' => 'DUPLICADA',
            'nombre' => 'DUPLICADA',
            ...$this->vigenciaProductiva(),
            'activa' => true,
        ]);
    }

    private function temporada(string $codigo, bool $activa = true): Temporada
    {
        if ($activa) {
            $this->desactivarTemporadasDePrueba();
        }

        return Temporada::create([
            'codigo' => $codigo,
            'nombre' => $codigo,
            ...$this->vigenciaProductiva(),
            'activa' => $activa,
        ]);
    }
}
