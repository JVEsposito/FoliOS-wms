<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\LineaProceso;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class OrdenesProcesoMpConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_dos_digitadores_no_comprometen_los_mismos_bins_en_lineas_distintas(): void
    {
        $lote = $this->loteMpEnCamara('CC1')['lote'];
        $token = $this->token(RolUsuario::DigitadorMateriaPrima);
        $solicitudes = [];
        foreach (['L1', 'L2'] as $codigo) {
            $solicitudes[] = ['POST', '/api/materia-prima/ordenes-proceso', $token, [
                'operacion_id' => (string) Str::uuid(),
                'linea_proceso_id' => LineaProceso::query()->where('codigo', $codigo)->value('id'),
                'fecha' => '2026-11-02',
                'turno_inicio' => 'A',
                'hora_inicio' => '08:00',
                'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 30]],
            ]];
        }

        $estados = array_column($this->solicitudesConcurrentes($solicitudes), 'status');
        sort($estados);

        $this->assertSame([201, 422], $estados);
        $this->assertSame(30, (int) ReservaLoteOrdenProcesoMp::query()->sum('bins_pendientes'));
    }

    public function test_dos_versiones_simultaneas_de_la_misma_orden_publican_una_sola(): void
    {
        $lote = $this->loteMpEnCamara('CC2')['lote'];
        $token = $this->token(RolUsuario::DigitadorMateriaPrima);
        $orden = $this->withToken($token)->postJson('/api/materia-prima/ordenes-proceso', [
            'operacion_id' => (string) Str::uuid(),
            'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
            'fecha' => '2026-11-02',
            'turno_inicio' => 'A',
            'hora_inicio' => '08:00',
            'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 10]],
        ])->assertCreated()->json('data');

        $solicitudes = array_map(fn (int $bins): array => [
            'POST', "/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $token, [
                'operacion_id' => (string) Str::uuid(),
                'version' => $orden['version'],
                'motivo_cambio' => "Ajuste a {$bins} bins",
                'hora_inicio' => '08:00',
                'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => $bins]],
            ],
        ], [12, 14]);

        $estados = array_column($this->solicitudesConcurrentes($solicitudes), 'status');
        sort($estados);

        $this->assertSame([200, 409], $estados);
        $this->assertDatabaseCount('versiones_orden_proceso_mp', 2);
    }

    private function token(RolUsuario $rol): string
    {
        return User::factory()->create(['rol' => $rol, 'activo' => true])
            ->createToken('ordenes-concurrentes', ['oficina'])->plainTextToken;
    }
}
