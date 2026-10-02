<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstadoCargaFolioDespachadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracion_es_idempotente_y_solo_finaliza_folios_en_anden_de_cargas_despachadas_o_cerradas(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-MIG',
            'nombre' => 'Temporada de migración',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $usuario = User::factory()->create(['rol' => RolUsuario::Despachador]);
        $asignaciones = [];
        foreach ([EstadoCarga::Cerrada, EstadoCarga::Despachada, EstadoCarga::Pendiente] as $indice => $estado) {
            $carga = Carga::create([
                'temporada_id' => $temporada->id,
                'codigo' => sprintf('CAR-MIG-%02d', $indice),
                'estado' => $estado,
                'prioridad' => PrioridadCarga::Normal,
                'creada_por_user_id' => $usuario->id,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $folio = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => sprintf('PAL-MIG-%02d', $indice),
                'tipo_bulto' => TipoBulto::Pallet,
                'fecha_ingreso' => now(),
                'activo' => true,
            ]);
            $asignaciones[] = CargaFolio::create([
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'estado' => 'en_anden',
                'asignado_por_user_id' => $usuario->id,
                'asignado_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_10_01_000000_finalizar_folios_de_cargas_despachadas.php');
        $migration->up();
        $migration->up();

        $this->assertSame('despachado', $asignaciones[0]->refresh()->estado->value);
        $this->assertSame('despachado', $asignaciones[1]->refresh()->estado->value);
        $this->assertSame('en_anden', $asignaciones[2]->refresh()->estado->value);
    }
}
