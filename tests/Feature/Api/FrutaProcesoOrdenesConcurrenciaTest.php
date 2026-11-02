<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\EntregaFrutaProceso;
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

class FrutaProcesoOrdenesConcurrenciaTest extends TestCase
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

    public function test_entregas_y_versiones_simultaneas_no_entregan_mas_de_lo_programado(): void
    {
        $lote = $this->loteMpEnCamara('CF1')['lote'];
        $digitador = $this->token(RolUsuario::DigitadorMateriaPrima);
        $orden = $this->withToken($digitador)->postJson('/api/materia-prima/ordenes-proceso', [
            'operacion_id' => (string) Str::uuid(),
            'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
            'fecha' => '2026-11-02',
            'turno_inicio' => 'A',
            'hora_inicio' => '08:00',
            'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 10]],
        ])->assertCreated()->json('data');
        $ruta = "/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas";
        $viaje = fn (int $bins): array => [
            'operacion_id' => (string) Str::uuid(),
            'orden_proceso_mp_id' => $orden['id'],
            'version_orden_proceso_mp_id' => $orden['version_vigente']['id'],
            'cantidad_envases' => $bins,
            'turno' => 'A',
        ];

        // Dos camareros por 6 bins cada uno de 10 programados: solo uno entra.
        $estados = array_column($this->solicitudesConcurrentes([
            ['POST', $ruta, $this->token(RolUsuario::CamareroFrio), $viaje(6)],
            ['POST', $ruta, $this->token(RolUsuario::CamareroFrio), $viaje(6)],
        ]), 'status');
        sort($estados);
        $this->assertSame([200, 422], $estados);
        $this->assertInvariantes($orden['id'], 10);

        // Un viaje mientras el digitador baja el lote a 7 bins: gane quien gane, la reserva cuadra.
        $resultados = $this->solicitudesConcurrentes([
            ['POST', $ruta, $this->token(RolUsuario::CamareroFrio), $viaje(3)],
            ['POST', "/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $digitador, [
                'operacion_id' => (string) Str::uuid(),
                'version' => 1,
                'motivo_cambio' => 'Menos fruta para el turno',
                'hora_inicio' => '08:00',
                'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 7]],
            ]],
        ]);
        $this->assertContains($resultados[0]['status'], [200, 409, 422], json_encode($resultados[0]));
        $this->assertContains($resultados[1]['status'], [200, 422], json_encode($resultados[1]));
        $programados = $resultados[1]['status'] === 200 ? 7 : 10;
        $this->assertInvariantes($orden['id'], $programados);
    }

    public function test_un_viaje_legado_y_una_version_simultaneos_no_consumen_los_mismos_bins(): void
    {
        config(['materia_prima.fruta_proceso_exige_orden' => false]);
        $lote = $this->loteMpEnCamara('LEGCC')['lote'];
        $digitador = $this->token(RolUsuario::DigitadorMateriaPrima);
        $orden = $this->withToken($digitador)->postJson('/api/materia-prima/ordenes-proceso', [
            'operacion_id' => (string) Str::uuid(),
            'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
            'fecha' => '2026-11-02', 'turno_inicio' => 'A', 'hora_inicio' => '08:00',
            'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 40]],
        ])->assertCreated()->json('data');
        $resultados = $this->solicitudesConcurrentes([
            ['POST', "/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->token(RolUsuario::CamareroFrio), [
                'operacion_id' => (string) Str::uuid(), 'cantidad_envases' => 8,
                'linea_proceso' => 'Línea 1', 'turno' => 'A', 'numero_orden' => 'LEGADO',
            ]],
            ['POST', "/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $digitador, [
                'operacion_id' => (string) Str::uuid(), 'version' => 1,
                'motivo_cambio' => 'Programar todos los bins', 'hora_inicio' => '08:00',
                'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 48]],
            ]],
        ]);
        $estados = array_column($resultados, 'status');
        sort($estados);
        $this->assertSame([200, 422], $estados, json_encode($resultados));
        $fisicos = 48 - (int) EntregaFrutaProceso::query()->whereNull('anulado_at')->sum('cantidad_envases');
        $this->assertSame($fisicos, (int) ReservaLoteOrdenProcesoMp::query()->sum('bins_pendientes'));
    }

    private function assertInvariantes(string $ordenId, int $programados): void
    {
        $reserva = ReservaLoteOrdenProcesoMp::query()->where('orden_proceso_mp_id', $ordenId)->firstOrFail();
        $entregados = (int) EntregaFrutaProceso::query()
            ->where('orden_proceso_mp_id', $ordenId)
            ->whereNull('anulado_at')
            ->sum('cantidad_envases');

        $this->assertSame($programados, $reserva->bins_programados);
        $this->assertSame($entregados, $reserva->bins_entregados);
        $this->assertSame($programados - $entregados, $reserva->bins_pendientes);
        $this->assertGreaterThanOrEqual(0, $reserva->bins_pendientes);
    }

    private function token(RolUsuario $rol): string
    {
        return User::factory()->create(['rol' => $rol, 'activo' => true])
            ->createToken('fruta-proceso-concurrente', ['oficina'])->plainTextToken;
    }
}
