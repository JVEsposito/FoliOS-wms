<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\LineaProceso;
use App\Models\OrdenProcesoMp;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class OrdenesProcesoMpApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    public function test_el_digitador_crea_la_orden_con_lotes_de_varios_clientes_y_horarios_en_cadena(): void
    {
        $primero = $this->loteMpEnCamara('A1');
        $segundo = $this->loteMpEnCamara('B1');
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);

        $orden = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([
                $this->fila($primero, 20, ['numero_proceso' => 'P-881', 'embalaje' => 'GFLV50M', 'kilos_por_caja' => 5, 'mercado_destino' => 'China', 'comuna' => 'Rengo', 'provincia' => 'Cachapoal']),
                $this->fila($segundo, 48),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'OP-000001')
            ->assertJsonPath('data.estado', 'programada')
            ->assertJsonPath('data.numero_version_vigente', 1)
            ->assertJsonPath('data.turno_pre_sif', 'T1')
            ->assertJsonPath('data.linea.codigo', 'L1')
            ->assertJsonPath('data.progreso.bins_programados', 68)
            ->assertJsonPath('data.progreso.bins_pendientes', 68)
            ->json('data');

        $filas = $orden['version_vigente']['lotes'];
        $this->assertSame(['L-A1', 'L-B1'], array_column($filas, 'numero_lote'));
        $this->assertSame(['Exportadora A1', 'Exportadora B1'], $orden['clientes']);
        // 18.000 kg en 48 bins: 20 bins son 7.500 kg, 1 h 15 min a 6.000 kg/h desde las 08:00.
        $this->assertEquals(7500, $filas[0]['kilos_programados']);
        $this->assertSame(4, $filas[0]['totes_programados']);
        $this->assertEquals(1350, $filas[0]['cajas_estimadas']);
        $this->assertSame('2026-11-02 08:00:00', $filas[0]['inicio_estimado']);
        $this->assertSame('2026-11-02 09:15:00', $filas[0]['termino_estimado']);
        $this->assertSame('2026-11-02 09:15:00', $filas[1]['inicio_estimado']);
        $this->assertSame('2026-11-02 12:15:00', $filas[1]['termino_estimado']);
        $this->assertSame('P-881', $filas[0]['numero_proceso']);
        $this->assertSame('Rengo', $filas[0]['comuna']);
        $this->assertNull($filas[1]['cajas_estimadas']);

        $this->getJson('/api/materia-prima/ordenes-proceso')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'OP-000001');
    }

    public function test_crear_es_idempotente_y_rechaza_el_mismo_identificador_con_otros_datos(): void
    {
        $lote = $this->loteMpEnCamara('ID1');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum');
        $payload = $this->payload([$this->fila($lote, 10)]);

        $id = $this->postJson('/api/materia-prima/ordenes-proceso', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/materia-prima/ordenes-proceso', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/materia-prima/ordenes-proceso', [...$payload, 'hora_inicio' => '09:00'])->assertStatus(409);

        $this->assertSame(1, OrdenProcesoMp::query()->count());
    }

    public function test_un_lote_no_se_programa_por_sobre_sus_bins_libres_entre_ordenes_abiertas(): void
    {
        $lote = $this->loteMpEnCamara('SAL');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum');
        $this->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 20)]))->assertCreated();

        $this->getJson('/api/materia-prima/ordenes-proceso/lotes-programables')
            ->assertOk()
            ->assertJsonPath('data.0.numero_lote', 'L-SAL')
            ->assertJsonPath('data.0.saldo_bins', 48)
            ->assertJsonPath('data.0.bins_comprometidos', 20)
            ->assertJsonPath('data.0.bins_libres', 28);

        $linea2 = LineaProceso::query()->where('codigo', 'L2')->value('id');
        $this->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 29)], ['linea_proceso_id' => $linea2]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lotes.0.bins_programados');
        $this->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 28)], ['linea_proceso_id' => $linea2]))
            ->assertCreated();

        $this->getJson('/api/materia-prima/ordenes-proceso/lotes-programables')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_una_nueva_version_exige_motivo_y_la_version_vigente_y_conserva_el_historial(): void
    {
        $primero = $this->loteMpEnCamara('V1');
        $segundo = $this->loteMpEnCamara('V2');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum');
        $orden = $this->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($primero, 10)]))->assertCreated()->json('data');

        $cambio = [
            'operacion_id' => (string) Str::uuid(),
            'version' => $orden['version'],
            'hora_inicio' => '08:00',
            'lotes' => [$this->fila($segundo, 12), $this->fila($primero, 14)],
        ];
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", $cambio)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo_cambio');

        $v2 = $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [...$cambio, 'motivo_cambio' => 'Llegó fruta de otro cliente'])
            ->assertOk()
            ->assertJsonPath('data.numero_version_vigente', 2)
            ->assertJsonPath('data.version_vigente.lotes.0.numero_lote', 'L-V2')
            ->assertJsonPath('data.version_vigente.lotes.1.bins_programados', 14)
            ->assertJsonPath('data.versiones.1.motivo_cambio', 'Llegó fruta de otro cliente')
            ->assertJsonCount(2, 'data.versiones')
            ->json('data');
        $this->assertSame(2, $v2['version']);

        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
            ...$cambio, 'operacion_id' => (string) Str::uuid(), 'motivo_cambio' => 'Cambio con versión vieja',
        ])->assertStatus(409);

        $this->assertDatabaseCount('lotes_version_orden_mp', 3);
        $this->assertSame(26, (int) ReservaLoteOrdenProcesoMp::query()->sum('bins_pendientes'));
    }

    public function test_no_se_quita_ni_se_baja_un_lote_por_debajo_de_lo_entregado_y_al_igualarlo_termina(): void
    {
        $primero = $this->loteMpEnCamara('E1');
        $segundo = $this->loteMpEnCamara('E2');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum');
        $orden = $this->postJson('/api/materia-prima/ordenes-proceso', $this->payload([
            $this->fila($primero, 10), $this->fila($segundo, 6),
        ]))->assertCreated()->json('data');
        // Fruta a proceso (PR 3) actualiza la reserva al entregar; aquí se simula ese estado.
        ReservaLoteOrdenProcesoMp::query()
            ->where('lote_materia_prima_id', $primero['lote']['id'])
            ->update(['bins_entregados' => 5, 'bins_pendientes' => 5]);

        $base = ['version' => $orden['version'], 'hora_inicio' => '08:00', 'motivo_cambio' => 'Ajuste de programa'];
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
            ...$base, 'operacion_id' => (string) Str::uuid(), 'lotes' => [$this->fila($segundo, 6)],
        ])->assertUnprocessable()->assertJsonValidationErrors('lotes');
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
            ...$base, 'operacion_id' => (string) Str::uuid(), 'lotes' => [$this->fila($primero, 4), $this->fila($segundo, 6)],
        ])->assertUnprocessable()->assertJsonValidationErrors('lotes.0.bins_programados');

        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
            ...$base, 'operacion_id' => (string) Str::uuid(), 'lotes' => [$this->fila($primero, 5)],
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'terminada')
            ->assertJsonPath('data.tipo_cierre', 'completa')
            ->assertJsonPath('data.progreso.bins_pendientes', 0);
        $this->assertDatabaseMissing('reservas_lote_orden_proceso_mp', ['lote_materia_prima_id' => $segundo['lote']['id']]);
    }

    public function test_el_supervisor_cancela_con_motivo_y_libera_los_bins_pendientes(): void
    {
        $lote = $this->loteMpEnCamara('CAN');
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $orden = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 48)]))
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/cancelar", ['version' => 1, 'motivo' => 'Packing detenido'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum');
        $cancelada = ['version' => 1, 'motivo' => 'Packing detenido'];
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/cancelar", $cancelada)
            ->assertOk()
            ->assertJsonPath('data.estado', 'terminada')
            ->assertJsonPath('data.tipo_cierre', 'cancelacion')
            ->assertJsonPath('data.motivo_cancelacion', 'Packing detenido')
            ->assertJsonPath('data.progreso.bins_pendientes', 0);
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/cancelar", $cancelada)->assertOk();

        $this->getJson('/api/materia-prima/ordenes-proceso/lotes-programables')
            ->assertOk()
            ->assertJsonPath('data.0.bins_libres', 48);
        $this->postJson("/api/materia-prima/ordenes-proceso/{$orden['id']}/versiones", [
            'operacion_id' => (string) Str::uuid(), 'version' => 2, 'hora_inicio' => '08:00',
            'motivo_cambio' => 'Reabrir', 'lotes' => [$this->fila($lote, 10)],
        ])->assertStatus(409);
        $this->getJson('/api/materia-prima/ordenes-proceso?estado=terminada')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/materia-prima/ordenes-proceso')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_una_linea_inactiva_no_recibe_ordenes_y_los_permisos_separan_consultar_de_programar(): void
    {
        $lote = $this->loteMpEnCamara('PER');
        LineaProceso::query()->where('codigo', 'L5')->update(['activa' => false]);
        $linea5 = LineaProceso::query()->where('codigo', 'L5')->value('id');

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 10)], ['linea_proceso_id' => $linea5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linea_proceso_id');

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 10)]))
            ->assertForbidden();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/materia-prima/ordenes-proceso')->assertOk();
        $this->actingAs($consulta, 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 10)]))
            ->assertForbidden();
    }

    public function test_el_reinicio_operacional_de_una_temporada_de_prueba_borra_sus_ordenes(): void
    {
        $lote = $this->loteMpEnCamara('RST');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', $this->payload([$this->fila($lote, 12)]))
            ->assertCreated();
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'password' => 'password']);

        $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/administracion/temporadas/{$temporada->id}/reinicio-operacional")
            ->assertOk()
            ->assertJsonPath('data.resumen.materia_prima.ordenes_proceso', 1);
        $this->postJson("/api/administracion/temporadas/{$temporada->id}/reinicio-operacional", [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Cierre controlado de la etapa de pruebas integrales.',
            'password' => 'password',
            'confirmacion' => "REINICIAR {$temporada->codigo}",
            'confirmar_exclusion_bodega' => true,
            'confirmar_preservar_configuracion' => true,
        ])->assertCreated()->assertJsonPath('data.resumen_despues.materia_prima.ordenes_proceso', 0);

        foreach (['ordenes_proceso_mp', 'versiones_orden_proceso_mp', 'lotes_version_orden_mp', 'reservas_lote_orden_proceso_mp'] as $tabla) {
            $this->assertDatabaseCount($tabla, 0);
        }
        $this->assertDatabaseCount('lineas_proceso', 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lotes
     * @param  array<string, mixed>  $reemplazos
     * @return array<string, mixed>
     */
    private function payload(array $lotes, array $reemplazos = []): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
            'fecha' => '2026-11-02',
            'turno_inicio' => 'T1',
            'hora_inicio' => '08:00',
            'lotes' => $lotes,
            ...$reemplazos,
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fila(array $contexto, int $bins, array $extra = []): array
    {
        return ['lote_materia_prima_id' => $contexto['lote']['id'], 'bins_programados' => $bins, ...$extra];
    }
}
