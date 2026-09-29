<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\BinRetornoPacking;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Existencias\ServicioExistencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DespachoComercialRetornoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepara_guia_externa_confirma_bins_y_conserva_snapshot(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $bin = $this->bin($temporada, $usuario, 'RET-COM-001', 'Comercial', 340.125);
        $otro = $this->bin($temporada, $usuario, 'RET-DEC-002', 'Desecho', 215.500);
        $this->actingAs($usuario, 'sanctum');

        $this->getJson('/api/materia-prima/despachos-comerciales/bins-disponibles')
            ->assertOk()->assertJsonCount(2, 'data');

        $despacho = $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Cliente de retornos',
            'bins' => [$bin->id, $otro->id],
        ])->assertCreated()->assertJsonCount(2, 'data.bins')->json('data');

        $this->assertMatchesRegularExpression('/^DC-\d{6}$/', $despacho['numero']);
        $this->assertDatabaseHas('bins_retorno_packing', ['id' => $bin->id, 'despacho_comercial_id' => $despacho['id']]);
        $this->assertSame('Reservado para despacho comercial', app(ServicioExistencias::class)
            ->filas(ServicioExistencias::MATERIA_PRIMA)
            ->firstWhere('folio_existencia', 'RET-COM-001')['estado_entrega']);
        $this->getJson('/api/materia-prima/despachos-comerciales/bins-disponibles')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Otro', 'bins' => [$bin->id],
        ])->assertStatus(409);

        $this->postJson("/api/materia-prima/despachos-comerciales/{$despacho['id']}/confirmar", [])
            ->assertUnprocessable()->assertJsonValidationErrors('numero_guia_sii');
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despacho['id']}/confirmar", [
            'numero_guia_sii' => '35201',
        ])->assertOk()->assertJsonPath('data.estado', 'confirmado')->assertJsonPath('data.numero_guia_sii', '35201');
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despacho['id']}/confirmar", [
            'numero_guia_sii' => '35201',
        ])->assertOk();
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despacho['id']}/cancelar")
            ->assertStatus(409);
        $this->assertDatabaseHas('despachos_comerciales_retorno_bins', [
            'despacho_comercial_id' => $despacho['id'], 'folio_definitivo' => 'RET-COM-001',
            'clasificacion' => 'Comercial', 'kilos_definitivos' => 340.125,
        ]);
        $this->assertNull(app(ServicioExistencias::class)
            ->filas(ServicioExistencias::MATERIA_PRIMA)
            ->firstWhere('folio_existencia', 'RET-COM-001'));
        $this->getJson('/api/materia-prima/despachos-comerciales')->assertOk()
            ->assertJsonPath('data.0.numero_guia_sii', '35201');
    }

    public function test_borrador_cancelado_libera_bin_y_guia_duplicada_no_confirma(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $primero = $this->bin($temporada, $usuario, 'RET-PRE-001', 'Precalibre', 100);
        $segundo = $this->bin($temporada, $usuario, 'RET-OTR-002', 'Otro', 200);
        $this->actingAs($usuario, 'sanctum');

        $borrador = $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Comprador A', 'bins' => [$primero->id],
        ])->assertCreated()->json('data');
        $this->postJson("/api/materia-prima/despachos-comerciales/{$borrador['id']}/cancelar")
            ->assertOk()->assertJsonPath('data.estado', 'cancelado');
        $this->assertDatabaseHas('bins_retorno_packing', ['id' => $primero->id, 'despacho_comercial_id' => null]);
        $this->assertDatabaseHas('despachos_comerciales_retorno_bins', ['despacho_comercial_id' => $borrador['id']]);

        $despachoA = $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Comprador A', 'bins' => [$primero->id],
        ])->assertCreated()->json('data');
        $despachoB = $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Comprador B', 'bins' => [$segundo->id],
        ])->assertCreated()->json('data');
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despachoA['id']}/confirmar", [
            'numero_guia_sii' => '3001',
        ])->assertOk();
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despachoB['id']}/confirmar", [
            'numero_guia_sii' => '3001',
        ])->assertStatus(409);
        $this->assertDatabaseHas('despachos_comerciales_retorno', ['id' => $despachoB['id'], 'estado' => 'borrador']);
    }

    public function test_no_permite_bin_pendiente_antiguo_ni_consulta_o_modificacion_sin_permiso(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $bin = $this->bin($temporada, $usuario, 'RET-REG-001', 'Comercial', 310);
        $binPendiente = $this->bin($temporada, $usuario, 'RET-PEN-002', 'Desecho', 300);
        $binPendiente->update(['estado' => 'pendiente_regularizacion', 'regularizado_at' => null]);
        $this->actingAs($usuario, 'sanctum');
        $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Comprador', 'bins' => [$binPendiente->id],
        ])->assertStatus(409);

        $despacho = $this->postJson('/api/materia-prima/despachos-comerciales', [
            'destinatario' => 'Comprador', 'bins' => [$bin->id],
        ])->assertCreated()->json('data');
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->actingAs($camarero, 'sanctum')->postJson("/api/materia-prima/fruta-proceso/retornos-bin/bins/{$bin->id}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'No se puede anular un reservado',
        ])->assertStatus(409);

        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/materia-prima/despachos-comerciales')->assertOk();
        $this->postJson("/api/materia-prima/despachos-comerciales/{$despacho['id']}/cancelar")->assertForbidden();
        $this->actingAs($camarero, 'sanctum')->getJson('/api/materia-prima/despachos-comerciales')->assertForbidden();
    }

    private function bin(Temporada $temporada, User $usuario, string $folio, string $clasificacion, float $kilos): BinRetornoPacking
    {
        return BinRetornoPacking::create([
            'temporada_id' => $temporada->id,
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $folio),
            'folio_provisional' => 'PR-'.$folio,
            'folio_definitivo' => $folio,
            'folio_definitivo_vigente' => $folio,
            'kilos_totales' => $kilos,
            'kilos_totales_definitivos' => $kilos,
            'nombre_resultado' => $clasificacion,
            'estado' => 'regularizado',
            'registrado_por_user_id' => $usuario->id,
            'registrado_at' => now(),
            'regularizado_at' => now(),
        ]);
    }
}
