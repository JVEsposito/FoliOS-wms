<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\EntregaFrutaProceso;
use App\Models\LineaProceso;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class FrutaProcesoOrdenesApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    public function test_el_camarero_entrega_contra_la_orden_y_la_orden_avanza_hasta_terminar(): void
    {
        $lote = $this->loteMpEnCamara('ENT')['lote'];
        $orden = $this->crearOrden([['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 20, 'numero_proceso' => 'P-500']]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->actingAs($camarero, 'sanctum');

        $this->getJson('/api/materia-prima/fruta-proceso/ordenes')
            ->assertOk()
            ->assertJsonPath('data.0.codigo', $orden['codigo'])
            ->assertJsonPath('data.0.version_vigente.lotes.0.numero_lote', 'L-ENT')
            ->assertJsonPath('data.0.version_vigente.lotes.0.bins_pendientes', 20)
            ->assertJsonPath('data.0.version_vigente.lotes.0.camara.codigo', 'MP-ENT');
        $this->getJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}")
            ->assertOk()
            ->assertJsonPath('data.ordenes.0.codigo', $orden['codigo'])
            ->assertJsonPath('data.ordenes.0.bins_pendientes', 20);

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 12))
            ->assertOk()
            ->assertJsonPath('data.entregas.0.orden_proceso.codigo', $orden['codigo'])
            ->assertJsonPath('data.entregas.0.linea_proceso', 'Línea 1')
            ->assertJsonPath('data.entregas.0.numero_orden', 'P-500')
            ->assertJsonPath('data.ordenes.0.bins_pendientes', 8);
        $this->assertDatabaseHas('ordenes_proceso_mp', ['id' => $orden['id'], 'estado' => 'en_proceso']);
        $this->assertDatabaseHas('entregas_fruta_proceso', [
            'orden_proceso_mp_id' => $orden['id'],
            'version_orden_proceso_mp_id' => $orden['version_vigente']['id'],
            'fuera_de_orden' => false,
        ]);

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 9))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cantidad_envases');
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [...$this->viaje($orden, 8), 'turno' => 'B'])
            ->assertOk()
            ->assertJsonPath('data.ordenes', []);
        $this->assertDatabaseHas('ordenes_proceso_mp', ['id' => $orden['id'], 'estado' => 'terminada', 'tipo_cierre' => 'completa']);
        $this->assertSame(['A', 'B'], EntregaFrutaProceso::query()->orderBy('cantidad_envases', 'desc')->pluck('turno')->all());

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 1))
            ->assertStatus(409);
    }

    public function test_una_version_nueva_solo_rechaza_la_entrega_si_cambio_el_lote_entregado(): void
    {
        $primero = $this->loteMpEnCamara('VR1')['lote'];
        $segundo = $this->loteMpEnCamara('VR2')['lote'];
        $tercero = $this->loteMpEnCamara('VR3')['lote'];
        $orden = $this->crearOrden([
            ['lote_materia_prima_id' => $primero['id'], 'bins_programados' => 10],
            ['lote_materia_prima_id' => $segundo['id'], 'bins_programados' => 10],
        ]);
        $this->publicarVersion($orden, [
            ['lote_materia_prima_id' => $primero['id'], 'bins_programados' => 10],
            ['lote_materia_prima_id' => $segundo['id'], 'bins_programados' => 15],
            ['lote_materia_prima_id' => $tercero['id'], 'bins_programados' => 5],
        ]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum');

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$primero['id']}/entregas", $this->viaje($orden, 4))->assertOk();
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$segundo['id']}/entregas", $this->viaje($orden, 4))
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $mensaje): bool => str_contains($mensaje, 'v2'));

        $v3 = $this->publicarVersion($orden, [
            ['lote_materia_prima_id' => $segundo['id'], 'bins_programados' => 15],
            ['lote_materia_prima_id' => $primero['id'], 'bins_programados' => 10],
        ], 2);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum');
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$tercero['id']}/entregas", [
            ...$this->viaje($orden, 2), 'version_orden_proceso_mp_id' => $v3['versiones'][1]['id'],
        ])->assertStatus(409)->assertJsonPath('message', fn (string $mensaje): bool => str_contains($mensaje, 'salió'));
    }

    public function test_un_lote_fuera_de_orden_exige_el_pin_de_un_supervisor_y_entra_como_nueva_version(): void
    {
        $programado = $this->loteMpEnCamara('FO1')['lote'];
        $extra = $this->loteMpEnCamara('FO2')['lote'];
        $orden = $this->crearOrden([['lote_materia_prima_id' => $programado['id'], 'bins_programados' => 10]]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'name' => 'Supervisora Frío']);
        $supervisor->forceFill(['pin_operacional_hash' => Hash::make('2580')])->save();
        $otroCamarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $otroCamarero->forceFill(['pin_operacional_hash' => Hash::make('2580')])->save();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum');

        $this->getJson('/api/materia-prima/fruta-proceso/autorizadores')
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Supervisora Frío')
            ->assertJsonCount(1, 'data');
        $ruta = "/api/materia-prima/fruta-proceso/lotes/{$extra['id']}/entregas";
        $this->postJson($ruta, $this->viaje($orden, 6))->assertUnprocessable()->assertJsonValidationErrors('autorizacion');
        $this->postJson($ruta, [...$this->viaje($orden, 6), 'autorizacion' => ['user_id' => $otroCamarero->id, 'pin' => '2580']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('autorizacion.user_id');
        $this->postJson($ruta, [...$this->viaje($orden, 6), 'autorizacion' => ['user_id' => $supervisor->id, 'pin' => '1397']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('autorizacion.pin');
        $this->assertSame(1, $supervisor->refresh()->pin_operacional_intentos_fallidos);

        $this->postJson($ruta, [...$this->viaje($orden, 6), 'autorizacion' => ['user_id' => $supervisor->id, 'pin' => '2580']])
            ->assertOk()
            ->assertJsonPath('data.entregas.0.orden_proceso.fuera_de_orden', true)
            ->assertJsonPath('data.entregas.0.orden_proceso.autorizado_por', 'Supervisora Frío');

        $detalle = $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum')
            ->getJson("/api/materia-prima/ordenes-proceso/{$orden['id']}")
            ->assertOk()
            ->assertJsonPath('data.numero_version_vigente', 2)
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.versiones.1.origen', 'fuera_de_orden')
            ->assertJsonPath('data.versiones.1.creada_por', 'Supervisora Frío')
            ->assertJsonPath('data.version_vigente.lotes.1.numero_lote', 'L-FO2')
            ->assertJsonPath('data.version_vigente.lotes.1.bins_programados', 6)
            ->assertJsonPath('data.version_vigente.lotes.1.bins_pendientes', 0)
            ->json('data');
        $this->assertSame(10, $detalle['progreso']['bins_pendientes']);
        $this->assertDatabaseHas('entregas_fruta_proceso', ['lote_materia_prima_id' => $extra['id'], 'autorizado_por_user_id' => $supervisor->id]);
    }

    public function test_anular_devuelve_los_bins_a_la_orden_y_reabre_una_orden_terminada(): void
    {
        $lote = $this->loteMpEnCamara('ANU')['lote'];
        $orden = $this->crearOrden([['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 10]]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $entrega = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 10))
            ->assertOk()
            ->json('data.entregas.0');
        $this->assertDatabaseHas('ordenes_proceso_mp', ['id' => $orden['id'], 'estado' => 'terminada', 'tipo_cierre' => 'completa']);

        $this->postJson("/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Bins equivocados',
        ])->assertOk()->assertJsonPath('data.ordenes.0.bins_pendientes', 10);

        $this->assertDatabaseHas('ordenes_proceso_mp', ['id' => $orden['id'], 'estado' => 'programada', 'tipo_cierre' => null, 'cerrado_at' => null]);
        $this->assertDatabaseHas('reservas_lote_orden_proceso_mp', ['orden_proceso_mp_id' => $orden['id'], 'bins_entregados' => 0, 'bins_pendientes' => 10]);
    }

    public function test_una_orden_cancelada_no_recibe_entregas_y_al_anular_no_recupera_pendientes(): void
    {
        $lote = $this->loteMpEnCamara('CXL')['lote'];
        $orden = $this->crearOrden([['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 10]]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $entrega = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 4))
            ->assertOk()
            ->json('data.entregas.0');
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/cancelar", ['version' => 1, 'motivo' => 'Cambio de programa'])
            ->assertOk();

        $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 2))
            ->assertStatus(409);
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/anular", [
                'operacion_id' => (string) Str::uuid(), 'motivo' => 'Corrección posterior',
            ])->assertOk();

        $this->assertDatabaseHas('ordenes_proceso_mp', ['id' => $orden['id'], 'estado' => 'terminada', 'tipo_cierre' => 'cancelacion']);
        $reserva = ReservaLoteOrdenProcesoMp::query()->where('orden_proceso_mp_id', $orden['id'])->firstOrFail();
        $this->assertSame([0, 0], [$reserva->bins_entregados, $reserva->bins_pendientes]);
    }

    public function test_con_la_orden_exigida_un_viaje_sin_orden_se_rechaza(): void
    {
        $lote = $this->loteMpEnCamara('EXI')['lote'];
        $orden = $this->crearOrden([['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 10]]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum');
        $legado = [
            'operacion_id' => (string) Str::uuid(), 'cantidad_envases' => 2, 'linea_proceso' => 'Línea 1', 'turno' => 'A', 'numero_orden' => 'OP-LIBRE',
        ];

        config(['materia_prima.fruta_proceso_exige_orden' => true]);
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $legado)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('orden_proceso_mp_id');
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $this->viaje($orden, 2))->assertOk();

        config(['materia_prima.fruta_proceso_exige_orden' => false]);
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [...$legado, 'operacion_id' => (string) Str::uuid()])
            ->assertOk();
        $this->assertDatabaseHas('entregas_fruta_proceso', ['numero_orden' => 'OP-LIBRE', 'orden_proceso_mp_id' => null]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lotes
     * @return array<string, mixed>
     */
    private function crearOrden(array $lotes): array
    {
        return $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', [
                'operacion_id' => (string) Str::uuid(),
                'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
                'fecha' => '2026-11-02',
                'turno_inicio' => 'A',
                'hora_inicio' => '08:00',
                'lotes' => $lotes,
            ])
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $orden
     * @param  array<int, array<string, mixed>>  $lotes
     * @return array<string, mixed>
     */
    private function publicarVersion(array $orden, array $lotes, int $version = 1): array
    {
        return $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
                'operacion_id' => (string) Str::uuid(),
                'version' => $version,
                'motivo_cambio' => 'Ajuste de programa',
                'hora_inicio' => '08:00',
                'lotes' => $lotes,
            ])
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $orden
     * @return array<string, mixed>
     */
    private function viaje(array $orden, int $bins): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'orden_proceso_mp_id' => $orden['id'],
            'version_orden_proceso_mp_id' => $orden['version_vigente']['id'],
            'cantidad_envases' => $bins,
            'turno' => 'A',
        ];
    }
}
