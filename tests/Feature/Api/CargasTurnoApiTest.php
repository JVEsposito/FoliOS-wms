<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Temporada;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargasTurnoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.operational_timezone' => 'America/Santiago', 'verificaciones.turnos.inicio' => '06:00', 'verificaciones.turnos.duracion_horas' => 8]);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 02:30', 'America/Santiago'));
    }

    public function test_camarero_ve_activas_y_terminadas_del_turno_nocturno_con_sus_folios_historicos(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $activa = $this->carga($usuario, 'ACTIVA', EstadoCarga::Pendiente);
        $cerrada = $this->carga($usuario, 'CERRADA', EstadoCarga::Cerrada, ['cerrada_at' => $this->hora('2026-10-21 01:00')]);
        $despachada = $this->carga($usuario, 'DESPACHADA', EstadoCarga::Despachada);
        $cancelada = $this->carga($usuario, 'CANCELADA', EstadoCarga::Cancelada, ['cancelada_at' => now()]);
        $this->carga($usuario, 'ANTERIOR', EstadoCarga::Cerrada, ['cerrada_at' => $this->hora('2026-10-20 21:59:59')]);
        $this->carga($usuario, 'SIGUIENTE', EstadoCarga::Cerrada, ['cerrada_at' => $this->hora('2026-10-21 06:00')]);
        $this->carga($usuario, 'BORRADOR', EstadoCarga::Borrador);
        $this->carga($usuario, 'SIN-PUBLICAR', EstadoCarga::Cancelada, ['publicada_at' => null, 'cancelada_at' => now()]);
        $otra = Temporada::create(['codigo' => 'OTRA-TURNO', 'nombre' => 'Otra', 'fecha_inicio' => '2025-09-01', 'activa' => false]);
        $this->carga($usuario, 'OTRA-TEMPORADA', EstadoCarga::Pendiente, ['temporada_id' => $otra->id]);

        foreach ([$cerrada, $despachada] as $carga) {
            $folio = Folio::create(['temporada_id' => $carga->temporada_id, 'numero_folio' => 'PAL-'.$carga->codigo,
                'tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'activo' => false]);
            CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => 'despachado',
                'asignado_por_user_id' => $usuario->id, 'asignado_at' => now()]);
        }

        $respuesta = $this->actingAs($usuario, 'sanctum')->getJson('/api/cargas/operacion-turno')->assertOk()
            ->assertJsonPath('meta.turno.nombre', '22:00–06:00')
            ->assertJsonPath('meta.turno.zona_horaria', 'America/Santiago');
        $this->assertEqualsCanonicalizing([$activa->codigo, $cerrada->codigo, $despachada->codigo, $cancelada->codigo], array_column($respuesta->json('data'), 'codigo'));
        $cargas = collect($respuesta->json('data'))->keyBy('codigo');
        foreach ([$cerrada, $despachada] as $carga) {
            $this->assertSame(1, $cargas[$carga->codigo]['total_folios']);
            $this->assertSame('despachado', $cargas[$carga->codigo]['folios'][0]['estado_carga']);
        }

        $this->postJson('/api/cargas', [])->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')
            ->getJson('/api/cargas/operacion-turno')->assertForbidden();
    }

    public function test_etag_cambia_al_comenzar_otro_turno_sin_modificar_las_cargas(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->carga($usuario, 'ACTIVA-ETAG', EstadoCarga::Pendiente);
        $this->carga($usuario, 'CERRADA-ETAG', EstadoCarga::Cerrada, ['cerrada_at' => now()]);
        $primera = $this->actingAs($usuario, 'sanctum')->getJson('/api/cargas/operacion-turno')->assertOk()->assertJsonCount(2, 'data');
        $etag = $primera->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/api/cargas/operacion-turno')->assertStatus(304);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 06:00', 'America/Santiago'));
        $segunda = $this->getJson('/api/cargas/operacion-turno')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.turno.nombre', '06:00–14:00')->assertJsonPath('data.0.codigo', 'ACTIVA-ETAG');
        $this->assertNotSame($etag, $segunda->headers->get('ETag'));
    }

    private function carga(User $usuario, string $codigo, EstadoCarga $estado, array $extra = []): Carga
    {
        return Carga::create([
            'temporada_id' => Temporada::query()->where('activa', true)->value('id'),
            'codigo' => $codigo, 'estado' => $estado, 'prioridad' => 'normal', 'publicada_at' => now(),
            'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
            ...$extra,
        ]);
    }

    private function hora(string $valor): CarbonImmutable
    {
        return CarbonImmutable::parse($valor, 'America/Santiago')->utc();
    }
}
