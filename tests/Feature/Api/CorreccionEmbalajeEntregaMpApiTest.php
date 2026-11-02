<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\EntregaFrutaProceso;
use App\Models\LineaProceso;
use App\Models\LoteMateriaPrima;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class CorreccionEmbalajeEntregaMpApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    public function test_cambiar_embalaje_pendiente_conserva_seis_programados_cuatro_entregados_y_dos_pendientes(): void
    {
        [$orden, $lote, $digitador, $entrega] = $this->prepararEntregaParcial();
        $this->actingAs($digitador, 'sanctum')->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $this->version($orden, $lote, 6))
            ->assertOk()->assertJsonPath('data.progreso.bins_programados', 6)
            ->assertJsonPath('data.progreso.bins_entregados', 4)->assertJsonPath('data.progreso.bins_pendientes', 2)
            ->assertJsonPath('data.version_vigente.lotes.0.embalaje', 'CAJA-2')
            ->assertJsonPath('data.entregas.0.embalaje', 'CAJA-5');
        $this->assertSame(4, EntregaFrutaProceso::findOrFail($entrega['id'])->cantidad_envases);
        $this->assertSame(2, ReservaLoteOrdenProcesoMp::query()->where('orden_proceso_mp_id', $orden['id'])->sole()->bins_pendientes);
        $this->assertDatabaseCount('correcciones_embalaje_mp', 0);
    }

    public function test_reducir_el_total_exige_confirmacion_y_solo_entonces_libera_los_dos_pendientes(): void
    {
        [$orden, $lote, $digitador] = $this->prepararEntregaParcial();
        $ruta = "/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones";
        $payload = $this->version($orden, $lote, 4);
        $this->actingAs($digitador, 'sanctum')->postJson($ruta, $payload)->assertUnprocessable()->assertJsonValidationErrors('confirmar_reduccion');
        $reserva = ReservaLoteOrdenProcesoMp::query()->where('orden_proceso_mp_id', $orden['id'])->sole();
        $this->assertSame([6, 4, 2], [$reserva->bins_programados, $reserva->bins_entregados, $reserva->bins_pendientes]);
        $this->postJson($ruta, [...$payload, 'confirmar_reduccion' => true])->assertOk()
            ->assertJsonPath('data.progreso.bins_programados', 4)->assertJsonPath('data.progreso.bins_pendientes', 0)
            ->assertJsonPath('data.estado', 'terminada');
        $this->assertSame(4, EntregaFrutaProceso::query()->sole()->cantidad_envases);
    }

    public function test_corregir_la_entrega_no_modifica_la_orden_el_saldo_ni_la_version_del_lote_y_es_idempotente(): void
    {
        [$orden, $lote, $digitador, $entrega] = $this->prepararEntregaParcial();
        $antes = LoteMateriaPrima::findOrFail($lote['id'])->only(['estado', 'version', 'cantidad_envases_primarios']);
        $payload = $this->correccion($entrega);
        $ruta = "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/embalaje";
        $this->actingAs($digitador, 'sanctum')->postJson($ruta, $payload)->assertOk()
            ->assertJsonPath('data.embalaje', 'CAJA-CORREGIDA')->assertJsonPath('data.cantidad_envases', 4)
            ->assertJsonPath('data.correcciones_embalaje.0.anterior.embalaje', 'CAJA-5')
            ->assertJsonPath('data.correcciones_embalaje.0.usuario', $digitador->name);
        $this->postJson($ruta, $payload)->assertOk();
        $this->assertDatabaseCount('correcciones_embalaje_mp', 1);
        $this->postJson($ruta, [...$payload, 'embalaje' => 'OTRA-CAJA'])->assertStatus(409);
        $this->assertSame($antes, LoteMateriaPrima::findOrFail($lote['id'])->only(['estado', 'version', 'cantidad_envases_primarios']));
        $this->getJson("/api/materia-prima/ordenes-proceso/{$orden['id']}")->assertOk()
            ->assertJsonPath('data.progreso.bins_programados', 6)->assertJsonPath('data.progreso.bins_entregados', 4)
            ->assertJsonPath('data.progreso.bins_pendientes', 2)->assertJsonPath('data.version_vigente.lotes.0.embalaje', 'CAJA-5')
            ->assertJsonPath('data.entregas.0.embalaje', 'CAJA-CORREGIDA');
        $this->assertSame(4, EntregaFrutaProceso::query()->sole()->cantidad_envases);
        $this->assertSame($orden['version_vigente']['id'], EntregaFrutaProceso::query()->sole()->version_orden_proceso_mp_id);
    }

    public function test_una_entrega_historica_lee_su_version_original_aunque_cambie_el_embalaje_pendiente(): void
    {
        [$orden, $lote, $digitador, $entrega] = $this->prepararEntregaParcial();
        EntregaFrutaProceso::findOrFail($entrega['id'])->update(['version_embalaje' => 0, 'embalaje_snapshot' => null, 'kilos_por_caja_snapshot' => null]);
        $this->actingAs($digitador, 'sanctum')->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $this->version($orden, $lote, 6))
            ->assertOk()->assertJsonPath('data.entregas.0.embalaje', 'CAJA-5');
        $this->postJson("/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/embalaje", [...$this->correccion($entrega), 'version_embalaje' => 0])
            ->assertOk()->assertJsonPath('data.correcciones_embalaje.0.anterior.embalaje', 'CAJA-5')
            ->assertJsonPath('data.version_embalaje', 1);
    }

    public function test_se_puede_corregir_un_viaje_de_una_orden_completa_sin_reabrirla_ni_cambiar_los_otros_viajes(): void
    {
        [$orden, $lote, $digitador, $primera] = $this->prepararEntregaParcial();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
                'operacion_id' => (string) Str::uuid(), 'orden_proceso_mp_id' => $orden['id'],
                'version_orden_proceso_mp_id' => $orden['version_vigente']['id'], 'cantidad_envases' => 2, 'turno' => 'A',
            ])->assertOk();
        $segunda = EntregaFrutaProceso::query()->whereKeyNot($primera['id'])->sole();
        $this->actingAs($digitador, 'sanctum')->postJson("/api/materia-prima/fruta-proceso/entregas/{$primera['id']}/embalaje", $this->correccion($primera))->assertOk();
        $this->getJson("/api/materia-prima/ordenes-proceso/{$orden['id']}")->assertOk()
            ->assertJsonPath('data.estado', 'terminada')->assertJsonPath('data.progreso.bins_entregados', 6)
            ->assertJsonPath('data.progreso.bins_pendientes', 0);
        $this->assertSame('CAJA-5', $segunda->refresh()->embalajeRegistrado()['embalaje']);
        $this->assertSame(2, EntregaFrutaProceso::query()->count());
    }

    public function test_exige_motivo_permiso_y_version_y_no_admite_cantidades_en_la_correccion(): void
    {
        [, , $digitador, $entrega] = $this->prepararEntregaParcial();
        $ruta = "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/embalaje";
        $payload = $this->correccion($entrega);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')->postJson($ruta, $payload)->assertForbidden();
        $this->actingAs($digitador, 'sanctum')->postJson($ruta, [...$payload, 'motivo' => ''])->assertUnprocessable();
        $this->postJson($ruta, [...$payload, 'cantidad_envases' => 2])->assertUnprocessable()->assertJsonValidationErrors('cantidad_envases');
        $this->postJson($ruta, $payload)->assertOk();
        $this->postJson($ruta, [...$payload, 'operacion_id' => (string) Str::uuid(), 'embalaje' => 'SEGUNDO-CAMBIO'])->assertStatus(409);
        $this->assertDatabaseCount('correcciones_embalaje_mp', 1);
        EntregaFrutaProceso::findOrFail($entrega['id'])->update(['anulado_at' => now()]);
        $this->postJson($ruta, [...$payload, 'operacion_id' => (string) Str::uuid(), 'version_embalaje' => 2])->assertStatus(409);
    }

    private function prepararEntregaParcial(): array
    {
        $lote = $this->loteMpEnCamara('EMP')['lote'];
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $orden = $this->actingAs($digitador, 'sanctum')->postJson('/api/materia-prima/ordenes-proceso', [
            'operacion_id' => (string) Str::uuid(), 'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
            'fecha' => '2026-11-02', 'turno_inicio' => 'A', 'hora_inicio' => '08:00',
            'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 6, 'embalaje' => 'CAJA-5', 'kilos_por_caja' => 5]],
        ])->assertCreated()->json('data');
        $entrega = $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
                'operacion_id' => (string) Str::uuid(), 'orden_proceso_mp_id' => $orden['id'],
                'version_orden_proceso_mp_id' => $orden['version_vigente']['id'], 'cantidad_envases' => 4, 'turno' => 'A',
            ])->assertOk()->json('data.entregas.0');

        return [$orden, $lote, $digitador, $entrega];
    }

    private function version(array $orden, array $lote, int $bins): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version' => $orden['version'], 'hora_inicio' => '08:00', 'motivo_cambio' => 'Cambio solicitado por packing',
            'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => $bins, 'embalaje' => 'CAJA-2', 'kilos_por_caja' => 2]]];
    }

    private function correccion(array $entrega): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_embalaje' => $entrega['version_embalaje'],
            'embalaje' => 'CAJA-CORREGIDA', 'kilos_por_caja' => 7, 'motivo' => 'Digitación de embalaje equivocada'];
    }
}
